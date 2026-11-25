<?php

namespace App\Support\RibbonPortal;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPortalLink as Link;
use App\Models\CustomerPortalSession as Session;
use App\Models\Order;
use App\Support\Digits;
use App\Support\SalesChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * رمزُ الدخول المطبوع أسفل فاتورة RIBBON — ما يُثبت أنّ كاتبَ الرقم صاحبُه.
 *
 * ═══ ولمَ رمزٌ له لا رمزُ QR القائم ═══
 *
 * رمزُ QR على الفاتورة هو نفسُه على سند التسليم (`PublicDocument::url($order)`)،
 * والسندُ يصل من أُهديت إليه الباقة — ويعرف في الغالب رقمَ من أهداها. فلو كان
 * يفتح الحساب لقرأ المُهدى إليه كلَّ ما اشتراه المُهدي. وهذا لا يُطبع إلّا على
 * فاتورة المشتري وإيصاله، ولا يُطبع على سندٍ ولا ورقةِ تجهيزٍ ولا في صفحة الرمز العامّة.
 *
 * ═══ وممّا أصدره موظّفٌ وحده ═══
 *
 * طلبُ الموقع وطلبُ واتساب الآليّ يُنسبان إلى العميل برقمٍ يكتبه الزائر بلا تحقّق
 * (`WebCheckout::customer`): فمن طلب برقم غيره لا يخرج بورقةٍ تفتح حسابَه.
 *
 * ═══ ويكفّ متى دخل صاحبُه ═══
 *
 * من اشترى اليومَ في المحلّ برقم غيره لا يخرج بإيصالٍ يفتح حسابَه: ما دامت له جلسةٌ
 * قائمة لا يُطبع الرمزُ على ما بعدها ولا يُقبل منه. وفاتورتُه التي دخل بها تبقى لأجهزته.
 *
 * ═══ ولا يُحفظ ═══
 *
 * يُشتقّ بمفتاح الخادم من الورقة وصاحبها ودورته. وإلغاءُ الدخول يبدّل الدورة، فتقف
 * رموزُ ما طُبع قبله، وتحمل الورقةُ نفسُها إن أُعيدت طباعتُها رمزًا جديدًا.
 */
final class Code
{
    /** تسعةُ أرقام: مليارُ احتمال، وعشرُ محاولاتٍ في اليوم للرقم الواحد */
    public const DIGITS = 9;

    /**
     * رمزُ هذه الورقة — أو `null` فلا يُطبع شيء.
     *
     * ومتجرٌ غيرُ صاحبها يُردّ قبل أيّ استعلام (`Portal::on` تسأل المعرّفَ أوّلًا).
     */
    public static function of(?Model $document): ?string
    {
        if (! ($document instanceof Order || $document instanceof CustomerInvoice) || ! $document->exists
            || ! Portal::on((int) $document->business_id) || ! self::byStaff($document)) {
            return null;
        }

        $customer = Customer::where('business_id', $document->business_id)->find($document->customer_id);
        if ($customer === null || Access::phone($customer) === null) {
            return null;
        }

        $floor = self::floor($customer);

        return $floor !== null && Carbon::parse($document->created_at)->gt($floor)
            ? null
            : self::make($customer, self::kind($document).'|'.$document->getKey(), self::epoch($customer));
    }

    /** كما يُطبع: ثلاثاتٌ تُقرأ وتُملى */
    public static function printed(?Model $document): ?string
    {
        return self::spaced(self::of($document));
    }

    /** رمزٌ يقرؤه الموظّفُ لعميلٍ أمامه بلا فاتورة — يقف بنهاية الغد */
    public static function daily(Customer $customer): string
    {
        return self::make($customer, 'day|'.now()->format('Y-m-d'), self::epoch($customer));
    }

    public static function spaced(?string $code): ?string
    {
        return $code === null ? null : implode(' ', str_split($code, 3));
    }

    /** ما كتبه الزائر رمزًا — بأرقامٍ عربيّة ومسافاتٍ وشرطات، أو `null` لما ليس رمزًا */
    public static function clean(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) Digits::western($raw));

        return strlen($digits) === self::DIGITS ? $digits : null;
    }

    /** أيفتح هذا الرمزُ حسابَ هذا العميل؟ — تُقارن كلُّها بلا خروجٍ مبكّر */
    public static function opens(Customer $customer, string $code): bool
    {
        $epoch = self::epoch($customer);
        $floor = self::floor($customer);
        $ok = false;

        foreach ([now(), now()->subDay()] as $day) {
            $ok = hash_equals(self::make($customer, 'day|'.$day->format('Y-m-d'), $epoch), $code) || $ok;
        }

        foreach (['order' => self::orders($customer), 'invoice' => self::invoices($customer)] as $kind => $query) {
            foreach ($query->when($floor, fn ($q) => $q->where('created_at', '<=', $floor))->pluck('id') as $id) {
                $ok = hash_equals(self::make($customer, $kind.'|'.$id, $epoch), $code) || $ok;
            }
        }

        return $ok;
    }

    /** أصدرها موظّف — لا زائرُ الموقع ولا محادثةٌ آليّة. وليست سلّةً معلّقةً ولا مسودّة */
    private static function byStaff(Order|CustomerInvoice $document): bool
    {
        return $document instanceof Order
            ? ! $document->is_held && ($document->channel === SalesChannel::POS || ($document->channel === null && $document->user_id !== null))
            : $document->status !== CustomerInvoice::DRAFT && $document->created_by !== null;
    }

    private static function orders(Customer $customer): Builder
    {
        return Order::where('business_id', $customer->business_id)->where('customer_id', $customer->id)->where('is_held', false)
            ->where(fn ($q) => $q->where('channel', SalesChannel::POS)->orWhere(fn ($w) => $w->whereNull('channel')->whereNotNull('user_id')));
    }

    private static function invoices(Customer $customer): Builder
    {
        return CustomerInvoice::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->where('status', '!=', CustomerInvoice::DRAFT)->whereNotNull('created_by');
    }

    private static function kind(Order|CustomerInvoice $document): string
    {
        return $document instanceof Order ? 'order' : 'invoice';
    }

    /** متى بدأت أقدمُ جلسةٍ قائمةٍ له — أو `null` لمن لا جلسةَ له */
    private static function floor(Customer $customer): ?Carbon
    {
        $at = Session::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->min('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /** دورتُه: آخرُ إلغاءٍ بيد موظّف */
    private static function epoch(Customer $customer): int
    {
        return (int) Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->where('revoked_why', Access::REVOKED_BY_STAFF)->max('id');
    }

    private static function make(Customer $customer, string $what, int $epoch): string
    {
        $raw = hash_hmac('sha256', 'code|'.$customer->business_id.'|'.$customer->id.'|'.$epoch.'|'.$what, Access::secret(), true);

        return str_pad((string) ((unpack('J', substr($raw, 0, 8))[1] & PHP_INT_MAX) % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }
}

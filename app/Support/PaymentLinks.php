<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Support\Paymob\Amount;
use App\Support\Paymob\QuickLink;
use App\Support\Paymob\QuickLinkFailed;
use App\Support\Store\Paymob;
use App\Support\Store\PaymobSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * روابطُ الدفع — تحصيلٌ من عميلٍ عبر Paymob، بحساب التاجر وحده.
 *
 * ═══ تحصيلٌ لا بيع ═══
 *
 * الرابطُ يطلب مالًا على ذمّةٍ قائمة: فاتورةٌ رُحّل إيرادُها وضريبتُها يومَ
 * صدرت، أو رصيدٌ للعميل. فلا يُنشئ طلبًا ولا إيرادًا ولا ضريبةً ولا تكلفة —
 * ولا يمرّ بنيّة دفع الموقع (`StorePaymentIntent`) التي تنتهي بطلب. وحين
 * يُدفع يُكتب **تحصيلٌ واحد** بالباب الذي تُكتب به التحصيلاتُ كلُّها
 * (`CustomerPayments::record`) بوسيلة `Paymob`: مدين «Paymob تحت التسوية»،
 * دائن ذمم العملاء.
 *
 * ═══ والإشعارُ وحده يقول «دُفع» ═══
 *
 * لا صفحةُ العودة ولا `success=true` في رابطٍ يكتبه أيُّ أحد: إشعارُ Paymob
 * الموقَّع (`callback`) بسرّ توقيع متجر الرابط — والمتجرُ من صفّ الرابط لا
 * من شيءٍ في الطلب. ثمّ مبلغُه وعملتُه وبيئتُه كما فُتح.
 *
 * ═══ ومرّةً واحدة ═══
 *
 * Paymob تُعيد الإرسال. فالرابطُ يُقفل في معاملة، ومعرّفُ العمليّة فريدٌ في
 * القاعدة، والتحصيلُ فريدٌ على الرابط — فخمسون إشعارًا تحصيلٌ واحد وقيدٌ
 * واحد وتخصيصٌ واحد.
 */
final class PaymentLinks
{
    /** مهلةُ الرابط بالأيّام إن لم تُكتب */
    public const DAYS = 7;

    /** أقصى مهلة — رابطٌ يُفتح بعد شهرين يطلب مبلغًا تغيّر */
    public const MAX_DAYS = 30;

    public const SOURCE = 'رابط دفع';

    /** بوّابةُ روابط هذا المتجر — مكتملةً، أو `null` («أكمل إعداد Paymob أولًا») */
    public static function gateway(int $businessId): ?PaymentGateway
    {
        $row = PaymobSettings::row($businessId);

        return $row?->linksReady() === true ? $row : null;
    }

    /** أتُفتح روابطُ دفعٍ لهذا المتجر الآن؟ */
    public static function ready(int $businessId): bool
    {
        return self::gateway($businessId) !== null;
    }

    /**
     * يفتح رابطًا عند Paymob ويحفظه — أو يرمي بسببٍ يُقرأ ولا يُعطي رابطًا.
     *
     * ═══ والفاتورةُ تُسأل وقتَ الفتح ═══
     *
     * من متجره، صادرةٌ، على العميل نفسه، وعليها باقٍ — والمبلغُ لا يتجاوز
     * باقيها (والافتراضُ باقيها كلُّه). وما يتغيّر بعد الفتح يُعالَج عند الدفع
     * (`settle`): المالُ المقبوض كلُّه يُسجَّل، والتخصيصُ بقدر الباقي يومها.
     *
     * ═══ وفشلُ Paymob لا يُعطي رابطًا ═══
     *
     * يُحفظ صفٌّ «تعذّر» للتدقيق بسببه — بلا عنوانٍ يُرسَل — ويُرمى للتاجر
     * السببُ مقروءًا.
     */
    public static function create(
        int $businessId,
        Customer $customer,
        ?CustomerInvoice $invoice,
        ?float $amount,
        ?string $description = null,
        ?int $userId = null,
        ?int $days = null,
    ): CustomerPaymentLink {
        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء متجرك.'));
        }

        if ($invoice !== null) {
            if ((int) $invoice->business_id !== $businessId) {
                throw new RuntimeException(__('هذه الفاتورة ليست من فواتير متجرك.'));
            }
            if ((int) $invoice->customer_id !== (int) $customer->id) {
                throw new RuntimeException(__('الفاتورة :n ليست على هذا العميل.', ['n' => $invoice->number]));
            }
            if ($invoice->status !== CustomerInvoice::ISSUED) {
                throw new RuntimeException(__('لا يُطلب دفعُ فاتورةٍ غير صادرة.'));
            }

            $outstanding = $invoice->outstanding();

            if ($outstanding <= 0) {
                throw new RuntimeException(__('هذه الفاتورة مسدَّدة — لا باقيَ يُطلب.'));
            }

            $amount ??= $outstanding;

            if (round($amount, 3) > round($outstanding, 3)) {
                throw new RuntimeException(__('المبلغ أكبر من باقي الفاتورة (:n).', ['n' => number_format($outstanding, 3)]));
            }
        }

        $amount = round((float) $amount, 3);

        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغ الرابط يجب أن يكون أكبر من صفر.'));
        }

        $gateway = self::gateway($businessId);

        if ($gateway === null) {
            throw new RuntimeException(__('أكمل إعداد Paymob أولًا.'));
        }

        $currency = strtoupper((string) ($invoice?->currency ?: (Money::of($businessId)['code'] ?? 'OMR')));
        $days = max(1, min(self::MAX_DAYS, $days ?? self::DAYS));
        $expires = now()->addDays($days)->endOfDay()->startOfMinute();

        /*
         * والمرجعُ عشوائيٌّ طويلٌ لا يُخمَّن، ولا يحمل معرّفَ المتجر: لا يُعرف
         * منه متجرٌ ولا يُصنع لمتجرٍ آخر. وبادئتُه `pay-` تميّزه عن نيّات
         * الموقع (`web-`) في سجلّات Paymob.
         */
        $reference = 'pay-'.Str::lower(Str::random(32));
        $description = filled($description) ? Str::limit(trim((string) $description), 250, '') : null;

        $base = [
            'business_id' => $businessId,
            'customer_id' => $customer->id,
            'customer_invoice_id' => $invoice?->id,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'is_live' => $gateway->isLive(),
            'created_by' => $userId,
        ];

        try {
            $made = QuickLink::create($gateway, [
                'amount_cents' => Amount::toMinor($amount, $currency),
                'currency' => $currency,
                'reference_id' => $reference,
                'expires_at' => $expires->format('Y-m-d\TH:i:s'),
                'notification_url' => PaymobSettings::webhook(),
                'full_name' => Str::limit(trim((string) $customer->name), 100, ''),
                'email' => filter_var(trim((string) $customer->email), FILTER_VALIDATE_EMAIL) ?: null,
                'phone_number' => self::phone((string) $customer->phone),
                'description' => $description ?? ($invoice ? __('فاتورة :n', ['n' => $invoice->number]) : null),
            ]);
        } catch (QuickLinkFailed $e) {
            /*
             * والفاشلُ يحفظ مهلتَه وما يُعرف عنه.
             *
             * فشلٌ «لا نعرف» (`uncertain`: انقطع الاتصال بعد الإرسال، أو خطأُ
             * خادمٍ عند Paymob) قد يكون رابطًا أُنشئ هناك ولم يصلنا — فلا يُعاد
             * الطلبُ وحده بعده ما دامت مهلتُه لم تنقضِ (`forInvoice`).
             */
            CustomerPaymentLink::create($base + [
                'status' => CustomerPaymentLink::FAILED,
                'error' => Str::limit($e->getMessage(), 500),
                'expires_at' => $expires,
                'meta' => $e->uncertain ? ['uncertain' => true] : null,
            ]);

            throw new RuntimeException(
                __('تعذّر إنشاء الرابط').' — '.$e->getMessage()
                    .($e->uncertain ? ' '.__('وقد يكون أُنشئ عند Paymob دون أن يصلنا جوابُها.') : ''),
                0,
                $e,
            );
        }

        $link = CustomerPaymentLink::create($base + [
            'status' => CustomerPaymentLink::PENDING,
            'provider_link_id' => $made['id'],
            'provider_order_id' => $made['order'],
            'short_url' => $made['short_url'],
            'expires_at' => self::expiry($made['expires_at']) ?? $expires,
            'meta' => ['state' => $made['state']],
        ]);

        Activity::log('created', 'أنشأ رابط دفع بمبلغ '.number_format($amount, 3).' '.$currency.' لـ'.$customer->name
            .($invoice ? ' على الفاتورة '.$invoice->number : ''), [
                'subject_id' => $link->id, 'subject_type' => 'customer_payment_link', 'business_id' => $businessId,
            ]);

        return $link;
    }

    /**
     * رابطُ دفعٍ لباقي فاتورة — يُعاد القائمُ ولا يُفتح ثانٍ فوقه.
     *
     * البابُ الذي يطلبه «إصدار الفاتورة وإنشاء رابط دفع» وزرُّ «إنشاء رابط
     * دفع» في صفحتها و«المالية» حين تُختار فاتورة. ويُنادى **بعد** أن تُكتب
     * الفاتورةُ وتُصدَر في معاملتها: طلبُ Paymob لا يقع داخل معاملة.
     *
     * ═══ ولا طلبان للمال نفسِه ═══
     *
     * - **رابطٌ قائمٌ بالمبلغ نفسِه** يُعاد كما هو (`[$link, true]`) — لا
     *   رابطٌ ثانٍ عند Paymob. فضغطتان، أو إعادةُ الصفحة، أو تبويبان: رابطٌ واحد.
     * - **رابطٌ قائمٌ بمبلغٍ آخر** (دُفع بعضُ الفاتورة نقدًا بعده مثلًا) يُردّ
     *   بسببه: دفعُه ودفعُ الجديد يأخذان من العميل أكثرَ ممّا عليه. يُلغى
     *   القائمُ أوّلًا ثمّ يُطلب.
     * - **محاولةٌ سابقةٌ لا تُعرف نتيجتُها** (`uncertain`) لم تنقضِ مهلتُها:
     *   قد يكون رابطُها عند Paymob. فلا يُعاد الطلبُ وحده — ويُعاد بقرارٍ
     *   صريح (`$replaceUncertain`) ممّن يعرف أنّه سيرسل الجديدَ وحده.
     * - **طلبان في اللحظة نفسِها** على الفاتورة نفسِها: قفلٌ يأخذه أحدُهما،
     *   والآخرُ يُقال له «يُنشأ الآن». والقفلُ خارج كلّ معاملة.
     *
     * والملغى والمنتهي والفاشلُ المؤكَّد لا يمنعون: الباقي يُطلب برابطٍ جديد.
     *
     * @return array{0: CustomerPaymentLink, 1: bool} الرابطُ، وأكان قائمًا من قبل
     */
    public static function forInvoice(
        CustomerInvoice $invoice,
        ?int $userId = null,
        ?float $amount = null,
        ?string $description = null,
        bool $replaceUncertain = false,
        ?int $days = null,
    ): array {
        $lock = Cache::lock('payment-link:invoice:'.$invoice->id, 90);

        if (! $lock->get()) {
            throw new RuntimeException(__('يُنشأ رابطُ دفعٍ لهذه الفاتورة الآن — انتظر لحظة ثمّ حدّث الصفحة.'));
        }

        try {
            $bid = (int) $invoice->business_id;
            self::expireDue($bid);

            $invoice = CustomerInvoice::where('business_id', $bid)->findOrFail($invoice->id);
            $customer = Customer::withTrashed()->where('business_id', $bid)->findOrFail($invoice->customer_id);

            $wanted = round((float) ($amount ?? $invoice->outstanding()), 3);

            $open = CustomerPaymentLink::where('business_id', $bid)
                ->where('customer_invoice_id', $invoice->id)
                ->where('status', CustomerPaymentLink::PENDING)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')->get();

            $same = $open->first(fn (CustomerPaymentLink $l) => abs((float) $l->amount - $wanted) < 0.0005);

            if ($same) {
                return [$same, true];
            }

            if ($open->isNotEmpty()) {
                throw new RuntimeException(__('على الفاتورة رابطُ دفعٍ قائم بمبلغ :n لم يُدفع بعد — ألغه أوّلًا من «المالية ← روابط الدفع» قبل طلب مبلغٍ آخر، فلا يُطلب المالُ مرّتين.', [
                    'n' => number_format((float) $open->first()->amount, 3),
                ]));
            }

            $doubt = self::doubt($invoice);

            if ($doubt && ! $replaceUncertain) {
                throw new UncertainPaymentLink(__('لم تُعرف نتيجةُ المحاولة السابقة: انقطع الاتصال بـ Paymob، فقد يكون الرابطُ أُنشئ هناك دون أن يصلنا. لا يُعاد الطلبُ وحده — إن طلبت رابطًا جديدًا فأرسل الجديدَ وحده للعميل.'));
            }

            $link = self::create($bid, $customer, $invoice, $amount, $description, $userId, $days);

            if ($doubt) {
                $doubt->forceFill(['meta' => array_merge((array) $doubt->meta, ['replaced_by' => $link->id])])->save();
                Activity::log('updated', 'طُلب رابطُ دفعٍ جديد للفاتورة '.$invoice->number.' بعد محاولةٍ لم تُعرف نتيجتُها — بقرار من طلبه', [
                    'subject_id' => $link->id, 'subject_type' => 'customer_payment_link', 'business_id' => $bid,
                ]);
            }

            return [$link, false];
        } finally {
            $lock->release();
        }
    }

    /**
     * آخرُ محاولةٍ فاشلةٍ لا تُعرف نتيجتُها — ما دامت مهلتُها لم تنقضِ ولم
     * يُطلب بعدها رابطٌ قام.
     */
    public static function doubt(CustomerInvoice $invoice): ?CustomerPaymentLink
    {
        $failed = self::lastFailure($invoice);

        if (! $failed || ! (($failed->meta ?? [])['uncertain'] ?? false) || isset(($failed->meta ?? [])['replaced_by'])) {
            return null;
        }

        return $failed->expires_at === null || $failed->expires_at->isFuture() ? $failed : null;
    }

    /**
     * آخرُ محاولةٍ لم تُعطِ رابطًا — إن لم يأتِ بعدها رابطٌ قام.
     *
     * تُرى في صفحة الفاتورة بعد التحديث: «أُصدرت ولم يُنشأ الرابط — لماذا،
     * وأعِد المحاولة»، لا رسالةٌ عابرة تختفي بإعادة الصفحة.
     */
    public static function lastFailure(CustomerInvoice $invoice): ?CustomerPaymentLink
    {
        $last = CustomerPaymentLink::where('business_id', $invoice->business_id)
            ->where('customer_invoice_id', $invoice->id)
            ->latest('id')->first();

        return $last && $last->status === CustomerPaymentLink::FAILED ? $last : null;
    }

    /** المحاولةُ الفاشلة كما تقرؤها صفحةُ الفاتورة — بلا سرّ */
    public static function failureRow(?CustomerPaymentLink $failed): ?array
    {
        if (! $failed) {
            return null;
        }

        $uncertain = (bool) (($failed->meta ?? [])['uncertain'] ?? false) && ! isset(($failed->meta ?? [])['replaced_by']);

        return [
            'error' => $failed->error,
            'at' => optional($failed->created_at)->format('Y-m-d H:i'),
            'uncertain' => $uncertain && ($failed->expires_at === null || $failed->expires_at->isFuture()),
            'until' => optional($failed->expires_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * يُلغي رابطًا معلَّقًا — عند Paymob أوّلًا، ثمّ هنا.
     *
     * ولا يُكتب «ملغى» ما لم تقل Paymob إنّه أُلغي: رابطٌ يُوسم ملغًى ويبقى
     * يُدفع يأخذ مالًا لا ينتظره أحد. والمدفوعُ لا يُلغى بهذا الباب أبدًا —
     * الاسترجاعُ طريقٌ آخر يعكس المالَ ولا يمحو الدفع.
     */
    public static function cancel(CustomerPaymentLink $link, ?int $userId = null): CustomerPaymentLink
    {
        if ($link->status !== CustomerPaymentLink::PENDING) {
            throw new RuntimeException(__('لا يُلغى إلّا رابطٌ بانتظار الدفع.'));
        }

        $gateway = PaymobSettings::row((int) $link->business_id);

        if ($gateway === null || ! filled($gateway->api_key) || ! filled($link->provider_link_id)) {
            throw new RuntimeException(__('أكمل إعداد Paymob أولًا.'));
        }

        try {
            $done = QuickLink::cancel($gateway, (string) $link->provider_link_id);
        } catch (QuickLinkFailed $e) {
            throw new RuntimeException(__('تعذّر إلغاء الرابط').' — '.$e->getMessage(), 0, $e);
        }

        if (! $done) {
            throw new RuntimeException(__('لم تؤكّد Paymob إلغاء الرابط — لم يُلغَ.'));
        }

        // وشرطيًّا: إشعارُ دفعٍ سبقنا إليه لا يُكتب فوقه «ملغى»
        $changed = CustomerPaymentLink::whereKey($link->id)
            ->where('status', CustomerPaymentLink::PENDING)
            ->update(['status' => CustomerPaymentLink::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $userId, 'updated_at' => now()]);

        if ($changed === 0) {
            throw new RuntimeException(__('تغيّرت حالُ الرابط قبل إلغائه — افتحه من جديد.'));
        }

        Activity::log('deleted', 'ألغى رابط دفع بمبلغ '.number_format((float) $link->amount, 3).' '.$link->currency, [
            'subject_id' => $link->id, 'subject_type' => 'customer_payment_link', 'business_id' => (int) $link->business_id,
        ]);

        return $link->fresh();
    }

    /**
     * ما انقضت مهلتُه وهو معلَّق ← «منتهٍ». لا يمسّ تحصيلًا ولا يُلغي عند Paymob.
     *
     * @return int عددُ ما انتهى
     */
    public static function expireDue(?int $businessId = null): int
    {
        return CustomerPaymentLink::where('status', CustomerPaymentLink::PENDING)
            ->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->update(['status' => CustomerPaymentLink::EXPIRED, 'updated_at' => now()]);
    }

    /**
     * «روابط بانتظار الدفع» — طلباتُ مالٍ حقيقيّةٌ ما زالت تُدفع، لا دَينٌ ولا قبض.
     *
     * المعلَّقُ الحقيقيُّ الذي لم تنقضِ مهلتُه — ولو لم يمرّ عليه `expireDue`
     * بعد. فلا يُعدّ رابطُ التجربة ولا المدفوعُ ولا الملغى ولا الفاشلُ ولا المنتهي.
     *
     * ═══ ولا يُطلب للفاتورة أكثرُ من باقيها ═══
     *
     * رابطان على فاتورةٍ واحدة، أو رابطٌ سُدّد بعضُ فاتورته نقدًا بعده، يطلبان
     * أكثرَ ممّا عليها — ولا يُدفع منهما فوق الباقي إلّا رصيدًا للعميل. فيُعدّ
     * لكلّ فاتورةٍ الأقلُّ من مجموع روابطها وباقيها (`outstanding`)، والفارقُ
     * يُقال (`capped`) لا يُخفى. والرابطُ بلا فاتورةٍ بمبلغه: دفعةٌ مقدّمةٌ تُطلب.
     *
     * وهذا مؤشّرٌ لا دَين: ما على الفواتير منه داخلٌ في «مستحقات العملاء»
     * أصلًا (`Receivables::outstanding`) — فلا يُجمعان.
     *
     * والفرعُ فرعُ الفاتورة؛ والرابطُ بلا فاتورةٍ أو على فاتورةٍ بلا فرع غيرُ
     * منسوب (`unattributed`) — في «كل الفروع» وحده.
     *
     * @return array{amount: float, count: int, capped: float, unattributed: float}
     */
    public static function awaiting(int $businessId, ?int $branchId = null): array
    {
        $links = CustomerPaymentLink::where('business_id', $businessId)
            ->where('is_live', true)
            ->where('status', CustomerPaymentLink::PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['id', 'customer_invoice_id', 'amount']);

        $invoices = CustomerInvoice::where('business_id', $businessId)
            ->whereIn('id', $links->pluck('customer_invoice_id')->filter()->unique())
            ->get()->keyBy('id');

        $out = ['amount' => 0.0, 'count' => 0, 'capped' => 0.0, 'unattributed' => 0.0];

        foreach ($links->groupBy(fn ($l) => (int) $l->customer_invoice_id) as $invoiceId => $group) {
            $asked = round((float) $group->sum('amount'), 3);
            $invoice = $invoiceId ? $invoices->get($invoiceId) : null;
            // فاتورةٌ أُلغيت أو لم تعد في هذا المتجر لا يُطلب لها شيء
            $counted = $invoiceId
                ? ($invoice && $invoice->status === CustomerInvoice::ISSUED ? min($asked, $invoice->outstanding()) : 0.0)
                : $asked;
            $branch = $invoice?->branch_id;

            if ($branchId !== null && (int) $branch !== $branchId) {
                continue;
            }

            $out['amount'] += $counted;
            $out['count'] += $group->count();
            $out['capped'] += $asked - $counted;
            if ($branch === null) {
                $out['unattributed'] += $counted;
            }
        }

        return array_map(fn ($v) => is_float($v) ? round($v, 3) : $v, $out);
    }

    /**
     * مالٌ أبلغت Paymob باسترجاعه ولم يُلغَ تحصيلُه بعد — يُقال بجوار المقبوضات.
     *
     * الاسترجاعُ يُكتب على الرابط ولا يمسّ الدفتر (`notSettled`): التحصيلُ قائمٌ
     * حتّى يُلغيه صاحبُ المحلّ فيُعكس قيدُه يومَ إلغائه. وإلى أن يفعل، يبقى
     * المالُ في المقبوض كما يقوله الدفتر — فيُقال هذا الرقمُ بجواره كي لا يُقرأ
     * مالًا باقيًا.
     */
    public static function refundsToReverse(int $businessId, ?int $branchId = null): float
    {
        return round((float) CustomerPaymentLink::query()->from('customer_payment_links as l')
            ->join('customer_payments as p', fn ($j) => $j->on('p.id', '=', 'l.customer_payment_id')
                ->on('p.business_id', '=', 'l.business_id'))
            ->leftJoin('customer_invoices as i', fn ($j) => $j->on('i.id', '=', 'l.customer_invoice_id')
                ->on('i.business_id', '=', 'l.business_id'))
            ->where('l.business_id', $businessId)
            ->where('l.status', CustomerPaymentLink::PAID)
            ->where('l.is_live', true)
            ->whereNull('p.cancelled_at')
            ->when($branchId !== null, fn ($q) => $q->where('i.branch_id', $branchId))
            ->get(['l.amount', 'l.meta'])
            ->filter(fn ($l) => filled(((array) ($l->meta ?? []))['provider_refund'] ?? null))
            ->sum('amount'), 3);
    }

    /* ═══════════ ما تعرضه الشاشات ═══════════ */

    /**
     * صفُّ رابطٍ كما تقرؤه الشاشة — ولا سرَّ فيه: الرابطُ القصير هو ما يُرسَل
     * للعميل أصلًا، ولا مفتاحَ ولا رمزَ ولا رقمَ بطاقة.
     *
     * @return array<string, mixed>
     */
    public static function row(CustomerPaymentLink $link): array
    {
        $extra = (array) (($link->meta ?? [])['extra_transactions'] ?? []);

        return [
            'id' => $link->id,
            'reference' => $link->reference,
            'customer' => ['id' => $link->customer_id, 'name' => $link->customer?->name ?? '—'],
            'invoice' => $link->invoice ? ['id' => $link->invoice->id, 'number' => $link->invoice->number] : null,
            'description' => $link->description,
            'amount' => (float) $link->amount,
            'currency' => $link->currency,
            'status' => $link->status,
            'is_live' => (bool) $link->is_live,
            // والرابطُ يُعطى ما دام يُدفع أو دُفع — والفاشلُ لا رابطَ له أصلًا
            'url' => in_array($link->status, [CustomerPaymentLink::PENDING, CustomerPaymentLink::PAID], true) ? $link->short_url : null,
            'created_at' => optional($link->created_at)->format('Y-m-d H:i'),
            'expires_at' => optional($link->expires_at)->format('Y-m-d H:i'),
            'paid_at' => optional($link->paid_at)->format('Y-m-d H:i'),
            'payment' => $link->payment ? ['id' => $link->payment->id, 'number' => $link->payment->number] : null,
            'error' => $link->status === CustomerPaymentLink::PAID ? null : $link->error,
            'review' => $extra !== [] || isset(($link->meta ?? [])['provider_refund']),
        ];
    }

    /**
     * لوحةُ روابط صفحةٍ — للفاتورة أو للعميل: الحالُ والصلاحيةُ والروابط.
     *
     * @param  callable(Builder): mixed  $scope
     * @return array<string, mixed>
     */
    public static function panel(int $businessId, callable $scope): array
    {
        self::expireDue($businessId);

        $user = auth()->user();
        $query = CustomerPaymentLink::where('business_id', $businessId)
            ->where('status', '!=', CustomerPaymentLink::FAILED)
            ->with(['customer:id,name', 'invoice:id,number', 'payment:id,number'])
            ->latest('id')->limit(50);
        $scope($query);

        return [
            'ready' => self::ready($businessId),
            /*
             * طلبُ الدفع برابطٍ فعلٌ باسمه (`PAYMENT_LINK_CREATE`) — لا «المالية»
             * ولا التحصيل. وإلغاءُ رابطٍ قائم فعلٌ آخر: يبقى لمن يملك التحصيل
             * وبابُه «المالية» كما كان — فمن يُمنح الطلبَ لا يُلغي روابطَ غيره.
             */
            'can' => (bool) $user?->may(Permissions::PAYMENT_LINK_CREATE),
            'can_cancel' => (bool) ($user?->may(Permissions::CUSTOMER_PAYMENT_CREATE) && $user?->allows('finance')),
            'settings_url' => ($user?->allows('integrations') ?? false) ? route('admin.integrations.paymob') : null,
            'links' => $query->get()->map(fn (CustomerPaymentLink $l) => self::row($l))->all(),
        ];
    }

    /* ═══════════ الإشعار ═══════════ */

    /**
     * إشعارُ Paymob لرابط دفع — `false` إن لم يكن لرابطٍ نعرفه (فهو لغيرنا).
     *
     * والرابطُ يُعرف بطلب Paymob الذي فُتح عليه (`provider_order_id` من ردّ
     * الإنشاء)، أو بمرجعنا إن عاد في `merchant_order_id`. والمتجرُ من صفّه.
     *
     * @param  array<string, mixed>  $obj
     */
    public static function callback(array $obj, ?string $hmac): bool
    {
        $order = trim((string) data_get($obj, 'order.id', ''));
        $merchant = trim((string) data_get($obj, 'order.merchant_order_id', ''));

        $link = null;
        if ($order !== '') {
            $link = CustomerPaymentLink::where('provider_order_id', $order)->first();
        }
        if ($link === null && str_starts_with($merchant, 'pay-')) {
            $link = CustomerPaymentLink::where('reference', $merchant)->first();
        }

        if ($link === null) {
            return false;
        }

        // سرُّ توقيع متجر الرابط — ولو أُطفئت بوّابتُه بعد الفتح: المالُ دُفع
        $gateway = PaymobSettings::row((int) $link->business_id);

        if ($gateway === null || ! Paymob::verify($obj, $hmac, (string) $gateway->hmac_secret)) {
            Log::warning('paymob link: توقيعٌ لا يطابق', ['link' => $link->id]);

            /*
             * ويُقال على الرابط — فهو أرجحُ أسباب «دفع العميلُ ولم يُسجَّل»:
             * HMAC المحفوظُ هنا غيرُ ما في لوحة Paymob. وكان يُكتب في سجلّ
             * الخادم وحده، فلا يرى صاحبُ المحلّ إلّا «بانتظار الدفع».
             *
             * وعلى المعلَّق وحده، ولا يمسّ مالًا ولا حالًا: من يطرق بلا سرٍّ
             * لا يكتب إلّا هذه الجملة، ودفعٌ صحيحٌ بعدها يمحوها (`settle`).
             */
            if ($link->status === CustomerPaymentLink::PENDING) {
                self::note($link, 'وصل إشعارُ دفعٍ بتوقيعٍ لا يطابق HMAC المحفوظ — راجع HMAC في إعدادات Paymob.');
            }

            return true;
        }

        $flag = fn (string $key) => filter_var(data_get($obj, $key), FILTER_VALIDATE_BOOL);

        // والقاعدةُ نفسُها في `PaymobController::callback`: نجحت، ولم تُعلَّق، ولم تُلغَ، ولم تُستردّ
        $settled = $flag('success') && ! $flag('pending') && ! $flag('is_voided') && ! $flag('is_refunded');

        if (! $settled) {
            self::notSettled($link, $obj, $flag);

            return true;
        }

        $wanted = Amount::toMinor((float) $link->amount, (string) $link->currency);
        $got = (int) data_get($obj, 'amount_cents', 0);
        $currency = strtoupper(trim((string) data_get($obj, 'currency', '')));

        if ($got !== $wanted || $currency !== strtoupper((string) $link->currency)) {
            Log::warning('paymob link: مبلغٌ أو عملةٌ لا تطابق الرابط', [
                'link' => $link->id, 'wanted' => $wanted, 'got' => $got, 'currency' => $currency,
            ]);
            self::note($link, 'المبلغُ أو العملةُ لا يطابقان الرابط');

            return true;
        }

        // وبيئةٌ غيرُ بيئة الرابط لا تُقبل: دفعةُ تجربةٍ ليست مالًا
        if (data_get($obj, 'is_live') !== null && $flag('is_live') !== (bool) $link->is_live) {
            Log::warning('paymob link: بيئةٌ لا تطابق الرابط', ['link' => $link->id]);
            self::note($link, 'بيئةُ الدفعة (Test/Live) لا تطابق الرابط');

            return true;
        }

        $transaction = trim((string) data_get($obj, 'id', ''));

        if ($transaction === '') {
            self::note($link, 'إشعارٌ بلا رقم عمليّة');

            return true;
        }

        self::settle($link, $transaction);

        /*
         * ═══ وبعد أن ثبت المال — لا في معاملته ═══
         *
         * فاتورةٌ طُلب تجهيزُها تظهر في لوحة التجهيز إن سُدّدت كلُّها
         * (`CustomerInvoicePreparations`). وخارجَ معاملة `settle` عن قصد:
         * سقوطٌ هناك يُسقط التحصيلَ معه، ويُقال لـPaymob «تمّ» عن مالٍ لم
         * يُسجَّل. ولا يرمي، ويُنادى في الإشعار المكرّر أيضًا — فكلُّ إعادةٍ
         * من Paymob محاولةٌ ثانيةٌ للتفعيل، ولا تفعيلَ مرّتين.
         */
        CustomerInvoicePreparations::afterLinkPaid($link);

        /*
         * ونقاطُ الولاء على ما خُصِّص للفاتورة — بعد التحصيل والتجهيز، وبمحاولةٍ
         * مستقلّة: سقوطُها لا يمسّ مالًا ولا مهمّةَ تجهيز، يُكتب على الرابط
         * ويُعاد (`InvoiceLoyalty::reconcile`). والإشعارُ المكرّر لا يمنح مرّتين.
         */
        InvoiceLoyalty::afterLinkPaid($link);

        return true;
    }

    /**
     * يُثبّت الدفع ويكتب تحصيلَه — مرّةً واحدةً مهما تكرّر الإشعار.
     *
     * ═══ والمالُ كلُّه يُسجَّل، والتخصيصُ بقدر الباقي ═══
     *
     * رابطٌ فُتح على مئةٍ ثمّ سُجّلت قبل دفعه دفعةٌ يدويّةٌ بثلاثين: باقي
     * الفاتورة سبعون. والعميلُ دفع مئةً فعلًا — فيُسجَّل التحصيلُ مئةً،
     * ويُخصَّص منه سبعون، ويبقى ثلاثون رصيدًا للعميل (`CustomerPayments`
     * يقصّ بالباقي). ولا يُعدَّل مبلغُ Paymob، ولا يضيع مال.
     */
    public static function settle(CustomerPaymentLink $link, string $transactionId): ?CustomerPayment
    {
        try {
            return DB::transaction(function () use ($link, $transactionId) {
                $locked = CustomerPaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();

                if ($locked->provider_transaction_id === $transactionId) {
                    return $locked->payment;
                }

                if ($locked->provider_transaction_id !== null) {
                    /*
                     * عمليّةٌ ثانيةٌ على رابطٍ دُفع — لا تُكتب تحصيلًا ثانيًا في صمت.
                     *
                     * تُحفظ على الرابط ويُنبَّه بها في السجلّ: مالٌ دخل Paymob
                     * ولا ينتظره رابط، يردّه صاحبُ المحلّ أو يُسجّله بيده.
                     */
                    $meta = (array) ($locked->meta ?? []);
                    $extra = array_values(array_unique([...((array) ($meta['extra_transactions'] ?? [])), $transactionId]));
                    $locked->forceFill(['meta' => ['extra_transactions' => $extra] + $meta])->save();

                    Activity::log('warning', 'دفعةٌ ثانيةٌ عبر Paymob على رابطٍ مدفوع ('.$transactionId.') — تحتاج مراجعة', [
                        'subject_id' => $locked->id, 'subject_type' => 'customer_payment_link', 'business_id' => (int) $locked->business_id,
                    ]);

                    return null;
                }

                /*
                 * ═══ ودفعةُ التجربة تُغلق الرابط ولا تمسّ الدفاتر ═══
                 *
                 * رابطٌ فُتح ببوّابةٍ في بيئة التجربة (`is_live = false`) لا يُدفع
                 * إلّا ببطاقة تجربة: Paymob تردّ رقمَ تكاملٍ من غير بيئة الرابط.
                 * وكان يُكتب تحصيلًا حقيقيًّا — مدين «Paymob تحت التسوية» بمالٍ لن
                 * تحوّله Paymob أبدًا، ودائن ذمم العميل — فتُرى فاتورتُه مسدَّدةً
                 * بما لم يدفعه، ويُعرض للتسوية البنكيّة.
                 *
                 * فيُوسم «مدفوعًا» بعمليّته — يرى من يجرّب أنّ الدفعَ وصل والإشعارَ
                 * صُدّق — بلا تحصيلٍ ولا قيدٍ ولا تخصيصٍ ولا نقاطٍ ولا رسالة. والحكمُ
                 * بصفّ الرابط لا بـ`is_live` في الإشعار: ذاك حقلٌ خارج التوقيع
                 * (`Paymob::HMAC_FIELDS`).
                 */
                if (! $locked->is_live) {
                    $locked->forceFill([
                        'status' => CustomerPaymentLink::PAID,
                        'paid_at' => now(),
                        'provider_transaction_id' => $transactionId,
                        'error' => null,
                    ])->save();

                    Activity::log('created', 'دُفع رابط دفعٍ تجريبيّ عبر Paymob بمبلغ '.number_format((float) $locked->amount, 3).' '.$locked->currency
                        .' — دفعة تجربة: لا تحصيل ولا قيد', [
                            'subject_id' => $locked->id, 'subject_type' => 'customer_payment_link', 'business_id' => (int) $locked->business_id,
                        ]);

                    return null;
                }

                $customer = Customer::withTrashed()->where('business_id', $locked->business_id)->findOrFail($locked->customer_id);
                $invoiceId = $locked->customer_invoice_id;

                $payment = CustomerPayments::record(
                    (int) $locked->business_id,
                    $customer,
                    (float) $locked->amount,
                    [
                        'method' => CustomerPayments::PAYMOB,
                        'occurred_at' => now()->toDateString(),
                        'external_reference' => Str::limit($transactionId, 80, ''),
                        'notes' => Str::limit(__('رابط دفع').' '.$locked->reference, 500, ''),
                        // بلا فاتورةٍ يبقى رصيدًا للعميل — لا سدادًا لأقدم فواتيره
                        'auto_allocate' => false,
                    ],
                    $invoiceId ? [(int) $invoiceId => (float) $locked->amount] : [],
                    null,
                );

                $locked->forceFill([
                    'status' => CustomerPaymentLink::PAID,
                    'paid_at' => now(),
                    'provider_transaction_id' => $transactionId,
                    'customer_payment_id' => $payment->id,
                    'error' => null,
                    /*
                     * «بانتظار النقاط» — يكتبه التحصيلُ الجديد وحده، في معاملته.
                     * فرابطٌ سُدّد قبل اكتساب النقاط على الروابط لا علَمَ له، ولا
                     * يمنحه إشعارٌ مكرّرٌ نقاطًا عن مالٍ قديم (`InvoiceLoyalty`).
                     */
                    'meta' => $locked->customer_invoice_id
                        ? ['loyalty' => InvoiceLoyalty::PENDING] + (array) ($locked->meta ?? [])
                        : $locked->meta,
                ])->save();

                Activity::log('created', 'دُفع رابط الدفع عبر Paymob بمبلغ '.number_format((float) $locked->amount, 3).' '.$locked->currency
                    .' — التحصيل '.$payment->number, [
                        'subject_id' => $locked->id, 'subject_type' => 'customer_payment_link', 'business_id' => (int) $locked->business_id,
                    ]);

                /*
                 * وتصل الفاتورةُ مَن دفعها على واتساب — Paymob تُبقيه على صفحتها
                 * بلا ورقة. وفي المعاملة نفسِها: صفُّ الرسالة يُكتب معها أو لا
                 * يُكتب، والإرسالُ بعد تثبيتها (`SendWhatsAppMessage::afterCommit`).
                 */
                WhatsAppAutomation::handlePaidLink($locked);

                return $payment;
            });
        } catch (QueryException $e) {
            // سبقَنا إشعارٌ بالعمليّة نفسِها إلى الفهرس الفريد — وهو نجاحٌ لا خطأ
            Log::info('paymob link: عمليّةٌ سُجّلت قبلُ', ['link' => $link->id]);

            return null;
        }
    }

    /**
     * دفعةٌ لم تُحتسب — يُكتب سببُها، والرابطُ يبقى ينتظر.
     *
     * بطاقةٌ رُدّت لا تُغلق الرابط: يعيد العميلُ بأخرى. والمعلَّقةُ لا تُكتب
     * فشلًا. والاسترجاعُ على رابطٍ مدفوعٍ يُقال ولا يمحو التحصيل — الاسترجاعُ
     * طريقٌ مستقلّ يعكس المال.
     */
    private static function notSettled(CustomerPaymentLink $link, array $obj, callable $flag): void
    {
        if ($flag('pending') && $flag('success')) {
            return;
        }

        if ($link->status === CustomerPaymentLink::PAID && ($flag('is_refunded') || $flag('is_voided'))) {
            $meta = (array) ($link->meta ?? []);
            $link->forceFill(['meta' => ['provider_refund' => $flag('is_refunded') ? 'refunded' : 'voided'] + $meta])->save();

            Activity::log('warning', 'أبلغت Paymob باسترجاع دفعة رابط مدفوع — التحصيل باقٍ حتّى يُعكس بيده', [
                'subject_id' => $link->id, 'subject_type' => 'customer_payment_link', 'business_id' => (int) $link->business_id,
            ]);

            return;
        }

        self::note($link, match (true) {
            $flag('is_voided') => 'أُلغيت الدفعةُ قبل تسويتها',
            $flag('is_refunded') => 'استُرجعت الدفعة',
            default => (string) data_get($obj, 'data.message', 'رُدّت الدفعة'),
        });
    }

    private static function note(CustomerPaymentLink $link, string $why): void
    {
        $link->forceFill(['error' => Str::limit($why, 500)])->save();
    }

    /** الهاتفُ بصيغةٍ دوليّة كما تطلبه Paymob — والعُمانيُّ المحلّيّ يُكمَّل بـ+968 */
    private static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return match (true) {
            $digits === '' => null,
            str_starts_with($digits, '968') && strlen($digits) === 11 => '+'.$digits,
            strlen($digits) === 8 => '+968'.$digits,
            default => '+'.ltrim($digits, '0'),
        };
    }

    /** مهلةُ Paymob كما ردّت — أو `null` إن لم تُقرأ */
    private static function expiry(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}

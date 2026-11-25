<?php

namespace App\Support\RibbonPortal;

use App\Models\Customer;
use App\Models\CustomerPortalLink as Link;
use App\Models\CustomerPortalSession as Session;
use App\Models\User;
use App\Support\Activity;
use App\Support\Contention;
use App\Support\Digits;
use App\Support\WhatsAppPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * بابُ البوابة — رابطٌ واحدٌ للجميع: رقمُ الهاتف ورمزٌ من فاتورته، ثمّ جلسةٌ على جهازه.
 *
 * ═══ عاملان لا واحد ═══
 *
 * الرقمُ يعرفه كلُّ من يعرف صاحبَه، فلا يكفي وحدَه ليُقرأ ما اشترى ولمن أهدى: رقمُه
 * المسجَّل يقول من هو، ورمزُ فاتورته (`Code`) يقول إنّ حاملَه صاحبُه. ويُطلبان مرّةً
 * على الجهاز، ثمّ تُحفظ الجلسة. ولا يُسأل الرقمُ وحده عن أحد: الاثنان في طلبٍ واحد
 * وردٌّ واحد، فلا يُعرف من هذا الباب أرقمٌ مسجَّلٌ أم لا.
 *
 * ═══ والإذنُ صفٌّ لكلّ عميل ═══
 *
 * يُكتب عند أوّل دخول (`customer_portal_links`)، وتتبعه جلساتُه. وما صدر فيه رابطٌ
 * شخصيٌّ قبل الرابط الواحد يبقى يعمل برقم صاحبه حتّى يُلغى، ولا يُصدر جديد.
 *
 * ═══ ويُسأل في كلّ طلب ═══
 *
 * الإذنُ لم يُلغَ، والعميلُ لم يُحذف، ورقمُه هو الذي دخل به. فما تغيّر منها يُغلق
 * الجلساتِ كلَّها في الطلب التالي — لا حين تنقضي.
 */
final class Access
{
    public const COOKIE = 'rb_my';

    /** عمرُ الجلسة على الجهاز — يتجدّد بالزيارة */
    public const DAYS = 180;

    public const REVOKED_BY_STAFF = 'staff';

    public const REVOKED_PHONE = 'phone_changed';

    public const REVOKED_CUSTOMER = 'customer_gone';

    /** محاولاتٌ خاطئة: للرقم (أو الرابط القديم) في ربع ساعة، وله في اليوم، وللعنوان في الساعة */
    private const LIMITS = ['who' => [5, 900], 'day' => [10, 86400], 'ip' => [20, 3600]];

    /* ─────────────────────────── الإذن ─────────────────────────── */

    /** الرقمُ المسجَّل الذي يُدخله العميل — هاتفُه، وإلّا رقمُ تواصله */
    public static function phone(Customer $customer): ?string
    {
        return self::normalize((string) (filled($customer->phone) ? $customer->phone : $customer->contact_phone));
    }

    /** بأيّ صيغةٍ كُتب — بقاعدة النظام (`WhatsAppPhone`): أرقامٌ عربيّة، ومفتاحٌ أو بلا مفتاح، ومسافات */
    public static function normalize(string $raw): ?string
    {
        return WhatsAppPhone::normalize(Digits::western($raw));
    }

    /** إذنُه القائم — أو `null` لمن لم يدخل بعد */
    public static function live(Customer $customer): ?Link
    {
        $link = Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->whereNull('revoked_at')->first();

        return $link && self::stands($link) ? $link : null;
    }

    /** إذنُه — القائمُ وإلّا جديد. وجهازان يدخلان معًا لا يكتبان إذنين: يردّ الفهرسُ الثاني فيُقرأ الأوّل */
    private static function grant(Customer $customer): ?Link
    {
        return self::live($customer)
            ?? Contention::attempt(fn () => DB::transaction(fn () => self::write($customer)))
            ?? self::live($customer);
    }

    private static function write(Customer $customer): Link
    {
        $link = new Link([
            'business_id' => (int) $customer->business_id,
            'customer_id' => (int) $customer->id,
            'nonce' => bin2hex(random_bytes(32)),
            'phone_hash' => self::phoneHash((string) self::phone($customer)),
        ]);
        // بصمةُ رمزٍ لا يُعطى لأحد: العمودُ من زمن الرابط الشخصيّ وهو فريد
        $link->token_hash = self::hash(self::token($link));
        $link->save();

        return $link;
    }

    /**
     * يُخرجه من أجهزته وتقف رموزُ ما طُبع من فواتيره — ولمن لم يدخل بعدُ كذلك.
     *
     * فاتورةٌ وقعت في يدِ غيره تُوقَف قبل أن يُدخل بها: صفٌّ يُكتب ملغًى يبدّل دورتَه (`Code`).
     */
    public static function revoke(Customer $customer, ?User $by): void
    {
        DB::transaction(function () use ($customer, $by) {
            $links = Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
                ->whereNull('revoked_at')->lockForUpdate()->get();
            ($links->isEmpty() ? collect([self::write($customer)]) : $links)
                ->each(fn (Link $l) => self::close($l, $by, self::REVOKED_BY_STAFF));
        });
    }

    /** كم جهازًا داخلٌ الآن */
    public static function devices(Link $link): int
    {
        return $link->sessions()->whereNull('revoked_at')->where('expires_at', '>', now())->count();
    }

    /** كلُّ من دخل — للطوارئ */
    public static function revokeAll(int $businessId, User $by): int
    {
        return DB::transaction(function () use ($businessId, $by) {
            $links = Link::where('business_id', $businessId)->whereNull('revoked_at')->lockForUpdate()->get();
            $links->each(fn (Link $l) => self::close($l, $by, self::REVOKED_BY_STAFF));

            return $links->count();
        });
    }

    private static function close(Link $link, ?User $by, string $why): void
    {
        $link->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id, 'revoked_why' => $why])->save();
        Session::where('link_id', $link->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /** أما زال قائمًا؟ — لم يُلغَ، وصاحبُه باقٍ في المتجر، ورقمُه هو الذي دخل به. وما سقط يُغلق الآن. */
    private static function stands(Link $link): bool
    {
        if ($link->revoked_at !== null) {
            return false;
        }

        $customer = $link->customer;
        $why = match (true) {
            $customer === null || (int) $customer->business_id !== (int) $link->business_id => self::REVOKED_CUSTOMER,
            ! hash_equals($link->phone_hash, self::phoneHash((string) self::phone($customer))) => self::REVOKED_PHONE,
            default => null,
        };

        if ($why !== null) {
            self::close($link, null, $why);

            return false;
        }

        return true;
    }

    /* ─────────────────────────── الدخول ─────────────────────────── */

    /**
     * يدخل برقم هاتفه ورمز فاتورته — رمزُ الجلسة، أو `null` لأيّ خطأٍ كان.
     *
     * ردٌّ واحدٌ لرقمٍ غير مسجَّلٍ ولرمزٍ لا يطابق ولرابطٍ أُلغي: من يجرّب لا يعرف أيَّها أصاب.
     *
     * @param  string  $key  رابطٌ شخصيٌّ صدر قبل الرابط الواحد — أو فارغ
     */
    public static function login(int $businessId, string $phone, string $code, string $key, Request $request): ?string
    {
        $given = self::normalize($phone);
        $link = $given === null ? null : ($key !== '' ? self::byKey($businessId, $key, $given) : self::byCode($businessId, $code, $given));

        if ($link === null) {
            foreach (self::keys($phone, $key, $request) as $name => $limiter) {
                RateLimiter::hit($limiter, self::LIMITS[$name][1]);
            }

            return null;
        }

        RateLimiter::clear(self::keys($phone, $key, $request)['who']);

        $raw = bin2hex(random_bytes(32));
        Session::create([
            'business_id' => $businessId,
            'customer_id' => $link->customer_id,
            'link_id' => $link->id,
            'token_hash' => self::hash($raw),
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(self::DAYS),
        ]);
        $link->forceFill(['last_used_at' => now()])->save();

        // وما انقضى منذ شهرٍ لا يبقى
        Session::where('business_id', $businessId)->where('expires_at', '<', now()->subDays(30))->delete();

        return $raw;
    }

    /**
     * صاحبُ الرقم الذي يفتحه الرمز.
     *
     * وسجلّان برقمٍ واحدٍ لا يُدمجان: الرمزُ من فاتورةِ أحدهما فهو صاحبُها. ورقمٌ غيرُ
     * مسجَّلٍ يُحسب له كما يُحسب لسواه — فلا يُعرف من زمن الردّ أنّه مسجَّل.
     */
    private static function byCode(int $businessId, string $code, string $given): ?Link
    {
        $code = Code::clean($code);
        if ($code === null) {
            return null;
        }

        $same = Customer::where('business_id', $businessId)
            ->where(fn ($q) => $q->where('phone', '!=', '')->orWhere('contact_phone', '!=', ''))
            ->get(['id', 'business_id', 'phone', 'contact_phone'])
            ->filter(fn (Customer $c) => self::phone($c) === $given)->values();

        if ($same->isEmpty()) {
            Code::opens((new Customer)->forceFill(['id' => 0, 'business_id' => $businessId]), $code);

            return null;
        }

        $owner = $same->first(fn (Customer $c) => Code::opens($c, $code));

        return $owner === null ? null : self::grant($owner);
    }

    /** رابطٌ شخصيٌّ صدر قبل الرابط الواحد — يعمل برقم صاحبه حتّى يُلغى */
    private static function byKey(int $businessId, string $key, string $given): ?Link
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $key)) {
            return null;
        }

        $link = Link::with('customer')->where('token_hash', self::hash($key))->where('business_id', $businessId)->whereNotNull('created_by')->first();

        return $link && self::stands($link) && hash_equals($link->phone_hash, self::phoneHash($given)) ? $link : null;
    }

    /** كم ثانيةً حتى تُقبل محاولة — أو صفر */
    public static function blockedFor(string $phone, string $key, Request $request): int
    {
        $wait = 0;
        foreach (self::keys($phone, $key, $request) as $name => $limiter) {
            if (RateLimiter::tooManyAttempts($limiter, self::LIMITS[$name][0])) {
                $wait = max($wait, RateLimiter::availableIn($limiter));
            }
        }

        return $wait;
    }

    /** @return array{who: string, day: string, ip: string} */
    private static function keys(string $phone, string $key, Request $request): array
    {
        // بصمةٌ لا الرقمُ نفسُه: لا يُكتب رقمٌ في العدّاد
        $who = $key !== '' ? self::hash($key) : self::phoneHash(self::normalize($phone) ?? 'none');

        return ['who' => 'ribbon-portal:w:'.$who, 'day' => 'ribbon-portal:d:'.$who, 'ip' => 'ribbon-portal:ip:'.$request->ip()];
    }

    /* ─────────────────────────── الجلسة ─────────────────────────── */

    /** جلسةُ هذا الطلب — قائمةً وصاحبُها ورابطُه قائمان، وإلّا `null` */
    public static function session(int $businessId, Request $request): ?Session
    {
        $raw = $request->cookie(self::COOKIE);
        if (! is_string($raw) || ! preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return null;
        }

        $session = Session::with('link.customer')->where('token_hash', self::hash($raw))
            ->where('business_id', $businessId)->whereNull('revoked_at')->where('expires_at', '>', now())->first();

        if (! $session || ! $session->link || (int) $session->link->customer_id !== (int) $session->customer_id || ! self::stands($session->link)) {
            return null;
        }

        // والزيارةُ تُطيلها — كتابةٌ كلَّ ساعةٍ لا كلَّ نبضة
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subHour())) {
            $session->forceFill(['last_seen_at' => now(), 'expires_at' => now()->addDays(self::DAYS)])->save();
        }

        return $session;
    }

    public static function logout(?Session $session): void
    {
        $session?->forceFill(['revoked_at' => now()])->save();
    }

    /** كعكةٌ لمسار البوابة وحده: لا يقرؤها المتجرُ ولا نصٌّ في الصفحة ولا موقعٌ آخر */
    public static function cookie(Request $request, ?string $raw): Cookie
    {
        return Cookie::create(
            self::COOKIE, (string) $raw, $raw === null ? 1 : now()->addDays(self::DAYS),
            '/'.Portal::PATH, null, $request->isSecure() || app()->isProduction(), true, false, Cookie::SAMESITE_LAX,
        );
    }

    /* ─────────────────────────── الأسرار ─────────────────────────── */

    /** مفتاحُ البوابة من مفتاح الخادم — تقرؤه `Code` كذلك */
    public static function secret(): string
    {
        return hash_hmac('sha256', 'ribbon-customer-portal', (string) config('app.key'), true);
    }

    private static function token(Link $link): string
    {
        $raw = hash_hmac('sha256', 'link|'.$link->business_id.'|'.$link->customer_id.'|'.$link->nonce, self::secret(), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function hash(?string $value): string
    {
        return hash('sha256', (string) $value);
    }

    /** والرقمُ بمفتاح الخادم لا بصمةً عارية: أرقامُ عُمان تُعدّ كلُّها في دقائق */
    private static function phoneHash(string $normalized): string
    {
        return hash_hmac('sha256', 'phone|'.$normalized, self::secret());
    }

    /** سطرُ السجلّ — بلا رمزٍ ولا رابط */
    public static function log(string $what, Customer $customer): void
    {
        Activity::log('updated', $what.' — '.$customer->name, ['subject_id' => $customer->id, 'subject_type' => 'customer']);
    }
}

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
 * بابُ البوابة — رابطٌ شخصيٌّ ورقمُ هاتفه، ثمّ جلسةٌ على جهازه.
 *
 * ═══ عاملان لا واحد ═══
 *
 * الرقمُ يُخمَّن والرابطُ يُعاد توجيهه، فلا يكفي أيُّهما: الرابطُ (256 بتًّا)
 * يقول من هو، ورقمُه المسجَّل يقول إنّ حاملَه صاحبُه. ولا يُسأل الرقمُ وحده
 * عن أحد، فلا يُعرف من هذا الباب أرقمٌ مسجَّلٌ أم لا.
 *
 * ═══ والرمزُ لا يُحفظ ═══
 *
 * بصمتُه وحدها في القاعدة. ويُشتقّ ثانيةً بمفتاح الخادم من `nonce` ليُنسخ
 * الرابطُ نفسُه بعد كلّ بيعةٍ بلا إبطال ما في يد العميل. ويُرسل في جزء `#`
 * من العنوان: لا يبلغ الخادمَ ولا سجلَّه ولا المُحيل، وتقرؤه الصفحةُ وترسله
 * في جسم الطلب.
 *
 * ═══ ويُسأل في كلّ طلب ═══
 *
 * الرابطُ لم يُلغَ، والعميلُ لم يُحذف، ورقمُه هو الذي صدر به الرابط. فما تغيّر
 * منها يُغلق الجلساتِ كلَّها في الطلب التالي — لا حين تنقضي.
 */
final class Access
{
    public const COOKIE = 'rb_my';

    /** عمرُ الجلسة على الجهاز — يتجدّد بالزيارة */
    public const DAYS = 90;

    public const REVOKED_BY_STAFF = 'staff';

    public const REVOKED_REISSUED = 'reissued';

    public const REVOKED_PHONE = 'phone_changed';

    public const REVOKED_CUSTOMER = 'customer_gone';

    /** محاولاتٌ خاطئة: للرابط في ربع ساعة، وله في اليوم، وللعنوان في الساعة */
    private const LIMITS = ['token' => [5, 900], 'day' => [15, 86400], 'ip' => [20, 3600]];

    /* ─────────────────────────── الرابط ─────────────────────────── */

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

    /** الرابطُ الحيّ لهذا العميل — صالحًا، وإلّا `null` */
    public static function live(Customer $customer): ?Link
    {
        $link = Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->whereNull('revoked_at')->first();

        return $link && self::stands($link) ? $link : null;
    }

    /**
     * رابطُ العميل ليُشارَك — القائمُ إن كان صالحًا، وإلّا جديد.
     *
     * @return array{url: string, link: Link, created: bool}|null `null` لمن لا رقمَ له يُدخله
     */
    public static function share(Customer $customer, ?User $by): ?array
    {
        if (self::phone($customer) === null) {
            return null;
        }

        $link = self::live($customer);
        $token = $link ? self::token($link) : null;

        // ومفتاحُ خادمٍ تبدّل لا يشتقّ الرمزَ القديم: يُصدر بدلَه
        if ($link && hash_equals($link->token_hash, self::hash($token))) {
            return ['url' => Portal::url().'#'.$token, 'link' => $link, 'created' => false];
        }

        return self::reissue($customer, $by);
    }

    /** رابطٌ جديد — والقديمُ وجلساتُه يُغلقان معًا */
    public static function reissue(Customer $customer, ?User $by): ?array
    {
        $phone = self::phone($customer);
        if ($phone === null) {
            return null;
        }

        $write = fn () => DB::transaction(function () use ($customer, $by, $phone) {
            Link::where('customer_id', $customer->id)->whereNull('revoked_at')->lockForUpdate()->get()
                ->each(fn (Link $old) => self::close($old, $by, self::REVOKED_REISSUED));

            $link = new Link([
                'business_id' => (int) $customer->business_id,
                'customer_id' => (int) $customer->id,
                'nonce' => bin2hex(random_bytes(32)),
                'phone_hash' => self::phoneHash($phone),
                'created_by' => $by?->id,
            ]);
            $link->token_hash = self::hash(self::token($link));
            $link->save();

            return $link;
        });

        // موظّفان في اللحظة نفسها: يردّ الفهرسُ الثاني فيقرأ ما كتبه الأوّل
        $link = Contention::attempt($write) ?? self::live($customer);

        return $link ? ['url' => Portal::url().'#'.self::token($link), 'link' => $link, 'created' => true] : null;
    }

    public static function revoke(Customer $customer, ?User $by): bool
    {
        return DB::transaction(function () use ($customer, $by) {
            $links = Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
                ->whereNull('revoked_at')->lockForUpdate()->get();
            $links->each(fn (Link $l) => self::close($l, $by, self::REVOKED_BY_STAFF));

            return $links->isNotEmpty();
        });
    }

    /** كلُّ روابط المتجر — للطوارئ */
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

    /** أما زال قائمًا؟ — لم يُلغَ، وصاحبُه باقٍ في المتجر، ورقمُه هو الذي صدر به. وما سقط يُغلق الآن. */
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
     * يدخل بالرابط ورقم الهاتف — رمزُ الجلسة، أو `null` لأيّ خطأٍ كان.
     *
     * ردٌّ واحدٌ لرابطٍ لا وجودَ له ولرابطٍ أُلغي ولرقمٍ لا يطابق: من يجرّب لا
     * يعرف أيَّها أصاب.
     */
    public static function login(int $businessId, string $token, string $phone, Request $request): ?string
    {
        $link = self::find($businessId, $token);
        $given = self::normalize($phone);

        if (! $link || $given === null || ! hash_equals($link->phone_hash, self::phoneHash($given))) {
            foreach (self::keys($token, $request) as $name => $key) {
                RateLimiter::hit($key, self::LIMITS[$name][1]);
            }

            return null;
        }

        RateLimiter::clear(self::keys($token, $request)['token']);

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

    /** كم ثانيةً حتى تُقبل محاولة — أو صفر */
    public static function blockedFor(string $token, Request $request): int
    {
        $wait = 0;
        foreach (self::keys($token, $request) as $name => $key) {
            if (RateLimiter::tooManyAttempts($key, self::LIMITS[$name][0])) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    /** أهذا الرابطُ لصاحب هذه الجلسة؟ */
    public static function sameCustomer(int $businessId, string $token, ?Session $session): bool
    {
        return $session !== null && self::find($businessId, $token)?->customer_id === $session->customer_id;
    }

    private static function find(int $businessId, string $token): ?Link
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $link = Link::with('customer')->where('token_hash', self::hash($token))->where('business_id', $businessId)->first();

        return $link && self::stands($link) ? $link : null;
    }

    /** @return array{token: string, day: string, ip: string} */
    private static function keys(string $token, Request $request): array
    {
        $t = self::hash($token);

        return ['token' => 'ribbon-portal:t:'.$t, 'day' => 'ribbon-portal:d:'.$t, 'ip' => 'ribbon-portal:ip:'.$request->ip()];
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

    private static function token(Link $link): string
    {
        $raw = hash_hmac('sha256', 'link|'.$link->business_id.'|'.$link->customer_id.'|'.$link->nonce, self::key(), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function hash(?string $value): string
    {
        return hash('sha256', (string) $value);
    }

    /** والرقمُ بمفتاح الخادم لا بصمةً عارية: أرقامُ عُمان تُعدّ كلُّها في دقائق */
    private static function phoneHash(string $normalized): string
    {
        return hash_hmac('sha256', 'phone|'.$normalized, self::key());
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'ribbon-customer-portal', (string) config('app.key'), true);
    }

    /** سطرُ السجلّ — بلا رمزٍ ولا رابط */
    public static function log(string $what, Customer $customer): void
    {
        Activity::log('updated', $what.' — '.$customer->name, ['subject_id' => $customer->id, 'subject_type' => 'customer']);
    }
}

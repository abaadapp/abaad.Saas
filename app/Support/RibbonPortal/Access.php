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
 * بابُ البوابة — رابطٌ واحدٌ للجميع: يكتب العميلُ رقمَ هاتفه، ويُعتمد جهازُه مرّةً (`Devices`).
 *
 * ═══ الرقمُ يقول من هو، والجهازُ المعتمد يقول إنّه هو ═══
 *
 * فلا يُقرأ حسابٌ برقمٍ وحده: الرقمُ يعرفه كلُّ من يعرف صاحبَه. والجهازُ يُعرف بعد اعتماده
 * بكعكةٍ لا يقرؤها نصُّ صفحةٍ ولا موقعٌ آخر، فيدخل منه صاحبُه بلا كتابة شيء.
 *
 * ═══ والإذنُ صفٌّ لكلّ عميل ═══
 *
 * يُكتب عند أوّل اعتماد (`customer_portal_links`)، وتتبعه جلساتُ أجهزته.
 *
 * ═══ ويُسأل في كلّ طلب ═══
 *
 * الإذنُ لم يُلغَ، والعميلُ لم يُحذف، ورقمُه هو الذي اعتُمد به. فما تغيّر منها يُغلق
 * الجلساتِ كلَّها في الطلب التالي — لا حين تنقضي.
 */
final class Access
{
    public const COOKIE = 'rb_my';

    /** عمرُ الجلسة على الجهاز المعتمد — يتجدّد بالزيارة */
    public const DAYS = 180;

    public const REVOKED_BY_STAFF = 'staff';

    public const REVOKED_PHONE = 'phone_changed';

    public const REVOKED_CUSTOMER = 'customer_gone';

    /** طلباتٌ جديدة: للرقم من عنوانٍ واحد في ربع ساعة، وللرقم في اليوم، وللعنوان في الساعة */
    private const LIMITS = ['who' => [5, 900], 'day' => [20, 86400], 'ip' => [40, 3600]];

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

    /** إذنُه القائم — أو `null` لمن لم يُعتمد له جهاز */
    public static function live(Customer $customer): ?Link
    {
        $link = Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->whereNull('revoked_at')->first();

        return $link && self::stands($link) ? $link : null;
    }

    /** إذنُه — القائمُ وإلّا جديد. وموظّفان يعتمدان معًا لا يكتبان إذنين: يردّ الفهرسُ الثاني فيُقرأ الأوّل */
    public static function grant(Customer $customer): ?Link
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
        // العمودُ فريدٌ ولا يفتح شيئًا: رابطٌ شخصيٌّ لا يوجد، والدخولُ بجهازٍ معتمد
        $link->token_hash = self::hash(self::token($link));
        $link->save();

        return $link;
    }

    /** يفتح جلسةَ الجهاز الذي اعتُمد — وإن لم يعد صاحبُه إليها في يومها انقضت */
    public static function open(Link $link, string $tokenHash): Session
    {
        return Session::create([
            'business_id' => $link->business_id,
            'customer_id' => $link->customer_id,
            'link_id' => $link->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addHours(Devices::HOURS),
        ]);
    }

    /** يُخرجه من أجهزته كلِّها، ويُغلق ما ينتظر منها */
    public static function revoke(Customer $customer, ?User $by): void
    {
        DB::transaction(function () use ($customer, $by) {
            Devices::drop($customer);
            Link::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
                ->whereNull('revoked_at')->lockForUpdate()->get()
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
            Devices::dropAll($businessId);
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

    /** أما زال قائمًا؟ — لم يُلغَ، وصاحبُه باقٍ في المتجر، ورقمُه هو الذي اعتُمد به. وما سقط يُغلق الآن. */
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

    /* ─────────────────────────── حدُّ المحاولات ─────────────────────────── */

    /** كم ثانيةً حتى يُقبل طلبٌ جديد — أو صفر */
    public static function blockedFor(string $phone, Request $request): int
    {
        $wait = 0;
        foreach (self::keys($phone, $request) as $name => $limiter) {
            if (RateLimiter::tooManyAttempts($limiter, self::LIMITS[$name][0])) {
                $wait = max($wait, RateLimiter::availableIn($limiter));
            }
        }

        return $wait;
    }

    /** ويُحسب كلُّ طلبٍ جديد، لرقمٍ مسجَّلٍ أو غيره */
    public static function count(string $phone, Request $request): void
    {
        foreach (self::keys($phone, $request) as $name => $limiter) {
            RateLimiter::hit($limiter, self::LIMITS[$name][1]);
        }
    }

    /** @return array{who: string, day: string, ip: string} */
    private static function keys(string $phone, Request $request): array
    {
        // بصمةٌ لا الرقمُ نفسُه: لا يُكتب رقمٌ في العدّاد
        $who = self::phoneHash((string) self::normalize($phone));

        return ['who' => 'ribbon-portal:w:'.$who.':'.$request->ip(), 'day' => 'ribbon-portal:d:'.$who, 'ip' => 'ribbon-portal:ip:'.$request->ip()];
    }

    /* ─────────────────────────── الجلسة ─────────────────────────── */

    /** جلسةُ هذا الطلب — قائمةً وصاحبُها وإذنُه قائمان، وإلّا `null` */
    public static function session(int $businessId, Request $request): ?Session
    {
        // وجهازٌ دخل قبل أن يتغيّر اسمُ الكعكة يبقى داخلًا
        foreach (array_unique([self::raw($request), self::read($request, self::COOKIE)]) as $raw) {
            $session = $raw === null ? null : Session::with('link.customer')->where('token_hash', self::hash($raw))
                ->where('business_id', $businessId)->whereNull('revoked_at')->where('expires_at', '>', now())->first();

            if ($session && $session->link && (int) $session->link->customer_id === (int) $session->customer_id && self::stands($session->link)) {
                return self::visited($session);
            }
        }

        return null;
    }

    /** والزيارةُ تُطيلها — كتابةٌ كلَّ ساعةٍ لا كلَّ نبضة */
    private static function visited(Session $session): Session
    {
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subHour())) {
            $session->forceFill(['last_seen_at' => now(), 'expires_at' => now()->addDays(self::DAYS)])->save();
            $session->link->forceFill(['last_used_at' => now()])->save();
        }

        return $session;
    }

    public static function logout(?Session $session): void
    {
        $session?->forceFill(['revoked_at' => now()])->save();
    }

    /** رمزُ هذا الجهاز من كعكته — أو `null` */
    public static function raw(Request $request): ?string
    {
        return self::read($request, self::name($request));
    }

    /** رمزُ الكعكة التي فُتحت بها هذه الجلسة — باسمها اليوم أو باسمها من قبل */
    public static function held(Request $request, Session $session): ?string
    {
        return collect([self::raw($request), self::read($request, self::COOKIE)])
            ->first(fn (?string $raw) => $raw !== null && hash_equals($session->token_hash, self::hash($raw)));
    }

    private static function read(Request $request, string $name): ?string
    {
        $raw = $request->cookie($name);

        return is_string($raw) && preg_match('/^[a-f0-9]{64}$/', $raw) ? $raw : null;
    }

    /**
     * `__Host-`: لا يكتبها إلّا هذا المضيفُ نفسُه.
     *
     * فلا تزرع صفحةٌ على نطاقٍ شقيقٍ (`*.abaadapp.om`) رمزَ جهازِ غيره في متصفّح العميل، فيعتمد
     * الموظّفُ ذاك وهو يظنّه هذا. وتلزم لها `https` — وبلاها (بيئةُ التطوير) الاسمُ وحده.
     */
    public static function name(Request $request): string
    {
        return (self::secure($request) ? '__Host-' : '').self::COOKIE;
    }

    private static function secure(Request $request): bool
    {
        return $request->isSecure() || app()->isProduction();
    }

    /** كعكةُ هذا المضيف: لا يقرؤها نصٌّ في الصفحة ولا موقعٌ آخر */
    public static function cookie(Request $request, ?string $raw): Cookie
    {
        return Cookie::create(
            self::name($request), (string) $raw, $raw === null ? 1 : now()->addDays(self::DAYS),
            self::secure($request) ? '/' : '/'.Portal::PATH, null, self::secure($request), true, false, Cookie::SAMESITE_LAX,
        );
    }

    /* ─────────────────────────── الأسرار ─────────────────────────── */

    /** مفتاحُ البوابة من مفتاح الخادم */
    public static function secret(): string
    {
        return hash_hmac('sha256', 'ribbon-customer-portal', (string) config('app.key'), true);
    }

    private static function token(Link $link): string
    {
        $raw = hash_hmac('sha256', 'link|'.$link->business_id.'|'.$link->customer_id.'|'.$link->nonce, self::secret(), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function hash(?string $value): string
    {
        return hash('sha256', (string) $value);
    }

    /** والرقمُ بمفتاح الخادم لا بصمةً عارية: أرقامُ عُمان تُعدّ كلُّها في دقائق */
    public static function phoneHash(string $normalized): string
    {
        return hash_hmac('sha256', 'phone|'.$normalized, self::secret());
    }

    /** سطرُ السجلّ — بلا رمزٍ ولا خانات */
    public static function log(string $what, Customer $customer): void
    {
        Activity::log('updated', $what.' — '.$customer->name, ['subject_id' => $customer->id, 'subject_type' => 'customer']);
    }
}

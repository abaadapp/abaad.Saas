<?php

namespace App\Support\RibbonPortal;

use App\Models\Customer;
use App\Models\CustomerPortalLink as Link;
use App\Models\CustomerPortalSession as Session;
use App\Models\User;
use App\Support\Activity;
use App\Support\Contention;
use App\Support\Digits;
use App\Support\Lists\SalesRegister;
use App\Support\WhatsAppPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * بابُ البوابة — رابطٌ واحدٌ للجميع: يكتب العميلُ رقمَ هاتفه المسجَّل فتفتح صفحتُه.
 *
 * ═══ الرقمُ وحده ═══
 *
 * قرارُ المالك (2026-11-25): لا رقمَ جهازٍ ولا اعتمادَ موظّف ولا رمزَ ولا كلمةَ مرور. فمن يعرف
 * رقمَ عميلٍ يفتح صفحتَه — يُقال ذلك لصاحب النشاط في شاشته، وله أن يوقف صفحةَ من يطلب (`stop`).
 * وتُحدّ المحاولاتُ (`LIMITS`): فلا تُجرَّب الأرقامُ لتُعرف، ولا تُفتح صفحاتُ قائمةٍ منها.
 *
 * ═══ والجهازُ يُذكر ═══
 *
 * كعكةٌ لا يقرؤها نصُّ صفحةٍ ولا موقعٌ آخر: يدخل منه صاحبُه بعدها بلا كتابة شيء.
 *
 * ═══ ويُسأل في كلّ طلب ═══
 *
 * لم يُوقَف، ولم يُحذف العميل، ورقمُه هو الذي دخل به. فما تغيّر منها يُغلق الجلساتِ كلَّها في
 * الطلب التالي — لا حين تنقضي.
 */
final class Access
{
    public const COOKIE = 'rb_my';

    /** عمرُ الجلسة على الجهاز — يتجدّد بالزيارة */
    public const DAYS = 90;

    /** أوقف الموظّفُ صفحتَه: لا تُفتح برقمه حتّى يُسمح بها */
    public const STOPPED = 'staff';

    /** إيقافٌ سُمح بعده */
    private const LIFTED = 'staff_lifted';

    /** دخولٌ سبق الإيقافَ بلحظة */
    private const LATE = 'stopped';

    public const REVOKED_PHONE = 'phone_changed';

    public const REVOKED_CUSTOMER = 'customer_gone';

    /**
     * دخولٌ جديد: للرقم من عنوانٍ واحد في ربع ساعة، وللرقم في اليوم، وللعنوان في الساعة وفي اليوم،
     * وأرقامٌ لا عميلَ لها من عنوانٍ واحد في الساعة. ومن دخل من قبل لا يُسأل عنها.
     */
    private const LIMITS = ['who' => [5, 900], 'day' => [20, 86400], 'ip' => [20, 3600], 'ipday' => [60, 86400], 'miss' => [10, 3600]];

    /* ─────────────────────────── الإذن ─────────────────────────── */

    /** الرقمُ المسجَّل الذي يُدخله العميل — هاتفُه، وإلّا رقمُ تواصله */
    public static function phone(Customer $customer): ?string
    {
        return self::normalize(self::shown($customer));
    }

    /** كما كُتب في سجلّه — يقرؤه الموظّفُ ليتّصل به هو */
    public static function shown(Customer $customer): string
    {
        return (string) (filled($customer->phone) ? $customer->phone : $customer->contact_phone);
    }

    /** بأيّ صيغةٍ كُتب — بقاعدة النظام (`WhatsAppPhone`): أرقامٌ عربيّة، ومفتاحٌ أو بلا مفتاح، ومسافات */
    public static function normalize(string $raw): ?string
    {
        return WhatsAppPhone::normalize(Digits::western($raw));
    }

    /**
     * صاحبُ هذا الرقم في المتجر — أو `null`. والمحذوفُ لا يُقرأ.
     *
     * ورقمٌ في سجلّين (كُتب قبل أن يُمنع التكرار) يفتح الذي اشترى آخرًا.
     */
    private static function holder(int $businessId, string $phone): ?Customer
    {
        $found = Customer::where('business_id', $businessId)
            ->where(fn ($q) => $q->where('phone', '!=', '')->orWhere('contact_phone', '!=', ''))
            ->orderBy('id')->get()
            ->filter(fn (Customer $c) => self::phone($c) === $phone);

        return $found->count() < 2 ? $found->first() : $found
            ->sortByDesc(fn (Customer $c) => (string) SalesRegister::forCustomer($businessId, (int) $c->id)->max('r.sold_at'))->first();
    }

    /**
     * يدخل برقم هاتفه — ويردّ رمزَ جهازه، أو `null`: لا عميلَ بهذا الرقم، أو أوقف المتجرُ صفحتَه.
     *
     * ولا حسابَ يُنشأ هنا. ومن كان داخلًا باسم غيره على هذا الجهاز خرج.
     */
    public static function enter(int $businessId, string $phone, Request $request): ?string
    {
        $customer = self::holder($businessId, $phone);
        $link = $customer === null || self::stopped($customer) ? null : self::grant($customer);
        if ($link === null) {
            return null;
        }

        self::logout(self::session($businessId, $request));

        $raw = bin2hex(random_bytes(32));
        Session::create([
            'business_id' => $link->business_id,
            'customer_id' => $link->customer_id,
            'link_id' => $link->id,
            'token_hash' => self::hash($raw),
            'expires_at' => now()->addDays(self::DAYS),
        ]);

        return $raw;
    }

    /** إذنُه القائم — أو `null` لمن لم يدخل */
    public static function live(Customer $customer): ?Link
    {
        $link = self::links($customer)->whereNull('revoked_at')->first();

        return $link && self::stands($link) ? $link : null;
    }

    /** إذنُه — القائمُ وإلّا جديد. ودخولان معًا لا يكتبان إذنين: يردّ الفهرسُ الثاني فيُقرأ الأوّل */
    private static function grant(Customer $customer): ?Link
    {
        return self::live($customer)
            ?? Contention::attempt(fn () => DB::transaction(fn () => self::write($customer)))
            ?? self::live($customer);
    }

    private static function write(Customer $customer, array $with = []): Link
    {
        // `token_hash` و`nonce` عمودان لازمان لا يفتحان شيئًا: رابطٌ شخصيٌّ لا يوجد
        return Link::create($with + [
            'business_id' => (int) $customer->business_id,
            'customer_id' => (int) $customer->id,
            'nonce' => bin2hex(random_bytes(32)),
            'token_hash' => self::hash(bin2hex(random_bytes(32))),
            'phone_hash' => self::phoneHash((string) self::phone($customer)),
        ]);
    }

    /** أوقف المتجرُ صفحةَ هذا العميل؟ */
    public static function stopped(Customer $customer): bool
    {
        return self::links($customer)->where('revoked_why', self::STOPPED)->exists();
    }

    /** يُخرجه من أجهزته كلِّها، ولا تُفتح صفحتُه برقمه حتّى يُسمح بها — ولو لم يدخل بعد */
    public static function stop(Customer $customer, ?User $by): void
    {
        DB::transaction(function () use ($customer, $by) {
            $open = self::links($customer)->whereNull('revoked_at')->lockForUpdate()->get();
            $open->each(fn (Link $l) => self::close($l, $by, self::STOPPED));
            // ومن لم يدخل: صفٌّ مُغلقٌ من أوّله — فلا يزاحم دخولًا يقع الآن، ويُغلقه في طلبه التالي
            if (! self::stopped($customer)) {
                self::write($customer, ['revoked_at' => now(), 'revoked_by' => $by?->id, 'revoked_why' => self::STOPPED]);
            }
        });
    }

    public static function allow(Customer $customer): void
    {
        self::links($customer)->where('revoked_why', self::STOPPED)->update(['revoked_why' => self::LIFTED, 'updated_at' => now()]);
    }

    /** يُخرج الجميع من أجهزتهم — ويدخل كلٌّ منهم برقمه من جديد */
    public static function signOutAll(int $businessId): int
    {
        return Session::where('business_id', $businessId)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /**
     * كم جهازًا داخلٌ الآن بكلّ إذن.
     *
     * @param  list<int>  $links
     * @return array<int, int>
     */
    public static function devices(array $links): array
    {
        return Session::whereIn('link_id', $links)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->selectRaw('link_id, count(*) as n')->groupBy('link_id')->pluck('n', 'link_id')->map(fn ($n) => (int) $n)->all();
    }

    private static function links(Customer $customer): Builder
    {
        return Link::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id);
    }

    private static function close(Link $link, ?User $by, string $why): void
    {
        $link->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id, 'revoked_why' => $why])->save();
        Session::where('link_id', $link->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /** أما زال قائمًا؟ — لم يُوقَف، وصاحبُه باقٍ في المتجر، ورقمُه هو الذي دخل به. وما سقط يُغلق الآن. */
    private static function stands(Link $link): bool
    {
        if ($link->revoked_at !== null) {
            return false;
        }

        $customer = $link->customer;
        $why = match (true) {
            $customer === null || (int) $customer->business_id !== (int) $link->business_id => self::REVOKED_CUSTOMER,
            ! hash_equals($link->phone_hash, self::phoneHash((string) self::phone($customer))) => self::REVOKED_PHONE,
            self::stopped($customer) => self::LATE,
            default => null,
        };

        if ($why !== null) {
            self::close($link, null, $why);

            return false;
        }

        return true;
    }

    /* ─────────────────────────── حدُّ المحاولات ─────────────────────────── */

    /** كم ثانيةً حتى يُقبل دخولٌ جديد — أو صفر. `$phone` مطبَّعًا، وفي العدّاد بصمتُه لا هو */
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

    /** يُحسب كلُّ دخولٍ يُطلب، ورقمٌ لا عميلَ له يُحسب مرّتين: مع غيره، ووحدَه (`$missed`) */
    public static function count(string $phone, Request $request, bool $missed = false): void
    {
        foreach (self::keys($phone, $request) as $name => $limiter) {
            if (($name === 'miss') === $missed) {
                RateLimiter::hit($limiter, self::LIMITS[$name][1]);
            }
        }
    }

    /** @return array<string, string> */
    private static function keys(string $phone, Request $request): array
    {
        $key = self::phoneHash($phone);
        $ip = $request->ip();

        return [
            'who' => 'ribbon-portal:w:'.$key.':'.$ip, 'day' => 'ribbon-portal:d:'.$key,
            'ip' => 'ribbon-portal:ip:'.$ip, 'ipday' => 'ribbon-portal:id:'.$ip, 'miss' => 'ribbon-portal:m:'.$ip,
        ];
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
     * فلا تزرع صفحةٌ على نطاقٍ شقيقٍ (`*.abaadapp.om`) جلسةَ غيره في متصفّح العميل. وتلزم لها
     * `https` — وبلاها (بيئةُ التطوير) الاسمُ وحده.
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

    public static function hash(?string $value): string
    {
        return hash('sha256', (string) $value);
    }

    /** والرقمُ بمفتاح الخادم لا بصمةً عارية: أرقامُ عُمان تُعدّ كلُّها في دقائق */
    public static function phoneHash(string $normalized): string
    {
        return hash_hmac('sha256', 'phone|'.$normalized, self::secret());
    }

    /** سطرُ السجلّ — بلا رمزٍ ولا رقم */
    public static function log(string $what, Customer $customer): void
    {
        Activity::log('updated', $what.' — '.$customer->name, ['subject_id' => $customer->id, 'subject_type' => 'customer']);
    }
}

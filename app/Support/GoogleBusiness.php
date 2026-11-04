<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\GoogleBusinessAccount;
use App\Models\GoogleBusinessReview;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ملفُّ الأعمال على Google — قراءةُ تقييمات المتجر والردُّ عليها.
 *
 * ═══ وهو بابٌ آخرُ غيرُ Places ═══
 *
 * `GooglePlaces` يقرأ الملفَّ **العامّ** بمفتاحٍ: اسمٌ ومعدّلٌ وعددٌ وخمسةُ
 * نصوصٍ تختارها Google. ولا رَدَّ فيه ولا قائمةَ تقييماتٍ كاملة.
 *
 * وهذا يفتح ملفَّ التاجر نفسِه بإذنه (OAuth): يقرأ تقييماته كلَّها ويردّ
 * عليها باسمه. ويحتاج ثلاثةً معًا — عميلَ OAuth، وسرَّه، ووصولًا **معتمَدًا**
 * من Google إلى «Business Profile APIs». وغيابُ أيٍّ منها يعني أنّ الميزة
 * غيرُ موصولة، وتُقال غيرَ موصولة.
 *
 * ═══ ولا يُدَّعى نشرٌ لم يقع ═══
 *
 * `reply` لا تكتب حرفًا في قاعدتنا قبل أن تردّ Google بالقبول. وردٌّ يُعرض
 * «منشورًا» ولم يُنشر يجعل التاجر يظنّ أنّه أجاب زبونًا لم يصله شيء — وهو
 * لا يفتح ملفَّه ليتحقّق.
 *
 * ═══ ولا يُكتب رمزٌ في سجلٍّ ولا في رسالة خطأ ═══
 */
final class GoogleBusiness
{
    /** الإذنُ المطلوب — إدارةُ ملفّ الأعمال */
    public const SCOPE = 'https://www.googleapis.com/auth/business.manage';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const ACCOUNTS_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    private const INFO_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    /** التقييماتُ والردُّ ما زالا على v4 — لا نسخةَ أحدثَ لهما عند Google */
    private const REVIEWS_URL = 'https://mybusiness.googleapis.com/v4/';

    /** أقصى ما تقبله Google في الصفحة الواحدة، كلٌّ بواجهته */
    private const REVIEWS_PAGE = 50;

    private const ACCOUNTS_PAGE = 20;

    private const LOCATIONS_PAGE = 100;

    /**
     * سقفُ الصفحات — ٢٠٬٠٠٠ تقييم للفرع الواحد.
     *
     * حارسٌ لا حدٌّ يُبلَغ: Google تُنهي القائمة بغياب `nextPageToken`، ورمزٌ
     * لا ينتهي يعني حلقةً لا تقف. وما بلغ السقف مزامنةٌ لم تكتمل، فلا يُختم بها
     * تقييمٌ واحد.
     */
    private const MAX_REVIEW_PAGES = 400;

    private const MAX_LIST_PAGES = 50;

    /* ═══ رحلةُ التاجر — حالٌ واحدة تقرؤها الشاشات ولوحةُ المنصّة ═══ */

    /** لم يُهيَّأ عميلُ OAuth لأبعاد بعد — شأنُ المنصّة لا التاجر */
    public const UNAVAILABLE = 'unavailable';

    /** لم يأذن التاجر، أو فصل بيده */
    public const OFF = 'off';

    /** انقطع الإذن من جهة Google — يُعيده التاجر بنفسه */
    public const RECONNECT = 'reconnect';

    /** مشروعُ أبعاد عند Google لم يُعتمد أو واجهاتُه غير مفعَّلة */
    public const API_BLOCKED = 'api_blocked';

    /** أذِن، ولم يربط فرعًا بموقعه بعد */
    public const LINK_BRANCHES = 'link_branches';

    public const READY = 'ready';

    /* ═══════════════════ الحال ═══════════════════ */

    /**
     * هل التكاملُ مهيَّأٌ أصلًا؟
     *
     * ثلاثةٌ معًا. وغيابُ واحدٍ يعني بابًا يُعرض ولا يُفتح: يضغطه التاجر
     * فيُنقل إلى صفحةِ خطأٍ عند Google لا يفهم منها شيئًا.
     */
    public static function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (blank(config('services.google_business.'.$key))) {
                return false;
            }
        }

        return true;
    }

    /** إذنُ هذا المتجر — أو لا شيء إن لم يُمنح أو فُصل */
    public static function for(int $businessId): ?GoogleBusinessAccount
    {
        $account = GoogleBusinessAccount::where('business_id', $businessId)->first();

        return $account && $account->isLive() ? $account : null;
    }

    /**
     * أين وصل التاجر — حالٌ واحدة، ومعها الخرائط وعدُّ الفروع.
     *
     * والخرائطُ لا تُغني عن الإذن: مفتاحُها يقرأ الملفَّ العامّ، ومعرّفُ المكان
     * لا يفتح ملفَّ أحد. فـ`maps` تُعرض بجوار الحال ولا تدخل فيها.
     *
     * @return array{state:string, maps:bool, locations:int, branches:int}
     */
    public static function state(int $businessId): array
    {
        $account = GoogleBusinessAccount::where('business_id', $businessId)->first();
        $branches = Branch::where('business_id', $businessId)->pluck('id');
        $locations = BranchGooglePlace::whereNotNull('gbp_location')->whereIn('branch_id', $branches)->count();

        return [
            'state' => match (true) {
                ! self::configured() => self::UNAVAILABLE,
                self::needsReconnect($businessId) => self::RECONNECT,
                $account === null || ! $account->isLive() => self::OFF,
                $account->api_blocked_at !== null => self::API_BLOCKED,
                $locations === 0 => self::LINK_BRANCHES,
                default => self::READY,
            },
            // صفٌّ لا معرّفَ مكانٍ فيه ربطُ ملفِّ أعمالٍ وحده، لا ربطُ خرائط
            'maps' => BranchGooglePlace::query()->linked()->whereIn('branch_id', $branches)
                ->where('place_id', '!=', '')->exists(),
            'locations' => $locations,
            'branches' => $branches->count(),
        ];
    }

    /* ═══════════════════ الإذن ═══════════════════ */

    /**
     * أمنحت Google إذنَ إدارة الملفّ فعلًا؟
     *
     * صفحةُ الموافقة تتيح للمستخدم أن يمنح بعضَ ما طُلب. والرمزُ يعود ناجحًا
     * على كلّ حال، ثمّ يُردّ كلُّ نداءٍ بـ٤٠٣ ولا أحد يعرف لماذا. فيُقرأ الممنوح
     * من الردّ لا ممّا طلبناه، وردٌّ بلا `scope` لا يُعدّ رفضًا.
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function granted(array $tokens): bool
    {
        $scope = trim((string) ($tokens['scope'] ?? ''));

        return $scope === '' || in_array(self::SCOPE, preg_split('/\s+/', $scope) ?: [], true);
    }

    /**
     * عنوانُ صفحة الإذن عند Google.
     *
     * و`state` كلمةٌ عشوائيّةٌ تُحفظ في الجلسة وتُقارَن عند العودة: بلا ذلك
     * يستطيع موقعٌ آخر أن يقود التاجر إلى ربط **حسابٍ ليس حسابه** بمتجره.
     *
     * و`prompt=consent` مع `access_type=offline` معًا: بدونهما لا تُعيد
     * Google رمزَ تجديدٍ لمن أذن مرّةً من قبل — فتعمل المزامنة ساعةً ثمّ
     * تتوقّف بلا سببٍ ظاهر.
     */
    public static function authUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google_business.client_id'),
            'redirect_uri' => config('services.google_business.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** كلمةٌ عشوائيّةٌ للحالة — تُحفظ في الجلسة وتُقارَن عند العودة */
    public static function newState(): string
    {
        return Str::random(40);
    }

    /**
     * تبديلُ الرمز المؤقّت برمزَي الوصول والتجديد.
     *
     * @return array{ok:bool, error:?string, data:?array}
     */
    public static function exchange(string $code): array
    {
        return self::token([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google_business.redirect'),
        ]);
    }

    /**
     * حفظُ الإذن على المتجر — ومعه بريدُ الحساب ليعرف التاجر بأيِّه ربط.
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function store(int $businessId, array $tokens, ?User $by = null): GoogleBusinessAccount
    {
        /*
         * ورمزُ التجديد يُقرأ **قبل** الكتابة، لا بعدها.
         *
         * Google تُعيده في أوّل إذنٍ وحده أحيانًا. ولو كُتب `null` فوق
         * المحفوظ لَانقطعت المزامنة بعد ساعة، ولا شيء يقول لماذا: الشاشةُ
         * تقول «مربوط» والتقييماتُ تتوقّف.
         *
         * وقراءتُه بعد الحفظ لا تنفع — `getOriginal` تردّ ما حُفظ للتوّ.
         */
        $kept = GoogleBusinessAccount::where('business_id', $businessId)->first()?->refresh_token;

        $account = GoogleBusinessAccount::updateOrCreate(
            ['business_id' => $businessId],
            [
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => filled($tokens['refresh_token'] ?? null)
                    ? $tokens['refresh_token']
                    : $kept,
                'token_expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'scopes' => $tokens['scope'] ?? null,
                'linked_by' => $by?->id,
                'linked_at' => now(),
                'revoked_at' => null,
                'last_error' => null,
                // إذنٌ جديد يُعيد السؤال: أوّلُ نداءٍ يقول الحالَ الآن
                'api_blocked_at' => null,
            ] + array_filter(['account_email' => $tokens['email'] ?? null]),
        );

        Activity::log('settings', 'ربط ملفّ Google Business Profile');

        return $account;
    }

    /**
     * فصلُ الإذن — ويُسحب عند Google أيضًا لا عندنا وحدنا.
     *
     * ومحوُ الرمز من قاعدتنا لا يُنهي الإذن: يبقى قائمًا في حساب التاجر حتّى
     * يُلغيه بيده. فيُطلب السحبُ صراحةً، ويُمحى الرمزان أيًّا كان الردّ —
     * إخفاقُ السحب لا يعني أن نبقيهما.
     */
    public static function revoke(GoogleBusinessAccount $account): void
    {
        $token = $account->refresh_token ?: $account->access_token;

        if (filled($token)) {
            try {
                Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
            } catch (\Throwable) {
                // سحبٌ لم يصل — والرمزان يُمحيان عندنا على كلّ حال
            }
        }

        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'revoked_at' => now(),
            'api_blocked_at' => null,
        ])->save();

        Activity::log('settings', 'فصل ملفّ Google Business Profile');
    }

    /**
     * رمزُ وصولٍ صالحٌ الآن — يُجدَّد إن انتهى.
     *
     * ولا يُرمى استثناء: الفشلُ يُقيَّد في `last_error` ويُقرأ في الشاشة،
     * فيعرف التاجر أنّ عليه إعادةَ الإذن.
     */
    public static function accessToken(GoogleBusinessAccount $account): ?string
    {
        if (! $account->isLive()) {
            return null;
        }

        if (! $account->tokenExpired()) {
            return $account->access_token;
        }

        $result = self::token([
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $result['ok']) {
            /*
             * وإذنٌ سحبته Google يُمحى عندنا — لا يُبقى رمزٌ لا يفتح شيئًا.
             *
             * وبقاؤه كان يعني شاشةً تقول «مربوط» بخطأٍ أحمر تحتها ولا زرَّ
             * يُصلحه. فيُمحى الرمزان، ويبقى `revoked_at` فارغًا ليُعرف أنّ
             * التاجر لم يفصله بيده: فتعرض الشاشة «أعِد ربط حساب Google».
             */
            $account->forceFill(($result['revoked'] ?? false) ? [
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'last_error' => __('انتهت صلاحية الإذن — أعِد ربط حساب Google.'),
            ] : ['last_error' => $result['error']])->save();

            return null;
        }

        $account->forceFill([
            'access_token' => $result['data']['access_token'] ?? null,
            'token_expires_at' => isset($result['data']['expires_in'])
                ? now()->addSeconds((int) $result['data']['expires_in'])
                : null,
            'last_error' => null,
        ])->save();

        return $account->access_token;
    }

    /**
     * نداءُ رمزٍ — والسرُّ لا يُكتب في خطأٍ ولا سجلّ.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool, error:?string, data:?array}
     */
    private static function token(array $payload): array
    {
        try {
            $response = Http::asForm()->timeout(15)->acceptJson()->post(self::TOKEN_URL, $payload + [
                'client_id' => config('services.google_business.client_id'),
                'client_secret' => config('services.google_business.client_secret'),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => __('تعذّر الوصول إلى Google. حاول بعد قليل.'), 'data' => null];
        }

        if ($response->successful() && filled($response->json('access_token'))) {
            return ['ok' => true, 'error' => null, 'data' => $response->json()];
        }

        return [
            'ok' => false,
            'error' => self::readable($response->status(), (string) ($response->json('error_description') ?? $response->json('error') ?? '')),
            'data' => null,
            /*
             * وإذنٌ لم تعد Google تعترف به — سحبه صاحبُه من حسابه، أو انتهى.
             *
             * وهو غيرُ عطلٍ عابر: لا يزول بالانتظار ولا بالتكرار، وعلاجُه
             * إعادةُ الربط بيد التاجر نفسِه.
             */
            'revoked' => $response->json('error') === 'invalid_grant',
        ];
    }

    /**
     * هل انقطع إذنُ هذا المتجر من جهة Google؟
     *
     * صفٌّ قائمٌ لم يفصله التاجر بزرّه (`revoked_at` فارغ) ولا رمزَ تجديدٍ
     * فيه: سحبت Google الإذن أو انتهى. فتقول الشاشة «أعِد الربط» بزرٍّ
     * يفتح صفحة الإذن له هو — لا «لم يُربط بعد»، ولا «راجعنا».
     */
    public static function needsReconnect(int $businessId): bool
    {
        $account = GoogleBusinessAccount::where('business_id', $businessId)->first();

        return $account !== null && $account->revoked_at === null && blank($account->refresh_token);
    }

    /**
     * حالُ ملفّ الأعمال لمتجرٍ — كما تراه المنصّة: نعم ولا وتاريخ، بلا رمز.
     *
     * @return array{state:string, connected:bool, reconnect:bool, apiBlocked:bool, locations:int, syncedAt:?string, error:bool}
     */
    public static function status(int $businessId): array
    {
        $account = GoogleBusinessAccount::where('business_id', $businessId)->first();
        $journey = self::state($businessId);

        return [
            'state' => $journey['state'],
            'connected' => $account !== null && $account->isLive(),
            'reconnect' => self::needsReconnect($businessId),
            'apiBlocked' => $account?->api_blocked_at !== null,
            'locations' => $journey['locations'],
            'syncedAt' => optional($account?->synced_at)->toIso8601String(),
            'error' => filled($account?->last_error) || BranchGooglePlace::whereNotNull('gbp_sync_error')
                ->whereIn('branch_id', Branch::where('business_id', $businessId)->pluck('id'))->exists(),
        ];
    }

    /* ═══════════════════ الحساب والمواقع ═══════════════════ */

    /**
     * حساباتُ الأعمال التي يملكها المأذون.
     *
     * @return array{ok:bool, error:?string, accounts:list<array<string,string>>}
     */
    public static function accounts(GoogleBusinessAccount $account): array
    {
        $result = self::all($account, self::ACCOUNTS_URL, 'accounts', ['pageSize' => self::ACCOUNTS_PAGE]);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'accounts' => []];
        }

        $out = [];

        foreach ($result['rows'] as $row) {
            if (blank($row['name'] ?? null)) {
                continue;
            }

            $out[] = [
                'name' => (string) $row['name'],
                'title' => (string) ($row['accountName'] ?? $row['name']),
            ];
        }

        return ['ok' => true, 'error' => null, 'accounts' => $out];
    }

    /**
     * مواقعُ حسابٍ — ولكلٍّ منها فرعٌ عند التاجر.
     *
     * و`metadata` يحمل معرّفَ المكان على الخرائط: به يُعرف أيُّ موقعٍ هو فرعُ
     * التاجر المربوط هناك، فلا يُختار بالاسم وحده بين مواقعَ متشابهة.
     *
     * @return array{ok:bool, error:?string, locations:list<array<string,string>>}
     */
    public static function locations(GoogleBusinessAccount $account, string $accountName): array
    {
        $result = self::all($account, self::INFO_URL.$accountName.'/locations', 'locations', [
            // أقلُّ ما يُعرض في قائمةِ اختيار — والقناعُ إلزاميٌّ في هذه الواجهة
            'readMask' => 'name,title,storefrontAddress,metadata',
            'pageSize' => self::LOCATIONS_PAGE,
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'locations' => []];
        }

        $out = [];

        foreach ($result['rows'] as $row) {
            if (blank($row['name'] ?? null)) {
                continue;
            }

            $lines = (array) ($row['storefrontAddress']['addressLines'] ?? []);
            $city = (string) ($row['storefrontAddress']['locality'] ?? '');

            $out[] = [
                'name' => (string) $row['name'],
                'title' => (string) ($row['title'] ?? $row['name']),
                'address' => trim(implode('، ', array_filter($lines + [$city]))),
                'placeId' => (string) ($row['metadata']['placeId'] ?? ''),
            ];
        }

        return ['ok' => true, 'error' => null, 'locations' => $out];
    }

    /**
     * هل هذا الموقعُ من مواقع هذا الإذن؟ — يُسأل Google لا الطلب.
     *
     * المتصفّح يُرسل اسمَ حسابٍ واسمَ موقع، وكلاهما نصٌّ يُكتب بأيّ شيء. ولو
     * حُفظ كما وصل لَربط متجرٌ فرعه بموقعِ غيره، فتُسحب إليه تقييماتُ محلٍّ
     * لا يملكه — أو يُرفض كلُّ سحبٍ بعده بلا سببٍ مفهوم. فيُقرأ الموقع من
     * قائمة الحساب كما تردّها Google لهذا الإذن نفسِه.
     *
     * @return array{ok:bool, error:?string, location:?array<string,string>}
     */
    public static function ownedLocation(GoogleBusinessAccount $account, string $accountName, string $location): array
    {
        $accounts = self::accounts($account);

        if (! $accounts['ok']) {
            return ['ok' => false, 'error' => $accounts['error'], 'location' => null];
        }

        if (! in_array($accountName, array_column($accounts['accounts'], 'name'), true)) {
            return ['ok' => false, 'error' => __('هذا الموقع ليس من مواقع حساب Google المربوط.'), 'location' => null];
        }

        $found = self::locations($account, $accountName);

        if (! $found['ok']) {
            return ['ok' => false, 'error' => $found['error'], 'location' => null];
        }

        foreach ($found['locations'] as $row) {
            if ($row['name'] === $location) {
                return ['ok' => true, 'error' => null, 'location' => $row];
            }
        }

        return ['ok' => false, 'error' => __('هذا الموقع ليس من مواقع حساب Google المربوط.'), 'location' => null];
    }

    /**
     * ربطُ فرعٍ بموقعٍ في ملفّ الأعمال.
     *
     * والموقعُ لا يُربط بفرعين: تفرّدٌ في القاعدة يحرسه، ولولاه لَسُحبت
     * تقييماتُه مرّتين وعُدّت مرّتين ونُبّه بها مرّتين.
     *
     * والحسابُ يُكتب على صفّ الفرع: فرعان من حسابين لا يتزاحمان. و`account_name`
     * لا يُكتب فوقه — فرعٌ قديمٌ بلا حسابٍ يقرؤه، وكتابتُه تكسره بصمت.
     *
     * وصفٌّ جديدٌ يُولد مفكوكًا عن الخرائط: ربطُ ملفّ الأعمال ليس ربطَ خرائط،
     * ولولا الختم لَعدّته `BranchGoogle::for` فرعًا مربوطًا بلا معرّف مكان.
     */
    public static function linkLocation(Branch $branch, string $location, string $accountName): BranchGooglePlace
    {
        $row = BranchGooglePlace::firstOrNew(['branch_id' => $branch->id]);

        $row->forceFill([
            'place_id' => $row->place_id ?: '',
            'place_name' => $row->place_name ?: '',
            'gbp_location' => $location,
            'gbp_account' => $accountName,
            'gbp_linked_at' => now(),
            // موقعٌ آخر: ما قيل عن القديم لا يُقال عنه
            'gbp_synced_at' => $row->gbp_location === $location ? $row->gbp_synced_at : null,
            'gbp_sync_error' => null,
            'unlinked_at' => $row->exists ? $row->unlinked_at : now(),
        ])->save();

        GoogleBusinessAccount::where('business_id', $branch->business_id)
            ->whereNull('account_name')
            ->update(['account_name' => $accountName]);

        Activity::log('updated', 'ربط فرع «'.$branch->name.'» بموقعه في ملفّ Google Business', [
            'subject_id' => $branch->id, 'subject_type' => 'branch',
        ]);

        return $row;
    }

    /* ═══════════════════ التقييمات ═══════════════════ */

    /** حسابُ أعمال هذا الفرع — وصفٌّ قديمٌ بلا حسابٍ يقرأ حسابَ المتجر كما كان */
    public static function businessAccount(BranchGooglePlace $place, GoogleBusinessAccount $account): ?string
    {
        return $place->gbp_account ?: $account->account_name;
    }

    /**
     * سحبُ تقييمات فرعٍ — صفحةً بعد صفحةٍ حتى تنتهي القائمة.
     *
     * ═══ العطب الذي جاء منه هذا ═══
     *
     * كانت تُطلب الصفحةُ الأولى وحدها (٥٠ تقييمًا)، ثمّ يُختم كلُّ ما عداها
     * «اختفى». فمتجرٌ له مئةُ تقييمٍ يفقد من شاشته نصفَها بعد أوّل سحب: تختفي
     * تقييماتٌ قائمة، ولا يردّ عليها أحد، ولا شيء يقول لماذا.
     *
     * فالختمُ شرطُه أن تكتمل المزامنة: صفحاتٌ كلُّها، وردٌّ ناجحٌ لكلٍّ منها.
     * وفشلُ أيِّ صفحةٍ يُبقي ما قُرئ قبلها — صفوفٌ صحيحة تُكتب مرّةً أخرى بلا ضرر —
     * ولا يختم شيئًا.
     *
     * @return array{ok:bool, error:?string, new:int, total:int}
     */
    public static function syncReviews(Branch $branch): array
    {
        $account = self::for($branch->business_id);
        $place = BranchGooglePlace::where('branch_id', $branch->id)->first();
        $owner = $account && $place ? self::businessAccount($place, $account) : null;

        if (! $account || ! $place || blank($place->gbp_location) || blank($owner)) {
            return ['ok' => false, 'error' => __('هذا الفرع غير مربوط بملفّ الأعمال.'), 'new' => 0, 'total' => 0];
        }

        /*
         * وسحبان معًا لفرعٍ واحد: الدوريّ والزرّ.
         *
         * `review_id` فريدٌ في الجدول، فيسقط أحدهما باستثناء قاعدةٍ لا يفهمه
         * أحد في منتصف صفحة.
         */
        $lock = Cache::lock('gbp-sync:'.$branch->id, 600);

        if (! $lock->get()) {
            return ['ok' => false, 'error' => __('تُحدَّث تقييمات هذا الفرع الآن — انتظر قليلًا.'), 'new' => 0, 'total' => 0];
        }

        try {
            return self::pull($branch, $place, $account, $owner);
        } finally {
            $lock->release();
        }
    }

    /** @return array{ok:bool, error:?string, new:int, total:int} */
    private static function pull(Branch $branch, BranchGooglePlace $place, GoogleBusinessAccount $account, string $owner): array
    {
        $url = self::REVIEWS_URL.$owner.'/'.$place->gbp_location.'/reviews';

        /*
         * وأوّلُ سحبٍ لهذا الموقع لا «جديدَ» فيه.
         *
         * تقييماتُ سنتين تصل كلُّها اليوم، وكان يُقال عن كلٍّ منها «جديد» في
         * الجرس. فتُؤرَّخ يومئذٍ بتاريخها عند Google، ويبقى الجديدُ ما كُتب حقًّا.
         */
        $first = $place->gbp_synced_at === null;
        $seen = [];
        $new = 0;
        $listed = 0;
        $reported = null;
        $token = null;

        for ($page = 1; ; $page++) {
            if ($page > self::MAX_REVIEW_PAGES) {
                return self::pullFailed($place, $account, __('لم تكتمل قراءة التقييمات من Google. حاول بعد قليل.'), $new);
            }

            $result = self::get($account, $url, ['pageSize' => self::REVIEWS_PAGE] + array_filter(['pageToken' => $token]));

            if (! $result['ok']) {
                return self::pullFailed($place, $account, (string) $result['error'], $new);
            }

            $reported = isset($result['data']['totalReviewCount']) ? (int) $result['data']['totalReviewCount'] : $reported;

            foreach ((array) ($result['data']['reviews'] ?? []) as $review) {
                $listed++;
                $id = (string) ($review['reviewId'] ?? $review['name'] ?? '');

                if ($id === '') {
                    continue;
                }

                $existing = GoogleBusinessReview::where('review_id', $id)->first();

                /*
                 * وتقييمٌ محفوظٌ على فرعِ متجرٍ آخر لا يُنقل إلى هنا.
                 *
                 * المعرّفُ فريدٌ في الجدول كلِّه، فكان الصفُّ يُقرأ أينما كان ثمّ
                 * يُكتب عليه فرعُ هذا السحب — فيخرج تقييمٌ من متجرٍ ويدخل متجرًا
                 * آخر بردّه وتنبيهه. يُترك في موضعه، ولا يُعدّ في هذا السحب.
                 */
                if ($existing && $existing->branch_id !== $branch->id
                    && ! Branch::withTrashed()->where('business_id', $branch->business_id)->whereKey($existing->branch_id)->exists()) {
                    continue;
                }

                $seen[] = $id;

                $fields = [
                    'branch_id' => $branch->id,
                    'rating' => self::stars($review['starRating'] ?? null),
                    'comment' => (string) ($review['comment'] ?? '') ?: null,
                    'author' => (string) ($review['reviewer']['displayName'] ?? '') ?: null,
                    'author_photo' => $review['reviewer']['profilePhotoUrl'] ?? null,
                    'reviewed_at' => self::time($review['createTime'] ?? null),
                    'updated_google_at' => self::time($review['updateTime'] ?? null),
                    /*
                     * والردُّ يُقرأ من Google لا يُترك على ما عندنا.
                     *
                     * التاجر قد يردّ من تطبيق Google نفسِه، أو يُحذف ردُّه هناك.
                     * وشاشةٌ تعرض ردًّا لم يعد قائمًا تكذب بهدوء.
                     */
                    'reply' => (string) ($review['reviewReply']['comment'] ?? '') ?: null,
                    'replied_at' => self::time($review['reviewReply']['updateTime'] ?? null),
                    'gone_at' => null,
                ];

                if ($existing) {
                    $existing->forceFill($fields)->save();
                } else {
                    GoogleBusinessReview::create($fields + [
                        'review_id' => $id,
                        'first_seen_at' => $first ? ($fields['reviewed_at'] ?? now()) : now(),
                    ]);
                    $new++;
                }
            }

            $next = (string) ($result['data']['nextPageToken'] ?? '');

            if ($next === '') {
                break;
            }

            // رمزٌ يعود نفسُه يعني صفحةً تتكرّر لا قائمةً تنتهي
            if ($next === $token) {
                return self::pullFailed($place, $account, __('لم تكتمل قراءة التقييمات من Google. حاول بعد قليل.'), $new);
            }

            $token = $next;
        }

        /*
         * وما اختفى من Google يُختم ولا يُمحى.
         *
         * زبونٌ حذف تقييمه أو أزالته Google. وبقاؤه معروضًا يعني تاجرًا يردّ
         * على كلامٍ لم يعد موجودًا. والصفُّ يبقى لأنّه أثرُ ما جرى.
         *
         * وقراءةٌ أقلُّ ممّا تعدّه Google لا يُختم بها: الناقصُ لم يختفِ، وإنّما لم
         * يصلنا. فيُترك معروضًا، وهو أهونُ من إخفاء تقييمٍ قائم.
         */
        if ($reported === null || $listed >= $reported) {
            GoogleBusinessReview::where('branch_id', $branch->id)
                ->whereNull('gone_at')
                ->when($seen !== [], fn ($q) => $q->whereNotIn('review_id', $seen))
                ->update(['gone_at' => now()]);
        } else {
            Log::warning('google.reviews_short', ['branch' => $branch->id, 'listed' => $listed, 'reported' => $reported]);
        }

        $place->forceFill(['gbp_synced_at' => now(), 'gbp_sync_error' => null])->save();
        $account->forceFill(['synced_at' => now(), 'last_error' => null])->save();

        return ['ok' => true, 'error' => null, 'new' => $new, 'total' => count($seen)];
    }

    /**
     * سحبٌ لم يكتمل — يُقيَّد على فرعه، فلا يقول فرعٌ سليمٌ ما أصاب جاره.
     *
     * @return array{ok:bool, error:?string, new:int, total:int}
     */
    private static function pullFailed(BranchGooglePlace $place, GoogleBusinessAccount $account, string $error, int $new): array
    {
        $place->forceFill(['gbp_sync_error' => Str::limit($error, 290)])->save();
        $account->forceFill(['last_error' => Str::limit($error, 290)])->save();

        return ['ok' => false, 'error' => $error, 'new' => $new, 'total' => 0];
    }

    /**
     * نشرُ ردٍّ على Google — ولا يُكتب عندنا قبل أن تقبله.
     *
     * ═══ وهذا أثقلُ سطرٍ في الملفّ ═══
     *
     * لو كُتب الردُّ عندنا ثمّ نُودي Google لَرأى التاجر ردَّه معروضًا وقد
     * رُدّ عندهم: يظنّ أنّه أجاب زبونًا لم يصله شيء، ولا يفتح ملفَّه ليتحقّق.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function reply(GoogleBusinessReview $review, string $comment): array
    {
        $branch = $review->branch;
        $account = $branch ? self::for($branch->business_id) : null;
        $place = $branch ? BranchGooglePlace::where('branch_id', $branch->id)->first() : null;
        $owner = $account && $place ? self::businessAccount($place, $account) : null;

        if (! $account || ! $place || blank($place->gbp_location) || blank($owner)) {
            return ['ok' => false, 'error' => __('هذا الفرع غير مربوط بملفّ الأعمال.')];
        }

        $url = self::REVIEWS_URL.$owner.'/'.$place->gbp_location
            .'/reviews/'.$review->review_id.'/reply';

        $result = self::call($account, 'put', $url, ['comment' => $comment]);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        /* وما يُكتب هو ما ردّته Google — لا ما كتبه التاجر في الحقل */
        $review->forceFill([
            'reply' => (string) ($result['data']['comment'] ?? $comment),
            'replied_at' => self::time($result['data']['updateTime'] ?? null) ?? now(),
        ])->save();

        Activity::log('updated', 'نشر ردًّا على تقييم Google في فرع «'.$branch->name.'»', [
            'subject_id' => $branch->id, 'subject_type' => 'branch',
        ]);

        return ['ok' => true, 'error' => null];
    }

    /* ═══════════════════ أدواتٌ داخليّة ═══════════════════ */

    /**
     * نداءُ قراءةٍ مأذون.
     *
     * @param  array<string, mixed>  $query
     * @return array{ok:bool, error:?string, data:array}
     */
    private static function get(GoogleBusinessAccount $account, string $url, array $query = []): array
    {
        return self::call($account, 'get', $url, $query);
    }

    /**
     * قائمةٌ من Google كاملةً — صفحةً بعد صفحةٍ حتى يغيب `nextPageToken`.
     *
     * صاحبُ سلسلةٍ بمئة فرعٍ وفرعٍ كان لا يجد فرعَه الأخير في القائمة، ويُردّ
     * ربطُه بـ«ليس من مواقع حسابك» وهو من مواقعه.
     *
     * @param  array<string, mixed>  $query
     * @return array{ok:bool, error:?string, rows:list<array<string,mixed>>}
     */
    private static function all(GoogleBusinessAccount $account, string $url, string $key, array $query): array
    {
        $rows = [];
        $token = null;

        for ($page = 1; $page <= self::MAX_LIST_PAGES; $page++) {
            $result = self::get($account, $url, $query + array_filter(['pageToken' => $token]));

            if (! $result['ok']) {
                return ['ok' => false, 'error' => $result['error'], 'rows' => []];
            }

            array_push($rows, ...array_values((array) ($result['data'][$key] ?? [])));

            $next = (string) ($result['data']['nextPageToken'] ?? '');

            if ($next === '') {
                return ['ok' => true, 'error' => null, 'rows' => $rows];
            }

            if ($next === $token) {
                break;
            }

            $token = $next;
        }

        return ['ok' => false, 'error' => __('لم تكتمل قراءة القائمة من Google. حاول بعد قليل.'), 'rows' => []];
    }

    /**
     * نداءٌ مأذون — وهو الموضعُ الوحيد الذي يكلّم واجهات الملفّ.
     *
     * ═══ و٤٠١ تُجدَّد مرّةً ═══
     *
     * رمزُ وصولٍ لم ينته عندنا وسحبه صاحبُه من حسابه يُردّ ٤٠١. فيُعدّ منتهيًا
     * ويُطلب غيرُه مرّةً واحدة: إن قبلت Google مضى النداء، وإن كان الإذنُ قد
     * سُحب ردّت `invalid_grant` فمُحي وعُرض «أعِد الربط». ولولا هذا لَبقي الصفُّ
     * «مربوطًا» يفشل كلَّ ساعةٍ بلا زرٍّ يُصلحه. والنشرُ PUT يُعاد بلا ضرر.
     *
     * @param  'get'|'put'  $method
     * @param  array<string, mixed>  $data
     * @return array{ok:bool, error:?string, data:array}
     */
    private static function call(GoogleBusinessAccount $account, string $method, string $url, array $data = []): array
    {
        $expired = __('انتهت صلاحية الإذن — أعِد ربط حساب Google.');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $token = self::accessToken($account);

            if ($token === null) {
                return ['ok' => false, 'error' => $account->last_error ?: $expired, 'data' => []];
            }

            try {
                $response = Http::withToken($token)->timeout(20)->acceptJson()->{$method}($url, $data);
            } catch (\Throwable) {
                return ['ok' => false, 'error' => __('تعذّر الوصول إلى Google. حاول بعد قليل.'), 'data' => []];
            }

            if ($response->status() !== 401) {
                break;
            }

            if ($attempt === 1) {
                $account->forceFill(['token_expires_at' => null])->save();
            }
        }

        if ($response->successful()) {
            if ($account->api_blocked_at !== null) {
                $account->forceFill(['api_blocked_at' => null, 'last_error' => null])->save();
            }

            return ['ok' => true, 'error' => null, 'data' => (array) ($response->json() ?? [])];
        }

        if (self::notApproved($response)) {
            $error = __('لم تعتمد Google بعدُ وصول أبعاد إلى «Business Profile APIs» — ليس عليك شيء، ويعود العمل تلقائيًّا بعد اعتمادها.');
            $account->forceFill(['api_blocked_at' => now(), 'last_error' => Str::limit($error, 290)])->save();

            return ['ok' => false, 'error' => $error, 'data' => []];
        }

        return [
            'ok' => false,
            'error' => self::readable($response->status(), (string) ($response->json('error.message') ?? '')),
            'data' => [],
        ];
    }

    /**
     * أهذا ردُّ «مشروعُ أبعاد لم يُعتمد» — لا ردٌّ على هذا التاجر؟
     *
     * حصّةٌ صفرٌ تعني أنّ Google لم توافق على المشروع (توثيقها: 0 QPM)، وواجهةٌ
     * معطَّلةٌ تعني أنّها لم تُفعَّل فيه. والاثنان عند أبعاد: «راجع حسابك» يُرسل
     * التاجر يبحث في ملفِّه عمّا ليس فيه. و٤٢٩ بحصّةٍ قائمةٍ زحامٌ عابرٌ لا يدخل هنا.
     *
     * ويُقرأ من تفاصيل الخطأ بصيغة Google العامّة (`ErrorInfo`)، ومن نصّ الرسالة
     * احتياطًا. ولم يُجرَّب على ردٍّ حيّ.
     */
    private static function notApproved(Response $response): bool
    {
        $info = collect((array) $response->json('error.details'))
            ->first(fn ($d) => is_array($d) && str_ends_with((string) ($d['@type'] ?? ''), 'google.rpc.ErrorInfo'));
        $reason = (string) ($info['reason'] ?? '');
        $message = (string) ($response->json('error.message') ?? '');

        return match ($response->status()) {
            403 => in_array($reason, ['SERVICE_DISABLED', 'API_DISABLED'], true)
                || str_contains($message, 'has not been used') || str_contains($message, 'is disabled'),
            429 => (string) ($info['metadata']['quota_limit_value'] ?? '') === '0',
            default => false,
        };
    }

    /**
     * رسالةٌ تقول ما يُفعل، لا رقمَ حالةٍ وحده.
     *
     * و٤٠٣ هنا بعد أن خرج منها ما يخصّ مشروع أبعاد (`notApproved`) أمرُ حسابٍ
     * أو موقع: حسابٌ لا يملك الموقع، أو Workspace أطفأ الملفَّ عن مستخدميه.
     */
    private static function readable(int $status, string $detail): string
    {
        $said = trim($detail) === '' ? '' : ' ('.Str::limit($detail, 160).')';

        return match (true) {
            $status === 401 => __('انتهت صلاحية الإذن — أعِد ربط حساب Google.'),
            $status === 403 => __('رفضت Google الطلب. تأكّد من أنّ حساب Google المربوط يملك هذا الموقع أو يديره.').$said,
            $status === 404 => __('لم تجد Google هذا الموقع أو التقييم.'),
            $status === 429 => __('تجاوزتَ حصّة Google لهذه الفترة. حاول لاحقًا.'),
            $status >= 500 => __('عطلٌ عند Google. حاول بعد قليل.'),
            default => __('لم تُتِمّ Google الطلب (:code).', ['code' => $status]).$said,
        };
    }

    /** النجومُ تصل كلمةً لا رقمًا */
    private static function stars(?string $rating): int
    {
        return match ($rating) {
            'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
            default => 0,
        };
    }

    private static function time(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

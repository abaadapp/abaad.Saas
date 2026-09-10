<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\GoogleBusinessAccount;
use App\Models\GoogleBusinessReview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    /* ═══════════════════ الإذن ═══════════════════ */

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
            $account->forceFill(['last_error' => $result['error']])->save();

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
        $result = self::get($account, self::ACCOUNTS_URL);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'accounts' => []];
        }

        $out = [];

        foreach ((array) ($result['data']['accounts'] ?? []) as $row) {
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
     * @return array{ok:bool, error:?string, locations:list<array<string,string>>}
     */
    public static function locations(GoogleBusinessAccount $account, string $accountName): array
    {
        $result = self::get($account, self::INFO_URL.$accountName.'/locations', [
            // أقلُّ ما يُعرض في قائمةِ اختيار — والقناعُ إلزاميٌّ في هذه الواجهة
            'readMask' => 'name,title,storefrontAddress',
            'pageSize' => 100,
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'locations' => []];
        }

        $out = [];

        foreach ((array) ($result['data']['locations'] ?? []) as $row) {
            if (blank($row['name'] ?? null)) {
                continue;
            }

            $lines = (array) ($row['storefrontAddress']['addressLines'] ?? []);
            $city = (string) ($row['storefrontAddress']['locality'] ?? '');

            $out[] = [
                'name' => (string) $row['name'],
                'title' => (string) ($row['title'] ?? $row['name']),
                'address' => trim(implode('، ', array_filter($lines + [$city]))),
            ];
        }

        return ['ok' => true, 'error' => null, 'locations' => $out];
    }

    /**
     * ربطُ فرعٍ بموقعٍ في ملفّ الأعمال.
     *
     * والموقعُ لا يُربط بفرعين: تفرّدٌ في القاعدة يحرسه، ولولاه لَسُحبت
     * تقييماتُه مرّتين وعُدّت مرّتين ونُبّه بها مرّتين.
     */
    public static function linkLocation(Branch $branch, string $location, string $accountName): BranchGooglePlace
    {
        $row = BranchGooglePlace::firstOrNew(['branch_id' => $branch->id]);

        $row->forceFill([
            'place_id' => $row->place_id ?: '',
            'place_name' => $row->place_name ?: '',
            'gbp_location' => $location,
            'gbp_linked_at' => now(),
            'unlinked_at' => $row->unlinked_at,
        ])->save();

        GoogleBusinessAccount::where('business_id', $branch->business_id)
            ->update(['account_name' => $accountName]);

        Activity::log('updated', 'ربط فرع «'.$branch->name.'» بموقعه في ملفّ Google Business', [
            'subject_id' => $branch->id, 'subject_type' => 'branch',
        ]);

        return $row;
    }

    /* ═══════════════════ التقييمات ═══════════════════ */

    /**
     * سحبُ تقييمات فرعٍ — والجديدُ يُعرف بأوّل مرّةٍ وصلنا فيها.
     *
     * @return array{ok:bool, error:?string, new:int, total:int}
     */
    public static function syncReviews(Branch $branch): array
    {
        $account = self::for($branch->business_id);
        $place = BranchGooglePlace::where('branch_id', $branch->id)->first();

        if (! $account || ! $place || blank($place->gbp_location) || blank($account->account_name)) {
            return ['ok' => false, 'error' => __('هذا الفرع غير مربوط بملفّ الأعمال.'), 'new' => 0, 'total' => 0];
        }

        $result = self::get(
            $account,
            self::REVIEWS_URL.$account->account_name.'/'.$place->gbp_location.'/reviews',
            ['pageSize' => 50],
        );

        if (! $result['ok']) {
            $account->forceFill(['last_error' => $result['error']])->save();

            return ['ok' => false, 'error' => $result['error'], 'new' => 0, 'total' => 0];
        }

        $seen = [];
        $new = 0;

        foreach ((array) ($result['data']['reviews'] ?? []) as $review) {
            $id = (string) ($review['reviewId'] ?? $review['name'] ?? '');

            if ($id === '') {
                continue;
            }

            $seen[] = $id;
            $existing = GoogleBusinessReview::where('review_id', $id)->first();

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
                GoogleBusinessReview::create($fields + ['review_id' => $id, 'first_seen_at' => now()]);
                $new++;
            }
        }

        /*
         * وما اختفى من Google يُختم ولا يُمحى.
         *
         * زبونٌ حذف تقييمه أو أزالته Google. وبقاؤه معروضًا يعني تاجرًا يردّ
         * على كلامٍ لم يعد موجودًا. والصفُّ يبقى لأنّه أثرُ ما جرى.
         */
        GoogleBusinessReview::where('branch_id', $branch->id)
            ->whereNull('gone_at')
            ->when($seen !== [], fn ($q) => $q->whereNotIn('review_id', $seen))
            ->update(['gone_at' => now()]);

        $account->forceFill(['synced_at' => now(), 'last_error' => null])->save();

        return ['ok' => true, 'error' => null, 'new' => $new, 'total' => count($seen)];
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

        if (! $account || ! $place || blank($place->gbp_location) || blank($account->account_name)) {
            return ['ok' => false, 'error' => __('هذا الفرع غير مربوط بملفّ الأعمال.')];
        }

        $token = self::accessToken($account);

        if ($token === null) {
            return ['ok' => false, 'error' => $account->last_error ?: __('انتهت صلاحية الإذن — أعِد ربط حساب Google.')];
        }

        $url = self::REVIEWS_URL.$account->account_name.'/'.$place->gbp_location
            .'/reviews/'.$review->review_id.'/reply';

        try {
            $response = Http::withToken($token)->timeout(20)->acceptJson()
                ->put($url, ['comment' => $comment]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => __('تعذّر الوصول إلى Google. حاول بعد قليل.')];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => self::readable($response->status(), (string) ($response->json('error.message') ?? '')),
            ];
        }

        /* وما يُكتب هو ما ردّته Google — لا ما كتبه التاجر في الحقل */
        $review->forceFill([
            'reply' => (string) ($response->json('comment') ?? $comment),
            'replied_at' => self::time($response->json('updateTime')) ?? now(),
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
        $token = self::accessToken($account);

        if ($token === null) {
            return [
                'ok' => false,
                'error' => $account->last_error ?: __('انتهت صلاحية الإذن — أعِد ربط حساب Google.'),
                'data' => [],
            ];
        }

        try {
            $response = Http::withToken($token)->timeout(20)->acceptJson()->get($url, $query);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => __('تعذّر الوصول إلى Google. حاول بعد قليل.'), 'data' => []];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'data' => (array) ($response->json() ?? [])];
        }

        return [
            'ok' => false,
            'error' => self::readable($response->status(), (string) ($response->json('error.message') ?? '')),
            'data' => [],
        ];
    }

    /**
     * رسالةٌ تقول ما يُفعل، لا رقمَ حالةٍ وحده.
     *
     * و٤٠٣ هنا أشيعُ ما يقع: الوصولُ إلى «Business Profile APIs» يُطلب
     * ويُراجَع عند Google، ولا يُفتح بتفعيلِ واجهةٍ في المشروع. فتُذكر باسمها.
     */
    private static function readable(int $status, string $detail): string
    {
        $said = trim($detail) === '' ? '' : ' ('.Str::limit($detail, 160).')';

        return match (true) {
            $status === 401 => __('انتهت صلاحية الإذن — أعِد ربط حساب Google.'),
            $status === 403 => __('رفضت Google الطلب. تأكّد من اعتماد وصولك إلى «Business Profile APIs» ومن أنّ الحساب يملك هذا الموقع.').$said,
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

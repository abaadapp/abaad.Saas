<?php

namespace App\Support;

use App\Models\Business;
use App\Models\InstagramConnection;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ‏‏وصلةُ إنستقرام — «تسجيل الدخول عبر Instagram» ثمّ الرجوعُ مربوطًا، بلا رمزٍ يُلصق.
 *
 * ═══ المسار كما في وثيقة Meta (Business Login for Instagram) ═══
 *
 *  1. `authorizeUrl`: صفحةُ إنستقرام بالأذونات الثلاث، وختمٌ (`state`) عشوائيٌّ مربوطٌ بالمتجر
 *     ومن ضغط، صالحٌ عشر دقائق ولمرّةٍ واحدة.
 *  2. `connect`: الرمزُ القصير (ساعة) يُستبدل برمزٍ طويل (ستّون يومًا)، ويُقرأ الحسابُ، ويُشترك في
 *     الإشعارات، ثمّ يُحفظ. وحسابٌ مربوطٌ بمتجرٍ آخر يُرفض قبل أن يُكتب شيء.
 *  3. `refresh`: يُجدَّد الرمزُ قبل انتهائه — بعد ٢٤ ساعةً من صدوره على الأقلّ، كما تشترط Meta.
 *     وإن فشل يُقال للتاجر «أعد ربط إنستقرام» بسببه.
 *
 * ولا يخرج الرمزُ إلى شاشة ولا سجلّ: عمودُه مشفَّر، وسجلُّ الأخطاء يحمل الرمزَ والسببَ من Meta وحدهما.
 */
class InstagramLink
{
    public const SCOPES = ['instagram_business_basic', 'instagram_business_manage_messages', 'instagram_business_manage_comments'];

    /** ‏‏حقولُ الإشعارات التي يُشترك فيها الحساب — التعليقاتُ والرسائلُ وضغطاتُ الأزرار */
    public const FIELDS = ['comments', 'messages', 'messaging_postbacks'];

    public const STATE_TTL_MINUTES = 10;

    /** ‏‏يُجدَّد الرمزُ حين يبقى من أيّامه هذا العدد أو أقلّ */
    public const REFRESH_WITHIN_DAYS = 15;

    /** ‏‏ولا يُجدَّد رمزٌ أحدثُ من هذا — شرطُ Meta */
    public const MIN_AGE_HOURS = 24;

    /** ‏‏أنواعُ الحساب التي تقبلها Meta لهذا الربط — حسابٌ شخصيٌّ لا يُربط */
    public const PROFESSIONAL = ['BUSINESS', 'MEDIA_CREATOR', 'CREATOR'];

    public static function available(?Business $business): bool
    {
        return $business !== null && InstagramPlatform::allows((int) $business->id) && InstagramPlatform::configured();
    }

    public static function forBusiness(int $businessId): ?InstagramConnection
    {
        return InstagramConnection::where('business_id', $businessId)->first();
    }

    public static function redirectUri(): string
    {
        return route('admin.integrations.instagram.callback');
    }

    /** ‏‏صفحةُ إنستقرام التي يُرسل إليها التاجر — والختمُ محفوظٌ لمرّةٍ واحدة */
    public static function authorizeUrl(Business $business, User $user): string
    {
        $state = Str::random(40);

        Cache::put(self::stateKey($state), ['business_id' => (int) $business->id, 'user_id' => (int) $user->id], now()->addMinutes(self::STATE_TTL_MINUTES));

        return config('services.instagram.authorize_url').'?'.http_build_query([
            'client_id' => InstagramPlatform::appId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
            // ‏‏ويختار التاجرُ الحسابَ بنفسه — لا يُربط حسابٌ بقي مفتوحًا في المتصفّح بلا قصد
            'force_reauth' => 'true',
        ]);
    }

    /**
     * ‏‏الرجوعُ من إنستقرام — يُحفظ الحسابُ أو يُقال لماذا لم يُحفظ.
     *
     * @return array{ok: bool, message: string, connection: ?InstagramConnection}
     */
    public static function connect(Business $business, User $user, ?string $state, ?string $code, ?string $error = null): array
    {
        $claimed = filled($state) ? Cache::pull(self::stateKey((string) $state)) : null;

        // ‏‏ختمٌ لا نعرفه أو انقضى أو استُعمل أو صنعه غيرُ من ضغط — لا يُقرأ رمزٌ معه
        if (! is_array($claimed) || $claimed['business_id'] !== (int) $business->id || $claimed['user_id'] !== (int) $user->id) {
            return self::refused(__('انتهت صلاحية طلب الربط — اضغط «تسجيل الدخول عبر Instagram» من جديد.'));
        }

        if (filled($error)) {
            return self::refused(__('لم يكتمل الربط — رُفض الإذن في إنستقرام.'));
        }

        // ‏‏و«#_» في آخر الرمز ليس منه — تقول Meta
        $code = preg_replace('/#_$/', '', trim((string) $code));

        if ($code === '') {
            return self::refused(__('لم يكتمل الربط — لم يصل رمز من إنستقرام.'));
        }

        $short = self::post((string) config('services.instagram.token_url'), [
            'client_id' => InstagramPlatform::appId(),
            'client_secret' => InstagramPlatform::appSecret(),
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::redirectUri(),
            'code' => $code,
        ]);

        // ‏‏والجوابُ في المثال ملفوفٌ في `data[]`، وفي الوصف مسطَّح — يُقبل الشكلان
        $token = (array) ($short['body']['data'][0] ?? $short['body']);

        if (! $short['ok'] || blank($token['access_token'] ?? null)) {
            return self::refused(__('رفض إنستقرام الربط: :why', ['why' => $short['error']]));
        }

        $granted = self::permissions($token['permissions'] ?? null);

        $long = self::get('/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => InstagramPlatform::appSecret(),
            'access_token' => (string) $token['access_token'],
        ], versioned: false);

        if (! $long['ok'] || blank($long['body']['access_token'] ?? null)) {
            return self::refused(__('رفض إنستقرام الربط: :why', ['why' => $long['error']]));
        }

        $access = (string) $long['body']['access_token'];
        $expires = now()->addSeconds(max(3600, (int) ($long['body']['expires_in'] ?? 5184000)));

        $me = self::get('/me', [
            'fields' => 'id,user_id,username,name,account_type,profile_picture_url',
            'access_token' => $access,
        ]);
        $account = (array) ($me['body']['data'][0] ?? $me['body']);
        $igUserId = (string) ($account['user_id'] ?? '');

        if (! $me['ok'] || $igUserId === '' || blank($account['username'] ?? null)) {
            return self::refused(__('لم نقرأ حساب إنستقرام: :why', ['why' => $me['error'] ?: __('جواب ناقص')]));
        }

        if (filled($account['account_type'] ?? null) && ! in_array(strtoupper((string) $account['account_type']), self::PROFESSIONAL, true)) {
            return self::refused(__('هذا حساب شخصي — حوّله إلى حساب احترافي (أعمال أو صانع محتوى) من إعدادات إنستقرام ثم اربطه.'));
        }

        $taken = InstagramConnection::where('ig_user_id', $igUserId)->where('business_id', '!=', $business->id)->exists();

        if ($taken) {
            return self::refused(__('حساب إنستقرام هذا مربوط بمتجر آخر في أبعاد — افصله من هناك أولًا.'));
        }

        $subscribed = self::subscribe($igUserId, $access);

        $connection = DB::transaction(function () use ($business, $user, $account, $igUserId, $access, $expires, $granted, $subscribed) {
            $row = InstagramConnection::where('business_id', $business->id)->lockForUpdate()->first() ?? new InstagramConnection(['business_id' => $business->id]);

            $row->forceFill([
                'ig_user_id' => $igUserId,
                'ig_scoped_id' => filled($account['id'] ?? null) ? (string) $account['id'] : null,
                'username' => mb_substr((string) $account['username'], 0, 100),
                'name' => filled($account['name'] ?? null) ? mb_substr((string) $account['name'], 0, 150) : null,
                'account_type' => filled($account['account_type'] ?? null) ? mb_substr((string) $account['account_type'], 0, 30) : null,
                'profile_picture_url' => filled($account['profile_picture_url'] ?? null) ? (string) $account['profile_picture_url'] : null,
                'access_token' => $access,
                'token_expires_at' => $expires,
                'permissions' => $granted,
                'subscribed_fields' => $subscribed['ok'] ? self::FIELDS : [],
                'status' => InstagramConnection::ACTIVE,
                'last_error' => $subscribed['ok'] ? null : mb_substr(__('لم يُشترك في الإشعارات: :why', ['why' => $subscribed['error']]), 0, 255),
                'connected_by' => $user->id,
                'connected_at' => now(),
                'refreshed_at' => now(),
                'revoked_at' => null,
            ])->save();

            return $row;
        });

        Activity::log('settings', 'إنستقرام: رُبط الحساب @'.$connection->username, [
            'subject_type' => InstagramConnection::class,
            'subject_id' => $connection->id,
        ]);

        $missing = array_values(array_diff(self::SCOPES, $granted));

        return ['ok' => true, 'connection' => $connection, 'message' => $missing === []
            ? __('رُبط حساب إنستقرام @:name.', ['name' => $connection->username])
            : __('رُبط @:name — لكن لم تُمنح كل الأذونات، فلن تعمل الأتمتة كلها. أعد الربط واسمح بها.', ['name' => $connection->username])];
    }

    /**
     * ‏‏يجدّد رمزَ وصلةٍ قبل انتهائه — وإن رفضته Meta صارت الوصلة «أعد الربط» بسببها.
     *
     * @return array{ok: bool, skipped: bool, error: ?string}
     */
    public static function refresh(InstagramConnection $connection): array
    {
        if (! $connection->usable()) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $issued = $connection->refreshed_at ?? $connection->connected_at;

        if ($issued !== null && $issued->gt(now()->subHours(self::MIN_AGE_HOURS))) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        $r = self::get('/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => (string) $connection->access_token,
        ], versioned: false);

        if ($r['ok'] && filled($r['body']['access_token'] ?? null)) {
            $connection->forceFill([
                'access_token' => (string) $r['body']['access_token'],
                'token_expires_at' => now()->addSeconds(max(3600, (int) ($r['body']['expires_in'] ?? 5184000))),
                'refreshed_at' => now(),
                'last_error' => null,
            ])->save();

            return ['ok' => true, 'skipped' => false, 'error' => null];
        }

        // ‏‏خطأُ رمزٍ (١٩٠) لا يُعاد: الرمزُ أُلغي أو انتهى — يعيد التاجرُ الربط. وغيرُه يُحاول غدًا
        if ($r['code'] === 190 || ($r['status'] === 400 && $r['type'] === 'OAuthException')) {
            $connection->forceFill([
                'status' => InstagramConnection::NEEDS_RELINK,
                'last_error' => mb_substr(__('انتهى إذن إنستقرام — أعد ربط الحساب.').' ('.$r['error'].')', 0, 255),
            ])->save();
        } else {
            $connection->forceFill(['last_error' => mb_substr(__('تعذّر تجديد رمز إنستقرام — نحاول غدًا.').' ('.$r['error'].')', 0, 255)])->save();
        }

        Log::warning('instagram.refresh_failed', ['business_id' => $connection->business_id, 'meta_code' => $r['code'], 'meta_type' => $r['type']]);

        return ['ok' => false, 'skipped' => false, 'error' => $r['error']];
    }

    /**
     * ‏‏يفصل التاجرُ حسابه — يُمحى الرمز، ويُطلب من Meta وقفُ الإشعارات (محاولةٌ لا شرط).
     */
    public static function disconnect(InstagramConnection $connection, ?User $by = null): void
    {
        if (filled($connection->access_token)) {
            self::delete('/'.$connection->ig_user_id.'/subscribed_apps', (string) $connection->access_token);
        }

        self::revoke($connection, 'فُصل من أبعاد');
    }

    /** ‏‏يُعلَّم الحسابُ ملغيًّا ويُمحى رمزُه — من الفصل، أو من إلغاء الإذن في إنستقرام */
    public static function revoke(InstagramConnection $connection, string $why): void
    {
        $connection->forceFill([
            'status' => InstagramConnection::REVOKED,
            'access_token' => null,
            'token_expires_at' => null,
            'subscribed_fields' => [],
            'revoked_at' => now(),
            'last_error' => null,
        ])->save();

        Activity::log('settings', 'إنستقرام: '.$why.' @'.$connection->username, [
            'subject_type' => InstagramConnection::class,
            'subject_id' => $connection->id,
            'business_id' => $connection->business_id,
        ]);
    }

    /**
     * ‏‏ما تعرضه شاشةُ التاجر — بلا رمز.
     *
     * @return array<string, mixed>|null
     */
    public static function view(?InstagramConnection $c): ?array
    {
        if ($c === null) {
            return null;
        }

        $state = match (true) {
            $c->status === InstagramConnection::REVOKED => 'revoked',
            $c->status === InstagramConnection::NEEDS_RELINK || ! $c->usable() => 'needs_relink',
            default => 'connected',
        };

        return [
            'state' => $state,
            'username' => $c->username,
            'name' => $c->name,
            'account_type' => $c->account_type,
            'picture' => $c->profile_picture_url,
            'expires_at' => $c->token_expires_at?->toIso8601String(),
            'last_webhook_at' => $c->last_webhook_at?->toIso8601String(),
            'connected_at' => $c->connected_at?->toIso8601String(),
            'missing_permissions' => array_values(array_diff(self::SCOPES, (array) ($c->permissions ?? []))),
            'subscribed' => array_values(array_intersect(self::FIELDS, (array) ($c->subscribed_fields ?? []))) === self::FIELDS,
            'error' => $c->last_error,
        ];
    }

    /** @return array{ok: bool, error: ?string} */
    private static function subscribe(string $igUserId, string $token): array
    {
        $r = self::post(self::graph('/'.$igUserId.'/subscribed_apps'), [
            'subscribed_fields' => implode(',', self::FIELDS),
            'access_token' => $token,
        ]);

        return ['ok' => $r['ok'] && ($r['body']['success'] ?? false) === true, 'error' => $r['error'] ?: __('جواب غير متوقع')];
    }

    /** @return list<string> */
    private static function permissions(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($p) => trim((string) $p), $list)));
    }

    private static function stateKey(string $state): string
    {
        return 'instagram_oauth_state:'.hash('sha256', $state);
    }

    /** @return array{ok: false, message: string, connection: null} */
    private static function refused(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'connection' => null];
    }

    private static function graph(string $path, bool $versioned = true): string
    {
        return rtrim((string) config('services.instagram.graph_url'), '/')
            .($versioned ? '/'.config('services.instagram.api_version') : '')
            .$path;
    }

    /** @return array{ok: bool, status: int, body: array, error: string, code: ?int, type: ?string} */
    private static function get(string $path, array $query, bool $versioned = true): array
    {
        try {
            return self::read(Http::timeout((int) config('services.instagram.timeout', 15))->acceptJson()->get(self::graph($path, $versioned), $query));
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'network_error', 'code' => null, 'type' => null];
        }
    }

    /** @return array{ok: bool, status: int, body: array, error: string, code: ?int, type: ?string} */
    private static function post(string $url, array $form): array
    {
        try {
            return self::read(Http::timeout((int) config('services.instagram.timeout', 15))->acceptJson()->asForm()->post($url, $form));
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'network_error', 'code' => null, 'type' => null];
        }
    }

    private static function delete(string $path, string $token): void
    {
        try {
            Http::timeout((int) config('services.instagram.timeout', 15))->acceptJson()->delete(self::graph($path).'?'.http_build_query(['access_token' => $token]));
        } catch (\Throwable $e) {
            // ‏‏محاولةٌ لا شرط: الرمزُ يُمحى عندنا على أيّ حال
        }
    }

    /** @return array{ok: bool, status: int, body: array, error: string, code: ?int, type: ?string} */
    private static function read(Response $r): array
    {
        $body = (array) ($r->json() ?? []);
        $err = (array) ($body['error'] ?? []);

        // ‏‏وأخطاءُ تسجيل الدخول تأتي مسطّحةً (`error_type`/`error_message`)، وأخطاءُ Graph في `error{}`
        $message = (string) ($err['message'] ?? $body['error_message'] ?? '');
        $code = $err['code'] ?? $body['code'] ?? null;
        $type = $err['type'] ?? $body['error_type'] ?? null;

        return [
            'ok' => $r->successful() && $err === [] && ! isset($body['error_type']),
            'status' => $r->status(),
            'body' => $body,
            'error' => mb_substr(trim(($code !== null ? $code.' · ' : '').$message) ?: ($r->successful() ? '' : 'HTTP '.$r->status()), 0, 160),
            'code' => is_numeric($code) ? (int) $code : null,
            'type' => is_string($type) ? $type : null,
        ];
    }
}

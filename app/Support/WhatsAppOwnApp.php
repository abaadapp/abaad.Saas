<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * التاجرُ بتطبيقه هو عند ميتا — ورقمِه وحسابِه ورمزِه وفوترتِه. وأبعاد برنامج.
 *
 * ═══ ما يملكه التاجر، وما نفعله نحن ═══
 *
 * يملك: تطبيق Meta Developer، وحساب واتساب للأعمال (WABA) ورقمَه، ورمزَ
 * «مستخدم النظام» الدائم، ووسيلةَ الدفع عند ميتا ورسومَها كلَّها.
 *
 * ونحن: نتحقّق من ذلك كلِّه قبل أن نقبله، ونُرسل برمزه، ونستقبل إشعاراتِ
 * تطبيقه على عنوانٍ خاصٍّ بوصلته (`/webhooks/whatsapp/m/{key}`) ونتحقّق من
 * توقيعها بسرّ تطبيقه هو — ميتا توقّع إشعاراتِ كلِّ تطبيقٍ بسرّه.
 *
 * ═══ وما لا يُقبل أبدًا ═══
 *
 *   · رمزٌ من تطبيق أبعاد — ذاك طريقُ أرقام أبعاد لا التجّار.
 *   · رمزٌ مؤقّت: ينتهي خلال ساعاتٍ فتقف رسائلُ المتجر بلا سبب يُرى.
 *   · حسابٌ تُحتسب رسائلُه على أبعاد (`billing`). ولا يُعدّ «جاهزًا» ما لم
 *     يثبت أنّها على التاجر: يبقى «بانتظار إثبات الفوترة» ولا يُرسل.
 *   · رقمٌ على تطبيق واتساب للأعمال في الهاتف: التعايشُ لا تتيحه ميتا إلّا
 *     عبر شريكٍ تقنيّ بالتسجيل المدمج — فلا نَعِد به هنا.
 *
 * ولا `register` ولا `deregister` ولا حذفَ عند ميتا من هذا الملفّ: الرقمُ
 * المسجَّل يبقى كما هو، والجديدُ يُسجَّل برمز صاحبه (`WhatsAppOnboarding::register`).
 */
class WhatsAppOwnApp
{
    public const TYPE = 'own_app';

    /** الفوترةُ على التاجر — ثبتت من ملكيّة الحساب عند ميتا */
    public const BILLING_MERCHANT = 'merchant';

    /** أكّدها صاحبُ المنصّة بعد أن رأى إثباتها */
    public const BILLING_CONFIRMED = 'confirmed';

    /** لم يثبت على من تُحتسب — والربطُ لا يُرسل */
    public const BILLING_UNPROVEN = 'unproven';

    /** يملكه حسابُ أعمال أبعاد — يُردّ ولا يُحفظ */
    public const BILLING_ABAAD = 'abaad';

    /** رمزُ الخطوة على وصلةٍ تنتظر إثبات الفوترة (`last_error_code`) */
    public const AWAITS_BILLING = 'billing_unproven';

    public const ABAAD_APP = 'abaad_app_not_allowed';

    public const TOKEN_EXPIRES = 'token_expires';

    public const APP_UNREADABLE = 'own_app_unreadable';

    public const OTHER_APP = 'own_app_other_app';

    public const BILLED_TO_ABAAD = 'billed_to_abaad';

    public const ON_PHONE_APP = 'own_app_on_phone_app';

    /** أيربط التجّارُ بتطبيقاتهم على هذا الخادم؟ — الافتراضُ نعم (`whatsapp.merchant_link`) */
    public static function enabled(): bool
    {
        return config('whatsapp.merchant_link', 'own_app') === 'own_app';
    }

    public static function is(?WhatsAppConnection $connection): bool
    {
        return $connection !== null && $connection->link_type === self::TYPE;
    }

    public static function callbackUrl(WhatsAppConnection $connection): string
    {
        return self::callbackFor((string) $connection->webhook_key);
    }

    private static function callbackFor(string $key): string
    {
        return (string) route('webhooks.whatsapp.own', ['key' => $key]);
    }

    /** أثبتت الفوترةُ أنّها على التاجر؟ */
    public static function billed(?WhatsAppConnection $connection): bool
    {
        return self::is($connection)
            && in_array($connection->billing_state, [self::BILLING_MERCHANT, self::BILLING_CONFIRMED], true);
    }

    public static function awaitsBilling(?WhatsAppConnection $connection): bool
    {
        return self::is($connection)
            && $connection->status === WhatsAppConnection::PENDING
            && $connection->last_error_code === self::AWAITS_BILLING;
    }

    /**
     * يربط رقمَ المتجر بتطبيق التاجر — كلُّ فحصٍ قبل أن يُكتب حرف.
     *
     * والرمزُ والسرُّ الفارغان يبقيان كما حُفظا لوصلةٍ قائمةٍ على الرقم نفسه:
     * من يُكمل ربطَ رقمٍ يُرسل اليوم لا يُطلب منه رمزٌ جديد، ولا يقف إرسالُه.
     *
     * @param  array{app_id:string, app_secret:?string, waba_id:string, phone_number_id:string, access_token:?string}  $data
     * @return array{ok:bool, field:?string, message:string, connection:?WhatsAppConnection, pending:bool}
     */
    public static function link(Business $business, User $actor, array $data): array
    {
        $appId = trim((string) $data['app_id']);
        $phone = trim((string) $data['phone_number_id']);
        $existing = WhatsAppConnections::forBusiness($business->id);

        if ($appId === WhatsAppLinkCheck::appId()) {
            return self::refuse($business, 'app_id', self::ABAAD_APP);
        }

        $same = $existing !== null && (string) $existing->phone_number_id === $phone;
        $secret = filled($data['app_secret'] ?? null) ? trim((string) $data['app_secret'])
            : ($same && self::is($existing) && (string) $existing->token_app_id === $appId ? $existing->app_secret : null);
        $token = filled($data['access_token'] ?? null) ? trim((string) $data['access_token'])
            : ($same ? $existing->access_token : null);

        if (blank($secret)) {
            return ['ok' => false, 'field' => 'app_secret', 'message' => __('اكتب سرّ التطبيق (App Secret) من إعدادات تطبيقك.'), 'connection' => null, 'pending' => false];
        }

        if (blank($token)) {
            return ['ok' => false, 'field' => 'access_token', 'message' => __('الصق رمز الوصول الدائم من «مستخدمي النظام» في حسابك.'), 'connection' => null, 'pending' => false];
        }

        /*
         * ═══ الرمز: من تطبيقك أنت، ودائم، وبصلاحياته، ولحسابك ═══
         *
         * `debug_token` يُسأل بمعرّف تطبيق التاجر وسرّه: فيثبت في نداءٍ واحد
         * أنّ السرّ صحيح — وبه وحده نتحقّق من إشعاراته — وأنّ الرمز صادرٌ منه.
         */
        $check = WhatsAppLinkCheck::token((string) $token, $data['waba_id'], $appId, (string) $secret);

        if (! $check['ok']) {
            return match (true) {
                $check['problem'] === WhatsAppLinkCheck::UNVERIFIABLE => self::refuse($business, 'access_token', WhatsAppLinkCheck::UNVERIFIABLE, $check['meta']),
                ($check['meta']['code'] ?? null) === 'wrong_app' => self::refuse($business, 'access_token', self::OTHER_APP, $check['meta']),
                in_array($check['problem'], [WhatsAppLinkCheck::WRONG_APP, WhatsAppLinkCheck::INVALID_TOKEN], true) => self::refuse($business, 'app_secret', self::APP_UNREADABLE, $check['meta']),
                default => self::refuse($business, $check['problem'] === WhatsAppLinkCheck::WABA_NOT_GRANTED ? 'waba_id' : 'access_token', (string) $check['problem'], $check['meta']),
            };
        }

        if ($check['expires_at'] !== null) {
            return self::refuse($business, 'access_token', self::TOKEN_EXPIRES);
        }

        $waba = (string) $check['waba'];

        // حسابُ رقمٍ من أرقام أبعاد لا يُربط لتاجر — رسائلُه على أبعاد
        if (in_array($waba, self::platformWabas(), true)) {
            return self::refuse($business, 'waba_id', self::BILLED_TO_ABAAD);
        }

        $number = WhatsAppLinkCheck::number($waba, (string) $token, $phone, $existing?->id);

        if (! $number['ok']) {
            return self::refuse($business, 'phone_number_id', (string) $number['problem'], $number['meta']);
        }

        $kind = WhatsAppLinkCheck::numberType((array) $number['number'], (string) $token, false);

        if ($kind['problem']) {
            return self::refuse($business, 'phone_number_id',
                $kind['problem'] === WhatsAppLinkCheck::COEXISTENCE_NOT_CHOSEN ? self::ON_PHONE_APP : (string) $kind['problem']);
        }

        $billing = self::billing($waba, (string) $token);

        if ($billing['state'] === self::BILLING_ABAAD) {
            return self::refuse($business, 'waba_id', self::BILLED_TO_ABAAD, $billing['meta']);
        }

        $unregistered = ($number['number']['platform_type'] ?? null) === WhatsAppEmbeddedSignup::PLATFORM_NOT_APPLICABLE;
        $waits = $billing['state'] === self::BILLING_UNPROVEN || $unregistered;

        // وما لا يُرسل بعد لا يحلّ محلَّ رقمٍ يُرسل اليوم
        if ($waits && $existing?->isUsable()) {
            return ['ok' => false, 'field' => 'phone_number_id', 'connection' => null, 'pending' => false, 'message' => $billing['state'] === self::BILLING_UNPROVEN
                ? __('لم يثبت أنّ رسائل هذا الحساب تُحتسب عليك عند ميتا — بقي ربطك الحاليّ كما هو.')
                : __('الرقم الجديد لم يُفعَّل في واتساب للأعمال بعد — بقي ربطك الحاليّ كما هو. افصله ثمّ أعد الربط لتفعيل الرقم الجديد.')];
        }

        $key = self::is($existing) && filled($existing->webhook_key) ? (string) $existing->webhook_key : Str::random(40);
        $verify = self::is($existing) && filled($existing->webhook_verify_token) ? (string) $existing->webhook_verify_token : Str::random(32);

        // تطبيقُ التاجر يُشرك في إشعارات حسابه — ويثبت ظهورُه بعينه في `subscribed_apps`
        $hook = WhatsAppLinkCheck::subscribe($waba, (string) $token, $appId, self::callbackFor($key));

        if ($refusal = WhatsAppLinkCheck::subscriptionRefusal($hook, $existing)) {
            return self::refuse($business, 'access_token', $refusal, $hook['meta']);
        }

        $step = match (true) {
            $billing['state'] === self::BILLING_UNPROVEN => self::AWAITS_BILLING,
            $unregistered => WhatsAppOnboarding::NEEDS_REGISTRATION,
            default => null,
        };

        $attributes = [
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $business->id,
            'provider' => 'meta_cloud',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'link_type' => self::TYPE,
            'app_secret' => (string) $secret,
            'webhook_key' => $key,
            'webhook_verify_token' => $verify,
            'waba_id' => $waba,
            'meta_business_id' => $billing['owner_id'],
            'phone_number_id' => $phone,
            'display_phone_number' => $number['number']['display_phone_number'] ?? $existing?->display_phone_number,
            'coexistence' => false,
            'access_token' => (string) $token,
            'token_expires_at' => null,
            'status' => $step === null ? WhatsAppConnection::ACTIVE : WhatsAppConnection::PENDING,
            'last_error_code' => $step,
            'last_error_message' => null,
            'last_error_at' => null,
            'billing_state' => $billing['state'],
            'billing_owner_id' => $billing['owner_id'],
            'billing_owner_name' => $billing['owner_name'],
            'billing_checked_at' => now(),
            'connected_at' => $same ? $existing->connected_at : now(),
            'connected_by_user_id' => $actor->id,
            'disconnected_at' => null,
        ] + WhatsAppLinkCheck::stamp($appId, $hook['state'], WhatsAppLinkCheck::routing($phone, (string) $token)['state'])
          + ($existing && ! $same ? WhatsAppLinkHealth::UNHEARD : [])
          // وعنوانٌ جديد لم تطابقه ميتا بعد
          + (self::is($existing) && $existing->webhook_key === $key ? [] : ['webhook_verified_at' => null]);

        $connection = $existing ? tap($existing)->update($attributes) : WhatsAppConnection::create($attributes);

        WhatsAppTemplates::seedBusinessDefaults($business->id);

        Activity::log('settings', 'واتساب — ربط رقم المتجر بتطبيق التاجر ('
            .($connection->display_phone_number ?? $phone).')');

        return ['ok' => true, 'field' => null, 'connection' => $connection, 'pending' => $step !== null, 'message' => match ($step) {
            self::AWAITS_BILLING => __('حُفظ الربط — ولا يُرسل حتى يثبت أنّ رسائل حسابك تُحتسب عليك عند ميتا. أكمل الخطوات أدناه.'),
            WhatsAppOnboarding::NEEDS_REGISTRATION => __('تمّ التفويض — بقيت خطوةٌ واحدة: فعّل رقمك برمز التحقّق بخطوتين (ستّة أرقام) أدناه.'),
            default => __('رُبط رقمك بتطبيقك — الصق عنوان الإشعارات ورمز التحقّق في لوحة تطبيقك ليصلنا ما يكتبه زبائنك.'),
        }];
    }

    /**
     * على من تُحتسب رسائلُ هذا الحساب عند ميتا؟
     *
     * ═══ ما يُعدّ دليلًا ═══
     *
     * حسابٌ نوعُ ملكيّته `SELF`، ومالكُه هو من أُنشئ نيابةً عنه، وليس واحدًا
     * من حسابات أعمال أبعاد. فحسابٌ كهذا يملكه صاحبُه ويستعمله لنفسه، وفوترتُه
     * فوترتُه. وأبعاد ليست «مزوّد حلول أعمال» عند ميتا، فلا خطَّ ائتمانٍ لها
     * يُشارَك مع حسابٍ لا تملكه (`#10` في فحص 2026-10-31).
     *
     * و`primary_funding_id` لا يُعتمد: ميتا لا تُعيده لنا أصلًا.
     *
     * وما سوى ذلك «لم يثبت» — لا «على التاجر»: قراءةٌ فشلت، أو ملكيّةٌ لشريك
     * (`CLIENT_OWNED` / `ON_BEHALF_OF`)، أو حساباتُ أبعاد لم تُعرف بعد. فيبقى
     * الربطُ لا يُرسل حتى يؤكّد صاحبُ المنصّة الإثبات (`confirmBilling`).
     *
     * @return array{state:string, owner_id:?string, owner_name:?string, meta:array{code:?string, message:?string}}
     */
    public static function billing(string $waba, string $token): array
    {
        $read = WhatsAppEmbeddedSignup::wabaOwnership($waba, $token);
        $meta = ['code' => $read['code'], 'message' => $read['message']];

        if (! $read['ok']) {
            return ['state' => self::BILLING_UNPROVEN, 'owner_id' => null, 'owner_name' => null, 'meta' => $meta];
        }

        $abaad = self::abaadBusinessIds();
        $named = array_values(array_filter([$read['owner_id'], $read['on_behalf_id']]));

        if (array_intersect($named, $abaad) !== []) {
            return ['state' => self::BILLING_ABAAD, 'owner_id' => $read['owner_id'], 'owner_name' => $read['owner_name'], 'meta' => $meta];
        }

        $self = $read['ownership_type'] === 'SELF'
            && filled($read['owner_id'])
            && ($read['on_behalf_id'] === null || $read['on_behalf_id'] === $read['owner_id']);

        return [
            'state' => $self && $abaad !== [] ? self::BILLING_MERCHANT : self::BILLING_UNPROVEN,
            'owner_id' => $read['owner_id'],
            'owner_name' => $read['owner_name'],
            'meta' => $meta,
        ];
    }

    /**
     * حساباتُ أعمال أبعاد عند ميتا — من الإعداد، ومن مالكي حسابات أرقام المنصّة.
     *
     * وما لم يُعرف لا يُخزَّن فارغًا: قراءةٌ فشلت اليوم تُعاد غدًا، وقائمةٌ
     * فارغةٌ تجعل كلَّ فوترةٍ «لم تثبت» — لا «على التاجر».
     *
     * @return list<string>
     */
    public static function abaadBusinessIds(): array
    {
        $configured = array_map('strval', (array) config('whatsapp.abaad_business_ids', []));
        $cached = Cache::get('whatsapp.abaad_business_ids');

        if (! is_array($cached)) {
            $cached = [];

            foreach (WhatsAppConnection::where('owner_type', WhatsAppMode::OWNER_PLATFORM)->get() as $platform) {
                $owner = filled($platform->meta_business_id) ? (string) $platform->meta_business_id
                    : (filled($platform->waba_id) && filled($platform->access_token)
                        ? WhatsAppEmbeddedSignup::wabaOwnership((string) $platform->waba_id, (string) $platform->access_token)['owner_id']
                        : null);

                if (filled($owner)) {
                    $cached[] = (string) $owner;
                }
            }

            if ($cached !== []) {
                Cache::put('whatsapp.abaad_business_ids', $cached, now()->addDay());
            }
        }

        return array_values(array_unique(array_merge($configured, $cached)));
    }

    /** @return list<string> */
    private static function platformWabas(): array
    {
        return WhatsAppConnection::where('owner_type', WhatsAppMode::OWNER_PLATFORM)
            ->whereNotNull('waba_id')->pluck('waba_id')->map(fn ($w) => (string) $w)->all();
    }

    /**
     * يؤكّد صاحبُ المنصّة أنّ فوترة هذا الحساب على التاجر — بعد أن رأى إثباتها.
     *
     * ثمّ تمضي الوصلة إلى خطوتها التالية: تفعيلُ الرقم إن كان جديدًا على
     * Cloud API، وإلّا «نشطة». ولا يمسّ هذا رمزًا ولا رقمًا.
     */
    public static function confirmBilling(WhatsAppConnection $connection): WhatsAppConnection
    {
        if (! self::is($connection) || self::billed($connection)) {
            return $connection;
        }

        $connection->forceFill(['billing_state' => self::BILLING_CONFIRMED, 'billing_checked_at' => now()])->save();

        return self::advance($connection);
    }

    /** وصلةٌ ثبتت فوترتُها تمضي إلى خطوتها التالية */
    private static function advance(WhatsAppConnection $connection): WhatsAppConnection
    {
        if (! self::awaitsBilling($connection) || ! self::billed($connection)) {
            return $connection;
        }

        $number = WhatsAppEmbeddedSignup::inspectNumber((string) $connection->phone_number_id, (string) $connection->access_token);
        $unregistered = $number['ok'] && ($number['platform_type'] ?? null) === WhatsAppEmbeddedSignup::PLATFORM_NOT_APPLICABLE;

        $connection->forceFill([
            'status' => $unregistered ? WhatsAppConnection::PENDING : WhatsAppConnection::ACTIVE,
            'last_error_code' => $unregistered ? WhatsAppOnboarding::NEEDS_REGISTRATION : null,
        ])->save();

        return $connection;
    }

    /**
     * «تحقّق الآن» لوصلة تطبيق التاجر — قراءةٌ عند ميتا، ونداءٌ واحدٌ إن لزم.
     *
     * يُعاد سؤالُ الفوترة لما لم يثبت، ويُثبت اشتراكُ تطبيقه في حسابه (`subscribe`
     * يُنادي `POST` على حسابه وبرمزه حين تقول قراءةٌ إنّه غائب)، ويُقرأ إعدادُ
     * إشعارات تطبيقه: أعنوانُها عنوانُنا، وأفيها حقلُ `messages`؟
     *
     * @return array{billing:string, webhook:string, app:string}
     */
    public static function recheck(WhatsAppConnection $connection): array
    {
        if (! self::billed($connection)) {
            $billing = self::billing((string) $connection->waba_id, (string) $connection->access_token);

            if ($billing['state'] !== self::BILLING_UNPROVEN) {
                $connection->forceFill([
                    'billing_state' => $billing['state'],
                    'billing_owner_id' => $billing['owner_id'] ?? $connection->billing_owner_id,
                    'billing_owner_name' => $billing['owner_name'] ?? $connection->billing_owner_name,
                    'billing_checked_at' => now(),
                ])->save();
            }

            self::advance($connection);
        }

        $callback = self::callbackUrl($connection);
        $hook = WhatsAppLinkCheck::subscribe((string) $connection->waba_id, (string) $connection->access_token, (string) $connection->token_app_id, $callback);
        $app = WhatsAppLinkCheck::appWebhook((string) $connection->token_app_id, $connection->app_secret, $callback);

        $connection->forceFill([
            'webhook_state' => $hook['state'] === WhatsAppLinkCheck::UNKNOWN ? $connection->webhook_state : $hook['state'],
            'webhook_checked_at' => $hook['state'] === WhatsAppLinkCheck::UNKNOWN ? $connection->webhook_checked_at : now(),
            'metadata' => array_merge((array) $connection->metadata, ['app_webhook' => $app['state']]),
        ])->save();

        return ['billing' => (string) $connection->billing_state, 'webhook' => $hook['state'], 'app' => $app['state']];
    }

    /**
     * ما تعرضه شاشةُ الربط عن وصلة تطبيق التاجر — بلا رمزٍ ولا سرّ تطبيق.
     *
     * ورمزُ التحقّق يُعرض لصاحب الحساب وحده: يكتبه في لوحة تطبيقه، وليس
     * مفتاحًا يُرسَل به أو يُقرأ به شيء.
     *
     * @return array<string, mixed>
     */
    public static function view(?WhatsAppConnection $connection, bool $mayManage): array
    {
        $mine = self::is($connection) && $connection->status !== WhatsAppConnection::INACTIVE;

        return [
            'enabled' => self::enabled(),
            'connection' => $mine ? [
                'app_id' => $connection->token_app_id,
                'waba_id' => $connection->waba_id,
                'phone_number_id' => $connection->phone_number_id,
                'callback_url' => self::callbackUrl($connection),
                'verify_token' => $mayManage ? $connection->webhook_verify_token : null,
                'webhook_verified_at' => $connection->webhook_verified_at?->format('Y-m-d H:i'),
                'last_webhook_at' => $connection->last_webhook_at?->format('Y-m-d H:i'),
                'app_webhook' => ((array) $connection->metadata)['app_webhook'] ?? null,
                'billing' => [
                    'state' => $connection->billing_state,
                    'owner' => $connection->billing_owner_name,
                    'checked_at' => $connection->billing_checked_at?->format('Y-m-d H:i'),
                ],
            ] : null,
        ];
    }

    /** ما يقرؤه التاجر عن كلّ علّة — بلا رمزٍ ولا سرّ */
    public static function say(string $problem): string
    {
        return match ($problem) {
            self::ABAAD_APP => __('هذا معرّف تطبيق أبعاد — اربط بتطبيقك أنت على Meta، فالرقم والحساب والفوترة لك.'),
            self::OTHER_APP => __('الرمز صادرٌ من تطبيقٍ غير الذي كتبت معرّفه — أنشئ الرمز من تطبيقك نفسه.'),
            self::APP_UNREADABLE => __('تعذّر التحقّق من الرمز بمعرّف تطبيقك وسرّه — تأكّد من App ID وApp Secret، ومن أنّ الرمز صادرٌ من هذا التطبيق ولم يُسحب.'),
            self::TOKEN_EXPIRES => __('هذا رمزٌ مؤقّت ينتهي — أنشئ رمز «مستخدم نظام» لا ينتهي من إعدادات حسابك في Meta.'),
            self::BILLED_TO_ABAAD => __('هذا الحساب تُحتسب رسائله على أبعاد لا عليك — اربط حساب واتساب للأعمال الذي يملكه نشاطك ووسيلة دفعه.'),
            self::ON_PHONE_APP => __('هذا الرقم يعمل على تطبيق واتساب للأعمال في الهاتف. الربط بتطبيقك يحتاج رقمًا ليس على تطبيق الهاتف — والتعايش لا تتيحه ميتا إلّا عبر شريكٍ تقنيّ.'),
            default => WhatsAppLinkCheck::say($problem),
        };
    }

    /**
     * @param  array{code:?string, message:?string}  $meta
     * @return array{ok:bool, field:string, message:string, connection:null, pending:bool}
     */
    private static function refuse(Business $business, string $field, string $problem, array $meta = ['code' => null, 'message' => null]): array
    {
        WhatsAppLinkCheck::log('own_app_link_refused', $business->id, $meta + ['problem' => $problem]);

        return ['ok' => false, 'field' => $field, 'message' => self::say($problem), 'connection' => null, 'pending' => false];
    }
}

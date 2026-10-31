<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Carbon;
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
 *   · حسابٌ تُحتسب رسائلُه على أبعاد (`billing`). ولا يُعدّ «جاهزًا» حتى يراجع
 *     فريقُ أبعاد دليلًا من ميتا على أنّ رسومه على التاجر (`confirmBilling`):
 *     يبقى «بانتظار إثبات الفوترة» ولا يُرسل — ولا تفتحه ملكيّةٌ ولا إقرار.
 *   · رقمٌ على تطبيق واتساب للأعمال في الهاتف: التعايشُ لا تتيحه ميتا إلّا
 *     عبر شريكٍ تقنيّ بالتسجيل المدمج — فلا نَعِد به هنا.
 *
 * ولا `register` ولا `deregister` ولا حذفَ عند ميتا من هذا الملفّ: الرقمُ
 * المسجَّل يبقى كما هو، والجديدُ يُسجَّل برمز صاحبه (`WhatsAppOnboarding::register`).
 */
class WhatsAppOwnApp
{
    public const TYPE = 'own_app';

    /** راجع فريقُ أبعاد دليلًا من ميتا على أنّها على التاجر — الطريقُ الوحيد إلى الإرسال */
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

    /* ═══ وقائعُ من ميتا تمنع تأكيدَ الفوترة ولو رُئي دليل — أو توقفه بعده ═══ */

    public const NO_PAYMENT_METHOD = 'billing_no_payment_method';

    public const SHARED_WITH_ABAAD = 'billing_shared_with_abaad';

    public const PARTNER_OWNED = 'billing_partner_owned';

    public const FUNDING_CHANGED = 'billing_funding_changed';

    /* ═══ وحسابُ الرسائل الذي تُحتسب عليه كلُّ رسالة (`sendAccount`) ═══ */

    /** الرمزُ يصل على هذا الرقم أكثرَ من حساب رسائل — أو لا يُعرف كم — ولا يُسمّى الحسابُ في الرسالة */
    public const AMBIGUOUS_ACCOUNT = 'messaging_account_ambiguous';

    /** الرمزُ لم يعد يُرسل من هذا الحساب، أو الرقمُ لم يعد فيه */
    public const ACCOUNT_UNBOUND = 'messaging_account_unbound';

    /** حسابُ الرسائل مربوطٌ لمتجرٍ آخر عندنا */
    public const ACCOUNT_TAKEN = 'messaging_account_taken';

    /** ما يُقبل دليلًا — من ميتا نفسها، باسم نشاط التاجر، لهذا الحساب */
    public const EVIDENCE = [
        'meta_invoice' => 'فاتورة أو إيصال من ميتا لرسائل هذا الحساب باسم نشاط التاجر',
        'payment_settings' => 'صفحة «الفوترة والمدفوعات» في حساب أعمال التاجر تُظهر وسيلة دفعه على هذا الحساب',
    ];

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

    /** راجع فريقُ أبعاد دليلَ أنّ فوترتها على التاجر؟ — ولا يُرسل غيرُها (`WhatsAppConnection::isUsable`) */
    public static function billed(?WhatsAppConnection $connection): bool
    {
        return self::is($connection) && $connection->billing_state === self::BILLING_CONFIRMED;
    }

    /**
     * وصلةُ تطبيق تاجرٍ لم تُراجَع فوترتُها — لا تُرسل.
     *
     * من الفوترة وحدها لا من الحالة: وصلةٌ أخطأت ثمّ نجح «فحصُ الربط» لا تصير
     * بنجاحه «نشطة» قبل أن يُراجَع دليلُها.
     */
    public static function awaitsBilling(?WhatsAppConnection $connection): bool
    {
        return self::is($connection)
            && $connection->status !== WhatsAppConnection::INACTIVE
            && ! self::billed($connection);
    }

    /**
     * أيُؤكِّد فريقُ أبعاد فوترةَ هذه الوصلة؟ — وصلةُ متجرٍ برقمه، لم تُؤكَّد.
     *
     * ووصلةُ رقمٍ يُرسل اليوم بطريقه القديم (RIBBON) تُراجَع أيضًا: فإذا أكمل
     * صاحبُها الربطَ بتطبيقه على الحساب نفسه مضى الدليلُ معه، ولم يقف إرسالُه.
     */
    public static function mayConfirm(?WhatsAppConnection $connection): bool
    {
        return $connection !== null
            && $connection->owner_type === WhatsAppMode::OWNER_BUSINESS
            && filled($connection->waba_id) && filled($connection->access_token)
            && $connection->status !== WhatsAppConnection::INACTIVE
            && $connection->billing_state !== self::BILLING_CONFIRMED;
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

        // وحسابُ رسائلٍ لمتجرٍ واحد: متجران على حسابٍ واحد تُحتسب رسائلُهما معًا
        if (self::accountTaken($waba, $business->id)) {
            return self::refuse($business, 'waba_id', self::ACCOUNT_TAKEN);
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

        $billing = self::read($waba, (string) $token, $phone, $appId, (string) $secret);

        if ($billing['state'] === self::BILLING_ABAAD) {
            return self::refuse($business, 'waba_id', self::BILLED_TO_ABAAD, $billing['meta']);
        }

        /*
         * ودليلٌ راجعه فريقُ أبعاد على هذا الحساب نفسه يمضي مع الربط — ما لم
         * تنقضه وقائعُ ميتا اليوم. وما سواه ينتظر مراجعةً: إقرارُ التاجر
         * (`billing_ack`) وملكيّةُ الحساب لا يفتحان الإرسال.
         */
        $carried = $existing !== null
            && (string) $existing->waba_id === $waba
            && $existing->billing_state === self::BILLING_CONFIRMED
            && self::refuted((array) $existing->billing_evidence, $billing, $waba) === null;

        $unregistered = ($number['number']['platform_type'] ?? null) === WhatsAppEmbeddedSignup::PLATFORM_NOT_APPLICABLE;
        $waits = ! $carried || $unregistered;

        // وما لا يُرسل بعد لا يحلّ محلَّ رقمٍ يُرسل اليوم
        if ($waits && $existing?->isUsable()) {
            return ['ok' => false, 'field' => 'phone_number_id', 'connection' => null, 'pending' => false, 'message' => ! $carried
                ? __('لم يُراجَع بعدُ دليلٌ من ميتا على أنّ رسائل هذا الحساب تُحتسب عليك — بقي ربطك الحاليّ كما هو حتى يراجعه فريق أبعاد.')
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
            ! $carried => self::AWAITS_BILLING,
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
            'billing_state' => $carried ? self::BILLING_CONFIRMED : $billing['state'],
            'billing_evidence' => $carried ? $existing->billing_evidence : null,
            'billing_owner_id' => $billing['owner_id'],
            'billing_owner_name' => $billing['owner_name'],
            'billing_checked_at' => now(),
            'metadata' => array_merge((array) $existing?->metadata, ['billing_facts' => $billing['facts']]),
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
            self::AWAITS_BILLING => __('حُفظ الربط — ولا يُرسل حتى يراجع فريق أبعاد دليلًا من ميتا على أنّ رسائل حسابك تُحتسب عليك. أكمل الخطوات أدناه.'),
            WhatsAppOnboarding::NEEDS_REGISTRATION => __('تمّ التفويض — بقيت خطوةٌ واحدة: فعّل رقمك برمز التحقّق بخطوتين (ستّة أرقام) أدناه.'),
            default => __('رُبط رقمك بتطبيقك — الصق عنوان الإشعارات ورمز التحقّق في لوحة تطبيقك ليصلنا ما يكتبه زبائنك.'),
        }];
    }

    /**
     * على من تُحتسب رسائلُ هذا الحساب عند ميتا؟ — ما نقرؤه، وما لا نقدر عليه.
     *
     * ═══ ما لا تقوله الواجهةُ لنا ═══
     *
     * في نموذج ميتا الجديد ينفصل «حسابُ واتساب» (WAAC: الرقمُ والاسم) عن
     * «حساب الرسائل» (القوالبُ ووسيلةُ الدفع) — ومعرّفُ الـWABA هو معرّفُ حساب
     * الرسائل. ووسيلةُ دفعه تُسأل بـ`primary_funding_id`، وميتا تردّه بـ`#10`
     * لكلّ تطبيقٍ لا يملكه «مزوّد حلول أعمال» — ولو سأل صاحبُ الحساب عن حسابه
     * برمز تطبيقه (فحص 2026-10-31). والملكيّةُ `SELF` تقول من يملك الحساب لا
     * من يدفع عنه.
     *
     * فلا شيءَ هنا يُثبت أنّ الرسوم على التاجر: النتيجةُ «لم يثبت» أو «أبعاد»
     * لا غير، ويثبت ذلك بمراجعة دليلٍ من ميتا (`confirmBilling`).
     *
     * ═══ وما نقرؤه — فيردّ الربط، أو يمنع التأكيد، أو يوقفه بعده ═══
     *
     *   · المالك، ومن أُنشئ نيابةً عنه: أحدُهما أبعاد → يُردّ.
     *   · أيرى نشاطُ أبعاد الحساب (`abaadAccess`)؟ يملكه → يُردّ؛ شُورك معه →
     *     لا يُؤكَّد: خطُّ ائتمان شريكٍ لا يبلغ حسابًا لم يُشارَك معه.
     *   · `primary_funding_id` إن ردّته ميتا: غائبٌ = لا وسيلة دفع → لا يُؤكَّد؛
     *     وحاضرٌ تُحفظ بصمتُه، فإن تبدّلت بعد التأكيد وقف الإرسال.
     *   · حسابٌ لشريكٍ نيابةً عن التاجر (`ON_BEHALF_OF`) → لا يُؤكَّد.
     *
     * @return array{state:string, owner_id:?string, owner_name:?string, facts:array<string, mixed>, meta:array{code:?string, message:?string}}
     */
    public static function billing(string $waba, string $token): array
    {
        $read = WhatsAppEmbeddedSignup::wabaOwnership($waba, $token);
        $funding = WhatsAppEmbeddedSignup::funding($waba, $token);
        $access = self::abaadAccess($waba);

        $facts = [
            'ownership' => $read['ok'] ? $read['ownership_type'] : null,
            'partner_owned' => $read['ok'] && ($read['ownership_type'] === 'ON_BEHALF_OF'
                || (filled($read['on_behalf_id']) && $read['on_behalf_id'] !== $read['owner_id'])),
            'funding' => $funding['state'],
            'funding_mark' => $funding['mark'],
            'funding_code' => $funding['code'],
            'abaad_access' => $access,
            'read_at' => now()->toIso8601String(),
        ];

        $named = array_values(array_filter([$read['owner_id'], $read['on_behalf_id']]));
        $abaad = array_intersect($named, self::abaadBusinessIds()) !== [] || $access === 'owned';

        return [
            'state' => $abaad ? self::BILLING_ABAAD : self::BILLING_UNPROVEN,
            'owner_id' => $read['owner_id'],
            'owner_name' => $read['owner_name'],
            'facts' => $facts,
            'meta' => ['code' => $read['code'], 'message' => $read['message']],
        ];
    }

    /**
     * الفوترةُ وعلاقةُ الرمز والرقم والحساب — قراءةٌ واحدة تُحفظ وقائعُها معًا.
     *
     * @return array{state:string, owner_id:?string, owner_name:?string, facts:array<string, mixed>, meta:array{code:?string, message:?string}}
     */
    private static function read(string $waba, string $token, string $phone, ?string $appId, ?string $secret): array
    {
        $billing = self::billing($waba, $token);
        $billing['facts'] += self::relation($waba, $token, $phone, $appId, $secret);

        return $billing;
    }

    /**
     * أما زال الرمزُ يُرسل من هذا الحساب، والرقمُ فيه؟ — وكم حسابَ رسائل يصله الرمزُ على هذا الرقم.
     *
     * `debug_token` يُسمّي الحساباتِ التي مُنح الرمزُ الإرسالَ منها
     * (`whatsapp_business_messaging`)، فيُسأل كلٌّ منها: أفيه هذا الرقم؟ وما لم
     * يُقرأ — قائمةٌ فارغة تعني «كلَّ ما يصله صاحبُ الرمز»، أو نداءٌ فشل — لا
     * يُعدّ: `reach` يبقى `null`، وهو «لا نعرف» لا «واحد».
     *
     * @return array{token_sends:?bool, phone_in_account:?bool, reach:?int}
     */
    public static function relation(string $waba, string $token, string $phone, ?string $appId, ?string $secret): array
    {
        $inspect = filled($appId) && filled($secret) ? WhatsAppEmbeddedSignup::inspect($token, $appId, $secret) : null;
        $messaging = $inspect !== null && $inspect['ok'] && $inspect['messaging_waba_ids'] !== [] ? $inspect['messaging_waba_ids'] : null;

        $numbers = WhatsAppEmbeddedSignup::phoneNumbers($waba, $token);
        $has = fn (array $list) => collect($list)->contains(fn ($n) => (string) ($n['id'] ?? '') === $phone);
        $phoneIn = $numbers['ok'] ? $has($numbers['numbers']) : null;
        $sends = $messaging === null ? null : in_array($waba, $messaging, true);

        $reach = null;
        $others = $messaging === null ? [] : array_values(array_diff($messaging, [$waba]));

        if ($sends === true && $phoneIn === true && count($others) <= 20) {
            $reach = 1;

            foreach ($others as $other) {
                $list = WhatsAppEmbeddedSignup::phoneNumbers($other, $token);

                if (! $list['ok']) {
                    $reach = null;
                    break;
                }

                $reach += $has($list['numbers']) ? 1 : 0;
            }
        }

        return ['token_sends' => $sends, 'phone_in_account' => $phoneIn, 'reach' => $reach];
    }

    /** أحسابُ الرسائل هذا مربوطٌ عندنا لمتجرٍ غير هذا؟ — مفصولًا كان أو قائمًا، كالرقم */
    public static function accountTaken(string $waba, int $businessId): bool
    {
        return WhatsAppConnection::where('waba_id', $waba)
            ->where('owner_type', WhatsAppMode::OWNER_BUSINESS)
            ->where('business_id', '!=', $businessId)
            ->exists();
    }

    /**
     * على أيّ حساب رسائل تُحتسب رسالةُ هذه الوصلة؟ — يُسمّى في كلّ رسالة، أو لا تخرج.
     *
     * ═══ لماذا يُسمّى ═══
     *
     * الرقمُ الواحد قد يحمل أكثر من حساب رسائل (حسابٌ لكلّ شريك). وميتا تقول:
     * رمزٌ يصل على الرقم أكثرَ من حساب ولم يُرسل `messaging_account_id` «قد
     * تُحتسب رسالتُه على حساب رسائل لم تقصده، أو تُرفض». فيُسمّى الحسابُ الذي
     * رُوجع دليلُ فوترته — ومعرّفُه معرّفُ الـWABA نفسُه في نموذجها الجديد.
     *
     * ═══ ومتى لا يُسمّى ═══
     *
     * إصدارٌ أقدم من `messaging_account_since`، أو المفتاحُ مطفأ: لا يُفترض أنّ
     * ميتا تقبله. فلا تخرج إلّا رسالةُ رمزٍ ثبت أنّه لا يصل على هذا الرقم إلّا
     * حسابًا واحدًا — وميتا تختاره وحدها. وما لم يثبت يُمنع: رسالةٌ لا تخرج
     * خيرٌ من رسالةٍ تُحتسب على حسابٍ آخر.
     *
     * ووصلاتُ أرقام أبعاد ووصلةُ RIBBON القديمة لا تُمسّ: لا يُضاف إليها شيء.
     *
     * @return array{account:?string, problem:?string}
     */
    public static function sendAccount(WhatsAppConnection $connection): array
    {
        if (! self::is($connection)) {
            return ['account' => null, 'problem' => null];
        }

        $reviewed = (string) (((array) $connection->billing_evidence)['waba_id'] ?? '');

        if (! self::billed($connection) || blank($connection->waba_id) || $reviewed !== (string) $connection->waba_id) {
            return ['account' => null, 'problem' => self::AWAITS_BILLING];
        }

        if (self::namesAccount()) {
            return ['account' => (string) $connection->waba_id, 'problem' => null];
        }

        $reach = ((array) (((array) $connection->metadata)['billing_facts'] ?? []))['reach'] ?? null;

        return $reach === 1 ? ['account' => null, 'problem' => null] : ['account' => null, 'problem' => self::AMBIGUOUS_ACCOUNT];
    }

    /** أيُسمّى حسابُ الرسائل في الرسالة على إصدار Graph الحاليّ؟ — `whatsapp.messaging_account_*` */
    public static function namesAccount(): bool
    {
        $version = ltrim((string) config('whatsapp.api_version'), 'v');
        $since = ltrim((string) config('whatsapp.messaging_account_since', 'v22.0'), 'v');

        return (bool) config('whatsapp.messaging_account_param', true)
            && preg_match('/^\d+(\.\d+)?$/', $version) === 1
            && version_compare($version, $since, '>=');
    }

    /**
     * أيُحوَّل إرسالُ المتجر إلى رقمه؟ — وصلةٌ تُرسل، رُوجع دليلُ فوترتها، ويُعرف حسابُ رسائلها.
     *
     * والطريقان يسألانه — التاجرُ من شاشته وفريقُ أبعاد من بطاقة المتجر: من
     * يُحوَّل قبل ذلك تقف رسائلُه، ومن بقي ينتظر يُرسل كما كان.
     */
    public static function readyForOwnMode(?WhatsAppConnection $connection): bool
    {
        return $connection !== null
            && $connection->owner_type === WhatsAppMode::OWNER_BUSINESS
            && $connection->isUsable()
            && $connection->billing_state === self::BILLING_CONFIRMED
            && self::sendAccount($connection)['problem'] === null;
    }

    /**
     * أتنقض وقائعُ ميتا اليوم دليلًا رُوجع؟ — سببُ النقض، أو `null`.
     *
     * وما لم يُقرأ لا ينقض: `#10` على وسيلة الدفع، أو قائمةٌ لم تُقرأ، لا
     * تُوقف إرسالًا رُوجع دليلُه. ينقضه ما قرأناه فخالفه.
     *
     * @param  array<string, mixed>  $evidence  ما حُفظ عند التأكيد
     * @param  array{state:string, facts:array<string, mixed>}  $billing  قراءةُ اليوم (`billing`)
     */
    public static function refuted(array $evidence, array $billing, ?string $waba = null): ?string
    {
        $facts = $billing['facts'];
        $was = $evidence['funding_mark'] ?? null;

        return match (true) {
            blank($evidence['reviewed_at'] ?? null) => self::AWAITS_BILLING,
            $waba !== null && (string) ($evidence['waba_id'] ?? '') !== $waba => self::AWAITS_BILLING,
            $billing['state'] === self::BILLING_ABAAD => self::BILLED_TO_ABAAD,
            ($blocker = self::blocker($facts)) !== null => $blocker,
            filled($was) && filled($facts['funding_mark']) && $was !== $facts['funding_mark'] => self::FUNDING_CHANGED,
            default => null,
        };
    }

    /**
     * ما يمنع التأكيدَ في وقائعِ قراءةٍ واحدة — ولو رُئي دليل.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function blocker(array $facts): ?string
    {
        return match (true) {
            ($facts['abaad_access'] ?? null) === 'owned' => self::BILLED_TO_ABAAD,
            ($facts['funding'] ?? null) === 'absent' => self::NO_PAYMENT_METHOD,
            ($facts['abaad_access'] ?? null) === 'shared' => self::SHARED_WITH_ABAAD,
            ($facts['partner_owned'] ?? false) === true => self::PARTNER_OWNED,
            ($facts['token_sends'] ?? null) === false, ($facts['phone_in_account'] ?? null) === false => self::ACCOUNT_UNBOUND,
            default => null,
        };
    }

    /**
     * ما يراه فريقُ أبعاد قبل أن يؤكّد — وقائعُ ميتا كما قُرئت، وما يمنع، وما رُوجع.
     *
     * بلا رمزٍ ولا سرٍّ ولا معرّفِ وسيلة دفع: البصمةُ نفسُها لا تُعرض.
     *
     * @return array<string, mixed>
     */
    public static function reviewView(WhatsAppConnection $connection): array
    {
        $facts = (array) (((array) $connection->metadata)['billing_facts'] ?? []);
        $evidence = (array) $connection->billing_evidence;
        $blocker = $facts === [] ? null : self::blocker($facts);

        return [
            'own_app' => self::is($connection),
            'billing_state' => $connection->billing_state,
            'billing_owner' => $connection->billing_owner_name,
            'awaits_billing' => self::awaitsBilling($connection),
            'may_confirm' => self::mayConfirm($connection),
            'facts' => $facts === [] ? null : [
                'ownership' => $facts['ownership'] ?? null,
                'funding' => $facts['funding'] ?? null,
                'abaad_access' => $facts['abaad_access'] ?? null,
                'partner_owned' => (bool) ($facts['partner_owned'] ?? false),
                'token_sends' => $facts['token_sends'] ?? null,
                'phone_in_account' => $facts['phone_in_account'] ?? null,
                'reach' => $facts['reach'] ?? null,
                'read_at' => filled($facts['read_at'] ?? null) ? Carbon::parse($facts['read_at'])->format('Y-m-d H:i') : null,
            ],
            'blocker' => $blocker === null ? null : self::say($blocker),
            'stopped' => self::is($connection) && $connection->last_error_code === self::AWAITS_BILLING && filled($connection->last_error_message)
                ? (string) $connection->last_error_message : null,
            'evidence' => $connection->billing_state === self::BILLING_CONFIRMED && $evidence !== [] ? [
                'kind' => __(self::EVIDENCE[$evidence['kind'] ?? ''] ?? (string) ($evidence['kind'] ?? '')),
                'reference' => $evidence['reference'] ?? null,
                'reviewed_by' => $evidence['reviewed_by'] ?? null,
                'reviewed_at' => filled($evidence['reviewed_at'] ?? null) ? Carbon::parse($evidence['reviewed_at'])->format('Y-m-d H:i') : null,
            ] : null,
            // ويُسمّى حسابُ الرسائل في كلّ رسالة؟ — وإلّا فالرمزُ يصل على الرقم حسابًا واحدًا أو لا تخرج
            'names_account' => self::namesAccount(),
            'kinds' => collect(self::EVIDENCE)->map(fn (string $label, string $key) => ['key' => $key, 'label' => __($label)])->values()->all(),
        ];
    }

    /**
     * أيرى نشاطُ أبعاد هذا الحساب عند ميتا؟ — برمز أرقام المنصّة، قراءةً لا غير.
     *
     * ما يملكه (`owned_whatsapp_business_accounts`)، وما شُورك معه
     * (`client_whatsapp_business_accounts`). وما لم يُقرأ «لا نعرف» — لا «لا».
     *
     * @return 'owned'|'shared'|'none'|'unknown'
     */
    public static function abaadAccess(string $waba): string
    {
        $platform = WhatsAppConnection::where('owner_type', WhatsAppMode::OWNER_PLATFORM)->get()
            ->first(fn (WhatsAppConnection $c) => filled($c->access_token));
        $ids = self::abaadBusinessIds();

        if (! $platform || $ids === []) {
            return 'unknown';
        }

        $known = true;

        foreach ($ids as $business) {
            foreach (['owned' => 'owned_whatsapp_business_accounts', 'shared' => 'client_whatsapp_business_accounts'] as $kind => $edge) {
                $list = WhatsAppEmbeddedSignup::businessWabas($business, $edge, (string) $platform->access_token);

                if ($list === null) {
                    $known = false;
                } elseif (in_array($waba, $list, true)) {
                    return $kind;
                }
            }
        }

        return $known ? 'none' : 'unknown';
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
     * يؤكّد فريقُ أبعاد أنّ فوترة هذا الحساب على التاجر — بعد أن راجع دليلًا من ميتا.
     *
     * وهو الطريقُ الوحيد إلى الإرسال: إقرارُ التاجر لا يكفي، وملكيّةُ الحساب
     * لا تكفي. ويُقرأ الحسابُ عند ميتا لحظةَ التأكيد، فوقائعُ تنقضه تردّه ولو
     * رُئي الدليل. ويُحفظ ما رُوجع، ومرجعُه، ومن راجعه ومتى، وبصمةُ وسيلة الدفع
     * إن قرأناها — فإن تبدّلت بعده وقف الإرسال (`recheckBilling`).
     *
     * ثمّ تمضي الوصلة إلى خطوتها التالية: تفعيلُ الرقم إن كان جديدًا على
     * Cloud API، وإلّا «نشطة». ولا يمسّ هذا رمزًا ولا رقمًا ولا شيئًا عند ميتا.
     *
     * @param  array{kind:string, reference:string}  $evidence
     * @return array{ok:bool, message:string}
     */
    public static function confirmBilling(WhatsAppConnection $connection, User $reviewer, array $evidence): array
    {
        if (! self::mayConfirm($connection) || ! array_key_exists($evidence['kind'], self::EVIDENCE)) {
            return ['ok' => false, 'message' => __('لا ربطَ لهذا المتجر ينتظر إثبات الفوترة.')];
        }

        $billing = self::read((string) $connection->waba_id, (string) $connection->access_token,
            (string) $connection->phone_number_id, $connection->token_app_id, $connection->app_secret);
        $record = [
            'kind' => $evidence['kind'],
            'reference' => mb_substr(trim($evidence['reference']), 0, 120),
            'reviewed_by_id' => $reviewer->id,
            'reviewed_by' => $reviewer->name,
            'reviewed_at' => now()->toIso8601String(),
            'waba_id' => (string) $connection->waba_id,
            'funding' => $billing['facts']['funding'],
            'funding_mark' => $billing['facts']['funding_mark'],
            'abaad_access' => $billing['facts']['abaad_access'],
        ];
        $refusal = self::accountTaken((string) $connection->waba_id, (int) $connection->business_id)
            ? self::ACCOUNT_TAKEN : self::refuted($record, $billing);

        $connection->forceFill([
            'billing_owner_id' => $billing['owner_id'] ?? $connection->billing_owner_id,
            'billing_owner_name' => $billing['owner_name'] ?? $connection->billing_owner_name,
            'billing_checked_at' => now(),
            'metadata' => array_merge((array) $connection->metadata, ['billing_facts' => $billing['facts']]),
        ] + ($refusal === null ? ['billing_state' => self::BILLING_CONFIRMED, 'billing_evidence' => $record] : []))->save();

        if ($refusal !== null) {
            return ['ok' => false, 'message' => self::say($refusal)];
        }

        self::advance($connection);

        return ['ok' => true, 'message' => self::is($connection)
            ? __('أُكّد أنّ فوترة ميتا على التاجر — مضى الربط إلى خطوته التالية.')
            : __('حُفظ الدليل — إذا أكمل التاجر الربط بتطبيقه على هذا الحساب لم يقف إرساله.')];
    }

    /** وصلةٌ رُوجعت فوترتُها تمضي إلى خطوتها التالية */
    private static function advance(WhatsAppConnection $connection): WhatsAppConnection
    {
        if (! self::billed($connection) || $connection->last_error_code !== self::AWAITS_BILLING) {
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
     * تُعاد قراءةُ فوترة الوصلة عند ميتا — قراءةٌ لا غير، ولا نداءَ يغيّر شيئًا.
     *
     * ووصلةٌ رُوجعت فوترتُها تنقضها وقائعُ اليوم — أبعادُ صارت مالكةً أو
     * شريكة، أو غابت وسيلةُ الدفع، أو تبدّلت — تقف: تعود «بانتظار إثبات
     * الفوترة» ولا تُرسل حتى يُراجَع دليلٌ جديد. وما لم يُقرأ لا يوقفها.
     *
     * @return string|null سببُ الإيقاف إن وقفت
     */
    public static function recheckBilling(WhatsAppConnection $connection): ?string
    {
        if (! self::is($connection) || $connection->status === WhatsAppConnection::INACTIVE) {
            return null;
        }

        $billing = self::read((string) $connection->waba_id, (string) $connection->access_token,
            (string) $connection->phone_number_id, $connection->token_app_id, $connection->app_secret);
        $billed = self::billed($connection);
        $stop = $billed ? self::refuted((array) $connection->billing_evidence, $billing, (string) $connection->waba_id) : null;

        $connection->forceFill([
            'billing_state' => $billed && $stop === null ? self::BILLING_CONFIRMED : $billing['state'],
            'billing_owner_id' => $billing['owner_id'] ?? $connection->billing_owner_id,
            'billing_owner_name' => $billing['owner_name'] ?? $connection->billing_owner_name,
            'billing_checked_at' => now(),
            'metadata' => array_merge((array) $connection->metadata, ['billing_facts' => $billing['facts']]),
        ] + ($stop === null ? [] : [
            'status' => WhatsAppConnection::PENDING,
            'last_error_code' => self::AWAITS_BILLING,
            'last_error_message' => self::say($stop),
            'last_error_at' => now(),
        ]))->save();

        if ($stop !== null) {
            WhatsAppLinkCheck::log('own_app_billing_stopped', $connection->business_id, ['problem' => $stop, 'code' => null, 'message' => null]);
            Activity::log('settings', 'واتساب — أُوقف إرسال وصلة تطبيق التاجر: نقضت قراءةُ ميتا دليلَ الفوترة', ['business_id' => $connection->business_id]);
        }

        return $stop;
    }

    /**
     * «تحقّق الآن» لوصلة تطبيق التاجر — قراءةٌ عند ميتا، ونداءٌ واحدٌ إن لزم.
     *
     * تُعاد قراءةُ الفوترة (`recheckBilling`)، ويُثبت اشتراكُ تطبيقه في حسابه
     * (`subscribe` يُنادي `POST` على حسابه وبرمزه حين تقول قراءةٌ إنّه غائب)،
     * ويُقرأ إعدادُ إشعارات تطبيقه: أعنوانُها عنوانُنا، وأفيها حقلُ `messages`؟
     *
     * @return array{billing:string, webhook:string, app:string}
     */
    public static function recheck(WhatsAppConnection $connection): array
    {
        self::recheckBilling($connection);

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
                    'reviewed_at' => self::billed($connection) ? substr((string) (((array) $connection->billing_evidence)['reviewed_at'] ?? ''), 0, 10) ?: null : null,
                    // ما يُرسله التاجر دليلًا — من ميتا نفسها، لا إقرارُه
                    'evidence' => array_values(array_map(fn (string $label) => __($label), self::EVIDENCE)),
                    'stopped' => $connection->last_error_code === self::AWAITS_BILLING && filled($connection->last_error_message)
                        ? (string) $connection->last_error_message : null,
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
            self::AWAITS_BILLING => __('لم يُراجَع بعدُ دليلٌ من ميتا على أنّ رسائل هذا الحساب تُحتسب على التاجر.'),
            self::NO_PAYMENT_METHOD => __('ميتا تقول إنّ هذا الحساب بلا وسيلة دفع — تُضاف من «الفوترة والمدفوعات» في حساب أعمال التاجر ثمّ يُراجَع الدليل.'),
            self::SHARED_WITH_ABAAD => __('هذا الحساب مشارَكٌ مع نشاط أبعاد عند ميتا — تُزال المشاركة أوّلًا، فحسابُ التاجر لا يُشارَك مع أبعاد.'),
            self::PARTNER_OWNED => __('هذا الحساب يملكه شريكٌ نيابةً عن التاجر — قد يدفع عنه الشريك. يُربط حسابٌ يملكه نشاط التاجر نفسه.'),
            self::AMBIGUOUS_ACCOUNT => __('لا يُعرف بأمان على أيّ حساب رسائل تُحتسب رسائل هذا الرقم — رمزك يصل على الرقم أكثر من حساب، ولا يُسمّى الحساب في الرسالة على إصدار الواجهة الحاليّ. لا تخرج رسالة حتى يُحسم.'),
            self::ACCOUNT_UNBOUND => __('رمزك لم يعد يُرسل من حساب الرسائل هذا، أو الرقم لم يعد فيه — راجع صلاحيّات «مستخدم النظام» في حسابك ثمّ أعد الربط.'),
            self::ACCOUNT_TAKEN => __('حساب الرسائل هذا مربوطٌ لمتجرٍ آخر عندنا — لكلّ متجرٍ حسابُ رسائله ووسيلةُ دفعه. اربط حساب الرسائل الذي يملكه نشاطك.'),
            self::FUNDING_CHANGED => __('تبدّلت وسيلة الدفع على هذا الحساب بعد مراجعة دليله — يُراجَع دليلٌ جديد قبل أن يعود الإرسال.'),
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

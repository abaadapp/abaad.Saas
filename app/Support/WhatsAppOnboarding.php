<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Log;

/**
 * من كودٍ عمرُه ثلاثون ثانية إلى وصلةٍ تُرسل — بترتيبٍ لا يُقفز فيه خطوة.
 *
 * ═══ ولمَ «تمّ» في الشاشة ليست «تمّ» هنا ═══
 *
 * ميتا تُطلق حدثَ `FINISH` في المتصفّح حين يُغلق التاجرُ نافذتَها راضيًا.
 * وذلك يقول إنّه أتمّ الخطوات، لا إنّ لدينا رمزًا يعمل ولا إنّ الرقم الذي
 * اختاره يتبع حسابًا مُنحناه. فالنجاحُ يُقرَّر هنا وحدَه، بعد:
 *
 *   ١ · تبديلِ الكود برمز.
 *   ٢ · فحصِ الرمز: أصالحٌ هو؟ وأيّ صلاحياتٍ يحمل؟ ولأيّ حساباتٍ؟ ومتى ينتهي؟
 *   ٣ · تثبيتِ حساب الأعمال من **الرمز** لا من المتصفّح.
 *   ٤ · قراءةِ أرقام الحساب من ميتا، والتحقّقِ أنّ الرقم المختار منها.
 *   ٥ · الاشتراكِ في الإشعارات إن لم يكن مشتركًا — ثمّ قراءةِ `subscribed_apps`
 *       والتحقّقِ أنّ تطبيقَ أبعاد بعينه فيها (`WhatsAppLinkCheck::subscribe`).
 *
 * والرمزُ يُفحص بالباب نفسه الذي يمرّ به الربطُ اليدويّ (`WhatsAppLinkCheck`):
 * مُصدرُه تطبيقُ أبعاد، وصلاحياتُه، وحسابُه. وما يُكتب في القاعدة يُكتب
 * بعد ذلك كلِّه — فربطٌ يفشل لا يمسّ وصلةً قائمة.
 *
 * وما دون ذلك يُردّ برسالةٍ عربيّةٍ تقول ما العمل، ولا يُكتب «متّصل».
 *
 * ═══ وما لا يُفعل هنا أبدًا ═══
 *
 * لا `register` للرقم. وثيقةُ ميتا لمسار تطبيق واتساب للأعمال تقول: تخطَّ
 * خطوةَ تسجيل الرقم. وتسجيلُه هو بعينه ما ينزع الرقمَ من التطبيق على
 * الهاتف — أي أن نكسر على التاجر واتسابَه اليوميّ لنربطه بنا.
 *
 * ولا حذفَ لرقمٍ ولا فكَّ ارتباطٍ عند ميتا في أيّ مسار. الفصلُ عندنا فصلٌ
 * محلّيّ: يبقى الرقمُ عند ميتا كما هو.
 */
class WhatsAppOnboarding
{
    /** رقمُ Cloud API مستقلّ — المسارُ الافتراضيّ */
    public const CLOUD_API = 'cloud_api';

    /** رقمٌ يبقى على تطبيق واتساب للأعمال في الهاتف — باختيارٍ صريح وحده */
    public const COEXISTENCE = 'coexistence';

    public const TYPES = [self::CLOUD_API, self::COEXISTENCE];

    /**
     * أمفتوحٌ مسارُ التعايش على هذا الخادم؟
     *
     * مطفأٌ ما لم يُشعَل: التعايشُ عند ميتا يحتاج حقولَ إشعاراتٍ في التطبيق
     * (`smb_message_echoes` و`history` و`smb_app_state_sync` و`account_update`)
     * ومزامنةً خلال أربعٍ وعشرين ساعة لا تُعاد — ولا شيءَ منها مبنيٌّ عندنا
     * بعد. وربطُ رقمٍ بالتعايش بلاها يُضيّع تاريخَ محادثاته مرّةً واحدةً
     * وإلى الأبد.
     */
    public static function coexistenceOpen(): bool
    {
        return (bool) config('whatsapp.coexistence_enabled', false);
    }

    /**
     * يربط رقمَ هذا المتجر بما أعادته ميتا.
     *
     * ومعرّفُ المتجر يصل من المتحكّم الذي قرأه من الجلسة — لا من الطلب.
     * وما يصل من المتصفّح (`$wabaId`, `$phoneNumberId`, `$type`) اقتراحٌ
     * يُفحص، لا أمرٌ يُنفَّذ.
     *
     * @return array{ok:bool, connection:?WhatsAppConnection, field:?string, message:?string, pending:bool, webhook:?string}
     */
    public static function connect(
        Business $business,
        User $actor,
        string $code,
        ?string $wabaId = null,
        ?string $phoneNumberId = null,
        string $type = self::CLOUD_API,
    ): array {
        $exchange = WhatsAppEmbeddedSignup::exchange($code);

        if (! $exchange['ok']) {
            return self::fail($business, 'code', __('تعذّر إكمال الربط مع ميتا — أعد المحاولة.'), $exchange);
        }

        $token = (string) $exchange['token'];

        /*
         * ═══ الرمز: من تطبيق أبعاد، بصلاحياته، ولحسابٍ مُنحه ═══
         *
         * والكودُ بُدّل بسرّ تطبيقنا فرمزُه منّا — لكنّ الفحص لا يُفترض:
         * `debug_token` يقولها. والبابُ نفسُه يحرس الربطَ اليدويّ
         * (`WhatsAppLinkCheck::token`)، فلا يتباعد الطريقان.
         */
        $check = WhatsAppLinkCheck::token($token, $wabaId);

        if (! $check['ok']) {
            $field = $check['problem'] === WhatsAppLinkCheck::WABA_NOT_GRANTED ? 'waba_id' : 'code';
            $message = $check['problem'] === WhatsAppLinkCheck::UNVERIFIABLE
                ? __('تعذّر التحقّق من التفويض — أعد المحاولة.')
                : (string) $check['message'];

            return self::fail($business, $field, $message, $check['meta'] + ['problem' => $check['problem']]);
        }

        $waba = (string) $check['waba'];
        $existing = WhatsAppConnections::forBusiness($business->id);

        $numbers = WhatsAppEmbeddedSignup::phoneNumbers($waba, $token);

        if (! $numbers['ok']) {
            return self::fail($business, 'waba_id', __('تعذّرت قراءة أرقام حسابك من ميتا — أعد المحاولة.'), $numbers);
        }

        $chosen = self::pickNumber($numbers['numbers'], $phoneNumberId);

        /*
         * ورقمٌ **سُمّي** ولم يُوجد في الحساب تناقضٌ يُردّ — لا انتظار.
         *
         * الفرقُ بينه وبين «لم يظهر رقمٌ بعد» أنّ ذاك لم يُسمَّ فيه شيء:
         * التفويضُ تمّ والقائمةُ فارغة. وهذا سُمّي فيه رقمٌ ليس في القائمة —
         * وانتظارُه انتظارُ ما لا يأتي.
         */
        if ($chosen === null && filled($phoneNumberId)) {
            return self::fail($business, 'phone_number_id', WhatsAppLinkCheck::say(WhatsAppLinkCheck::NUMBER_NOT_IN_WABA), [
                'code' => 'number_mismatch',
                'message' => 'sent='.(string) $phoneNumberId,
            ]);
        }

        /*
         * ولا رقمَ بعد؟ — تُحفظ الوصلةُ «بانتظار ميتا» ولا يُقال «فشل».
         *
         * في مسار تطبيق واتساب للأعمال تُعيد ميتا حسابَ الأعمال أوّلًا، وقد
         * لا يظهر الرقمُ في قائمته إلّا بعد دقائق. والتفويضُ في يدنا صحيحٌ
         * كاملٌ — فضياعُه لأنّ قائمةً تأخّرت خسارةٌ بلا سبب.
         *
         * إلّا أن يكون للمتجر رقمٌ يُرسل اليوم: «بانتظار ميتا» فوقه يُطفئ
         * رسائلَه ليصير لا شيء. فيبقى ما كان، ويُقال للتاجر لماذا.
         */
        if ($chosen === null) {
            if ($existing?->isUsable()) {
                return self::fail($business, 'phone_number_id', __('تمّ التفويض، ولم يُظهر حسابك رقمًا بعد — بقي ربطك الحاليّ كما هو. أعد الربط بعد دقائق.'), [
                    'code' => 'no_number_yet', 'message' => 'kept existing connection',
                ]);
            }

            $hook = WhatsAppLinkCheck::subscribe($waba, $token);

            $connection = self::save($business, $actor, [
                'waba_id' => $waba,
                'meta_business_id' => WhatsAppEmbeddedSignup::ownerBusinessId($waba, $token),
                'access_token' => $token,
                'token_expires_at' => $check['expires_at'] ?? $exchange['expires_at'],
                'status' => WhatsAppConnection::PENDING,
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ] + WhatsAppLinkCheck::stamp($check['app_id'], $hook['state']));

            return ['ok' => true, 'connection' => $connection, 'field' => null, 'pending' => true, 'webhook' => $hook['state'],
                'message' => __('تمّ التفويض — وحسابك لم يُظهر رقمًا بعد. نتابع، وستكتمل خلال دقائق.')];
        }

        $numberId = (string) $chosen['id'];

        // ورقمٌ يملكه متجرٌ آخر لا يُنتزع منه — الحارسُ نفسُه في الربط اليدويّ
        if (WhatsAppLinkCheck::taken($numberId, $existing?->id)) {
            return self::fail($business, 'phone_number_id', WhatsAppLinkCheck::say(WhatsAppLinkCheck::NUMBER_TAKEN), [
                'code' => 'number_taken', 'message' => 'phone_number_id already bound',
            ]);
        }

        /*
         * ═══ والتعايشُ باختيارٍ صريحٍ وحده ═══
         *
         * ميتا منذ v4 تفتح مسارَ تطبيق الأعمال من تلقاء نفسها حين يُدخَل رقمٌ
         * عليه. فلا يُقرأ من الاختيار وحده: يُسأل الرقمُ نفسُه. ورقمٌ على
         * التطبيق والتاجرُ اختار «رقمًا مستقلًّا» — أو المسارُ مطفأٌ عندنا —
         * لا يُربط ولا يُعلن.
         */
        $onApp = WhatsAppLinkCheck::onBusinessApp($chosen, $token);

        if ($onApp && ($type !== self::COEXISTENCE || ! self::coexistenceOpen())) {
            return self::fail($business, 'code', WhatsAppLinkCheck::say(WhatsAppLinkCheck::COEXISTENCE_NOT_CHOSEN), [
                'code' => 'coexistence_not_chosen', 'message' => 'type='.$type,
            ]);
        }

        $hook = WhatsAppLinkCheck::subscribe($waba, $token);

        /*
         * ولا تُستبدل وصلةٌ تستقبل بوصلةٍ لا تستقبل.
         *
         * متجرٌ يصله اليوم ما يكتبه زبائنه، وأعاد الربط فلم يثبت اشتراكُ
         * أبعاد على الحساب الجديد: لو حُفظ لَصار رقمُه يُرسل ولا يستقبل.
         */
        if ($hook['state'] !== WhatsAppLinkCheck::SUBSCRIBED
            && in_array(WhatsAppLinkHealth::state($existing), [WhatsAppLinkHealth::READY, WhatsAppLinkHealth::RECEIVING], true)) {
            return self::fail($business, 'code', __('لم يثبت اشتراك أبعاد في إشعارات الحساب الجديد — بقي ربطك الحاليّ كما هو.'), $hook['meta']);
        }

        $connection = self::save($business, $actor, [
            'waba_id' => $waba,
            'meta_business_id' => WhatsAppEmbeddedSignup::ownerBusinessId($waba, $token),
            'phone_number_id' => $numberId,
            'display_phone_number' => $chosen['display_phone_number'] ?? null,
            'coexistence' => $onApp,
            'access_token' => $token,
            'token_expires_at' => $check['expires_at'] ?? $exchange['expires_at'],
            'status' => WhatsAppConnection::ACTIVE,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ] + WhatsAppLinkCheck::stamp($check['app_id'], $hook['state'])
          + ($existing && $existing->phone_number_id !== $numberId ? ['last_webhook_at' => null] : []));

        if ($hook['state'] !== WhatsAppLinkCheck::SUBSCRIBED) {
            self::log('subscribe_unproven', $business->id, $hook['meta'] + ['code' => $hook['state']]);
        }

        // قوالب المحلّ تُهيَّأ بأسمائها الافتراضية — كما في الربط اليدويّ
        WhatsAppTemplates::seedBusinessDefaults($business->id);

        Activity::log('settings', 'واتساب — ربط رقم المتجر بالتسجيل المدمج ('
            .($chosen['display_phone_number'] ?? $numberId).')');

        return ['ok' => true, 'connection' => $connection, 'field' => null, 'pending' => false, 'webhook' => $hook['state'],
            'message' => self::linkedMessage($hook['state'])];
    }

    /** ما يُقال بعد ربطٍ قُبل — والاستقبالُ لا يُدّعى قبل أن يثبت */
    public static function linkedMessage(string $webhook): string
    {
        return $webhook === WhatsAppLinkCheck::SUBSCRIBED
            ? __('تمّ ربط رقمك بنجاح، وتطبيق أبعاد مشترك في إشعاراته. يثبت استقبال الرسائل بأوّل رسالةٍ تصل.')
            : __('رُبط رقمك للإرسال، لكنّ اشتراك أبعاد في إشعاراته لم يثبت — رسائل زبائنك لن تصل بعد. اضغط «فحص الربط» أو أعد الربط.');
    }

    /**
     * الرقمُ المقصود من قائمة ميتا — وما لا يُطابق لا يُخمَّن.
     *
     * @param  list<array<string, mixed>>  $numbers
     * @return array<string, mixed>|null
     */
    public static function pickNumber(array $numbers, ?string $wanted): ?array
    {
        if ($numbers === []) {
            return null;
        }

        if (filled($wanted)) {
            foreach ($numbers as $number) {
                if ((string) ($number['id'] ?? '') === (string) $wanted) {
                    return $number;
                }
            }

            /*
             * ورقمٌ أرسله المتصفّح وليس في قائمة الحساب **يُردّ** ولا يُستبدل
             * بغيره. فلو أُخذ «أوّلُ رقمٍ في القائمة» بدلًا منه لَرُبط التاجر
             * برقمٍ لم يختره — وقرأه زبائنُه وردّوا عليه.
             */
            return null;
        }

        return count($numbers) === 1 ? $numbers[0] : null;
    }

    /**
     * كتابةُ الوصلة — تحديثًا لصفّ المتجر أو إنشاءً له.
     *
     * والرمزُ يمرّ بـ`encrypted` cast قبل أن يمسّ القاعدة (انظر
     * `WhatsAppConnection::$casts`)، ولا يُكتب في سجلٍّ ولا يعود إلى شاشة.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function save(Business $business, User $actor, array $attributes): WhatsAppConnection
    {
        $existing = WhatsAppConnections::forBusiness($business->id);

        $attributes = array_merge([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $business->id,
            'provider' => 'meta_cloud',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'connected_at' => now(),
            'connected_by_user_id' => $actor->id,
            'disconnected_at' => null,
        ], $attributes);

        return $existing ? tap($existing)->update($attributes) : WhatsAppConnection::create($attributes);
    }

    /**
     * فشلٌ يُقال للتاجر مختصرًا، ويُقيَّد للمشرف كاملًا.
     *
     * ═══ والرسالتان مختلفتان عمدًا ═══
     *
     * التاجر يقرأ «أعد المحاولة» — ورمزُ خطأ ميتا ونصُّه لا يعنيانه ولا
     * يُصلح بهما شيئًا. والسجلُّ يقرأ الرمزَ والنصَّ لأنّ من يُصلح يحتاجهما.
     * ولا شيء من الاعتماد في أيٍّ منهما.
     *
     * @param  array{code:?string, message:?string}  $meta
     * @return array{ok:bool, connection:null, field:string, message:string, pending:bool}
     */
    private static function fail(Business $business, string $field, string $message, array $meta): array
    {
        self::log('onboarding_failed', $business->id, $meta);

        $connection = WhatsAppConnections::forBusiness($business->id);

        if ($connection) {
            $connection->forceFill([
                'last_error_code' => (string) ($meta['code'] ?? 'unknown'),
                'last_error_message' => mb_substr((string) ($meta['message'] ?? ''), 0, 500),
                'last_error_at' => now(),
            ])->save();
        }

        return ['ok' => false, 'connection' => null, 'field' => $field, 'message' => $message, 'pending' => false];
    }

    /**
     * @param  array{code:?string, message:?string}  $meta
     */
    private static function log(string $event, ?int $businessId, array $meta): void
    {
        Log::warning('whatsapp.'.$event, [
            'business_id' => $businessId,
            'meta_code' => $meta['code'] ?? null,
            /* والنصُّ يُقصّ: رسائلُ ميتا تحمل أحيانًا عناوينَ طلبٍ طويلة */
            'meta_message' => mb_substr((string) ($meta['message'] ?? ''), 0, 300),
        ]);
    }
}

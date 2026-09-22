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
 *   ٥ · الاشتراكِ في الإشعارات إن لم يكن مشتركًا.
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
    /**
     * يربط رقمَ هذا المتجر بما أعادته ميتا.
     *
     * ومعرّفُ المتجر يصل من المتحكّم الذي قرأه من الجلسة — لا من الطلب.
     * وما يصل من المتصفّح (`$wabaId`, `$phoneNumberId`) اقتراحٌ يُفحص، لا
     * أمرٌ يُنفَّذ.
     *
     * @return array{ok:bool, connection:?WhatsAppConnection, field:?string, message:?string, pending:bool}
     */
    public static function connect(
        Business $business,
        User $actor,
        string $code,
        ?string $wabaId = null,
        ?string $phoneNumberId = null,
    ): array {
        $exchange = WhatsAppEmbeddedSignup::exchange($code);

        if (! $exchange['ok']) {
            return self::fail($business, 'code', __('تعذّر إكمال الربط مع ميتا — أعد المحاولة.'), $exchange);
        }

        $token = (string) $exchange['token'];

        $inspect = WhatsAppEmbeddedSignup::inspect($token);

        if (! $inspect['ok']) {
            return self::fail($business, 'code', __('تعذّر التحقّق من التفويض — أعد المحاولة.'), $inspect);
        }

        $missing = array_diff(WhatsAppEmbeddedSignup::REQUIRED_SCOPES, $inspect['scopes']);

        if ($missing !== []) {
            return self::fail($business, 'code', __('التفويض ناقص — أعد الربط واقبل كلّ الأذونات المطلوبة.'), [
                'code' => 'missing_scopes',
                'message' => 'missing: '.implode(',', $missing),
            ]);
        }

        /*
         * ═══ حسابُ الأعمال: ما مُنحناه، لا ما قيل لنا ═══
         *
         * `granular_scopes` تُسمّي الحسابات التي يملك هذا الرمزُ إدارتَها.
         * فإن أرسل المتصفّح معرّفًا ليس فيها — خطأً أو عبثًا — رُدّ. وإن لم
         * يرسل شيئًا وكان المُنح حسابًا واحدًا أُخذ. وإن كانت عدّةً ولم
         * يُسمَّ واحدٌ منها لم يُخمَّن: الخطأُ هنا أن نربط حسابَ تاجرٍ بآخر.
         */
        $granted = $inspect['waba_ids'];

        /*
         * ولا يُستبدل ما أرسله المتصفّح بغيره.
         *
         * أوّلُ كتابةٍ لهذا السطر كانت تُسقط المرسَل وتأخذ «الوحيدَ الممنوح»
         * حين لا يُطابق. وذاك يبتلع تناقضًا: المتصفّح رأى حسابًا والرمزُ مُنح
         * لآخر. ولو وقع ذلك حقًّا — بخطأٍ عند ميتا أو بعبثٍ في الحمولة —
         * لَرُبط التاجر بحسابٍ لم يره قطّ، ولَخرجت رسائلُه منه.
         *
         * فما أُرسل يُطابَق أو يُردّ. وما لم يُرسل شيءٌ يُؤخذ الوحيدُ الممنوح.
         */
        $waba = filled($wabaId)
            ? (in_array((string) $wabaId, $granted, true) ? (string) $wabaId : null)
            : (count($granted) === 1 ? $granted[0] : null);

        if ($waba === null) {
            return self::fail($business, 'waba_id', __('لم نتعرّف على حساب واتساب للأعمال الذي مُنحنا إدارته — أعد الربط.'), [
                'code' => 'waba_mismatch',
                'message' => 'sent='.(string) $wabaId.' granted='.implode(',', $granted),
            ]);
        }

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
            return self::fail($business, 'phone_number_id', __('الرقم الذي اخترته ليس ضمن حسابك عند ميتا — أعد الربط.'), [
                'code' => 'number_mismatch',
                'message' => 'sent='.(string) $phoneNumberId,
            ]);
        }

        /*
         * ولا رقمَ بعد؟ — تُحفظ الوصلةُ «بانتظار ميتا» ولا يُقال «فشل».
         *
         * في مسار تطبيق واتساب للأعمال تُعيد ميتا حسابَ الأعمال أوّلًا، وقد
         * لا يظهر الرقمُ في قائمته إلّا بعد دقائق. والتفويضُ في يدنا صحيحٌ
         * كاملٌ — فضياعُه لأنّ قائمةً تأخّرت خسارةٌ بلا سبب، ويعود التاجر
         * يربط من أوّله.
         */
        if ($chosen === null) {
            $connection = self::save($business, $actor, [
                'waba_id' => $waba,
                'meta_business_id' => WhatsAppEmbeddedSignup::ownerBusinessId($waba, $token),
                'access_token' => $token,
                'token_expires_at' => $inspect['expires_at'] ?? $exchange['expires_at'],
                'status' => WhatsAppConnection::PENDING,
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ]);

            self::subscribeOnce($waba, $token, $connection);

            return ['ok' => true, 'connection' => $connection, 'field' => null, 'pending' => true,
                'message' => __('تمّ التفويض — وحسابك لم يُظهر رقمًا بعد. نتابع، وستكتمل خلال دقائق.')];
        }

        $numberId = (string) $chosen['id'];

        /*
         * ورقمٌ يملكه متجرٌ آخر لا يُنتزع منه.
         *
         * الفهرسُ الفريد يرفض على كلّ حال، لكنّه يرفض بخطأِ قاعدةِ بيانات.
         * وهذا يقول للتاجر ما جرى بلغةٍ يفهمها — وهو الحارسُ نفسُه الذي
         * يحرس الربط اليدويّ.
         */
        $existing = WhatsAppConnections::forBusiness($business->id);

        $clash = WhatsAppConnection::where('phone_number_id', $numberId)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();

        if ($clash) {
            return self::fail($business, 'phone_number_id', __('هذا الرقم مربوطٌ بحسابٍ آخر.'), [
                'code' => 'number_taken', 'message' => 'phone_number_id already bound',
            ]);
        }

        $connection = self::save($business, $actor, [
            'waba_id' => $waba,
            'meta_business_id' => WhatsAppEmbeddedSignup::ownerBusinessId($waba, $token),
            'phone_number_id' => $numberId,
            'display_phone_number' => $chosen['display_phone_number'] ?? null,
            'coexistence' => ($chosen['platform_type'] ?? null) === WhatsAppEmbeddedSignup::PLATFORM_SMB_APP,
            'access_token' => $token,
            'token_expires_at' => $inspect['expires_at'] ?? $exchange['expires_at'],
            'status' => WhatsAppConnection::ACTIVE,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ]);

        self::subscribeOnce($waba, $token, $connection);

        // قوالب المحلّ تُهيَّأ بأسمائها الافتراضية — كما في الربط اليدويّ
        WhatsAppTemplates::seedBusinessDefaults($business->id);

        Activity::log('settings', 'واتساب — ربط رقم المتجر بالتسجيل المدمج ('
            .($chosen['display_phone_number'] ?? $numberId).')');

        return ['ok' => true, 'connection' => $connection, 'field' => null, 'pending' => false,
            'message' => __('تمّ ربط رقمك بنجاح.')];
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
     * الاشتراك في إشعارات الحساب — مرّةً، ولا يُكسر قائمٌ.
     *
     * وفشلُه لا يُفشل الربط: التاجرُ يستطيع أن يُرسل بلا إشعارات، وما ينقصه
     * حالاتُ التسليم. فيُقيَّد السببُ على الوصلة ويُعرض، ولا تُهدم وصلةٌ
     * صحيحةٌ من أجله.
     */
    private static function subscribeOnce(string $waba, string $token, WhatsAppConnection $connection): void
    {
        if (WhatsAppEmbeddedSignup::subscribed($waba, $token) === true) {
            return;
        }

        $result = WhatsAppEmbeddedSignup::subscribe($waba, $token);

        if (! $result['ok']) {
            $connection->forceFill([
                'last_error_code' => (string) $result['code'],
                'last_error_message' => mb_substr((string) $result['message'], 0, 500),
                'last_error_at' => now(),
            ])->save();

            self::log('subscribe_failed', $connection->business_id, $result);
        }
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

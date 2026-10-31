<?php

namespace App\Support;

use App\Models\WhatsAppConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * هل هذا الربطُ لتطبيق أبعاد حقًّا؟ — بابٌ واحدٌ تمرّ به كلُّ طرق الربط.
 *
 * ═══ ولمَ وُجد ═══
 *
 * في 2026-10-28 أُشرك تطبيقٌ في إشعارات حساب متجرٍ، وقالت ميتا `success`.
 * لكنّ التطبيق المشترك كان تطبيقَ التاجر نفسه لا «Abaad Messaging»: ميتا
 * تُشرك **التطبيقَ الذي أصدر الرمز**، ورمزُ المتجر كان قد لُصق يدويًّا من
 * تطبيقه هو. فالرقمُ يُرسل، والشاشةُ تقول
 * «متّصل»، ولا يصلنا من زبائنه حرف.
 *
 * فالقاعدةُ هنا: لا يُقبل رمزٌ ولا يُكتب «متّصل» ولا يُنادى `POST
 * subscribed_apps` حتى يثبت من ميتا نفسِها:
 *
 *   ١ · أنّ الرمز صالحٌ، وأنّ `debug_token` يقول إنّ مُصدرَه `whatsapp.app_id`.
 *   ٢ · أنّه يحمل `whatsapp_business_management` و`whatsapp_business_messaging`.
 *   ٣ · أنّ حساب واتساب للأعمال (WABA) ممّا مُنحه — بعينه لا بالتخمين.
 *   ٤ · أنّ الرقمَ من أرقام ذلك الحساب، وليس مربوطًا بوصلةٍ أخرى عندنا.
 *   ٥ · أنّ نوعَ الرقم معروف: مستقلٌّ أكّدته ميتا، أو على تطبيق الأعمال
 *       والتعايشُ مختارٌ ومفتوح. ونوعٌ لم يُعرف لا يُربط (`numberType`).
 *
 * ثمّ الاشتراك: `200` لا تكفي — يُقرأ `subscribed_apps` بعده ويُطلب فيه
 * تطبيقُنا بعينه، بلا عنوانٍ بديلٍ (override) يأخذ إشعاراتِه إلى غيرنا.
 * و`POST` لا يُنادى إلّا بعد قراءةٍ ناجحةٍ تقول إنّ تطبيقَنا غائب: قراءةٌ
 * فشلت (`UNKNOWN`) توقف الربطَ كلَّه، ولا يُحفظ عليها شيء (`subscriptionRefusal`).
 *
 * ═══ وما لا يُفعل هنا ═══
 *
 * لا رمزٌ ولا سرٌّ في رسالةٍ أو سجلّ. ولا `DELETE` عند ميتا أبدًا، ولا
 * `POST` فوق عنوانٍ بديل (الاشتراكُ بلا جسمٍ يمحو البديلَ — أي يغيّر إعدادًا
 * لغيرنا). و`recheck` قراءةٌ عند ميتا لا غير.
 */
final class WhatsAppLinkCheck
{
    /* ═══ علّةُ الرمز أو الحساب أو الرقم ═══ */

    /** الرمزُ من تطبيقٍ غير تطبيق أبعاد — أو أبت ميتا أن تصفه لتطبيقنا */
    public const WRONG_APP = 'wrong_app';

    /** منتهٍ أو مسحوبٌ أو لا تعرفه ميتا */
    public const INVALID_TOKEN = 'invalid_token';

    /** لم نصل ميتا — لا حكمَ على الرمز */
    public const UNVERIFIABLE = 'unverifiable';

    public const MISSING_SCOPES = 'missing_scopes';

    public const WABA_NOT_GRANTED = 'waba_not_granted';

    public const NUMBER_NOT_IN_WABA = 'number_not_in_waba';

    public const NUMBER_TAKEN = 'number_taken';

    /** رقمٌ على تطبيق الأعمال في الهاتف — والتاجرُ لم يختر التعايش صراحةً */
    public const COEXISTENCE_NOT_CHOSEN = 'coexistence_not_chosen';

    /** لم تقل ميتا أمستقلٌّ الرقمُ أم على تطبيق الأعمال — فلا يُربط */
    public const NUMBER_TYPE_UNKNOWN = 'number_type_unknown';

    /** لم تُقرأ `subscribed_apps` — قبل النداء أو بعده — فلا يُحفظ شيء */
    public const SUBSCRIPTION_UNVERIFIABLE = 'subscription_unverifiable';

    /** لم يثبت اشتراكُنا، وللمتجر ربطٌ يُرسل اليوم — فيبقى كما هو */
    public const SUBSCRIPTION_UNPROVEN = 'subscription_unproven';

    /** ما يقع على الرمز نفسه — `INCOMPLETE` ما يقع على تفويضه */
    public const TOKEN_PROBLEMS = [self::WRONG_APP, self::INVALID_TOKEN];

    public const INCOMPLETE = [self::MISSING_SCOPES, self::WABA_NOT_GRANTED, self::NUMBER_NOT_IN_WABA];

    /* ═══ حالُ الاشتراك في الإشعارات ═══ */

    /** تطبيقُ أبعاد في `subscribed_apps`، بلا عنوانٍ بديل */
    public const SUBSCRIBED = 'subscribed';

    /** ليس فيه — ولو قالت ميتا `success` */
    public const NOT_SUBSCRIBED = 'not_subscribed';

    /** فيه، لكن بعنوانٍ بديلٍ يأخذ الإشعاراتِ إلى غير عنواننا */
    public const OVERRIDE = 'override';

    /** لم نستطع القراءة */
    public const UNKNOWN = 'unknown';

    /* ═══ إعدادُ الإشعارات في تطبيق أبعاد نفسه — واحدٌ لكلّ المتاجر ═══ */

    /** العنوانُ عنوانُنا، و`messages` مُشعَل */
    public const APP_LISTENING = 'listening';

    /** العنوانُ في التطبيق ليس `/webhooks/whatsapp` عندنا */
    public const APP_CALLBACK_ELSEWHERE = 'callback_elsewhere';

    /** حقلُ `messages` غيرُ مُشعَلٍ في التطبيق — لا يصل من متجرٍ حرف */
    public const APP_MESSAGES_OFF = 'messages_off';

    /* ═══ توجيهُ المحادثات عند ميتا (Conversation Routing) لرقمٍ بعينه ═══ */

    /** لا توجيه على الرقم: كلُّ مستجيبٍ متّصلٍ يصله كلُّ وارد */
    public const ROUTING_OFF = 'routing_off';

    /** أبعاد المستجيبُ الأساسيّ لنقطة «الخدمة» (`customer_service`) */
    public const ROUTING_OURS = 'service_primary';

    /** توجيهٌ مُفعَّلٌ وأبعاد ليست أساسيّةً للخدمة — الواردُ يذهب إلى غيرها */
    public const ROUTING_ELSEWHERE = 'not_primary';

    public static function appId(): string
    {
        return (string) config('whatsapp.app_id');
    }

    /**
     * فحصُ الرمز قبل قبوله — بلا كتابةٍ في قاعدةٍ ولا نداءٍ يغيّر عند ميتا.
     *
     * و`$wantedWaba` ما سمّاه المتصفّح أو الحقل: يُطابَق بما مُنحه الرمز أو
     * يُردّ. وإن لم يُسمَّ شيءٌ أُخذ الحسابُ الوحيدُ الممنوح، ولا يُخمَّن بين
     * اثنين.
     *
     * @return array{ok:bool, problem:?string, message:?string, app_id:?string, waba:?string, expires_at:?Carbon, meta:array{code:?string, message:?string}}
     */
    public static function token(string $token, ?string $wantedWaba): array
    {
        $inspect = WhatsAppEmbeddedSignup::inspect($token);
        $meta = ['code' => $inspect['code'], 'message' => $inspect['message']];

        if (! $inspect['ok']) {
            $problem = match (true) {
                $inspect['code'] === 'network_error' => self::UNVERIFIABLE,
                $inspect['code'] === 'invalid_token', $inspect['code'] === '190' => self::INVALID_TOKEN,
                /*
                 * ورمزٌ يأبى `debug_token` أن يصفه لتطبيقنا (`#100`) ليس رمزَ
                 * تطبيقنا: ميتا تصف لكلّ تطبيقٍ رموزَه. فيُردّ ردَّ «تطبيقٍ
                 * غير مطابق» لا «أعد المحاولة» — المحاولةُ لا تُصلحه.
                 */
                default => self::WRONG_APP,
            };

            return self::refuse($problem, $inspect['app_id'], $meta);
        }

        if ($inspect['app_id'] !== self::appId()) {
            return self::refuse(self::WRONG_APP, $inspect['app_id'], [
                'code' => 'wrong_app', 'message' => 'token app '.(string) $inspect['app_id'],
            ]);
        }

        $missing = array_diff(WhatsAppEmbeddedSignup::REQUIRED_SCOPES, $inspect['scopes']);

        if ($missing !== []) {
            return self::refuse(self::MISSING_SCOPES, $inspect['app_id'], [
                'code' => 'missing_scopes', 'message' => 'missing: '.implode(',', $missing),
            ]);
        }

        /*
         * ═══ الحساب: ما مُنحه الرمز، لا ما قيل لنا ═══
         *
         * `granular_scopes` تُسمّي الحسابات بأعيانها. وقائمةٌ فارغة تعني
         * «كلَّ ما يصل إليه صاحبُ الرمز» — فيُقبل المسمّى ويُختبر بقراءة
         * أرقامه في `number()`: رمزٌ لا يصله يُردّ هناك.
         */
        $granted = $inspect['waba_ids'];
        $waba = match (true) {
            filled($wantedWaba) => $granted === [] || in_array((string) $wantedWaba, $granted, true) ? (string) $wantedWaba : null,
            default => count($granted) === 1 ? $granted[0] : null,
        };

        // والإرسالُ ممنوحٌ للحساب نفسه — وإلّا رُبط رقمٌ لا يُرسل
        $messaging = $inspect['messaging_waba_ids'];

        if ($waba === null || ($messaging !== [] && ! in_array($waba, $messaging, true))) {
            return self::refuse(self::WABA_NOT_GRANTED, $inspect['app_id'], [
                'code' => 'waba_mismatch', 'message' => 'sent='.(string) $wantedWaba.' granted='.implode(',', $granted),
            ]);
        }

        return [
            'ok' => true, 'problem' => null, 'message' => null, 'app_id' => $inspect['app_id'],
            'waba' => $waba, 'expires_at' => $inspect['expires_at'], 'meta' => $meta,
        ];
    }

    /**
     * الرقمُ من أرقام الحساب، ولا يملكه غيرُ هذه الوصلة عندنا.
     *
     * @return array{ok:bool, problem:?string, message:?string, number:?array<string, mixed>, meta:array{code:?string, message:?string}}
     */
    public static function number(string $waba, string $token, string $phoneNumberId, ?int $exceptConnectionId): array
    {
        $numbers = WhatsAppEmbeddedSignup::phoneNumbers($waba, $token);
        $meta = ['code' => $numbers['code'], 'message' => $numbers['message']];

        $chosen = collect($numbers['numbers'])->first(fn ($n) => (string) ($n['id'] ?? '') === $phoneNumberId);

        if (! $numbers['ok'] || $chosen === null) {
            return ['ok' => false, 'problem' => self::NUMBER_NOT_IN_WABA, 'message' => self::say(self::NUMBER_NOT_IN_WABA),
                'number' => null, 'meta' => $numbers['ok'] ? ['code' => 'number_mismatch', 'message' => 'sent='.$phoneNumberId] : $meta];
        }

        if (self::taken($phoneNumberId, $exceptConnectionId)) {
            return ['ok' => false, 'problem' => self::NUMBER_TAKEN, 'message' => self::say(self::NUMBER_TAKEN),
                'number' => null, 'meta' => ['code' => 'number_taken', 'message' => 'phone_number_id already bound']];
        }

        return ['ok' => true, 'problem' => null, 'message' => null, 'number' => (array) $chosen, 'meta' => $meta];
    }

    public static function taken(string $phoneNumberId, ?int $exceptConnectionId): bool
    {
        return WhatsAppConnection::where('phone_number_id', $phoneNumberId)
            ->when($exceptConnectionId, fn ($q) => $q->where('id', '!=', $exceptConnectionId))
            ->exists();
    }

    /**
     * أعلى تطبيق الأعمال في الهاتف هذا الرقم؟ — من القائمة أو من الرقم نفسه.
     *
     * و`null` تبقى `null`: «لم نعرف» ليست «ليس عليه».
     *
     * @param  array<string, mixed>  $number  صفُّ الرقم من `phone_numbers`
     */
    public static function onBusinessApp(array $number, string $token): ?bool
    {
        if (($number['platform_type'] ?? null) === WhatsAppEmbeddedSignup::PLATFORM_SMB_APP) {
            return true;
        }

        $id = (string) ($number['id'] ?? '');

        return $id === '' ? null : WhatsAppEmbeddedSignup::onBusinessApp($id, $token);
    }

    /**
     * أيُربط هذا الرقمُ بنوعه؟ — قبل أيّ اشتراكٍ أو كتابة.
     *
     *   · `false` (أكّدت ميتا أنّه مستقلّ) ← يُربط Cloud API.
     *   · `true` (على تطبيق الأعمال) ← لا يُربط إلّا والتعايشُ مختارٌ ومفتوح.
     *   · `null` (لم نعرف) ← لا يُربط أبدًا: لو عُدّ مستقلًّا لَرُبط رقمٌ
     *     قد يكون على هاتف التاجر بمسارٍ لا يصلح له.
     *
     * @param  array<string, mixed>  $number  صفُّ الرقم من `phone_numbers`
     * @return array{problem:?string, on_app:?bool}
     */
    public static function numberType(array $number, string $token, bool $coexistence): array
    {
        $onApp = self::onBusinessApp($number, $token);

        return ['on_app' => $onApp, 'problem' => match (true) {
            $onApp === null => self::NUMBER_TYPE_UNKNOWN,
            $onApp && ! $coexistence => self::COEXISTENCE_NOT_CHOSEN,
            default => null,
        }];
    }

    /**
     * قراءةُ الاشتراك — `GET` وحده.
     *
     * @return array{state:string, apps:list<array{app_id:string, name:?string, override:?string}>, meta:array{code:?string, message:?string}}
     */
    public static function webhook(string $waba, string $token): array
    {
        $read = WhatsAppEmbeddedSignup::subscribedApps($waba, $token);
        $meta = ['code' => $read['code'], 'message' => $read['message']];

        if (! $read['ok']) {
            return ['state' => self::UNKNOWN, 'apps' => [], 'meta' => $meta];
        }

        $ours = collect($read['apps'])->firstWhere('app_id', self::appId());

        $state = match (true) {
            $ours === null => self::NOT_SUBSCRIBED,
            ! self::isOurCallback($ours['override']) => self::OVERRIDE,
            default => self::SUBSCRIBED,
        };

        return ['state' => $state, 'apps' => $read['apps'], 'meta' => $meta];
    }

    /**
     * الاشتراكُ ثمّ إثباتُه: `GET` ← `POST` إن لزم ← `GET`.
     *
     * يُنادى برمزٍ ثبت أنّه من تطبيقنا (`token()`) — فميتا تُشرك مُصدرَه، وهو
     * نحن. و`POST` لا يُنادى إلّا حين تقول قراءةٌ ناجحةٌ إنّ تطبيقَنا غائب
     * (`NOT_SUBSCRIBED`). فما سواها يُعاد كما قُرئ بلا نداء:
     *
     *   · `SUBSCRIBED` — مشتركٌ أصلًا، فلا يُكرَّر.
     *   · `OVERRIDE` — الاشتراكُ بلا جسمٍ يمحو العنوانَ البديل، وذاك إعدادٌ
     *     وُضع عمدًا يُسأل عنه صاحبُه قبل أن يُمسّ.
     *   · `UNKNOWN` — لم نقرأ، فلا نعرف أغائبٌ تطبيقُنا أم لا. ونداءٌ على
     *     تخمينٍ نداءٌ لا يُدرى ما يغيّر.
     *
     * @return array{state:string, posted:bool, meta:array{code:?string, message:?string}}
     */
    public static function subscribe(string $waba, string $token): array
    {
        $before = self::webhook($waba, $token);

        if ($before['state'] !== self::NOT_SUBSCRIBED) {
            return ['state' => $before['state'], 'posted' => false, 'meta' => $before['meta']];
        }

        $post = WhatsAppEmbeddedSignup::subscribe($waba, $token);

        if (! $post['ok']) {
            return ['state' => self::NOT_SUBSCRIBED, 'posted' => true,
                'meta' => ['code' => $post['code'], 'message' => $post['message']]];
        }

        /*
         * و`success: true` ليست الدليل.
         *
         * هي قالتها يومَها والمشتركُ تطبيقٌ آخر. الدليلُ أن يظهر تطبيقُنا
         * بعينه في القائمة بعد النداء.
         */
        $after = self::webhook($waba, $token);

        return ['state' => $after['state'], 'posted' => true, 'meta' => $after['meta']];
    }

    /**
     * أيُحفظ ربطٌ جديدٌ بهذا الاشتراك؟ — `null` نعم، وإلّا علّةُ الردّ.
     *
     *   · `UNKNOWN` لا يُحفظ عليه شيءٌ أبدًا: لم نقرأ، فلا نحكم.
     *   · وما لم يثبت (`NOT_SUBSCRIBED` · `OVERRIDE`) لا يحلّ محلَّ ربطٍ يُرسل
     *     اليوم: يبقى القديمُ برمزه ورقمه وحسابه.
     *   · ومتجرٌ لا ربطَ له يُرسل يُحفظ له «متّصلٌ لا يستقبل» ويُقال له ذلك —
     *     و«جاهز» لا تُقال إلّا لـ`SUBSCRIBED` (`WhatsAppLinkHealth`).
     *
     * @param  array{state:string}  $hook  ما أعاده `subscribe()`
     */
    public static function subscriptionRefusal(array $hook, ?WhatsAppConnection $existing): ?string
    {
        return match (true) {
            $hook['state'] === self::UNKNOWN => self::SUBSCRIPTION_UNVERIFIABLE,
            $hook['state'] !== self::SUBSCRIBED && (bool) $existing?->isUsable() => self::SUBSCRIPTION_UNPROVEN,
            default => null,
        };
    }

    /**
     * فحصُ وصلةٍ قائمة عند ميتا — قراءةٌ لا غير، ولا يُكتب حرفٌ في قاعدة.
     *
     * ما يُسأل: أيُّ تطبيقٍ أصدر الرمز، وصلاحياتُه وحسابُه، وأنّ الرقمَ من
     * أرقام الحساب، وحالُ `subscribed_apps` والعنوانِ البديل. وهو ما يقرؤه
     * أمرُ `whatsapp:own-numbers` على كلّ الوصلات بلا أن يغيّر شيئًا.
     *
     * @return array{skipped:bool, routing:?string, app_id:?string, ours:?bool, problem:?string, webhook:?string, verified:bool}
     */
    public static function inspect(WhatsAppConnection $connection): array
    {
        $token = (string) $connection->access_token;

        if ($token === '' || blank($connection->phone_number_id)) {
            return ['skipped' => true, 'routing' => null, 'app_id' => null, 'ours' => null, 'problem' => null, 'webhook' => null, 'verified' => false];
        }

        $check = self::token($token, $connection->waba_id);
        $problem = $check['ok'] ? null : $check['problem'];
        $webhook = null;
        $routing = null;

        if ($check['ok']) {
            $number = self::number((string) $check['waba'], $token, (string) $connection->phone_number_id, $connection->id);
            $problem = $number['ok'] ? null : $number['problem'];
            $webhook = self::webhook((string) $check['waba'], $token)['state'];
            $routing = $number['ok'] ? self::routing((string) $connection->phone_number_id, $token)['state'] : null;
        }

        return [
            'skipped' => false,
            'routing' => $routing,
            'app_id' => $check['app_id'],
            // و`#100` لا يُسمّي التطبيق — لكنّه يقول إنّه ليس تطبيقنا
            'ours' => $problem === self::WRONG_APP ? false : ($check['app_id'] === null ? null : $check['app_id'] === self::appId()),
            'problem' => $problem,
            'webhook' => $webhook,
            'verified' => $check['ok'] && $problem === null,
        ];
    }

    /**
     * أيستمع تطبيقُ أبعاد نفسُه؟ — عنوانُه وحقلُ `messages`. قراءةٌ لا غير.
     *
     * @return array{state:string, callback_ours:?bool, fields:list<string>, meta:array{code:?string, message:?string}}
     */
    public static function appWebhook(): array
    {
        $read = WhatsAppEmbeddedSignup::appSubscription();
        $meta = ['code' => $read['code'], 'message' => $read['message']];

        if (! $read['ok']) {
            return ['state' => self::UNKNOWN, 'callback_ours' => null, 'fields' => [], 'meta' => $meta];
        }

        $ours = filled($read['callback_url']) && self::isOurCallback($read['callback_url']);

        return [
            'state' => match (true) {
                ! $ours => self::APP_CALLBACK_ELSEWHERE,
                ! $read['active'] || ! in_array('messages', $read['fields'], true) => self::APP_MESSAGES_OFF,
                default => self::APP_LISTENING,
            },
            'callback_ours' => $ours,
            'fields' => $read['fields'],
            'meta' => $meta,
        ];
    }

    /**
     * أيمرّ واردُ هذا الرقم بأبعاد في توجيه المحادثات عند ميتا؟ — قراءةٌ لا غير.
     *
     * الاشتراكُ في الحساب لا يكفي حين يكون للرقم توجيه: ميتا تُسلّم رسالةَ
     * الزبون لمستجيب «الخدمة» الأساسيّ وحده. والإصلاحُ عند صاحب الحساب في
     * Meta Business Suite — لا واجهةَ برمجيّةَ لضبطه، ولا نضبطه نحن.
     *
     * @return array{state:string, roles:list<string>, meta:array{code:?string, message:?string}}
     */
    public static function routing(string $phoneNumberId, string $token): array
    {
        $read = WhatsAppEmbeddedSignup::routingRoles($phoneNumberId, $token);
        $meta = ['code' => $read['code'], 'message' => $read['message']];

        return ['roles' => $read['roles'], 'meta' => $meta, 'state' => match (true) {
            ! $read['ok'] => self::UNKNOWN,
            $read['enabled'] === false => self::ROUTING_OFF,
            in_array('customer_service', $read['roles'], true) => self::ROUTING_OURS,
            default => self::ROUTING_ELSEWHERE,
        }];
    }

    /**
     * فحصُ وصلةٍ قائمة — قراءةٌ عند ميتا، وكتابةٌ في أعمدة الفحص وحدها.
     *
     * لا يُمسّ الرمزُ ولا الرقمُ ولا `status`: وصلةٌ تُرسل اليوم برمزٍ من
     * تطبيقٍ آخر تبقى تُرسل — الفحصُ يقول الحقيقة ولا يقطع رسائلَ متجر.
     * وإصلاحُها إعادةُ ربطٍ يقرّرها صاحبُها.
     *
     * @return array{problem:?string, webhook:?string}
     */
    public static function recheck(WhatsAppConnection $connection): array
    {
        return self::record($connection, self::inspect($connection));
    }

    /**
     * ما قرأه `inspect()` يُكتب في أعمدة الفحص وحدها.
     *
     * @param  array{skipped:bool, routing:?string, app_id:?string, ours:?bool, problem:?string, webhook:?string, verified:bool}  $found
     * @return array{problem:?string, webhook:?string}
     */
    public static function record(WhatsAppConnection $connection, array $found): array
    {
        if ($found['skipped']) {
            return ['problem' => null, 'webhook' => null];
        }

        $problem = $found['problem'];
        $webhook = $found['webhook'];

        // علّةٌ لا يُحكم بها على رمزٍ لم نصل ميتا لنسأل عنه
        if ($problem === self::UNVERIFIABLE) {
            return ['problem' => $problem, 'webhook' => $connection->webhook_state];
        }

        // وقراءةُ اشتراكٍ فشلت لا تمحو ما قُرئ قبلها — كالرمز الذي لم نصل ميتا لنسأل عنه
        $read = $webhook !== null && $webhook !== self::UNKNOWN;

        $connection->forceFill([
            'token_app_id' => $found['app_id'],
            'token_verified_at' => $found['verified'] ? now() : null,
            'link_problem' => $problem,
            'webhook_state' => $webhook === self::UNKNOWN ? $connection->webhook_state : $webhook,
            'webhook_checked_at' => $read ? now() : $connection->webhook_checked_at,
            'routing_state' => in_array($found['routing'], [null, self::UNKNOWN], true) ? $connection->routing_state : $found['routing'],
        ])->save();

        self::log('link_rechecked', $connection->business_id, ['code' => $problem ?? $webhook, 'message' => null]);

        return ['problem' => $problem, 'webhook' => $webhook];
    }

    /**
     * أعمدةُ الفحص كما تُكتب مع وصلةٍ قُبلت.
     *
     * @return array<string, mixed>
     */
    public static function stamp(?string $appId, string $webhookState, ?string $routing = null): array
    {
        return [
            'token_app_id' => $appId,
            'token_verified_at' => now(),
            'link_problem' => null,
            'webhook_state' => $webhookState,
            'webhook_checked_at' => now(),
            // وتوجيهٌ لم يُقرأ لا يُحكم به — يُترك فارغًا حتى «فحص الربط»
            'routing_state' => $routing === self::UNKNOWN ? null : $routing,
        ];
    }

    /**
     * عنوانٌ بديلٌ هو عنوانُنا نفسُه لا يُعدّ بديلًا.
     */
    public static function isOurCallback(?string $override): bool
    {
        if (blank($override)) {
            return true;
        }

        $ours = (string) route('webhooks.whatsapp');

        return parse_url((string) $override, PHP_URL_HOST) === parse_url($ours, PHP_URL_HOST)
            && rtrim((string) parse_url((string) $override, PHP_URL_PATH), '/') === rtrim((string) parse_url($ours, PHP_URL_PATH), '/');
    }

    /** ما يقرؤه التاجر عن كلّ علّة — بلا رمزٍ ولا سرّ */
    public static function say(string $problem): string
    {
        return match ($problem) {
            self::WRONG_APP => __('هذا الرمز صادرٌ من تطبيق ميتا آخر لا من تطبيق أبعاد (Abaad Messaging) — فلا تصلنا به رسائل زبائنك ولا حالاتها. اربط رقمك من زرّ «ربط واتساب API» في أبعاد واختر حسابك ورقمك نفسيهما: يبقى الرقم والحساب ملكك، ولا يُطلب منك ترك تطبيقك.'),
            self::INVALID_TOKEN => __('الرمز منتهٍ أو مسحوب عند ميتا — أعد الربط.'),
            self::UNVERIFIABLE => __('تعذّر الوصول إلى ميتا للتحقّق من التفويض — أعد المحاولة بعد قليل.'),
            self::MISSING_SCOPES => __('التفويض ناقص — أعد الربط واقبل كلّ الأذونات المطلوبة.'),
            self::WABA_NOT_GRANTED => __('لم نتعرّف على حساب واتساب للأعمال الذي مُنحنا إدارته — أعد الربط.'),
            self::NUMBER_NOT_IN_WABA => __('الرقم الذي اخترته ليس ضمن حسابك عند ميتا — أعد الربط.'),
            self::NUMBER_TAKEN => __('هذا الرقم مربوطٌ بحسابٍ آخر.'),
            self::COEXISTENCE_NOT_CHOSEN => __('هذا الرقم يعمل على تطبيق واتساب للأعمال في الهاتف. لم يُربط: التعايش مع التطبيق لا يُستعمل إلّا باختيارٍ صريح.'),
            self::NUMBER_TYPE_UNKNOWN => __('تعذّر التأكّد من نوع هذا الرقم عند ميتا (رقم Cloud API مستقل أم على تطبيق واتساب للأعمال) — لم يُربط ولم يتغيّر شيء. أعد المحاولة بعد قليل.'),
            self::SUBSCRIPTION_UNVERIFIABLE => __('تعذّر التحقّق من اشتراك تطبيق أبعاد في إشعارات حسابك عند ميتا — لم يُحفظ الربط ولم يتغيّر شيء. أعد المحاولة بعد قليل.'),
            self::SUBSCRIPTION_UNPROVEN => __('لم يثبت اشتراك أبعاد في إشعارات هذا الحساب — بقي ربطك الحاليّ كما هو.'),
            default => __('تعذّر إكمال الربط مع ميتا — أعد المحاولة.'),
        };
    }

    /**
     * @param  array{code:?string, message:?string}  $meta
     * @return array{ok:bool, problem:string, message:string, app_id:?string, waba:null, expires_at:null, meta:array{code:?string, message:?string}}
     */
    private static function refuse(string $problem, ?string $appId, array $meta): array
    {
        return ['ok' => false, 'problem' => $problem, 'message' => self::say($problem), 'app_id' => $appId,
            'waba' => null, 'expires_at' => null, 'meta' => $meta];
    }

    /** @param  array{code:?string, message:?string}  $meta */
    public static function log(string $event, ?int $businessId, array $meta): void
    {
        Log::warning('whatsapp.'.$event, [
            'business_id' => $businessId,
            'meta_code' => $meta['code'] ?? null,
            'meta_message' => mb_substr((string) ($meta['message'] ?? ''), 0, 300),
        ]);
    }
}

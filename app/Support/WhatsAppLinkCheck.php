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
 *
 * ثمّ الاشتراك: `200` لا تكفي — يُقرأ `subscribed_apps` بعده ويُطلب فيه
 * تطبيقُنا بعينه، بلا عنوانٍ بديلٍ (override) يأخذ إشعاراتِه إلى غيرنا.
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
     * @param  array<string, mixed>  $number  صفُّ الرقم من `phone_numbers`
     */
    public static function onBusinessApp(array $number, string $token): bool
    {
        if (($number['platform_type'] ?? null) === WhatsAppEmbeddedSignup::PLATFORM_SMB_APP) {
            return true;
        }

        return WhatsAppEmbeddedSignup::onBusinessApp((string) ($number['id'] ?? ''), $token) === true;
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
     * نحن. ولا `POST` فوق عنوانٍ بديل: الاشتراكُ بلا جسمٍ يمحوه، وذاك إعدادٌ
     * وُضع عمدًا يُسأل عنه صاحبُه قبل أن يُمسّ.
     *
     * @return array{state:string, posted:bool, meta:array{code:?string, message:?string}}
     */
    public static function subscribe(string $waba, string $token): array
    {
        $before = self::webhook($waba, $token);

        if (in_array($before['state'], [self::SUBSCRIBED, self::OVERRIDE], true)) {
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

        return ['state' => $after['state'] === self::UNKNOWN ? self::UNKNOWN : $after['state'], 'posted' => true, 'meta' => $after['meta']];
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
        $token = (string) $connection->access_token;

        if ($token === '' || blank($connection->phone_number_id)) {
            return ['problem' => null, 'webhook' => null];
        }

        $check = self::token($token, $connection->waba_id);
        $problem = $check['ok'] ? null : $check['problem'];
        $webhook = null;

        // علّةٌ لا يُحكم بها على رمزٍ لم نصل ميتا لنسأل عنه
        if ($problem === self::UNVERIFIABLE) {
            return ['problem' => $problem, 'webhook' => $connection->webhook_state];
        }

        if ($check['ok']) {
            $number = self::number((string) $check['waba'], $token, (string) $connection->phone_number_id, $connection->id);
            $problem = $number['ok'] ? null : $number['problem'];
            $webhook = self::webhook((string) $check['waba'], $token)['state'];
        }

        $connection->forceFill([
            'token_app_id' => $check['app_id'],
            'token_verified_at' => $check['ok'] && $problem === null ? now() : null,
            'link_problem' => $problem,
            'webhook_state' => $webhook,
            'webhook_checked_at' => $webhook !== null ? now() : $connection->webhook_checked_at,
        ])->save();

        self::log('link_rechecked', $connection->business_id, ['code' => $problem ?? $webhook, 'message' => null]);

        return ['problem' => $problem, 'webhook' => $webhook];
    }

    /**
     * أعمدةُ الفحص كما تُكتب مع وصلةٍ قُبلت.
     *
     * @return array<string, mixed>
     */
    public static function stamp(?string $appId, string $webhookState): array
    {
        return [
            'token_app_id' => $appId,
            'token_verified_at' => now(),
            'link_problem' => null,
            'webhook_state' => $webhookState,
            'webhook_checked_at' => now(),
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
            self::WRONG_APP => __('هذا الرمز لم يصدر من تطبيق أبعاد (Abaad Messaging) — فلا تصلنا رسائل زبائنك به. اربط رقمك من زرّ «ربط واتساب API» في أبعاد، أو أنشئ الرمز من تطبيق أبعاد.'),
            self::INVALID_TOKEN => __('الرمز منتهٍ أو مسحوب عند ميتا — أعد الربط.'),
            self::UNVERIFIABLE => __('تعذّر الوصول إلى ميتا للتحقّق من التفويض — أعد المحاولة بعد قليل.'),
            self::MISSING_SCOPES => __('التفويض ناقص — أعد الربط واقبل كلّ الأذونات المطلوبة.'),
            self::WABA_NOT_GRANTED => __('لم نتعرّف على حساب واتساب للأعمال الذي مُنحنا إدارته — أعد الربط.'),
            self::NUMBER_NOT_IN_WABA => __('الرقم الذي اخترته ليس ضمن حسابك عند ميتا — أعد الربط.'),
            self::NUMBER_TAKEN => __('هذا الرقم مربوطٌ بحسابٍ آخر.'),
            self::COEXISTENCE_NOT_CHOSEN => __('هذا الرقم يعمل على تطبيق واتساب للأعمال في الهاتف. لم يُربط: التعايش مع التطبيق لا يُستعمل إلّا باختيارٍ صريح.'),
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

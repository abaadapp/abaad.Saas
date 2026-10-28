<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * التسجيل المدمج عند ميتا — نداءاتُ الربط وفحصِه، ولا نداءَ يغيّر عند ميتا
 * غيرُ الاشتراك في الإشعارات (`subscribe`).
 *
 * ═══ ولمَ ملفٌّ ثانٍ وعندنا `MetaWhatsAppClient` ═══
 *
 * ذاك يُرسل رسائلَ برمزِ وصلةٍ قائمة؛ وهذا يصنع الوصلةَ نفسَها بسرّ التطبيق.
 * ولو جُمعا لَصار الملفُّ الذي يُنادى في كلّ بيعةٍ يحمل سرَّ التطبيق في
 * مجاله — وهو ما لا حاجةَ به إليه قطّ.
 *
 * ═══ وما لا يخرج من هذا الملفّ ═══
 *
 * لا رمزٌ ولا سرٌّ في رسالةِ خطأ، ولا في سجلّ، ولا في قيمةٍ مردودةٍ إلى
 * شاشة. ما يعود منه: «تمّ» أو «لم يتمّ ورمزُ الخطأ كذا ونصُّه كذا» — كما
 * قالتهما ميتا. والرمزُ نفسُه يُردّ في مفتاحٍ واحدٍ يقرؤه المتحكّم ويُخزّنه
 * مشفَّرًا ولا يمرّ به على شاشة.
 *
 * ═══ والكودُ عمرُه ثلاثون ثانية ═══
 *
 * ميتا تقول ذلك صراحةً في وثيقة التنفيذ. فلا يُخزَّن الكودُ في طابور ولا
 * يُؤجَّل: يصل الخادمَ فيُبدَّل في النداء التالي مباشرةً.
 */
class WhatsAppEmbeddedSignup
{
    /**
     * الصلاحيات التي لا يقوم الربط بدونها.
     *
     * `whatsapp_business_management` لقراءة حساب الأعمال وأرقامه والاشتراك
     * في الإشعارات، و`whatsapp_business_messaging` للإرسال. ومن مُنح الأولى
     * وحدها يُربط ربطًا لا يُرسل — وهو أسوأ من ألّا يُربط: الشاشة تقول
     * «متّصل» والرسائل تقف.
     */
    public const REQUIRED_SCOPES = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    /**
     * نوعُ الرقم حين يكون باقيًا في تطبيق واتساب للأعمال.
     *
     * ميتا تُعيده في `platform_type` على الرقم. وهو ما نعني به «التعايش»:
     * الرقمُ في التطبيق على الهاتف وفي النظام معًا.
     */
    public const PLATFORM_SMB_APP = 'SMB_APP';

    /** أمكتملُ الإعداد؟ — بلا معرّفٍ أو سرٍّ أو إعدادِ تسجيلٍ لا يُعرض الزرّ */
    public static function configured(): bool
    {
        return filled(config('whatsapp.app_id'))
            && filled(config('whatsapp.app_secret'))
            && filled(config('whatsapp.config_id'));
    }

    private static function base(): string
    {
        return rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.api_version');
    }

    private static function timeout(): int
    {
        return (int) config('whatsapp.timeout', 15);
    }

    /**
     * تبديل الكود برمزِ مستخدمِ نظامٍ لهذا التاجر.
     *
     * ويُنادى من الخادم وحده: سرُّ التطبيق طرفٌ في النداء، ومن وضعه في
     * متصفّحٍ وضعه في يد كلّ من يفتح أدوات المطوّر.
     *
     * @return array{ok:bool, token:?string, expires_at:?Carbon, code:?string, message:?string}
     */
    public static function exchange(string $code): array
    {
        try {
            $response = Http::timeout(self::timeout())->acceptJson()
                ->get(self::base().'/oauth/access_token', [
                    'client_id' => config('whatsapp.app_id'),
                    'client_secret' => config('whatsapp.app_secret'),
                    'code' => $code,
                ]);
        } catch (\Throwable $e) {
            return self::no('network_error', $e->getMessage());
        }

        if (! $response->successful()) {
            return self::no(...self::error($response->json()));
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($token === '') {
            return self::no('no_token', __('لم تُعِد ميتا رمزًا.'));
        }

        /*
         * ومدّةُ الرمز تُقرأ ولا تُفترض.
         *
         * رمزُ مستخدم النظام من تسجيل الأعمال لا ينتهي غالبًا — فلا `expires_in`
         * في الردّ. وبعض الإعدادات تُعيد مدّةً. فمن كتب «ستّون يومًا» في
         * الكود كتب تحذيرًا كاذبًا لمن لا ينتهي رمزُه، وسكت عمّن ينتهي بعد
         * أسبوع. و`debug_token` تقولها بعد قليلٍ بدقّةٍ أكبر.
         */
        $seconds = (int) ($response->json('expires_in') ?? 0);

        return [
            'ok' => true,
            'token' => $token,
            'expires_at' => $seconds > 0 ? now()->addSeconds($seconds) : null,
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * فحصُ الرمز: ماذا مُنح، ولأيّ حساباتٍ، ومتى ينتهي.
     *
     * ═══ ولمَ يُفحص رمزٌ وصلنا لتوّنا ═══
     *
     * لأنّ ما وصل المتصفّحَ لا يُصدَّق. المتصفّح يقول «حسابُ الأعمال كذا»،
     * والرمزُ وحده يقول ما **مُنح فعلًا**. و`granular_scopes` تُسمّي الحسابات
     * المأذون بها بأعيانها — فمن أرسل معرّف حسابٍ لم يُمنح لهذا الرمز يُردّ
     * هنا لا عند أوّل رسالةٍ تفشل بعد شهر.
     *
     * والنداء برمز التطبيق `{app_id}|{app_secret}` — وهو نداءُ خادمٍ لا غير.
     *
     * ═══ والتطبيقُ الذي أصدره ═══
     *
     * `data.app_id` يقول أيُّ تطبيقٍ عند ميتا أصدر الرمز. ورمزٌ من تطبيقٍ آخر
     * يُرسل الرسائل ويُشرك **تطبيقَه هو** في إشعارات الحساب — لا تطبيقَنا —
     * فيبدو الربطُ سليمًا ولا يصلنا حرف (هكذا وقع لمتجرٍ في 2026-10-28).
     * فيُردّ معه `app_id` ليُقارن بـ`whatsapp.app_id` (`WhatsAppLinkCheck::token`).
     *
     * @return array{ok:bool, app_id:?string, scopes:list<string>, waba_ids:list<string>, messaging_waba_ids:list<string>, expires_at:?Carbon, code:?string, message:?string}
     */
    public static function inspect(string $token): array
    {
        try {
            $response = Http::timeout(self::timeout())->acceptJson()
                ->get(self::base().'/debug_token', [
                    'input_token' => $token,
                    'access_token' => config('whatsapp.app_id').'|'.config('whatsapp.app_secret'),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'app_id' => null, 'scopes' => [], 'waba_ids' => [], 'messaging_waba_ids' => [], 'expires_at' => null,
                'code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return ['ok' => false, 'app_id' => null, 'scopes' => [], 'waba_ids' => [], 'messaging_waba_ids' => [], 'expires_at' => null,
                'code' => $code, 'message' => $message];
        }

        $data = (array) ($response->json('data') ?? []);
        $appId = filled($data['app_id'] ?? null) ? (string) $data['app_id'] : null;

        if (! ($data['is_valid'] ?? false)) {
            return ['ok' => false, 'app_id' => $appId, 'scopes' => [], 'waba_ids' => [], 'messaging_waba_ids' => [], 'expires_at' => null,
                'code' => 'invalid_token', 'message' => __('الرمز الذي أعادته ميتا غير صالح.')];
        }

        $granular = (array) ($data['granular_scopes'] ?? []);
        $scopes = [];
        $wabaIds = [];
        $messagingIds = [];

        foreach ($granular as $row) {
            $scope = (string) ($row['scope'] ?? '');

            if ($scope === '') {
                continue;
            }

            $scopes[] = $scope;

            if ($scope === 'whatsapp_business_management') {
                foreach ((array) ($row['target_ids'] ?? []) as $id) {
                    $wabaIds[] = (string) $id;
                }
            }

            if ($scope === 'whatsapp_business_messaging') {
                foreach ((array) ($row['target_ids'] ?? []) as $id) {
                    $messagingIds[] = (string) $id;
                }
            }
        }

        /* والصلاحياتُ العامّة تُقرأ أيضًا: إعدادٌ بلا تفصيلٍ يضعها في `scopes` */
        foreach ((array) ($data['scopes'] ?? []) as $scope) {
            $scopes[] = (string) $scope;
        }

        /*
         * ونهايةُ الرمز: `expires_at = 0` تعني «لا ينتهي» عند ميتا لا
         * «انتهى سنة ١٩٧٠». وقد سقط في هذا غيرُ واحد.
         */
        $expires = (int) ($data['expires_at'] ?? 0);

        return [
            'ok' => true,
            'app_id' => $appId,
            'scopes' => array_values(array_unique($scopes)),
            'waba_ids' => array_values(array_unique($wabaIds)),
            'messaging_waba_ids' => array_values(array_unique($messagingIds)),
            'expires_at' => $expires > 0 ? now()->setTimestamp($expires) : null,
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * أرقامُ حساب الأعمال — كما تعرفها ميتا لا كما قال المتصفّح.
     *
     * وبها يُفحص أنّ الرقم يتبع هذا الحساب فعلًا: معرّفُ رقمٍ يصل من
     * المتصفّح قد يكون رقمَ حسابٍ آخر — وربطُه يعني أن نُرسل برقمٍ لا نملكه
     * أو أن نسرق إشعاراتِ غيرنا.
     *
     * @return array{ok:bool, numbers:list<array<string, mixed>>, code:?string, message:?string}
     */
    public static function phoneNumbers(string $wabaId, string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$wabaId.'/phone_numbers', [
                    'fields' => 'id,display_phone_number,verified_name,platform_type,code_verification_status,quality_rating',
                    'limit' => 50,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'numbers' => [], 'code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return ['ok' => false, 'numbers' => [], 'code' => $code, 'message' => $message];
        }

        return ['ok' => true, 'numbers' => (array) ($response->json('data') ?? []), 'code' => null, 'message' => null];
    }

    /**
     * حسابُ الأعمال في ميتا الذي يملك هذا الـWABA — للعرض والتقييد.
     *
     * ويُطلب وحده في نداءٍ صغير: `owner_business_info` لا يأتي مع الأرقام.
     * وفشلُه لا يُفشل الربط — معرّفٌ للعرض لا شرطٌ للإرسال.
     */
    public static function ownerBusinessId(string $wabaId, string $token): ?string
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$wabaId, ['fields' => 'id,name,owner_business_info']);
        } catch (\Throwable) {
            return null;
        }

        $id = $response->json('owner_business_info.id');

        return filled($id) ? (string) $id : null;
    }

    /**
     * أمُشترِكٌ تطبيقُنا في إشعارات هذا الحساب؟
     *
     * و`null` تعني «لم نستطع أن نعرف» — لا «لا». فمن قرأها «لا» اشترك
     * ثانيةً، ومن قرأها «نعم» ترك الاشتراك مفقودًا.
     */
    public static function subscribed(string $wabaId, string $token): ?bool
    {
        $read = self::subscribedApps($wabaId, $token);

        if (! $read['ok']) {
            return null;
        }

        $appId = (string) config('whatsapp.app_id');

        foreach ($read['apps'] as $app) {
            if ($app['app_id'] === $appId) {
                return true;
            }
        }

        return false;
    }

    /**
     * التطبيقاتُ المشتركة في إشعارات هذا الحساب — كما تعرفها ميتا.
     *
     * ويُقرأ معها `override_callback_uri`: تطبيقٌ مشتركٌ بعنوانٍ بديلٍ تذهب
     * إشعاراتُه إلى ذلك العنوان لا إلى ما في لوحة التطبيق. وقراءةٌ فقط —
     * `GET` لا يغيّر عند ميتا شيئًا.
     *
     * @return array{ok:bool, apps:list<array{app_id:string, name:?string, override:?string}>, code:?string, message:?string}
     */
    public static function subscribedApps(string $wabaId, string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$wabaId.'/subscribed_apps');
        } catch (\Throwable $e) {
            return ['ok' => false, 'apps' => [], 'code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return ['ok' => false, 'apps' => [], 'code' => $code, 'message' => $message];
        }

        /*
         * وردٌّ لا قائمةَ فيه لا يُقرأ «لا أحدَ مشترك».
         *
         * لو قُرئ كذلك لَنُودي `POST` على تخمين. فقائمةٌ غائبة، أو صفٌّ لا
         * يُقرأ معرّفُ تطبيقه، قراءةٌ فاشلة — لا حكمَ بها على الاشتراك.
         */
        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            return ['ok' => false, 'apps' => [], 'code' => 'unreadable', 'message' => 'subscribed_apps without a data list'];
        }

        $apps = [];

        foreach ($data as $row) {
            $app = is_array($row) ? ($row['whatsapp_business_api_data'] ?? null) : null;
            $id = is_array($app) ? ($app['id'] ?? null) : null;

            if (! is_scalar($id) || (string) $id === '') {
                return ['ok' => false, 'apps' => [], 'code' => 'unreadable', 'message' => 'subscribed_apps row without an app id'];
            }

            $apps[] = [
                'app_id' => (string) $id,
                'name' => $app['name'] ?? null,
                'override' => filled($row['override_callback_uri'] ?? null) ? (string) $row['override_callback_uri'] : null,
            ];
        }

        return ['ok' => true, 'apps' => $apps, 'code' => null, 'message' => null];
    }

    /**
     * إعدادُ الإشعارات في تطبيق أبعاد نفسه — العنوانُ وحقولُه. قراءةٌ برمز التطبيق.
     *
     * الاشتراكُ في حساب متجرٍ (`subscribed_apps`) يقول إنّ الحسابَ يُرسل
     * إلى تطبيقنا؛ وهذا يقول إلى أيّ عنوانٍ يُرسل التطبيقُ، وأيّ الحقول
     * مُشعَلة. فلو أُطفئ `messages` في لوحة التطبيق لَما وصل حرفٌ من أيّ
     * متجر — والمتاجرُ كلُّها «مشتركة». `GET` لا يغيّر عند ميتا شيئًا.
     *
     * @return array{ok:bool, callback_url:?string, fields:list<string>, active:bool, code:?string, message:?string}
     */
    public static function appSubscription(): array
    {
        $none = ['ok' => false, 'callback_url' => null, 'fields' => [], 'active' => false];

        if (blank(config('whatsapp.app_id')) || blank(config('whatsapp.app_secret'))) {
            return $none + ['code' => 'not_configured', 'message' => 'whatsapp.app_id or app_secret missing'];
        }

        try {
            $response = Http::timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.config('whatsapp.app_id').'/subscriptions', [
                    'access_token' => config('whatsapp.app_id').'|'.config('whatsapp.app_secret'),
                ]);
        } catch (\Throwable $e) {
            return $none + ['code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return $none + ['code' => $code, 'message' => $message];
        }

        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            return $none + ['code' => 'unreadable', 'message' => 'subscriptions without a data list'];
        }

        $row = collect($data)->first(fn ($r) => is_array($r) && ($r['object'] ?? null) === 'whatsapp_business_account');

        // التطبيقُ بلا اشتراكٍ في كائن واتساب أصلًا — قراءةٌ ناجحةٌ تقول «لا شيء»
        if ($row === null) {
            return ['ok' => true, 'callback_url' => null, 'fields' => [], 'active' => false, 'code' => null, 'message' => null];
        }

        $fields = collect((array) ($row['fields'] ?? []))
            ->map(fn ($f) => is_array($f) ? (string) ($f['name'] ?? '') : (string) $f)
            ->filter()->values()->all();

        return [
            'ok' => true,
            'callback_url' => filled($row['callback_url'] ?? null) ? (string) $row['callback_url'] : null,
            'fields' => $fields,
            'active' => (bool) ($row['active'] ?? false),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * أدوارُ أبعاد في توجيه محادثات هذا الرقم (Conversation Routing) — قراءةٌ لا غير.
     *
     * ميتا قد تُنشئ توجيهًا عند الربط من المستجيبين القائمين على الرقم، ولا
     * تجعل الشريكَ الجديد أساسيًّا تلقائيًّا. فتطبيقٌ مشتركٌ في الحساب قد لا
     * يصله وارد. و`#100` «not enabled» قراءةٌ ناجحة: لا توجيه، فكلُّ
     * مستجيبٍ متّصلٍ يصله كلُّ وارد. والتوجيهُ يُضبط من Meta Business Suite
     * وحده — لا واجهةَ برمجيّةَ لضبطه، ولا نضبطه نحن.
     *
     * @return array{ok:bool, enabled:?bool, roles:list<string>, standby:bool, code:?string, message:?string}
     */
    public static function routingRoles(string $phoneNumberId, string $token): array
    {
        $none = ['enabled' => null, 'roles' => [], 'standby' => false];

        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$phoneNumberId.'/routing_roles');
        } catch (\Throwable $e) {
            return ['ok' => false] + $none + ['code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            if ($code === '100' && str_contains(mb_strtolower($message), 'not enabled')) {
                return ['ok' => true, 'enabled' => false, 'roles' => [], 'standby' => false, 'code' => null, 'message' => null];
            }

            return ['ok' => false] + $none + ['code' => $code, 'message' => $message];
        }

        $roles = $response->json('roles');

        if (! is_array($roles) || ! array_is_list($roles)) {
            return ['ok' => false] + $none + ['code' => 'unreadable', 'message' => 'routing_roles without a roles list'];
        }

        return [
            'ok' => true,
            'enabled' => true,
            'roles' => array_values(array_map('strval', array_filter($roles, 'is_scalar'))),
            'standby' => (bool) $response->json('is_standby', false),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * أهذا الرقمُ على تطبيق واتساب للأعمال في الهاتف أيضًا؟
     *
     * وثيقةُ ميتا لمسار تطبيق الأعمال تقرأ `is_on_biz_app` على الرقم نفسه —
     * والرقمُ بعد التعايش يقرأ `platform_type = CLOUD_API` لا `SMB_APP`،
     * فالنوعُ وحده لا يكفي.
     *
     * ثلاثُ إجاباتٍ لا اثنتان: `true` عليه، و`false` قالت ميتا صراحةً إنّه
     * ليس عليه، و`null` لم نعرف — نداءٌ فشل أو حقلٌ غاب. و`null` لا تُقرأ
     * `false`: من يربط بها يربط رقمًا لا يدري ما هو (`WhatsAppLinkCheck::numberType`).
     */
    public static function onBusinessApp(string $phoneNumberId, string $token): ?bool
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$phoneNumberId, ['fields' => 'is_on_biz_app,platform_type']);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        if ($response->json('platform_type') === self::PLATFORM_SMB_APP) {
            return true;
        }

        $flag = $response->json('is_on_biz_app');

        return is_bool($flag) ? $flag : null;
    }

    /**
     * الاشتراك في إشعارات هذا الحساب — بلا هذا لا يصلنا شيء.
     *
     * والعنوانُ ليس طرفًا في النداء: ميتا ترسل إلى العنوان المسجَّل في
     * التطبيق نفسه — وهو `https://app.abaadapp.om/webhooks/whatsapp` ولم
     * يتغيّر ولا يتغيّر من هنا.
     *
     * @return array{ok:bool, code:?string, message:?string}
     */
    public static function subscribe(string $wabaId, string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->post(self::base().'/'.$wabaId.'/subscribed_apps');
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return ['ok' => false, 'code' => $code, 'message' => $message];
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    /**
     * نداءُ فحصٍ على الرقم — «أيردّ هذا الرقم علينا اليوم؟».
     *
     * @return array{ok:bool, display_phone_number:?string, verified_name:?string, quality_rating:?string, code:?string, message:?string}
     */
    public static function inspectNumber(string $phoneNumberId, string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$phoneNumberId, [
                    'fields' => 'display_phone_number,verified_name,quality_rating,platform_type',
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'display_phone_number' => null, 'verified_name' => null,
                'quality_rating' => null, 'code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return ['ok' => false, 'display_phone_number' => null, 'verified_name' => null,
                'quality_rating' => null, 'code' => $code, 'message' => $message];
        }

        return [
            'ok' => true,
            'display_phone_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
            'quality_rating' => $response->json('quality_rating'),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * رمزُ خطأِ ميتا ونصُّه — ولا شيءَ من الاعتماد.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{0:string, 1:string}
     */
    private static function error(?array $payload): array
    {
        $error = (array) ($payload['error'] ?? []);

        return [
            (string) ($error['code'] ?? 'meta_error'),
            (string) ($error['message'] ?? __('لم تقبل ميتا الطلب.')),
        ];
    }

    /**
     * @return array{ok:bool, token:?string, expires_at:null, code:string, message:string}
     */
    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'token' => null, 'expires_at' => null, 'code' => $code, 'message' => $message];
    }
}

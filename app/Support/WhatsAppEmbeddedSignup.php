<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * التسجيل المدمج عند ميتا — النداءاتُ الخمسة التي يحتاجها الربط، ولا سادس.
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
     * @return array{ok:bool, scopes:list<string>, waba_ids:list<string>, expires_at:?Carbon, code:?string, message:?string}
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
            return ['ok' => false, 'scopes' => [], 'waba_ids' => [], 'expires_at' => null,
                'code' => 'network_error', 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            [$code, $message] = self::error($response->json());

            return ['ok' => false, 'scopes' => [], 'waba_ids' => [], 'expires_at' => null,
                'code' => $code, 'message' => $message];
        }

        $data = (array) ($response->json('data') ?? []);

        if (! ($data['is_valid'] ?? false)) {
            return ['ok' => false, 'scopes' => [], 'waba_ids' => [], 'expires_at' => null,
                'code' => 'invalid_token', 'message' => __('الرمز الذي أعادته ميتا غير صالح.')];
        }

        $granular = (array) ($data['granular_scopes'] ?? []);
        $scopes = [];
        $wabaIds = [];

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
            'scopes' => array_values(array_unique($scopes)),
            'waba_ids' => array_values(array_unique($wabaIds)),
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
     * يُسأل قبل الاشتراك: `POST` ثانيةً لا يكسر شيئًا عند ميتا، لكنّ السؤال
     * يجعل السجلّ يقول «كان مشتركًا» بدل أن يقول «اشتركنا» في كلّ مرّة —
     * وأحدُهما خبرٌ والآخرُ ضجيج.
     *
     * و`null` تعني «لم نستطع أن نعرف» — لا «لا». فمن قرأها «لا» اشترك
     * ثانيةً، ومن قرأها «نعم» ترك الاشتراك مفقودًا.
     */
    public static function subscribed(string $wabaId, string $token): ?bool
    {
        try {
            $response = Http::withToken($token)->timeout(self::timeout())->acceptJson()
                ->get(self::base().'/'.$wabaId.'/subscribed_apps');
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $appId = (string) config('whatsapp.app_id');

        foreach ((array) ($response->json('data') ?? []) as $row) {
            if ((string) ($row['whatsapp_business_api_data']['id'] ?? '') === $appId) {
                return true;
            }
        }

        return false;
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

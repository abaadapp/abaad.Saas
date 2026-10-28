<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Order;
use App\Models\Plan;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;

/**
 * قدرات الباقة — ما تفتحه فعلًا، لا ما تعد به في صفحة التسعير.
 *
 * كان في الباقة عمودان من نوعين: أسقفٌ عدديّة تُفرَض (`PlanLimits`)، و`features`
 * قائمةُ نصوصٍ حرّة يكتبها صاحب المنصّة ولا يقرؤها حارس. فـ«تقارير متقدمة»
 * و«صلاحيات مخصصة» كلماتٌ تُقرأ ولا تعمل: من اشترى الأرخص يفتحها كلّها.
 *
 * والفرق بين النصّ والقدرة أنّ القدرة مفتاحٌ من قائمةٍ مغلقة: لا يُكتب باليد،
 * ولا يُطابَق بالاسم، ولا يسقط لأنّ حرفًا اختلف. والنصّ يبقى كما هو للعرض —
 * فصاحب المنصّة يكتب في صفحة التسعير ما يشاء، ويؤشّر على ما يُفتح فعلًا.
 *
 * والافتراض «مفتوح» لا «مغلق»:
 *
 *   - باقةٌ بلا قدراتٍ محفوظة (`null`) تفتح كلّ شيء — الأقدم من هذا العمود لا
 *     تُقفل على أصحابها لأنّ حقلًا فيها لم يُملأ بعد.
 *   - متجرٌ بلا باقةٍ أصلًا يفتح كلّ شيء — كما في `PlanLimits`: «بلا باقة لا
 *     سقف».
 *
 * والقفلُ الخاطئ أسوأ من الفتح الخاطئ هنا: من فُتح له ما لم يشترِه يُكتشف
 * ويُصحَّح، ومن أُغلق دونه ما اشتراه يتوقّف عمله اليوم ويظنّ العطب في النظام.
 */
class PlanFeatures
{
    /**
     * القائمة المغلقة — مفتاحٌ واسمٌ يُعرض لصاحب المنصّة ووصفُ ما يُغلق.
     *
     * ولا يُضاف إليها مفتاحٌ لا حارسَ له: قدرةٌ تُؤشَّر في الشاشة ولا تُفحص في
     * الكود هي `features` نفسها بثوبٍ جديد.
     */
    public const CAPABILITIES = [
        'reports_advanced' => [
            'label' => 'التقارير المتقدّمة',
            'hint' => 'تحليلات الهالك وتصدير التقارير — والملخّصات على الشاشة تبقى للجميع',
        ],
        'custom_permissions' => [
            'label' => 'الصلاحيات المخصّصة',
            'hint' => 'تخصيص أقسام كل موظف يدويًّا — وأدوارُ النظام تبقى للجميع',
        ],
        'loyalty' => [
            'label' => 'برنامج الولاء',
            'hint' => 'النقاط واستبدالها عند الصندوق',
        ],
        'whatsapp' => [
            'label' => 'إشعارات واتساب',
            'hint' => 'إرسال حالة الطلب إلى العميل',
        ],
        /*
         * والموقعُ بابان: لوحةٌ يُبنى فيها، وعنوانٌ يُخدَم عليه الزبون.
         *
         * فالحارسُ في الموضعين — `CheckPlanFeature` على `admin.website.*`
         * وأخواتها القديمة، و`Storefront::serving` على كلّ صفحةٍ عامّة وكلّ
         * سلّةٍ وطلب. وإغلاقُ اللوحة وحدها يُبقي الموقعَ يبيع بلا باقة.
         *
         * ولا يمحو الإغلاقُ شيئًا: الموقعُ ونسخُه وتصميمُه ونطاقُه في مكانها،
         * ويعود كما كان يومَ تعود القدرة.
         */
        'website' => [
            'label' => 'الموقع الإلكتروني',
            'hint' => 'بناء الموقع ونشره ونطاقه — والموقع العام وطلباته لا تُخدم بدونها',
        ],
        /*
         * وأدواتُ التسويق ما ليس له قدرةٌ مستقلّة: التقييماتُ والكوبونات.
         *
         * فالولاءُ تحت `loyalty`، وواتساب تحت `whatsapp` — لكلٍّ مقبضُه، ولا
         * يُغلق أحدُهما بإغلاق هذه.
         */
        'marketing_tools' => [
            'label' => 'أدوات التسويق',
            'hint' => 'تقييمات العملاء والكوبونات والعروض — والولاء وواتساب لهما قدرتاهما',
        ],
        /*
         * والتطبيقاتُ التكامليّة: صفحتُها وخرائطُ Google وGoogle Business.
         *
         * والحارسُ في `CheckPlanFeature` على بابها و`google*`، وفي
         * `IntegrationsController::connect` لأداة Google. ولا يمحو الإغلاقُ
         * شيئًا: المفتاحُ والربطُ والمواقعُ المربوطة في مكانها، وتعود يومَ تعود.
         *
         * وليس منها بابان: Paymob مفتوحٌ لكلّ باقة (بنيةُ دفعٍ لفواتير العملاء
         * وروابط الدفع)، وربطُ واتساب بقدرة `whatsapp`.
         *
         * ونزلت قبل حارسها بترحيلٍ أضافها إلى كلّ قائمةٍ صريحة
         * (`2026_10_13_180000_a_plan_keeps_the_integrations_it_already_had`)،
         * فلم يُغلق نشرُ الحارس شيئًا على متجرٍ قائم.
         */
        'integrations' => [
            'label' => 'التطبيقات التكاملية',
            'hint' => 'ربط المتجر بالخدمات الخارجية وضبطها من «التطبيقات التكاملية»',
        ],
        /*
         * ═══ وإدارةُ الفروع — قدراتُ المؤسسات ═══
         *
         * خمسُ قدراتٍ تُباع مع باقة المؤسسات وحدها، وكلٌّ لها حارسُها في
         * `CheckPlanFeature`. وهي **مغلقةٌ افتراضًا** خلافًا لما فوقها — انظر
         * `ENTERPRISE`.
         */
        'enterprise_transfers' => [
            'label' => 'نقل المخزون بين الفروع',
            'hint' => 'سندات النقل والموافقة والإرسال والاستلام ومعالجة الفروقات وسجلّها',
        ],
        'enterprise_replenishment' => [
            'label' => 'طلبات تزويد الفروع',
            'hint' => 'تطلب الفروع البضاعة وتعتمدها الإدارة سندَ نقل — تحتاج «نقل المخزون بين الفروع»',
        ],
        'enterprise_branch_analytics' => [
            'label' => 'أداء الفروع',
            'hint' => 'لوحة مركزية تقارن مبيعات الفروع وأرباحها ومخزونها',
        ],
        'enterprise_weekly_report' => [
            'label' => 'التقرير الأسبوعي للفروع',
            'hint' => 'ملخّص أسبوعيّ لكلّ الفروع داخل اللوحة',
        ],
        'enterprise_alerts' => [
            'label' => 'التنبيهات المركزية للفروع',
            'hint' => 'تنبيهات طلبات التزويد والتحويلات المتأخرة وفروق الاستلام ونقص مخزون الفروع',
        ],
    ];

    /**
     * قدراتُ المؤسسات — تُفتح بالقائمة الصريحة وحدها.
     *
     * ═══ ولمَ لا تتبع الافتراض المفتوح ═══
     *
     * «بلا باقة» و«باقةٌ لم تُملأ قدراتُها» تفتحان كلّ ما فوق: هي قدراتٌ كانت
     * تعمل قبل أن يكون للقدرات عمود، والقفلُ الخاطئ عليها يوقف عملًا قائمًا.
     * وهذه وُلدت مع عمودها: لا متجرَ يعمل بها اليوم فيُقفل دونه. فالفتحُ
     * الخاطئ هنا لا يُكتشف ويُصحَّح — هو بيعُ باقة المؤسسات بسعر الأساسيّة،
     * وتسرّبُها إلى كلّ باقةٍ قديمةٍ عمودُها فارغ أو مخصّصةٍ لم يؤشَّر فيها.
     *
     * فلا يفتحها إلّا مفتاحُها في `capabilities` — يكتبه ترحيلُ باقة المؤسسات
     * (`2026_10_24_090000_the_enterprise_plan_runs_its_branches`) أو يؤشّره
     * صاحبُ المنصّة صراحةً في محرّر الباقة.
     */
    public const ENTERPRISE = [
        'enterprise_transfers',
        'enterprise_replenishment',
        'enterprise_branch_analytics',
        'enterprise_weekly_report',
        'enterprise_alerts',
    ];

    /**
     * هل يفتح اشتراكُ هذا المتجر هذه القدرة؟
     *
     * ═══ وللسياسة الجديدة بابٌ قبل الباقة ═══
     *
     * متجرٌ أُنشئ بعد إطلاق الإضافات (`Addons\PricingPolicy`) تُفتح له قدراتُ
     * الكتالوج (`Addons\Catalog::FEATURE_KEYS`، ونقطةُ البيع منها) بشمولها في
     * باقته أو بإضافةٍ ساريةٍ له — لا بقائمة `capabilities`. وما سواها من قدرات
     * الباقة يُقرأ من الباقة كما كان.
     *
     * ومتجرُ السياسة السابقة كما كان حرفًا بحرف: نقطةُ البيع مفتوحةٌ له (لم
     * تُبَع منفصلةً يومًا)، وباقتُه تقرّر الباقي. ومنحةٌ ساريةٌ من صاحب المنصّة
     * تفتح فوقها ولا تُغلق ما فيها.
     */
    public static function allows(?Business $business, string $key): bool
    {
        $sold = in_array($key, Catalog::FEATURE_KEYS, true);

        if ($sold && $business !== null && PricingPolicy::isAddons($business)) {
            return Entitlements::has($business, $key);
        }

        if ($key === Catalog::POS) {
            return true;
        }

        return self::allowedBy($business?->plan, $key)
            || ($sold && $business !== null && Entitlements::active($business, $key));
    }

    /** الجواب نفسه عن باقةٍ بعينها — تُسأل قبل النقل إليها */
    public static function allowedBy(?Plan $plan, string $key): bool
    {
        $granted = $plan?->capabilities;

        // وقدراتُ المؤسسات بالقائمة الصريحة وحدها — لا افتراضَ يفتحها
        if (in_array($key, self::ENTERPRISE, true)) {
            return is_array($granted) && in_array($key, $granted, true);
        }

        // بلا باقة، أو بباقةٍ لم تُملأ قدراتُها: مفتوحٌ كما كان
        if ($plan === null || ! is_array($granted)) {
            return true;
        }

        return in_array($key, $granted, true);
    }

    /**
     * يقطع الطلب إن كانت القدرة مغلقة — ورسالةٌ تقول ما يُفعل.
     *
     * و403 لا 404: من يصطدم بها اشترى نظامًا وله أن يعرف أنّ البابَ موجودٌ
     * وأنّ باقتَه لا تفتحه. و«غير موجود» تجعله يظنّ العطب في النظام فيتّصل
     * بالدعم بدل أن يرقّي.
     */
    public static function enforce(?Business $business, string $key): void
    {
        if (self::allows($business, $key)) {
            return;
        }

        abort(403, self::refusal($business, $key));
    }

    /**
     * أبوابُ نقطة البيع التي لا تُنشئ بيعًا — لمن يملكها الآن أو باع منها يومًا.
     *
     * متجرٌ انقضت إضافتُه أو نزل من باقةٍ تشملها يُغلق عملَه القائم: يطبع إيصالًا،
     * ويصحّح بندًا، ويحصّل باقيًا. ومتجرٌ لم يبع من الصندوق قطّ ولا ملك إضافتَه لا
     * عملَ له هناك — والبابُ المفتوح له نقطةُ بيعٍ مجّانيّةٌ تُطرق بطلبٍ مباشر.
     */
    public static function allowsPosRecords(?Business $business): bool
    {
        // ولا يُفتح صندوقٌ بلا متجرٍ يُسأل عنه — «بلا باقة مفتوح» لا يسري على نقطة البيع
        if ($business === null) {
            return false;
        }

        if (self::allows($business, Catalog::POS)) {
            return true;
        }

        return Order::where('business_id', $business->id)->where('channel', SalesChannel::POS)->exists()
            || BusinessAddon::where('business_id', $business->id)
                ->whereHas('addon', fn ($q) => $q->where('capability', Catalog::POS))->exists();
    }

    public static function enforcePosRecords(?Business $business): void
    {
        if (! self::allowsPosRecords($business)) {
            abort(403, self::refusal($business, Catalog::POS));
        }
    }

    /** نصّ الرفض — يُقرأ في الشاشة وفي رسالة التحقّق معًا */
    public static function refusal(?Business $business, string $key): string
    {
        $feature = __(self::CAPABILITIES[$key]['label'] ?? Catalog::CAPABILITIES[$key] ?? $key);

        // ومتجرُ السياسة الجديدة يشتري الإضافةَ لا يرقّي الباقة
        if (PricingPolicy::isAddons($business) && in_array($key, Catalog::FEATURE_KEYS, true)) {
            return __('«:feature» ليست ضمن اشتراكك. اطلبها من «إضافات متجرك».', ['feature' => $feature]);
        }

        return __('«:feature» ليست في باقة «:plan». رقِّ الباقة لفتحها.', [
            'feature' => $feature,
            'plan' => $business?->plan?->name ?? __('الحالية'),
        ]);
    }

    /**
     * ما يفتحه هذا المتجر — تقرؤه الواجهة فتُخفي ما لا يُفتح.
     *
     * والبابُ الذي يُعرض ولا يُفتح أسوأ من بابٍ لا يُعرض: الموظّف يظنّ العطب
     * في النظام ويعيد المحاولة. فالشاشة تقرأ هذه لا تخمّن.
     *
     * @return array<string, bool>
     */
    public static function map(?Business $business): array
    {
        $out = [];

        foreach (array_keys(self::CAPABILITIES) as $key) {
            $out[$key] = self::allows($business, $key);
        }

        // ونقطةُ البيع — ليست في محرّر الباقة، وتسألها الواجهةُ لتُخفي زرَّها
        $out[Catalog::POS] = self::allows($business, Catalog::POS);

        return $out;
    }

    /** المفاتيح المسموح حفظُها على الباقة — يقرؤها التحقّق في لوحة المنصّة */
    public static function keys(): array
    {
        return array_keys(self::CAPABILITIES);
    }

    /** ما دون قدرات المؤسسات — قائمةُ الاحترافيّة */
    public static function baseKeys(): array
    {
        return array_values(array_diff(self::keys(), self::ENTERPRISE));
    }

    /**
     * الخيارات كما تُعرض في محرّر الباقة.
     *
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn ($key) => [
                'key' => $key,
                'label' => __(self::CAPABILITIES[$key]['label']),
                'hint' => __(self::CAPABILITIES[$key]['hint']),
            ],
            self::keys(),
        );
    }
}

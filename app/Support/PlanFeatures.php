<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Plan;

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
    ];

    /** هل تفتح باقةُ هذا المتجر هذه القدرة؟ */
    public static function allows(?Business $business, string $key): bool
    {
        return self::allowedBy($business?->plan, $key);
    }

    /** الجواب نفسه عن باقةٍ بعينها — تُسأل قبل النقل إليها */
    public static function allowedBy(?Plan $plan, string $key): bool
    {
        $granted = $plan?->capabilities;

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

    /** نصّ الرفض — يُقرأ في الشاشة وفي رسالة التحقّق معًا */
    public static function refusal(?Business $business, string $key): string
    {
        return __('«:feature» ليست في باقة «:plan». رقِّ الباقة لفتحها.', [
            'feature' => __(self::CAPABILITIES[$key]['label'] ?? $key),
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

        return $out;
    }

    /** المفاتيح المسموح حفظُها على الباقة — يقرؤها التحقّق في لوحة المنصّة */
    public static function keys(): array
    {
        return array_keys(self::CAPABILITIES);
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

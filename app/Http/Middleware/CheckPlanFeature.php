<?php

namespace App\Http\Middleware;

use App\Support\PlanFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حارسُ قدرات الباقة — أخو `CheckAbility` وليس هو.
 *
 * `CheckAbility` تسأل «هل يملك هذا الموظّف هذا القسم؟»، وهذه تسأل «هل اشترى
 * صاحبُ المتجر هذه القدرة؟». والسؤالان يقعان معًا على المسار الواحد: مالكٌ
 * يملك كلّ الأقسام في متجرٍ على الباقة الأساسية يُردّ هنا لا هناك.
 *
 * والخريطة باسم المسار كما في أختها — لا وسيطٌ يُكتب عند كل مسار: مسارٌ يُضاف
 * وينسى كاتبُه وسيطَه يمرّ بلا حارس، ولا يظهر ذلك في شاشة.
 */
class CheckPlanFeature
{
    /**
     * المسارات المحروسة → القدرة التي تفتحها.
     *
     * والنجمة تعني بادئة: `admin.export.*` كلّها تصدير.
     *
     * وما ليس هنا مفتوحٌ لكلّ باقة — وهو الصواب: الأصل أنّ ما بيع يُفتح،
     * والقفلُ يُكتب صراحةً موضعًا موضعًا.
     */
    public const ROUTES = [
        /*
         * «تقارير أساسية» تُقرأ على الشاشة؛ والتصدير فوقها.
         *
         * و«تحليلات الهالك» خرجت من القفل بقرار المالك: تُفتح لكلّ باقة.
         * وهي شاشةُ قراءةٍ كبقيّة التقارير — تقول ما تلف وما فُقد، وهو رقمٌ
         * يخسره صاحبُ المحلّ كلَّ يومٍ لا يراه. وحجبُه عن الباقة الأساسية
         * يحجب الخسارة عمّن هو أحوجُ إلى رؤيتها.
         *
         * والتصديرُ يبقى فوقها كما فوق غيرها — الملفّ هو المبيع لا الشاشة.
         */
        'admin.reports.xlsx' => 'reports_advanced',
        'admin.reports.pdf' => 'reports_advanced',
        'admin.export.reports' => 'reports_advanced',
        /*
         * وتصديراتُ الصفحات الخمسَ عشرةَ معها.
         *
         * فُتحت حين صار لكلّ تقريرٍ صفحتُه: كُتبت لها ثلاثةُ مسارات جديدة
         * ولم تُكتب في هذه الخريطة. فبقي «ملخّص المبيعات» وحده مقفلًا على
         * الباقة الأساسية، وخمسةَ عشرَ تقريرًا يُصدَّر منها كلُّ شيء —
         * إكسل وPDF وCSV — بلا أن يشتريَ صاحبُها التحليل.
         *
         * وهو أسوأ من ثغرةٍ في البيع: قفلٌ يُرى على بابٍ وبابٌ آخر مفتوح
         * بجواره على الغرفة نفسها.
         */
        'admin.reports.export.*' => 'reports_advanced',
        // شاشةُ الولاء وحفظُها — والنقاط نفسها تُفحص عند الصندوق (PosController::loyaltyOn)
        'admin.marketing.loyalty' => 'loyalty',
        'admin.marketing.loyalty.save' => 'loyalty',
        // والإرسال نفسه يُفحص في WhatsAppFeature::blockReason — الإشعار يخرج من الطلب لا من زرّ
        'admin.marketing.whatsapp*' => 'whatsapp',
    ];

    /**
     * تقاريرُ يخرج ملفُّها لكلّ باقة.
     *
     * و«ضريبة القيمة المضافة» منها: ملفُّها ليس تحليلًا يُشترى، هو الورقةُ
     * التي يُقدَّم بها إقرارٌ إلى جهةٍ حكومية. وتقريرُ ضريبةٍ لا يخرج ملفُّه
     * لا يؤدّي الغرضَ الوحيد الذي وُجد له — يُقرأ على الشاشة ثم يُنقل رقمًا
     * رقمًا بيد.
     */
    public const OPEN_EXPORTS = ['vat'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = (string) $route?->getName();
        $key = self::featureFor($name);

        if ($key !== null && ! self::isOpenExport($name, $route?->parameter('report'))) {
            PlanFeatures::enforce($request->user()?->business, $key);
        }

        return $next($request);
    }

    /** هل هذا تنزيلُ تقريرٍ مفتوحٍ للجميع؟ — يُقاس بالمعامل لا بالاسم */
    public static function isOpenExport(string $route, ?string $report): bool
    {
        return str_starts_with($route, 'admin.reports.export.')
            && $report !== null
            && in_array($report, self::OPEN_EXPORTS, true);
    }

    /** القدرة التي يحتاجها هذا المسار — أو null إن كان مفتوحًا */
    public static function featureFor(string $route): ?string
    {
        if (isset(self::ROUTES[$route])) {
            return self::ROUTES[$route];
        }

        foreach (self::ROUTES as $pattern => $key) {
            if (str_ends_with($pattern, '*') && str_starts_with($route, rtrim($pattern, '*'))) {
                return $key;
            }
        }

        return null;
    }
}

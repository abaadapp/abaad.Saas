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

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        $key = self::featureFor($name);

        if ($key !== null) {
            PlanFeatures::enforce($request->user()?->business, $key);
        }

        return $next($request);
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

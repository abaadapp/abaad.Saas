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
        /*
         * ═══ الموقعُ الإلكترونيّ — العائلةُ كلُّها باسمها ═══
         *
         * لوحةُ الموقع وكلُّ ما فيها: الإنشاءُ والمحرّرُ والأقسامُ والصفحاتُ
         * والتصميمُ والوسائطُ والمتجرُ والنطاقُ والظهورُ في البحث والنشرُ
         * والاستعادةُ والصيانة. بادئةٌ واحدة لا قائمةُ أسماء: بابٌ يُضاف غدًا
         * تحتها يُحرس يومَ يُكتب.
         *
         * والأبوابُ القديمة تحت «التسويق» قبل أن ينتقل الموقع إلى قسمه — ما
         * زالت تحفظ صفحةَ المتجر البسيطة وصورَها ومنتجاتِها ومسارَ النطاق
         * والسيو والتحليلات، فتُحرس بالقدرة نفسِها. ومعاينةُ المتجر معها.
         *
         * والموقعُ العامُّ نفسُه يُحرس في `Storefront::serving` — لا هنا: هو
         * خارج اللوحة ولا يمرّ بهذا الوسيط.
         */
        'admin.website.*' => 'website',
        'admin.marketing.website*' => 'website',
        'admin.marketing.store.*' => 'website',
        'admin.marketing.domain.path' => 'website',
        'admin.marketing.seo*' => 'website',
        'admin.store.preview' => 'website',
        /*
         * ═══ وأدواتُ التسويق — ما ليس له قدرةٌ مستقلّة ═══
         *
         * التقييماتُ والكوبونات وحدهما. والولاءُ وواتساب فوقهما بقدرتيهما،
         * ولا تُكتب هنا بادئةُ `admin.marketing.*`: كانت ستُغلق الولاءَ
         * وواتساب على من اشتراهما لأنّه لم يشترِ هذه.
         *
         * وصرفُ الكوبون عند الصندوق وفي سلّة الموقع لا يُقفل بها: من نزلت
         * باقتُه لا يُنشئ كوبونًا جديدًا، وما أعطاه لزبائنه قبلها يبقى يُصرف.
         */
        'admin.marketing.reviews*' => 'marketing_tools',
        'admin.marketing.coupons' => 'marketing_tools',
        'admin.coupons.*' => 'marketing_tools',
        /*
         * ═══ والتطبيقاتُ التكامليّة — بابُها وGoogle وما بعده ═══
         *
         * صفحتُها، وخرائطُ Google (المفتاحُ والتفعيلُ والبحثُ وربطُ الفروع
         * والتحديث)، وGoogle Business (الربطُ وعودةُ OAuth والمواقعُ والمزامنةُ
         * والردُّ والتنبيهات والفصل). `google*` بادئةٌ تشمل `googleBusiness*`.
         *
         * ولا تُكتب بادئةُ `admin.integrations.*` كلُّها: تحتها بابان ليسا منها
         * بقرار المالك (2026-10-13) —
         *
         *  · Paymob مفتوحٌ لكلّ باقة: بنيةُ دفعٍ لفواتير العملاء وروابط الدفع، لا
         *    أداةُ نموّ. انظر `OPEN_INTEGRATIONS`.
         *  · وربطُ واتساب بقدرة واتساب أدناه — لا بهذه.
         *
         * و`admin.integrations.connect/{tool}` يُحرس في متحكّمه: قدرتُه تتبع
         * الأداةَ في المعامل لا اسمَ المسار (`IntegrationsController::connect`).
         *
         * ولا يُحرس هنا ما ليس بابَ تاجر: ردودُ Meta وPaymob العامّة خارج
         * `admin.*` ولا تمرّ بهذا الوسيط — ووقفُها يُفسد اتّساقَ ما سبق ربطُه.
         */
        'admin.integrations.index' => 'integrations',
        'admin.integrations.google*' => 'integrations',
        // وربطُ واتساب وإعدادُه — بقدرة واتساب، كإشعاراته وسجلّه
        'admin.integrations.whatsapp*' => 'whatsapp',
        /*
         * ═══ وإدارةُ الفروع — قدراتُ المؤسسات ═══
         *
         * ما يُنشئ عملًا جديدًا يُقفل: طلبُ النقل واعتمادُه وتحضيرُه وإرسالُه،
         * وطلبُ التزويد واعتمادُه. أمّا ما يُغلق عملًا قائمًا — الاستلامُ وتسويةُ
         * الفرق والرفضُ والإلغاءُ والقراءة — فيبقى بعد تخفيض الباقة: بضاعةٌ خرجت
         * من فرعٍ قبل التخفيض تُستلم في الآخر بعده، ولا تبقى معلّقةً قيدَ النقل
         * لأنّ الباقة تغيّرت. ولا يُفتح بذلك جديد: الشاشةُ تُقرأ لمن له فيها سجلّ
         * (`BranchTransferController::enter`)، و`BranchTransfers::refusal` تسأل
         * القدرةَ نفسَها ثانيةً على الاعتماد والإرسال.
         *
         * والقراءاتُ — أداءُ الفروع والتقريرُ الأسبوعيّ — تُقفل كلُّها، وتصديرُها معها
         * (`Reports::FEATURES`).
         */
        'admin.inventory.transfers.store' => 'enterprise_transfers',
        'admin.inventory.transfers.approve' => 'enterprise_transfers',
        'admin.inventory.transfers.prepare' => 'enterprise_transfers',
        'admin.inventory.transfers.dispatch' => 'enterprise_transfers',
        'admin.inventory.supply.store' => 'enterprise_replenishment',
        'admin.inventory.supply.approve' => 'enterprise_replenishment',
        'admin.reports.transfers' => 'enterprise_transfers',
        'admin.reports.branches' => 'enterprise_branch_analytics',
        'admin.reports.branchesWeekly' => 'enterprise_weekly_report',
        /*
         * ═══ ونقطةُ البيع — إضافةٌ لمتاجر السياسة الجديدة ═══
         *
         * ما يُنشئ بيعًا جديدًا يُقفل: فتحُ الصندوق، وتفعيلُ جهازه، والدفعُ،
         * والتعليق، وحركةُ الدرج، والكوبون، وإضافةُ بندٍ أو استبدالُه في بيعةٍ
         * قائمة. وما يُغلق عملًا قائمًا يبقى بعد انقضاء الإضافة: الإيصالاتُ
         * وطباعتُها، وتصحيحُ البند والدفع، وتحصيلُ الباقي، وسجلُّ الطلبات —
         * ولوحةُ الإدارة كلُّها كما هي.
         *
         * ولا يُقفل على متجر السياسة السابقة شيء: `PlanFeatures::allows` تفتح
         * نقطةَ البيع له كما كانت لكلّ باقة.
         */
        'pos.index' => 'pos',
        'pos.setup' => 'pos',
        'pos.setup.activate' => 'pos',
        'pos.checkout' => 'pos',
        'pos.hold' => 'pos',
        'pos.movements.store' => 'pos',
        'pos.coupon.apply' => 'pos',
        'pos.orders.items.store' => 'pos',
        'pos.orders.items.replace' => 'pos',
    ];

    /**
     * أبوابُ التكاملات المفتوحة لكلّ باقة — مكتوبةٌ لا منسيّة.
     *
     * Paymob بنيةُ الدفع التي تقف عليها روابطُ الدفع على فواتير العملاء: متجرٌ
     * على الأساسيّة يُصدر فاتورةً ويرسل رابطَ سدادها. فلا تُغلق بإغلاق
     * التكاملات. ويقرؤها `IntegrationsAreSoldWithThePlanTest`: بابٌ جديدٌ تحت
     * `admin.integrations.` لا يُحرس ولا يُذكر هنا يُسقط الاختبار.
     */
    public const OPEN_INTEGRATIONS = ['admin.integrations.paymob', 'admin.integrations.paymob.save'];

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

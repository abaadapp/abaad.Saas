<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use App\Support\Reports;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التقارير التي صار لكلٍّ منها صفحته — وسائل الدفع، وأداء الموظفين،
 * والعملاء الأكثر إنفاقًا.
 *
 * وكانت ثلاثتها تُعرض في نافذةٍ واحدة (ReportViewer): قالبٌ واحد يرسم
 * أعمدةً وصفوفًا لأيٍّ منها. فلا مبدّلَ فترةٍ فوقها — كانت محسوبةً على
 * الشهر الجاري وحده ولا شيء على الشاشة يقول ذلك — ولا مؤشّراتٍ تُقرأ
 * بنظرة، ولا رابطٌ يُرسَل لأحدٍ ليفتح ما فتحتَه.
 *
 * ولكلٍّ الآن فترتُه في رابطه، ومؤشّراتُه فوق جدوله.
 */
class ReportPageController extends Controller
{
    /**
     * الحارس هنا لا في المسار وحده.
     *
     * حارس المسار يشتقّ القسم من اسم المسار، فكلّ ما تحت `admin.reports.*`
     * يُقاس بصلاحية «التقارير». وهذه قراءاتٌ على أقسامٍ أخرى: مقبوضاتُ
     * الصندوق، ومبيعاتُ كل موظف، وإنفاقُ كل عميل. فمن مُنح «التقارير»
     * وحدها كان يقرؤها كلّها بكتابة عنوانها.
     */
    private function guard(string $route): void
    {
        $section = Reports::sectionForRoute($route);
        abort_if($section === null, 404);
        abort_unless(
            auth()->user()?->allows($section),
            403,
            __('ليس لديك صلاحية للوصول إلى قسم «:section».', ['section' => $section]),
        );
    }

    /** الفترة تُردّ إلى المفهوم: مجهولةٌ تسقط إلى الشهر لا إلى «كل الفترات» */
    private function range(Request $request): string
    {
        return Demo::range($request->query('range'));
    }

    /** ما تشترك فيه الصفحات الثلاث: الفترة واسمها وشريط التنقّل */
    private function shell(string $range): array
    {
        return [
            'range' => $range,
            'rangeLabel' => Demo::rangeLabel($range),
            'tabs' => Reports::tabsFor(auth()->user()),
        ];
    }

    public function payments(Request $request): Response
    {
        $this->guard('admin.reports.payments');
        $range = $this->range($request);

        $methods = Demo::paymentMethods($range);
        $total = array_sum(array_column($methods, 'total'));
        $count = array_sum(array_column($methods, 'count'));

        // «النشطة» ما تحرّك منها فعلًا: عددُ الصفوف ثابتٌ مهما كان في الدرج،
        // ورقمٌ لا يتغيّر ليس خبرًا
        $active = count(array_filter($methods, fn ($m) => $m['count'] > 0));
        $top = collect($methods)->sortByDesc('total')->first();

        return Inertia::render('Admin/Reports/Payments', array_merge($this->shell($range), [
            'methods' => $methods,
            'summary' => [
                'total' => round((float) $total, 3),
                'count' => (int) $count,
                'active' => $active,
                'topName' => ($top['count'] ?? 0) > 0 ? $top['name'] : null,
                'topTotal' => ($top['count'] ?? 0) > 0 ? round((float) $top['total'], 3) : 0.0,
            ],
        ]));
    }

    public function staff(Request $request): Response
    {
        $this->guard('admin.reports.staff');
        $range = $this->range($request);

        $rows = Demo::staffPerformance($range);
        $total = array_sum(array_column($rows, 'sales'));

        // المتوسّط على من باع لا على من في الكشف: قسمةُ المبيعات على الجميع
        // تُظهر البائعين أضعف ممّا هم كلّما كبر عدد غير البائعين
        $sellers = array_values(array_filter($rows, fn ($r) => $r['orders'] > 0));

        return Inertia::render('Admin/Reports/Staff', array_merge($this->shell($range), [
            'rows' => $rows,
            'summary' => [
                'total' => round((float) $total, 3),
                'staff' => count($rows),
                'sellers' => count($sellers),
                'average' => count($sellers) > 0 ? round((float) $total / count($sellers), 3) : 0.0,
                'topName' => $sellers[0]['name'] ?? null,
                'topSales' => $sellers[0]['sales'] ?? 0.0,
            ],
        ]));
    }

    public function customers(Request $request): Response
    {
        $this->guard('admin.reports.customers');
        $range = $this->range($request);

        // سقفٌ يُقال على الشاشة لا يُخفى: قائمةُ «الأكثر إنفاقًا» مبتورةٌ
        // عمدًا، ومن لا يعرف السقف يظنّها كلَّ عملائه
        $limit = 50;
        $rows = Demo::topCustomers($limit, $range);
        $total = array_sum(array_column($rows, 'total'));
        $orders = array_sum(array_column($rows, 'orders'));

        return Inertia::render('Admin/Reports/Customers', array_merge($this->shell($range), [
            'rows' => $rows,
            'limit' => $limit,
            'summary' => [
                'total' => round((float) $total, 3),
                'customers' => count($rows),
                'orders' => (int) $orders,
                'average' => $orders > 0 ? round((float) $total / $orders, 3) : 0.0,
            ],
        ]));
    }
}

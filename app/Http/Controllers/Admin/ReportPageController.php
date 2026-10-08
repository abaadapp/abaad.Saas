<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\CostsAndLosses;
use App\Support\Demo;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Http\JsonResponse;
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

    /** ما تشترك فيه صفحات التقارير: الفترة واسمها وشريط التنقّل */
    private function shell(string $range): array
    {
        return [
            'range' => $range,
            'rangeLabel' => Demo::rangeLabel($range),
        ];
    }

    /**
     * تقريرٌ يقرأ بياناته من `ReportData` — الطريق نفسه لعشرة تقارير.
     *
     * والمرشّحات تعود إلى الشاشة كما وصلت: بلا ذلك تُفرَّغ المنتقيات بعد كل
     * تحميل، فيختار التاجر فرعًا فتُعرض بياناته ويقول المنتقي «الكل».
     */
    private function report(Request $request, string $key, string $screen, array $filterKeys = []): Response
    {
        $route = 'admin.reports.'.$key;
        $this->guard($route);

        $range = $this->range($request);
        $filters = ['range' => $range];
        foreach ($filterKeys as $name) {
            $value = $request->query($name);
            $filters[$name] = is_string($value) && $value !== '' ? $value : null;
        }

        $data = ReportData::$key(Demo::bid(), $filters);

        return Inertia::render('Admin/Reports/'.$screen, array_merge($this->shell($range), $data, [
            'filters' => $filters,
            'limit' => ReportData::LIMIT,
        ]));
    }

    /** صافي الربح — والفرعُ مرشِّحُه الوحيد (`ReportData::profit`) */
    public function profit(Request $request): Response
    {
        return $this->report($request, 'profit', 'Profit', ['branch_id']);
    }

    /** مرشّحاتُ التكاليف والخسائر كما وصلت — والتنقيةُ في `CostsAndLosses::scope` */
    private function costFilters(Request $request): array
    {
        $filters = [];
        foreach (['from', 'to', 'branch_id', 'category'] as $name) {
            $value = $request->query($name);
            $filters[$name] = is_string($value) && $value !== '' ? $value : null;
        }

        return $filters;
    }

    /**
     * التكاليفُ والخسائر — من دفتر الأستاذ (`ReportData::costs`).
     *
     * مدّةٌ بحدّين لا فترةٌ مسمّاة: المقارنةُ بالمدّة السابقة المكافئة تحتاج
     * حدّين. والقسمُ «المالية» (`Reports::ALL`): التقريرُ يفتح قيودَ الدفتر
     * سطرًا سطرًا، وهو ما تحرسه شاشةُ القيود نفسُها.
     */
    public function costs(Request $request): Response
    {
        $this->guard('admin.reports.costs');

        $data = ReportData::costs(Demo::bid(), $this->costFilters($request));

        return Inertia::render('Admin/Reports/Costs', $data + [
            'filters' => [
                'from' => $data['scope']['from'],
                'to' => $data['scope']['to'],
                'branch_id' => $data['scope']['branch_id'] !== null ? (string) $data['scope']['branch_id'] : null,
                'category' => $data['scope']['category'],
            ],
        ]);
    }

    /**
     * سطورُ صفٍّ من التكاليف والخسائر — قراءةٌ بالحارس والنطاق نفسيهما.
     *
     * والحسابُ يُسأل عنه في المتجر: معرّفٌ من متجرٍ آخر ⇒ ٤٠٤ لا قائمةٌ فارغة.
     */
    public function costLines(Request $request): JsonResponse
    {
        $this->guard('admin.reports.costs');

        $bid = Demo::bid();
        $scope = CostsAndLosses::scope($bid, $this->costFilters($request), auth()->user());

        $raw = $request->query('account_id');
        $accountId = null;
        if (is_string($raw) && $raw !== '') {
            $id = filter_var($raw, FILTER_VALIDATE_INT);
            abort_if($id === false || ! Account::where('business_id', $bid)->whereKey($id)->exists(), 404);
            $accountId = (int) $id;
        }

        return response()->json(CostsAndLosses::drill($bid, $scope, $accountId, (int) $request->query('page', 1)));
    }

    public function finance(Request $request): Response
    {
        return $this->report($request, 'finance', 'Finance', ['method', 'type', 'q']);
    }

    public function expenses(Request $request): Response
    {
        return $this->report($request, 'expenses', 'Expenses', ['type', 'status']);
    }

    public function bank(Request $request): Response
    {
        return $this->report($request, 'bank', 'Bank', ['match_status']);
    }

    public function orders(Request $request): Response
    {
        return $this->report($request, 'orders', 'Orders', ['status', 'branch_id', 'payment_method', 'fulfillment']);
    }

    public function addons(Request $request): Response
    {
        return $this->report($request, 'addons', 'Addons', ['branch_id', 'channel']);
    }

    public function products(Request $request): Response
    {
        return $this->report($request, 'products', 'Products', ['category_id']);
    }

    public function inventory(Request $request): Response
    {
        return $this->report($request, 'inventory', 'Inventory', ['category_id', 'below']);
    }

    public function purchases(Request $request): Response
    {
        return $this->report($request, 'purchases', 'Purchases', ['status', 'supplier_id']);
    }

    public function suppliers(Request $request): Response
    {
        return $this->report($request, 'suppliers', 'Suppliers');
    }

    public function activity(Request $request): Response
    {
        return $this->report($request, 'activity', 'Activity', ['user_id', 'action']);
    }

    public function marketing(Request $request): Response
    {
        return $this->report($request, 'marketing', 'Marketing');
    }

    public function seasons(Request $request): Response
    {
        return $this->report($request, 'seasons', 'Seasons', ['status']);
    }

    public function stocktake(Request $request): Response
    {
        return $this->report($request, 'stocktake', 'Stocktake', ['branch_id', 'reason']);
    }

    public function payments(Request $request): Response
    {
        return $this->report($request, 'payments', 'Payments');
    }

    public function vat(Request $request): Response
    {
        return $this->report($request, 'vat', 'Vat');
    }

    public function staff(Request $request): Response
    {
        return $this->report($request, 'staff', 'Staff');
    }

    public function customers(Request $request): Response
    {
        return $this->report($request, 'customers', 'Customers');
    }
}

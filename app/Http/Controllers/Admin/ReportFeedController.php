<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * تغذية صفحات التقارير — نفس ما يُرسله PageController عند فتح الصفحة.
 *
 * التقارير تُحتسب لحظة الفتح بلا تخزين مؤقت، فهي صحيحة حين تُفتح ثم تتجمّد.
 * وصفحة تُترك مفتوحة على مكتب التاجر تعرض أرقام الصباح بعد يوم بيع كامل.
 *
 * والحمولة هنا مطابقة لحمولة الصفحة عن قصد: تغذيةٌ تقيس غير ما عُرض أول
 * مرّة تجعل الأرقام تقفز بلا سبب ظاهر.
 */
class ReportFeedController extends Controller
{
    /*
     * الفترة تصل مع التغذية كما وصلت مع الصفحة.
     *
     * وبدونها كانت الشاشة تُحدَّث بعد دقائق على الفترة الافتراضية: يفتح
     * التاجر «اليوم» فيجد أرقامه تنقلب إلى أرقام الشهر بلا أن يلمس شيئًا.
     */
    public function reports(Request $request)
    {
        $range = Demo::range($request->query('range'));

        return $this->feed([
            'summary' => Demo::reportSummary($range),
            'salesSeries' => Demo::salesTrend($range),
            'paymentDistribution' => Demo::paymentDistribution($range),
            'topSellingProducts' => Demo::topSellingProducts(5, $range),
        ]);
    }

    public function analytics(Request $request)
    {
        $range = Demo::range($request->query('range'));

        return $this->feed([
            'periodComparison' => Demo::periodComparison($range),
            'topProducts' => Demo::topProducts($range),
            'topCustomers' => Demo::topCustomers(7, $range),
            'salesByWeekday' => Demo::salesByWeekday($range),
            'salesByHour' => Demo::salesByHour($range),
            'categorySales' => Demo::categorySales($range),
        ]);
    }

    public function profitability(Request $request)
    {
        $range = Demo::range($request->query('range'));

        return $this->feed([
            'summary' => Demo::profitSummary($range),
            'products' => Demo::productProfitability($range),
            'categories' => Demo::categoryProfitability($range),
        ]);
    }

    private function feed(array $payload)
    {
        return response()->json($payload + ['updated_at' => now()->format('H:i:s')]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;

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
    public function reports()
    {
        return $this->feed([
            'summary' => Demo::reportSummary(),
            'salesSeries' => Demo::salesSeries(),
            'paymentDistribution' => Demo::paymentDistribution(),
            'topSellingProducts' => Demo::topSellingProducts(),
        ]);
    }

    public function analytics()
    {
        return $this->feed([
            'periodComparison' => Demo::periodComparison(),
            'topProducts' => Demo::topProducts(),
            'topCustomers' => Demo::topCustomers(),
            'salesByWeekday' => Demo::salesByWeekday(),
            'salesByHour' => Demo::salesByHour(),
            'categorySales' => Demo::categorySales(),
        ]);
    }

    public function profitability()
    {
        return $this->feed([
            'summary' => Demo::profitSummary(),
            'products' => Demo::productProfitability(),
            'categories' => Demo::categoryProfitability(),
        ]);
    }

    private function feed(array $payload)
    {
        return response()->json($payload + ['updated_at' => now()->format('H:i:s')]);
    }
}

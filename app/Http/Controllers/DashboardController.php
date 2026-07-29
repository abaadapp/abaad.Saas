<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use Inertia\Inertia;

/**
 * لوحات التحكم: صفحات Inertia + نقاط نهاية JSON لتحديث البطاقات لحظيًا.
 */
class DashboardController extends Controller
{
    public function adminStats()
    {
        return response()->json([
            'stats' => Demo::adminStats(),
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * لوحة صاحب المتجر — كان جلب البيانات داخل القالب، فنُقل إلى هنا
     * لأن Inertia يمرّر البيانات من الخادم لا من داخل العرض.
     */
    public function admin()
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => Demo::adminStats(),
            'salesSeries' => Demo::salesSeries(),
            'paymentDistribution' => Demo::paymentDistribution(),
            'smartAlerts' => Demo::smartAlerts(),
            // أحدث 6 طلبات وأفضل 5 منتجات وموظفين — ما تعرضه اللوحة فقط
            'recentOrders' => collect(Demo::orders())->take(6)->values()->all(),
            'topProducts' => collect(Demo::products())->take(5)->values()->all(),
            'topEmployees' => collect(Demo::employees())->take(5)->values()->all(),
        ]);
    }

    public function superStats()
    {
        return response()->json([
            'stats' => Demo::superStats(),
            'updated_at' => now()->format('H:i:s'),
        ]);
    }
}

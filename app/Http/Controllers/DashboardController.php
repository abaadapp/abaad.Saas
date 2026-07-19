<?php

namespace App\Http\Controllers;

use App\Support\Demo;

/**
 * نقاط نهاية JSON لتحديث بطاقات الإحصائيات لحظيًا (بدون إعادة تحميل الصفحة).
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

    public function superStats()
    {
        return response()->json([
            'stats' => Demo::superStats(),
            'updated_at' => now()->format('H:i:s'),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\DashboardMetrics;
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
            // بطاقات اختيارية من مقاييس التقارير — تُرسل كاملةً وتختار الواجهة
            // منها: سبعة تجميعات خفيفة، وطلبُ الخادم عند كل إضافة أثقل منها
            'statCatalog' => \App\Support\AlertMetrics::catalog(Demo::bid()),
            // مخططات اللوحة تتبع الفرع المختار مثل البطاقات وأحدث الطلبات.
            // التقارير العامة تبقى مستقلة عن هذا العقد ولا نغيّر معناها هنا.
            'salesSeries' => DashboardMetrics::salesYear(),
            'paymentDistribution' => DashboardMetrics::paymentDistribution(),
            /*
             * أحدث ستّة طلباتٍ وأفضل خمسة أصنافٍ وأعلى خمسةِ موظّفين.
             *
             * والثلاثةُ كانت تُقتطع في PHP من جداولَ تُحمَّل كاملة: كلُّ طلبٍ
             * مباعٍ في المتجر ليُعرض ستّة، وكلُّ صنفٍ بمقاساته وإضافاته
             * ووصفاته ليُعرض خمسة.
             *
             * وأسوأُ من ثمنِها أنّ اثنتين منها لم تكونا ما يقول اسمُهما:
             * «أفضل المنتجات» كانت أقدمَ خمسةِ أصنافٍ بالمعرّف — لا تُرتَّب
             * ببيعٍ ولا تُذكر فيها كميّة — و«أداء الموظفين» أقدمَ خمسةِ
             * موظّفين. ودالّةُ الأفضل مبيعًا موجودةٌ في النظام تخدم التقارير
             * منذ بُنيت، ولم تكن اللوحة تناديها.
             */
            'recentOrders' => Demo::orders(null, 6),
            'topProducts' => Demo::topSellingProducts(5, 'month', Demo::currentBranchId()),
            // والترتيبُ بما حقّقه هذا الشهر — وعددُ الموظّفين محدودٌ بالباقة
            'topEmployees' => collect(Demo::employees())
                ->sortByDesc('achieved')->take(5)->values()->all(),
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

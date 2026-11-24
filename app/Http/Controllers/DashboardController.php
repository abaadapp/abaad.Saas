<?php

namespace App\Http\Controllers;

use App\Support\DashboardMetrics;
use App\Support\Demo;
use App\Support\PlatformMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * لوحات التحكم: صفحات Inertia + نقاط نهاية JSON لتحديث البطاقات والرسوم لحظيًا.
 */
class DashboardController extends Controller
{
    public function adminStats()
    {
        // البطاقاتُ والرسمان معًا — انظر `DashboardMetrics::snapshot`
        return response()->json(DashboardMetrics::snapshot() + [
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * لوحة صاحب المتجر — كان جلب البيانات داخل القالب، فنُقل إلى هنا
     * لأن Inertia يمرّر البيانات من الخادم لا من داخل العرض.
     */
    public function admin()
    {
        return Inertia::render('Admin/Dashboard', DashboardMetrics::snapshot() + [
            // بطاقات اختيارية من مقاييس التقارير — تُرسل كاملةً وتختار الواجهة
            // منها: سبعة تجميعات خفيفة، وطلبُ الخادم عند كل إضافة أثقل منها
            'statCatalog' => \App\Support\AlertMetrics::catalog(Demo::bid()),
            /*
             * تنبيهُ بيانات العملاء — عددُ من تحتاج أرقامُهم مراجعة، من متجره وحده.
             *
             * هادئٌ في الصفحة لا في الجرس، ولمن يدخل قسم العملاء وحده: زرُّه
             * يفتح قائمتَهم، ومن لا يدخلها لا يُدعى إليها. والعددُ محفوظٌ عشر
             * دقائق ويُمحى بحفظ أيّ عميل (`CustomerPhone::reviewCount`).
             *
             * ومن أغلقه في جلسته هذه لا يُرسل إليه حتّى يخرج ويدخل (`dismissPhoneReview`).
             */
            'customerPhoneReview' => auth()->user()?->allows('customers') && ! session()->has(self::phoneReviewKey())
                ? \App\Support\CustomerPhone::reviewCount(Demo::bid())
                : 0,
            // والبطاقاتُ والرسمان في اللقطة أعلاه: تتبع الفرعَ المختار كأحدث
            // الطلبات، وتتحدّث معًا في نبضة `adminStats`. والتقارير العامة
            // تبقى مستقلة عن هذا العقد ولا نغيّر معناها هنا.
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
            /*
             * والترتيبُ بما حقّقه هذا الشهر — **في الفرع المختار**.
             *
             * كانت تقرأ المتجر كلَّه بينما بطاقةُ «مبيعات الشهر» فوقها تقرأ
             * الفرع، و«أفضل المنتجات» بجانبها تُنادى بالفرع صراحةً. فتقول
             * الشاشةُ الواحدة رقمين لشهرٍ واحد — ولا شيء فيها يقول أيُّهما
             * على أيّ نطاق.
             */
            'topEmployees' => collect(Demo::employees(Demo::currentBranchId()))
                ->sortByDesc('achieved')->take(5)->values()->all(),
        ]);
    }

    /**
     * إغلاقُ تنبيه بيانات العملاء من لوحة التحكم — إلى أن تنتهي هذه الجلسة.
     *
     * في الجلسة لا في القاعدة: الخروجُ يُبطلها (`LoginController::logout`)
     * فيعود التنبيهُ مع الدخول التالي ما بقي من يحتاج مراجعة. وهو لوحة التحكم
     * وحدها: قسمُ العملاء يقول العددَ نفسَه كما كان.
     */
    public function dismissPhoneReview(Request $request): RedirectResponse
    {
        // بابُ من يُعرض له التنبيهُ نفسُه — وقسمُ اللوحة يسأله `CheckAbility` قبله
        abort_unless($request->user()->allows('customers'), 403);

        $request->session()->put(self::phoneReviewKey(), true);

        return to_route('admin.dashboard');
    }

    /** لصاحبه في متجره: من تبدّل حسابُه أو متجرُه في الجلسة نفسِها لا يرث إغلاقَ غيره */
    private static function phoneReviewKey(): string
    {
        return 'phone_review_dismissed.'.Demo::bid().'.'.auth()->id();
    }

    public function superStats()
    {
        // البطاقاتُ والرسمان معًا — انظر `PlatformMetrics::dashboard`
        return response()->json(PlatformMetrics::dashboard() + [
            'updated_at' => now()->format('H:i:s'),
        ]);
    }
}

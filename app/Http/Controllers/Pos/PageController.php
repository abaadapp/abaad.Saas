<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحات عرض نقطة البيع — نُقل جلب البيانات من القوالب إلى هنا.
 */
class PageController extends Controller
{
    /** إعدادات الولاء بنفس الافتراضيات التي كانت في القالب */
    private function loyaltySettings(): array
    {
        $s = Demo::businessSettings();

        return [
            'loyaltyEnabled' => ($s['loyalty_enabled'] ?? '1') !== '0',
            'redeemMaxPct' => (float) ($s['loyalty_redeem_max_pct'] ?? 50),
            'earnRate' => (float) ($s['loyalty_earn_rate'] ?? 5),
            'redeemMin' => (float) ($s['loyalty_redeem_min'] ?? 100),
        ];
    }

    /**
     * شاشة البيع لا تُفتح قبل أن يُعرف من يقف على الصندوق.
     *
     * البوابة هنا لا في middleware عام: بقيّة صفحات نقطة البيع (الطلبات،
     * الفواتير، العملاء) عرضٌ لا بيع، وحصرُها خلف الاختيار يجعل صاحب النشاط
     * يختار موظفًا لمجرّد أن يطالع الفواتير — وهو عكس المقصود.
     */
    public function index(): Response|\Illuminate\Http\RedirectResponse
    {
        if (\App\Support\PosCashier::required()) {
            return redirect()->route('pos.cashier');
        }

        /*
         * لا شاشة بيع بلا وردية مفتوحة.
         *
         * الترتيب مقصود: يُختار الواقف على الصندوق أولًا، لأن الوردية تُسجَّل
         * باسمه. والتوجيه هنا لا تعطيلُ زرٍّ في الواجهة: الكاشير يجد نفسه
         * أمام شاشة الفتح فيفتح ويعود، بدل أن يجمع سلّة ثم يُرفض دفعُها.
         */
        if (! \App\Support\Shifts::isOpen() && \App\Support\Shifts::blocksSelling()) {
            return redirect()->route('pos.shift');
        }

        return Inertia::render('Pos/Index', [
            // رصيد الفرع الذي سيُخصم منه البيع، لا مجموع الشركة
            'products' => Demo::products(Demo::activeBranchId()),
            'categories' => Demo::posCategories(),
            'customers' => Demo::customers(),
            'addons' => Demo::addons(),
            'coupons' => Demo::activeCoupons(),
            // سلة مستعادة من طلب معلّق (تُمرَّر عبر الجلسة من PosController::resume)
            'resumeCart' => session('resume_cart'),
            'settings' => $this->loyaltySettings(),
        ]);
    }

    public function orders(): Response
    {
        return Inertia::render('Pos/Orders', [
            'heldOrders' => Demo::heldOrders(),
        ]);
    }

    public function orderDetails(string $number): Response
    {
        $order = Demo::orderDetails($number);
        abort_if(empty($order), 404);

        return Inertia::render('Pos/OrderDetails', [
            'order' => $order,
        ]);
    }

    /**
     * مقبوضات الوردية المفتوحة — لا آخر ثلاثين فاتورة.
     *
     * كانت تعرض آخر ٣٠ فاتورة للفرع بلا حدٍّ زمني، وهي شاشة تقفيل صندوق:
     * فمحلٌّ يبيع ٥٠ مرّة يوميًّا كان يرى جزء يومه، ومحلٌّ يبيع ٥ مرّات يرى
     * ستّة أيام مخلوطة. وفي الحالتين لا يطابق الرقم ما في الدرج — رقمٌ يبدو
     * دقيقًا وهو ليس كذلك، وذلك أسوأ من غياب الشاشة.
     */
    public function payments(): Response
    {
        $shift = \App\Support\Shifts::current();

        return Inertia::render('Pos/Payments', [
            // سقفٌ يسع يومًا مزدحمًا: بترُ الوردية عند ٣٠ يُنقص المجموع بلا أن يقول
            'receipts' => $shift ? Demo::receipts(limit: 500, shiftId: $shift->id) : [],
            'shift' => $shift ? [
                'opened_at' => $shift->opened_at?->format('Y-m-d H:i'),
                'opening_balance' => (float) $shift->opening_balance,
                'expected' => \App\Support\Shifts::expectedCash($shift),
            ] : null,
        ]);
    }

    public function receipts(): Response
    {
        return Inertia::render('Pos/Receipts', [
            // المبالغ تُنزع للكاشير من الحمولة نفسها، لا من الجدول فقط
            'receipts' => \App\Support\ReceiptVisibility::filter(Demo::receipts()),
            'showsAmounts' => \App\Support\ReceiptVisibility::showsAmounts(),
            'branchName' => Demo::currentBranchName(),
        ]);
    }

    public function customers(): Response
    {
        return Inertia::render('Pos/Customers', [
            'customers' => Demo::customers(),
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('Pos/Settings', [
            'settings' => Demo::businessSettings(),
            'branchName' => Demo::currentBranchName(),
        ]);
    }
}

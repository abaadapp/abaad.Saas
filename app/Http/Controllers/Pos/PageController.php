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

    public function index(): Response
    {
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

    public function orderDetails(string $id): Response
    {
        $order = Demo::orderDetails($id);
        abort_if(empty($order), 404);

        return Inertia::render('Pos/OrderDetails', [
            'order' => $order,
        ]);
    }

    public function payments(): Response
    {
        return Inertia::render('Pos/Payments', [
            'receipts' => Demo::receipts(),
        ]);
    }

    public function receipts(): Response
    {
        return Inertia::render('Pos/Receipts', [
            'receipts' => Demo::receipts(),
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

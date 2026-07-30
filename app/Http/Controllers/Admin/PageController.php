<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحات العرض في لوحة صاحب المتجر.
 *
 * كانت كلها Route::view تجلب بياناتها من داخل قوالب Blade. Inertia يمرّر
 * البيانات من الخادم، فنُقل الجلب إلى هنا كما هو — نفس الدوال ونفس النتائج.
 */
class PageController extends Controller
{
    /* ------------------------------ المنتجات ------------------------------ */

    public function productsCreate(): Response
    {
        return Inertia::render('Admin/Products/Create', [
            'categories' => Demo::categories(),
        ]);
    }

    public function productsShow(string $id): Response
    {
        $product = Demo::product($id);
        abort_if(empty($product), 404);

        return Inertia::render('Admin/Products/Show', [
            'product' => $product,
            'sold' => Demo::productSold($id),
            'movements' => Demo::movements(),
        ]);
    }

    public function productsEdit(string $id): Response
    {
        $product = Demo::product($id);
        abort_if(empty($product), 404);

        return Inertia::render('Admin/Products/Edit', [
            'product' => $product,
            'categories' => Demo::categories(),
        ]);
    }

    public function productsBarcodes(): Response
    {
        return Inertia::render('Admin/Products/Barcodes', [
            'products' => Demo::products(),
        ]);
    }

    /* ----------------------------- التصنيفات ----------------------------- */

    public function categoriesIndex(): Response
    {
        return Inertia::render('Admin/Categories/Index', [
            'categories' => Demo::categories(),
        ]);
    }

    public function categoriesCreate(): Response
    {
        return Inertia::render('Admin/Categories/Create', [
            'categories' => Demo::categories(),
        ]);
    }

    /* ------------------------------ الإضافات ------------------------------ */

    public function addonsIndex(): Response
    {
        return Inertia::render('Admin/Addons/Index', [
            'addons' => Demo::addons(),
            'emojiGroups' => self::emojiGroups(),
        ]);
    }

    /** مجموعات الإيموجي بصيغة منتقي الواجهة — مصدرها App\Support\Emojis وحدها */
    private static function emojiGroups(): array
    {
        $out = [];
        foreach (\App\Support\Emojis::groups() as $label => $items) {
            $out[__($label)] = array_map(fn ($it) => ['e' => $it[0], 'k' => mb_strtolower($it[1])], $items);
        }

        return $out;
    }

    /* ------------------------------- الفروع ------------------------------- */

    public function branchesIndex(): Response
    {
        return Inertia::render('Admin/Branches/Index', [
            'branches' => Demo::branches(),
        ]);
    }

    /* ------------------------------ الطلبات ------------------------------ */

    public function ordersShow(string $id): Response
    {
        $order = Demo::orderDetails($id);
        abort_if(empty($order), 404);

        return Inertia::render('Admin/Orders/Show', [
            'order' => $order,
        ]);
    }

    /* ------------------------------ العملاء ------------------------------ */

    public function customersShow(string $id): Response
    {
        $customer = Demo::customer($id);
        abort_if(empty($customer), 404);

        return Inertia::render('Admin/Customers/Show', [
            'customer' => $customer,
            'orders' => Demo::customerOrders($id),
        ]);
    }

    /* ----------------------------- الموظفون ----------------------------- */

    public function employeesIndex(): Response
    {
        return Inertia::render('Admin/Employees/Index', [
            'employees' => Demo::employees(),
        ]);
    }

    public function employeesCreate(): Response
    {
        return Inertia::render('Admin/Employees/Create', [
            'branches' => Demo::branches(),
            'currentBranchName' => Demo::currentBranchName(),
        ]);
    }

    public function employeesShow(string $id): Response
    {
        $employee = Demo::employee($id);
        abort_if(empty($employee), 404);

        return Inertia::render('Admin/Employees/Show', [
            'employee' => $employee,
            'orderCount' => Demo::employeeOrderCount($id),
        ]);
    }

    /* ------------------------------ المخزون ------------------------------ */

    public function inventoryIndex(): Response
    {
        return Inertia::render('Admin/Inventory/Index', [
            'inventory' => Demo::inventory(),
            'branches' => Demo::branches(),
            'currentBranchId' => Demo::currentBranchId(),
        ]);
    }

    public function inventoryMovements(): Response
    {
        return Inertia::render('Admin/Inventory/Movements', [
            'movements' => Demo::movements(),
            'products' => Demo::products(),
            'branches' => Demo::branches(),
            'currentBranchId' => Demo::currentBranchId(),
        ]);
    }

    /* ----------------------- المورّدون وأوامر الشراء ----------------------- */

    public function suppliersIndex(): Response
    {
        return Inertia::render('Admin/Suppliers/Index', [
            'suppliers' => Demo::suppliers(),
        ]);
    }

    public function purchasesIndex(): Response
    {
        $s = Demo::purchaseOrderStats();

        return Inertia::render('Admin/Purchases/Index', [
            'stats' => [
                ['label' => __('إجمالي الأوامر'), 'value' => (string) $s['total'], 'icon' => 'clipboard-list', 'color' => 'primary'],
                ['label' => __('قيد التنفيذ'), 'value' => (string) $s['pending'], 'icon' => 'clock', 'color' => 'warning'],
                ['label' => __('مستلمة'), 'value' => (string) $s['received'], 'icon' => 'package-check', 'color' => 'success'],
                ['label' => __('قيمة قيد الاستلام'), 'value' => Demo::money($s['value']), 'icon' => 'wallet', 'color' => 'info'],
            ],
            // رابط الإيصال يُبنى هنا: المسار وحده لا يكفي المتصفح لفتحه
            'orders' => array_map(function ($o) {
                $o['receipt'] = $o['receipt']
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($o['receipt'])
                    : null;

                return $o;
            }, Demo::purchaseOrders()),
            'reorder' => Demo::reorderSuggestions(),
        ]);
    }

    public function purchasesCreate(): Response
    {
        return Inertia::render('Admin/Purchases/Create', [
            'suppliers' => Demo::suppliers(),
            'products' => Demo::products(),
            'reorderSuggestions' => Demo::reorderSuggestions(),
            'branches' => Demo::branches(),
            'currentBranchId' => Demo::currentBranchId(),
        ]);
    }

    /* ------------------------- المالية والتقارير ------------------------- */

    public function financeIndex(): Response
    {
        return Inertia::render('Admin/Finance/Index', [
            'financeStats' => Demo::financeStats(),
            'profitStats' => Demo::profitStats(),
            'paymentMethods' => Demo::paymentMethods(),
            'transactions' => Demo::transactions(),
        ]);
    }

    public function financeStatement(): Response
    {
        return Inertia::render('Admin/Finance/Statement', [
            'account' => Demo::bankAccount(),
            'statement' => Demo::bankStatement(),
            'lines' => Demo::bankLines(),
            'reconciliation' => Demo::reconciliationSummary(),
        ]);
    }

    public function reportsIndex(): Response
    {
        return Inertia::render('Admin/Reports/Index', [
            'summary' => Demo::reportSummary(),
            'salesSeries' => Demo::salesSeries(),
            'paymentDistribution' => Demo::paymentDistribution(),
            'topSellingProducts' => Demo::topSellingProducts(),
        ]);
    }

    public function analytics(): Response
    {
        return Inertia::render('Admin/Analytics', [
            'periodComparison' => Demo::periodComparison(),
            'topProducts' => Demo::topProducts(),
            'topCustomers' => Demo::topCustomers(),
            'salesByWeekday' => Demo::salesByWeekday(),
            'salesByHour' => Demo::salesByHour(),
            'categorySales' => Demo::categorySales(),
        ]);
    }

    public function profitability(): Response
    {
        return Inertia::render('Admin/Profitability', [
            'summary' => Demo::profitSummary(),
            'products' => Demo::productProfitability(),
            'categories' => Demo::categoryProfitability(),
        ]);
    }

    public function marketing(): Response
    {
        return Inertia::render('Admin/Marketing', [
            'stats' => Demo::couponStats(),
            'coupons' => Demo::coupons(),
            'segments' => Demo::marketingSegment(),
        ]);
    }

    public function vat(): Response
    {
        return Inertia::render('Admin/Vat', [
            'report' => Demo::vatReport(),
            'settings' => Demo::vatSettings(),
        ]);
    }

    /* ----------------------------- الإعدادات ----------------------------- */

    public function settingsIndex(): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'notifications' => Demo::allNotifications(),
            'settings' => Demo::businessSettings(),
        ]);
    }
}

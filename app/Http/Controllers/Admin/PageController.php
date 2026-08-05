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

        $margin = $product['price'] - $product['cost'];
        $marginPct = $product['price'] > 0 ? round(($margin / $product['price']) * 100) : 0;

        return Inertia::render('Admin/Products/Show', [
            'product' => $product,
            'stats' => [
                ['label' => __('الكمية المتوفرة'), 'value' => __(':n قطعة', ['n' => $product['qty']]), 'icon' => 'package', 'color' => 'primary'],
                ['label' => __('إجمالي المبيعات'), 'value' => __(':n قطعة', ['n' => Demo::productSold($id)]), 'icon' => 'shopping-cart', 'color' => 'success'],
                ['label' => __('سعر التكلفة'), 'value' => Demo::money($product['cost']), 'icon' => 'wallet', 'color' => 'info'],
                ['label' => __('هامش الربح'), 'value' => $marginPct . '%', 'icon' => 'trending-up', 'color' => 'primary',
                    'trend' => Demo::money($margin), 'up' => true],
            ],
            // حركات هذا المنتج وحده، لا كل حركات المتجر
            'movements' => array_slice(array_values(array_filter(
                Demo::movements(),
                fn ($m) => $m['product'] === $product['name'],
            )), 0, 6),
            'thumbs' => array_values(array_filter([$product['image']])),
            'description' => $product['description']
                ?? __('باقة أنيقة من الورود الطبيعية الطازجة مناسبة لجميع المناسبات، منسّقة بعناية بأيدي خبراء التنسيق لدينا لتمنح لمسة جمالية مميزة.'),
        ]);
    }

    public function productsEdit(string $id): Response
    {
        $product = Demo::product($id);
        abort_if(empty($product), 404);

        return Inertia::render('Admin/Products/Edit', [
            'product' => $product,
            'categories' => Demo::categories(),
            'description' => $product['description'] ?? '',
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
            'emojiGroups' => self::emojiGroups(),
            'palette' => self::PALETTE,
        ]);
    }

    public function categoriesEdit(string $id): Response
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $category = \App\Models\Category::where('business_id', $bid)->findOrFail($id);

        return Inertia::render('Admin/Categories/Edit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'name_en' => $category->name_en,
                'icon' => $category->icon,
                // اللون قد يكون اسم رمز في الصفوف القديمة، ومنتقي الألوان
                // لا يقبل إلا سداسيًّا — فيُوحَّد قبل أن يصل النموذج
                'color' => Demo::categoryColor($category->color),
                'parent_id' => $category->parent_id,
            ],
            // القسم لا يصلح أبًا لنفسه، فيُستبعَد من القائمة
            'categories' => array_values(array_filter(
                Demo::categories(),
                fn ($c) => $c['id'] !== $category->id,
            )),
            'emojiGroups' => self::emojiGroups(),
            'palette' => self::PALETTE,
        ]);
    }

    /** ألوان الأقسام — نفس اللوحة التي كانت مضمّنة في القالب */
    private const PALETTE = [
        '#7c3aed', '#8b5cf6', '#6366f1', '#3b82f6', '#0ea5e9',
        '#06b6d4', '#14b8a6', '#10b981', '#22c55e', '#84cc16',
        '#eab308', '#f59e0b', '#f97316', '#ef4444', '#e11d48',
        '#db2777', '#d946ef', '#a855f7', '#64748b', '#78716c',
    ];

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

    public function ordersShow(string $number): Response
    {
        $order = Demo::orderDetails($number);
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
            // الافتراضي أولًا ثم الأقدم — ترتيب ثابت لا يقفز بين التحميلات
            'addresses' => \App\Models\CustomerAddress::where('customer_id', $id)
                ->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'city' => $a->city,
                    'area' => $a->area,
                    'street' => $a->street,
                    'is_default' => $a->is_default,
                ])->all(),
        ]);
    }

    /* ----------------------------- الموظفون ----------------------------- */

    public function employeesIndex(): Response
    {
        $bid = Demo::bid();
        // كم موظفًا يشغل كل مسمّى — عمود «الاستخدام» في تبويب الوظائف
        $usage = \App\Models\User::where('business_id', $bid)
            ->selectRaw('job_title, COUNT(*) as c')->groupBy('job_title')->pluck('c', 'job_title');

        return Inertia::render('Admin/Employees/Index', [
            'employees' => Demo::employees(),
            'jobTitles' => \App\Models\JobTitle::where('business_id', $bid)->orderBy('name')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'role' => $t->role,
                    'roleLabel' => \App\Models\JobTitle::ROLES[$t->role] ?? $t->role,
                    'description' => $t->description,
                    'usage' => (int) ($usage[$t->name] ?? 0),
                ])->all(),
            'roleOptions' => collect(\App\Models\JobTitle::ROLES)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all(),
        ]);
    }

    public function employeesCreate(): Response
    {
        return Inertia::render('Admin/Employees/Create', [
            'branches' => Demo::branches(),
            'jobTitles' => self::jobTitles(),
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
            'salesSeries' => Demo::employeeSalesSeries($id),
            // سجل نشاط حقيقي من ActivityLog — القالب القديم كان يعرض قائمة
            // مكتوبة يدويًا («أتمّ طلب بيع بقيمة 45.000») لا تخص أحدًا.
            'activities' => Demo::userActivities((int) $id, 20),
            // الصلاحيات معروضة للاسترشاد؛ الفرض الفعلي يتم بدور الموظف عبر middleware
            'permissions' => [
                'فتح نقطة البيع' => true,
                'إنشاء طلب جديد' => true,
                'تطبيق خصومات' => true,
                'إلغاء طلب' => false,
                'إدارة المنتجات' => false,
                'إدارة المخزون' => true,
                'عرض التقارير' => true,
                'إدارة الموظفين' => false,
                'إدارة المصروفات' => false,
                'تعديل الإعدادات' => false,
            ],
        ]);
    }

    /** مسمّيات الوظائف المعرّفة للنشاط */
    private static function jobTitles(): array
    {
        return \App\Models\JobTitle::where('business_id', Demo::bid())->orderBy('name')->pluck('name')->all();
    }

    /* ------------------------------ المخزون ------------------------------ */

    public function inventoryIndex(): Response
    {
        // الحالات المسموحة فقط — أي قيمة أخرى في الرابط تُهمَل بدل أن تُمرَّر
        $stock = request('stock');
        $allowed = ['متوفر', 'منخفض', 'نفد المخزون'];

        return Inertia::render('Admin/Inventory/Index', [
            'inventory' => Demo::inventory(),
            'branches' => Demo::branches(),
            'currentBranchId' => Demo::currentBranchId(),
            'stockFilter' => in_array($stock, $allowed, true) ? $stock : null,
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

    public function purchasesCreate(\Illuminate\Http\Request $request): Response
    {
        return Inertia::render('Admin/Purchases/Create', [
            'suppliers' => Demo::suppliers(),
            'products' => Demo::products(),
            'reorderSuggestions' => Demo::reorderSuggestions(),
            'branches' => Demo::branches(),
            'currentBranchId' => Demo::currentBranchId(),
            // القدوم من شاشة إعادة الطلب يملأ الأصناف المقترحة مسبقًا
            'fromReorder' => $request->query('from') === 'reorder',
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

    public function vat(\Illuminate\Http\Request $request): Response
    {
        return Inertia::render('Admin/Vat', [
            // الفترة تأتي من الرابط كما كانت في القالب (شهر/ربع/سنة)
            'report' => Demo::vatReport($request->query('period', 'quarter')),
            'settings' => Demo::vatSettings(),
        ]);
    }

    /* ----------------------------- الإعدادات ----------------------------- */

    public function settingsIndex(): Response
    {
        $b = \App\Models\Business::find(Demo::bid());

        return Inertia::render('Admin/Settings/Index', [
            'settings' => Demo::businessSettings(),
            'business' => [
                'name' => $b?->name ?? '',
                'phone' => $b?->phone,
                'email' => $b?->email,
                'address' => $b?->address,
            ],
            // القائمة الكاملة — تبويب «التنبيهات المرسلة» يعرضها بلا اختصار
            'notificationsAll' => Demo::allNotifications(),
        ]);
    }
}

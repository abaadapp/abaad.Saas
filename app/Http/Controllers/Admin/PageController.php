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

    public function productsBarcodes(\Illuminate\Http\Request $request): Response
    {
        // ?ids= — طباعة ملصق صنفٍ بعينه من صفّه في القائمة بدل البحث عنه
        // بين مئة ملصق. وبلا المعامل تُعرض الأصناف كلّها كما كانت.
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));

        $products = collect(Demo::products())
            ->when($ids, fn ($c) => $c->whereIn('id', $ids))
            ->values()->all();

        return Inertia::render('Admin/Products/Barcodes', [
            'products' => $products,
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
        return Inertia::render('Admin/Employees/Index', $this->employeesData());
    }

    /**
     * بيانات قسم الموظفين — تُقرأ من موضعين: صفحتها المستقلّة، ولوحة
     * الإعدادات حيث يُفتح القسم مكانها. وهي هنا مرّةً واحدة فلا تفترق النسختان.
     *
     * @return array<string, mixed>
     */
    private function employeesData(): array
    {
        $bid = Demo::bid();
        // كم موظفًا يشغل كل مسمّى — عمود «الاستخدام» في تبويب الوظائف
        $usage = \App\Models\User::where('business_id', $bid)
            ->selectRaw('job_title, COUNT(*) as c')->groupBy('job_title')->pluck('c', 'job_title');

        return [
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
        ];
    }

    public function employeesCreate(): Response
    {
        return Inertia::render('Admin/Employees/Create', [
            'branches' => Demo::branches(),
            'branchOptions' => \App\Models\Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            'jobTitles' => self::jobTitles(),
            'currentBranchName' => Demo::currentBranchName(),
            'sections' => \App\Support\Permissions::sectionLabels(),
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

    /*
     * أوامر الشراء انتقلت إلى PurchaseOrderController::index.
     *
     * صار للقسم ثلاث شاشات — القائمة والسندات والأوامر — ولكلٍّ منها كتابة،
     * فلم تعد صفحةَ عرضٍ تُقدَّم من هنا.
     */

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

    /*
     * المالية انتقلت إلى App\Http\Controllers\Admin\Finance.
     *
     * صارت خمس شاشات على دفترٍ مزدوج لا شاشتين على جدول معاملات، ولكلٍّ منها
     * كتابةٌ تمرّ بـ`Ledger::post` — فلم تعد صفحاتِ عرضٍ تُقدَّم من هنا.
     */

    /**
     * فهرس التقارير — بابٌ واحد يُعرض منه ما في النظام من تقارير.
     *
     * كانت هذه الصفحة لوحة مبيعاتٍ واحدة، وبقيّة التقارير موزّعةً على أقسام
     * اللوحة لا يجمعها جامع: من يريد الضريبة يعرف أين هي، ومن لا يعرف لا
     * يجدها. صارت اللوحة تقريرًا من التقارير (reportsSales أدناه) والفهرس
     * يعرضها وأخواتها مصنَّفةً وقابلةً للبحث.
     *
     * ولا فترةَ هنا: الفهرس قائمةُ مداخل لا أرقامًا، والفترة تخصّ من يعرض رقمًا.
     */
    public function reportsIndex(): Response
    {
        return Inertia::render('Admin/Reports/Index', [
            'reports' => \App\Support\Reports::forUser(auth()->user()),
            'categories' => \App\Support\Reports::categoryLabels(),
        ]);
    }

    /**
     * ملخّص المبيعات — كلّه على فترةٍ واحدة يختارها التاجر.
     *
     * كانت البطاقات تجمع عمر المتجر كلّه والمخطّط يرسم السنة الجارية — رقمان
     * لفترتين في شاشةٍ واحدة. والفترة في الرابط لا في الجلسة: رابطٌ يُرسَل
     * أو يُحفَظ يفتح على ما فُتح عليه، ولا تتبدّل شاشة أحدٍ لأن آخر بدّلها.
     */
    public function reportsSales(\Illuminate\Http\Request $request): Response
    {
        $range = Demo::range($request->query('range'));

        return Inertia::render('Admin/Reports/Sales', [
            'summary' => Demo::reportSummary($range),
            'salesSeries' => Demo::salesTrend($range),
            'paymentDistribution' => Demo::paymentDistribution($range),
            'topSellingProducts' => Demo::topSellingProducts(5, $range),
            'range' => $range,
        ]);
    }

    public function analytics(\Illuminate\Http\Request $request): Response
    {
        $range = Demo::range($request->query('range'));

        return Inertia::render('Admin/Analytics', [
            'periodComparison' => Demo::periodComparison($range),
            'topProducts' => Demo::topProducts($range),
            'topCustomers' => Demo::topCustomers(7, $range),
            'salesByWeekday' => Demo::salesByWeekday($range),
            'salesByHour' => Demo::salesByHour($range),
            'categorySales' => Demo::categorySales($range),
            'range' => $range,
        ]);
    }

    public function profitability(\Illuminate\Http\Request $request): Response
    {
        $range = Demo::range($request->query('range'));

        return Inertia::render('Admin/Profitability', [
            'summary' => Demo::profitSummary($range),
            'products' => Demo::productProfitability($range),
            'categories' => Demo::categoryProfitability($range),
            'range' => $range,
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

    public function settingsIndex(\Illuminate\Http\Request $request): Response
    {
        $b = \App\Models\Business::find(Demo::bid());
        $section = $request->query('section');
        $section = is_string($section) ? $section : null;

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
            'customAlerts' => \App\Models\CustomAlert::where('business_id', Demo::bid())
                ->orderByDesc('id')->get()->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'message' => $a->message,
                    'section' => $a->section,
                    'metric' => $a->metric,
                    'operator' => $a->operator,
                    'threshold' => $a->threshold === null ? null : (float) $a->threshold,
                    'color' => $a->color,
                    'due_at' => optional($a->due_at)->format('Y-m-d\\TH:i'),
                    'active' => $a->active,
                ])->all(),
            'alertMetrics' => collect(\App\Models\CustomAlert::METRICS)
                ->map(fn ($m, $k) => ['key' => $k, 'label' => __($m['label']), 'unit' => $m['unit'], 'section' => $m['section']])
                ->values()->all(),
            'alertSections' => \App\Support\Permissions::sectionLabels(),
            // قسم «صلاحيات الموظفين»: الموظفون الفعليون وحالة صلاحية كلٍّ منهم
            'staffPermissions' => \App\Models\User::where('business_id', Demo::bid())
                ->where('role', '!=', 'super_admin')->orderBy('name')->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'job_title' => $u->job_title ?: $u->roleLabel(),
                    'manual' => $u->hasManualPermissions(),
                    'count' => count($u->permissions ?? []),
                ])->all(),

            /*
             * أقسام «النظام» تُفتح داخل هذه الصفحة كبقيّة الأقسام.
             *
             * والقسم المطلوب يأتي في الرابط (?section=branches) لا في المرساة،
             * لأن الخادم يحتاج أن يعرفه: بياناته تُحسب هنا. والمرساة لا تصل
             * إلى الخادم أصلًا.
             *
             * ولا تُحسب إلا لقسمها: فتحُ الإعدادات لا يجرّ معه جدول الفروع
             * ولا سجلّ النشاط، ولا يراهما إلا من قصدهما.
             */
            'section' => $section,
            ...$this->settingsSection($section, $request),
        ]);
    }

    /**
     * بيانات قسمٍ من أقسام «النظام» — أو لا شيء.
     *
     * وهي في الرابط لا في الذاكرة عمدًا: بعد إضافة فرعٍ يعود المستخدم بـback()
     * إلى العنوان نفسه بمعاملِه، فتُحسب البيانات ثانيةً ويبقى القسم مفتوحًا
     * على أحدث حال. ولو كانت في المرساة لعاد إلى لوحةٍ فارغة بعد كل حفظ.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function renameKey(array $data, string $from, string $to): array
    {
        if (array_key_exists($from, $data)) {
            $data[$to] = $data[$from];
            unset($data[$from]);
        }

        return $data;
    }

    private function settingsSection(?string $section, \Illuminate\Http\Request $request): array
    {
        return match ($section) {
            'branches' => ['branches' => Demo::branches()],
            'employees' => $this->employeesData(),
            /*
             * الفروع تصل هنا بشكل خيارات (value/label) لا بشكل صفوف الجدول،
             * فتُسمّى باسمٍ آخر. ولولا ذلك لتصادمت مع `branches` في قسم الفروع
             * تصادمًا صامتًا: النوع واحد في TypeScript والحقول مختلفة، فيصير
             * الجدول صفوفًا فارغة بلا خطأٍ يُنبّه.
             */
            'devices' => $this->renameKey(
                \App\Http\Controllers\Pos\DeviceController::panelData(),
                'branches',
                'branchOptions',
            ),
            'activity' => \App\Http\Controllers\ActivityController::adminData($request),
            'trash' => \App\Http\Controllers\Admin\TrashController::panelData(),
            default => [],
        };
    }
}

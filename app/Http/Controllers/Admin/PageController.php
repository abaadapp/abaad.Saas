<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\Finance\ChartController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\DeviceController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomAlert;
use App\Models\CustomerAddress;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use App\Support\Emojis;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\ProductImages;
use App\Support\Reports;
use App\Support\Roles;
use Illuminate\Http\Request;
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
        // التركيب يُملأ مع المنتج: القوائم (المكوّنات والإضافات) تُرسل فارغةَ
        // الصفوف لا فارغةَ الخيارات — فمن يكتب باقةً يختار مكوّناتها الآن
        return Inertia::render('Admin/Products/Create', [
            'categories' => Demo::categories(),
            'composition' => ProductCompositionController::blank(
                auth()->user()->business_id ?? Demo::bid(),
            ),
        ]);
    }

    public function productsShow(string $id): Response
    {
        $product = Demo::product($id);
        abort_if(empty($product), 404);

        // الصفّ نفسه لا نسخته المسطّحة: المعرض علاقةٌ لا عمود
        $model = Product::where('business_id', auth()->user()->business_id ?? Demo::bid())
            ->with('images')->find($id);

        $margin = $product['price'] - $product['cost'];
        $marginPct = $product['price'] > 0 ? round(($margin / $product['price']) * 100) : 0;

        return Inertia::render('Admin/Products/Show', [
            'product' => $product,
            'stats' => [
                ['label' => __('الكمية المتوفرة'), 'value' => __(':n قطعة', ['n' => $product['qty']]), 'icon' => 'package', 'color' => 'primary'],
                ['label' => __('إجمالي المبيعات'), 'value' => __(':n قطعة', ['n' => Demo::productSold($id)]), 'icon' => 'shopping-cart', 'color' => 'success'],
                ['label' => __('سعر التكلفة'), 'value' => Demo::money($product['cost']), 'icon' => 'wallet', 'color' => 'info'],
                ['label' => __('هامش الربح'), 'value' => $marginPct.'%', 'icon' => 'trending-up', 'color' => 'primary',
                    'trend' => Demo::money($margin), 'up' => true],
            ],
            // حركات هذا المنتج وحده، لا كل حركات المتجر
            'movements' => array_slice(array_values(array_filter(
                Demo::movements(),
                fn ($m) => $m['product'] === $product['name'],
            )), 0, 6),
            /*
             * المعرض من بابه الواحد — الرئيسية أوّلًا ثمّ ما بعدها.
             *
             * كان سطرًا يبني قائمةً من صورةٍ واحدة، فالمصفوفة موجودةٌ
             * والمعرض لا. والشاشة تعرض المصغّرات حين تزيد على واحدة —
             * فبقيت لا تعرضها أبدًا.
             */
            'thumbs' => $model
                ? array_column(ProductImages::gallery($model), 'url')
                : array_values(array_filter([$product['image']])),
            'description' => $product['description']
                ?? __('باقة أنيقة من الورود الطبيعية الطازجة مناسبة لجميع المناسبات، منسّقة بعناية بأيدي خبراء التنسيق لدينا لتمنح لمسة جمالية مميزة.'),
        ]);
    }

    public function productsEdit(string $id): Response
    {
        $product = Demo::product($id);
        abort_if(empty($product), 404);

        $model = Product::where('business_id', auth()->user()->business_id ?? Demo::bid())
            ->with('images')->find($id);

        return Inertia::render('Admin/Products/Edit', [
            'product' => $product,
            'categories' => Demo::categories(),
            'description' => $product['description'] ?? '',
            'composition' => $model
                ? ProductCompositionController::payload($model)
                : null,
            /*
             * المعرض يصل إلى شاشة التعديل لتُدار منه الصور.
             *
             * والسقف معه: الشاشة تُخفي زرّ الرفع عند بلوغه بدل أن تقبل الملفّ
             * ثمّ تردّه — ورفضٌ بعد انتظار رفعِ أربعة ميغابايت أسوأ من زرٍّ
             * مطفأ يقول لماذا.
             */
            'gallery' => $model ? ProductImages::gallery($model) : [],
            'galleryMax' => ProductImages::MAX,
            'galleryMaxKb' => ProductImages::MAX_KB,
        ]);
    }

    /* ----------------------------- التصنيفات ----------------------------- */

    /** ألوان الأقسام — نفس اللوحة التي كانت مضمّنة في القالب */
    private const PALETTE = [
        '#7c3aed', '#8b5cf6', '#6366f1', '#3b82f6', '#0ea5e9',
        '#06b6d4', '#14b8a6', '#10b981', '#22c55e', '#84cc16',
        '#eab308', '#f59e0b', '#f97316', '#ef4444', '#e11d48',
        '#db2777', '#d946ef', '#a855f7', '#64748b', '#78716c',
    ];

    /* ------------------------------ الإضافات ------------------------------ */

    /** مجموعات الإيموجي بصيغة منتقي الواجهة — مصدرها App\Support\Emojis وحدها */
    private static function emojiGroups(): array
    {
        $out = [];
        foreach (Emojis::groups() as $label => $items) {
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
            'addresses' => CustomerAddress::where('customer_id', $id)
                ->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'city' => $a->city,
                    'area' => $a->area,
                    'street' => $a->street,
                    'is_default' => $a->is_default,
                ])->all(),
            // للتعديل: الفرع يُختار من فروع هذا النشاط لا من قائمةٍ مفتوحة
            'branches' => Demo::branches(),
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
        $usage = User::where('business_id', $bid)
            ->selectRaw('job_title, COUNT(*) as c')->groupBy('job_title')->pluck('c', 'job_title');

        return [
            'employees' => Demo::employees(),
            'jobTitles' => JobTitle::where('business_id', $bid)->orderBy('name')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'role' => $t->role,
                    'roleLabel' => Roles::label($t->role),
                    'description' => $t->description,
                    'usage' => (int) ($usage[$t->name] ?? 0),
                ])->all(),
        ];
    }

    public function employeesCreate(): Response
    {
        return Inertia::render('Admin/Employees/Create', [
            'branches' => Demo::branches(),
            'branchOptions' => Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            'jobTitles' => self::jobTitles(),
            'currentBranchName' => Demo::currentBranchName(),
            'sections' => Permissions::sectionLabels(),
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
        return JobTitle::where('business_id', Demo::bid())->orderBy('name')->pluck('name')->all();
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

    public function purchasesCreate(Request $request): Response
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

    /*
     * التسويق انتقل إلى App\Http\Controllers\Admin\Marketing.
     *
     * صار ستّ أدوات تُفتح كلٌّ منها بعنوانها، لا صفحةً جامعة تُفتح فيها
     * الكوبونات ويُبحث عن الباقي.
     */

    /* ----------------------------- الإعدادات ----------------------------- */

    /**
     * فهرس التقارير — بابٌ واحد لما كان مبعثرًا في اثنتي عشرة شاشة.
     *
     * ولا يعرض إلا ما يفتحه صاحبه: `Reports::forUser` يرشّح بالصلاحية، فلا
     * يرى المحاسب بطاقةً تقوده إلى ٤٠٣. والقائمة الجانبية تُخفي ما لا يُملك
     * منذ البداية، فلولا الترشيح لافترق البابان على الشيء نفسه.
     */
    public function reportsIndex(): Response
    {
        return Inertia::render('Admin/Reports/Index', [
            'reports' => Reports::forUser(auth()->user()),
            'categories' => Reports::categoryLabels(),
        ]);
    }

    /**
     * ملخّص المبيعات — كلّه على فترةٍ واحدة يختارها التاجر.
     *
     * كانت البطاقات تجمع عمر المتجر كلّه والمخطّط يرسم السنة الجارية — رقمان
     * لفترتين في شاشةٍ واحدة. والفترة في الرابط لا في الجلسة: رابطٌ يُرسَل
     * أو يُحفَظ يفتح على ما فُتح عليه، ولا تتبدّل شاشة أحدٍ لأن آخر بدّلها.
     */
    public function reportsSales(Request $request): Response
    {
        // الحمولة من Support\Reports لا تُجمع هنا: الملفّات الثلاثة تقرأ
        // المصدر نفسه، فلا يخرج ملفٌّ بغير ما على الشاشة
        return Inertia::render('Admin/Reports/Sales', Reports::salesReport($request->query('range')));
    }

    public function settingsIndex(Request $request): Response
    {
        $b = Business::find(Demo::bid());
        $section = $request->query('section');
        $section = is_string($section) ? $section : null;

        return Inertia::render('Admin/Settings/Index', [
            'settings' => Demo::businessSettings(),
            'business' => [
                'name' => $b?->name ?? '',
                'phone' => $b?->phone,
                'email' => $b?->email,
                'address' => $b?->address,
                'logo' => \App\Http\Controllers\SuperAdmin\PageController::logoUrl($b?->logo),
            ],
            /*
             * إعدادات الموقع والنطاق — انتقلت من «أدوات التسويق» إلى هنا.
             *
             * والنطاق إعدادُ متجرٍ لا أداةُ تسويق: يُكتب مرّةً عند التجهيز ثمّ
             * تقرؤه شاشة السيو ورابط «الموقع» في الترويسة. وكان يُطلب من
             * قسمٍ لا يفتحه إلا من يبحث عن الكوبونات.
             *
             * وتُرسل دائمًا لا عند طلب قسمها: ثمانية مفاتيح نصّية، وطلبُها
             * برحلةٍ إلى الخادم أغلى من إرسالها.
             */
            'site' => MarketingSettings::group(Demo::bid(), 'website'),
            /*
             * بريد الاستعادة — حالُه وحده، بلا رمزٍ ولا بصمة.
             *
             * ويُرسَل دائمًا لا عند طلب قسمه: أربعة حقول، وهو أوّل ما يجب أن
             * يراه صاحبُ حسابٍ لم يضبطه بعد.
             */
            'recovery' => RecoveryEmailController::view(auth()->user()),
            // القائمة الكاملة — تبويب «التنبيهات المرسلة» يعرضها بلا اختصار
            'notificationsAll' => Demo::allNotifications(),
            'customAlerts' => CustomAlert::where('business_id', Demo::bid())
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
            'alertMetrics' => collect(CustomAlert::METRICS)
                ->map(fn ($m, $k) => ['key' => $k, 'label' => __($m['label']), 'unit' => $m['unit'], 'section' => $m['section']])
                ->values()->all(),
            'alertSections' => Permissions::sectionLabels(),
            // قسم «صلاحيات الموظفين»: الموظفون الفعليون وحالة صلاحية كلٍّ منهم
            'staffPermissions' => User::where('business_id', Demo::bid())
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

    private function settingsSection(?string $section, Request $request): array
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
                DeviceController::panelData(),
                'branches',
                'branchOptions',
            ),
            'activity' => ActivityController::adminData($request),
            'trash' => TrashController::panelData(),
            /*
             * الشجرة صلاحيتها «المالية» لا «الإعدادات».
             *
             * المسار هنا `admin.settings.index`، و`CheckAbility` يشتقّ القسم
             * من اسم المسار — فلولا هذا الفحص لقرأ كلُّ من يملك الإعدادات
             * أرصدةَ الدفتر وميزانَ المراجعة من بابٍ خلفيّ، وهو ما يُمنع منه
             * في `‎/finance/chart‎` نفسه. والأزرار بعدها ترفض بـ403: شاشةٌ
             * تُعرض ولا يعمل فيها شيء.
             */
            'chart' => $request->user()?->allows('finance')
                ? ChartController::panelData(Demo::bid())
                : [],
            default => [],
        };
    }
}

<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Setting;
use App\Support\BusinessTypes;
use App\Support\Demo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحات العرض في لوحة المنصة.
 *
 * كانت كلها Route::view تجلب بياناتها من داخل قوالب Blade، على غرار ما كان
 * في لوحة صاحب المتجر. Inertia يمرّر البيانات من الخادم، فنُقل الجلب إلى هنا.
 */
class PageController extends Controller
{
    /**
     * مدن القائمة. أنواع الأنشطة لم تعد هنا: النوع صار يقرّر تصنيفات البداية
     * التي يُجهَّز بها المتجر، فمصدره الوحيد BusinessTypes::TYPES كي لا تفترق
     * قائمة الاختيار عن القائمة التي لها بذور.
     */
    private const CITIES = ['مسقط', 'صلالة', 'صحار', 'نزوى', 'صور'];

    private const STATUSES = ['نشط', 'منتهي', 'معطل'];

    /**
     * عرض صفحة منصة.
     *
     * تُلحق عملة العرض بكل صفحة: مدير المنصة بلا business_id فـcontext
     * المشترك يأتي null، ولا تجد الواجهة عملة تُنسّق بها المبالغ. المصدر هنا
     * هو Demo::displayCurrency نفسه الذي كانت Demo::money تستخدمه في القوالب،
     * فيخرج نفس النص حرفًا بحرف.
     */
    private function page(string $component, array $props): Response
    {
        return Inertia::render($component, [...$props, 'currency' => Demo::displayCurrency()]);
    }

    /* ------------------------------ الرئيسية ------------------------------ */

    public function dashboard(): Response
    {
        return $this->page('Platform/Dashboard', [
            'stats' => Demo::superStats(),
            'revenueSeries' => Demo::revenueSeries(),
            'growthSeries' => Demo::businessesGrowthSeries(),
            'latestBusinesses' => array_slice(Demo::businesses(), 0, 6),
            'activities' => Demo::activities(),
            'expiringSubscriptions' => array_slice(Demo::subscriptions(), 0, 5),
        ]);
    }

    /* ------------------------------ الشركات ------------------------------ */

    public function businessesCreate(): Response
    {
        return $this->page('Platform/Businesses/Create', [
            'options' => $this->businessOptions(),
        ]);
    }

    public function businessesShow(string $id): Response
    {
        $business = Demo::business($id);
        abort_if(empty($business), 404);

        $counts = Demo::businessCounts($business['id']);
        $overview = Demo::businessOverview($business['id']);
        $model = \App\Models\Business::with('plan')->findOrFail($business['id']);

        return $this->page('Platform/Businesses/Show', [
            'business' => [
                ...$business,
                'logo' => self::logoUrl($business['logo']),
                // بريد الدخول — أوّل ما يُسأل عنه حين يتصل التاجر
                'owner_email' => \App\Support\MerchantAccount::owner($model)?->email,
            ],
            'subscription' => collect(Demo::subscriptions())->firstWhere('business', $business['name']),
            'stats' => [
                ['label' => __('الفروع'), 'value' => (string) $business['branches'], 'icon' => 'git-branch', 'color' => 'primary'],
                ['label' => __('الموظفون'), 'value' => (string) $counts['employees'], 'icon' => 'users', 'color' => 'info'],
                ['label' => __('المنتجات'), 'value' => (string) $counts['products'], 'icon' => 'package', 'color' => 'secondary'],
                ['label' => __('الطلبات'), 'value' => (string) $counts['orders'], 'icon' => 'shopping-bag', 'color' => 'success'],
            ],
            'overview' => [
                'sales' => Demo::money($overview['sales']),
                'orders' => $overview['orders'],
                'average' => Demo::money($overview['average']),
            ],
            // مقيّدة بمعرّف هذه الشركة لا بالنشاط الحالي — انظر Demo::businessOrders
            'branches' => Demo::businessBranches($business['id']),
            'orders' => Demo::businessOrders($business['id']),
            /*
             * الاستهلاك مقابل سقف الباقة.
             *
             * حدٌّ يُفرض عند الإنشاء يجب أن يُرى قبل أن يُصطدَم به: من بلغ
             * سقفه هو المرشَّح للترقية، ولا سبيل لمعرفته إن لم يُعرض.
             */
            'usage' => \App\Support\PlanLimits::usage($model),
            'renewal' => [
                'monthly' => \App\Support\Billing::price($model, 'monthly'),
                'yearly' => \App\Support\Billing::price($model, 'yearly'),
                'endsAt' => optional($model->ends_at)->format('Y-m-d'),
            ],
        ]);
    }

    public function businessesEdit(string $id): Response
    {
        $business = Demo::business($id);
        abort_if(empty($business), 404);

        $model = \App\Models\Business::find($business['id']);

        return $this->page('Platform/Businesses/Edit', [
            'business' => [
                ...$business,
                // العنوان وتاريخا الاشتراك كانت قيمًا ثابتة في القالب
                'address' => $model?->address,
                'plan_id' => $model?->plan_id,
                'starts_at' => optional($model?->starts_at)->format('Y-m-d'),
                'ends_at' => optional($model?->ends_at)->format('Y-m-d'),
                'logo_url' => self::logoUrl($model?->logo),
                // حساب الدخول يُعرض ولا يُعاد إنشاؤه من هنا
                'owner_email' => $model ? \App\Support\MerchantAccount::owner($model)?->email : null,
            ],
            'options' => $this->businessOptions(),
        ]);
    }

    /* --------------------------- الاشتراكات --------------------------- */

    public function subscriptionsIndex(): Response
    {
        $s = Demo::subscriptionStats();

        return $this->page('Platform/Subscriptions/Index', [
            'stats' => [
                ['label' => __('اشتراكات نشطة'), 'value' => (string) $s['active'], 'icon' => 'badge-check', 'color' => 'success'],
                ['label' => __('اشتراكات منتهية'), 'value' => (string) $s['expired'], 'icon' => 'badge-x', 'color' => 'danger'],
                ['label' => __('الإيراد الشهري'), 'value' => Demo::money($s['monthly_revenue']), 'icon' => 'wallet', 'color' => 'warning'],
                ['label' => __('الإيراد السنوي'), 'value' => Demo::money($s['yearly_revenue']), 'icon' => 'trending-up', 'color' => 'primary'],
            ],
            'subscriptions' => Demo::subscriptions(),
            'planNames' => Plan::orderBy('id')->pluck('name')->all(),
            'planOptions' => SubscriptionController::planOptions(),
        ]);
    }

    public function plans(): Response
    {
        return $this->page('Platform/Subscriptions/Plans', [
            'plans' => Plan::orderBy('id')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'monthly' => (float) $p->monthly_price,
                'yearly' => (float) $p->yearly_price,
                'color' => $p->color,
                'popular' => (bool) $p->is_popular,
                'features' => $p->features ?? [],
            ])->all(),
        ]);
    }

    public function invoices(): Response
    {
        $s = Demo::invoiceStats();

        return $this->page('Platform/Subscriptions/Invoices', [
            'stats' => [
                ['label' => __('إجمالي المدفوع'), 'value' => Demo::money($s['paid']), 'icon' => 'circle-check', 'color' => 'success'],
                ['label' => __('إجمالي غير المدفوع'), 'value' => Demo::money($s['unpaid']), 'icon' => 'circle-alert', 'color' => 'danger'],
                ['label' => __('عدد الفواتير'), 'value' => (string) $s['count'], 'icon' => 'file-text', 'color' => 'primary'],
            ],
            'invoices' => Demo::invoices(),
        ]);
    }

    /* ---------------------------- المستخدمون ---------------------------- */

    public function usersShow(string $id): Response
    {
        $user = Demo::platformUser($id);
        abort_if(empty($user), 404);

        $model = \App\Models\User::find($user['id']);

        return $this->page('Platform/Users/Show', [
            'user' => [
                ...$user,
                // القيم القابلة للتعديل تُقرأ من السجل نفسه لا من التسمية المعروضة
                'role_key' => $model?->role,
                // كانت «الباقة الاحترافية» نصًّا ثابتًا تحت اسم الشركة
                'business_plan' => $model?->business?->plan?->name,
            ],
            'activities' => Demo::userActivities($user['id']),
            'roles' => $this->roleOptions(),
            'permissions' => $this->permissionsFor($model?->role),
        ]);
    }

    /**
     * صلاحيات الدور الفعلية للعرض فقط.
     *
     * القالب كان يعرض ثماني خانات اختيار بقيم ثابتة وزرَّ حفظ لا يحفظ شيئًا —
     * يُظهر toast نجاح ثم لا يتغيّر شيء. الصلاحيات هنا مشتقّة من الدور
     * (App\Support\Permissions) وهي مصدر الفرض الحقيقي، فتُعرض كما هي.
     */
    private function permissionsFor(?string $role): array
    {
        $labels = [
            'dashboard' => __('لوحة التحكم'), 'customers' => __('العملاء'), 'products' => __('المنتجات'),
            'orders' => __('الطلبات'), 'marketing' => __('التسويق'), 'inventory' => __('المخزون'),
            'finance' => __('المالية'), 'expenses' => __('المصروفات'), 'reports' => __('التقارير'),
            'settings' => __('الإعدادات'), 'categories' => __('الأقسام'), 'suppliers' => __('المورّدون'),
            'purchases' => __('أوامر الشراء'), 'profitability' => __('الربحية'), 'vat' => __('الضريبة'),
            'employees' => __('الموظفون'), 'pos' => __('نقطة البيع'), 'branch' => __('الفروع'),
        ];

        return collect(\App\Support\Permissions::sections())
            ->map(fn ($section) => [
                'label' => $labels[$section] ?? $section,
                'granted' => \App\Support\Permissions::allows($role, $section),
            ])->all();
    }

    /* ------------------------------ التقارير ------------------------------ */

    public function reports(): Response
    {
        $subs = Demo::subscriptionStats();
        $dist = Demo::planDistribution();

        return $this->page('Platform/Reports/Index', [
            // كانت هذه البطاقات أرقامًا ثابتة (52,640 ر.ع و120 و128 …)
            'cards' => [
                ['title' => __('تقرير الإيرادات'), 'desc' => __('إجمالي إيرادات الاشتراكات'), 'icon' => 'wallet', 'color' => 'primary',
                    'value' => Demo::money(\App\Models\Invoice::where('status', 'مدفوعة')->sum('amount'))],
                ['title' => __('تقرير الاشتراكات'), 'desc' => __('الاشتراكات النشطة والمنتهية'), 'icon' => 'refresh-cw', 'color' => 'success',
                    'value' => (string) ($subs['active'] + $subs['expired'])],
                ['title' => __('تقرير الشركات'), 'desc' => __('الشركات المسجلة في المنصة'), 'icon' => 'building-2', 'color' => 'info',
                    'value' => (string) \App\Models\Business::count()],
                ['title' => __('تقرير الأنشطة'), 'desc' => __('سجل الأنشطة والعمليات'), 'icon' => 'activity', 'color' => 'warning',
                    'value' => (string) \App\Models\ActivityLog::count()],
            ],
            'revenueSeries' => Demo::revenueSeries(),
            'planDistribution' => $dist,
            'planSummary' => $this->planSummary(),
        ]);
    }

    /**
     * ملخّص الباقات — كان جدولًا من ثلاثة صفوف مكتوبة يدويًا في القالب،
     * أرقامها ثابتة لا علاقة لها بالاشتراكات الفعلية.
     */
    private function planSummary(): array
    {
        $rows = \App\Models\Subscription::with('plan')->where('status', 'نشط')->get()
            ->groupBy(fn ($s) => $s->plan?->name ?? __('بدون باقة'));
        $totalSubs = $rows->sum(fn ($g) => $g->count());

        return $rows->map(function ($group, $name) use ($totalSubs) {
            $monthly = (float) $group->sum('amount');

            return [
                'plan' => $name,
                'subs' => $group->count(),
                'monthly' => $monthly,
                'yearly' => $monthly * 12,
                'pct' => $totalSubs > 0 ? round(($group->count() / $totalSubs) * 100) : 0,
            ];
        })->values()->all();
    }

    /* ----------------------------- الإعدادات ----------------------------- */

    public function settings(): Response
    {
        return $this->page('Platform/Settings/Index', [
            'settings' => $this->platformSettings(),
            // حال البريد كما هو على الخادم — لا كما حُفظ في الشاشة
            'mail' => \App\Support\PlatformConfig::mailStatus(),
        ]);
    }

    /**
     * إعدادات المنصة المحفوظة (business_id = null) مدمَجة فوق الافتراضيات.
     *
     * القالب كان يطبع القيم الافتراضية في value= مباشرة ولا يقرأ المحفوظ إلا
     * لمربّعات الاختيار، فكل حقل نصّي يعود لقيمته الأولى بعد الحفظ.
     */
    private function platformSettings(): array
    {
        $saved = Setting::whereNull('business_id')->pluck('value', 'key')->all();

        return [...self::SETTING_DEFAULTS, ...$saved];
    }

    /**
     * الافتراضيات — وكلٌّ منها موصولٌ بشيء.
     *
     * حُذف منها ما كان يُعرض ويُحفظ ولا يقرؤه سطر: تنسيق التاريخ، المنطقة
     * الزمنية، الرقم الضريبي، التجديد التلقائي، العملات الأربعة، إشعارات
     * المنصة الستّة، الشروط والخصوصية، ومضيف SMTP ومنفذه (بقيت الاعتمادات
     * في .env حيث موضعها).
     */
    private const SETTING_DEFAULTS = [
        'app_name' => 'Abad POS',
        'locale' => 'ar',
        'maintenance_mode' => '0',
        'company' => 'شركة أبعاد للتقنية',
        'official_email' => 'info@abad.om',
        'phone' => '+968 24000000',
        'website' => 'https://abad.om',
        'trial_days' => '14',
        'grace_days' => '7',
        'default_plan' => 'أساسية',
        'auto_suspend' => '1',
        'vat_rate' => '5',
        'tax_mode' => 'exclusive',
        'from_address' => 'no-reply@abad.om',
        'from_name' => 'Abad POS',
    ];

    /* ------------------------------ مشتركات ------------------------------ */

    /**
     * رابط عرض الشعار.
     *
     * المرفوع يُخزَّن مسارًا داخل قرص public فيحتاج تحويلًا، لكن بعض السجلات
     * تحمل رابطًا مطلقًا أصلًا. تمريره على url() ينتج
     * http://localhost/storage/https://… وهو رابط مكسور يفشل تحميله.
     */
    public static function logoUrl(?string $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        return str_starts_with($logo, 'http')
            ? $logo
            : \Illuminate\Support\Facades\Storage::disk('public')->url($logo);
    }

    private function businessOptions(): array
    {
        return [
            'types' => BusinessTypes::TYPES,
            'cities' => self::CITIES,
            'statuses' => self::STATUSES,
            'plans' => Plan::orderBy('id')->get()->map(fn ($p) => ['label' => $p->name, 'value' => $p->id])->all(),
        ];
    }

    /** الأدوار المعروضة في نماذج المستخدمين */
    private function roleOptions(): array
    {
        return [
            ['label' => __('مدير المنصة'), 'value' => 'super_admin'],
            ['label' => __('مدير نشاط'), 'value' => 'admin'],
            ['label' => __('مدير فرع'), 'value' => 'manager'],
            ['label' => __('كاشير'), 'value' => 'cashier'],
            ['label' => __('موظف مبيعات'), 'value' => 'sales'],
            ['label' => __('محاسب'), 'value' => 'accountant'],
        ];
    }

    /** تُستدعى من UserController@index لتوحيد قائمة الأدوار بين الصفحتين */
    public static function roles(): array
    {
        return (new self)->roleOptions();
    }

    /** تُستدعى من BusinessController@index لتوحيد قوائم التصفية */
    public static function filterOptions(Request $request): array
    {
        /*
         * أنواع التصفية من المسجَّل فعلًا لا من القائمة المعروفة وحدها.
         *
         * النوع صار كتابةً حرّة، فقصرُ المرشّح على الستّة المعروفة يعني أن
         * «مغسلة» تُسجَّل ثمّ لا سبيل إلى تصفيتها — مدخلٌ يقبل ما لا يستطيع
         * البحث عنه.
         */
        $types = \App\Models\Business::whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->all();

        return [
            'types' => collect(BusinessTypes::TYPES)->merge($types)->unique()->values()->all(),
            'statuses' => self::STATUSES,
            'plans' => Plan::orderBy('id')->pluck('name')->all(),
        ];
    }
}

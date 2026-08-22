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
            /*
             * اشتراك هذا المتجر بمعرّفه لا باسمه.
             *
             * مطابقةُ الاسم تخلط متجرين تسمّيا بالاسم نفسه — وهو وارد: «مخبز
             * الرحمة» في صلالة وآخر في نزوى — فيُعرض في ملفّ أحدهما اشتراكُ
             * الآخر وتاريخُ انتهائه. والأحدث أولى حين تكون له دورات كثيرة.
             */
            'subscription' => collect(Demo::subscriptions())->firstWhere('business_id', $business['id']),
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
                // المشتركون والمجرّبون رقمان لا رقم: خلطهما يقول خمسةً وفيهم
                // اثنان لم يدفعا ريالًا
                ['label' => __('المشتركون'), 'value' => (string) $s['active'], 'icon' => 'badge-check', 'color' => 'success'],
                ['label' => __('في التجربة'), 'value' => (string) $s['trialing'], 'icon' => 'hourglass', 'color' => $s['trialing'] > 0 ? 'warning' : 'secondary'],
                ['label' => __('منتهية'), 'value' => (string) $s['expired'], 'icon' => 'badge-x', 'color' => 'danger'],
                ['label' => __('الإيراد الشهري'), 'value' => Demo::money($s['monthly_revenue']), 'icon' => 'wallet', 'color' => 'primary'],
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
                // السقوف تُحرَّر من الشاشة الآن، فتُقرأ فيها
                'max_branches' => $p->max_branches,
                'max_employees' => $p->max_employees,
                'max_products' => $p->max_products,
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
        /*
         * صفٌّ واحد باستعلامٍ واحد.
         *
         * كان يُنادى `Demo::platformUser` فيُحمّل كلَّ مستخدمي المنصّة بشركاتهم
         * إلى الذاكرة ثمّ يلتقط منهم واحدًا. عشرة اليوم، وعشرة آلاف حين يكبر —
         * وفتحُ ملفٍّ واحد يقرأ الجدول كلَّه.
         */
        $model = \App\Models\User::with('business.plan')->find($id);
        abort_if($model === null, 404);

        return $this->page('Platform/Users/Show', [
            'user' => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'phone' => $model->phone,
                'business_id' => $model->business_id,
                'business' => $model->business?->name ?? __('المنصة'),
                'role' => $model->roleLabel(),
                'status' => $model->status,
                'last_login' => optional($model->last_login_at)->format('Y-m-d H:i') ?? '—',
                'created' => optional($model->created_at)->format('Y-m-d') ?? '—',
                // القيم القابلة للتعديل تُقرأ من السجل نفسه لا من التسمية المعروضة
                'role_key' => $model->role,
                // للكاشير بابٌ آخر: الرمز. والتحذير يقيس البابين لا أحدهما
                'has_pin' => filled($model->getRawOriginal('pin')),
                // كانت «الباقة الاحترافية» نصًّا ثابتًا تحت اسم الشركة
                'business_plan' => $model->business?->plan?->name,
            ],
            'activities' => Demo::userActivities($model->id),
            'roles' => $this->roleOptions(),
            // ربطُ الحساب بمتجره صار قابلًا للإصلاح — كان يُثبَّت عند الإنشاء وحده
            'businesses' => \App\Models\Business::orderBy('name')->get()
                ->map(fn ($b) => ['label' => $b->name, 'value' => $b->id])->all(),
            'permissions' => $this->permissionsFor($model),
            // «تتبع الدور» جملةٌ تكذب على من خُصّصت صلاحياته يدويًّا
            'permissions_manual' => $model->hasManualPermissions(),
        ]);
    }

    /**
     * صلاحيات المستخدم كما تُفرض فعلًا — لا كما يقترحها دورُه.
     *
     * كانت تُشتقّ من الدور وحده، بينما `User::allows()` تقول غير ذلك: إن
     * خصّص التاجرُ صلاحيات موظّفه يدويًّا فقائمتُه تُلغي خريطة الدور كلَّها.
     * فكاشيرٌ مُنح «المخزون» وحده كان يظهر هنا: لوحة التحكم ✓ نقطة البيع ✓
     * المخزون ✗ — عكسُ واقعه في الخانات الثلاث. وشاشةُ تدقيقٍ تُخطئ أسوأ من
     * غيابها: من يقرؤها لا يعيد الفحص.
     *
     * والتسميات من `Permissions::sectionLabels()` لا من قائمةٍ محلّيّة كانت
     * تُعدّد أقسامًا لا وجود لها (تقارير، ربحية، فروع…).
     */
    private function permissionsFor(?\App\Models\User $user): array
    {
        $labels = \App\Support\Permissions::sectionLabels();

        return collect(\App\Support\Permissions::sections())
            ->map(fn ($section) => [
                'label' => $labels[$section] ?? $section,
                'granted' => (bool) $user?->allows($section),
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
                    'value' => (string) ($subs['active'] + $subs['trialing'] + $subs['expired'])],
                ['title' => __('تقرير الشركات'), 'desc' => __('الشركات المسجلة في المنصة'), 'icon' => 'building-2', 'color' => 'info',
                    'value' => (string) \App\Models\Business::real()->count()],
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
            /*
             * الباقات بأسمائها لتُختار لا لتُكتب.
             *
             * «الباقة الافتراضية» كانت حقلًا نصّيًّا يُطابَق بالحرف: مسافةٌ
             * زائدة أو باقةٌ أُعيدت تسميتُها تعني متجرًا يُضاف بلا باقة —
             * ولا شيء في الشاشة يقول إن الحقل لم يعد يطابق شيئًا.
             */
            'plans' => \App\Models\Plan::orderBy('id')->pluck('name')
                ->map(fn ($n) => ['label' => $n, 'value' => $n])->all(),
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
    /**
     * الأدوار: كلّها من `App\Support\Roles` لا ستّةً منتقاة.
     *
     * كانت القائمة تُعدّد ستّة وتُسقط `inventory` و`delivery`، وهما دوران
     * يمنحهما التاجر لموظّفيه فعلًا. فيصل مسؤولُ مخزونٍ إلى هذه الشاشة
     * ودورُه ليس بين الخيارات، فتُعرض خانةُ الدور فارغةً وهي مطلوبة: لا
     * يُحفظ تعديلُ هاتفه حتى يُسنَد إليه دورٌ آخر.
     */
    private function roleOptions(): array
    {
        return \App\Support\Roles::options();
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
        $types = \App\Models\Business::real()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->all();

        return [
            'types' => collect(BusinessTypes::TYPES)->merge($types)->unique()->values()->all(),
            'statuses' => self::STATUSES,
            'plans' => Plan::orderBy('id')->pluck('name')->all(),
        ];
    }
}

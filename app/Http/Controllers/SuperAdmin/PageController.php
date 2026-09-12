<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Billing;
use App\Support\BusinessTypes;
use App\Support\CrmAssistant;
use App\Support\Demo;
use App\Support\GoogleBilling;
use App\Support\GoogleReviews;
use App\Support\MerchantAccount;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;
use App\Support\PlatformConfig;
use App\Support\Roles;
use App\Support\SupportWhatsApp;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    /**
     * حالاتُ الشركة — تقرؤها الشاشةُ للاختيار، ويقرؤها الحارس للقبول.
     *
     * ومصدرٌ واحد: كان التحقّق `string|max:50` يقبل أيّ نصّ بينما الشاشة
     * تعرض ثلاثًا. وحالةٌ لا تعرفها القائمة ليست خطأً يُرى — هي شبحٌ صامت:
     * `Tenancy::BLOCKED_BUSINESS` لا تعرفها فلا يُحجب المتجر، وعدّادُ
     * «الشركات النشطة» يقارن بـ«نشط» فلا يعدّها. متجرٌ يعمل ولا يُحصى ولا
     * يُوقَف، ولا شاشةَ تستطيع أن تُعيده إلى حالةٍ معروفة.
     */
    public const STATUSES = ['نشط', 'منتهي', 'معطل'];

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
        $model = Business::with('plan')->findOrFail($business['id']);

        return $this->page('Platform/Businesses/Show', [
            'business' => [
                ...$business,
                'logo' => self::logoUrl($business['logo']),
                // بريد الدخول — أوّل ما يُسأل عنه حين يتصل التاجر
                'owner_email' => MerchantAccount::owner($model)?->email,
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
            'usage' => PlanLimits::usage($model),
            'renewal' => [
                'monthly' => Billing::price($model, 'monthly'),
                'yearly' => Billing::price($model, 'yearly'),
                'endsAt' => optional($model->ends_at)->format('Y-m-d'),
            ],
            // واتساب هذا المتجر: حدُّه واستهلاكه وأذونه — بلا رمزٍ ولا سرّ
            'whatsapp' => WhatsAppController::businessView($model),
            // ووسيلة استعادته: العنوان وحاله — أوّل ما يُسأل عنه حين يتّصل صاحبه
            'recovery' => RecoveryController::view($model),
        ]);
    }

    public function businessesEdit(string $id): Response
    {
        $business = Demo::business($id);
        abort_if(empty($business), 404);

        $model = Business::find($business['id']);

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
                'owner_email' => $model ? MerchantAccount::owner($model)?->email : null,
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
                /*
                 * ما تفتحه الباقة — و`null` تعني «كلّ شيء» لا «لا شيء».
                 *
                 * تُرسل كما هي لا مُسوّاةً إلى قائمةٍ فارغة: الفرق بينهما هو
                 * الفرق بين باقةٍ لم تُضبط بعد وباقةٍ أُغلقت عمدًا، والشاشة
                 * تحتاج أن تقول أيّهما.
                 */
                'capabilities' => $p->capabilities,
            ])->all(),
            'capabilityOptions' => PlanFeatures::options(),
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
         * وفتحُ ملفٍّ واحد يقرأ الجدول كلَّه. (وقد رُفعت تلك الدالّة بعدها:
         * بقيت بلا مُنادٍ تخترع وجهًا عشوائيًّا وتقرأ كلّ المستأجرين.)
         */
        $model = User::with('business.plan')->find($id);
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
                // كانت «الباقة الاحترافية» نصًّا ثابتًا تحت اسم الشركة
                'business_plan' => $model->business?->plan?->name,
            ],
            'activities' => Demo::userActivities($model->id),
            'roles' => $this->roleOptions(),
            // ربطُ الحساب بمتجره صار قابلًا للإصلاح — كان يُثبَّت عند الإنشاء وحده
            'businesses' => Business::orderBy('name')->get()
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
    private function permissionsFor(?User $user): array
    {
        $labels = Permissions::sectionLabels();

        return collect(Permissions::sections())
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
                    'value' => Demo::money(Invoice::where('status', 'مدفوعة')->sum('amount'))],
                ['title' => __('تقرير الاشتراكات'), 'desc' => __('الاشتراكات النشطة والمنتهية'), 'icon' => 'refresh-cw', 'color' => 'success',
                    'value' => (string) ($subs['active'] + $subs['trialing'] + $subs['expired'])],
                ['title' => __('تقرير الشركات'), 'desc' => __('الشركات المسجلة في المنصة'), 'icon' => 'building-2', 'color' => 'info',
                    'value' => (string) Business::real()->count()],
                ['title' => __('تقرير الأنشطة'), 'desc' => __('سجل الأنشطة والعمليات'), 'icon' => 'activity', 'color' => 'warning',
                    'value' => (string) ActivityLog::count()],
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
        $rows = Subscription::with('plan')->where('status', 'نشط')->get()
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
            'mail' => PlatformConfig::mailStatus(),
            /*
             * الباقات بأسمائها لتُختار لا لتُكتب.
             *
             * «الباقة الافتراضية» كانت حقلًا نصّيًّا يُطابَق بالحرف: مسافةٌ
             * زائدة أو باقةٌ أُعيدت تسميتُها تعني متجرًا يُضاف بلا باقة —
             * ولا شيء في الشاشة يقول إن الحقل لم يعد يطابق شيئًا.
             */
            'plans' => Plan::orderBy('id')->pluck('name')
                ->map(fn ($n) => ['label' => $n, 'value' => $n])->all(),
            /*
             * حال الوصلة المشتركة — بلا رمز.
             *
             * `publicView` هو الباب: تمرير النموذج نفسه كان يُخرج الرمز إلى
             * المتصفّح مهما كان `$hidden` عليه.
             */
            /* تلميحُ مفتاح الخرائط لا المفتاح — أربعةُ أحرفٍ ليُعرف أيُّه محفوظ */
            'googleKeyHint' => GoogleReviews::platformKeyHint(),
            /*
             * حالُ خرائط Google في المنصّة — مهيَّأةٌ أو لا، وكم فرعًا رُبط.
             *
             * والعددُ محسوبٌ لا موعود: مفتاحٌ محفوظٌ وصفرُ فروعٍ مربوطة يعني
             * أنّ الميزة لم تصل التجّار، وذلك خبرٌ يُقرأ في سطرٍ لا يُكتشف
             * بعد شهر.
             */
            /*
             * حالُ فوترة Google وموعدُ انتهاء التجربة.
             *
             * وهو أخطرُ ما في هذه الشاشة صمتًا: يومَ تنتهي التجربة تتوقّف
             * الخرائط عن كلّ التجّار دفعةً واحدة، ولا يقول شيءٌ لماذا.
             */
            'googleBilling' => GoogleBilling::view(),
            'googleHealth' => [
                'configured' => GoogleReviews::platformKey() !== null,
                'linkedBranches' => BranchGooglePlace::query()->linked()->count(),
                'branches' => Branch::count(),
            ],
            /*
             * ومن يستطيع أن يكلّمنا على واتساب — عددٌ محسوبٌ لا وعد.
             *
             * القناةُ تُشعَل ثمّ لا يصل شيء فيُظنّ العطبُ في الربط، والسببُ
             * أنّ أصحابَ المتاجر لم يكتبوا أرقامهم. فيُقال قبل أن يُسأل.
             */
            'supportReach' => SupportWhatsApp::reach(),
            /*
             * ورقمُ الإشعارات بغرضه صراحةً.
             *
             * كانت تأخذ **أحدثَ** وصلةِ منصّة أيًّا كان غرضُها. فيومَ رُبط رقمُ
             * مبيعاتٍ ثانٍ، كانت هذه الشاشة ستعرضه على أنّه «رقم أبعاد
             * المشترك» — ومقبضُ «استقبال رسائل الدعم» فوقه سيكتب في الصفّ
             * الخطأ. والأسوأ أنّ «فصل الرقم» كان سيُطفئ رقمَ المبيعات
             * والمشغّلُ يظنّه يُطفئ الإشعارات.
             */
            'whatsapp' => WhatsAppConnections::publicView(
                WhatsAppConnection::query()->platform()
                    ->where('purpose', WhatsAppMode::PURPOSE_NOTIFICATIONS)
                    ->orderByDesc('id')->first(),
                withIds: true,
            ),
            /*
             * وحالُ مزوّد الذكاء الاصطناعيّ — اسمُه وهل هو صالحٌ للنداء.
             *
             * ولا يخرج المفتاحُ ولا طرفٌ منه: `ready` سؤالٌ عن وجوده لا عن
             * قيمته. ومقبضٌ يُعرض فوق مزوّدٍ غائبٍ لا يُدير شيئًا — فيُقال
             * حالُه قبل المقبض.
             */
            'aiProvider' => [
                'ready' => CrmAssistant::provider()->ready(),
                'name' => CrmAssistant::provider()->name(),
            ],
            /* ورقمُ المبيعات وصلةٌ ثانيةٌ تُعرض وحدَها — نطاقان لا يختلطان */
            'salesWhatsapp' => WhatsAppConnections::publicView(
                WhatsAppConnection::query()->platform()
                    ->where('purpose', WhatsAppMode::PURPOSE_CRM_SALES)
                    ->orderByDesc('id')->first(),
                withIds: true,
            ),
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
        /*
         * ═══ ولا يُقرأ الجدول كلُّه — بل ما لهذه الشاشة وحده ═══
         *
         * كانت تنشر كلَّ صفوف `business_id = null` فوق الافتراضيّات. وفي
         * الجدول ما ليس لها: `google_places_key` مفتاحُ خرائط المنصّة —
         * معمًّى، لكنّه وصل حمولةَ الصفحة كاملًا (٢٥٦ حرفًا) فقرأه من فتح
         * «مصدر الصفحة».
         *
         * وقد عرف الكودُ الخطرَ ولم يُغلقه: `SettingController::googleKey`
         * يشرح أنّ المفتاح لا يمرّ في `KEYS` «لأنّ تلك تُقرأ في
         * `platformSettings` فتصل المتصفّح» — ثمّ تقرأ `platformSettings`
         * الجدولَ كلَّه لا `KEYS`. فالاستثناءُ حرسَ بابًا والتسريبُ من آخر.
         *
         * والقائمةُ تُشتقّ من الافتراضيّات لا تُكتب ثانية: مفتاحٌ يُضاف
         * للشاشة يُضاف في موضعٍ واحد، وسرٌّ يُخزَّن غدًا لا يتسرّب لأنّ
         * أحدًا نسي أن يستثنيه.
         */
        $saved = Setting::whereNull('business_id')
            ->whereIn('key', array_keys(self::SETTING_DEFAULTS))
            ->pluck('value', 'key')->all();

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
        /*
         * ولا باقةَ افتراضيّةً حتى يختار صاحبُ المنصّة واحدةً من باقاته.
         *
         * ═══ وكان هنا «أساسية» — اسمٌ لا يطابق شيئًا ═══
         *
         * الباقاتُ تُسمّى «الباقة الأساسية» و«الباقة الاحترافية»، ولا شيءَ في
         * النظام يُنشئ باقةً اسمُها «أساسية». فالشاشةُ ترسل هذا الافتراضيَّ في
         * كلّ حفظ، و`update` تردّ الطلبَ **كلَّه** لأنّ الاسم لا يطابق باقة —
         * كلَّ الحقول، في كلّ التبويبات.
         *
         * وأثرُه أنّ هذه الشاشة لم تحفظ حرفًا واحدًا منذ كُتب: يضغط صاحبُ
         * المنصّة «حفظ» فترتدّ الصفحة كأنّ شيئًا لم يكن، ورسالةُ الرفض تُرسم
         * على حقلٍ في تبويبٍ آخر لا يراه. وقِيس ذلك على الإنتاج: صفٌّ واحدٌ
         * في جدول إعدادات المنصّة كلِّه.
         *
         * والفراغُ هو الصادق: «لا باقةَ افتراضيّة» حالٌ يفهمها `update`
         * (`filled` تردّ false فيمرّ)، وتعرضها الشاشةُ «— بلا باقة —»،
         * ويختار منها التاجرُ ما هو قائمٌ فعلًا.
         */
        'default_plan' => '',
        'auto_suspend' => '1',
        'vat_rate' => '5',
        'tax_mode' => 'exclusive',
        'from_address' => 'no-reply@abad.om',
        'from_name' => 'Abad POS',
        // واتساب مُطفأ حتى يُربط الرقم ويُقرَّر — لا يبدأ نظام إرسالٍ من نفسه
        'whatsapp_enabled' => '0',
        'whatsapp_shared_enabled' => '1',
        /*
         * الرقم مكتوبٌ حرفًا لا مُشتقًّا.
         *
         * `(string) WhatsAppQuota::FALLBACK_DEFAULT` تعبيرٌ لا يقبله PHP 8.4
         * داخل `const` — يقبله 8.5 عندي ويرفضه الخادم، فيسقط الملفّ كلّه
         * وتصير كلّ صفحةٍ خمسمئة. والقيمتان تبقيان متطابقتين باختبارٍ يقارنهما.
         */
        'whatsapp_shared_default_monthly_limit' => '100',
        /*
         * والمساعدُ الذكيّ «اقتراحُ الردّ فقط» افتراضًا لا «متوقّف».
         *
         * ولمَ لا يُطفأ افتراضًا: لأنّه مطفأٌ فعلًا بلا مفتاحٍ في البيئة —
         * `CrmAssistant::available()` تسأل عن المزوّد أيضًا. فمقبضٌ مطفأٌ
         * فوق مزوّدٍ غائبٍ مقبضان لبابٍ واحد: يُضبط أحدُهما ولا يُفتح الباب،
         * فيُظنّ المفتاحُ خاطئًا.
         *
         * والوضعُ هنا يقول ما نسمح به؛ والمفتاحُ يقول ما نستطيعه.
         */
        'crm_ai_mode' => CrmAssistant::SUGGEST,
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
            : Storage::disk('public')->url($logo);
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
        return Roles::options();
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
        $types = Business::real()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->all();

        return [
            'types' => collect(BusinessTypes::TYPES)->merge($types)->unique()->values()->all(),
            'statuses' => self::STATUSES,
            'plans' => Plan::orderBy('id')->pluck('name')->all(),
        ];
    }
}

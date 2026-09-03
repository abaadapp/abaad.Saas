<?php

namespace App\Support;

use App\Models\User;

/**
 * صلاحيات الأقسام حسب الدور داخل لوحة النشاط.
 * '*' = كل الأقسام. dashboard و pos متاحة دائمًا لأي موظف يصل للوحة.
 */
class Permissions
{
    public const MAP = [
        'admin' => ['*'],
        'manager' => ['*'],
        // المحاسب يقرأ التقارير: هي عملُه لا زينةٌ فوقه — والمحاسبة المتقدّمة
        // عملُه قبل ذلك: شجرةُ الحسابات والقيود اليومية وميزان المراجعة
        'accountant' => ['dashboard', 'orders', 'customers', 'finance', 'accounting', 'expenses', 'employees', 'pos', 'reports'],
        // من يجهّز البضاعة يرى لوحة التجهيز — وهي عملُه لا زينةٌ فوقه
        'inventory' => ['dashboard', 'products', 'inventory', 'suppliers', 'purchases', 'pos', 'preparation'],
        'sales' => ['dashboard', 'orders', 'customers', 'products', 'pos', 'preparation'],
        'cashier' => ['dashboard', 'pos'],
        // والسائق يقرأ ما سيحمله: العنوان والموعد والمستلِم
        'delivery' => ['dashboard', 'orders', 'pos', 'preparation'],
    ];

    /** كل الأقسام التي تظهر في لوحة النشاط — مصدر واحد تقرأ منه الواجهة */
    public const SECTIONS = [
        'dashboard', 'customers', 'products', 'orders', 'marketing',
        'inventory', 'finance', 'expenses', 'settings',
        /*
         * المحاسبة المتقدّمة قسمٌ مستقلّ عن «المالية».
         *
         * «المالية» ما يفعله الموظّف كلّ يوم: يسجّل مصروفًا، ويقرأ ما دخل وما
         * خرج، ويرى ما على المتجر. و«المحاسبة المتقدّمة» شجرةُ الحسابات
         * والقيود اليدوية والأصول الثابتة — وهي أدواتٌ تُفسد الدفتر إن
         * استُعملت بلا علم: قيدٌ يدويّ مختلّ الطرفين، أو حسابٌ نظاميّ يُعاد
         * تسميته، لا يُكتشف أثرهما إلا في ميزان المراجعة بعد شهور.
         *
         * وكانت الخمس تحت مفتاحٍ واحد: من مُنح «المالية» ليسجّل مصروفًا مُنح
         * معها حقَّ إعادة بناء شجرة حسابات المتجر.
         */
        'accounting',
        /*
         * الموقع الإلكتروني قسمٌ مستقلّ عن «الإعدادات».
         *
         * كان ثلاثة تبويبات داخل شاشة الإعدادات، فمن مُنح الإعدادات ليضبط
         * ضريبةً أو يضيف فرعًا مُنح معها نشرَ موقع المتجر على الإنترنت وتبديلَ
         * ما يقرؤه كلّ زائر. وهما عملان لا يفعلهما الشخص نفسه في أكثر
         * المتاجر.
         */
        'website',
        'suppliers', 'purchases', 'employees', 'pos', 'reports',
        /*
         * التجهيز قسمٌ مستقلّ لا جزءٌ من «المبيعات».
         *
         * «المبيعات» تفتح الفواتير وإجماليّاتها ومجموعَ ما رُشّح — وهي أوسع
         * بكثير ممّا يحتاجه من يصنع الباقة. ومنحُها لعامل التجهيز يعني أنّ
         * كلّ من يقف عند الطاولة يقرأ مبيعات المحلّ.
         */
        'preparation',
    ];

    /**
     * الأدوار التي تدخل لوحة النشاط. الكاشير ليس منها — صلاحية `dashboard`
     * وحدها لا تكفي، لأن حارس المسار دورٌ لا صلاحية.
     *
     * مصدرٌ واحد: يقرأه middleware المسار، ويقرأه الخادم ليخبر الواجهة هل
     * تُظهر زر «العودة إلى اللوحة» أم لا. الفصل بينهما كان سيُظهر زرًّا
     * يقود إلى 403.
     */
    public const PANEL_ROLES = ['admin', 'manager', 'accountant', 'inventory', 'sales', 'delivery'];

    /**
     * لا قسم مفتوحًا بلا منح.
     *
     * كانت «لوحة التحكم» و«نقطة البيع» و«الفروع» تُفتح لكل من دخل مهما كانت
     * صلاحياته — فصاحب النشاط يرفع علامة عنها ولا يتغيّر شيء، وهو أسوأ ما
     * يكون: منعٌ ظاهرٌ في الشاشة لا وجود له في الواقع. صارت الثلاثة تُمنح
     * صراحةً كغيرها، فما لم يُعلَّم لا يُفتح.
     */
    public const ALWAYS_OPEN = [];

    /**
     * أقسام خارج لوحة النشاط: تُمنح ولا تفتح بابها.
     *
     * نقطة البيع شاشةٌ قائمة بذاتها، فمنحُها وحدها لا يجعل صاحبها يدخل
     * اللوحة — وإلا لصار كل كاشير في اللوحة بمجرّد أن يبيع.
     */
    public const OUTSIDE_PANEL = ['pos'];

    /**
     * هل يدخل هذا المستخدم لوحة النشاط؟
     *
     * الدور وحده لا يكفي منذ صارت الصلاحيات تُخصَّص يدويًّا: كاشيرٌ مُنح
     * صلاحية المخزون كان يُمنع عند الباب فلا تصل صلاحيته إلى الفحص أصلًا —
     * ميزةٌ تُحفَظ في القاعدة ولا تعمل. فمن مُنح قسمًا من أقسام اللوحة يدخل
     * ليصل إليه، ويبقى ما لم يُمنح محجوبًا عنه بحارس القسم.
     */
    public static function entersPanel(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin() || in_array($user->role, self::PANEL_ROLES, true)) {
            return true;
        }

        return collect($user->permissions ?? [])
            ->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
            ->isNotEmpty();
    }

    /**
     * أوّل صفحةٍ في اللوحة يفتحها هذا المستخدم فعلًا — أو null.
     *
     * `entersPanel` تجيب «هل يدخل؟» ولا تقول «إلى أين». وزرّ «لوحة النشاط»
     * في نقطة البيع كان يعتمد عليها ثم يقود إلى `admin.dashboard` دائمًا،
     * فموظّفٌ مُنح المخزون وحده يرى الزرّ — لأنه يدخل اللوحة — ويصطدم بـ403
     * على قسمٍ لم يُمنحه. بابٌ يُعرض ولا يُفتح، وهو أسوأ من بابٍ لا يُعرض:
     * الموظّف يظنّ العطب في النظام ويعيد المحاولة، والتاجر يظنّ أن صلاحياته
     * لم تُحفظ.
     *
     * فيُسأل عن الوجهة لا عن الإذن، ومن لا وجهة له لا يرى الزرّ.
     */
    public static function panelEntry(?User $user): ?string
    {
        if ($user === null || ! self::entersPanel($user)) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return route('super-admin.dashboard');
        }

        return collect(self::SECTIONS)
            ->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
            ->filter(fn ($s) => $user->allows($s))
            ->map(fn ($s) => self::routeFor($s))
            ->first(fn ($r) => $r !== null);
    }

    /** القسم → مساره. مصدرٌ واحد يقرؤه التوجيه بعد الدخول وروابط التنبيهات */
    public const ROUTES = [
        'dashboard' => 'admin.dashboard', 'customers' => 'admin.customers.index',
        'products' => 'admin.products.index', 'orders' => 'admin.orders.index',
        'marketing' => 'admin.marketing.loyalty', 'inventory' => 'admin.inventory.index',
        // «المالية» تبدأ بملخّصها: «كم عندي وكم ربحت؟» قبل «أين حساباتي؟»
        'finance' => 'admin.finance.summary', 'expenses' => 'admin.expenses.index',
        'accounting' => 'admin.finance.chart',
        'website' => 'admin.website.index',
        'settings' => 'admin.settings.index', 'suppliers' => 'admin.suppliers.index',
        'purchases' => 'admin.purchases.index', 'employees' => 'admin.employees.index',
        'pos' => 'pos.index',
        // «التقارير» كانت في SECTIONS ولا مسار لها هنا: من أوّلُ ما مُنح له
        // التقاريرُ يسقط على `ROUTES['reports']` غير الموجود، فيرتفع خطأ
        // مفتاحٍ ناقص داخل HandleInertiaRequests — أي ٥٠٠ على كل صفحةٍ يفتحها
        'reports' => 'admin.reports.index',
        'preparation' => 'admin.preparation.index',
    ];

    /**
     * مسار القسم إن كان له باب — وإلا null.
     *
     * القراءة المباشرة من ROUTES كانت تنفجر على قسمٍ نُسي فيها بدل أن تتخطّاه:
     * والفشل هنا يقع في مشاركة Inertia، فيصير خمسمئةً على كلّ صفحة لا بابًا
     * مفقودًا في قائمة.
     */
    private static function routeFor(string $section): ?string
    {
        return isset(self::ROUTES[$section]) ? route(self::ROUTES[$section]) : null;
    }

    /**
     * أوّل صفحة يراها المستخدم بعد الدخول.
     *
     * كانت تُختار بالدور وحده، فتُرسل كلّ من ليس مديرًا إلى نقطة البيع. ومنذ
     * صارت الصلاحيات تُخصَّص، صار ذلك يعني دخولًا ناجحًا ينتهي إلى 403: موظفٌ
     * مُنح المخزون ولم يُمنح نقطة البيع يُدفع إلى بابٍ مغلق في وجهه. فيُختار
     * القسم من صلاحياته: لوحته إن ملكها، ثمّ نقطة البيع، ثمّ أوّل ما مُنح.
     *
     * وبابان يجب أن يتّفقا هنا: صلاحية القسم، ودخول اللوحة. الكاشير يملك
     * صلاحية «لوحة التحكم» بحكم دوره ولا يدخل اللوحة — فإرساله إليها دخولٌ
     * ينتهي إلى 403. فلا يُقترح قسمٌ من اللوحة على من لا يدخلها.
     */
    public static function homeFor(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return route('super-admin.dashboard');
        }

        $panel = self::entersPanel($user);

        if ($panel && $user->allows('dashboard')) {
            return route(self::ROUTES['dashboard']);
        }

        if ($user->allows('pos')) {
            return route(self::ROUTES['pos']);
        }

        // بلا صلاحية واحدة: تُرفض الآن عند الحفظ، لكن حسابًا قديمًا قد يسبقها
        return ($panel ? self::panelEntry($user) : null) ?? route('login');
    }

    /** أسماء الأقسام كما تُعرض للتاجر — الواجهة لا تخمّنها من المفتاح */
    public static function sectionLabels(): array
    {
        $labels = [
            'dashboard' => 'لوحة التحكم', 'customers' => 'العملاء', 'products' => 'المنتجات',
            'orders' => 'المبيعات', 'marketing' => 'أدوات التسويق', 'inventory' => 'المخزون',
            'finance' => 'المالية', 'accounting' => 'المحاسبة المتقدمة',
            'expenses' => 'مصاريف شهرية', 'settings' => 'الإعدادات',
            'website' => 'الموقع الإلكتروني',
            'suppliers' => 'الموردين', 'purchases' => 'المشتريات',
            'employees' => 'الرواتب والموظفين', 'pos' => 'نقطة البيع',
            // كانت ساقطةً فتُعرض «reports» بحروفٍ لاتينية في قائمة صلاحيات عربية
            'reports' => 'التقارير',
            'preparation' => 'لوحة التجهيز',
        ];

        return collect(self::SECTIONS)
            ->mapWithKeys(fn ($s) => [$s => __($labels[$s] ?? $s)])
            ->all();
    }

    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * ما يفتحه الدور — والمجهول لا يفتح شيئًا.
     *
     * كان السطر ينتهي بـ`?? ['*']`، فأيّ دورٍ لا تعرفه الخريطة يمرّ بكلّ
     * قسم: خطأٌ مطبعيّ في الحقل، أو صفٌّ قديم بدورٍ أُلغي، أو طلبٌ يُرسَل
     * إلى المسار مباشرةً بدورٍ مخترَع — كلّها كانت تنتهي إلى صلاحياتٍ كاملة.
     * والفشل في بابٍ يجب أن يُغلقه لا أن يفتحه على مصراعيه.
     *
     * وصاحبُ النشاط ومديرُ الفرع يبقيان على `*` صراحةً في الخريطة، فلا
     * يمسّ هذا التشديدُ أحدًا يعمل اليوم.
     */
    public static function abilities(?string $role): array
    {
        return self::MAP[$role] ?? [];
    }

    public static function allows(?string $role, string $section): bool
    {
        if ($role === 'super_admin') {
            return true;
        }
        $abilities = self::abilities($role);
        if (in_array('*', $abilities, true)) {
            return true;
        }
        if (in_array($section, self::ALWAYS_OPEN, true)) {
            return true;
        }

        return in_array($section, $abilities, true);
    }

    /**
     * مسارات هيكل اللوحة لا أقسامها: الجرس، والبحث، ومبدّلا اللغة والعملة.
     *
     * هذه أدوات الشريط العلوي التي يراها كلّ من دخل اللوحة أيًّا كان قسمه —
     * ليست بابًا إلى بيانات قسم، فلا يُشتقّ لها مفتاح صلاحية. والبحث يصفّي
     * نتائجه بنفسه حسب ما يملكه صاحبه (انظر SearchController).
     */
    public const SHELL = [
        'admin.search', 'admin.currency.switch', 'admin.language.update',
        'admin.notifications.feed', 'admin.notifications.dismiss', 'admin.notifications.clear',
    ];

    /**
     * مسارٌ اسمه لا يشتقّ قسمه — يُنسب صراحةً إلى القسم الذي يملكه.
     *
     * الاشتقاق من الاسم يعمل ما دام الاسم يطابق مفتاح الصلاحية. وحين يفترقان
     * يُنتج مفتاحًا لا وجود له في SECTIONS، فلا يملكه أحد: يُمنع منه كلّ من
     * خُصّصت صلاحياته يدويًّا مهما مُنح، ويُمنع منه كلُّ دورٍ إلا المالك
     * والمدير (لهما '*'). والنتيجة صفحةٌ في القائمة لا تُفتح، أو علامةٌ
     * يرفعها صاحب النشاط ولا تغيّر شيئًا.
     *
     * أوضحها «الفروع»: مساره admin.branches يشتقّ 'branches' والمفتاح
     * 'branch' — فمنحُها كان بلا أثر.
     */
    public const ALIASES = [
        // تبديل الفرع صار في قائمة الحساب بالهيدر، وصفحة الفروع في الإعدادات
        'branches' => 'settings',
        'branch' => 'settings',
        'addons' => 'products',
        'jobTitles' => 'employees',
        // مسيرة الرواتب وصرفها من قسم «الرواتب والموظفين» — لا مفتاح ثالث لها
        'payroll' => 'employees',
        'coupons' => 'marketing',
        'bank' => 'finance',
        'expenseTypes' => 'expenses',
        'goals' => 'dashboard',
        'alerts' => 'settings',
        'backup' => 'settings',
        'activity' => 'settings',
        // أجهزة نقطة البيع إعدادٌ إداريّ: من يملك الإعدادات يفعّل ويُلغي
        'devices' => 'settings',
        // معاينةُ المتجر تتبع الشاشة التي يُضبط فيها — «الإعدادات»
        'store' => 'settings',
    ];

    /**
     * التصدير يتبع قسم ما يُصدَّر: admin.export.orders → orders.
     *
     * وما ليس هنا يسقط إلى «الإعدادات» — أضيقُ ما يُمنح. و`reports` كانت
     * ساقطةً فيه: زرّ «CSV» في ملخّص المبيعات يُرسم لكل من يفتح الصفحة،
     * ويُطالَب صاحبه عند الضغط بصلاحية الإعدادات. فالمحاسب — وهو أكثر من
     * يُصدّر — يفتح تقريرًا مأذونًا له ويُردّ بـ٤٠٣ عن ملفّه.
     */
    public const EXPORT_ALIASES = [
        'products' => 'products', 'orders' => 'orders', 'customers' => 'customers',
        'transactions' => 'finance', 'expenses' => 'expenses', 'inventory' => 'inventory',
        'reports' => 'reports',
        // و«الموردون» كانت ساقطةً مثلها: زرّ CSV في شاشة المورّدين يُرسم لمن
        // يملك القسم، ويُطالَب عند الضغط بصلاحية الإعدادات — وأمين المخزن
        // يملك المورّدين ولا يملك الإعدادات، فلا يُنزِّل ملفّ شاشته أبدًا.
        // وحارسٌ في ReportsTellTheirScopeTest يمنع سقوط الثامن.
        'suppliers' => 'suppliers',
    ];

    /**
     * مسارات «المحاسبة المتقدّمة» — بادئاتُها، فما تحتها يتبعها.
     *
     * البادئة لا القائمة المُحصاة: `admin.finance.chart.store` و`.update`
     * و`.destroy` تُضاف الواحدة تلو الأخرى، وقائمةٌ تُعدّ يدويًّا تنسى
     * التالية — فيبقى مسارُ حذفٍ من الشجرة مفتوحًا لمن مُنح «المالية».
     */
    public const ADVANCED_ACCOUNTING = [
        'admin.finance.chart',
        'admin.finance.journal',
        'admin.finance.assets',
        // ربط نوع المصروف بحسابه — قرارٌ محاسبيّ لا تسجيلُ مصروف
        'admin.expenseTypes.update',
    ];

    /** هل هذا المسار من هيكل اللوحة لا من أقسامها؟ */
    public static function isShell(?string $route): bool
    {
        return $route !== null && in_array($route, self::SHELL, true);
    }

    /** استخراج القسم من اسم المسار: admin.products.index → products */
    public static function sectionFromRoute(?string $route): string
    {
        if (! $route) {
            return 'dashboard';
        }

        /*
         * أدوات المحاسبة المتقدّمة تسقط على قسمها لا على «المالية».
         *
         * الاشتقاق من الاسم يقرأ `admin.finance.journal` قسمًا اسمه
         * `finance` — وهو الصواب في الاسم والخطأ في المعنى: من مُنح المالية
         * ليسجّل مصروفًا لا يُمنح معها كتابةَ قيدٍ يدويّ ولا حذفَ حسابٍ من
         * الشجرة. فتُنسب هذه بالبادئة صراحةً.
         */
        foreach (self::ADVANCED_ACCOUNTING as $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix.'.')) {
                return 'accounting';
            }
        }
        // شاشة المدفوعات داخل نقطة البيع شاشة مالية لا شاشة بيع: تعرض
        // تحصيلات اليوم وطرق الدفع. الكاشير يبيع ولا يطّلع على حصيلة
        // الصندوق، فتتبع صلاحية «finance» لا صلاحية «pos» المفتوحة للجميع.
        if ($route === 'pos.payments') {
            return 'finance';
        }
        if (str_starts_with($route, 'pos.')) {
            return 'pos';
        }
        $parts = explode('.', $route);
        $section = $parts[1] ?? 'dashboard';

        if ($section === 'export') {
            // ما لا يُعرف ما يُصدَّر يتبع الإعدادات: أضيقُ ما يُمنح
            return self::EXPORT_ALIASES[$parts[2] ?? ''] ?? 'settings';
        }

        return self::ALIASES[$section] ?? $section;
    }
}

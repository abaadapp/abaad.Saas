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
        'accountant' => ['dashboard', 'orders', 'customers', 'finance', 'expenses', 'reports', 'profitability', 'vat', 'pos'],
        'inventory' => ['dashboard', 'products', 'categories', 'inventory', 'suppliers', 'purchases', 'reports', 'pos'],
        'sales' => ['dashboard', 'orders', 'customers', 'products', 'pos'],
        'cashier' => ['dashboard', 'pos'],
        'delivery' => ['dashboard', 'orders', 'pos'],
    ];

    /** كل الأقسام التي تظهر في لوحة النشاط — مصدر واحد تقرأ منه الواجهة */
    public const SECTIONS = [
        'dashboard', 'customers', 'products', 'orders', 'marketing',
        'inventory', 'finance', 'expenses', 'reports', 'settings',
        'categories', 'suppliers', 'purchases', 'profitability', 'vat',
        'employees', 'pos', 'branch',
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

    /** القسم → مساره. مصدرٌ واحد يقرؤه التوجيه بعد الدخول وروابط التنبيهات */
    public const ROUTES = [
        'dashboard' => 'admin.dashboard', 'customers' => 'admin.customers.index',
        'products' => 'admin.products.index', 'orders' => 'admin.orders.index',
        'marketing' => 'admin.marketing.index', 'inventory' => 'admin.inventory.index',
        'finance' => 'admin.finance.index', 'expenses' => 'admin.expenses.index',
        'reports' => 'admin.reports.index', 'settings' => 'admin.settings.index',
        'categories' => 'admin.categories.index', 'suppliers' => 'admin.suppliers.index',
        'purchases' => 'admin.purchases.index', 'profitability' => 'admin.profitability.index',
        'vat' => 'admin.vat.index', 'employees' => 'admin.employees.index',
        'pos' => 'pos.index', 'branch' => 'admin.branches.index',
    ];

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

        $first = $panel
            ? collect(self::SECTIONS)->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
                ->first(fn ($s) => $user->allows($s))
            : null;

        // بلا صلاحية واحدة: تُرفض الآن عند الحفظ، لكن حسابًا قديمًا قد يسبقها
        return $first ? route(self::ROUTES[$first]) : route('login');
    }

    /** أسماء الأقسام كما تُعرض للتاجر — الواجهة لا تخمّنها من المفتاح */
    public static function sectionLabels(): array
    {
        $labels = [
            'dashboard' => 'لوحة التحكم', 'customers' => 'العملاء', 'products' => 'المنتجات',
            'orders' => 'الطلبات', 'marketing' => 'التسويق', 'inventory' => 'المخزون',
            'finance' => 'المالية', 'expenses' => 'المصروفات', 'reports' => 'التقارير',
            'settings' => 'الإعدادات', 'categories' => 'الأقسام', 'suppliers' => 'المورّدون',
            'purchases' => 'أوامر الشراء', 'profitability' => 'الربحية',
            'vat' => 'ضريبة القيمة المضافة', 'employees' => 'الموظفون',
            'pos' => 'نقطة البيع', 'branch' => 'الفروع',
        ];

        return collect(self::SECTIONS)
            ->mapWithKeys(fn ($s) => [$s => __($labels[$s] ?? $s)])
            ->all();
    }

    public static function sections(): array
    {
        return self::SECTIONS;
    }

    public static function abilities(?string $role): array
    {
        return self::MAP[$role] ?? ['*'];
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

    /** استخراج القسم من اسم المسار: admin.products.index → products */
    public static function sectionFromRoute(?string $route): string
    {
        if (! $route) {
            return 'dashboard';
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
        return $parts[1] ?? 'dashboard';
    }
}

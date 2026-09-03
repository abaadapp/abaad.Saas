import type { LucideIcon } from 'lucide-react';
import {
    ACCOUNTING_TABS,
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    PURCHASE_TABS,
    WEBSITE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import {
    AppWindow,
    BarChart3,
    Boxes,
    Building2,
    ClipboardList,
    FileSpreadsheet,
    FlaskConical,
    Globe,
    History,
    Layers,
    LayoutDashboard,
    Megaphone,
    MessageCircle,
    MessageSquare,
    Package,
    RefreshCw,
    Search,
    Settings,
    ShoppingCart,
    Star,
    TicketPercent,
    Truck,
    UserCog,
    Users,
    Wallet,
} from 'lucide-react';

export interface NavItem {
    label: string;
    icon: LucideIcon;
    route: string;
    /**
     * قسم الصلاحية — يُخفى العنصر إن لم يملكه المستخدم.
     * بلا قيمة يظهر دائمًا: قائمة المنصة يحرسها middleware الدور نفسه
     * (role:super_admin) لا صلاحيات الأقسام، فلا معنى لتصفيتها هنا.
     */
    section?: string;
    /**
     * صفحات تتبع هذا العنصر ولا مدخل لها في القائمة — تُبقيه مضيئًا.
     *
     * كلّ ما يُفتح من شريط تبويبات القسم: «القيود اليومية» و«شجرة الحسابات»
     * صفحتا المالية، و«سندات الموردين» صفحة المشتريات. وبلا هذا كان الضغط
     * عليها يُطفئ القائمة كلّها: لا عنصر مضيء، فلا يعرف من فتحها أين هو.
     */
    covers?: string[];
    /**
     * قائمةٌ منسدلة تحت العنصر — لا وجهةَ له هو.
     *
     * «أدوات التسويق» ستّ أدوات لا تجمعها صفحة: من يريد إعدادات الموقع لا
     * يمرّ بالكوبونات. فالعنصر يفتح ما تحته ولا ينقل إلى صفحةٍ جامعة.
     */
    children?: NavItem[];
}

export interface NavGroup {
    heading?: string;
    /**
     * مجموعةٌ تُدفع إلى أسفل الشريط.
     *
     * «الإعدادات» تُفتح مرّةً في الشهر ولا تُفتح مرّةً في الساعة، فمكانها
     * أسفل القائمة لا وسطها بين ما يُستعمل كلّ يوم.
     */
    footer?: boolean;
    items: NavItem[];
}

/**
 * صفحات الشريط مصدرها شريط تبويبات القسم نفسه.
 *
 * قائمةٌ ثانية مكتوبة باليد كانت ستفترق عن الأولى عند أوّل تبويب يُضاف —
 * فيظهر تبويبٌ لا يُضيء قسمه في القائمة.
 */
const covers = (tabs: SectionTab[]) => tabs.map((tb) => tb.routeName);

/**
 * القائمة الجانبية للوحة التاجر.
 *
 * المجموعات تفصلها خطوط لا عناوين: العناوين («المتجر»، «الإدارة») تسمية
 * لا تُقرأ — لا أحد يبحث عن «المالية» تحت «الإدارة»، ويبحث عن «المالية».
 * والخطّ يفصل بلا أن يشغل سطرًا ولا أن يدّعي معنى.
 */
export const NAV: NavGroup[] = [
    {
        items: [
            { label: 'لوحة التحكم', icon: LayoutDashboard, route: 'admin.dashboard', section: 'dashboard' },
            {
                label: 'العملاء',
                icon: Users,
                route: 'admin.customers.index',
                section: 'customers',
                covers: covers(CUSTOMER_TABS),
            },
        ],
    },
    {
        items: [
            { label: 'المنتجات', icon: Package, route: 'admin.products.index', section: 'products' },
            // «الطلبات» سابقًا: المتجر يبيع ولا يستقبل طلبات وحسب
            { label: 'المبيعات', icon: ShoppingCart, route: 'admin.orders.index', section: 'orders' },
            // لوحة التجهيز — قسمٌ مستقلّ، فمن مُنحه وحده يراه ولا يرى المبيعات
            { label: 'لوحة التجهيز', icon: ClipboardList, route: 'admin.preparation.index', section: 'preparation' },
            {
                label: 'المشتريات',
                icon: Truck,
                route: 'admin.purchases.index',
                section: 'purchases',
                covers: covers(PURCHASE_TABS),
            },
        ],
    },
    {
        items: [
            {
                label: 'المالية',
                icon: Wallet,
                route: 'admin.finance.summary',
                section: 'finance',
                covers: [...covers(FINANCE_TABS), 'admin.finance.statement'],
            },
            /*
             * المحاسبة المتقدّمة عنصرٌ مستقلّ لا تبويبٌ في المالية.
             *
             * وضعُها بين تبويبات المالية كان يجعلها على بُعد نقرةٍ من كلّ من
             * فتح القسم ليسجّل مصروفًا. وهي ليست شاشةً أعمق بل أدواتٌ أخرى:
             * من لا يعرف المحاسبة لا يجد فيها ما يريد، ومن يعرفها يعرف
             * أين يبحث. فيراها من مُنحها وحده — والصلاحية تُخفي العنصر.
             */
            {
                label: 'المحاسبة المتقدمة',
                icon: FileSpreadsheet,
                route: 'admin.finance.chart',
                section: 'accounting',
                covers: covers(ACCOUNTING_TABS),
            },
            {
                label: 'الرواتب والموظفين',
                icon: UserCog,
                route: 'admin.employees.index',
                section: 'employees',
                covers: covers(EMPLOYEE_TABS),
            },
        ],
    },
    {
        items: [
            {
                label: 'المخزون',
                icon: Boxes,
                route: 'admin.inventory.index',
                section: 'inventory',
                covers: covers(INVENTORY_TABS),
            },
        ],
    },
    {
        items: [
            {
                label: 'أدوات التسويق',
                icon: Megaphone,
                route: 'admin.marketing.loyalty',
                section: 'marketing',
                children: [
                    { label: 'برنامج ولاء', icon: Star, route: 'admin.marketing.loyalty', section: 'marketing' },
                    { label: 'تقييمات العملاء', icon: MessageSquare, route: 'admin.marketing.reviews', section: 'marketing' },
                    { label: 'الكوبونات والعروض', icon: TicketPercent, route: 'admin.marketing.coupons', section: 'marketing' },
                    { label: 'تحسين محركات البحث', icon: Search, route: 'admin.marketing.seo', section: 'marketing' },
                    { label: 'إشعارات واتساب', icon: MessageCircle, route: 'admin.marketing.whatsapp', section: 'marketing' },
                ],
            },
        ],
    },
    {
        /*
         * التقارير مجموعةٌ وحدها بين التسويق والإعدادات.
         *
         * وهي بابٌ لا شاشةُ أرقام: تجمع ما تفرّق في اثنتي عشرة شاشة وتقود
         * إليها. وموضعُها آخر ما يُقرأ لأنّها تُفتح بعد العمل لا قبله.
         */
        items: [{ label: 'التقارير', icon: BarChart3, route: 'admin.reports.index', section: 'reports' }],
    },
    {
        /*
         * الموقع الإلكتروني بندٌ في القائمة لا تبويبٌ داخل الإعدادات.
         *
         * كان يُوصَل إليه بثلاث نقرات: الإعدادات ‹ المتجر ‹ إعدادات الموقع.
         * وهو من أكثر ما يُفتح عند من له موقع — يبدّل صورةً، يضيف عرضًا،
         * ينشر تغييرًا — فمكانُه حيث يُفتح لا حيث يُنسى.
         */
        items: [
            {
                label: 'الموقع الإلكتروني',
                icon: AppWindow,
                route: 'admin.website.index',
                section: 'website',
                covers: covers(WEBSITE_TABS),
            },
        ],
    },
    {
        footer: true,
        items: [{ label: 'الإعدادات', icon: Settings, route: 'admin.settings.index', section: 'settings' }],
    },
];

/**
 * القائمة الجانبية للوحة المنصة — منقولة عن $menu في layouts/super-admin.blade.php.
 * تمرّ على نفس مكوّن Sidebar، فتُعرض بنفس ثيمة لوحة التاجر حرفيًا.
 */
export const PLATFORM_NAV: NavGroup[] = [
    {
        items: [{ label: 'الرئيسية', icon: LayoutDashboard, route: 'super-admin.dashboard' }],
    },
    {
        heading: 'الإدارة',
        items: [
            { label: 'الشركات', icon: Building2, route: 'super-admin.businesses.index' },
            { label: 'الاشتراكات', icon: RefreshCw, route: 'super-admin.subscriptions.index' },
            { label: 'الباقات', icon: Layers, route: 'super-admin.subscriptions.plans' },
            { label: 'المستخدمون', icon: Users, route: 'super-admin.users.index' },
            /*
             * طلبات النطاقات — شاشةٌ في القائمة لا صفحةٌ تُعرف بالرابط.
             *
             * التاجر يضغط «اطلب من أبعاد تجهيز نطاق» وينتظر إنسانًا. وطلبٌ
             * لا يظهر في قائمة المشغّل طلبٌ لا يراه إلا من كان يبحث عنه.
             */
            { label: 'طلبات النطاقات', icon: Globe, route: 'super-admin.domains.index' },
        ],
    },
    {
        heading: 'أخرى',
        items: [
            // الديمو قسمٌ قائم بذاته لا زرٌّ في الإعدادات: يُفتح في كل عرض
            { label: 'الديمو', icon: FlaskConical, route: 'super-admin.demo.index' },
            { label: 'التقارير', icon: BarChart3, route: 'super-admin.reports.index' },
            { label: 'سجل النشاط', icon: History, route: 'super-admin.activity.index' },
            { label: 'الإعدادات', icon: Settings, route: 'super-admin.settings.index' },
        ],
    },
];

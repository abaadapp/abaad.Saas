import type { LucideIcon } from 'lucide-react';
import {
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    PURCHASE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import {
    BarChart3,
    Boxes,
    Building2,
    ClipboardList,
    FlaskConical,
    History,
    Layers,
    LayoutDashboard,
    MapPin,
    Megaphone,
    MessageCircle,
    MessageSquare,
    Package,
    RefreshCw,
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
     * قدرةُ الباقة التي يفتحها هذا العنصر — يُخفى إن لم تشمله باقة المتجر.
     *
     * غير `section`: ذاك صلاحيةُ الموظّف، وهذا ما اشتراه صاحب المتجر. وبابٌ
     * يُعرض ويردّ بـ403 يجعل صاحبه يظنّ العطب في النظام ويعيد المحاولة.
     */
    feature?: string;
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
                route: 'admin.finance.index',
                section: 'finance',
                covers: [...covers(FINANCE_TABS), 'admin.finance.statement'],
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
        /*
         * التقارير مجموعةٌ وحدها بين المخزون والتسويق.
         *
         * وهي بابٌ لا شاشةُ أرقام: تجمع ما تفرّق في اثنتي عشرة شاشة وتقود
         * إليها.
         */
        items: [{ label: 'التقارير', icon: BarChart3, route: 'admin.reports.index', section: 'reports' }],
    },
    {
        items: [
            {
                label: 'أدوات التسويق',
                icon: Megaphone,
                route: 'admin.marketing.loyalty',
                section: 'marketing',
                children: [
                    { label: 'برنامج ولاء', icon: Star, route: 'admin.marketing.loyalty', section: 'marketing', feature: 'loyalty' },
                    { label: 'تقييمات العملاء', icon: MessageSquare, route: 'admin.marketing.reviews', section: 'marketing' },
                    { label: 'ربط خرائط Google', icon: MapPin, route: 'admin.marketing.google', section: 'marketing' },
                    { label: 'الكوبونات والعروض', icon: TicketPercent, route: 'admin.marketing.coupons', section: 'marketing' },
                    { label: 'إشعارات واتساب', icon: MessageCircle, route: 'admin.marketing.whatsapp', section: 'marketing', feature: 'whatsapp' },
                ],
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

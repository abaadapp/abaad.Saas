import type { LucideIcon } from 'lucide-react';
import {
    ArrowDownCircle,
    BarChart3,
    Boxes,
    Building2,
    Flower,
    History,
    Layers,
    LayoutDashboard,
    Megaphone,
    Package,
    RefreshCw,
    Settings,
    ShoppingCart,
    Store,
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
}

export interface NavGroup {
    heading?: string;
    items: NavItem[];
}

/**
 * القائمة الجانبية — منقولة عن $menu في layouts/admin.blade.php.
 * التسميات بالعربية لأنها مفاتيح الترجمة نفسها (كما في __() داخل Blade).
 */
export const NAV: NavGroup[] = [
    {
        items: [
            { label: 'الرئيسية', icon: LayoutDashboard, route: 'admin.dashboard', section: 'dashboard' },
            { label: 'العملاء', icon: Users, route: 'admin.customers.index', section: 'customers' },
        ],
    },
    {
        heading: 'المتجر',
        items: [
            { label: 'المنتجات', icon: Package, route: 'admin.products.index', section: 'products' },
            { label: 'الطلبات', icon: ShoppingCart, route: 'admin.orders.index', section: 'orders' },
            { label: 'التسويق والكوبونات', icon: Megaphone, route: 'admin.marketing.index', section: 'marketing' },
        ],
    },
    {
        heading: 'الإدارة',
        items: [
            { label: 'المخزون', icon: Boxes, route: 'admin.inventory.index', section: 'inventory' },
            { label: 'المالية', icon: Wallet, route: 'admin.finance.index', section: 'finance' },
            { label: 'المصروفات', icon: ArrowDownCircle, route: 'admin.expenses.index', section: 'expenses' },
            { label: 'التقارير', icon: BarChart3, route: 'admin.reports.index', section: 'reports' },
            { label: 'الإعدادات', icon: Settings, route: 'admin.settings.index', section: 'settings' },
        ],
    },
    {
        heading: 'نقطة البيع',
        items: [{ label: 'فتح نقطة البيع', icon: Store, route: 'pos.index', section: 'pos' }],
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
            { label: 'محلات الورود', icon: Flower, route: 'super-admin.flower-shops.index' },
            { label: 'الاشتراكات', icon: RefreshCw, route: 'super-admin.subscriptions.index' },
            { label: 'الباقات', icon: Layers, route: 'super-admin.subscriptions.plans' },
            { label: 'المستخدمون', icon: Users, route: 'super-admin.users.index' },
        ],
    },
    {
        heading: 'أخرى',
        items: [
            { label: 'التقارير', icon: BarChart3, route: 'super-admin.reports.index' },
            { label: 'سجل النشاط', icon: History, route: 'super-admin.activity.index' },
            { label: 'الإعدادات', icon: Settings, route: 'super-admin.settings.index' },
        ],
    },
];

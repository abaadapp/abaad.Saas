import type { LucideIcon } from 'lucide-react';
import {
    ArrowDownCircle,
    BarChart3,
    Boxes,
    LayoutDashboard,
    Megaphone,
    Package,
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
    /** قسم الصلاحية — يُخفى العنصر إن لم يملكه المستخدم */
    section: string;
}

export interface NavGroup {
    heading?: string;
    items: NavItem[];
}

/** القائمة الجانبية — منقولة عن $menu في layouts/admin.blade.php */
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

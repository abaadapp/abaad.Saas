import type { ComponentProps, ReactNode } from 'react';
import { Link } from '@inertiajs/react';

/**
 * أسماء المسارات التي صارت صفحات Inertia.
 *
 * ما ليس هنا ما زال Blade، ويجب زيارته بتنقّل كامل لا عبر <Link>:
 * رابط Inertia إلى وجهة Blade يستقبل HTML بدل JSON، فيُجبر المتصفح على
 * انتقال صلب تفشل فيه الأصول — وهو ما يجعل الرابط يبدو معطّلًا تمامًا.
 *
 * احذف الاسم من هنا فقط بعد أن تُحوَّل صفحته فعلًا.
 */
export const INERTIA_ROUTES = new Set<string>([
    'admin.dashboard',
    'admin.employees.index',
    'admin.suppliers.index',
    'admin.categories.index',
    'admin.inventory.index',
    'admin.products.index',
    'admin.customers.index',
    'admin.orders.index',
    'admin.activity.index',
    'admin.branches.index',
    'admin.addons.index',
    'admin.purchases.index',
    'admin.expenses.index',
    'admin.products.show',
    'admin.orders.show',
    'admin.products.create',
    'admin.products.edit',
    'admin.categories.create',
    'admin.inventory.movements',
    'admin.inventory.reorder',
    'admin.inventory.stocktake',
    'admin.vat.index',
    'admin.profitability.index',
    'admin.marketing.index',
    'admin.reports.index',
    'admin.analytics.index',
    'admin.employees.create',
    'admin.employees.edit',
    'admin.employees.show',
    'pos.index',
    'pos.orders',
    'pos.order-details',
    'pos.payments',
    'pos.receipts',
    'pos.customers',
    'pos.settings',
]);

export function isInertiaRoute(name: string): boolean {
    return INERTIA_ROUTES.has(name);
}

interface SmartLinkProps extends Omit<ComponentProps<'a'>, 'href'> {
    /** اسم المسار (لا عنوانه) حتى نعرف أمحوَّل هو أم لا */
    routeName: string;
    href: string;
    children: ReactNode;
}

/** يختار تلقائيًا بين تنقّل Inertia وتنقّل كامل حسب حالة الصفحة الهدف. */
export default function SmartLink({ routeName, href, children, ...props }: SmartLinkProps) {
    if (isInertiaRoute(routeName)) {
        // توقيع onClick في <Link> أعمّ منه في <a>؛ نوسّعه هنا بدل تضييق نوع الوسيط
        const linkProps = props as ComponentProps<typeof Link>;

        return (
            <Link href={href} {...linkProps}>
                {children}
            </Link>
        );
    }

    return (
        <a href={href} {...props}>
            {children}
        </a>
    );
}

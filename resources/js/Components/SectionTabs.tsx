import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface SectionTab {
    label: string;
    routeName: string;
    /** وسيط المسار إن كان يحتاجه */
    args?: unknown;
}

interface Props {
    tabs: SectionTab[];
    /** اسم مسار التبويب النشط */
    current: string;
    className?: string;
}

/**
 * شريط تبويبات القسم — بديل partials/products-tabs و inventory-tabs و تبويبات المصروفات.
 *
 * النشط يُحدَّد باسم المسار لا بالرابط: مسارات مثل المنتجات لها صفحات فرعية
 * (إنشاء/تعديل/عرض) يجب أن تُبقي تبويبها مضيئًا.
 */
export default function SectionTabs({ tabs, current, className }: Props) {
    const t = useTranslate();

    return (
        <div className={cn('mb-6 flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)]', className)}>
            {tabs.map((tab) => {
                // "admin.products.index" ينشط أيضًا على "admin.products.create"
                const family = tab.routeName.replace(/\.[^.]+$/, '');
                const active = current === tab.routeName || current.startsWith(family + '.');

                return (
                    <SmartLink
                        key={tab.routeName}
                        routeName={tab.routeName}
                        href={route(tab.routeName, tab.args as never)}
                        className={cn(
                            '-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors',
                            active
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        {t(tab.label)}
                    </SmartLink>
                );
            })}
        </div>
    );
}

/** تبويبات قسم المنتجات */
export const PRODUCT_TABS: SectionTab[] = [
    { label: 'الأقسام', routeName: 'admin.categories.index' },
    { label: 'المنتجات', routeName: 'admin.products.index' },
    { label: 'الإضافات', routeName: 'admin.addons.index' },
];

/** تبويبات قسم المخزون */
export const INVENTORY_TABS: SectionTab[] = [
    { label: 'المخزون', routeName: 'admin.inventory.index' },
    { label: 'إعادة الطلب', routeName: 'admin.inventory.reorder' },
    { label: 'الجرد الفعلي', routeName: 'admin.inventory.stocktake' },
    { label: 'المورّدون', routeName: 'admin.suppliers.index' },
    { label: 'أوامر الشراء', routeName: 'admin.purchases.index' },
    { label: 'حركات المخزون', routeName: 'admin.inventory.movements' },
];

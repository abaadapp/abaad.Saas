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

    /*
     * تبويب نشط واحد لا أكثر.
     *
     * المطابقة بالعائلة وحدها كانت تُضيء أربعة تبويبات معًا: عائلة
     * admin.inventory.overview و.index و.stocktake و.movements واحدة هي
     * admin.inventory، فكل صفحة مخزون تُطابقها جميعًا.
     *
     * فالمطابقة الحرفية تُقدَّم أولًا، ولا يُلجأ إلى العائلة إلا حين لا يطابق
     * أيُّ تبويب حرفيًا (صفحات فرعية مثل admin.products.create) — ويؤخذ عندها
     * أطولُ مسارٍ مطابق فيبقى الاختيار محدَّدًا لا عشوائيًا.
     */
    const activeRoute =
        tabs.find((tb) => tb.routeName === current)?.routeName ??
        tabs
            .filter((tb) => current.startsWith(tb.routeName.replace(/\.[^.]+$/, '') + '.'))
            .sort((a, b) => b.routeName.length - a.routeName.length)[0]?.routeName;

    return (
        <div className={cn('mb-6 flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)]', className)}>
            {tabs.map((tab) => {
                const active = tab.routeName === activeRoute;

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
/*
 * ستة مداخل لا أكثر. «إعادة الطلب» لم تعد تبويبًا: هي تنبيه في النظرة العامة
 * يقود إلى المنتجات مفلترة على «منخفض» — مدخل واحد للقائمة نفسها بدل مدخلين
 * يعرضان الشيء ذاته بترتيب مختلف.
 */
export const INVENTORY_TABS: SectionTab[] = [
    { label: 'نظرة عامة', routeName: 'admin.inventory.overview' },
    { label: 'المنتجات والكميات', routeName: 'admin.inventory.index' },
    { label: 'أوامر الشراء', routeName: 'admin.purchases.index' },
    { label: 'الجرد', routeName: 'admin.inventory.stocktake' },
    { label: 'المورّدون', routeName: 'admin.suppliers.index' },
    { label: 'حركات المخزون', routeName: 'admin.inventory.movements' },
];

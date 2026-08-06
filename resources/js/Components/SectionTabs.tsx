import { usePage } from '@inertiajs/react';
import SmartLink from '@/Components/SmartLink';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

export interface SectionTab {
    label: string;
    routeName: string;
    /** وسيط المسار إن كان يحتاجه */
    args?: unknown;
    /**
     * قسم الصلاحية — يُخفى التبويب إن لم يملكه المستخدم.
     *
     * بلا هذا كان الشريط يعرض التبويبات كاملةً لكل من فتح الصفحة، فمحاسبٌ
     * مُنح «التقارير» ولم يُمنح «الربحية» يرى تبويبها ويضغطه فيصطدم بـ403.
     * والقائمة الجانبية تُخفي ما لا يُملك منذ البداية — فيفترق البابان على
     * الشيء نفسه: هذا يخفيه وذاك يعرضه.
     */
    section?: string;
}

interface Props {
    tabs: SectionTab[];
    /** اسم مسار التبويب النشط */
    current: string;
    className?: string;
    /**
     * `underline` خطٌّ تحت النشط — الافتراضي.
     * `segmented` شريط مقسَّم: حاوية رمادية والنشط بطاقة بيضاء بعرض متساوٍ.
     *
     * يطابق نمط Tabs بالاسم نفسه، فلا يفترق شكل تبويبات المسارات عن تبويبات
     * داخل الصفحة.
     */
    variant?: 'underline' | 'segmented';
}

/**
 * شريط تبويبات القسم — بديل partials/products-tabs و inventory-tabs و تبويبات المصروفات.
 *
 * النشط يُحدَّد باسم المسار لا بالرابط: مسارات مثل المنتجات لها صفحات فرعية
 * (إنشاء/تعديل/عرض) يجب أن تُبقي تبويبها مضيئًا.
 */
export default function SectionTabs({ tabs: all, current, className, variant = 'underline' }: Props) {
    const t = useTranslate();
    const { auth } = usePage<PageProps>().props;
    const segmented = variant === 'segmented';

    // بلا قسم يظهر التبويب دائمًا — انظر SectionTab.section
    const tabs = all.filter((tb) => !tb.section || (auth?.abilities.includes(tb.section) ?? false));

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
    // تبويبٌ واحد ليس شريطًا: من لا يملك إلا قسمًا من القسم يرى عنوان صفحته
    // مرسومًا كتبويب لا ينقله إلى شيء
    if (tabs.length < 2) {
        return null;
    }

    const activeRoute =
        tabs.find((tb) => tb.routeName === current)?.routeName ??
        tabs
            .filter((tb) => current.startsWith(tb.routeName.replace(/\.[^.]+$/, '') + '.'))
            .sort((a, b) => b.routeName.length - a.routeName.length)[0]?.routeName;

    return (
        <div
            className={cn(
                'mb-6 flex items-center gap-1 overflow-x-auto',
                segmented
                    ? 'w-full rounded-[14px] bg-[#f4f4f2] p-1.5'
                    : 'border-b border-[var(--ui-border,#e8e8e8)]',
                className,
            )}
        >
            {tabs.map((tab) => {
                const active = tab.routeName === activeRoute;

                return (
                    <SmartLink
                        key={tab.routeName}
                        routeName={tab.routeName}
                        href={route(tab.routeName, tab.args as never)}
                        className={cn(
                            'whitespace-nowrap text-sm font-medium transition-colors',
                            // النشط يتبدّل لونًا وظلًّا فقط — لا حدًّا ولا حجمًا
                            segmented
                                ? cn(
                                      'flex-1 rounded-[10px] px-4 py-2.5 text-center',
                                      active
                                          ? 'bg-white text-[#111] shadow-[0_1px_3px_rgba(0,0,0,0.08)]'
                                          : 'text-[#6b7280] hover:text-[#374151]',
                                  )
                                : cn(
                                      '-mb-px border-b-2 px-4 py-3',
                                      active
                                          ? 'border-[#111] text-[#111]'
                                          : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                                  ),
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
    { label: 'الأقسام', routeName: 'admin.categories.index', section: 'categories' },
    { label: 'المنتجات', routeName: 'admin.products.index', section: 'products' },
    { label: 'الإضافات', routeName: 'admin.addons.index', section: 'products' },
];

/** تبويبات قسم المخزون */
/*
 * ستة مداخل لا أكثر. «إعادة الطلب» لم تعد تبويبًا: هي تنبيه في النظرة العامة
 * يقود إلى المنتجات مفلترة على «منخفض» — مدخل واحد للقائمة نفسها بدل مدخلين
 * يعرضان الشيء ذاته بترتيب مختلف.
 */
/*
 * قسم المالية. الربحية والضريبة خارجه عمدًا: تابعتان للتقارير، ولهما
 * شريطهما في REPORTS_TABS أدناه.
 */
export const FINANCE_TABS: SectionTab[] = [
    { label: 'المالية', routeName: 'admin.finance.index', section: 'finance' },
    { label: 'المصروفات', routeName: 'admin.expenses.index', section: 'expenses' },
    { label: 'كشف الحساب البنكي', routeName: 'admin.finance.statement', section: 'finance' },
    { label: 'الورديات', routeName: 'admin.shifts.index', section: 'finance' },
];

/*
 * قسم التقارير. كانت الثلاثة الأخيرة بطاقاتٍ في وسط صفحة التقارير: تُفتح
 * بنقرة، ثم لا سبيل للانتقال بينها إلا بالرجوع إلى التقارير أولًا — نقرتان
 * لكل تنقّل. الشريط يجعلها كالمالية: نقرة واحدة من أي صفحة إلى أختها.
 */
export const REPORTS_TABS: SectionTab[] = [
    { label: 'التقارير', routeName: 'admin.reports.index', section: 'reports' },
    { label: 'تحليلات متقدمة', routeName: 'admin.analytics.index', section: 'reports' },
    { label: 'الربحية', routeName: 'admin.profitability.index', section: 'profitability' },
    { label: 'ضريبة القيمة المضافة', routeName: 'admin.vat.index', section: 'vat' },
];

export const INVENTORY_TABS: SectionTab[] = [
    { label: 'نظرة عامة', routeName: 'admin.inventory.overview', section: 'inventory' },
    { label: 'المنتجات والكميات', routeName: 'admin.inventory.index', section: 'inventory' },
    { label: 'أوامر الشراء', routeName: 'admin.purchases.index', section: 'purchases' },
    { label: 'الجرد', routeName: 'admin.inventory.stocktake', section: 'inventory' },
    { label: 'المورّدون', routeName: 'admin.suppliers.index', section: 'suppliers' },
    { label: 'حركات المخزون', routeName: 'admin.inventory.movements', section: 'inventory' },
];

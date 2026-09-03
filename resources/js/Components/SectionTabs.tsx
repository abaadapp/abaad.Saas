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
     * بلا هذا كان الشريط يعرض التبويبات كاملةً لكل من فتح الصفحة، فمن مُنح
     * «المالية» ولم يُمنح «المصروفات» يرى تبويبها ويضغطه فيصطدم بـ403.
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
}

/**
 * شريط تبويبات القسم — بديل partials/products-tabs و inventory-tabs و تبويبات المصروفات.
 *
 * النشط يُحدَّد باسم المسار لا بالرابط: مسارات مثل المنتجات لها صفحات فرعية
 * (إنشاء/تعديل/عرض) يجب أن تُبقي تبويبها مضيئًا.
 *
 * وشكلٌ واحد لا خيار فيه: خطٌّ تحت النشط. كان الشكل خيارًا بين `underline`
 * و`segmented`، فانقسم النظام شريطين — تسعة عشر موضعًا على هذا وخمسةٌ على
 * ذاك — لا لأن أحدًا قرّر، بل لأن كل شاشةٍ جديدة تنسخ ما جاورها. والخيار
 * الذي لا يُتَّخذ مرّةً واحدة يُتَّخذ في كل ملفٍّ من جديد، فنُزع من جذره:
 * لا يعود الانقسام إلا بتعديل هذا الملفّ نفسه.
 */
export default function SectionTabs({ tabs: all, current, className }: Props) {
    const t = useTranslate();
    const { auth } = usePage<PageProps>().props;

    // بلا قسم يظهر التبويب دائمًا — انظر SectionTab.section
    const tabs = all.filter((tb) => !tb.section || (auth?.abilities.includes(tb.section) ?? false));

    /*
     * تبويب نشط واحد لا أكثر.
     *
     * المطابقة بالعائلة وحدها كانت تُضيء تبويبات القسم كلّها معًا: عائلة
     * admin.inventory.index و.stocktake و.adjustments واحدة هي
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
                'mb-6 flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)]',
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
                            // ‏-mb-px يرفع حدّ التبويب فوق حدّ الشريط فيحلّ محلّه،
                            // ولولاه لظهر خطّان متجاوران تحت النشط
                            '-mb-px border-b-2 px-4 py-3',
                            // النشط يتبدّل لونًا لا حجمًا — فلا يقفز ما تحته
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

/**
 * قسم العملاء — العملاء والمورّدون.
 *
 * كلاهما جهةٌ يتعامل معها المتجر: هذه تشتري منه وتلك يشتري منها. وكان
 * المورّدون في المخزون، فيبحث عنهم من يريد بياناتهم حيث تُعدّ البضاعة.
 */
export const CUSTOMER_TABS: SectionTab[] = [
    { label: 'العملاء', routeName: 'admin.customers.index', section: 'customers' },
    { label: 'الموردين', routeName: 'admin.suppliers.index', section: 'suppliers' },
];

/**
 * قسم المنتجات — مدخلٌ واحد.
 *
 * الأقسام والإضافات حُذفتا، والشريط يختفي من نفسه حين لا يبقى إلا تبويب
 * (انظر SectionTabs: تبويبٌ واحد ليس شريطًا).
 */
export const PRODUCT_TABS: SectionTab[] = [
    { label: 'المنتجات', routeName: 'admin.products.index', section: 'products' },
];

/*
 * قسم المالية — ما يُفتح كلّ يوم.
 *
 * الترتيب يتبع ما يُسأل عنه أوّلًا: «كم عندي وكم ربحت؟» ثمّ «ماذا جرى؟» ثمّ
 * «ماذا أنفقت؟» ثمّ «ماذا عليّ؟» ثمّ «أين حساباتي؟».
 *
 * وشجرةُ الحسابات والقيود اليومية والأصول الثابتة خرجت من هنا إلى
 * `ACCOUNTING_TABS`: كانت خمسُها شريطًا واحدًا، فيفتح الموظّف «المالية»
 * ليسجّل مصروفًا فيقع على شجرة حسابات المتجر وزرِّ «قيد يدويّ» — أدواتٌ لا
 * يُفسدها إلا من لا يعرف ماذا تفعل.
 *
 * وكشف الحساب البنكي ليس تبويبًا: هو صفحةُ حسابٍ بعينه تُفتح من قائمة
 * الحسابات، فبقاؤه تبويبًا كان يعني بابًا يقود إلى «أوّل حساب» أيًّا كان.
 */
export const FINANCE_TABS: SectionTab[] = [
    { label: 'الملخّص المالي', routeName: 'admin.finance.summary', section: 'finance' },
    { label: 'الحركة المالية', routeName: 'admin.finance.transactions', section: 'finance' },
    { label: 'المصروفات', routeName: 'admin.expenses.index', section: 'expenses' },
    { label: 'المبالغ المستحقة', routeName: 'admin.finance.dues', section: 'finance' },
    { label: 'الحسابات البنكية', routeName: 'admin.finance.index', section: 'finance' },
];

/*
 * المحاسبة المتقدّمة — ما لا يُفتح إلا بعلم.
 *
 * شجرةُ الحسابات وميزانُ المراجعة في شاشةٍ واحدة (الميزان أسفل الشجرة)، ثمّ
 * القيود اليومية، ثمّ الأصول الثابتة وإهلاكها. وكلّها تحت `accounting` — لا
 * `finance`: قيدٌ يدويّ مختلّ لا يُكتشف أثره إلا في ميزان المراجعة بعد شهور.
 */
export const ACCOUNTING_TABS: SectionTab[] = [
    { label: 'شجرة الحسابات وميزان المراجعة', routeName: 'admin.finance.chart', section: 'accounting' },
    { label: 'القيود اليومية', routeName: 'admin.finance.journal', section: 'accounting' },
    { label: 'أصول ثابتة', routeName: 'admin.finance.assets', section: 'accounting' },
];

/*
 * قسم الموقع الإلكتروني — مرتَّبٌ على ما يريد التاجر إنجازه.
 *
 * لا «الأساسية» و«أخرى»: تلك تسمياتٌ تصف مكانَ الحقل في الشاشة لا ما يريده
 * من يفتحها. ومن يفتح موقعه يريد أن يرى حاله، أو يعدّل صفحة، أو يبدّل لونًا،
 * أو يضبط متجره، أو يظهر في غوغل — فهذه هي التبويبات.
 *
 * والترتيب ترتيبُ الاستعمال: اللوحةُ أوّلًا لأنها ما يُفتح كلّ مرّة، والسيو
 * والدومين آخرًا لأنّهما يُضبطان مرّةً ثم لا يُفتحان.
 */
export const WEBSITE_TABS: SectionTab[] = [
    { label: 'الموقع', routeName: 'admin.website.index', section: 'website' },
    { label: 'الصفحات', routeName: 'admin.website.pages', section: 'website' },
    { label: 'التصميم', routeName: 'admin.website.design', section: 'website' },
    { label: 'المتجر', routeName: 'admin.website.shop', section: 'website' },
    { label: 'الظهور في البحث', routeName: 'admin.website.seo', section: 'website' },
];


/*
 * قسم الرواتب والموظفين.
 *
 * المسيرة والصرف شاشتان لا واحدة: الأولى تُحضّر وتعتمد، والثانية تُخرج المال.
 * ودمجُهما يجعل زرَّ الاعتماد وزرَّ الصرف متجاورين على شاشةٍ واحدة، فيُضغط
 * الثاني قبل مراجعة الأوّل.
 */
export const EMPLOYEE_TABS: SectionTab[] = [
    { label: 'الموظفين', routeName: 'admin.employees.index', section: 'employees' },
    { label: 'مسيرة الرواتب', routeName: 'admin.payroll.index', section: 'employees' },
    { label: 'صرف الرواتب', routeName: 'admin.payroll.payments', section: 'employees' },
];

/*
 * قسم المشتريات — القائمة ثمّ السندات ثمّ الأوامر.
 *
 * الترتيب يتبع ما يُسأل عنه أكثر: «ماذا اشتريتُ؟» أوّلًا، ثمّ «ماذا عليّ؟»،
 * ثمّ «ماذا طلبتُ ولم يصل؟».
 */
export const PURCHASE_TABS: SectionTab[] = [
    { label: 'قائمة المشتريات', routeName: 'admin.purchases.index', section: 'purchases' },
    { label: 'سندات الموردين', routeName: 'admin.purchases.invoices', section: 'purchases' },
    { label: 'أوامر الشراء', routeName: 'admin.purchases.orders', section: 'purchases' },
];

/*
 * قسم المخزون — خمسة مداخل.
 *
 * أوامر الشراء والمورّدون خرجا منه إلى «المشتريات»: بابٌ واحد لكلٍّ منهما لا
 * بابان في قسمين. والتحويل بين الفروع وحركات المخزون قراءاتٌ من قراءات
 * «المنتجات»، فلا تُفرد لهما تبويبات.
 */
export const INVENTORY_TABS: SectionTab[] = [
    { label: 'المنتجات', routeName: 'admin.inventory.index', section: 'inventory' },
    { label: 'عمليات جرد المخزون', routeName: 'admin.inventory.stocktake', section: 'inventory' },
    { label: 'سجل المخزون', routeName: 'admin.inventory.adjustments', section: 'inventory' },
    { label: 'إشعار استلام بضاعة', routeName: 'admin.inventory.receipts', section: 'inventory' },
];

import type { LucideIcon } from 'lucide-react';
import { BarChart3, FileText, Store, UserCircle } from 'lucide-react';
import {
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    PRODUCT_TABS,
    PURCHASE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import { SETTINGS_NAV } from '@/Pages/Admin/Settings/partials/SettingsNav';
import { NAV, PLATFORM_NAV, type NavGroup, type NavItem } from '@/lib/nav';
import type { SharedProps } from '@/types';

/** صفحةٌ في الدليل — ما يكفي لعرضها في القائمة وفتحها */
export interface PageEntry {
    label: string;
    icon: LucideIcon;
    /** الوجهة مبنيّةً بـ`route()` — الدليل يُبنى عند العرض لا عند الاستيراد */
    href: string;
    /** عنوان المجموعة التي تضمّه في القائمة */
    group: string;
    /** قسم الصلاحية — يُخفى ما لا يملكه المستخدم، كما تفعل القائمة الجانبية */
    section?: string;
    /** قدرة الباقة — يُخفى ما لم يشمله اشتراك المتجر */
    feature?: string;
}

/** تقريرٌ كما يرسله الخادم في المشترَك — انظر HandleInertiaRequests */
export interface ReportLink {
    title: string;
    href: string;
}

/**
 * نصٌّ يُقارَن به — لا كما كُتب.
 *
 * العربية تُكتب الكلمة الواحدة بأشكال: «الإعدادات» و«الاعدادات»، «الفواتير»
 * و«الفواتير» بتطويلٍ بينها، «الرواتب» و«الرّواتب» بشدّة. ومن يبحث يكتب
 * أسرعَ ما تصل إليه يده — بلا همزةٍ ولا شدّة. فمطابقةٌ حرفًا بحرف تردّ «لا
 * نتائج» على اسمٍ مكتوبٍ في القائمة أمام عينه.
 *
 * والياء والألف المقصورة كذلك، والتاء المربوطة والهاء: «التجهيز» تُكتب
 * بالطريقتين، و«صفحة» و«صفحه» يقصدان الشيء نفسه.
 */
export function fold(text: string): string {
    return text
        .toLowerCase()
        .replace(/[ً-ْـ]/g, '')
        .replace(/[آأإٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ')
        .trim();
}

/** أوراقُ القائمة: العنصر نفسه، أو أبناؤه إن كان قائمةً منسدلة لا وجهةَ لها */
const leaves = (groups: NavGroup[]): NavItem[] =>
    groups.flatMap((g) => g.items).flatMap((i) => i.children ?? [i]);

/**
 * تبويبات الأقسام كلّها في قائمةٍ واحدة.
 *
 * وهي مصدرُ الشريط نفسه — فتبويبٌ يُضاف هناك يظهر هنا بلا أن يُكتب مرّتين،
 * وقائمتان مكتوبتان باليد تفترقان عند أوّل إضافة.
 */
const SECTION_TABS: readonly SectionTab[] = [
    ...CUSTOMER_TABS,
    ...PRODUCT_TABS,
    ...FINANCE_TABS,
    ...EMPLOYEE_TABS,
    ...PURCHASE_TABS,
    ...INVENTORY_TABS,
];

type SettingsItem = {
    key: string;
    label: string;
    desc: string;
    icon: LucideIcon;
    section?: string;
};

/**
 * دليلُ صفحات لوحة التاجر — كلُّ ما يُفتح، بأيقونته ووجهته.
 *
 * يُقرأ في الشريط العلوي: من ينقر حقل البحث يرى النظام كلَّه مبسوطًا أمامه
 * بدل صندوقٍ فارغ لا يقول ما يقبل. وأكثرُ ما يُطلب من صندوق بحثٍ في لوحةٍ
 * ليس صفًّا في جدول بل صفحةً يعرف صاحبُها اسمها ولا يعرف تحت أيّ قسمٍ
 * دُفنت — «سجل المخزون» تحت المخزون، و«صرف الرواتب» تحت الموظفين، و«شجرة
 * الحسابات» تحت الإعدادات وتحت المالية معًا.
 *
 * ولا يُكتب هنا اسمُ صفحةٍ ولا مسارُها: كلّه مقروءٌ من مصادره — القائمة
 * الجانبية، وتبويبات الأقسام، وبطاقات الإعدادات، وفهرس التقارير عند
 * الخادم. فصفحةٌ تُضاف إلى أيٍّ منها تدخل الدليل من نفسها، وصفحةٌ تُحذف
 * تخرج منه. ودليلٌ مكتوبٌ باليد كان سيَعِد بعد شهرٍ بأبوابٍ أُغلقت.
 */
export function adminPages(reports: ReportLink[] = []): PageEntry[] {
    const top = NAV.flatMap((g) => g.items);
    const pages: PageEntry[] = [];
    const seen = new Set<string>();

    /*
     * الوجهة هي الهويّة.
     *
     * «المالية» في القائمة الجانبية و«الحسابات البنكية» في تبويباتها صفحةٌ
     * واحدة بمسارٍ واحد. وعرضُها مرّتين باسمين يجعل القارئ يظنّهما بابين.
     * والأوّل يبقى: اسمُ القائمة الجانبية هو الذي يعرفه المستخدم.
     */
    const add = (page: PageEntry) => {
        if (seen.has(page.href)) return;
        seen.add(page.href);
        pages.push(page);
    };

    for (const item of leaves(NAV)) {
        add({
            label: item.label,
            icon: item.icon,
            href: route(item.route),
            group: 'أقسام النظام',
            section: item.section,
            feature: item.feature,
        });
    }

    /*
     * وتبويبُ القسم يرث أيقونة قسمه.
     *
     * `SectionTab` لا تحمل أيقونة — الشريط يرسمها نصًّا. والقائمة هنا
     * أيقوناتٌ، فتُؤخذ من العنصر الذي يغطّي التبويب في القائمة الجانبية:
     * «سجل المخزون» بأيقونة المخزون، و«صرف الرواتب» بأيقونة الموظفين. وهو
     * أصدق من أيقونةٍ واحدة للجميع: العين تجد القسم قبل أن تقرأ الاسم.
     */
    for (const tab of SECTION_TABS) {
        const owner = top.find((i) => i.route === tab.routeName || i.covers?.includes(tab.routeName));

        add({
            label: tab.label,
            icon: owner?.icon ?? FileText,
            href: route(tab.routeName, tab.args as never),
            group: 'أقسام النظام',
            section: tab.section ?? owner?.section,
            feature: owner?.feature,
        });
    }

    // نقطة البيع — شاشةٌ خارج اللوحة، فلا مدخل لها في القائمة الجانبية
    add({ label: 'نقطة البيع', icon: Store, href: route('pos.index'), group: 'أقسام النظام', section: 'pos' });

    /*
     * وأقسام الإعدادات تُفتح بـ`?section=` لا بالمرساة.
     *
     * المرساة لا تصل الخادم، وأقسامٌ منها بياناتُها عنده (الفروع والأجهزة
     * وسجل النشاط). والمعامل يعمل للجميع — انظر `tabFromUrl` — فوجهةٌ
     * واحدة لكلّها أسلم من قاعدةٍ تُنسخ هنا وتتقادم هناك.
     */
    for (const group of SETTINGS_NAV) {
        for (const item of group.items as readonly SettingsItem[]) {
            add({
                label: item.label,
                icon: item.icon,
                href: route('admin.settings.index', { section: item.key }),
                group: 'الإعدادات',
                section: item.section ?? 'settings',
            });
        }
    }

    /*
     * والتقارير تصل من الخادم مُصفّاةً.
     *
     * فهرسُها `App\Support\Reports::ALL` — وفيه عنوانُ كلّ تقرير وقسمُه
     * وقدرةُ باقته. ونسخُه إلى الواجهة يعني فهرسين يفترقان، فيُرسَل مبنيًّا
     * في المشترَك: `Reports::forUser` تُسقط ما لا يملكه المستخدم وما لا
     * تفتحه باقته قبل أن يصل. ولذلك لا `section` لها هنا — صُفّيت قبل أن
     * تُكتب.
     */
    for (const report of reports) {
        add({ label: report.title, icon: BarChart3, href: report.href, group: 'التقارير' });
    }

    add({ label: 'إدارة حسابك', icon: UserCircle, href: route('profile.edit'), group: 'حسابك' });

    return pages;
}

/** دليلُ صفحات لوحة المنصة — قائمتُها وحدها، فلا تبويبات لها ولا إعدادات أقسام */
export function platformPages(): PageEntry[] {
    return leaves(PLATFORM_NAV).map((item) => ({
        label: item.label,
        icon: item.icon,
        href: route(item.route),
        group: 'أقسام النظام',
    }));
}

/**
 * ما يراه هذا المستخدم من الدليل.
 *
 * القاعدةُ قاعدةُ القائمة الجانبية نفسها: الصلاحية تُخفي القسم، والباقة
 * تُخفي ما لم يُشترَ. ودليلٌ يعرض ما لا يُفتح يقود إلى ٤٠٣ — ويجعل صاحبه
 * يظنّ العطب في النظام فيعيد المحاولة.
 *
 * والغائب مفتوح: صفحةٌ قديمة في المتصفّح لا تحمل خريطة الباقة يجب ألّا
 * تُخفي على صاحبها ما اشتراه، والخادم هو الحارس على كل حال.
 */
export function visibleTo(pages: PageEntry[], auth: SharedProps['auth']): PageEntry[] {
    return pages.filter(
        (p) =>
            (!p.section || (auth?.abilities.includes(p.section) ?? false)) &&
            (!p.feature || (auth?.planFeatures?.[p.feature] ?? true)),
    );
}

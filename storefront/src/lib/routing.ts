import type { DocPage, SiteDocument } from '@/site/types';

/**
 * المسار ← الصفحة.
 *
 * الصفحات في المستند لا في المستودع: التاجر ينشئ «سياسة الاستبدال» في أبعاد
 * فتظهر على `/refunds` بلا نشرةِ كود. ومسارٌ لكلّ صفحةٍ يعني أنّ إضافة صفحةٍ
 * تحتاج مبرمجًا — وهو ما بُني الموقعُ كلُّه لتفاديه.
 */

/** `['about','us']` ← `/about/us`، ولا شيء ← `/` */
export function pathOf(slug: string[] | undefined): string {
    if (!slug || slug.length === 0) return '/';

    return `/${slug.map((s) => decodeURIComponent(s)).join('/')}`;
}

/** توحيد الشكل: `/about/` و`about` و`/about` مسارٌ واحد */
export function normalizeSlug(slug: string): string {
    const trimmed = slug.replace(/^\/+|\/+$/g, '');

    return trimmed === '' ? '/' : `/${trimmed}`;
}

export function match(doc: SiteDocument, path: string): DocPage | undefined {
    const wanted = normalizeSlug(path);

    return doc.pages.find((p) => normalizeSlug(p.slug) === wanted);
}

/**
 * أتُفتح هذه الصفحة لزائر؟
 *
 * «مسوّدة» لا تُفتح — هي عملٌ لم يُرضَ عنه بعد. و«مخفية من القائمة» تُفتح
 * برابطها ولا تظهر في القائمة، وهو معنى الإخفاء بالضبط: صفحةُ عرضٍ تُرسل
 * برابطها ولا يعثر عليها من يتصفّح.
 */
export function open(page: DocPage | undefined): DocPage | undefined {
    if (!page) return undefined;

    return page.status === 'draft' ? undefined : page;
}

/** الصفحة المطلوبة، جاهزةً للعرض — أو undefined */
export function resolvePage(doc: SiteDocument, slug: string[] | undefined): DocPage | undefined {
    return open(match(doc, pathOf(slug)));
}

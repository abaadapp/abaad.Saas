import type { Metadata } from 'next';
import type { DocPage, SiteDocument } from '@/site/types';

/**
 * الوسوم — من الصفحة أوّلًا، ثمّ من الموقع، ثمّ من هويّة النشاط.
 *
 * وثلاثتُها موجودةٌ في المستند، فلا يُخترع منها شيء. والسقوطُ من واحدةٍ إلى
 * التي تحتها هو ما يجعل تاجرًا لم يفتح شاشة السيو قطّ يخرج بعنوانٍ ووصفٍ
 * معقولين — بدل «Untitled» في نتيجة غوغل.
 *
 * وهذه وسومٌ يرسمها الخادم في `<head>` لا `document.title` بعد التحميل:
 * زاحفُ فيسبوك وواتساب لا ينتظر JavaScript، فبطاقةُ الرابط تبقى فارغة.
 */

const MAX_TITLE = 70;
const MAX_DESCRIPTION = 170;

function clip(text: string, max: number): string {
    const clean = text.replace(/\s+/g, ' ').trim();

    return clean.length > max ? `${clean.slice(0, max - 1).trimEnd()}…` : clean;
}

function firstOf(...values: (string | null | undefined)[]): string {
    for (const v of values) {
        if (typeof v === 'string' && v.trim() !== '') return v.trim();
    }

    return '';
}

export function pageTitle(doc: SiteDocument, page: DocPage | undefined): string {
    const name = firstOf(doc.brand?.name, doc.name, 'متجر');
    const own = firstOf(page?.seo?.title);

    if (own) return clip(own, MAX_TITLE);

    // والرئيسية لا تُسمّى «الرئيسية»: اسم المتجر وجملتُه أنفعُ في نتيجة بحث
    if (!page || page.is_home) {
        const site = firstOf(doc.seo?.title);

        if (site) return clip(site, MAX_TITLE);

        const tagline = firstOf(doc.brand?.tagline);

        return clip(tagline ? `${name} — ${tagline}` : name, MAX_TITLE);
    }

    return clip(`${page.title} — ${name}`, MAX_TITLE);
}

export function pageDescription(doc: SiteDocument, page: DocPage | undefined): string {
    return clip(firstOf(page?.seo?.description, doc.seo?.description, doc.brand?.tagline), MAX_DESCRIPTION);
}

export function pageImage(doc: SiteDocument, page: DocPage | undefined): string {
    return firstOf(page?.seo?.image, doc.seo?.image, doc.brand?.logo);
}

/**
 * وسوم الصفحة كاملةً.
 *
 * `index: false` تُطاع كما هي: التاجر الذي أطفأ الظهور في البحث أطفأه لسبب —
 * موقعٌ قيد الإعداد، أو متجرٌ يخدم زبائن يعرفهم.
 */
export function buildMetadata(doc: SiteDocument, page: DocPage | undefined, origin: string, path: string): Metadata {
    const title = pageTitle(doc, page);
    const description = pageDescription(doc, page);
    const image = pageImage(doc, page);
    const canonical = `${origin}${path === '/' ? '' : path}` || origin;
    const indexable = doc.seo?.index !== false;
    const name = firstOf(doc.brand?.name, doc.name);

    return {
        // بلا هذا يبني Next روابط الصور النسبية على عنوان الخادم الداخليّ
        metadataBase: new URL(origin),
        title,
        description: description || undefined,
        alternates: { canonical },
        robots: indexable
            ? { index: true, follow: true }
            : { index: false, follow: false, googleBot: { index: false, follow: false } },
        openGraph: {
            type: 'website',
            siteName: name || undefined,
            title,
            description: description || undefined,
            url: canonical,
            locale: doc.locale === 'en' ? 'en_US' : 'ar_AR',
            images: image ? [{ url: image }] : undefined,
        },
        twitter: {
            card: image ? 'summary_large_image' : 'summary',
            title,
            description: description || undefined,
            images: image ? [image] : undefined,
        },
    };
}

/**
 * بيانات منظَّمة — المتجر وأسئلتُه.
 *
 * غوغل يقرؤها فيعرض ساعات العمل والهاتف والأسئلة تحت النتيجة. وكلُّها من
 * المستند: لا حقلَ جديد يُسأل عنه التاجر.
 */
export function structuredData(doc: SiteDocument, page: DocPage | undefined, origin: string): object[] {
    const brand = doc.brand;
    const out: object[] = [];
    const name = firstOf(brand?.name, doc.name);

    if (name) {
        out.push({
            '@context': 'https://schema.org',
            '@type': brand?.address ? 'LocalBusiness' : 'Organization',
            name,
            url: origin,
            ...(brand?.logo ? { logo: brand.logo } : {}),
            ...(brand?.phone ? { telephone: brand.phone } : {}),
            ...(brand?.email ? { email: brand.email } : {}),
            ...(brand?.address ? { address: { '@type': 'PostalAddress', streetAddress: brand.address } } : {}),
            ...(brand?.social?.length ? { sameAs: brand.social.map((s) => s.url).filter(Boolean) } : {}),
        });
    }

    const faq = page?.sections?.find((s) => s.type === 'faq' && s.visible !== false);
    const items = Array.isArray(faq?.data?.items) ? (faq.data.items as { q?: string; a?: string }[]) : [];
    const answered = items.filter((i) => (i.q ?? '').trim() !== '' && (i.a ?? '').trim() !== '');

    if (answered.length > 0) {
        out.push({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            mainEntity: answered.map((i) => ({
                '@type': 'Question',
                name: i.q,
                acceptedAnswer: { '@type': 'Answer', text: i.a },
            })),
        });
    }

    return out;
}

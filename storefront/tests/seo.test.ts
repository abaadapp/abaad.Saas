import { describe, expect, it } from 'vitest';
import { buildMetadata, pageDescription, pageTitle, structuredData } from '@/lib/seo';
import { clone, homeOf, store } from './helpers';

/**
 * الوسوم — ما يقرؤه غوغل وواتساب قبل أن يرى أحدٌ الصفحة.
 */
describe('وسوم الصفحة', () => {
    const home = homeOf(store);
    const contact = store.pages.find((p) => p.key === 'contact')!;

    it('الرئيسية تحمل اسم المتجر لا كلمة «الرئيسية»', () => {
        expect(pageTitle(store, home)).toBe(store.seo!.title);
        expect(pageTitle(store, home)).not.toContain('الرئيسية');
    });

    it('صفحةٌ داخلية تحمل عنوانها ثمّ اسم المتجر', () => {
        expect(pageTitle(store, contact)).toBe(`${contact.title} — ${store.brand!.name}`);
    });

    it('سيو الصفحة يسبق سيو الموقع', () => {
        const doc = clone(store);
        const page = { ...contact, seo: { title: 'كلّمنا الآن', description: 'وصفُ الصفحة' } };

        expect(pageTitle(doc, page)).toBe('كلّمنا الآن');
        expect(pageDescription(doc, page)).toBe('وصفُ الصفحة');
    });

    it('بلا سيوٍ إطلاقًا يسقط على جملة النشاط لا على فراغ', () => {
        const doc = clone(store);

        doc.seo = null;
        expect(pageTitle(doc, home)).toContain(doc.brand!.name);
        expect(pageDescription(doc, home)).toBe(doc.brand!.tagline);
    });

    it('العنوان يُقصّ عند سبعين حرفًا والوصف عند مئةٍ وسبعين', () => {
        const doc = clone(store);

        doc.seo = { title: 'ع'.repeat(200), description: 'و'.repeat(400), index: true };
        expect(pageTitle(doc, home).length).toBeLessThanOrEqual(70);
        expect(pageDescription(doc, home).length).toBeLessThanOrEqual(170);
    });

    it('العنوان القانونيّ يتبع المسار الحاليّ', () => {
        const meta = buildMetadata(store, contact, 'https://wrood.om', '/contact');

        expect(meta.alternates?.canonical).toBe('https://wrood.om/contact');
        expect(buildMetadata(store, home, 'https://wrood.om', '/').alternates?.canonical).toBe('https://wrood.om');
    });

    it('بطاقة المشاركة تحمل عنوانًا ووصفًا وصورة', () => {
        const doc = clone(store);

        doc.seo = { ...doc.seo!, image: 'https://cdn.test/og.jpg' };
        const og = buildMetadata(doc, home, 'https://wrood.om', '/').openGraph!;

        expect(og.title).toBe(doc.seo.title);
        expect(og.images).toEqual([{ url: 'https://cdn.test/og.jpg' }]);
        expect((og as { locale?: string }).locale).toBe('ar_AR');
    });

    it('الصورة تسقط على شعار المتجر إن لم تُضبط', () => {
        const doc = clone(store);

        doc.seo = { ...doc.seo!, image: '' };
        doc.brand!.logo = 'https://app.test/storage/logo.png';

        expect(buildMetadata(doc, home, 'https://wrood.om', '/').openGraph?.images).toEqual([
            { url: 'https://app.test/storage/logo.png' },
        ]);
    });

    it('من أطفأ الظهور في البحث لا يُفهرَس', () => {
        const doc = clone(store);

        doc.seo = { ...doc.seo!, index: false };
        expect(buildMetadata(doc, home, 'https://wrood.om', '/').robots).toMatchObject({ index: false, follow: false });
    });

    it('البيانات المنظَّمة تصف النشاط بعنوانه وحساباته', () => {
        const [business] = structuredData(store, home, 'https://wrood.om') as Record<string, unknown>[];

        expect(business['@type']).toBe('LocalBusiness');
        expect(business.name).toBe(store.brand!.name);
        expect(business.telephone).toBe(store.brand!.phone);
        expect(business.sameAs).toContain(store.brand!.social[0].url);
    });

    it('صفحةٌ فيها أسئلة تُخرج FAQPage', () => {
        const doc = clone(store);
        const page = homeOf(doc);

        page.sections.push({
            type: 'faq',
            visible: true,
            source: null,
            data: { items: [{ q: 'متى تفتحون؟', a: 'من الثامنة صباحًا.' }, { q: '', a: '' }] },
        });

        const faq = structuredData(doc, page, 'https://wrood.om').find(
            (s) => (s as Record<string, unknown>)['@type'] === 'FAQPage',
        ) as Record<string, unknown>;

        expect((faq.mainEntity as unknown[]).length).toBe(1);
    });
});

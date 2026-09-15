import { describe, expect, it } from 'vitest';
import { match, normalizeSlug, open, pathOf, resolvePage } from '@/lib/routing';
import { clone, store } from './helpers';

/**
 * الصفحات ومساراتها — من المستند لا من المستودع.
 */
describe('المسار يصل إلى صفحته', () => {
    it('الجذر هو الرئيسية', () => {
        expect(pathOf(undefined)).toBe('/');
        expect(pathOf([])).toBe('/');
        expect(match(store, '/')!.is_home).toBe(true);
    });

    it('كلّ صفحةٍ في المستند تُفتح بمسارها', () => {
        for (const page of store.pages) {
            expect(match(store, page.slug)?.key, page.slug).toBe(page.key);
        }
    });

    it('الشرطة الزائدة لا تصنع صفحةً أخرى', () => {
        expect(normalizeSlug('/about/')).toBe('/about');
        expect(normalizeSlug('about')).toBe('/about');
        expect(normalizeSlug('')).toBe('/');
        expect(match(store, '/contact/')?.key).toBe('contact');
    });

    it('المسار العربيّ المرمَّز يُفكّ', () => {
        expect(pathOf(['%D9%85%D9%86-%D9%86%D8%AD%D9%86'])).toBe('/من-نحن');
    });

    it('مسارٌ لا صفحة له لا يُطابَق', () => {
        expect(match(store, '/nothing-here')).toBeUndefined();
        expect(resolvePage(store, ['nothing-here'])).toBeUndefined();
    });

    it('المسوّدة لا تُفتح، والمخفيّة تُفتح برابطها', () => {
        const doc = clone(store);
        const [, second] = doc.pages;

        second.status = 'draft';
        expect(resolvePage(doc, [second.slug.replace('/', '')])).toBeUndefined();

        second.status = 'hidden';
        expect(resolvePage(doc, [second.slug.replace('/', '')])?.key).toBe(second.key);
        expect(open(second)?.key).toBe(second.key);
    });

    it('مسارٌ متعدّد الأجزاء يُبنى كما هو', () => {
        expect(pathOf(['a', 'b', 'c'])).toBe('/a/b/c');
    });
});

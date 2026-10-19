import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import {
    freshSlug,
    keepShownCollections,
    parseCollections,
    serializeCollections,
    slugify,
    type StoreCollection,
    validSlug,
} from '@/Pages/Admin/Website/theme/CollectionsField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «مجموعات المتجر» في محرّر الواجهة — رفوفٌ يختار أصنافَها بيده.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الصفَّ قبل التذييل، وأنّ المجموعةَ تُنشأ وتُسمّى بلغتيها وتُوصف
 * وتُختار أصنافُها من أيّ فئةٍ وتُرتَّب وتُخفى وتُحذف بتأكيد — وأنّ «احفظ»
 * يُرسل مفتاحَها وحده نصَّ JSON بالترتيب الذي يراه، ولا يُرسل صنفًا أُخفي
 * بعد اختياره. وأنّ «عاين» يُرسل المسوّدةَ إلى رفّ المجموعة.
 *
 * وقواعدُ الحفظ نفسُها (المتجرُ والمعروضُ والصورةُ والرابط) تُحرس في
 * الخادم: `ARibbonShopCuratesItsOwnCollectionsTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key, hint: '', fixed: true, on: true, silent: null, source: null, fields: [], ...over,
});

const HEAD = row({ key: 'head', label: 'رأس المتجر', fields: [{ key: 'store_announcement_ar', kind: 'text', label: 'نص الشريط بالعربية' }] });
const COLLECTIONS = row({
    key: 'collections',
    label: 'مجموعات المتجر',
    fields: [{ key: 'store_collections', kind: 'collections', label: 'المجموعات', max: 3, max_products: 2 }],
});
const HERO = row({ key: 'hero', label: 'الواجهة', fields: [{ key: 'store_hero_title', kind: 'text', label: 'عنوان الواجهة — العربية' }] });
const FOOT = row({ key: 'foot', label: 'التذييل' });

const PRODUCTS = [
    { id: 36, name: 'بالون', image: null },
    { id: 37, name: 'شوكولاتة', image: null },
    { id: 38, name: 'ورد أحمر', image: '/storage/p/38.jpg' },
];

const one = (over: Partial<StoreCollection> = {}): StoreCollection => ({
    slug: 'graduation', title_ar: 'هدايا التخرّج', title_en: 'Graduation gifts', desc_ar: '', desc_en: '',
    image: '', visible: true, in_row: true, products: [38], ...over,
});

const draw = (collections = '') => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[{ key: 'home', path: '/', fixed: true, on: true, blocked: null }]}
            rows={[HEAD, COLLECTIONS, HERO, FOOT]}
            values={{ store_announcement_ar: '', store_hero_title: '', store_collections: collections }}
            order={['cats']}
            maxFeatured={4}
            products={PRODUCTS}
        />,
    );
};

const capture = () => {
    const sent: { url: string; data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ url, data });
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

const press = (name: string) => fireEvent.click(screen.getByRole('button', { name }));
const openRow = () => fireEvent.click(screen.getByText('مجموعات المتجر'));
const sentList = (sent: { data: Record<string, unknown> }[]) => parseCollections(String(sent[sent.length - 1].data.store_collections));

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ القائمة وكتابتُها', () => {
    it('النصُّ المحفوظ ← قائمة — والعابثُ يسقط ولا يُسقط الشاشة', () => {
        expect(parseCollections('')).toEqual([]);
        expect(parseCollections('{oops')).toEqual([]);
        expect(parseCollections('{"slug":"x"}')).toEqual([]);
        expect(parseCollections(JSON.stringify([{ slug: 'a', title_ar: 'أ', products: [3, '3', -1, 4] }]))[0].products).toEqual([3, 4]);
    });

    it('والفراغُ يُكتب فراغًا — لا «[]»', () => {
        expect(serializeCollections([])).toBe('');
        expect(parseCollections(serializeCollections([one()]))).toEqual([one()]);
    });

    it('ولا يُرسَل صنفٌ أُخفي بعد اختياره', () => {
        const raw = serializeCollections([one({ products: [38, 99, 36] })]);
        expect(parseCollections(keepShownCollections(raw, PRODUCTS))[0].products).toEqual([38, 36]);
    });

    it('والرابطُ المختصرُ حروفٌ صغيرةٌ وأرقامٌ وشرطة — فريدٌ في قائمته', () => {
        expect(slugify('Graduation Gifts 2027!')).toBe('graduation-gifts-2027');
        expect(validSlug('graduation-gifts')).toBe(true);
        expect(validSlug('Graduation')).toBe(false);
        expect(validSlug('-x')).toBe(false);
        expect(freshSlug(['graduation'], 'Graduation')).toBe('graduation-2');
        expect(validSlug(freshSlug([]))).toBe(true);
    });
});

describe('«مجموعات المتجر» في المحرّر', () => {
    it('صفٌّ ثابتٌ قبل التذييل — لا قسمٌ يُرتَّب في الرئيسية', () => {
        draw();

        const rows = screen.getAllByTestId(/^row-/).map((li) => li.dataset.testid);
        expect(rows.slice(-2)).toEqual(['row-collections', 'row-foot']);
        expect(within(screen.getByTestId('row-collections')).queryByRole('switch')).toBeNull();
    });

    it('تُنشأ مجموعةٌ وتُسمّى بلغتيها وتُختار أصنافُها — و«احفظ» يُرسل مفتاحَها وحده', () => {
        const sent = capture();
        draw();
        openRow();

        expect(screen.getByTestId('collections-none')).toBeTruthy();
        fireEvent.click(screen.getByTestId('collections-add'));

        fireEvent.change(screen.getByTestId('collection-0-title-ar'), { target: { value: 'هدايا التخرّج' } });
        fireEvent.change(screen.getByTestId('collection-0-title-en'), { target: { value: 'Graduation gifts' } });
        fireEvent.change(screen.getByTestId('collection-0-slug'), { target: { value: 'Graduation!' } });
        press('إضافة ورد أحمر');
        press('إضافة بالون');

        // والإنجليزيُّ يُكتب من اليسار
        expect((screen.getByTestId('collection-0-title-en') as HTMLInputElement).dir).toBe('ltr');

        press('احفظ');
        expect(sent).toHaveLength(1);
        expect(Object.keys(sent[0].data)).toEqual(['store_collections']);
        expect(sentList(sent)).toEqual([one({ slug: 'graduation', products: [38, 36] })]);
    });

    it('تُرتَّب المجموعاتُ وتُخفى — ويُحفظ الترتيبُ كما يراه', () => {
        const sent = capture();
        draw(serializeCollections([one(), one({ slug: 'birthday', title_ar: 'أعياد الميلاد', title_en: '' })]));
        openRow();

        press('تحريك لأعلى أعياد الميلاد');
        fireEvent.click(screen.getByTestId('collection-1-visible'));
        press('احفظ');

        const list = sentList(sent);
        expect(list.map((c) => c.slug)).toEqual(['birthday', 'graduation']);
        expect(list[1].visible).toBe(false);
    });

    it('ويقف عند حدّ المنتجات في المجموعة — الآتي من الخادم', () => {
        draw(serializeCollections([one({ products: [36, 37] })]));
        openRow();
        fireEvent.click(screen.getByText('هدايا التخرّج'));

        expect((screen.getByRole('button', { name: 'إضافة ورد أحمر' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('والحذفُ بتأكيد — ضغطةٌ واحدة لا تحذف', () => {
        const sent = capture();
        draw(serializeCollections([one(), one({ slug: 'offers', title_ar: 'عروض' })]));
        openRow();
        fireEvent.click(screen.getByText('عروض'));

        fireEvent.click(screen.getByTestId('collection-1-delete'));
        expect(screen.getAllByTestId(/^collection-\d+$/)).toHaveLength(2);

        fireEvent.click(screen.getByTestId('collection-1-delete-confirm'));
        press('احفظ');
        expect(sentList(sent).map((c) => c.slug)).toEqual(['graduation']);
    });

    it('ويقف عند حدّ المجموعات', () => {
        draw(serializeCollections([one({ slug: 'a' }), one({ slug: 'b' }), one({ slug: 'c' })]));
        openRow();

        expect((screen.getByTestId('collections-add') as HTMLButtonElement).disabled).toBe(true);
    });

    it('ورابطٌ مكرّرٌ يُقال في صفّه قبل أن يُحفظ', () => {
        draw(serializeCollections([one({ slug: 'a' }), one({ slug: 'b', title_ar: 'ب' })]));
        openRow();
        fireEvent.click(screen.getByText('ب'));
        fireEvent.change(screen.getByTestId('collection-1-slug'), { target: { value: 'a' } });

        expect(screen.getByText('رابطٌ غير صالح أو مكرّر')).toBeTruthy();
    });

    it('و«عاين» يُرسل المسوّدةَ إلى رفّ المجموعة — ويعود إلى الرئيسية بضغطة', () => {
        draw(serializeCollections([one()]));
        openRow();
        fireEvent.click(screen.getByText('هدايا التخرّج'));
        fireEvent.click(screen.getByTestId('collection-0-preview'));

        expect(screen.getByTestId('preview-form').getAttribute('action')).toBe('/admin.store.preview/shop/graduation');
        expect(screen.getByTestId('preview-target').textContent).toContain('/shop?collection=graduation');

        press('عُد إلى الرئيسية');
        expect(screen.getByTestId('preview-form').getAttribute('action')).toBe('/admin.store.preview');
    });
});

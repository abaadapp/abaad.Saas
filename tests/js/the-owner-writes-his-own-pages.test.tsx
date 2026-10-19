import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { serializeCollections } from '@/Pages/Admin/Website/theme/CollectionsField';
import { blankPage, parsePages, sectionFilled, serializePages } from '@/Pages/Admin/Website/theme/CustomPagesField';
import { rowItems } from '@/Pages/Admin/Website/theme/ShopRowField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «الصفحات المخصّصة» و«ترتيب شريط المتجر» في محرّر الواجهة.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • الصفحةُ تُنشأ بعنوانها ونبذتها بلغتيها، وأقسامُها تُضاف وتُرتَّب وتُحذف،
 *    والقسمُ الفارغُ يُقال عنه قبل الحفظ، وزرُّها يُختار من وجهات متجره ولا
 *    يفتح الصفحةَ نفسَها، وتُعايَن في الإطار قبل أن تُحفظ.
 *  • وشريطُ المتجر يُرتَّب كلُّه — «كل المنتجات» و«الأكثر مبيعًا» والمجموعاتُ
 *    والفئات — و«كل المنتجات» فيه دائمًا، ويُحفظ مع المجموعات في صفّها.
 *
 * وقواعدُ الخادم: `ARibbonCustomPageShowsWhatItsOwnerWroteTest` و`ARibbonShopRowFollowsItsOwnersOrderTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key, hint: '', fixed: true, on: true, silent: null, source: null, fields: [], ...over,
});

const ROWS = [
    row({ key: 'head', label: 'رأس المتجر' }),
    row({ key: 'hero', label: 'الواجهة' }),
    row({ key: 'collections', label: 'مجموعات المتجر', fields: [
        { key: 'store_collections', kind: 'collections', label: 'المجموعات', max: 5, max_products: 5 },
        { key: 'store_shop_row', kind: 'shoprow', label: 'ترتيب شريط المتجر' },
    ] }),
    row({ key: 'pages', label: 'الصفحات المخصّصة', fields: [{ key: 'store_custom_pages', kind: 'pages', label: 'الصفحات', max: 2, max_sections: 2 }] }),
    row({ key: 'foot', label: 'التذييل' }),
];

const col = (slug: string, title: string, over = {}) => ({ slug, title_ar: title, title_en: '', desc_ar: '', desc_en: '', image: '', visible: true, in_row: true, products: [38], ...over });

const draw = (values: Record<string, string> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[{ key: 'home', path: '/', fixed: true, on: true, blocked: null }]}
            rows={ROWS}
            values={{
                store_custom_pages: '', store_shop_row: '', store_shop_nav_categories: '11',
                store_collections: serializeCollections([col('offers', 'العروض'), col('grad', 'هدايا التخرّج'), col('secret', 'مخفيّة', { visible: false })]),
                ...values,
            }}
            order={[]}
            maxFeatured={4}
            products={[{ id: 38, name: 'ورد أحمر', image: null }]}
            shortcutCategories={[{ id: 11, name: 'باقات', shown: 3 }, { id: 12, name: 'فارغة', shown: 0 }]}
        />,
    );
};

const capture = () => {
    const sent: { data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ data });
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

const saveIn = (key: string) => fireEvent.click(within(screen.getByTestId(`row-${key}`)).getByRole('button', { name: 'احفظ' }));
const last = (sent: { data: Record<string, unknown> }[]) => sent[sent.length - 1].data;

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ الصفحات وكتابتُها', () => {
    it('الفراغُ «لا صفحات»، والعابثُ يسقط، والجديدةُ تبدأ «كاملة»', () => {
        expect(parsePages('')).toEqual([]);
        expect(parsePages('{oops')).toEqual([]);
        expect(serializePages([])).toBe('');
        expect(blankPage(['our-services'], { ar: 'خدماتنا', en: 'Our services' })).toMatchObject({ slug: 'our-services-2', fit: 'contain', sections: [], cta: null, visible: true });
        expect(sectionFilled({ image: '', title_ar: ' ', title_en: '', body_ar: '', body_en: '' })).toBe(false);
        expect(parsePages(JSON.stringify([{ slug: 'a', title_ar: 'أ', cta: { label_ar: 'x', target: { type: 'url', value: 'https://x' } } }]))[0].cta).toBeNull();
    });
});

describe('«الصفحات المخصّصة» في المحرّر', () => {
    it('تُكتب صفحةٌ بأقسامها وزرِّها — و«احفظ» يُرسلها وحدها', () => {
        const sent = capture();
        draw();
        fireEvent.click(screen.getByText('الصفحات المخصّصة'));

        expect(screen.getByTestId('pages-none')).toBeTruthy();
        fireEvent.click(screen.getByTestId('pages-add'));
        fireEvent.change(screen.getByTestId('page-0-title-ar'), { target: { value: 'خدماتنا' } });
        fireEvent.change(screen.getByTestId('page-0-title-en'), { target: { value: 'Our services' } });
        fireEvent.change(screen.getByTestId('page-0-intro-ar'), { target: { value: 'نُنسّق الورد\nلكلّ مناسبة' } });
        fireEvent.change(screen.getByTestId('page-0-intro-en'), { target: { value: 'We arrange flowers' } });
        expect((screen.getByTestId('page-0-intro-en') as HTMLTextAreaElement).dir).toBe('ltr');

        fireEvent.click(screen.getByTestId('page-0-add-section'));
        expect(screen.getByTestId('page-0-section-0-empty')).toBeTruthy();
        fireEvent.change(screen.getByTestId('page-0-section-0-title-ar'), { target: { value: 'الأعراس' } });
        expect(screen.queryByTestId('page-0-section-0-empty')).toBeNull();
        fireEvent.click(screen.getByTestId('page-0-add-section'));
        fireEvent.change(screen.getByTestId('page-0-section-1-body-ar'), { target: { value: 'الشركات' } });
        expect((screen.getByTestId('page-0-add-section') as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى القسم 2' }));

        fireEvent.change(screen.getByTestId('page-0-cta-ar'), { target: { value: 'تسوّق العروض' } });
        fireEvent.change(screen.getByTestId('page-0-cta-target'), { target: { value: 'collection:offers' } });

        saveIn('pages');
        expect(Object.keys(last(sent))).toEqual(['store_custom_pages']);
        const [page] = parsePages(String(last(sent).store_custom_pages));
        expect(page).toMatchObject({
            title_ar: 'خدماتنا', title_en: 'Our services', intro_ar: 'نُنسّق الورد\nلكلّ مناسبة',
            cta: { label_ar: 'تسوّق العروض', label_en: '', target: { type: 'collection', value: 'offers' } },
        });
        expect(page.sections.map((s) => s.body_ar || s.title_ar)).toEqual(['الشركات', 'الأعراس']);
    });

    it('وزرُّ الصفحة لا يفتحها هي — وتُعايَن في الإطار قبل الحفظ', () => {
        draw({ store_custom_pages: serializePages([
            { ...blankPage([], { ar: 'خدماتنا', en: '' }), slug: 'services', cta: { label_ar: 'عروضنا', label_en: '', target: { type: 'page', value: 'offers' } } },
            { ...blankPage([], { ar: 'عروضنا', en: '' }), slug: 'offers' },
        ]) });
        fireEvent.click(screen.getByText('الصفحات المخصّصة'));
        fireEvent.click(screen.getByText('خدماتنا'));

        const values = Array.from((screen.getByTestId('page-0-cta-target') as HTMLSelectElement).options).map((o) => o.value);
        expect(values).toContain('page:offers');
        expect(values).not.toContain('page:services');

        fireEvent.click(screen.getByTestId('page-0-preview'));
        expect(screen.getByTestId('preview-target').textContent).toContain('/page/services');
        expect(screen.getByTestId('preview-form').getAttribute('action')).toBe('/admin.store.preview/page/services');
    });

    it('والرابطُ المكرّرُ أو غيرُ الصالح يُقال قبل الحفظ — والحذفُ بتأكيد', () => {
        const sent = capture();
        draw({ store_custom_pages: serializePages([{ ...blankPage([], { ar: 'أ', en: '' }), slug: 'same' }, { ...blankPage([], { ar: 'ب', en: '' }), slug: 'same' }]) });
        fireEvent.click(screen.getByText('الصفحات المخصّصة'));
        fireEvent.click(screen.getByText('ب'));

        expect(screen.getByText('رابطٌ غير صالح أو مكرّر')).toBeTruthy();
        fireEvent.change(screen.getByTestId('page-1-slug'), { target: { value: 'Other Page!' } });
        expect((screen.getByTestId('page-1-slug') as HTMLInputElement).value).toBe('otherpage');

        fireEvent.click(screen.getByTestId('page-1-delete'));
        fireEvent.click(screen.getByTestId('page-1-delete-confirm'));
        saveIn('pages');
        expect(parsePages(String(last(sent).store_custom_pages)).map((p) => p.slug)).toEqual(['same']);
    });
});

describe('«ترتيب شريط المتجر»', () => {
    const labels = { all: 'كل المنتجات', best: 'الأكثر مبيعًا' };
    const cols = [col('offers', 'العروض'), col('grad', 'هدايا التخرّج'), col('secret', 'مخفيّة', { visible: false }), col('bar', 'للشريط وحده', { in_row: false })];
    const cats = [{ id: 11, name: 'باقات', shown: 3 }, { id: 12, name: 'فارغة', shown: 0 }];

    it('ما يُرتَّب ما يظهر — بترتيب صاحبه، و«كل المنتجات» دائمًا، والجديدُ آخرًا', () => {
        expect(rowItems('', cols, [11, 12], cats, labels).map((i) => i.token)).toEqual(['all', 'best', 'col:offers', 'col:grad', 'cat:11']);
        expect(rowItems('col:grad,best', cols, [11], cats, labels).map((i) => i.token)).toEqual(['all', 'col:grad', 'best', 'col:offers', 'cat:11']);
    });

    it('يُحرَّك كلُّ زرٍّ — ويُحفظ مع المجموعات في صفّها', () => {
        const sent = capture();
        draw();
        fireEvent.click(screen.getByText('مجموعات المتجر'));

        expect(screen.getAllByTestId(/^shoprow-(all|best|col:|cat:)/).map((li) => li.dataset.testid)).toEqual([
            'shoprow-all', 'shoprow-best', 'shoprow-col:offers', 'shoprow-col:grad', 'shoprow-cat:11',
        ]);
        expect(within(screen.getByTestId('shoprow-all')).getByText('يبقى دائمًا')).toBeTruthy();

        // «العروض | كل المنتجات | …»
        const shoprow = screen.getByTestId('shoprow');
        fireEvent.click(within(shoprow).getByRole('button', { name: 'تحريك لأعلى العروض' }));
        fireEvent.click(within(shoprow).getByRole('button', { name: 'تحريك لأعلى العروض' }));

        saveIn('collections');
        expect(Object.keys(last(sent)).sort()).toEqual(['store_collections', 'store_shop_row']);
        expect(last(sent).store_shop_row).toBe('col:offers,all,best,col:grad,cat:11');

        fireEvent.click(screen.getByTestId('shoprow-reset'));
        saveIn('collections');
        expect(last(sent).store_shop_row).toBe('');
    });
});

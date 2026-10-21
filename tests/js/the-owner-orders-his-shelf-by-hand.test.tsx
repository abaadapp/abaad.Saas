import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';
import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import {
    arrange,
    membersOf,
    parseShelfOrder,
    serializeShelfOrder,
    type ShelfOrderSeed,
} from '@/Pages/Admin/Website/theme/ShelfOrderField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * ترتيبُ الرفّ بيد صاحبه في محرّر الواجهة — ومعه ترتيبُ الأقسام بالضغط السريع.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - من لم تصله الحمولةُ لا يرى لوحة. ومن وصلته يرى «كل المنتجات» أبجديًّا
 *     كما يراها زبونُه، وينتقل إلى اليدويّ فيبدأ بها، ويرتّب بسهمين.
 *   - القائمتان منفصلتان: ترتيبُ الفئة لا يمسّ «كل المنتجات».
 *   - المخفيُّ الذي له موضعٌ يُرسم موسومًا ويبقى موضعُه.
 *   - الحفظُ يرسل المفتاحَ وحده بالباب القائم، وما يردّه الخادمُ يُقال.
 *   - وسهمُ الأقسام لا يُرسل حفظين معًا: ما يُضغط والحفظُ في الطريق ينتظره.
 *
 * وقواعدُ الحفظ نفسُها في الخادم: `ARibbonShopOrdersItsShelfByHandTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    group: over.fixed && ! ['head', 'images', 'upsells', 'vbar'].includes(over.key) ? 'main' : 'advanced',
    label: over.key,
    hint: 'وصفُ الصفّ',
    fixed: false,
    on: true,
    silent: null,
    source: null,
    fields: [],
    ...over,
});

const PAGES = [
    { key: 'home', path: '/', fixed: true, on: true, blocked: null },
    { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
];

const HERO = row({ key: 'hero', label: 'الواجهة', fixed: true, fields: [{ key: 'store_headline', kind: 'text', label: 'العنوان الكبير' }] });
const COLLECTIONS = row({ key: 'collections', label: 'مجموعات المتجر', fixed: true });
const FOOT = row({ key: 'foot', label: 'التذييل', fixed: true });

const ROSES = 7;
const GIFTS = 8;

/** بترتيب الرفّ الأبجديّ كما يرسله الخادم — والمخفيُّ (٥٠) له موضع */
const PRODUCTS = [
    { id: 11, name: 'باقة الورد الأبيض', image: null, category_id: ROSES, shown: true },
    { id: 12, name: 'باقة الورد الأحمر', image: null, category_id: ROSES, shown: true },
    { id: 13, name: 'باقة الورد الوردي', image: null, category_id: ROSES, shown: true },
    { id: 50, name: 'باقة مخفية', image: null, category_id: ROSES, shown: false },
    { id: 14, name: 'بطاقة إهداء', image: null, category_id: GIFTS, shown: true },
];

const seed = (value = ''): ShelfOrderSeed => ({ value, products: PRODUCTS });

const draw = (shelfOrder: ShelfOrderSeed | null, rows: EditorRow[] = [HERO, COLLECTIONS, FOOT], order: string[] = []) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={PAGES}
            rows={rows}
            values={{ store_headline: '' }}
            order={order}
            maxFeatured={4}
            products={[]}
            shortcutCategories={[
                { id: ROSES, name: 'ورد', shown: 3 },
                { id: GIFTS, name: 'هدايا', shown: 1 },
                { id: 9, name: 'فارغة', shown: 0 },
            ]}
            shelfOrder={shelfOrder}
        />,
    );

    const toggle = screen.queryByTestId('advanced-toggle');
    if (toggle?.getAttribute('aria-expanded') === 'false') fireEvent.click(toggle);
};

const openShelf = () => fireEvent.click(screen.getByText('مجموعات المتجر'));

/** ما في القائمة بترتيبه */
const items = () =>
    within(screen.getByTestId('shelf-order-items'))
        .getAllByRole('listitem')
        .map((li) => Number(li.dataset.testid!.replace('shelf-order-', '')));

const press = (label: string) => fireEvent.click(screen.getByRole('button', { name: label }));

const capture = (answer?: (o: { onSuccess?: () => void; onError?: (e: Record<string, string>) => void }) => void) => {
    const sent: { url: string; data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ url, data });
        answer?.(options as never);
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

afterEach(() => vi.restoreAllMocks());

describe('صيغةُ القوائم كما يكتبها الخادم', () => {
    it('تُقرأ بتسامح وتُكتب بصيغةٍ واحدة — والفارغُ لا يُكتب', () => {
        expect(parseShelfOrder('{nope')).toEqual({ all: [], categories: {} });
        expect(parseShelfOrder('{"all":[3,3,-1,"x",2],"categories":{"7":[1],"x":[2],"8":[]}}')).toEqual({ all: [3, 2], categories: { 7: [1] } });

        expect(serializeShelfOrder({ all: [], categories: { 8: [], 7: [] } })).toBe('');
        expect(serializeShelfOrder({ all: [2, 1], categories: { 12: [5], 7: [4] } })).toBe('{"all":[2,1],"categories":{"7":[4],"12":[5]}}');
    });

    it('المرتَّبُ بموضعه ثمّ ما لم يُرتَّب بترتيب إضافته — كما في `ShelfOrder::sort`', () => {
        expect(arrange(PRODUCTS, [13, 11]).map((p) => p.id)).toEqual([13, 11, 12, 14, 50]);
        expect(arrange(PRODUCTS, null).map((p) => p.id)).toEqual([11, 12, 13, 50, 14]);
    });

    it('أصنافُ القائمة: المعروضُ، والمخفيُّ الذي له موضعٌ فيها وحده', () => {
        expect(membersOf(PRODUCTS, 'all', []).map((p) => p.id)).toEqual([11, 12, 13, 14]);
        expect(membersOf(PRODUCTS, 'all', [50]).map((p) => p.id)).toEqual([11, 12, 13, 50, 14]);
        expect(membersOf(PRODUCTS, String(GIFTS), []).map((p) => p.id)).toEqual([14]);
    });
});

describe('لوحةُ ترتيب الرفّ', () => {
    it('لا تُرسم لمن لم تصله', () => {
        draw(null);
        openShelf();

        expect(screen.queryByTestId('shelf-order')).toBeNull();
    });

    it('تبدأ أبجديّةً كما يراها الزبون — بلا سهمين حتّى يختار اليدويّ', () => {
        draw(seed());
        openShelf();

        expect(items()).toEqual([11, 12, 13, 14]);
        expect(screen.getByRole('radio', { name: 'أبجدي — كما هو الآن' })).toBeChecked();
        expect(screen.queryByRole('button', { name: 'تحريك لأعلى باقة الورد الأحمر' })).toBeNull();
        expect(screen.getByTestId('shelf-order-save')).toBeDisabled();
        // والفئةُ الفارغة لا تُعرض لتُرتَّب
        expect(within(screen.getByTestId('shelf-order-list')).queryByText('فارغة')).toBeNull();
    });

    it('اليدويُّ يبدأ بما يراه الزبون، والسهمان يرتّبان — والأوّلُ لا يصعد والأخيرُ لا ينزل', () => {
        draw(seed());
        openShelf();

        fireEvent.click(screen.getByRole('radio', { name: 'ترتيب يدوي' }));
        expect(items()).toEqual([11, 12, 13, 14]);
        expect(screen.getByRole('button', { name: 'تحريك لأعلى باقة الورد الأبيض' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'تحريك لأسفل بطاقة إهداء' })).toBeDisabled();

        press('تحريك لأعلى باقة الورد الأحمر');
        expect(items()).toEqual([12, 11, 13, 14]);
        press('تحريك لأسفل باقة الورد الأحمر');
        press('تحريك لأسفل باقة الورد الأحمر');
        expect(items()).toEqual([11, 13, 12, 14]);
        expect(screen.getByText('لم يُحفظ بعد')).toBeInTheDocument();
    });

    it('يرسل المفتاحَ وحده بالباب القائم وبالترتيب المرئيّ، ويقول «حُفظ»', () => {
        const sent = capture((o) => o.onSuccess?.());
        draw(seed());
        openShelf();

        fireEvent.click(screen.getByRole('radio', { name: 'ترتيب يدوي' }));
        press('تحريك لأعلى باقة الورد الأحمر');
        fireEvent.click(screen.getByTestId('shelf-order-save'));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin.marketing.store.save');
        expect(sent[0].data).toEqual({ store_shelf_order: '{"all":[12,11,13,14]}' });
        expect(screen.getByText('حُفظ')).toBeInTheDocument();
        expect(screen.queryByText('لم يُحفظ بعد')).toBeNull();
    });

    it('ترتيبُ الفئة قائمتُها — لا يمسّ «كل المنتجات»', () => {
        const sent = capture((o) => o.onSuccess?.());
        draw(seed('{"all":[14,11,12,13]}'));
        openShelf();

        fireEvent.change(screen.getByTestId('shelf-order-list'), { target: { value: String(ROSES) } });
        expect(items()).toEqual([11, 12, 13]);
        expect(screen.getByRole('radio', { name: 'أبجدي — كما هو الآن' })).toBeChecked();

        fireEvent.click(screen.getByRole('radio', { name: 'ترتيب يدوي' }));
        press('تحريك لأعلى باقة الورد الأحمر');
        fireEvent.click(screen.getByTestId('shelf-order-save'));

        expect(sent[0].data).toEqual({ store_shelf_order: '{"all":[14,11,12,13],"categories":{"7":[12,11,13]}}' });

        fireEvent.change(screen.getByTestId('shelf-order-list'), { target: { value: 'all' } });
        expect(items()).toEqual([14, 11, 12, 13]);
    });

    it('المخفيُّ الذي له موضعٌ يُرسم موسومًا ويبقى موضعُه ويُرسَل', () => {
        const sent = capture((o) => o.onSuccess?.());
        draw(seed('{"all":[13,50,11,12,14]}'));
        openShelf();

        expect(items()).toEqual([13, 50, 11, 12, 14]);
        expect(within(screen.getByTestId('shelf-order-50')).getByText('مخفيّ الآن — يبقى في مكانه')).toBeInTheDocument();

        press('تحريك لأسفل باقة الورد الأبيض');
        fireEvent.click(screen.getByTestId('shelf-order-save'));

        expect(sent[0].data).toEqual({ store_shelf_order: '{"all":[13,50,12,11,14]}' });
    });

    it('والعودةُ إلى الأبجديّ تمحو قائمتها وحدها', () => {
        const sent = capture((o) => o.onSuccess?.());
        draw(seed('{"all":[14,11],"categories":{"7":[13,12,11]}}'));
        openShelf();

        fireEvent.click(screen.getByRole('radio', { name: 'أبجدي — كما هو الآن' }));
        expect(items()).toEqual([11, 12, 13, 14]);
        fireEvent.click(screen.getByTestId('shelf-order-save'));

        expect(sent[0].data).toEqual({ store_shelf_order: '{"categories":{"7":[13,12,11]}}' });
    });

    it('وما يردّه الخادمُ يُقال في اللوحة', () => {
        capture((o) => o.onError?.({ store_shelf_order: 'تعذّرت قراءة ترتيب المنتجات — أعد ترتيبها.' }));
        draw(seed());
        openShelf();

        fireEvent.click(screen.getByRole('radio', { name: 'ترتيب يدوي' }));
        fireEvent.click(screen.getByTestId('shelf-order-save'));

        expect(screen.getByTestId('shelf-order-error')).toHaveTextContent('تعذّرت قراءة ترتيب المنتجات');
        expect(screen.getByText('تعذّر الحفظ')).toBeInTheDocument();
    });
});

describe('سهمُ الأقسام بالضغط السريع', () => {
    const CATS = row({ key: 'cats', label: 'تسوّق حسب الفئة' });
    const BEST = row({ key: 'best', label: 'الأكثر مبيعًا' });
    const NEW = row({ key: 'new', label: 'وصل حديثًا' });

    it('لا يُرسل حفظين معًا — ويُرسل آخرَ ما ضُغط حين يعود الأوّل', () => {
        const post = vi.mocked(router.post);
        post.mockClear();
        draw(null, [HERO, CATS, BEST, NEW, FOOT], ['cats', 'best', 'new']);

        press('أنزل تسوّق حسب الفئة');
        press('أنزل تسوّق حسب الفئة');

        // الأوّلُ في الطريق — والثاني ينتظره، لا يسابقه إلى الخادم
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({ store_sections: 'best,cats,new' });

        const first = post.mock.calls[0][2] as { onSuccess: () => void; onFinish: () => void };
        act(() => {
            first.onSuccess();
            first.onFinish();
        });

        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][1]).toEqual({ store_sections: 'best,new,cats' });

        const second = post.mock.calls[1][2] as { onSuccess: () => void; onFinish: () => void };
        act(() => {
            second.onSuccess();
            second.onFinish();
        });
        expect(post).toHaveBeenCalledTimes(2);
        expect(screen.getByText('حُفظ')).toBeInTheDocument();
    });

    it('وضغطاتٌ كثيرةٌ في أثناء حفظٍ واحد تُرسَل آخرُها وحدها', () => {
        const post = vi.mocked(router.post);
        post.mockClear();
        draw(null, [HERO, CATS, BEST, NEW, FOOT], ['cats', 'best', 'new']);

        press('أنزل تسوّق حسب الفئة');
        press('أنزل تسوّق حسب الفئة');
        press('ارفع تسوّق حسب الفئة');

        const first = post.mock.calls[0][2] as { onFinish: () => void };
        act(() => first.onFinish());

        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][1]).toEqual({ store_sections: 'best,cats,new' });
    });
});

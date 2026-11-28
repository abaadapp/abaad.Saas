import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { fromTill, type TillProductRow } from '@/lib/till-products';
import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏الصندوقُ يقرأ من الأصناف الأخفّ ما كان يقرؤه من الكاملة — فيبيع البيعَ نفسَه.
 *
 * الخادمُ يُسقط من صفّ الصنف للصندوق ما لا يقرؤه (`Demo::forTill`) والاسمَ
 * المعروض حين يساوي الاسم، والصندوقُ يُكمله عند الدخول (`fromTill`). فيُفتح
 * الصندوقُ هنا مرّتين — بالصفوف الكاملة كما كانت، وبالأخفّ كما تُرسل الآن —
 * ويُباع في كلٍّ منهما الشيءُ نفسُه، فتُقارن السلّةُ والإجماليُّ وحمولةُ البيعة
 * التي تصل الخادم، قيمةً بقيمة. ويقابله في PHP `TheTillIsSentOnlyWhatItReadsTest`.
 */

/** ‏صفوفُ الصنف كما تبنيها `Demo::products` لكلّ واجهة */
const full = (): Record<string, unknown>[] => [
    // ‏ضريبةُ المتجر، وسعرٌ بثلاث خانات، وخصمُ صنف
    { id: 1, name: 'باقة تتبع المتجر', name_en: null, label: 'باقة تتبع المتجر', cat: 'ورود', cat_label: 'Flowers', price: 12.345, cost: 4, qty: 9, sku: 'R-1', barcode: '6290000000011', image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, published: true, pos_enabled: true, description_pos_enabled: false, description_web_enabled: true, pos_description: null, alert: 2, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0 },
    // ‏صفرٌ محدَّد، واسمٌ إنجليزيّ يعرضه الصندوقُ الإنجليزيّ
    { id: 2, name: 'باقة بلا ضريبة', name_en: 'Zero bouquet', label: 'باقة بلا ضريبة', cat: 'ورود', cat_label: 'Flowers', price: 7.777, cost: 3, qty: 4, sku: null, barcode: null, image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, published: false, pos_enabled: true, description_pos_enabled: false, description_web_enabled: true, pos_description: null, alert: 1, tax: 0, variants: [], addon_ids: [], has_recipe: false, discount: 0 },
    // ‏خصمُ صنف، ووصفٌ مفتوحٌ للصندوق، وخدمةٌ لا تُعدّ
    { id: 3, name: 'تغليف فاخر', name_en: null, label: 'تغليف فاخر', cat: 'خدمات', cat_label: 'Services', price: 2.5, cost: 0, qty: 0, sku: 'W-1', barcode: null, image: '/storage/products/wrap.webp', thumb: '/storage/products/thumbs/wrap.webp', stock_status: '', tracks_stock: false, active: true, published: true, pos_enabled: true, description_pos_enabled: true, description_web_enabled: false, pos_description: 'ورقٌ مزدوج', alert: null, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0.5 },
    // ‏موقوفٌ، ولموقعٍ وحده — يصلان كما هما
    { id: 4, name: 'باقة موقوفة', name_en: null, label: 'باقة موقوفة', cat: 'ورود', cat_label: 'Flowers', price: 9, cost: 1, qty: 3, sku: null, barcode: null, image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: false, published: true, pos_enabled: true, description_pos_enabled: false, description_web_enabled: true, pos_description: null, alert: 1, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0 },
    { id: 5, name: 'باقة الموقع', name_en: null, label: 'باقة الموقع', cat: 'ورود', cat_label: 'Flowers', price: 9, cost: 1, qty: 3, sku: null, barcode: null, image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, published: true, pos_enabled: false, description_pos_enabled: false, description_web_enabled: true, pos_description: null, alert: 1, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0 },
];

/** ‏وكما يُرسلها الخادمُ للصندوق الآن — `Demo::forTill` حرفًا */
const slim = (rows: Record<string, unknown>[]): TillProductRow[] =>
    rows.map((r) => {
        const { published: _p, cat_label: _c, description_pos_enabled: _d, description_web_enabled: _w, ...rest } = r;
        if (rest.label === rest.name) delete rest.label;

        return rest as unknown as TillProductRow;
    });

const draw = (products: unknown[], inclusive: boolean) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        products,
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [],
        addons: [],
        coupons: [],
        settings: { vat: { enabled: true, rate: 5, inclusive } },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();
};

/** ‏يفتح الصندوق، ويبيع ما في `basket` بالنقد، ويعيد ما رآه الكاشير وما وصل الخادم */
const sell = async (products: unknown[], inclusive: boolean, basket: string[]) => {
    const sent: Record<string, unknown>[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            if (String(url).includes('/pos/checkout')) {
                sent.push(JSON.parse(String(init?.body)));

                return { ok: true, status: 200, json: async () => ({ ok: true, invoice: 'INV-1', total: sent.at(-1)!.expected_total }) };
            }

            return { ok: true, status: 200, json: async () => ({}) };
        }),
    );
    draw(products, inclusive);
    const { default: PosIndex } = await import('@/Pages/Pos/Index');
    const user = userEvent.setup();
    const view = render(<PosIndex />);

    const cards = () => [...view.container.querySelectorAll('section button.group')] as HTMLElement[];
    const shown = cards().map((c) => c.querySelector('h3')?.textContent ?? '');
    for (const name of basket) {
        const card = cards().find((c) => c.querySelector('h3')?.textContent === name);
        if (!card) throw new Error(`لا بطاقة «${name}»`);
        await user.click(card);
    }
    const bar = screen.getByTestId('pos-paybar').textContent;
    await user.click(within(screen.getByTestId('pos-paybar')).getAllByRole('button').find((b) => /الدفع/.test(b.textContent ?? ''))!);
    await user.click(await screen.findByRole('button', { name: /نقدي/ }));
    await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    await waitFor(() => expect(sent).toHaveLength(1));
    const { client_uuid: _u, queued_at: _q, ...payload } = sent[0];
    view.unmount();
    cleanup();

    return { shown, bar, payload };
};

beforeEach(() => {
    vi.resetModules();
});
afterEach(() => {
    vi.unstubAllGlobals();
});

describe('الأصنافُ الأخفّ', () => {
    it('يُكمل الاسمَ المعروض الغائبَ من الاسم — ولا يمسّ الحاضرَ ولو فارغًا أو إنجليزيًّا', () => {
        const rows = [
            { id: 1, name: 'باقة' },
            { id: 2, name: 'وردة', label: 'Rose' },
            { id: 3, name: 'بلا', label: '' },
        ] as unknown as TillProductRow[];

        const out = fromTill(rows);

        expect(out.map((p) => p.label)).toEqual(['باقة', 'Rose', '']);
        // ‏في الصفوف نفسِها — لا نسخةٌ ثانية من ألفي صنف
        expect(out).toBe(rows as unknown);
    });

    it('ولا يصل الصندوقَ من الصفّ الأخفّ حقلٌ ممّا أُسقط', () => {
        for (const row of slim(full())) {
            for (const k of ['published', 'cat_label', 'description_pos_enabled', 'description_web_enabled']) expect(row).not.toHaveProperty(k);
        }
        expect(slim(full()).every((r) => !('label' in r))).toBe(true);
    });

    for (const inclusive of [false, true]) {
        it(`البيعةُ نفسُها بالبيسة — الضريبةُ ${inclusive ? 'شاملة' : 'مضافة'}: الأصنافُ المعروضة، والشريط، وحمولةُ البيعة`, async () => {
            const basket = ['باقة تتبع المتجر', 'باقة بلا ضريبة', 'تغليف فاخر', 'باقة تتبع المتجر'];

            const before = await sell(full(), inclusive, basket);
            vi.resetModules();
            const after = await sell(slim(full()), inclusive, basket);

            expect(after.shown).toEqual(before.shown);
            // ‏الموقوفُ وما للموقع وحده لا يصيران قابلَين للبيع بحقلٍ سقط
            expect(after.shown).not.toContain('باقة موقوفة');
            expect(after.shown).not.toContain('باقة الموقع');
            expect(after.bar).toBe(before.bar);
            expect(after.payload).toEqual(before.payload);
            expect(after.payload.items).toHaveLength(3);
            expect(Number(after.payload.expected_total)).toBeGreaterThan(30);
            expect(after.bar).toMatch(/\d/);
        });
    }
});

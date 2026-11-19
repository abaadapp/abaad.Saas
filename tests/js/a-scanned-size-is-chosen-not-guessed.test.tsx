import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏الماسحُ لا يُدخل صنفًا ناقصَ المقاس — ويعرف المقاسَ بباركوده.
 *
 * ═══ ما كان ═══
 *
 * باركودُ صنفٍ له مقاساتٌ يُدخله السلّةَ سطرًا بسعره بلا مقاس، فيردّه الخادمُ
 * عند الدفع: «اختر مقاس…». وباركودُ المقاس نفسِه لا يعرفه الماسح: «لا يوجد
 * منتج بهذا الباركود». والبطاقةُ تفتح نافذةَ المقاس في الحالتين.
 *
 * فصار الماسحُ يفتح النافذةَ نفسَها للصنف، ويُضيف المقاسَ الممسوحَ بعينه —
 * بالسطر الذي تبنيه النافذة (`addChoice`) وسطرِ البطاقة (`plainLine`).
 */

const base = {
    name_en: null, cat: 'ورود', cost: 1, qty: 50, image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true,
    active: true, pos_enabled: true, pos_description: null, alert: 1, tax: 5, addon_ids: [], has_recipe: false, discount: 0,
};
const shelf = [
    // ‏صنفٌ بمقاسين لكلٍّ باركودُه ورمزُه — وسعرُه نفسُه لا يُباع به
    {
        ...base, id: 1, name: 'بوكيه', label: 'بوكيه', price: 4.748, sku: 'BQ-1', barcode: '6290000000005',
        variants: [
            { id: 11, name: 'صغير', label: 'صغير', price: 7.125, sku: 'BQ-S', barcode: '6290000000051' },
            { id: 12, name: 'كبير', label: 'كبير', price: 9.333, sku: 'BQ-L', barcode: '6290000000052' },
        ],
    },
    // ‏وصنفٌ بلا مقاس — يُمسح فيُضاف كما كان
    { ...base, id: 2, name: 'وردة', label: 'وردة', price: 1.25, sku: 'RS-1', barcode: '6290000000002', variants: [] },
    // ‏وموقوفٌ بمقاسٍ له باركود — لا يُباع
    {
        ...base, id: 3, name: 'باقة موقوفة', label: 'باقة موقوفة', price: 3, sku: null, barcode: null, active: false,
        variants: [{ id: 31, name: 'وسط', label: 'وسط', price: 5, sku: null, barcode: '6290000000031' }],
    },
];

let sent: Record<string, unknown>[] = [];
const open = async () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        products: shelf,
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [],
        addons: [],
        coupons: [],
        settings: { vat: { enabled: true, rate: 5, inclusive: false } },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};
const bar = () => screen.getByTestId('pos-paybar').textContent ?? '';
const scan = async (user: ReturnType<typeof userEvent.setup>, code: string) => user.type(screen.getByPlaceholderText('امسح الباركود'), `${code}{Enter}`);
const card = async (user: ReturnType<typeof userEvent.setup>, view: ReturnType<typeof render>, name: string) =>
    user.click([...view.container.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === name) as HTMLElement);
const pay = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(within(screen.getByTestId('pos-paybar')).getAllByRole('button').find((b) => /الدفع/.test(b.textContent ?? ''))!);
    await user.click(await screen.findByRole('button', { name: /نقدي/ }));
    await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    await waitFor(() => expect(sent).toHaveLength(1));
    const items = (sent[0].items as Record<string, unknown>[]).map(({ id, variant_id, addons, name, price, qty }) => ({ id, variant_id, addons, name, price, qty }));

    return { items, total: sent[0].expected_total };
};
const choose = async (user: ReturnType<typeof userEvent.setup>, size: string) => {
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByText(size));
    await user.click(within(dialog).getByRole('button', { name: 'إضافة للسلة' }));
};

beforeEach(() => {
    vi.resetModules();
    sent = [];
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
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

/** ‏ما تبيعه البطاقةُ ونافذتُها — يُقاس عليه الماسح */
const byCard = async (size: string) => {
    const view = await open();
    const user = userEvent.setup();
    await card(user, view, 'بوكيه');
    await choose(user, size);
    const out = { bar: bar(), ...(await pay(user)) };
    cleanup();
    vi.resetModules();
    sent = [];

    return out;
};

describe('الماسحُ والمقاسات', () => {
    it('باركودُ صنفٍ له مقاسات يفتح نافذتَه ولا يُضيف شيئًا — ثمّ يُباع كما يُباع بالبطاقة', async () => {
        const expected = await byCard('كبير');

        await open();
        const user = userEvent.setup();
        await scan(user, '6290000000005');

        // ‏لا سطرَ بلا مقاس، والنافذةُ مفتوحة تسأل
        expect(within(await screen.findByRole('dialog')).getByText('كبير')).toBeTruthy();
        expect(bar()).toContain('0.000');

        await choose(user, 'كبير');
        expect(bar()).toBe(expected.bar);
        expect(await pay(user)).toEqual({ items: expected.items, total: expected.total });
        // ‏9.333 + 5% = 9.800 — لا 4.748 بلا مقاس
        expect(expected.total).toBe(9.8);
    });

    it('ورمزُ الصنف (SKU) كباركوده — بلا حالة أحرف', async () => {
        await open();
        const user = userEvent.setup();
        await scan(user, 'bq-1');

        expect(within(await screen.findByRole('dialog')).getByText('صغير')).toBeTruthy();
    });

    it('باركودُ مقاسٍ بعينه يُضيف ذلك المقاسَ — كما تُضيفه النافذةُ بلا إضافات', async () => {
        const expected = await byCard('صغير');

        await open();
        const user = userEvent.setup();
        await scan(user, '6290000000051');

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(bar()).toBe(expected.bar);
        expect(await pay(user)).toEqual({ items: expected.items, total: expected.total });
        expect((expected.items[0] as { variant_id: number }).variant_id).toBe(11);
    });

    it('ورمزُ المقاس كباركوده', async () => {
        const expected = await byCard('كبير');

        await open();
        const user = userEvent.setup();
        await scan(user, 'BQ-L');

        expect(bar()).toBe(expected.bar);
    });

    it('ومقاسُ صنفٍ موقوف لا يُطابَق — كالصنف نفسه', async () => {
        await open();
        const user = userEvent.setup();
        await scan(user, '6290000000031');

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(bar()).toContain('0.000');
    });

    it('وصنفٌ بلا مقاس يُمسح فيُضاف كما كان — وكما تُضيفه البطاقة', async () => {
        const view = await open();
        const user = userEvent.setup();
        await card(user, view, 'وردة');
        const expected = { bar: bar(), ...(await pay(user)) };
        cleanup();
        vi.resetModules();
        sent = [];

        await open();
        const again = userEvent.setup();
        await scan(again, '6290000000002');

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(bar()).toBe(expected.bar);
        expect(await pay(again)).toEqual({ items: expected.items, total: expected.total });
    });
});

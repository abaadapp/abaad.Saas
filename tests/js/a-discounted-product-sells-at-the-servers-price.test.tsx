import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { fromTill, type TillProductRow } from '@/lib/till-products';
import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏صنفٌ عليه خصمٌ يُباع في الصندوق بالسعر الذي يبيع به الخادم — بالبيسة.
 *
 * ═══ ما كان ═══
 *
 * الصندوقُ يبيع بـ`price`، والخادمُ بـ`Product::sellingPrice()` (بعد الخصم).
 * صنفٌ بـ12.276 عليه 15% في متجرٍ بخمسة: الشاشةُ تقول 12.890 والفاتورةُ 10.957.
 * بالاتّصال يردّ الخادمُ البيعة؛ وبلا اتّصالٍ يُقبض أكثرُ ممّا يُكتب.
 *
 * والحالاتُ في `tests/fixtures/pos-product-discount.json` يقرؤها هذا الملفّ
 * ويقرؤها `TheTillSellsADiscountedProductAtTheServersPriceTest`: سعرُ البيع
 * فيها ما يرسله الخادم، والإجماليُّ ما يقبله — يوم يفترقان يسقط أحدُهما.
 */

interface Fixture {
    products: { key: string; name: string; price: number; discount: number; tax: number | null; sale_price: number | null; barcode?: string; variants?: { name: string; price: number }[]; addon?: { name: string; price: number } }[];
    cases: { name: string; inclusive: boolean; lines: { key: string; qty: number; variant?: string; addon?: number }[]; total: number; before: number }[];
}
const shared = JSON.parse(readFileSync(resolve(__dirname, '../fixtures/pos-product-discount.json'), 'utf8')) as Fixture;

/** ‏الصفوفُ كما يرسلها الخادمُ للصندوق: النسبةُ الفعليّة، و`sale_price` للمخصوم وحده */
const rows = (): TillProductRow[] =>
    shared.products.map((p, i) => ({
        id: i + 1, name: p.name, name_en: null, cat: 'ورود', price: p.price, cost: 1, qty: 50, sku: null, barcode: p.barcode ?? null,
        image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, pos_enabled: true, pos_description: null, alert: 1,
        tax: p.tax ?? 5,
        variants: (p.variants ?? []).map((v, j) => ({ id: 100 + j, name: v.name, label: v.name, price: v.price, sku: null })),
        addon_ids: p.addon ? [1] : [],
        has_recipe: false, discount: p.discount,
        ...(p.sale_price !== null ? { sale_price: p.sale_price } : {}),
    })) as unknown as TillProductRow[];

const addons = [{ id: 1, name: 'شوكولاتة', name_en: null, label: 'شوكولاتة', price: 2.345, icon: null, active: true, scope: 'selected', pos_enabled: true }];

const open = async (inclusive: boolean) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        products: rows(),
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [],
        addons,
        coupons: [],
        settings: { vat: { enabled: true, rate: 5, inclusive } },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};
const bar = () => screen.getByTestId('pos-paybar').textContent ?? '';
const amount = (text: string) => Number(text.match(/(\d+\.\d{3})/g)?.at(-1));
const card = (view: ReturnType<typeof render>, name: string) =>
    [...view.container.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === name) as HTMLElement;

let sent: Record<string, unknown>[] = [];
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

describe('خصمُ الصنف في الصندوق', () => {
    for (const c of shared.cases) {
        it(`${c.name}: الشاشةُ ${c.total.toFixed(3)} — ما يكتبه الخادم، لا ${c.before.toFixed(3)}`, async () => {
            const view = await open(c.inclusive);
            const user = userEvent.setup();
            for (const line of c.lines) {
                const p = shared.products.find((x) => x.key === line.key)!;
                for (let i = 0; i < line.qty; i++) {
                    await user.click(card(view, p.name));
                    const dialog = screen.queryByRole('dialog');
                    if (dialog) {
                        if (line.variant) await user.click(within(dialog).getByText(line.variant));
                        for (let a = 0; a < (line.addon ?? 0); a++) await user.click(within(dialog).getByRole('button', { name: 'زيادة' }));
                        await user.click(within(dialog).getByRole('button', { name: 'إضافة للسلة' }));
                    }
                }
            }

            expect(amount(bar())).toBe(c.total);

            await user.click(within(screen.getByTestId('pos-paybar')).getAllByRole('button').find((b) => /الدفع/.test(b.textContent ?? ''))!);
            await user.click(await screen.findByRole('button', { name: /نقدي/ }));
            await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
            await waitFor(() => expect(sent).toHaveLength(1));
            // ‏وما يُرسل هو ما على الشاشة — فلا يردّه الخادمُ (`assertScreenTotal`)
            expect(sent[0].expected_total).toBe(c.total);
        });
    }

    it('والماسحُ يبيع بسعر البيع كالبطاقة', async () => {
        await open(false);
        const user = userEvent.setup();
        await user.type(screen.getByPlaceholderText('امسح الباركود'), '6290000001001{Enter}');

        expect(amount(bar())).toBe(10.957);
    });

    it('والبطاقةُ تعرض ما يُقبض، وقبل الخصم مشطوبًا — وصنفٌ بلا خصمٍ بسعره وحده', async () => {
        const view = await open(false);
        const discounted = card(view, 'باقة بخصم');
        const plain = card(view, 'باقة بلا خصم');

        expect(discounted.textContent).toContain('10.435');
        expect(discounted.querySelector('.line-through')?.textContent).toContain('12.276');
        expect(plain.textContent).toContain('35.664');
        expect(plain.querySelector('.line-through')).toBeNull();
    });

    it('ويُكمل `fromTill` سعرَ البيع الغائب من السعر — ولا يمسّ الحاضر', () => {
        const out = fromTill([
            { id: 1, name: 'بلا خصم', price: 4.5 },
            { id: 2, name: 'مخصوم', price: 10, sale_price: 8.5 },
        ] as unknown as TillProductRow[]);

        expect(out.map((p) => p.sale_price)).toEqual([4.5, 8.5]);
        expect(out.map((p) => p.price)).toEqual([4.5, 10]);
    });
});

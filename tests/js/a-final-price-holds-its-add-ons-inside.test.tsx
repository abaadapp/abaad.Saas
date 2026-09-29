import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { act, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { billableAddons, cartLineTotal, type CartItem, type ResumeCart, usePosCart } from '@/hooks/usePosCart';
import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * «السعر النهائيّ» في الطلب المخصَّص لا تُجمع إضافاتُه فوقه — في الصندوق كما في الخادم.
 *
 * ═══ العطب ═══
 *
 * طلبٌ مخصَّص بسعرٍ نهائيّ ١٧٫٢٠٠ اختار له الكاشيرُ كرتًا بـ٠٫٢٠٠. الكرتُ داخلَ
 * السعر — والنافذةُ ترسل ١٧٫٢٠٠ كما هو. لكنّ السلّة كانت تجمع إضافاتِ كلّ
 * بندٍ فوق سعره، فقرأت ١٧٫٤٠٠: في سطر البند، وفي المجموع، ومنه الخصمُ
 * والضريبةُ والنقاطُ وزرُّ الدفع. والخادمُ (`SaleLines`) يقبض ١٧٫٢٠٠.
 *
 * ═══ والقاعدة (`cartLineTotal`) ═══
 *
 *   منتجٌ عاديّ          ← السعر × الكمية + الإضافات
 *   مخصَّص «قيمة»        ← السعر (القيمةُ ناقصَ الإضافات) × الكمية + الإضافات
 *   مخصَّص «سعرٌ نهائيّ» ← السعر × الكمية — والإضافاتُ داخلَه
 *   إضافةٌ مستقلّة       ← السعر × الكمية
 *
 * والحالاتُ نفسُها يُحاسَب عليها الخادمُ في
 * `AFinalPriceHoldsItsAddOnsInsideTest` — من ملفٍّ واحد يقرؤه الطرفان.
 */

interface Case {
    name: string;
    kind: 'custom' | 'product' | 'standalone';
    mode?: 'budget' | 'value';
    price: number;
    qty: number;
    addon_qty: number;
    line: number;
}

const shared = JSON.parse(readFileSync(resolve(__dirname, '../fixtures/pos-line-totals.json'), 'utf8')) as {
    addon_price: number;
    cases: Case[];
};

const CARD = { addon_id: 10, name: 'كرت', price: shared.addon_price, qty: 1 };

const custom = (mode: 'budget' | 'value', price: number) => ({
    template_id: 1, template_name: 'طلب مخصص', mode, price,
    base_value: mode === 'value' ? price : null, fields: [], components: [],
});

/** البندُ كما يدخل السلّة لكلّ حالةٍ من الملفّ المشترك */
const lineOf = (c: Case): Omit<CartItem, 'key' | 'note'> => {
    if (c.kind === 'standalone') {
        return { id: null, addon_id: 10, name: 'كرت', price: c.price, qty: c.qty };
    }

    const addons = c.addon_qty > 0 ? [{ ...CARD, qty: c.addon_qty }] : [];

    return c.kind === 'custom'
        ? { id: null, name: 'طلب مخصص', price: c.price, qty: c.qty, addons, custom: custom(c.mode!, c.price) }
        : { id: 1, name: 'باقة', price: c.price, qty: c.qty, addons };
};

const r3 = (v: number) => Math.round(v * 1000) / 1000;

/* ═══════════════════════════ القاعدة ═══════════════════════════ */

describe('ثمنُ البند — حالاتُ الخادم نفسُها', () => {
    it.each(shared.cases)('$name ← $line', (c) => {
        expect(r3(cartLineTotal(lineOf(c)))).toBe(c.line);
    });

    it('والإضافاتُ في «السعر النهائيّ» باقيةٌ على البند — لا تُدفع وحدها', () => {
        const budget = lineOf(shared.cases.find((c) => c.mode === 'budget' && c.qty === 1)!);

        expect(budget.addons).toHaveLength(1);
        expect(billableAddons(budget)).toBe(0);
        // وفي «القيمة» تُدفع مرّةً واحدة
        expect(billableAddons({ addons: [CARD], custom: custom('value', 17) })).toBe(0.2);
        expect(billableAddons({ addons: [CARD], custom: null })).toBe(0.2);
    });
});

/* ═══════════════════════════ السلّة ═══════════════════════════ */

const customers = [{ id: 1, name: 'مريم', name_en: null, label: 'مريم', phone: '91234567', points: 5000, language: 'ar' }];

const cart = (items: Omit<CartItem, 'key' | 'note'>[], vat?: { enabled: boolean; rate: number; inclusive: boolean }) =>
    renderHook(() =>
        usePosCart({
            products: [],
            customers,
            coupons: [],
            loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
            vat,
            resume: { id: null, customer: null, items } as ResumeCart,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            onToast: vi.fn(),
        }),
    );

const BUDGET = lineOf(shared.cases[0]);
const VALUE = lineOf(shared.cases[2]);
const PRODUCT = lineOf(shared.cases[4]);

describe('السلّةُ كلُّها تقرأ الثمنَ نفسَه', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        localStorage.clear();
        fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ ok: true, code: 'TEN', type: 'نسبة', value: 10, message: 'تم' }),
        }));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('«سعرٌ نهائيّ» ١٧٫٢٠٠ مع كرت ← المجموعُ والإجماليّ ١٧٫٢٠٠ لا ١٧٫٤٠٠', () => {
        const { result } = cart([BUDGET]);

        expect(r3(result.current.subtotal)).toBe(17.2);
        expect(r3(result.current.total)).toBe(17.2);
        expect(r3(result.current.displayTotal)).toBe(17.2);
    });

    it('و«قيمة + إضافات» ١٧٫٠٠٠ مع كرت ← ١٧٫٢٠٠، والمنتجُ ١٠ مع كرت ← ١٠٫٢٠٠', () => {
        expect(r3(cart([VALUE]).result.current.subtotal)).toBe(17.2);
        expect(r3(cart([PRODUCT]).result.current.subtotal)).toBe(10.2);
        // والثلاثةُ معًا — كلٌّ بقاعدته
        expect(r3(cart([BUDGET, VALUE, PRODUCT]).result.current.subtotal)).toBe(44.6);
    });

    it('والضريبةُ من الثمن الصحيح — مضافةً ومشمولة', () => {
        const added = cart([BUDGET], { enabled: true, rate: 5, inclusive: false }).result.current;
        expect(r3(added.taxAmount)).toBe(0.86); // ٥٪ من ١٧٫٢٠٠ — لا من ١٧٫٤٠٠ (٠٫٨٧)
        expect(r3(added.total)).toBe(18.06);

        const inside = cart([BUDGET], { enabled: true, rate: 5, inclusive: true }).result.current;
        expect(r3(inside.taxAmount)).toBe(r3((17.2 * 5) / 105));
        expect(r3(inside.total)).toBe(17.2);
    });

    it('والكوبونُ يُسأل عنه ويُحسب على ١٧٫٢٠٠', async () => {
        const { result } = cart([BUDGET]);

        await act(async () => {
            await result.current.applyCoupon('TEN');
        });

        const sent = JSON.parse(String((fetchMock.mock.calls[0][1] as { body: string }).body));
        expect(sent.subtotal).toBeCloseTo(17.2, 6);
        expect(r3(result.current.couponDiscount)).toBe(1.72);
        expect(r3(result.current.total)).toBe(15.48);
    });

    it('والنقاطُ: سقفُ الاستبدال وما يُكسب من الثمن الصحيح', async () => {
        const { result } = cart([BUDGET]);

        await act(async () => {
            result.current.selectCustomer('مريم', 1);
        });
        expect(r3(result.current.redeemCap)).toBe(8.6); // نصفُ ١٧٫٢٠٠
        expect(result.current.pointsToEarn).toBe(17);

        await act(async () => {
            result.current.setRedeemActive(true);
        });
        expect(r3(result.current.redeemDiscount)).toBe(8.6);
        expect(r3(result.current.total)).toBe(8.6);
    });
});

/* ═══════════════════════════ الشاشة ═══════════════════════════ */

describe('سطرُ البند في السلّة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

        Object.assign(pageProps, {
            translations: {},
            products: [],
            categories: [{ value: 'الكل', label: 'الكل' }],
            seasons: [],
            customers: [],
            addons: [],
            coupons: [],
            resumeCart: { id: null, customer: null, items: [BUDGET] },
            settings: {},
            orderOptions: undefined,
            customOrder: { templates: [] },
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });
        localStorage.clear();
    });

    it('يقول السعرَ النهائيّ وحده — والكرتُ تحته باقٍ', async () => {
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        // الكرتُ يُعرض تحت بنده كما كان
        expect(screen.getByText(/\+ كرت/)).toBeInTheDocument();
        // و١٧٫٤٠٠ لا تُكتب في أيّ موضع — لا في السطر ولا في المجموع ولا في زرّ الدفع
        expect(screen.queryAllByText(/17\.400/)).toHaveLength(0);
        // وثمنُ السطر والمجموعُ كلاهما ١٧٫٢٠٠
        expect(screen.getAllByText(/17\.200/).length).toBeGreaterThanOrEqual(2);
    });
});

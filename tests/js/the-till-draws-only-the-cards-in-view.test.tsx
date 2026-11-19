import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { WINDOW_FROM } from '@/Pages/Pos/partials/ProductGrid';
import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏الصندوقُ يرسم البطاقاتِ الظاهرة وحدها — والبيعُ هو هو.
 *
 * ═══ ما كان ═══
 *
 * عشرةُ آلاف صنفٍ = عشرةُ آلاف بطاقةٍ في الصفحة. على جهازٍ أبطأ بأربع مرّات: فتحُ
 * الصندوق 8.35 ثانية، والإضافةُ للسلّة 1.47، ومسحُ البحث 4.18 — والسببُ عددُ
 * البطاقات لا البيانات (البحثُ في عشرة آلاف صفٍّ ملّيثانيةٌ واحدة).
 *
 * فمن `WINDOW_FROM` صنفًا تُرسم الصفوفُ التي في الإطار وثلاثةٌ حولها، ويقوم مقامَ
 * الباقي فراغان بطوله. وما دونه يُرسم كلُّه كما كان.
 *
 * ‏jsdom لا يخطّط الصفحة، فيُحاكى هنا تخطيطُ شاشةٍ بأربعة أعمدة: صفٌّ بـ292 بكسلًا
 * (بطاقةٌ 280 وفجوةٌ 12)، وإطارٌ بـ900.
 */

const ROW = 292;
const VIEW = 900;
const COLS = 4;

const product = (i: number) => ({
    id: i, name: `منتج ${i}`, name_en: `Product ${i}`, label: `منتج ${i}`, cat: 'ورود', price: 1 + (i % 7), cost: 0.5, qty: 30,
    sku: `SKU-${i}`, barcode: `629${String(i).padStart(10, '0')}`, image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true,
    active: true, pos_enabled: true, pos_description: null, alert: 1, tax: 5, variants: [], addon_ids: [], has_recipe: false, discount: 0,
});
const shelf = (n: number) => Array.from({ length: n }, (_, k) => product(k + 1));

const scroller = () => document.querySelector('.overflow-y-auto') as HTMLElement;
const grid = () => document.querySelector('[data-pos-card]')?.parentElement as HTMLElement;
const cards = () => [...document.querySelectorAll('section [data-pos-card] h3')].map((h) => h.textContent);

/* ‏تخطيطُ شاشةٍ لا يحسبه jsdom */
const real = { getComputedStyle: window.getComputedStyle, rect: Element.prototype.getBoundingClientRect };
// ‏والتجسّسُ يُستعاد بعد كلّ اختبار (`restoreMocks`) — فيُعاد قبل كلٍّ منها
const fakeGrid = () =>
    vi.spyOn(window, 'getComputedStyle').mockImplementation((el: Element, pseudo?: string | null) => {
        const style = real.getComputedStyle.call(window, el, pseudo);
        if (!(el as HTMLElement).hasAttribute?.('data-pos-window') && !el.querySelector?.(':scope > [data-pos-card]')) return style;

        return new Proxy(style, {
            get: (t, k) => (k === 'gridTemplateColumns' ? Array(COLS).fill('280px').join(' ') : k === 'rowGap' ? '12px' : Reflect.get(t, k)),
        });
    });
beforeAll(() => {
    Object.defineProperty(HTMLElement.prototype, 'offsetTop', {
        configurable: true,
        get(this: HTMLElement) {
            if (!this.hasAttribute('data-pos-card') || !this.parentElement) return 0;
            const k = [...this.parentElement.children].filter((c) => c.hasAttribute('data-pos-card') || c.querySelector('h3')).indexOf(this);

            return Math.floor(k / COLS) * ROW;
        },
    });
    Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
        configurable: true,
        get(this: HTMLElement) {
            return this.classList.contains('overflow-y-auto') ? VIEW : 0;
        },
    });
    // ‏الشبكةُ تصعد في الإطار بقدر ما مُرِّر
    Element.prototype.getBoundingClientRect = function (this: Element) {
        const top = this.hasAttribute('data-pos-window') ? -(scroller()?.scrollTop ?? 0) : 0;

        return { top, bottom: top, left: 0, right: 0, width: 0, height: 0, x: 0, y: top, toJSON: () => ({}) } as DOMRect;
    };
});
afterAll(() => {
    Element.prototype.getBoundingClientRect = real.rect;
    delete (HTMLElement.prototype as { offsetTop?: number }).offsetTop;
    delete (HTMLElement.prototype as { clientHeight?: number }).clientHeight;
});

let sent: Record<string, unknown>[] = [];
const open = async (n: number) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        products: shelf(n),
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [],
        addons: [],
        coupons: [],
        settings: { vat: { enabled: true, rate: 5, inclusive: false } },
        orderOptions: undefined,
        customOrder: { templates: [{ id: 1, name: 'طلب مخصص', fields: [] }] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};
const scrollTo = async (top: number) => {
    scroller().scrollTop = top;
    fireEvent.scroll(scroller());
    await new Promise((r) => setTimeout(r, 40));
};
const clickCard = async (user: ReturnType<typeof userEvent.setup>, name: string) => {
    const card = [...document.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === name) as HTMLElement;
    await user.click(card);
};
const pay = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(within(screen.getByTestId('pos-paybar')).getAllByRole('button').find((b) => /الدفع/.test(b.textContent ?? ''))!);
    await user.click(await screen.findByRole('button', { name: /نقدي/ }));
    await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    await waitFor(() => expect(sent).toHaveLength(1));

    return sent[0];
};

beforeEach(() => {
    vi.resetModules();
    fakeGrid();
    sent = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
        if (String(url).includes('/pos/checkout')) {
            sent.push(JSON.parse(String(init?.body)));

            return { ok: true, status: 200, json: async () => ({ ok: true, invoice: 'INV-1', total: sent.at(-1)!.expected_total }) };
        }

        return { ok: true, status: 200, json: async () => ({}) };
    }));
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('بطاقاتُ الصندوق الظاهرة', () => {
    it(`ما دون ${WINDOW_FROM} صنفًا يُرسم كلُّه كما كان — بلا فراغٍ ولا نافذة`, async () => {
        await open(WINDOW_FROM - 1);

        expect(cards()).toHaveLength(WINDOW_FROM - 1);
        expect(document.querySelector('[data-pos-window]')).toBeNull();
        // ‏و«تخصيص الطلب» أوّلُ خانة
        expect(grid().firstElementChild?.textContent).toContain('طلب مخصص');
    });

    it('عشرةُ آلاف صنف: يُرسم ما في الإطار وحده، وطولُ الشبكة طولُ القائمة كلِّها', async () => {
        await open(10_000);
        await waitFor(() => expect(document.querySelector('[data-pos-window]')?.getAttribute('data-pos-window')).toMatch(/^0-\d+\/2501$/));

        // ‏إطارٌ بثلاثة صفوفٍ وثلاثةٌ تحته — لا عشرةُ آلاف
        expect(cards().length).toBeLessThanOrEqual(8 * COLS);
        expect(cards()[0]).toBe('منتج 1');
        expect(grid().firstElementChild?.textContent).toContain('طلب مخصص');

        const spacers = [...grid().children].filter((c) => c.getAttribute('aria-hidden') === 'true') as HTMLElement[];
        const drawnRows = Math.ceil((cards().length + 1) / COLS);
        const height = spacers.reduce((s, el) => s + parseFloat(el.style.height) + 12, 0) + drawnRows * ROW;
        // ‏(10,000 صنف + بطاقة التخصيص) ÷ 4 = 2,501 صفّ — والفرقُ فجوةٌ واحدة
        expect(Math.abs(height - 2501 * ROW)).toBeLessThanOrEqual(12);
    });

    it('يُمرَّر إلى منتصفها فتُرسم بطاقاتُ المنتصف — بترتيبها — وتُباع بالضغط', async () => {
        await open(10_000);
        await waitFor(() => expect(document.querySelector('[data-pos-window]')).not.toBeNull());
        await scrollTo(1250 * ROW);

        await waitFor(() => expect(cards()).toContain('منتج 5000'));
        const shown = cards().map((c) => Number(c?.replace('منتج ', '')));
        expect(shown).toEqual([...shown].sort((a, b) => a - b));
        expect(shown.every((n, k) => k === 0 || n === shown[k - 1] + 1)).toBe(true);
        expect(cards().length).toBeLessThanOrEqual(10 * COLS);
        expect(grid().textContent).not.toContain('طلب مخصص');

        const user = userEvent.setup();
        await clickCard(user, 'منتج 5000');
        // ‏السعرُ 1 + (5000 mod 7) = 3، وضريبتُه 5%
        const sale = await pay(user);
        expect(sale.expected_total).toBe(3.15);
        expect((sale.items as { id: number }[])[0].id).toBe(5000);
    });

    it('والعودةُ إلى أوّلها تُعيد «تخصيص الطلب» والأوائل', async () => {
        await open(10_000);
        await waitFor(() => expect(document.querySelector('[data-pos-window]')).not.toBeNull());
        await scrollTo(800 * ROW);
        await waitFor(() => expect(cards()[0]).not.toBe('منتج 1'));
        await scrollTo(0);

        await waitFor(() => expect(cards()[0]).toBe('منتج 1'));
        expect(grid().firstElementChild?.textContent).toContain('طلب مخصص');
    });

    it('والبحثُ يجد صنفًا لم يُرسم، والماسحُ يُضيف صنفًا لم يُرسم', async () => {
        await open(10_000);
        const user = userEvent.setup();
        await waitFor(() => expect(document.querySelector('[data-pos-window]')).not.toBeNull());
        expect(cards()).not.toContain('منتج 9876');

        await user.type(screen.getByPlaceholderText('ابحث عن منتج بالاسم أو الرمز…'), 'منتج 9876');
        await waitFor(() => expect(cards()).toEqual(['منتج 9876']));
        await user.clear(screen.getByPlaceholderText('ابحث عن منتج بالاسم أو الرمز…'));

        await user.type(screen.getByPlaceholderText('امسح الباركود'), '6290000009999{Enter}');
        const sale = await pay(user);
        // ‏1 + (9999 mod 7) = 4، و5% = 4.200
        expect((sale.items as { id: number }[])[0].id).toBe(9999);
        expect(sale.expected_total).toBe(4.2);
    });
});

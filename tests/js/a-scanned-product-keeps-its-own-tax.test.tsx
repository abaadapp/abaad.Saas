import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏صنفٌ يُمسح باركودُه يدخل السلّةَ بضريبته هو — كما يدخلها بالضغط على بطاقته.
 *
 * ═══ ما كان ═══
 *
 * قائمةُ الأصناف التي تقرؤها السلّةُ (`cartProducts`) لا تحمل `tax`، والماسحُ
 * (`scanBarcode`) يأخذ الضريبةَ منها — فيدخل الصنفُ الممسوح بلا نسبته، ويُحسب
 * بنسبة المتجر. صنفٌ بضريبةٍ صفرٍ محدَّدة في متجرٍ بخمسة: الشاشةُ تقول 58.340
 * والخادمُ يحسب 56.557. بالاتّصال يردّ الخادمُ البيعةَ ويطلب تأكيدَ الصحيح؛
 * وبلا اتّصال يقبض الكاشيرُ ما على الشاشة، ويكتب الخادمُ غيرَه ويعلّمه للمراجعة.
 */

const product = (id: number, name: string, price: number, tax: number, barcode: string) => ({
    id, name, name_en: null, label: name, cat: 'ورود', price, cost: 1, qty: 50, sku: null, barcode,
    image: null, thumb: null, stock_status: 'متوفر', tracks_stock: true, active: true, pos_enabled: true,
    pos_description: null, alert: 1, tax, variants: [], addon_ids: [], has_recipe: false, discount: 0,
});
const shelf = [
    product(1, 'باقة بلا ضريبة', 35.664, 0, '6290000000750'),
    product(2, 'باقة بخمسة', 19.898, 5, '6290000000001'),
];

const open = async (resumeCart: unknown = null) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        resumeCart,
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
const click = async (view: ReturnType<typeof render>, name: string) => {
    const card = [...view.container.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === name) as HTMLElement;
    await userEvent.setup().click(card);
};

beforeEach(() => {
    vi.resetModules();
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({}) })));
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('الماسحُ والبطاقة', () => {
    it('صنفٌ بضريبةٍ صفرٍ يُمسح فيُحسب كما يُحسب بالضغط عليه — والشريطُ يقول ما يكتبه الخادم', async () => {
        const clicked = await open();
        await click(clicked, 'باقة بلا ضريبة');
        await click(clicked, 'باقة بخمسة');
        const byCard = bar();
        cleanup();

        vi.resetModules();
        const scanned = await open();
        const user = userEvent.setup();
        await user.type(screen.getByPlaceholderText('امسح الباركود'), '6290000000750{Enter}');
        await click(scanned, 'باقة بخمسة');

        expect(bar()).toBe(byCard);
        // ‏55.562 + 5% من 19.898 وحدها = 56.557 — لا 5% من المجموع كلِّه (58.340)
        expect(byCard).toContain('56.557');
    });
});

/**
 * ‏والسلّةُ المعلّقة تعود بضريبة كلّ صنفٍ فيها — لا بنسبة المتجر.
 *
 * الخادمُ (`PosController::resume`) يعيد البنودَ بأسعارها وكميّاتها ولا يعيد
 * نسبتَها، والدفعُ يُسعّرها من جديد بنسبة كلّ صنف. فكانت الشاشةُ تضرب الصنفَ
 * المعفى بخمسة: تقول 58.340 والخادمُ يكتب 56.557.
 */
describe('السلّةُ المعلّقة حين تُستأنف', () => {
    it('تُحسب كما تُحسب الأصنافُ نفسُها بالضغط عليها', async () => {
        const clicked = await open();
        await click(clicked, 'باقة بلا ضريبة');
        await click(clicked, 'باقة بخمسة');
        const byCard = bar();
        cleanup();

        vi.resetModules();
        await open({
            id: 7,
            customer: null,
            items: [
                { id: 1, name: 'باقة بلا ضريبة', price: 35.664, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null },
                { id: 2, name: 'باقة بخمسة', price: 19.898, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null },
            ],
            coupon_code: null,
        });

        expect(bar()).toBe(byCard);
        expect(bar()).toContain('56.557');
    });

    it('وبندٌ لا صنفَ له على الرفّ — إضافةٌ أو طلبٌ مخصَّص أو صنفٌ أُوقف — يبقى بنسبة المتجر كما يحسبه الخادم', async () => {
        await open({
            id: 8,
            customer: null,
            items: [{ id: 99, name: 'صنفٌ أُوقف', price: 10, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null }],
            coupon_code: null,
        });

        // ‏10 + 5% = 10.500
        expect(bar()).toContain('10.500');
    });
});

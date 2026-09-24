import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * الصندوقُ يقول ما بقي من نقاطه — لا ما خرج منها وحده.
 *
 * ═══ ما كان ═══
 *
 * «− ٣٫٠٠٠ (٣٠٠ نقطة)». تقول ما خرج ولا تقول ما بقي. والزبون يسأل الكاشيرَ
 * عن رصيده وهو واقف، فيطرح الكاشيرُ في رأسه — أو يفتح شاشة العملاء
 * والطابورُ خلفه.
 */

const CUSTOMER = {
    id: 1, name: 'مريم', label: 'مريم — 96899110001', phone: '96899110001',
    points: 500, language: 'ar',
};

const draw = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        translations: {},
        products: [],
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [CUSTOMER],
        addons: [],
        coupons: [],
        // فاتورةٌ بأربعين: السقفُ نصفُها، فيسع خصمُ ثلاثة ريالاتٍ كاملًا
        resumeCart: { id: null, customer: 'مريم', items: [{ id: 1, name: 'باقة ورد', price: 20, qty: 2 }] },
        settings: { loyaltyEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        ...over,
    });
    localStorage.clear();
};

const open = async () => {
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};

describe('بطاقةُ نقاط الولاء في الصندوق', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    /** ولا سطرَ خصمٍ قبل أن يُضغط الزرّ — النقاط تُستبدَل باختيار */
    it('لا تخصم قبل أن يُضغط «استخدم النقاط»', async () => {
        draw();
        await open();

        expect(screen.getByText(/نقاط العميل:/)).toBeInTheDocument();
        expect(screen.queryByTestId('redeem-line')).toBeNull();
    });

    /**
     * ═══ وما بقي يُقال إلى جانب ما خرج ═══
     *
     * خمسمئة نقطةٍ تساوي خمسة ريالات، والسقفُ نصفُ فاتورةٍ بأربعين — أي
     * عشرين. فيُخصم رصيدُه كلُّه: خمسة ريالاتٍ وخمسمئة نقطة، ولا يبقى شيء.
     */
    it('وتقول ما بقي بعد الخصم', async () => {
        draw();
        await open();

        fireEvent.click(screen.getByRole('button', { name: 'استخدم النقاط' }));

        const line = screen.getByTestId('redeem-line');

        expect(line).toHaveTextContent('500 نقطة');
        expect(line).toHaveTextContent(/يبقى 0 نقطة/);
    });

    /**
     * ورصيدٌ يتجاوز سقفَ الفاتورة يُخصم منه ما يسعه — والباقي يُقال.
     *
     * فاتورةٌ بعشرة: سقفُها خمسة ريالات، أي خمسمئة نقطة. ورصيدُه ألف —
     * فتبقى له خمسمئة، وهي الحالُ التي يسأل عنها الزبون أكثر من غيرها.
     */
    it('وتقول الباقي حين لا يسع السقفُ رصيدَه كلَّه', async () => {
        draw({
            customers: [{ ...CUSTOMER, points: 1000 }],
            resumeCart: { id: null, customer: 'مريم', items: [{ id: 1, name: 'باقة ورد', price: 10, qty: 1 }] },
        });
        await open();

        fireEvent.click(screen.getByRole('button', { name: 'استخدم النقاط' }));

        const line = screen.getByTestId('redeem-line');

        expect(line).toHaveTextContent('500 نقطة');
        expect(line).toHaveTextContent(/يبقى 500 نقطة/);
    });

    /** ويختفي السطرُ حين يتراجع — فلا يبقى رقمٌ لخصمٍ أُلغي */
    it('ويختفي حين يتراجع عن الاستبدال', async () => {
        draw();
        await open();

        fireEvent.click(screen.getByRole('button', { name: 'استخدم النقاط' }));
        expect(screen.getByTestId('redeem-line')).toBeInTheDocument();

        // و«إلغاء» غيرُ وحيدةٍ في الشاشة — فيُضغط زرُّ البطاقة نفسِه
        fireEvent.click(screen.getAllByRole('button', { name: 'إلغاء' })[0]);
        expect(screen.queryByTestId('redeem-line')).toBeNull();
    });
});

/**
 * وورقةُ النجاح تقول الثلاثة: ما استُبدل، وما كُسب، وما بقي.
 *
 * وكانت تقول المكتسب وحده — فالزبون الذي استبدل ثلاثمئة يقرأ «نقاط ولاء
 * مكتسبة: ٤٠» ولا يعرف ماذا بقي له.
 */
describe('ورقةُ النجاح بعد البيع', () => {
    const paid = async (result: Record<string, unknown>) => {
        Object.assign(pageProps, {
            translations: {},
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: [] },
        });

        const { default: PaymentDialog } = await import('@/Pages/Pos/partials/PaymentDialog');
        const user = userEvent.setup();

        render(
            <PaymentDialog
                open
                onOpenChange={() => {}}
                total={12.5}
                displayTotal={12.5}
                customer="مريم"
                money={(v) => String(v)}
                fmt={(v) => String(v)}
                onCheckout={(async () => ({ synced: true, invoice: 'INV-1', rejected: false, ...result })) as never}
                onNewOrder={() => {}}
                methods={['نقدي']}
            />,
        );

        await user.click(screen.getByRole('button', { name: /نقدي/ }));
        await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));
    };

    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('تقول ما استُبدل وما كُسب وما بقي', async () => {
        await paid({ points: 40, redeemed: 300, balance: 240 });

        const box = await screen.findByTestId('points-summary');

        expect(box).toHaveTextContent(/نقاط مستبدَلة:\s*−\s*300/);
        expect(box).toHaveTextContent(/نقاط ولاء مكتسبة:\s*\+\s*40/);
        expect(box).toHaveTextContent(/الرصيد المتبقي:\s*240/);
    });

    /**
     * ولا رصيدَ يُعرض لبيعةٍ لم يردّ الخادمُ عنها رقمًا.
     *
     * البيعةُ بلا اتصالٍ تبقى في الطابور ولم تُخصم نقاطُها بعد — ورقمٌ
     * يُعرض عنها كذبٌ يقرؤه الزبون على أنّه رصيدُه.
     */
    it('ولا تعرض رصيدًا لبيعةٍ لم يردّ عنها الخادم', async () => {
        await paid({ points: 0, redeemed: 0, balance: null, synced: false, invoice: null });

        expect(screen.queryByTestId('points-summary')).toBeNull();
    });

    /**
     * ═══ وكسبٌ بلا رصيدٍ مردود لا يفتح الصندوق ═══
     *
     * وهو السؤالُ الواحد الذي عليه الصندوقُ كلُّه: أردّ الخادمُ رصيدًا؟
     * ولو عُلِّق على «كسب أو استبدل» لَانفتح بلا رصيدٍ يُعرض فيه — سطران
     * وثالثٌ فارغ.
     */
    it('ولا يفتح الصندوق بكسبٍ بلا رصيد', async () => {
        await paid({ points: 40, redeemed: 0, balance: null });

        expect(screen.queryByTestId('points-summary')).toBeNull();
    });

    /** وزبونٌ لم يستبدل ولم يكسب يقرأ رصيدَه كما هو */
    it('ويقول الرصيد ولو لم يستبدل ولم يكسب', async () => {
        await paid({ points: 0, redeemed: 0, balance: 500 });

        const box = await screen.findByTestId('points-summary');

        expect(box).toHaveTextContent(/الرصيد المتبقي:\s*500/);
        expect(box).not.toHaveTextContent(/مستبدَلة/);
        expect(box).not.toHaveTextContent(/مكتسبة/);
    });
});

/**
 * ═══ وأصلُ العطب: البطاقةُ تُصحَّح بعد البيع ═══
 *
 * `customers` حالةٌ محليّة تُملأ مرّةً عند فتح الشاشة، وما يُجلب بعد البيع
 * هو المنتجات وحدها (`onSynced`). فكان الرصيد يبقى خمسمئة بعد أن خُصمت منه
 * ثلاثمئة — ثمّ تُرفض البيعةُ التالية بـ«تغيّر رصيد نقاط العميل».
 *
 * وهذا حارسُ العلاج: الفاتورةُ تردّ الرصيد، والشاشةُ تكتبه في الحال.
 */
describe('رصيدُ النقاط بعد بيعة', () => {
    const shelf = [{ id: 1, label: 'باقة ورد', price: 20, image: null, sku: null, barcode: null, stock: 50 }];
    const buyer = { id: 7, name: 'مريم', label: 'مريم', phone: '96899110001', points: 500, language: 'ar' };

    const till = async (body: Record<string, unknown>) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({ ok: true, status: 200, json: async () => body })),
        );

        const { renderHook, act } = await import('@testing-library/react');
        const { usePosCart } = await import('@/hooks/usePosCart');

        const { result } = renderHook(() =>
            usePosCart({
                products: shelf,
                customers: [buyer],
                coupons: [],
                loyalty: { loyaltyEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
                vat: { enabled: false, rate: 0, inclusive: false },
                resume: undefined,
                currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
                onToast: () => {},
            } as never),
        );

        await act(async () => {
            result.current.add(shelf[0] as never);
            result.current.selectCustomer('مريم', 7);
        });

        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });

        return result;
    };

    beforeEach(() => {
        localStorage.clear();
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
    });

    it('يكتب الرصيد الذي ردّته الفاتورة', async () => {
        const result = await till({ invoice: 'INV-1', points_earned: 17, points_redeemed: 300, points_balance: 217 });

        expect(result.current.selectedPoints).toBe(217);
    });

    /**
     * ولا يُكتب رصيدٌ لم يُردّ.
     *
     * الردُّ المكرَّر لا يحمل نقاطًا — ولو كُتب صفرٌ مكانه لَقرأ الكاشير أنّ
     * نقاط الزبون نفدت وهي في حسابه كما هي.
     */
    it('ولا يمحو الرصيد حين لا تردّه الفاتورة', async () => {
        const result = await till({ invoice: 'INV-1', duplicate: true });

        expect(result.current.selectedPoints).toBe(500);
    });
});

import { useState } from 'react';
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * الاستبدالُ بنسبةٍ من الفاتورة — في الصندوق وفي صفحة برنامج الولاء.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الصندوقُ يعرض ما يُخصم ونسبتَه من هذه الفاتورة، ويُرسل النقاطَ والخصمَ
 *     كما يحسبهما الخادم (`Loyalty::quote` — مرآتُه `lib/loyalty.ts`).
 *   - الرصيدُ يُقال نسبةً لا مالًا: قيمةُ النقطة في النسبة تتبع الفاتورة.
 *   - صفحةُ الولاء تختار الطريقة، وتُظهر حقلَها وحده، وتُرسل ما اختير.
 *
 * والخادمُ يحرس الكتابةَ (`LoyaltyRedeemsByAFixedAmountOrAPercentTest`).
 */

const posts: { url: string; data: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: string, value: unknown) => setData((d) => ({ ...d, [key]: value })),
            post: (url: string) => {
                posts.push({ url, data: { ...data } });
            },
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const PERCENT = { loyaltyEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100, redeemMode: 'percent', redeemPercent: 10, redeemPoints: 100 };
const CURRENCY = { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 };

const reset = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        context: { currency: CURRENCY },
        auth: { abilities: [], mayActions: [] },
        flash: { toast: null, status: null },
        errors: {},
        ...over,
    });
    localStorage.clear();
    posts.length = 0;
};

describe('الصندوقُ بطريقة النسبة', () => {
    beforeEach(() => reset());

    const draw = (points: number, settings: Record<string, unknown> = PERCENT) =>
        Object.assign(pageProps, {
            products: [],
            categories: [{ value: 'الكل', label: 'الكل' }],
            seasons: [],
            customers: [{ id: 1, name: 'مريم', label: 'مريم', phone: '96899110001', points, language: 'ar' }],
            addons: [],
            coupons: [],
            // سلّةٌ بأربعين: السقفُ ٥٠٪ = عشرون
            resumeCart: { id: null, customer: 'مريم', items: [{ id: 1, name: 'باقة ورد', price: 20, qty: 2 }] },
            settings,
            orderOptions: undefined,
            customOrder: { templates: [] },
        });

    it('يعرض الخصمَ ونسبتَه من هذه الفاتورة، والرصيدَ نسبةً لا مالًا', async () => {
        draw(800);
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        // ٨٠٠ نقطة = ٨٠٪؛ والسقفُ ٥٠٪ من أربعين = عشرون مقابل ٥٠٠ نقطة
        expect(screen.getByTestId('points-worth')).toHaveTextContent('(80%)');
        const offer = screen.getByTestId('redeem-offer');
        expect(offer).toHaveTextContent(/20[.,]000/);
        expect(offer).toHaveTextContent('(50%)');
        expect(offer).toHaveTextContent('500 نقطة');

        fireEvent.click(screen.getByRole('button', { name: 'استخدام النقاط' }));

        const line = screen.getByTestId('redeem-line');
        expect(line).toHaveTextContent('500 نقطة');
        expect(line).toHaveTextContent(/20[.,]000/);
        expect(line).toHaveTextContent(/يبقى 300 نقطة\s*\(30%\)/);
    });

    it('بلا سقفٍ يحدّده التاجر: النقاطُ تخصم ما تساويه حتّى الفاتورةَ كلَّها — لا صفرٌ يُغلقها', async () => {
        draw(800, { ...PERCENT, redeemMaxPct: null, redeemMaxAmount: null });
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        // ٨٠٠ نقطة = ٨٠٪ من أربعين = ٣٢
        const offer = screen.getByTestId('redeem-offer');
        expect(offer).toHaveTextContent(/32[.,]000/);
        expect(offer).toHaveTextContent('(80%)');
        expect(offer).toHaveTextContent('800 نقطة');
    });

    it('وسقفُ المبلغ يحدّ الخصمَ ويُقال تحته', async () => {
        draw(800, { loyaltyEnabled: true, redeemMaxPct: null, redeemMaxAmount: 7, earnRate: 1, redeemMin: 100 });
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        // مبلغٌ ثابت بالصرف القديم: سبعةٌ = ٧٠٠ نقطة، ويبقى مئة
        expect(screen.getByTestId('redeem-offer')).toHaveTextContent(/7[.,]000/);
        fireEvent.click(screen.getByRole('button', { name: 'استخدام النقاط' }));
        expect(screen.getByTestId('redeem-line')).toHaveTextContent('700 نقطة');
        expect(screen.getByText(/الحد الأقصى لهذه الفاتورة/)).toHaveTextContent(/7[.,]000/);
        expect(screen.getByText(/الحد الأقصى لهذه الفاتورة/)).not.toHaveTextContent('%');
    });

    it('يُرسل النقاطَ والخصمَ كما يحسبهما الخادم', async () => {
        const fetch = vi.fn(async (_url: string, _init?: RequestInit) => ({
            ok: true, status: 200, json: async () => ({ invoice: 'INV-1', points_redeemed: 500, points_balance: 300 }),
        }));
        vi.stubGlobal('fetch', fetch);
        const shelf = [{ id: 1, label: 'باقة ورد', price: 20, image: null, sku: null, barcode: null, stock: 50 }];

        const { usePosCart } = await import('@/hooks/usePosCart');
        const { result } = renderHook(() =>
            usePosCart({
                products: shelf,
                customers: [{ id: 7, name: 'مريم', label: 'مريم', phone: '96899110001', points: 800, language: 'ar' }],
                coupons: [],
                loyalty: PERCENT,
                vat: { enabled: false, rate: 0, inclusive: false },
                resume: undefined,
                currency: CURRENCY,
                onToast: () => {},
            } as never),
        );

        await act(async () => {
            result.current.add(shelf[0] as never);
            result.current.selectCustomer('مريم', 7);
        });
        await act(async () => result.current.chooseRedeem(true));

        // سلّةٌ بعشرين: السقفُ ٥٠٪ = عشرة — وخمسون بالمئة خمسُمئة نقطةٍ أيًّا كانت الفاتورة
        expect(result.current.redeemPointsUsed).toBe(500);
        expect(result.current.redeemDiscount).toBe(10);

        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });

        const body = JSON.parse(String(fetch.mock.calls.at(-1)?.[1]?.body));
        expect(body.redeem_points).toBe(500);
        expect(body.redeem_discount).toBe(10);
        vi.unstubAllGlobals();
    });
});

describe('صفحةُ برنامج الولاء — طريقةُ الاستبدال', () => {
    beforeEach(() => reset());

    const page = async (settings: Record<string, string>) => {
        Object.assign(pageProps, {
            settings,
            summary: { members: 0, points: 0, earned: 0, redeemed: 0 },
            top: [],
            recent: [],
        });
        const { default: Loyalty } = await import('@/Pages/Admin/Marketing/Loyalty');
        render(<Loyalty />);
    };

    it('متجرٌ لم يختر يبقى على المبلغ الثابت بحقله', async () => {
        await page({ loyalty_enabled: '1' });

        expect(screen.getByTestId('redeem-mode-fixed')).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByTestId('redeem-value')).toBeInTheDocument();
        expect(screen.queryByTestId('redeem-percent')).toBeNull();
    });

    it('اختيارُ النسبة يُظهر حقلَها ومثالًا محسوبًا، ويُرسلها مع الحفظ', async () => {
        await page({ loyalty_enabled: '1', loyalty_redeem_value: '1' });

        fireEvent.click(screen.getByTestId('redeem-mode-percent'));

        expect(screen.getByTestId('redeem-mode-percent')).toHaveAttribute('aria-checked', 'true');
        expect(screen.queryByTestId('redeem-value')).toBeNull();
        fireEvent.change(screen.getByTestId('redeem-percent'), { target: { value: '10' } });

        // فاتورةٌ بعشرين ورصيدٌ بمئتي نقطة = ٢٠٪ = ٤٫٠٠٠ (تحت سقف الخمسين)
        expect(screen.getByTestId('redeem-percent-example')).toHaveTextContent(/4\.000/);
        expect(screen.getByTestId('redeem-percent-example')).toHaveTextContent('20');

        fireEvent.click(screen.getByRole('button', { name: /حفظ/ }));
        expect(posts.at(-1)?.data).toMatchObject({ loyalty_redeem_mode: 'percent', loyalty_redeem_percent: '10', loyalty_redeem_value: '1' });
    });

    it('السقفان اختياريّان: فراغٌ «بلا حدّ» يُرسل فارغًا، ومبلغٌ يُكتب', async () => {
        await page({ loyalty_enabled: '1', loyalty_redeem_max_pct: '' });

        expect((screen.getByTestId('redeem-max-pct') as HTMLInputElement).value).toBe('');
        expect(screen.getByTestId('redeem-max-pct')).toHaveAttribute('placeholder', 'بلا حدّ');
        fireEvent.change(screen.getByTestId('redeem-max-amount'), { target: { value: '7.5' } });

        fireEvent.click(screen.getByRole('button', { name: /حفظ/ }));
        expect(posts.at(-1)?.data).toMatchObject({ loyalty_redeem_max_pct: '', loyalty_redeem_max_amount: '7.5' });
    });

    it('ومتجرٌ لم يحفظ شيئًا يرى الافتراضَ: خمسون بالمئة، والمبلغُ بلا حدّ', async () => {
        await page({ loyalty_enabled: '1' });

        expect((screen.getByTestId('redeem-max-pct') as HTMLInputElement).value).toBe('50');
        expect((screen.getByTestId('redeem-max-amount') as HTMLInputElement).value).toBe('');
    });
});

describe('تنبيهُ الفاتورة — نقاطٌ تغطّيها كلَّها', () => {
    beforeEach(() => reset());

    it('يُعرض الخصمُ كاملًا ويُختار — الفاتورةُ تُصدَر بصفر (`CustomerInvoices::coveredByPoints`)', async () => {
        const { LoyaltyPrompt } = await import('@/Pages/Admin/CustomerInvoices/Create');
        const chose: string[] = [];
        render(
            <LoyaltyPrompt
                offer={{ eligible: true, reason: null, balance: 20000, points: 10000, discount: 100, percent: null }}
                choice="ask"
                onChoose={(c) => chose.push(c)}
            />,
        );

        expect(screen.getByTestId('invoice-loyalty')).toHaveTextContent(/100[.,]000/);
        expect(screen.getByTestId('invoice-loyalty')).toHaveTextContent('10000 نقطة');
        fireEvent.click(screen.getByRole('button', { name: 'استخدام النقاط' }));
        expect(chose).toEqual(['use']);
    });
});

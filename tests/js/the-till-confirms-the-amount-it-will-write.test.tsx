import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * المبلغُ الذي يقرؤه الكاشيرُ هو المبلغُ الذي يُكتب — أو يُؤكَّد الصحيحُ قبل أن يُقبض.
 *
 * ═══ قرارُ المالك (المراجعة الثامنة) ═══
 *
 *   ١. الشاشةُ ترسل ما قرأته (`expected_total`)، ومتى اكتملت البيعةُ عندها
 *      (`queued_at`)، وبأيّ قواعد نقاطٍ حسبت (`loyalty_rules`) — لبيعةٍ تصل بعد انقطاع.
 *   ٢. إن ردّ الخادمُ البيعةَ لأنّ المبلغ تغيّر، يُقال الصحيحُ، وضغطةُ الدفع التالية
 *      تأكيدٌ له لهذه السلّة وحدها — وأيُّ تغييرٍ فيها يُسقطه.
 *
 * ويقابله في الخادم `PosController::assertScreenTotal` و`reviewOffline`.
 */

const shelf = [{ id: 1, label: 'باقة ورد', price: 100, image: null, sku: null, barcode: null, stock: 50 }];
const buyer = { id: 7, name: 'مريم', label: 'مريم', phone: '96899110001', points: 1000, language: 'ar' };
const settings = { loyaltyEnabled: true, redeemEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100, redeemPoints: 100, redeemValue: 1, redeemMinInvoice: 0 };

type Sent = Record<string, unknown>;

const setOnline = (on: boolean) => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => on });
};

const hook = async (respond: (body: Sent) => { status: number; body: unknown }) => {
    const sent: Sent[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (_url: string, init: RequestInit) => {
            const body = JSON.parse(String(init.body)) as Sent;
            sent.push(body);
            const r = respond(body);

            return { ok: r.status < 300, status: r.status, json: async () => r.body };
        }),
    );

    const { usePosCart } = await import('@/hooks/usePosCart');
    const view = renderHook(() =>
        usePosCart({
            products: shelf,
            customers: [buyer],
            coupons: [],
            loyalty: settings,
            vat: { enabled: false, rate: 0, inclusive: false },
            resume: undefined,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            onToast: () => {},
        } as never),
    );

    await act(async () => {
        view.result.current.add(shelf[0] as never);
        view.result.current.selectCustomer('مريم', 7);
    });

    return { view, sent };
};

beforeEach(() => {
    localStorage.clear();
    setOnline(true);
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });
});

afterEach(() => {
    vi.unstubAllGlobals();
    setOnline(true);
});

describe('الصندوق — المبلغُ الذي يُقبض', () => {
    it('يُرسل ما قرأه الكاشيرُ ومتى، وقواعدَ النقاط حين يستبدل', async () => {
        const { view, sent } = await hook(() => ({ status: 200, body: { invoice: 'INV-1', points_balance: 0 } }));

        act(() => view.result.current.chooseRedeem(true));
        const before = Date.now();
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(sent[0].expected_total).toBe(90);
        expect(sent[0].queued_at as number).toBeGreaterThanOrEqual(before);
        expect(sent[0].loyalty_rules).toMatchObject({ mode: 'fixed', points: 100, value: 1, max_pct: 50 });
    });

    it('وبلا استبدال: لا قواعدَ تُرسل', async () => {
        const { view, sent } = await hook(() => ({ status: 200, body: { invoice: 'INV-1', points_balance: 1000 } }));

        act(() => view.result.current.chooseRedeem(false));
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(sent[0].expected_total).toBe(100);
        expect(sent[0].loyalty_rules).toBeNull();
    });

    it('ومبلغٌ تغيّر: تُردّ البيعةُ، ويُقال الصحيح، والضغطةُ التالية تؤكّده', async () => {
        let calls = 0;
        const { view, sent } = await hook(() => {
            calls++;
            return calls === 1
                ? { status: 422, body: { message: 'تغيّر مبلغ البيعة: الصحيح 110', errors: { expected_total: ['تغيّر مبلغ البيعة: الصحيح 110'] }, totals: { total: 110, shown: 100, stale_screen: false } } }
                : { status: 200, body: { invoice: 'INV-2', total: 110, points_balance: 1000 } };
        });

        act(() => view.result.current.chooseRedeem(false));
        let first: Awaited<ReturnType<typeof view.result.current.checkoutSale>> | undefined;
        await act(async () => {
            first = await view.result.current.checkoutSale('نقدي');
        });
        expect(first?.rejected).toBe(true);
        expect(view.result.current.items).toHaveLength(1);

        let second: Awaited<ReturnType<typeof view.result.current.checkoutSale>> | undefined;
        await act(async () => {
            second = await view.result.current.checkoutSale('نقدي');
        });
        expect(sent[1].expected_total).toBe(110);
        expect(second?.synced).toBe(true);
        expect(second?.total).toBe(110);
    });

    it('وتأكيدٌ لسلّةٍ لا يُقبض عن غيرها', async () => {
        let calls = 0;
        const { view, sent } = await hook(() => {
            calls++;
            return calls === 1
                ? { status: 422, body: { errors: { expected_total: ['تغيّر'] }, totals: { total: 110, shown: 100, stale_screen: false } } }
                : { status: 200, body: { invoice: 'INV-3', points_balance: 1000 } };
        });

        act(() => view.result.current.chooseRedeem(false));
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });
        // أُضيفت باقةٌ ثانية — فالمبلغُ يُحسب من جديد
        await act(async () => {
            view.result.current.add(shelf[0] as never);
        });
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(sent[1].expected_total).toBe(200);
    });
});

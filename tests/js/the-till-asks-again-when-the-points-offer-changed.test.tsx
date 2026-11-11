import { act, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * الصندوقُ يسأل من جديد حين يتغيّر عرضُ النقاط — ولا يستبدل بلا اتصال.
 *
 * ═══ قرارُ المالك ═══
 *
 *   ١. متّصلًا: إن ردّ الخادمُ البيعةَ لأنّ الرصيدَ أو القواعدَ تغيّرت، يُكتب
 *      الرصيدُ والقواعدُ الجديدة، ويعود التنبيهُ بزرّيه — ولا خصمَ حتّى يختار.
 *   ٢. بلا اتصال: لا استبدال، ورسالةٌ تقول ذلك، والبيعُ العاديّ يمضي.
 *   ٣. ما في الطابور بيعةٌ اكتملت: يُرسل `replay` فلا يردّه الخادمُ لأجل النقاط.
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

describe('الصندوقُ المتّصل — عرضٌ تغيّر يُسأل عنه من جديد', () => {
    it('يُرسل ما وافق عليه الكاشير: النقاطَ وقيمتَها', async () => {
        const { view, sent } = await hook(() => ({ status: 200, body: { invoice: 'INV-1', points_balance: 0 } }));

        act(() => view.result.current.chooseRedeem(true));
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(sent[0].redeem_points).toBe(1000);
        expect(sent[0].redeem_discount).toBe(10);
        expect(sent[0].replay).toBeUndefined();
    });

    it('وردٌّ بعرضٍ جديد: يُكتب الرصيدُ والقواعد، ويُلغى الاختيار، ويُقال السبب', async () => {
        const offer = { balance: 300, points: 300, discount: 3, reason: null, settings: { ...settings, redeemValue: 1 } };
        const { view } = await hook(() => ({
            status: 422,
            body: { errors: { redeem_points: ['تغيّر رصيد نقاط العميل — الرصيد الآن 300 نقطة'] }, loyalty: offer },
        }));

        act(() => view.result.current.chooseRedeem(true));
        let result: Awaited<ReturnType<typeof view.result.current.checkoutSale>> | undefined;
        await act(async () => {
            result = await view.result.current.checkoutSale('نقدي');
        });

        expect(result?.rejected).toBe(true);
        expect(result?.loyaltyChanged).toBe(true);
        expect(view.result.current.selectedPoints).toBe(300);
        expect(view.result.current.redeemActive).toBe(false);
        expect(view.result.current.redeemDecided).toBe(false);
        expect(view.result.current.redeemDiscount).toBe(0);
        expect(view.result.current.redeemQuote.max_points).toBe(300);
        expect(view.result.current.redeemQuote.max_discount).toBe(3);
        expect(view.result.current.redeemNotice).toContain('300');
        // والسلّةُ باقية — البيعةُ لم تُكتب
        expect(view.result.current.items).toHaveLength(1);
    });

    it('والقواعدُ الجديدة تُحسب بها المرآة: مئةُ نقطةٍ بريالين', async () => {
        const { view } = await hook(() => ({
            status: 422,
            body: { errors: { redeem_points: ['تغيّرت قواعد الاستبدال'] }, loyalty: { balance: 1000, points: 1000, discount: 20, reason: null, settings: { ...settings, redeemValue: 2 } } },
        }));

        act(() => view.result.current.chooseRedeem(true));
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(view.result.current.redeemQuote.max_discount).toBe(20);
    });

    it('وبرنامجٌ أُطفئ والصندوقُ مفتوح يختفي', async () => {
        const { view } = await hook(() => ({
            status: 422,
            body: { errors: { redeem_points: ['أُطفئ'] }, loyalty: { balance: 1000, points: 0, discount: 0, reason: 'off', settings: { ...settings, loyaltyEnabled: false } } },
        }));

        act(() => view.result.current.chooseRedeem(true));
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(view.result.current.loyaltyOn).toBe(false);
        expect(view.result.current.canRedeem).toBe(false);
    });
});

describe('بلا اتصال — لا استبدال، والبيعُ العاديّ يمضي', () => {
    it('لا عرضَ ولا خصم، و`redeemOffline` يقول السبب', async () => {
        setOnline(false);
        const { view } = await hook(() => ({ status: 200, body: {} }));
        act(() => {
            window.dispatchEvent(new Event('offline'));
        });

        expect(view.result.current.canRedeem).toBe(false);
        expect(view.result.current.redeemOffline).toBe(true);
        expect(view.result.current.redeemDiscount).toBe(0);
    });

    it('وانقطاعٌ بعد «استخدام النقاط» يُسقط الخصم — ولا يعود وحده مع الاتصال', async () => {
        const { view } = await hook(() => ({ status: 200, body: {} }));
        act(() => view.result.current.chooseRedeem(true));
        expect(view.result.current.redeemDiscount).toBe(10);

        setOnline(false);
        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        expect(view.result.current.redeemDiscount).toBe(0);

        setOnline(true);
        act(() => {
            window.dispatchEvent(new Event('online'));
        });
        expect(view.result.current.redeemDiscount).toBe(0);
        expect(view.result.current.redeemDecided).toBe(false);
    });

    it('وبيعةٌ بلا اتصال تُحفظ بلا نقاط', async () => {
        setOnline(false);
        const { view } = await hook(() => {
            throw new Error('offline');
        });
        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        act(() => view.result.current.chooseRedeem(true));
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        const queued = JSON.parse(localStorage.getItem('abadpos:pos:outbox') || '[]');
        expect(queued).toHaveLength(1);
        expect(queued[0].payload.redeem_points).toBe(0);
        expect(queued[0].payload.redeem_discount).toBeNull();
    });
});

describe('ما في الطابور بيعةٌ اكتملت', () => {
    it('يُرسل `replay` حين يُرفع من الطابور', async () => {
        localStorage.setItem(
            'abadpos:pos:outbox',
            JSON.stringify([{ uuid: 'q-1', at: Date.now(), payload: { client_uuid: 'q-1', items: [], redeem_points: 300, redeem_discount: 3 } }]),
        );
        const { sent } = await hook(() => ({ status: 200, body: { invoice: 'INV-9' } }));

        const replayed = sent.find((b) => b.client_uuid === 'q-1');
        expect(replayed?.replay).toBe(true);
        expect(replayed?.redeem_discount).toBe(3);
    });
});

describe('ورقةُ الدفع لا تقول «تمّ» عن بيعةٍ رُدّت', () => {
    const pay = async (result: Record<string, unknown>) => {
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: [] } });
        const { default: PaymentDialog } = await import('@/Pages/Pos/partials/PaymentDialog');
        const onOpenChange = vi.fn();
        const user = userEvent.setup();

        render(
            <PaymentDialog
                open
                onOpenChange={onOpenChange}
                total={90}
                displayTotal={90}
                customer="مريم"
                money={(v) => String(v)}
                fmt={(v) => String(v)}
                onCheckout={(async () => ({ synced: false, invoice: null, points: 0, ...result })) as never}
                onNewOrder={() => {}}
                methods={['نقدي']}
            />,
        );
        await user.click(screen.getByRole('button', { name: /نقدي/ }));
        await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));

        return onOpenChange;
    };

    it('عرضُ نقاطٍ تغيّر: تُغلق ورقةُ الدفع ويعود الكاشيرُ إلى السلّة', async () => {
        const onOpenChange = await pay({ rejected: true, loyaltyChanged: true });

        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(screen.queryByText('تم الدفع بنجاح')).toBeNull();
    });

    it('وردٌّ آخر يُبقيها على الدفع — لا «حُفظ البيع وسيُرسَل»', async () => {
        await pay({ rejected: true });

        expect(screen.queryByText('تم الدفع بنجاح')).toBeNull();
        expect(screen.queryByText(/حُفظ البيع وسيُرسَل/)).toBeNull();
        expect(screen.getByRole('button', { name: /تأكيد الدفع/ })).toBeInTheDocument();
    });
});

describe('شاشةُ الصندوق بلا اتصال', () => {
    it('تقول إنّ النقاط لا تُستبدل الآن — ولا زرَّ «استخدام النقاط»', async () => {
        setOnline(false);
        Object.assign(pageProps, {
            products: [],
            categories: [{ value: 'الكل', label: 'الكل' }],
            seasons: [],
            customers: [{ ...buyer, points: 500 }],
            addons: [],
            coupons: [],
            resumeCart: { id: null, customer: 'مريم', items: [{ id: 1, name: 'باقة ورد', price: 20, qty: 2 }] },
            settings,
            orderOptions: undefined,
            customOrder: { templates: [] },
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        expect(screen.getByTestId('redeem-offline')).toHaveTextContent('لا اتصال');
        expect(screen.queryByTestId('redeem-offer')).toBeNull();
        expect(screen.queryByRole('button', { name: 'استخدام النقاط' })).toBeNull();
    });
});

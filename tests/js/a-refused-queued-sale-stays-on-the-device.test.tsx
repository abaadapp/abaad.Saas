import { act, render, renderHook, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * ‏بيعةٌ في الطابور اكتملت عند الصندوق وقُبض ثمنُها — فلا يمحوها ردٌّ من الخادم.
 *
 * ═══ العطب ═══
 *
 * كان الطابورُ يحذف البيعةَ عند 419 و422 و409 برسالةٍ تختفي:
 *   - ‏419: انقطاعٌ أطولُ من عمر الجلسة (٨ ساعات في قالب الإنتاج) — فتُحذف
 *     **كلُّ** بيعات الانقطاع عند عودة الشبكة، واحدةً بعد أخرى.
 *   - ‏422: نفد المخزونُ أو انتهى الكوبونُ أو أُوقف العميلُ في أثناء الانقطاع.
 * نقدٌ في الدرج بلا فاتورة، ومخزونٌ لم يُخصم، ولا أثرَ يُراجَع.
 *
 * ═══ والحكم ═══
 *
 *   ١. ‏419/401: تبقى كلُّها بمعرّفاتها، ويقف الإرسالُ حتى الدخول — ثمّ تُرسل.
 *   ٢. ‏422/409: تبقى على الجهاز بسببها، ولا تُعاد وحدَها كلَّ نبضة (السببُ لا
 *      يزول بالانتظار)، وتُعاد باليد بمعرّفها نفسِه — فلا تُكتب مرّتين.
 *   ٣. ‏ما سواها (شبكة، 5xx): يبقى ويُعاد لاحقًا كما كان.
 *   ٤. ‏والطابورُ يُقرأ من جديد قبل كلّ كتابة: بيعةٌ تُتمّ أثناء الإرسال لا تُمحى.
 *   ٥. ‏والبيعةُ المتّصلة (الكاشيرُ أمام السلّة) تُردّ بـ422 كما كانت.
 */

const shelf = [{ id: 1, label: 'باقة ورد', price: 100, image: null, sku: null, barcode: null, stock: 50 }];
const KEY = 'abadpos:pos:outbox';

type Sent = Record<string, unknown>;
type Reply = { status: number; body?: unknown };

const setOnline = (on: boolean) => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => on });
};
const queued = () => JSON.parse(localStorage.getItem(KEY) || '[]') as { uuid: string; payload: Sent; held?: { status: number; error: string } }[];
const entry = (uuid: string, extra: Sent = {}) => ({ uuid, at: Date.now(), payload: { client_uuid: uuid, items: [{ id: 1, name: 'باقة ورد', qty: 1 }], expected_total: 100, ...extra } });
const refused = (message: string): Reply => ({ status: 422, body: { message, errors: { items: [message] } } });

/**
 * ‏الخادمُ الوهميّ: `sent` بيعاتُ `/pos/checkout` وحدها، و`asked` أسئلةُ السجلّ
 * (`/pos/outbox/status`) — ويجيب السجلُّ بما في `saved` (لا شيء افتراضًا).
 */
const mount = async (respond: (body: Sent, n: number) => Reply, saved: () => Record<string, { invoice: string; total: number }> = () => ({})) => {
    const sent: Sent[] = [];
    const asked: string[][] = [];
    const onToast = vi.fn();
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit) => {
            const body = JSON.parse(String(init.body)) as Sent;
            if (String(url).includes('/pos/outbox/status')) {
                asked.push(body.uuids as string[]);
                const known = saved();
                const hit = Object.fromEntries((body.uuids as string[]).filter((u) => known[u]).map((u) => [u, known[u]]));

                return { ok: true, status: 200, json: async () => ({ saved: hit }) };
            }
            sent.push(body);
            const r = respond(body, sent.length);

            return { ok: r.status < 300, status: r.status, json: async () => r.body ?? {} };
        }),
    );

    const { usePosCart } = await import('@/hooks/usePosCart');
    const view = renderHook(() =>
        usePosCart({
            products: shelf,
            customers: [],
            coupons: [],
            loyalty: { loyaltyEnabled: false },
            vat: { enabled: false, rate: 0, inclusive: false },
            resume: undefined,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            onToast,
        } as never),
    );

    return { view, sent, asked, onToast };
};

const reconnect = async () => {
    await act(async () => {
        window.dispatchEvent(new Event('online'));
    });
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

describe('الجلسةُ انتهت أثناء الانقطاع (419) — لا تُحذف بيعة', () => {
    it('تبقى البيعاتُ كلُّها بمعرّفاتها، ويقف الإرسالُ عند الأولى، ويُقال للكاشير أن يدخل', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1'), entry('q-2'), entry('q-3')]));
        const { view, sent, onToast } = await mount(() => ({ status: 419, body: { message: 'CSRF token mismatch.' } }));

        await waitFor(() => expect(view.result.current.sessionLost).toBe(true));
        // ‏لا تُطرق البقيّةُ بجلسةٍ ميتة — ولا تُحذف
        expect(sent).toHaveLength(1);
        expect(queued().map((e) => e.uuid)).toEqual(['q-1', 'q-2', 'q-3']);
        expect(queued().every((e) => !e.held)).toBe(true);
        expect(view.result.current.pendingCount).toBe(3);
        expect(onToast).toHaveBeenCalledWith(expect.stringContaining('انتهت جلسة الصندوق'), 'danger');

        // ‏ونبضةٌ ثانيةٌ بالجلسة نفسِها لا تكرّر التنبيه ولا تحذف
        await reconnect();
        await waitFor(() => expect(sent).toHaveLength(2));
        expect(onToast.mock.calls.filter((c) => String(c[0]).includes('انتهت جلسة الصندوق'))).toHaveLength(1);
        expect(queued()).toHaveLength(3);
    });

    it('وبعد الدخول تُرسل كلُّها بمعرّفاتها نفسِها، ويزول التنبيه', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1'), entry('q-2')]));
        let session = false;
        const { view, sent } = await mount((body) => (session ? { status: 200, body: { invoice: `INV-${body.client_uuid}` } } : { status: 419 }));
        await waitFor(() => expect(view.result.current.sessionLost).toBe(true));

        session = true;
        await reconnect();
        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(sent.slice(1).map((b) => b.client_uuid)).toEqual(['q-1', 'q-2']);
        expect(sent.slice(1).every((b) => b.replay === true)).toBe(true);
        expect(view.result.current.sessionLost).toBe(false);
    });

    it('و401 (لا دخول) مثلُها: تبقى ولا تُحذف', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1')]));
        const { view } = await mount(() => ({ status: 401, body: { message: 'Unauthenticated.' } }));
        await waitFor(() => expect(view.result.current.sessionLost).toBe(true));
        expect(queued().map((e) => e.uuid)).toEqual(['q-1']);
    });
});

describe('الخادمُ ردّها لسببٍ في بياناتها (422) — تبقى للمراجعة', () => {
    it('تُحفظ بسببها ولا تُحذف، وتمضي التاليةُ إلى الخادم', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1'), entry('q-2')]));
        const { view, sent, onToast } = await mount((body) =>
            body.client_uuid === 'q-1' ? refused('الكمية المطلوبة غير متوفرة') : { status: 200, body: { invoice: 'INV-2' } },
        );

        await waitFor(() => expect(sent).toHaveLength(2));
        await waitFor(() => expect(queued().map((e) => e.uuid)).toEqual(['q-1']));
        const [kept] = queued();
        expect(kept.held).toMatchObject({ status: 422, error: 'الكمية المطلوبة غير متوفرة' });
        // ‏والبيعةُ كما اكتملت: معرّفُها وحمولتُها لم تتغيّرا
        expect(kept.payload).toEqual(entry('q-1').payload);
        expect(view.result.current.heldSales.map((e) => e.uuid)).toEqual(['q-1']);
        expect(view.result.current.pendingCount).toBe(0);
        expect(onToast).toHaveBeenCalledWith(expect.stringContaining('بقي على الجهاز للمراجعة'), 'danger');
    });

    it('ولا تُعاد وحدَها كلَّ نبضة — السببُ لا يزول بالانتظار', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1')]));
        const { sent } = await mount(() => refused('انتهت مرات استخدام الكوبون'));
        await waitFor(() => expect(queued()[0]?.held).toBeTruthy());

        await reconnect();
        await reconnect();
        expect(sent).toHaveLength(1);
        expect(queued()).toHaveLength(1);
    });

    it('وتُعاد باليد بمعرّفها نفسِه — فإن كان الخادمُ كتبها ردّ فاتورتَها ولم يكتبها ثانيةً', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1')]));
        let fixed = false;
        const { view, sent } = await mount(() => (fixed ? { status: 200, body: { ok: true, invoice: 'INV-1', duplicate: true } } : refused('الكمية المطلوبة غير متوفرة')));
        await waitFor(() => expect(queued()[0]?.held).toBeTruthy());

        fixed = true;
        await act(async () => {
            await view.result.current.retryHeld('q-1');
        });

        expect(sent).toHaveLength(2);
        expect(sent[1].client_uuid).toBe('q-1');
        expect(sent[1].replay).toBe(true);
        expect(queued()).toHaveLength(0);
    });

    it('وإن ردّها الخادمُ ثانيةً تبقى بالسبب الجديد', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1')]));
        let reason = 'الكمية المطلوبة غير متوفرة';
        const { view } = await mount(() => refused(reason));
        await waitFor(() => expect(queued()[0]?.held).toBeTruthy());

        reason = 'هذا العميل موقوف';
        await act(async () => {
            await view.result.current.retryHeld('q-1');
        });
        expect(queued()[0].held?.error).toBe('هذا العميل موقوف');
    });

    it('و409 مثلُها: تبقى ولا تُحذف', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1')]));
        await mount(() => ({ status: 409, body: { message: 'تعارض' } }));
        await waitFor(() => expect(queued()[0]?.held?.status).toBe(409));
    });
});

describe('ما سوى ذلك يبقى ويُعاد لاحقًا كما كان', () => {
    it('خطأُ خادمٍ (500) لا يحذفها ولا يحجزها', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1'), entry('q-2')]));
        const { sent } = await mount(() => ({ status: 500 }));
        await waitFor(() => expect(sent).toHaveLength(1));
        expect(queued().map((e) => [e.uuid, !!e.held])).toEqual([
            ['q-1', false],
            ['q-2', false],
        ]);
    });
});

describe('بيعةٌ تُتمّ على الشاشة أثناء إرسال الطابور لا تُمحى', () => {
    it('الطابورُ يُقرأ من جديد قبل كلّ كتابة', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('q-1')]));
        const { sent } = await mount((body) => {
            // ‏والخادمُ يردّ على q-1 — والكاشيرُ قد أتمّ بيعةً كُتبت في الطابور قبل أن يصل الردّ
            if (body.client_uuid === 'q-1') localStorage.setItem(KEY, JSON.stringify([...queued(), entry('live-1')]));

            return { status: 200, body: { invoice: 'INV-1' } };
        });

        await waitFor(() => expect(sent).toHaveLength(1));
        await waitFor(() => expect(queued().map((e) => e.uuid)).toEqual(['live-1']));
    });
});

describe('البيعةُ المتّصلة والكاشيرُ أمامها', () => {
    it('انتهت الجلسة: تُحفظ في الطابور كبيعةٍ بلا اتّصال وتُرسل بعد الدخول بمعرّفها', async () => {
        let session = false;
        const { view, sent } = await mount(() => (session ? { status: 200, body: { invoice: 'INV-1' } } : { status: 419 }));
        act(() => view.result.current.add(shelf[0] as never));

        let result: Awaited<ReturnType<typeof view.result.current.checkoutSale>> | undefined;
        await act(async () => {
            result = await view.result.current.checkoutSale('نقدي');
        });
        expect(result?.rejected).toBe(false);
        expect(result?.synced).toBe(false);
        expect(queued().map((e) => e.uuid)).toEqual([sent[0].client_uuid]);
        expect(view.result.current.sessionLost).toBe(true);

        session = true;
        await reconnect();
        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(sent.filter((b) => b.client_uuid === sent[0].client_uuid)).toHaveLength(2);
    });

    it('ردّها الخادمُ (422): تُردّ أمامه كما كانت ولا تبقى في الطابور — السلّةُ على الشاشة', async () => {
        const { view } = await mount(() => refused('الكمية المطلوبة غير متوفرة'));
        act(() => view.result.current.add(shelf[0] as never));

        let result: Awaited<ReturnType<typeof view.result.current.checkoutSale>> | undefined;
        await act(async () => {
            result = await view.result.current.checkoutSale('نقدي');
        });
        expect(result?.rejected).toBe(true);
        expect(queued()).toHaveLength(0);
        expect(view.result.current.items).toHaveLength(1);
    });
});

describe('قائمةُ المراجعة على الشاشة', () => {
    it('تعرض البيعةَ وسببَها، وزرُّها يعيدها بمعرّفها', async () => {
        const { default: HeldSalesDialog } = await import('@/Pages/Pos/partials/HeldSalesDialog');
        const onRetry = vi.fn(async () => {});
        const sale = { ...entry('q-1', { customer: 'مريم' }), held: { status: 422, error: 'الكمية المطلوبة غير متوفرة', at: Date.now() } };

        render(<HeldSalesDialog open onOpenChange={() => {}} sales={[sale]} onRetry={onRetry} money={(v) => `${v} ر.ع`} />);

        expect(screen.getByText('الكمية المطلوبة غير متوفرة')).toBeTruthy();
        expect(screen.getByText('100 ر.ع')).toBeTruthy();
        expect(screen.getByText(/مريم/)).toBeTruthy();
        await userEvent.setup().click(screen.getByRole('button', { name: /أعد الإرسال/ }));
        expect(onRetry).toHaveBeenCalledWith('q-1');
    });
});

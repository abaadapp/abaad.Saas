import { act, render, renderHook, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { classify, due, entryDelay, fresh, keysOf, localNumber, MAX_AUTO_ATTEMPTS, pauseDelay, stateOf, type OutboxEntry } from '@/lib/outbox';
import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏بيعةُ الطابور لا تضيع، ولا تُكتب مرّتين، ولا توقف بيعةٌ فاشلةٌ ما خلفها.
 *
 * ═══ ما كان ═══
 *
 * خطأُ خادمٍ (500) على بيعةٍ واحدة يوقف الجولة (`break`) — فتبقى كلُّ بيعات
 * الطابور خلفها ما دام الخطأ. ولا يُسأل الخادمُ أكتبها قبل أن تُعاد أو يُحكم
 * بردّها. وكلُّ بيعةٍ تصل تُطلق رسالةً وإعادةَ تحميلٍ للأصناف (نحو ١MB):
 * مئةُ بيعةٍ بعد انقطاع = مئةُ تحميل.
 *
 * ═══ ما صار (`lib/outbox.ts` و`usePosCart.flushOutbox`) ═══
 *
 *   ١. ‏500 في بيعة: تُعاد بتأخيرٍ يتضاعف (٣٠ث… ١٥د)، وما يتعلّق بها (الصنفُ
 *      نفسُه، العميلُ، الكوبونُ، السلّةُ المعلّقة) ينتظر خلفها، والمستقلّةُ تمضي.
 *      وبعد ٦ محاولات تنتظر يدًا («يتكرر خطأ الخادم») ولا توقف أحدًا.
 *   ٢. ‏503/502/504/429 والشبكة: الخادمُ كلُّه غائب — تقف الجولة، وتطول المهلة.
 *   ٣. ‏ما فشل قبلُ يُسأل عنه السجلُّ (`/pos/outbox/status`) قبل أن يُعاد؛ وما ردّه
 *      الخادمُ (422/409) يُسأل عنه قبل أن يُحكم بردّه.
 *   ٤. ‏رسالةٌ واحدةٌ وإعادةُ تحميلٍ واحدة لكلّ جولة.
 *
 * ويقابله في PHP `AQueuedSaleIsLookedUpByItsKeyBeforeItIsSentAgainTest` و
 * `TheSameQueuedSaleSentTwiceAtOnceIsWrittenOnceTest`.
 */

const KEY = 'abadpos:pos:outbox';
type Sent = Record<string, unknown>;
type Reply = { status: number; body?: unknown } | 'network';

const setOnline = (on: boolean) => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => on });
};
const queued = () => JSON.parse(localStorage.getItem(KEY) || '[]') as OutboxEntry[];
const entry = (uuid: string, product = 1, extra: Sent = {}, more: Partial<OutboxEntry> = {}): OutboxEntry => ({
    uuid,
    at: Date.now(),
    payload: { client_uuid: uuid, items: [{ id: product, name: `صنف ${product}`, qty: 1, price: 10 }], expected_total: 10, payment_method: 'نقدي', ...extra },
    ...more,
});

/**
 * ‏خادمٌ وهميّ يتذكّر ما كتبه: ردٌّ ناجحٌ يكتب البيعة، والمعرّفُ نفسُه بعدها يُردّ
 * بفاتورته الأولى (كما يفعل `PosController::checkout`)، والسجلُّ يُسأل.
 */
const server = (reply: (body: Sent) => Reply = () => ({ status: 200 })) => {
    const written: Record<string, { invoice: string; total: number }> = {};
    const checkouts: Sent[] = [];
    const lookups: string[][] = [];
    const fetch = vi.fn(async (url: string, init: RequestInit) => {
        const body = JSON.parse(String(init.body)) as Sent;
        if (String(url).includes('/pos/outbox/status')) {
            const uuids = body.uuids as string[];
            lookups.push(uuids);

            return { ok: true, status: 200, json: async () => ({ saved: Object.fromEntries(uuids.filter((u) => written[u]).map((u) => [u, written[u]])) }) };
        }
        checkouts.push(body);
        const uuid = String(body.client_uuid);
        if (written[uuid]) return { ok: true, status: 200, json: async () => ({ ok: true, invoice: written[uuid].invoice, duplicate: true }) };
        const r = reply(body);
        if (r === 'network') throw new TypeError('Failed to fetch');
        if (r.status === 200) written[uuid] = { invoice: `INV-${Object.keys(written).length + 1}`, total: Number(body.expected_total ?? 0) };

        return { ok: r.status < 300, status: r.status, json: async () => r.body ?? (r.status === 200 ? { ok: true, invoice: written[uuid].invoice, total: written[uuid].total } : {}) };
    });

    return { written, checkouts, lookups, fetch, sentFor: (uuid: string) => checkouts.filter((b) => b.client_uuid === uuid).length };
};

const mount = async (srv: ReturnType<typeof server>) => {
    const onToast = vi.fn();
    const onSynced = vi.fn();
    vi.stubGlobal('fetch', srv.fetch);
    const { usePosCart } = await import('@/hooks/usePosCart');
    const view = renderHook(() =>
        usePosCart({
            products: [{ id: 1, label: 'صنف 1', price: 10, image: null, sku: null, barcode: null, stock: 50 }],
            customers: [],
            coupons: [],
            loyalty: { loyaltyEnabled: false },
            vat: { enabled: false, rate: 0, inclusive: false },
            resume: undefined,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            // ‏كما تمرّرها الشاشة: دوالُّ جديدةٌ في كلّ رسم
            onToast: (...a: unknown[]) => onToast(...a),
            onSynced: () => onSynced(),
        } as never),
    );

    return { view, onToast, onSynced };
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
    vi.useRealTimers();
    vi.unstubAllGlobals();
    setOnline(true);
});

/* ═══════════════════════════ القواعد ═══════════════════════════ */

describe('ما يعنيه كلُّ ردّ', () => {
    it('يُفرَّق بين الجلسة والصلاحية والردّ والتعارض وخطأ البيعة وغياب الخادم', () => {
        expect([200, 201].map(classify)).toEqual(['ok', 'ok']);
        expect([401, 419].map(classify)).toEqual(['session', 'session']);
        expect(classify(403)).toBe('forbidden');
        expect(classify(422)).toBe('refused');
        expect(classify(409)).toBe('conflict');
        expect([500, 400, 413].map(classify)).toEqual(['server', 'server', 'server']);
        expect([503, 502, 504, 429, 404, 405].map(classify)).toEqual(Array(6).fill('unavailable'));
        expect(classify(null)).toBe('network');
    });

    it('التأخيرُ يتضاعف ولا يتجاوز سقفه', () => {
        expect([1, 2, 3, 4, 5, 6, 9].map(entryDelay)).toEqual([30_000, 60_000, 120_000, 240_000, 480_000, 900_000, 900_000]);
        expect([1, 2, 3, 6, 9].map(pauseDelay)).toEqual([8_000, 16_000, 32_000, 256_000, 300_000]);
        expect(MAX_AUTO_ATTEMPTS).toBe(6);
    });

    it('ما تتعلّق به البيعة: صنفُها وإضافتُها وعميلُها وكوبونُها وسلّتُها', () => {
        const e = entry('k', 7, { customer_id: 3, coupon_code: 'eid', resume_id: 9, items: [{ id: 7, qty: 1, addons: [{ addon_id: 4 }] }, { id: null, addon_id: 5, qty: 1 }] });
        expect([...keysOf(e)].sort()).toEqual(['a:4', 'a:5', 'c:3', 'k:EID', 'p:7', 'r:9']);
    });

    it('المردودةُ والعالقةُ لا توقف أحدًا، والتي تنتظر موعدَها توقف ما يتعلّق بها وحده', () => {
        const now = Date.now();
        const list = [
            entry('held', 1, {}, { held: { status: 422, error: 'x', at: now } }),
            entry('stuck', 1, {}, { stuck: true }),
            entry('after-held-same-product', 1),
            entry('waiting', 2, {}, { attempts: 1, nextAt: now + 60_000 }),
            entry('behind-waiting', 2),
            entry('behind-behind', 3, { customer_id: 2 }, {}),
            entry('independent', 4),
        ];
        // ‏«behind-behind» لا يشارك «waiting»؛ لكنّه يشارك ما خلفه؟ لا — فيمضي
        expect(due(list, now).map((e) => e.uuid)).toEqual(['after-held-same-product', 'behind-behind', 'independent']);
        // ‏وسلسلةٌ: الثالثةُ تشارك الثانيةَ الواقفةَ خلف الأولى — فتقف معهما حفظًا للترتيب
        const chain = [entry('a', 1, {}, { attempts: 1, nextAt: now + 1 }), entry('b', 1, { customer_id: 8 }), entry('c', 5, { customer_id: 8 })];
        expect(due(chain, now).map((e) => e.uuid)).toEqual([]);
        expect(due(chain, now + 2).map((e) => e.uuid)).toEqual(['a', 'b', 'c']);
    });

    it('حالُ البيعة كما يقرؤها الكاشير، ورقمُها المحلّيّ، وإعادتُها تُبقي متجرَها ونتيجةَ آخر محاولة', () => {
        const sending = new Set(['s']);
        expect(stateOf(entry('s'), { sending, sessionLost: false })).toBe('sending');
        expect(stateOf(entry('h', 1, {}, { held: { status: 422, error: '', at: 0 } }), { sending, sessionLost: true })).toBe('held');
        expect(stateOf(entry('t', 1, {}, { stuck: true }), { sending, sessionLost: false })).toBe('stuck');
        expect(stateOf(entry('l'), { sending, sessionLost: true })).toBe('signin');
        expect(stateOf(entry('r', 1, {}, { attempts: 2 }), { sending, sessionLost: false })).toBe('retrying');
        expect(stateOf(entry('p'), { sending, sessionLost: false })).toBe('pending');
        expect(localNumber('3f2a9c1e-77b0-4d1e-9a51-0c2b8e1f4a77')).toBe('3F2A9C1E');
        const last = { status: 500, error: 'x', at: 1 };
        expect(fresh(entry('f', 1, {}, { business: 4, attempts: 6, stuck: true, nextAt: 9, last, held: { status: 422, error: '', at: 0 } }))).toEqual({ ...entry('f'), at: expect.any(Number), business: 4, last });
    });
});

/* ═══════════════════════════ الطابور ═══════════════════════════ */

describe('خطأُ خادمٍ في بيعةٍ واحدة (500) لا يوقف ما خلفها', () => {
    it('تُعاد بموعدٍ، وتمضي المستقلّة، وتنتظر خلفها ما يتعلّق بها', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1), entry('b', 2), entry('c', 1)]));
        const srv = server((b) => (b.client_uuid === 'a' ? { status: 500 } : { status: 200 }));
        await mount(srv);

        await waitFor(() => expect(srv.written.b).toBeTruthy());
        const [a, c] = queued();
        expect(queued().map((e) => e.uuid)).toEqual(['a', 'c']);
        expect(a.attempts).toBe(1);
        expect(a.last?.status).toBe(500);
        expect((a.nextAt ?? 0) - Date.now()).toBeGreaterThan(25_000);
        expect(srv.sentFor('c')).toBe(0);
        expect(c.attempts).toBeUndefined();
    });

    it('وبعد الحدّ تنتظر يدًا («عالقة») بعد أن يُسأل السجلّ — ولا توقف ما يتعلّق بها', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1, {}, { attempts: MAX_AUTO_ATTEMPTS - 1, nextAt: Date.now() - 1, last: { status: 500, error: '', at: 0 } }), entry('c', 1)]));
        const srv = server((b) => (b.client_uuid === 'a' ? { status: 500 } : { status: 200 }));
        const { view, onToast } = await mount(srv);

        await waitFor(() => expect(queued()[0]?.stuck).toBe(true));
        expect(srv.lookups.flat()).toContain('a');
        expect(onToast).toHaveBeenCalledWith(expect.stringContaining('يتكرّر عليه خطأ الخادم'), 'danger');
        await waitFor(() => expect(srv.written.c).toBeTruthy());
        expect(queued().map((e) => e.uuid)).toEqual(['a']);
        expect(view.result.current.reviewCount).toBe(1);

        // ‏ولا تُعاد وحدَها بعد ذلك
        await reconnect();
        await reconnect();
        expect(srv.sentFor('a')).toBe(1);
    });

    it('كتبها الخادمُ ثمّ سقط قبل أن يردّ: يقول السجلُّ ذلك فتُحذف بفاتورتها ولا تُرسل ثانيةً', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1, {}, { attempts: 1, nextAt: Date.now() - 1, last: { status: 500, error: '', at: 0 } })]));
        const srv = server();
        srv.written.a = { invoice: 'INV-9', total: 10 };
        const { view } = await mount(srv);

        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(srv.sentFor('a')).toBe(0);
        expect(view.result.current.synced[0]).toMatchObject({ uuid: 'a', invoice: 'INV-9', total: 10, expected: 10 });
    });
});

describe('الخادمُ كلُّه غائب (503) أو لا شبكة — تقف الجولة ويطول الانتظار', () => {
    it('لا تُطرق البقيّة، ولا تُعدّ على البيعة، والانتظارُ يتضاعف', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1), entry('b', 2)]));
        let up = false;
        const srv = server(() => (up ? { status: 200 } : { status: 503 }));
        await mount(srv);
        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(srv.checkouts).toHaveLength(1);
        expect(queued()[0].attempts).toBeUndefined();
        expect(queued()[0].last?.status).toBe(503);

        // ‏النبضةُ بعد ٨ ثوانٍ تطرق مرّة، ثمّ ينتظر ١٦
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8_000);
        });
        expect(srv.checkouts).toHaveLength(2);
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8_000);
        });
        expect(srv.checkouts).toHaveLength(2);

        up = true;
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8_000);
        });
        expect(Object.keys(srv.written).sort()).toEqual(['a', 'b']);
        expect(queued()).toHaveLength(0);
    });

    it('ولا شبكةٌ في الطريق: تبقى كما هي، ونتيجتُها «لا اتصال»', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1), entry('b', 2)]));
        const srv = server(() => 'network');
        await mount(srv);

        await waitFor(() => expect(queued()[0].last?.status).toBeNull());
        expect(srv.checkouts).toHaveLength(1);
        expect(queued().map((e) => [e.uuid, e.attempts ?? 0, !!e.held, !!e.stuck])).toEqual([
            ['a', 0, false, false],
            ['b', 0, false, false],
        ]);
    });
});

describe('ما ردّه الخادمُ يُسأل عنه سجلُّه قبل أن يُحكم', () => {
    it('‏409 وهي مكتوبة: تُحذف بفاتورتها — لا تُحجز ولا تُكرَّر', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1)]));
        const srv = server(() => ({ status: 409, body: { message: 'تعارض' } }));
        srv.fetch.mockImplementationOnce(async (_url: string, init: RequestInit) => {
            // ‏الطلبُ الأوّل كُتب ثمّ ردّ الوسيطُ بتعارض
            srv.checkouts.push(JSON.parse(String(init.body)));
            srv.written.a = { invoice: 'INV-1', total: 10 };

            return { ok: false, status: 409, json: async () => ({ message: 'تعارض' }) };
        });
        const { view } = await mount(srv);

        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(view.result.current.synced[0]?.invoice).toBe('INV-1');
        expect(srv.lookups).toEqual([['a']]);
    });

    it('‏409 وليست مكتوبة: تبقى «مرفوضة» بسببها', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1)]));
        const srv = server(() => ({ status: 409, body: { message: 'تعارض' } }));
        await mount(srv);
        await waitFor(() => expect(queued()[0]?.held?.status).toBe(409));
    });

    it('‏422: يُسأل السجلُّ مرّةً ثمّ تبقى بالحقل الذي ردّه الخادم', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1)]));
        const srv = server(() => ({ status: 422, body: { message: 'x', errors: { 'items.0.qty': ['المتوفر 0'] } } }));
        await mount(srv);

        await waitFor(() => expect(queued()[0]?.held).toBeTruthy());
        expect(queued()[0].held).toMatchObject({ status: 422, error: 'المتوفر 0', fields: ['items.0.qty'] });
        expect(srv.lookups).toEqual([['a']]);
    });

    it('‏403: تبقى، ويقف الإرسال، ويُقال إنّ مستخدمًا مخوّلًا يلزم', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1), entry('b', 2)]));
        const srv = server(() => ({ status: 403 }));
        const { view, onToast } = await mount(srv);

        await waitFor(() => expect(view.result.current.forbidden).toBe(true));
        expect(srv.checkouts).toHaveLength(1);
        expect(queued()).toHaveLength(2);
        expect(onToast).toHaveBeenCalledWith(expect.stringContaining('لا يملك البيع'), 'danger');
    });
});

describe('مئةُ بيعةٍ بعد انقطاع', () => {
    it('تُرسل كلُّها مرّةً مرّة، برسالةٍ واحدةٍ وإعادةِ تحميلٍ واحدة', async () => {
        localStorage.setItem(KEY, JSON.stringify(Array.from({ length: 100 }, (_, i) => entry(`s-${i}`, 100 + i))));
        const srv = server();
        const { onToast, onSynced } = await mount(srv);

        await waitFor(() => expect(queued()).toHaveLength(0), { timeout: 10_000 });
        expect(Object.keys(srv.written)).toHaveLength(100);
        expect(srv.checkouts).toHaveLength(100);
        expect(onSynced).toHaveBeenCalledTimes(1);
        expect(onToast.mock.calls.filter((c) => String(c[0]).includes('تمت مزامنة'))).toEqual([['تمت مزامنة 100 طلبات معلّقة ✓', 'success']]);
    });

    it('وبيعةٌ مردودةٌ بينها لا توقف ما بعدها', async () => {
        localStorage.setItem(KEY, JSON.stringify(Array.from({ length: 10 }, (_, i) => entry(`s-${i}`, 100 + i))));
        const srv = server((b) => (b.client_uuid === 's-3' ? { status: 422, body: { message: 'x', errors: { coupon_code: ['انتهى'] } } } : { status: 200 }));
        await mount(srv);

        await waitFor(() => expect(Object.keys(srv.written)).toHaveLength(9));
        expect(queued().map((e) => [e.uuid, e.held?.fields])).toEqual([['s-3', ['coupon_code']]]);
    });
});

describe('ولا تُرسل البيعةُ مرّتين', () => {
    it('ضغطتان على «أعد الإرسال» = طلبٌ واحد', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1, {}, { held: { status: 422, error: 'x', at: 0 } })]));
        const srv = server();
        const { view } = await mount(srv);

        await act(async () => {
            await Promise.all([view.result.current.retryOne('a'), view.result.current.retryOne('a')]);
        });
        expect(srv.sentFor('a')).toBe(1);
        expect(queued()).toHaveLength(0);
    });

    it('جولتان معًا (عودةُ الشبكة مرّتين) = كلُّ بيعةٍ مرّةً', async () => {
        localStorage.setItem(KEY, JSON.stringify([entry('a', 1), entry('b', 2), entry('c', 3)]));
        const srv = server();
        await mount(srv);
        await act(async () => {
            window.dispatchEvent(new Event('online'));
            window.dispatchEvent(new Event('online'));
        });

        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(['a', 'b', 'c'].map(srv.sentFor)).toEqual([1, 1, 1]);
    });

    it('أُغلق المتصفّحُ أثناء الإرسال: يُكمل حين يُفتح، ولا يُكتب شيءٌ مرّتين ولا يضيع شيء', async () => {
        localStorage.setItem(KEY, JSON.stringify(Array.from({ length: 6 }, (_, i) => entry(`s-${i}`, 100 + i))));
        let release: () => void = () => {};
        const gate = new Promise<void>((r) => (release = r));
        const srv = server();
        const real = srv.fetch.getMockImplementation()!;
        srv.fetch.mockImplementation(async (url: string, init: RequestInit) => {
            const body = JSON.parse(String(init.body)) as Sent;
            // ‏الثالثةُ تصل الخادمَ وتُكتب، والصفحةُ تُغلق قبل أن يصل ردُّها
            if (body.client_uuid === 's-2') {
                await real(url, init);
                await gate;
                throw new TypeError('the page was closed');
            }

            return real(url, init);
        });
        const first = await mount(srv);
        await waitFor(() => expect(srv.written['s-2']).toBeTruthy());
        first.view.unmount();
        release();

        srv.fetch.mockImplementation(real);
        await mount(srv);
        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(Object.keys(srv.written).sort()).toEqual(['s-0', 's-1', 's-2', 's-3', 's-4', 's-5']);
        expect(srv.checkouts.filter((b) => b.client_uuid === 's-2').length).toBeLessThanOrEqual(2);
    });

    it('وطابورٌ كُتب قبل هذا (بلا حقول المحاولات) يُرسل كما هو', async () => {
        localStorage.setItem(KEY, JSON.stringify([{ uuid: 'old-1', at: 1, payload: { client_uuid: 'old-1', items: [{ id: 1, name: 'x', qty: 1 }] } }]));
        const srv = server();
        await mount(srv);
        await waitFor(() => expect(queued()).toHaveLength(0));
        expect(srv.written['old-1']).toBeTruthy();
    });
});

/* ═══════════════════════════ الشاشة ═══════════════════════════ */

describe('قائمةُ البيعات المعلّقة', () => {
    const money = (v: number) => `${v.toFixed(3)} ر.ع`;

    it('حالُ كلِّ بيعة، وما يحتاج مراجعةً أوّلًا، وما وصل بفاتورته — ومبلغٌ كتبه الخادمُ غيرَ ما قُبض يُقال', async () => {
        const { default: HeldSalesDialog } = await import('@/Pages/Pos/partials/HeldSalesDialog');
        const sales = [
            entry('p-1', 1),
            entry('r-1', 2, {}, { attempts: 2, nextAt: Date.now() + 60_000, last: { status: 500, error: 'خطأ في الخادم (500)', at: Date.now() } }),
            entry('h-1', 3, {}, { held: { status: 422, error: 'المتوفر 0 والمطلوب 1', at: Date.now(), fields: ['items'] } }),
            entry('t-1', 4, {}, { stuck: true, attempts: 6, last: { status: 500, error: 'خطأ في الخادم (500)', at: Date.now() } }),
            entry('s-1', 5),
        ];
        render(
            <HeldSalesDialog
                open
                onOpenChange={() => {}}
                sales={sales}
                onRetry={async () => {}}
                money={money}
                sending={new Set(['s-1'])}
                synced={[{ uuid: 'z-1', invoice: 'INV-77', total: 12, expected: 10, at: Date.now() }]}
            />,
        );

        const rows = screen.getAllByTestId('held-sale');
        expect(rows.map((r) => r.getAttribute('data-state'))).toEqual(['held', 'stuck', 'pending', 'retrying', 'sending']);
        expect(screen.getAllByTestId('sale-state').map((b) => b.textContent)).toEqual(['مرفوضة', 'يتكرر خطأ الخادم', 'معلّقة', 'معلّقة — تُعاد آليًّا', 'قيد الإرسال']);
        expect(screen.getByText('المتوفر 0 والمطلوب 1')).toBeTruthy();
        // ‏«أعد الإرسال» لما يحتاج يدًا وحده
        expect(screen.getAllByRole('button', { name: /أعد الإرسال/ })).toHaveLength(2);
        expect(screen.getByTestId('synced-sale').textContent).toContain('INV-77');
        expect(screen.getByTestId('synced-differs').textContent).toContain('12.000');
    });

    it('«مراجعة العملية» تعرض الأصناف وكمّيّاتها والمعرّف وما يُفعل', async () => {
        const { default: HeldSalesDialog } = await import('@/Pages/Pos/partials/HeldSalesDialog');
        const sale = entry('h-1', 3, { items: [{ id: 3, name: 'باقة ورد', qty: 2, price: 5 }] }, { held: { status: 422, error: 'x', at: Date.now(), fields: ['items.0.qty'] } });
        render(<HeldSalesDialog open onOpenChange={() => {}} sales={[sale]} onRetry={async () => {}} money={money} />);

        await userEvent.setup().click(screen.getByRole('button', { name: /مراجعة العملية/ }));
        const review = screen.getByTestId('sale-review');
        expect(review.textContent).toContain('باقة ورد');
        expect(review.textContent).toContain('×2');
        expect(review.textContent).toContain('h-1');
        expect(review.textContent).toContain('أضف الكمية الناقصة');
    });

    it('لا تُعاد والجلسةُ لا تملك البيع، وضغطتان أثناء الإرسال = طلبٌ واحد', async () => {
        const { default: HeldSalesDialog } = await import('@/Pages/Pos/partials/HeldSalesDialog');
        const sale = entry('h-1', 3, {}, { held: { status: 422, error: 'x', at: Date.now() } });
        let finish: () => void = () => {};
        const onRetry = vi.fn(() => new Promise<void>((r) => (finish = r)));
        const view = render(<HeldSalesDialog open onOpenChange={() => {}} sales={[sale]} onRetry={onRetry} money={money} canRetry={false} />);
        expect((screen.getByRole('button', { name: /أعد الإرسال/ }) as HTMLButtonElement).disabled).toBe(true);

        view.rerender(<HeldSalesDialog open onOpenChange={() => {}} sales={[sale]} onRetry={onRetry} money={money} canRetry />);
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /أعد الإرسال/ }));
        await user.click(screen.getByRole('button', { name: /أعد الإرسال/ }));
        expect(onRetry).toHaveBeenCalledTimes(1);
        await act(async () => finish());
    });

    it('التنبيهُ في الشاشة: عددُ ما يحتاج مراجعة، وزرٌّ يفتح القائمة — والبيعُ تحته', async () => {
        localStorage.setItem(KEY, JSON.stringify([
            entry('h-1', 1, {}, { held: { status: 422, error: 'x', at: Date.now() } }),
            entry('t-1', 2, {}, { stuck: true }),
        ]));
        vi.stubGlobal('fetch', server().fetch);
        Object.assign(pageProps, {
            products: [],
            categories: [{ value: 'الكل', label: 'الكل' }],
            seasons: [],
            customers: [],
            addons: [],
            coupons: [],
            settings: {},
            customOrder: { templates: [] },
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        const notice = await screen.findByTestId('review-notice');
        expect(notice.textContent).toContain('توجد (2) عمليات بيع معلقة تحتاج إلى مراجعة.');
        // ‏في مكانه من الصفحة — لا طافيًا فوق أزرار البيع
        expect(notice.className).not.toMatch(/\bfixed\b/);
        await userEvent.setup().click(screen.getByRole('button', { name: 'مراجعة العمليات' }));
        expect(await screen.findByTestId('held-sales')).toBeTruthy();
        expect(screen.getAllByTestId('held-sale')).toHaveLength(2);
    });
});

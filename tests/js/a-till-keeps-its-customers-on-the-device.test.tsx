import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flatten, forgetOtherShops, merge, resetForgetOtherShops, syncCustomers, toPosCustomer, type CachedBucket, type SyncResponse } from '@/lib/till-customers';

/**
 * زبائنُ الصندوق في الجهاز — يُزامَنون بما تغيّر، ولا يمحو جوابٌ متأخّرٌ ما جرى بعده.
 *
 * ويقابله في الخادم `ATillKeepsItsCustomersAndAsksOnlyWhatChangedTest`، وفي
 * متصفّحٍ حقيقيّ (IndexedDB والانقطاع) فحصُ Playwright في تقرير المرحلة الثانية.
 */

const SHOP = 41;
const currency = { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true };
const loyalty = { loyaltyEnabled: true, redeemEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100, redeemPoints: 100, redeemValue: 1, redeemMinInvoice: 0 };

const setOnline = (on: boolean) => Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => on });
const bucket = (b: number, hash: string, rows: CachedBucket['rows']): CachedBucket => ({ scope: SHOP, bucket: b, hash, rows });
const answer = (over: Partial<SyncResponse> = {}): SyncResponse => ({ business: SHOP, buckets: {}, rows: {}, count: 0, ...over });
const jsonRes = (status: number, body: unknown) => ({ ok: status < 300, status, json: async () => body }) as Response;

describe('the row the server sends', () => {
    it('becomes what the till reads, the missing read as empty', () => {
        expect(toPosCustomer({ id: 3, name: 'مريم' })).toEqual({ id: 3, name: 'مريم', name_en: null, label: 'مريم', phone: '', points: 0, language: null });

        const ctx = { alert: { type: 'block' as const, reason: 'دين' }, birthday_in: null, note: null };
        expect(toPosCustomer({ id: 4, name: 'Ali', name_en: 'Ali', phone: '9123', points: 9, language: 'en', context: ctx })).toMatchObject({
            label: 'Ali', phone: '9123', points: 9, language: 'en', context: ctx,
        });
    });
});

describe('merging the answer into the device copy', () => {
    it('keeps a known bucket, replaces a changed one, drops one that emptied', () => {
        const cached = new Map([
            [1, bucket(1, 'h1', [{ id: 1, name: 'أ' }])],
            [2, bucket(2, 'old', [{ id: 2, name: 'ب' }])],
            [3, bucket(3, 'h3', [{ id: 3, name: 'ج' }])],
        ]);

        const { next, put, drop, complete } = merge(SHOP, cached, answer({
            buckets: { 1: 'h1', 2: 'new', 9: 'h9' },
            rows: { 2: [{ id: 2, name: 'ب٢', points: 5 }], 9: [{ id: 265, name: 'جديد' }, { id: 9, name: 'د' }] },
        }));

        expect(complete).toBe(true);
        expect([...next.keys()].sort()).toEqual([1, 2, 9]);
        expect(next.get(1)).toBe(cached.get(1));
        expect(put.map((p) => p.bucket)).toEqual([2, 9]);
        expect(drop).toEqual([3]); // ‏لم يبقَ فيه أحد — يُمحى من الجهاز
        expect(flatten(next).map((r) => r.id)).toEqual([1, 2, 9, 265]); // ‏بترتيب الخادم
    });

    it('never pretends to hold a bucket the answer did not send', () => {
        const { complete, next } = merge(SHOP, new Map([[1, bucket(1, 'a', [])]]), answer({ buckets: { 1: 'b' } }));

        expect(complete).toBe(false);
        expect(next.has(1)).toBe(false);
    });
});

describe('asking the server', () => {
    it('sends what the device holds and refuses an answer for another shop', async () => {
        const calls: RequestInit[] = [];
        const fetcher = vi.fn(async (_u: RequestInfo | URL, init?: RequestInit) => {
            calls.push(init!);
            return jsonRes(200, answer({ business: SHOP + 1 }));
        });

        await expect(syncCustomers(SHOP, new Map([[5, bucket(5, 'h5', [])]]), fetcher as never)).rejects.toThrow();
        expect(JSON.parse(String(calls[0].body))).toEqual({ have: { 5: 'h5' } });
    });

    it('throws when there is no answer so the till keeps its copy', async () => {
        await expect(syncCustomers(SHOP, null, (async () => { throw new TypeError('offline'); }) as never)).rejects.toThrow();
        await expect(syncCustomers(SHOP, null, (async () => jsonRes(500, {})) as never)).rejects.toThrow();
    });
});

describe('another shop on the same device', () => {
    beforeEach(() => resetForgetOtherShops());

    it('keeps only the shop of whoever sits, and the login screen keeps everything', () => {
        const keep = vi.fn(async () => {});
        forgetOtherShops({ auth: null }, keep);
        expect(keep).not.toHaveBeenCalled();

        forgetOtherShops({ auth: { user: { businessId: 7 } } }, keep);
        forgetOtherShops({ auth: { user: { businessId: 7 } } }, keep);
        forgetOtherShops({ auth: { user: { businessId: 8 } } }, keep);
        forgetOtherShops({ auth: { user: { businessId: null } } }, keep);

        expect(keep.mock.calls).toEqual([[7], [8], [null]]);
    });
});

/* ═══════════ في الصندوق نفسِه ═══════════ */

type Call = { url: string; init?: RequestInit };

const till = async (respond: (url: string, init?: RequestInit) => Promise<Response> | Response) => {
    const calls: Call[] = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
        calls.push({ url, init });
        return respond(url, init);
    }));
    const toasts: string[] = [];
    const { usePosCart } = await import('@/hooks/usePosCart');
    const view = renderHook(() =>
        usePosCart({
            products: [{ id: 1, label: 'باقة', price: 10, image: null, sku: '', barcode: '', stock: 9 }],
            customersScope: SHOP,
            coupons: [],
            loyalty,
            vat: { enabled: false, rate: 0, inclusive: false },
            resume: null,
            currency,
            onToast: (m: string) => toasts.push(m),
        } as never),
    );

    return { view, calls, toasts };
};

describe('the till with its customers on the device', () => {
    beforeEach(() => {
        localStorage.clear();
        setOnline(true);
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('loads the list from the sync and searches it', async () => {
        const { view, calls } = await till(() => jsonRes(200, answer({
            buckets: { 3: 'h' }, rows: { 3: [{ id: 3, name: 'مريم', phone: '96899110001', points: 120 }] }, count: 1,
        })));

        await waitFor(() => expect(view.result.current.customersStatus).toBe('ready'));
        expect(calls[0].url).toBe('/pos/till-customers/sync');
        act(() => view.result.current.setCustomerSearch('9911'));
        expect(view.result.current.filteredCustomers.map((c) => c.label)).toEqual(['مريم']);
    });

    it('sells cash when the list never arrived, and fetches it when the line is back', async () => {
        let up = false;
        const { view } = await till(async () => {
            if (!up) throw new TypeError('offline');
            return jsonRes(200, answer({ buckets: { 3: 'h' }, rows: { 3: [{ id: 3, name: 'مريم' }] }, count: 1 }));
        });

        await waitFor(() => expect(view.result.current.customersStatus).toBe('offline'));
        expect(view.result.current.customers).toEqual([]);
        expect(view.result.current.isWalkIn).toBe(true);

        up = true;
        await act(async () => {
            window.dispatchEvent(new Event('online'));
        });
        await waitFor(() => expect(view.result.current.customersStatus).toBe('ready'));
        expect(view.result.current.customers.map((c) => c.id)).toEqual([3]);
    });

    it('does not let a late answer erase what happened at the till after it was asked', async () => {
        let release: (r: Response) => void = () => {};
        const { view, calls } = await till((url) =>
            url.endsWith('/sync') ? new Promise<Response>((r) => { release = r; }) : jsonRes(200, {}),
        );
        await waitFor(() => expect(calls.some((c) => c.url.endsWith('/sync'))).toBe(true));

        // ‏اللغةُ اختيرت بعد أن سُئل الخادم وقبل أن يصل جوابُه — والجوابُ لا يعرفها
        act(() => view.result.current.setCustomerLanguage(3, 'en'));
        await act(async () => {
            release(jsonRes(200, answer({ buckets: { 3: 'h' }, rows: { 3: [{ id: 3, name: 'مريم' }] }, count: 1 })));
        });

        await waitFor(() => expect(view.result.current.customersStatus).toBe('ready'));
        expect(view.result.current.customers[0].language).toBe('en');
    });

    it('asks the chosen customer as he is now, and a deleted one sends the sale back to cash', async () => {
        const { view, calls, toasts } = await till((url) => {
            if (url.endsWith('/sync')) {
                return jsonRes(200, answer({ buckets: { 3: 'h', 4: 'h4' }, rows: { 3: [{ id: 3, name: 'مريم', points: 100 }], 4: [{ id: 4, name: 'سالم' }] }, count: 2 }));
            }
            if (url.endsWith('/3')) return jsonRes(200, { customer: { id: 3, name: 'مريم', points: 250, context: { alert: { type: 'warning', reason: 'جديد' }, birthday_in: null, note: null } } });
            return jsonRes(404, { message: 'gone' });
        });
        await waitFor(() => expect(view.result.current.customersStatus).toBe('ready'));

        act(() => view.result.current.selectCustomer('مريم', 3));
        await waitFor(() => expect(view.result.current.selectedCustomer?.points).toBe(250));
        expect(view.result.current.selectedCustomer?.context?.alert?.reason).toBe('جديد');
        expect(calls.some((c) => c.url === '/pos/till-customers/3')).toBe(true);

        act(() => view.result.current.selectCustomer('سالم', 4));
        await waitFor(() => expect(view.result.current.isWalkIn).toBe(true));
        expect(view.result.current.customers.map((c) => c.id)).toEqual([3]);
        expect(toasts.join(' ')).toContain('لم يعد موجودًا');
    });

    it('does not ask the server about the chosen customer while offline', async () => {
        const { view, calls } = await till((url) =>
            url.endsWith('/sync') ? jsonRes(200, answer({ buckets: { 3: 'h' }, rows: { 3: [{ id: 3, name: 'مريم', points: 100 }] }, count: 1 })) : jsonRes(200, {}),
        );
        await waitFor(() => expect(view.result.current.customersStatus).toBe('ready'));

        setOnline(false);
        act(() => view.result.current.selectCustomer('مريم', 3));

        expect(view.result.current.selectedCustomer?.points).toBe(100);
        expect(calls.filter((c) => c.url.startsWith('/pos/till-customers/3'))).toHaveLength(0);
    });
});

describe('the queue of pending sales on a device shared by two shops', () => {
    beforeEach(() => {
        localStorage.clear();
        setOnline(true);
    });
    afterEach(() => vi.unstubAllGlobals());

    it('sends this shop and the old untagged sales, keeps the other shop untouched', async () => {
        localStorage.setItem('abadpos:pos:outbox', JSON.stringify([
            { uuid: 'mine', payload: { items: [] }, at: 1, business: SHOP },
            { uuid: 'legacy', payload: { items: [] }, at: 2 },
            { uuid: 'theirs', payload: { items: [] }, at: 3, business: SHOP + 1 },
        ]));

        const sent: string[] = [];
        const { view } = await till((url, init) => {
            if (url === '/pos/checkout') {
                sent.push(String(init?.body));
                return jsonRes(200, { invoice: 'INV-1' });
            }
            return jsonRes(200, answer());
        });

        await waitFor(() => expect(sent).toHaveLength(2));
        const left = JSON.parse(localStorage.getItem('abadpos:pos:outbox') || '[]');
        expect(left).toEqual([{ uuid: 'theirs', payload: { items: [] }, at: 3, business: SHOP + 1 }]);
        expect(view.result.current.pendingCount).toBe(0);
        expect(view.result.current.foreignPendingCount).toBe(1);
    });
});

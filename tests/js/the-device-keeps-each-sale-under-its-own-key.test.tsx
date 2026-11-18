import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { reviewOf, type OutboxEntry } from '@/lib/outbox';
import { add, drop, ENTRY_PREFIX, get, isQueueKey, LEGACY_KEY, readAll, replace, room } from '@/lib/outbox-store';
import { pageProps } from './setup';

const signedOut = vi.fn();
vi.mock('@/lib/logout', () => ({ logout: (...a: unknown[]) => signedOut(...a) }));

/**
 * ‏مخزنُ البيعات المعلّقة على الجهاز (`lib/outbox-store.ts`) — وما يُقال عنها.
 *
 * كلُّ بيعةٍ في مفتاحها، والقائمةُ القديمة نسخةٌ لنسخة أبعاد الأقدم. لا يُرحَّل شيءٌ
 * ولا يُمسح: ما كتبته نسخةٌ أقدم يُقرأ ويُرسل كما هو، ولا يُحذف إلّا بمعرّفه.
 */

const sale = (uuid: string, at = 1, more: Partial<OutboxEntry> = {}): OutboxEntry => ({
    uuid,
    at,
    payload: { client_uuid: uuid, items: [{ id: 1, name: 'باقة', qty: 2, price: 5 }], expected_total: 10, payment_method: 'نقدي' },
    ...more,
});
const legacy = () => JSON.parse(localStorage.getItem(LEGACY_KEY) || '[]') as OutboxEntry[];

beforeEach(() => {
    localStorage.clear();
    signedOut.mockReset();
});
afterEach(() => vi.restoreAllMocks());

describe('المخزن', () => {
    it('كلُّ بيعةٍ في مفتاحها، ونسختُها في القائمة القديمة — وتُحذف منهما معًا', () => {
        expect(add(sale('a'))).toBe(true);
        expect(JSON.parse(localStorage.getItem(`${ENTRY_PREFIX}a`)!).uuid).toBe('a');
        expect(legacy().map((e) => e.uuid)).toEqual(['a']);

        drop('a');
        expect(localStorage.getItem(`${ENTRY_PREFIX}a`)).toBeNull();
        expect(localStorage.getItem(LEGACY_KEY)).toBeNull();
        expect(readAll()).toEqual([]);
    });

    it('ما كتبته نسخةٌ أقدم في القائمة يُقرأ كما هو، وبترتيب إتمامه مع الجديد', () => {
        localStorage.setItem(LEGACY_KEY, JSON.stringify([sale('old-1', 1), sale('old-2', 3)]));
        add(sale('new', 2));

        expect(readAll().map((e) => e.uuid)).toEqual(['old-1', 'new', 'old-2']);
        expect(get('old-2')?.uuid).toBe('old-2');
    });

    it('ولا تُتجاهل بيعةٌ في القائمة بلا مفتاح — ولو أعادها تبويبٌ بعد أن أكّدها الخادم: تُرسل فيردّ فاتورتَها', () => {
        add(sale('done'));
        const stale = localStorage.getItem(LEGACY_KEY)!;
        drop('done');
        localStorage.setItem(LEGACY_KEY, stale);

        // ‏إرسالُها ثانيةً لا يكتبها مرّتين، وتجاهلُها قد يُسقط بيعةً لم تصل
        expect(readAll().map((e) => e.uuid)).toEqual(['done']);
        expect(get('done')?.uuid).toBe('done');
        drop('done');
        expect(readAll()).toEqual([]);
    });

    it('حالُها يتغيّر في مفتاحها ونسختِها — ولا تُعاد بيعةٌ حُذفت', () => {
        add(sale('h'));
        expect(replace('h', { ...sale('h'), held: { status: 422, error: 'x', at: 5 } })).toBe(true);
        expect(get('h')?.held?.status).toBe(422);
        expect(legacy()[0].held?.status).toBe(422);

        drop('h');
        expect(replace('h', sale('h'))).toBe(false);
        expect(readAll()).toEqual([]);
    });

    it('وبيعةٌ من القائمة القديمة يتغيّر حالُها: تُكتب في مفتاحها ولا تُحذف من القائمة', () => {
        localStorage.setItem(LEGACY_KEY, JSON.stringify([sale('o')]));
        replace('o', { ...sale('o'), attempts: 1 });

        expect(JSON.parse(localStorage.getItem(`${ENTRY_PREFIX}o`)!).attempts).toBe(1);
        expect(legacy().map((e) => e.uuid)).toEqual(['o']);
        expect(readAll()).toHaveLength(1);
    });

    it('ونسخةٌ لم تتّسع لها القائمة لا تمسّ البيعة — أصلُها في مفتاحها', () => {
        const real = Storage.prototype.setItem;
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (this: Storage, k: string, v: string) {
            if (k === LEGACY_KEY) throw new DOMException('full', 'QuotaExceededError');
            real.call(this, k, v);
        });

        expect(add(sale('k'))).toBe(true);
        expect(readAll().map((e) => e.uuid)).toEqual(['k']);
    });

    it('وما لا يُقرأ في القائمة القديمة يبقى فيها — لا يُرمى', () => {
        localStorage.setItem(LEGACY_KEY, JSON.stringify([{ junk: true }, sale('x')]));
        drop('x');

        expect(legacy()).toEqual([{ junk: true }]);
    });

    it('المساحة: تُجرَّب بكتابةٍ تُحذف بعدها', () => {
        expect(room()).toBe('ok');
        expect(Object.keys(localStorage)).toEqual([]);

        const real = Storage.prototype.setItem;
        const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (this: Storage, k: string, v: string) {
            if (v.length > 10_000) throw new DOMException('full', 'QuotaExceededError');
            real.call(this, k, v);
        });
        expect(room()).toBe('low');
        spy.mockImplementation(() => {
            throw new DOMException('full', 'QuotaExceededError');
        });
        expect(room()).toBe('full');
    });

    it('أحداثُ التبويبات الأخرى: مفاتيحُ الطابور وحدها، ومسحُ التخزين كلِّه', () => {
        expect(isQueueKey(`${ENTRY_PREFIX}z`)).toBe(true);
        expect(isQueueKey(LEGACY_KEY)).toBe(true);
        expect(isQueueKey(null)).toBe(true);
        expect(isQueueKey('abadpos:pos:probe')).toBe(false);
        expect(isQueueKey('abaad.editor.unsaved.1.1')).toBe(false);
    });
});

describe('ما تحتاجه البيعةُ المرفوضة', () => {
    const held = (fields: string[]) => sale('r', 1, { held: { status: 422, error: 'x', at: 1, fields } });

    it('الإعادةُ آمنة ما دام معرّفُها في حمولتها', () => {
        expect(reviewOf(sale('s')).safe).toBe(true);
        expect(reviewOf({ ...sale('s'), payload: { client_uuid: 'other' } }).safe).toBe(false);
    });

    it('كوبونٌ وآجلٌ ونقاطٌ وسلّةٌ أُتمّت من جهازٍ آخر: مراجعةٌ ماليّة — ومخزونٌ وعميلٌ موقوف: مسؤولٌ مخوّل', () => {
        for (const f of ['coupon_code', 'credit', 'paid_now', 'redeem_points', 'resume_id', 'expected_total']) {
            expect(reviewOf(held([f]))).toMatchObject({ financial: true, manager: true, useful: false });
        }
        for (const f of ['items', 'items.0.qty', 'block_override_reason', 'customer']) {
            expect(reviewOf(held([f]))).toMatchObject({ financial: false, manager: true, useful: false });
        }
        expect(reviewOf(held([]))).toMatchObject({ financial: false, manager: true });
        expect(reviewOf(sale('t', 1, { stuck: true }))).toMatchObject({ financial: false, manager: false, useful: true });
    });
});

describe('قائمةُ المراجعة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
    });

    it('لكلّ مرفوضة: المعرّف، وما قاله السجلّ، وما تحتاجه — والملاحظةُ الصادقة عن الجهاز', async () => {
        const { default: HeldSalesDialog } = await import('@/Pages/Pos/partials/HeldSalesDialog');
        render(
            <HeldSalesDialog
                open
                onOpenChange={() => {}}
                sales={[
                    sale('coupon-1', 1, { held: { status: 422, error: 'انتهى الكوبون', at: 1, fields: ['coupon_code'] }, checked: { at: 1, found: false } }),
                    sale('stock-1', 2, { held: { status: 422, error: 'نفد المخزون', at: 1, fields: ['items'] } }),
                    sale('wait-1', 3),
                ]}
                onRetry={async () => {}}
                money={(v) => `${v}`}
                persisted
            />,
        );

        const [coupon, stock, waiting] = screen.getAllByTestId('held-sale');
        expect(within(coupon).getByTestId('needs-finance')).toBeTruthy();
        expect(within(coupon).getByTestId('server-check').textContent).toContain('ولم يجدها مسجّلة');
        expect(within(stock).getByTestId('needs-manager')).toBeTruthy();
        expect(within(stock).getByTestId('server-check').textContent).toBe('لم يؤكدها الخادم بعد');
        expect(within(waiting).queryByTestId('needs-manager')).toBeNull();
        // ‏لا حذفَ ولا شطبَ ولا تسوية
        expect(screen.queryByRole('button', { name: /حذف|شطب|تسوية/ })).toBeNull();

        await userEvent.click(within(coupon).getByRole('button', { name: 'مراجعة العملية' }));
        expect(within(coupon).getByText('coupon-1')).toBeTruthy();
        expect(within(coupon).getByTestId('finance-note').textContent).toContain('لا تسوية ولا شطب');

        const note = screen.getByTestId('device-note').textContent ?? '';
        expect(note).toContain('إن مُسحت بيانات المتصفح أو تلف الجهاز قبل ذلك فلا يمكن استعادتها');
        expect(note).toContain('أذن المتصفح بحفظها حفظًا دائمًا');
    });
});

describe('الخروجُ وعلى الجهاز بيعاتٌ لم تصل', () => {
    const layout = (businessId: number) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            translations: {},
            auth: { abilities: ['pos'], mayActions: [], user: { name: 'كاشير', avatar: null, roleLabel: 'كاشير', businessId } },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, currencies: [] },
            locale: 'ar',
            csrf: 'x',
            flash: {},
        });
        const base = globalThis.route as unknown as (...a: unknown[]) => string;
        globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'pos.index' })) as never;
    };
    const signOut = async () => {
        const { default: PosLayout } = await import('@/Layouts/PosLayout');
        render(<PosLayout title="اختبار"><div /></PosLayout>);
        await userEvent.click(screen.getByRole('button', { name: /كاشير/ }));
        await userEvent.click(await screen.findByRole('menuitem', { name: /تسجيل الخروج/ }));
    };

    it('يُقال كم بيعةً عليه وأنّها تبقى — ثمّ يخرج إن شاء', async () => {
        layout(1);
        add(sale('a', 1, { business: 1 }));
        add(sale('b', 2, { business: 1 }));
        add(sale('other-shop', 3, { business: 2 }));

        await signOut();

        const ask = await screen.findByTestId('logout-pending');
        expect(ask.textContent).toContain('على هذا الجهاز 2 من البيعات لم يؤكدها الخادم بعد');
        expect(signedOut).not.toHaveBeenCalled();
        await userEvent.click(within(ask).getByTestId('logout-anyway'));
        expect(signedOut).toHaveBeenCalledTimes(1);
        // ‏والخروجُ لا يمسّها
        expect(readAll()).toHaveLength(3);
    });

    it('ولا سؤالَ وما عليه شيء لمتجره', async () => {
        layout(1);
        add(sale('other-shop', 1, { business: 2 }));

        await signOut();

        await waitFor(() => expect(signedOut).toHaveBeenCalledTimes(1));
        expect(screen.queryByTestId('logout-pending')).toBeNull();
    });
});

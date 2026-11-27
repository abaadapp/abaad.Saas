import { act, render, renderHook, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { OutboxEntry } from '@/lib/outbox';
import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * ‏البيعةُ التي لم يحفظها الجهازُ لا تُقال «حُفظت»، وما حُفظ لا يمحوه تبويبٌ آخر.
 *
 * ═══ ما كان ═══
 *
 *   ١. ‏الطابورُ قائمةٌ واحدة في مفتاحٍ واحد (`abadpos:pos:outbox`) تُقرأ وتُكتب
 *      كلُّها مع كلّ بيعة. وكتابتُها بلا حارس: مساحةٌ ممتلئة ترمي خطأً لا يلتقطه
 *      أحد — فتقف البيعةُ قبل أن تُرسل ولو كان الاتّصال قائمًا، ولا يُقال للكاشير
 *      شيء. والكتابةُ لا تُقرأ بعدها: متصفّحٌ يقبلها ولا يحفظها يمرّ.
 *   ٢. ‏تبويبان للصندوق على جهازٍ واحد يقرأ كلٌّ منهما القائمةَ من ذاكرته ثمّ
 *      يكتبها كاملة. فبيعةٌ يكتبها هذا والآخرُ يُنهي إرسالَ غيرها تُمحى بنسخته
 *      القديمة (المتصفّحُ ينقل الكتابةَ بين التبويبات بعد لحظة لا في الحال).
 *   ٣. ‏والعدّادُ في تبويبٍ لا يرى ما كتبه الآخر حتى تُحمَّل الصفحة.
 *
 * ═══ ما صار (`lib/outbox-store.ts`) ═══
 *
 * كلُّ بيعةٍ في مفتاحها (`abadpos:pos:outbox:<المعرّف>`) تُكتب مرّةً وتُقرأ بعدها،
 * ولا تُحذف إلّا بمعرّفها حين يقول الخادمُ إنّه كتبها. والقائمةُ القديمة باقيةٌ
 * نسخةً لها، لنسخة أبعاد الأقدم إن عاد إليها الجهاز. وما لم يُحفظ يُقال، والسلّةُ
 * تبقى، والمحاولةُ التالية للسلّة نفسِها بمعرّفها نفسِه.
 */

const KEY = 'abadpos:pos:outbox';
type Sent = Record<string, unknown>;
type Reply = { status: number; body?: unknown } | 'network';
const shelf = [{ id: 1, label: 'باقة ورد', price: 10, image: null, sku: null, barcode: null, stock: 50 }];

const setOnline = (on: boolean) => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => on });
};
/** ‏البيعاتُ على الجهاز كما يقرؤها أيُّ تبويب — مفاتيحُها والقائمةُ القديمة */
const onDevice = (): string[] => {
    const own = Object.keys(localStorage).filter((k) => k.startsWith(`${KEY}:`)).map((k) => (JSON.parse(localStorage.getItem(k)!) as OutboxEntry).uuid);
    const legacy = (JSON.parse(localStorage.getItem(KEY) || '[]') as OutboxEntry[]).map((e) => e.uuid);

    return [...new Set([...own, ...legacy])];
};

/** ‏مساحةٌ ممتلئة: كلُّ كتابةٍ لبيعةٍ (أو أكبر من حدٍّ) تُرمى كما يرميها المتصفّح */
const realSet = Storage.prototype.setItem;
const fill = (mode: 'throw' | 'silent' = 'throw') =>
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (this: Storage, k: string, v: string) {
        if (k.startsWith('abadpos:pos:')) {
            if (mode === 'silent') return;
            throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
        }
        realSet.call(this, k, v);
    });

const mount = async (reply: (body: Sent) => Reply) => {
    const sent: Sent[] = [];
    const onToast = vi.fn();
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit) => {
            const body = JSON.parse(String(init.body)) as Sent;
            if (String(url).includes('/pos/outbox/status')) return { ok: true, status: 200, json: async () => ({ saved: {} }) };
            sent.push(body);
            const r = reply(body);
            if (r === 'network') throw new TypeError('Failed to fetch');

            return { ok: r.status < 300, status: r.status, json: async () => r.body ?? (r.status === 200 ? { ok: true, invoice: `INV-${sent.length}`, total: 10 } : {}) };
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
            onToast: (...a: unknown[]) => onToast(...a),
        } as never),
    );

    return { view, sent, onToast };
};
type View = Awaited<ReturnType<typeof mount>>['view'];
const sell = async (view: View) => {
    act(() => view.result.current.add(shelf[0] as never));
    let result: Awaited<ReturnType<View['result']['current']['checkoutSale']>> | undefined;
    await act(async () => {
        result = await view.result.current.checkoutSale('نقدي');
    });

    return result!;
};

beforeEach(() => {
    localStorage.clear();
    setOnline(true);
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    setOnline(true);
});

describe('مساحةُ الجهاز ممتلئة أو لا تحفظ', () => {
    it('بلا اتّصال: لا تُقال «حُفظت» — تبقى السلّة، ويُقال لماذا، ولا يُعدّ شيءٌ معلّقًا', async () => {
        setOnline(false);
        const { view, onToast } = await mount(() => 'network');
        fill();

        const result = await sell(view);

        expect(result.rejected).toBe(true);
        expect(result.notSaved).toBe(true);
        expect(view.result.current.items).toHaveLength(1);
        expect(view.result.current.pendingCount).toBe(0);
        expect(onToast).toHaveBeenCalledWith(expect.stringContaining('لم تُحفظ البيعة'), 'danger');
    });

    it('ومتصفّحٌ يقبل الكتابةَ ولا يحفظها: تُقرأ بعدها فيُعرف', async () => {
        setOnline(false);
        const { view } = await mount(() => 'network');
        fill('silent');

        const result = await sell(view);

        expect(result.notSaved).toBe(true);
        expect(view.result.current.items).toHaveLength(1);
    });

    it('والمحاولةُ التالية للسلّة نفسِها بمعرّفها نفسِه — فإن كان الخادمُ كتب الأولى لا تُكتب ثانية', async () => {
        setOnline(false);
        const { view, sent } = await mount(() => 'network');
        const full = fill();
        await sell(view);
        const first = sent[0].client_uuid;

        full.mockRestore();
        let again: Awaited<ReturnType<View['result']['current']['checkoutSale']>> | undefined;
        await act(async () => {
            again = await view.result.current.checkoutSale('نقدي');
        });

        expect(again?.rejected).toBe(false);
        expect(sent[1].client_uuid).toBe(first);
        expect(onDevice()).toEqual([first]);
    });

    it('وبعد أن تُحفظ، سلّةٌ مثلُها بيعةٌ أخرى بمعرّفٍ جديد — ولا يُبتلع بيعُ زبونٍ ثانٍ', async () => {
        setOnline(false);
        const { view, sent } = await mount(() => 'network');
        const full = fill();
        await sell(view);
        full.mockRestore();
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });
        // ‏حُفظت الثانيةُ بمعرّف الأولى. والكاشيرُ يبيع السلّةَ نفسَها لزبونٍ تالٍ دون أن يفرّغها
        await act(async () => {
            await view.result.current.checkoutSale('نقدي');
        });

        expect(sent[1].client_uuid).toBe(sent[0].client_uuid);
        expect(sent[2].client_uuid).not.toBe(sent[0].client_uuid);
        expect(onDevice().sort()).toEqual([sent[0].client_uuid, sent[2].client_uuid].sort());
    });

    it('وسلّةٌ تُركت (أُفرغت) بعد محاولةٍ لم تُحفظ: مثلُها بعدها بمعرّفٍ جديد', async () => {
        setOnline(false);
        const { view, sent } = await mount(() => 'network');
        const full = fill();
        await sell(view);
        full.mockRestore();
        act(() => view.result.current.clear());

        await sell(view);

        expect(sent[1].client_uuid).not.toBe(sent[0].client_uuid);
        expect(onDevice()).toEqual([sent[1].client_uuid]);
    });

    it('بالاتّصال: المساحةُ الممتلئة لا توقف البيع — يُرسل ويكتبه الخادم', async () => {
        const { view, sent } = await mount(() => ({ status: 200 }));
        fill();

        const result = await sell(view);

        expect(sent).toHaveLength(1);
        expect(result.synced).toBe(true);
        expect(result.invoice).toBe('INV-1');
    });

    it('ويُقال في الشاشة إنّ المساحة ممتلئة قبل أن تُحتاج', async () => {
        const { view } = await mount(() => ({ status: 200 }));
        fill();
        await sell(view);

        await waitFor(() => expect(view.result.current.room).toBe('full'));
    });
});

describe('ورقةُ الدفع وبيعةٌ لم تُحفظ', () => {
    it('لا تُفتح «تم الدفع»، ويبقى السببُ فوق الزرّ لا رسالةً تختفي', async () => {
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: [] } });
        const { default: PaymentDialog } = await import('@/Pages/Pos/partials/PaymentDialog');
        const user = userEvent.setup();
        render(
            <PaymentDialog
                open
                onOpenChange={() => {}}
                total={10}
                displayTotal={10}
                customer=""
                money={(v) => String(v)}
                fmt={(v) => String(v)}
                onCheckout={(async () => ({ synced: false, invoice: null, points: 0, rejected: true, notSaved: true })) as never}
                onNewOrder={() => {}}
                methods={['نقدي']}
            />,
        );

        await user.click(screen.getByRole('button', { name: /نقدي/ }));
        await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));

        expect((await screen.findByTestId('sale-not-saved')).textContent).toContain('لم تُحفظ البيعة');
        expect(screen.queryByText('تم الدفع بنجاح')).toBeNull();
    });
});

describe('تبويبٌ آخر على الجهاز نفسِه', () => {
    it('نسختُه القديمة من القائمة لا تمحو بيعةً كُتبت هنا', async () => {
        setOnline(false);
        const { view } = await mount(() => 'network');
        await sell(view);
        const kept = onDevice();
        expect(kept).toHaveLength(1);

        // ‏التبويبُ الآخر أنهى إرسالَ غيرها وكتب القائمةَ كما يراها — قبل أن تصله هذه
        realSet.call(localStorage, KEY, '[]');
        view.unmount();

        const reopened = await mount(() => ({ status: 200 }));
        expect(reopened.view.result.current.pendingCount).toBe(1);
        setOnline(true);
        await act(async () => {
            window.dispatchEvent(new Event('online'));
        });
        await waitFor(() => expect(reopened.sent.map((b) => b.client_uuid)).toEqual(kept));
        await waitFor(() => expect(onDevice()).toEqual([]));
    });

    it('وما كتبه الآخرُ يظهر في عدّاد هذا دون تحميل الصفحة', async () => {
        setOnline(false);
        const { view } = await mount(() => 'network');
        expect(view.result.current.pendingCount).toBe(0);

        const other: OutboxEntry = { uuid: 'tab-2', at: Date.now(), payload: { client_uuid: 'tab-2', items: [{ id: 1, qty: 1 }], expected_total: 10 } };
        realSet.call(localStorage, `${KEY}:tab-2`, JSON.stringify(other));
        act(() => {
            window.dispatchEvent(new StorageEvent('storage', { key: `${KEY}:tab-2` }));
        });

        await waitFor(() => expect(view.result.current.pendingCount).toBe(1));
    });
});

describe('التخزينُ الدائم', () => {
    it('يُطلب من المتصفّح مرّةً حين تبقى بيعةٌ على الجهاز — لا قبلها ولا مع كلّ بيعة', async () => {
        const persist = vi.fn(async () => true);
        const persisted = vi.fn(async () => false);
        Object.defineProperty(window.navigator, 'storage', { configurable: true, value: { persist, persisted, estimate: async () => ({ usage: 0, quota: 1e9 }) } });
        setOnline(false);
        const { view } = await mount(() => 'network');
        expect(persist).not.toHaveBeenCalled();

        await sell(view);
        await sell(view);

        await waitFor(() => expect(persist).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(view.result.current.persisted).toBe(true));
        Reflect.deleteProperty(window.navigator, 'storage');
    });
});

describe('التنبيهُ في شاشة الصندوق', () => {
    const page = () =>
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
    const sale = (uuid: string, more: Partial<OutboxEntry> = {}): OutboxEntry => ({ uuid, at: Date.now(), payload: { client_uuid: uuid, items: [{ id: 1, qty: 1 }], expected_total: 10 }, ...more });

    it('عددُ ما لم يؤكّده الخادم، وأن تُبقى بياناتُ المتصفّح، وحالُ الاتّصال — والمرفوضةُ تُعدّ وحدها', async () => {
        setOnline(false);
        localStorage.setItem(KEY, JSON.stringify([sale('p-1'), sale('p-2'), sale('h-1', { held: { status: 422, error: 'x', at: Date.now() } })]));
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ saved: {} }) })));
        page();
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        const notice = await screen.findByTestId('outbox-notice');
        expect(notice.textContent).toContain('يوجد لديك 3 عمليات بيع لم تكتمل مزامنتها بعد. يرجى إبقاء بيانات المتصفح وعدم مسحها حتى تتم المزامنة أو مراجعة العمليات.');
        expect(notice.textContent).toContain('غير متصل');
        expect(screen.getByTestId('review-notice').textContent).toContain('توجد (1) عمليات بيع معلقة تحتاج إلى مراجعة.');
        expect(notice.className).not.toMatch(/\bfixed\b/);

        await userEvent.setup().click(screen.getByRole('button', { name: 'مراجعة العمليات' }));
        expect(screen.getAllByTestId('held-sale')).toHaveLength(3);
    });

    it('لا يظهر وما على الجهاز لمتجرٍ آخر وحده', async () => {
        localStorage.setItem(KEY, JSON.stringify([sale('other', { business: 99 })]));
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ saved: {} }) })));
        page();
        // ‏صندوقُ متجرٍ بعينه: عملاؤه من الجهاز (`customersScope`)، فلا قائمةَ في الصفحة
        delete (pageProps as Record<string, unknown>).customers;
        Object.assign(pageProps, { customersScope: 1 });
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        await screen.findByTestId('foreign-pending');
        expect(screen.queryByTestId('outbox-notice')).toBeNull();
    });
});

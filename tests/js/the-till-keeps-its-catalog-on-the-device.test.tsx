import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';
import { applyCatalog, askWith, snapshotOf, SCHEMA, type CatalogReply, type CatalogSnapshot, type StoredBucket } from '@/lib/till-catalog';
import type { TillProductRow } from '@/lib/till-products';

/**
 * ‏‏كتالوجُ الصندوق على الجهاز — يُعرض ممّا حُفظ، ويأتيه ما تغيّر وحده، ولا يُعدّ
 * ناقصٌ كاملًا، ولا يُكتب قديمٌ فوق أحدث.
 *
 * ═══ ما كان ═══
 *
 * صفحةُ الصندوق تحمل الأصنافَ كلَّها في كلّ فتح: عند عشرة آلاف صنف 4.5MB يبنيها
 * الخادمُ في 860ms، ولم يتغيّر منها شيء.
 *
 * ═══ ما صار ═══
 *
 * يحفظ الجهازُ الكتالوجَ (`lib/till-catalog-store.ts`) ويعرضه حين يُفتح الصندوق،
 * ويسأل الخادمَ عمّا تغيّر (`lib/till-catalog.ts`، و`App\Support\TillCatalog`).
 */

const SCOPE = '7:3:ar:1';
const B = 4;
const H = (c: string) => c.repeat(16);
const row = (id: number, over: Partial<TillProductRow> = {}): TillProductRow =>
    ({
        id, name: `صنف ${id}`, name_en: null, cat: 'ورد', price: 2, cost: 1, qty: 10, sku: null, barcode: null, image: '', thumb: '',
        stock_status: 'متوفر', tracks_stock: true, active: true, pos_enabled: true, pos_description: null, alert: 1, tax: 5,
        variants: [], addon_ids: [], has_recipe: false, discount: 0, ...over,
    }) as TillProductRow;
const shelf = () => Array.from({ length: 10 }, (_, k) => row(k + 1));
const full = (rows = shelf(), v = 'v1', ih = 'a', oh = 'b'): CatalogReply => ({
    full: true, scope: SCOPE, v, t: 't1', b: B, ih: H(ih).repeat(B), oh: H(oh).repeat(B), count: rows.length, products: rows,
});
const snapOf = (d: CatalogReply): CatalogSnapshot => {
    const r = applyCatalog(null, d);
    if (r === 'stale') throw new Error('full rejected');

    return r.snap;
};

describe('applyCatalog — ما يفعله الجهازُ بالجواب', () => {
    it('الكاملُ يُعدّ: عددُه وبصماتُه ومعرّفاتُه — وما خالف يُطرح كلُّه', () => {
        expect(snapOf(full()).rows.size).toBe(10);

        expect(applyCatalog(null, { ...full(), count: 9 })).toBe('stale');
        expect(applyCatalog(null, { ...full(), ih: 'abc' })).toBe('stale');
        expect(applyCatalog(null, { ...full(), oh: H('z').repeat(B) })).toBe('stale');
        expect(applyCatalog(null, { ...full(), b: 3 })).toBe('stale');
        expect(applyCatalog(null, { ...full([row(1), row(1)]), count: 2 })).toBe('stale');
        expect(applyCatalog(null, { ...full(), t: undefined })).toBe('stale');
    });

    it('سلّةٌ تغيّرت تُستبدل كلُّها — ما حُذف منها يُحذف، وما لم يتغيّر يبقى الكائنَ نفسَه', () => {
        const s = snapOf(full());
        // ‏‏السلّة 1: 1 و5 و9 — تغيّر 5، وحُذف 9، وبقي 1 كما هو
        const r = applyCatalog(s, { base: 'v1', v: 'v2', t: 't2', b: B, buckets: [[1, H('c'), H('d')]], rows: [1], products: [row(1), row(5, { price: 3 })] });
        if (r === 'stale') throw new Error('stale');

        expect(r.snap.rows.get(1)).toBe(s.rows.get(1));
        expect(r.snap.rows.get(5)?.price).toBe(3);
        expect(r.snap.rows.has(9)).toBe(false);
        expect(r.snap.rows.get(2)).toBe(s.rows.get(2));
        expect(r.changed).toEqual([1]);
        expect(r.moved).toBe(true);
        expect(r.snap.ih[1]).toBe(H('c'));
        // ‏‏وما سُلِّم لا يُمسّ: جوابٌ لم يُطبَّق لا يترك أثرًا
        expect(s.rows.has(9)).toBe(true);
        expect(s.ih[1]).toBe(H('a'));
    });

    it('سلّةٌ تغيّرت بصمتُها وصفُّها هو هو (عمودٌ للموقع): تُكتب بصمتُها ولا يُعاد عرضُ شيء', () => {
        const s = snapOf(full());
        const r = applyCatalog(s, { base: 'v1', v: 'v2', t: 't2', b: B, buckets: [[2, H('e'), H('b')]], rows: [], products: [] });
        if (r === 'stale') throw new Error('stale');

        expect(r.moved).toBe(false);
        expect(r.changed).toEqual([2]);
        expect(r.snap.ih[2]).toBe(H('e'));
        expect([...r.snap.rows.values()]).toEqual([...s.rows.values()]);
    });

    it('جوابٌ على غير ما عند الجهاز الآن يُطرح — لا يُكتب قديمٌ فوق أحدث', () => {
        const s = snapOf(full());
        const late = { base: 'v1', v: 'v2', t: 't2', b: B, buckets: [[1, H('c'), H('d')]], rows: [1], products: [row(5, { price: 3 })] } as CatalogReply;
        const moved = applyCatalog(s, late);
        if (moved === 'stale') throw new Error('stale');

        expect(applyCatalog(moved.snap, late)).toBe('stale');
        expect(applyCatalog(null, late)).toBe('stale');
        expect(applyCatalog(s, { ...late, b: 8 })).toBe('stale');
        expect(applyCatalog(s, { ...late, products: [row(2)] })).toBe('stale');
        expect(applyCatalog(s, { ...late, rows: [3] })).toBe('stale');
        // ‏‏وسلّةٌ بأصنافها لم تُعلَن بصمتُها — ولو كان صنفُها فيها
        expect(applyCatalog(s, { ...late, rows: [1, 3], products: [row(5), row(3)] })).toBe('stale');
        expect(applyCatalog(s, { ...late, buckets: [[9, H('c'), H('d')]] as [number, string, string][] })).toBe('stale');
        expect(applyCatalog(s, { v: 'other', t: 't3', same: true })).toBe('stale');
    });

    it('«كما هو» يحفظ الرمزَ الجديد ولا يغيّر صنفًا', () => {
        const s = snapOf(full());
        const r = applyCatalog(s, { same: true, v: 'v1', t: 't9' });
        if (r === 'stale') throw new Error('stale');

        expect(r.snap.t).toBe('t9');
        expect(r.snap.rows).toBe(s.rows);
        expect(r.moved).toBe(false);
    });

    it('ويُرسل الجهازُ بصماتِه ورمزَه — أو لا شيء حين لا يحفظ', () => {
        expect(askWith(SCOPE, null)).toEqual({ scope: SCOPE });
        expect(askWith(SCOPE, snapOf(full()), true)).toEqual({ scope: SCOPE, t: 't1', b: B, ih: H('a').repeat(B), oh: H('b').repeat(B), deep: true });
    });
});

describe('snapshotOf — نسخةُ الجهاز كاملةً سليمة، أو لا شيء', () => {
    const head = (over = {}) => ({ scope: SCOPE, schema: SCHEMA, b: B, v: 'v1', t: 't1', count: 10, rev: 'r1', ...over });
    const buckets = (): StoredBucket[] =>
        Array.from({ length: B }, (_, i) => ({ scope: SCOPE, bucket: i, ih: H('a'), oh: H('b'), rows: shelf().filter((r) => r.id % B === i) }));

    it('تُقرأ كما كُتبت', () => {
        expect(snapshotOf(SCOPE, head(), buckets())?.rows.size).toBe(10);
    });

    it.each([
        ['سلّةٌ ناقصة', () => [head(), buckets().slice(1)]],
        ['سلّةٌ مكرّرة', () => [head(), [...buckets().slice(1), buckets()[1]]]],
        ['صنفٌ في غير سلّته', () => [head(), buckets().map((b) => (b.bucket === 0 ? { ...b, rows: [...b.rows, row(5)] } : b))]],
        ['عددٌ غيرُ عدد الرأس', () => [head({ count: 11 }), buckets()]],
        ['بصمةٌ تالفة', () => [head(), buckets().map((b) => (b.bucket === 2 ? { ...b, oh: 'xx' } : b))]],
        ['مفتاحٌ آخر', () => [head({ scope: '8:3:ar:1' }), buckets()]],
        ['بنيةٌ أقدم', () => [head({ schema: SCHEMA - 1 }), buckets()]],
        ['صنفٌ بلا سعر', () => [head(), buckets().map((b) => (b.bucket === 1 ? { ...b, rows: b.rows.map((r) => ({ ...r, price: undefined })) } : b))]],
        ['لا شيء', () => [null, []]],
    ])('%s: تالفة — لا يُعرض نصفُها', (_what, make) => {
        const [h, bs] = make();
        expect(snapshotOf(SCOPE, h, bs)).toBeNull();
    });
});

/* ═══════════════ الجهازُ والخادم معًا ═══════════════ */

const memory = vi.hoisted(() => ({
    stored: null as null | { snap: unknown; rev: string },
    available: true,
    failSave: false,
    saves: [] as Array<{ changed: number[] | 'all'; rev: string | null }>,
}));
vi.mock('@/lib/till-catalog-store', () => ({
    load: vi.fn(async () => (memory.available ? memory.stored : 'unavailable')),
    save: vi.fn(async (snap: unknown, rev: string | null, changed: number[] | 'all') => {
        if (!memory.available) return 'unavailable';
        memory.saves.push({ changed, rev });
        if (memory.failSave) return 'failed';
        const next = `r${memory.saves.length}`;
        memory.stored = { snap, rev: next };

        return { rev: next };
    }),
    forgetOtherCatalogs: vi.fn(),
    keepOnly: vi.fn(async () => {}),
}));

let replies: Array<CatalogReply | 'offline' | 'scope' | (() => Promise<CatalogReply>)>;
let asked: Array<Record<string, unknown>>;
beforeEach(() => {
    memory.stored = null;
    memory.available = true;
    memory.failSave = false;
    memory.saves = [];
    replies = [];
    asked = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (_url: string, init?: RequestInit) => {
            asked.push(JSON.parse(String(init?.body ?? '{}')));
            const r = replies.shift();
            if (r === 'offline' || r === undefined) throw new TypeError('Failed to fetch');
            if (r === 'scope') return { ok: false, status: 409, json: async () => ({ scope_changed: true }) };
            const d = typeof r === 'function' ? await r() : r;

            return { ok: true, status: 200, json: async () => d };
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const mount = async () => {
    const { default: useTillCatalog } = await import('@/hooks/useTillCatalog');

    return renderHook(() => useTillCatalog('/pos/catalog-feed', SCOPE, { intervalMs: 1e9 }));
};

describe('useTillCatalog — من الجهاز أوّلًا، ثمّ ما تغيّر', () => {
    beforeEach(() => {
        (globalThis as Record<string, unknown>).__realCatalog = true;
    });
    afterEach(() => {
        (globalThis as Record<string, unknown>).__realCatalog = false;
    });

    it('جهازٌ بلا نسخة: يأخذ الكتالوجَ كلَّه مرّةً ويحفظه — ثمّ يسأل بما حفظ', async () => {
        replies = [full(), { same: true, v: 'v1', t: 't1', scope: SCOPE }];
        const { result } = await mount();
        await waitFor(() => expect(result.current.status).toBe('ready'));

        expect(asked[0]).toEqual({ scope: SCOPE });
        expect(result.current.rows?.map((r) => r.id)).toEqual(shelf().map((r) => r.id));
        expect(result.current.source).toBe('server');
        expect(memory.saves).toEqual([{ changed: 'all', rev: null }]);
        await waitFor(() => expect(result.current.saved).toBe('yes'));
        // ‏‏والاسمُ المعروض الغائب يُكمَل مرّةً — والمحفوظُ هو المعروضُ نفسُه، لا نسختان
        expect(result.current.rows?.[0].label).toBe('صنف 1');
        expect(result.current.rows?.[0].sale_price).toBe(2);
        expect((memory.stored?.snap as CatalogSnapshot).rows.get(1)).toBe(result.current.rows?.[0]);

        await act(() => result.current.refresh());
        expect(asked[1]).toMatchObject({ scope: SCOPE, t: 't1', b: B });
    });

    it('جهازٌ بنسخة: تُعرض قبل أن يجيب الخادم — و«كما هو» لا يغيّر كائنًا ولا يكتب شيئًا', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        let answer!: (d: CatalogReply) => void;
        replies = [() => new Promise<CatalogReply>((ok) => (answer = ok))];
        const { result } = await mount();

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.source).toBe('device');
        const shown = result.current.rows;
        await waitFor(() => expect(answer).toBeTypeOf('function'));
        await act(async () => answer({ same: true, v: 'v1', t: 't1', scope: SCOPE }));

        expect(result.current.rows).toBe(shown);
        expect(memory.saves).toEqual([]);
    });

    it('صنفٌ تغيّر: كائنُه وحده جديد — وتُكتب سلّتُه وحدها فوق ما قُرئ', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        let answer!: (d: CatalogReply) => void;
        replies = [() => new Promise<CatalogReply>((ok) => (answer = ok))];
        const { result } = await mount();
        await waitFor(() => expect(result.current.source).toBe('device'));
        const shown = result.current.rows;
        await waitFor(() => expect(answer).toBeTypeOf('function'));
        await act(async () => answer({ scope: SCOPE, base: 'v1', v: 'v2', t: 't2', b: B, buckets: [[2, H('c'), H('d')]], rows: [2], products: [row(2, { price: 9 }), row(6), row(10)] }));
        await waitFor(() => expect(result.current.rows?.[1].price).toBe(9));

        expect(result.current.rows?.filter((r, i) => r !== shown?.[i]).map((r) => r.id)).toEqual([2]);
        expect(memory.saves).toEqual([{ changed: [2], rev: 'r0' }]);
    });

    it('جوابٌ لا يُعرف أساسُه يُطرح — ويُسأل الكلُّ من جديد، وما يُعرض يبقى حتى يصل', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        replies = [{ scope: SCOPE, base: 'other', v: 'v2', t: 't2', b: B, buckets: [], rows: [], products: [] }, full(shelf().map((r) => (r.id === 3 ? { ...r, price: 7 } : r)), 'v3')];
        const { result } = await mount();

        await waitFor(() => expect(asked).toHaveLength(2));
        expect(asked[1]).toEqual({ scope: SCOPE });
        await waitFor(() => expect(result.current.rows?.[2].price).toBe(7));
    });

    it('كاملٌ لا يُطبَّق لا يُطلب ثانيةً في الحال — لا حلقةَ تنزيلٍ لا تنتهي', async () => {
        replies = [{ ...full(), count: 3 }, { ...full(), count: 3 }, { ...full(), count: 3 }];
        const { result } = await mount();
        await waitFor(() => expect(result.current.status).toBe('failed'));
        await new Promise((ok) => setTimeout(ok, 50));

        expect(asked).toHaveLength(1);
        expect(result.current.stale).toBe(true);
        // ‏‏والسؤالُ التالي (الفترة، أو عودةُ الاتّصال) يسأل من جديد
        replies = [full()];
        await act(() => result.current.refresh());
        await waitFor(() => expect(result.current.status).toBe('ready'));
    });

    it('سؤالٌ واحد في وقت: ما طُلب أثناءه يُصفّ خلفه — والفحصُ العميق لا يضيع', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        let first!: (d: CatalogReply) => void;
        replies = [() => new Promise<CatalogReply>((ok) => (first = ok)), { same: true, v: 'v1', t: 't5', scope: SCOPE }];
        const { result } = await mount();
        await waitFor(() => expect(asked).toHaveLength(1));

        void result.current.refresh(true);
        void result.current.refresh();
        expect(asked).toHaveLength(1);
        await act(async () => first({ same: true, v: 'v1', t: 't1', scope: SCOPE }));

        await waitFor(() => expect(asked).toHaveLength(2));
        expect(asked[1].deep).toBe(true);
    });

    it('لا اتّصال: تبقى نسخةُ الجهاز ويُقال إنّها لم يُتحقَّق منها — وجهازٌ بلا نسخةٍ يُقال له', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        replies = ['offline'];
        const { result, unmount } = await mount();
        await waitFor(() => expect(result.current.stale).toBe(true));

        expect(result.current.status).toBe('ready');
        expect(result.current.rows).toHaveLength(10);
        unmount();

        memory.stored = null;
        replies = ['offline'];
        const second = await mount();
        await waitFor(() => expect(second.result.current.status).toBe('failed'));
        expect(second.result.current.rows).toBeNull();
    });

    it('فُتح متجرٌ أو فرعٌ أو لغةٌ أخرى: لا يُطبَّق شيء ولا يُسأل بعدها', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        replies = ['scope'];
        const { result } = await mount();
        await waitFor(() => expect(result.current.scopeChanged).toBe(true));
        await act(() => result.current.refresh());

        expect(asked).toHaveLength(1);
        expect(result.current.rows).toHaveLength(10);
        expect(memory.saves).toEqual([]);
    });

    it('جوابٌ لمفتاحٍ آخر لا يُطبَّق', async () => {
        replies = [{ ...full(), scope: '8:3:ar:1' }];
        const { result } = await mount();
        await waitFor(() => expect(result.current.scopeChanged).toBe(true));

        expect(result.current.rows).toBeNull();
        expect(memory.saves).toEqual([]);
    });

    it('مساحةٌ ممتلئة: يعمل الصندوقُ من الخادم ويُقال إنّ الأصناف لم تُحفظ', async () => {
        memory.failSave = true;
        replies = [full()];
        const { result } = await mount();
        await waitFor(() => expect(result.current.saved).toBe('no'));

        expect(result.current.status).toBe('ready');
        expect(result.current.rows).toHaveLength(10);
    });

    it('لا IndexedDB (نافذةٌ خاصّة): من الخادم في كلّ فتح، ولا شيء يتعطّل', async () => {
        memory.available = false;
        replies = [full()];
        const { result } = await mount();
        await waitFor(() => expect(result.current.status).toBe('ready'));

        expect(result.current.saved).toBe('off');
        expect(asked[0]).toEqual({ scope: SCOPE });
    });

    /* ─── مفتاحُ المنصّة (`TillCatalogSwitch`) ─── */

    it('أطفأه مديرُ المنصّة: لا يُطبَّق شيءٌ ولا يُحفظ ولا يُمحى، ولا يُسأل بعدها — وما يُعرض يبقى هو هو', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        const store = await import('@/lib/till-catalog-store');
        (store.keepOnly as ReturnType<typeof vi.fn>).mockClear();
        let answer!: (d: CatalogReply) => void;
        replies = [() => new Promise<CatalogReply>((ok) => (answer = ok))];
        const { result } = await mount();
        await waitFor(() => expect(result.current.source).toBe('device'));
        const shown = result.current.rows;
        await waitFor(() => expect(answer).toBeTypeOf('function'));
        await act(async () => answer({ off: true, scope: SCOPE }));

        await waitFor(() => expect(result.current.off).toBe(true));
        expect(result.current.rows).toBe(shown);
        expect(result.current.status).toBe('ready');
        expect(memory.saves).toEqual([]);
        expect(store.keepOnly).not.toHaveBeenCalled();
        expect((memory.stored?.snap as CatalogSnapshot).v).toBe('v1');

        // ‏‏ولا سؤالَ بعده: لا بعد بيعة، ولا حين يعود التبويب أو الاتّصال
        await act(() => result.current.refresh(true));
        window.dispatchEvent(new Event('online'));
        document.dispatchEvent(new Event('visibilitychange'));
        await new Promise((r) => setTimeout(r, 20));
        expect(asked).toHaveLength(1);
    });

    it('مطفأٌ وسؤالٌ مصفوفٌ خلفه (بيعةٌ أو مبلغٌ ردّه الخادم): لا يُرسل', async () => {
        memory.stored = { snap: snapOf(full()), rev: 'r0' };
        let answer!: (d: CatalogReply) => void;
        replies = [() => new Promise<CatalogReply>((ok) => (answer = ok)), full()];
        const { result } = await mount();
        await waitFor(() => expect(answer).toBeTypeOf('function'));
        void result.current.refresh(true);
        await act(async () => answer({ off: true, scope: SCOPE }));
        await new Promise((r) => setTimeout(r, 20));

        expect(result.current.off).toBe(true);
        expect(asked).toHaveLength(1);
    });

    it('جهازٌ بلا نسخةٍ والمفتاحُ مطفأ: لا أصنافَ من الكتالوج ولا خطأ — تنتظر الشاشةُ أصنافَ الخادم', async () => {
        replies = [{ off: true, scope: SCOPE }];
        const { result } = await mount();
        await waitFor(() => expect(result.current.off).toBe(true));

        expect(result.current.rows).toBeNull();
        expect(result.current.status).toBe('loading');
        expect(memory.saves).toEqual([]);
    });
});

/* ═══════════════ والكميّةُ من الصفحة لا من الكتالوج ═══════════════ */

describe('useLiveStock مع الكتالوج على الجهاز', () => {
    const seed = { full: true, v: 's1', b: 1, h: H('0'), scope: '7:3', products: [{ id: 1, qty: 4, stock_status: 'منخفض' }], updated_at: '10:00:00' };

    it('كميّاتُ الصفحة تُفرض من أوّل رسم — وتحديثُ الكتالوج لا يُعيد كميّةَ يومِ حُفظ', async () => {
        const { default: useLiveStock } = await import('@/hooks/useLiveStock');
        const stale = [{ id: 1, name: 'أ', qty: 50, stock_status: 'متوفر' }];
        const painted: number[] = [];
        const { result, rerender } = renderHook(
            ({ list }) => {
                const r = useLiveStock('/pos/stock-feed', list, { scope: '7:3', seed, freshKey: seed, firstMs: 1e9, intervalMs: 1e9 });
                painted.push(r.products[0].qty);

                return r;
            },
            { initialProps: { list: stale } },
        );

        // ‏‏من أوّل رسم — لا رسمٌ بكميّة يومِ حُفظ ثمّ يُصحَّح
        expect(painted[0]).toBe(4);
        expect(result.current.updatedAt).toBe('10:00:00');

        // ‏‏سؤالٌ لاحق جاء بأحدث من الصفحة
        replies = [{ full: true, v: 's2', b: 1, h: H('1'), scope: '7:3', products: [{ id: 1, qty: 3, stock_status: 'منخفض' }], updated_at: '10:01:00' } as unknown as CatalogReply];
        await act(() => result.current.refresh());
        expect(result.current.products[0].qty).toBe(3);

        // ‏‏والكتالوجُ تحدّث (اسمٌ جديد) وكميّتُه المحفوظةُ قديمة — لا تعود كميّةُ الصفحة ولا كميّتُه
        rerender({ list: [{ ...stale[0], name: 'أ٢' }] });
        expect(result.current.products[0]).toMatchObject({ name: 'أ٢', qty: 3 });
    });

    it('وبلا لقطةٍ من الصفحة كما كان: أصنافُ الصفحة هي المرجع', async () => {
        const { default: useLiveStock } = await import('@/hooks/useLiveStock');
        const { result } = renderHook(() => useLiveStock('/pos/stock-feed', [{ id: 1, qty: 50, stock_status: 'متوفر' }], { firstMs: 1e9, intervalMs: 1e9 }));

        expect(result.current.products[0].qty).toBe(50);
        expect(result.current.updatedAt).toBeNull();
    });
});

/* ═══════════════ الشاشةُ لا تُفتح قبل أصنافها ═══════════════ */

const catalogState = vi.hoisted(() => ({ rows: null as unknown[] | null, status: 'loading' as string, off: false }));
vi.mock('@/hooks/useTillCatalog', async (real) => {
    const actual = (await real()) as Record<string, unknown>;

    return {
        ...actual,
        default: (url: string, scope: string, opts: unknown) =>
            (globalThis as Record<string, unknown>).__realCatalog
                ? (actual.default as (...a: unknown[]) => unknown)(url, scope, opts)
                : { rows: catalogState.rows, status: catalogState.status, source: 'device', stale: false, saved: 'yes', scopeChanged: false, off: catalogState.off, refresh: vi.fn(async () => {}) },
    };
});
vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

describe('صفحةُ الصندوق والكتالوجُ على الجهاز', () => {
    const exempt = { ...row(1, { name: 'باقة بلا ضريبة', label: 'باقة بلا ضريبة', price: 35.664, tax: 0 }) };
    const taxed = { ...row(2, { name: 'باقة بخمسة', label: 'باقة بخمسة', price: 19.898, tax: 5 }) };
    const open = async () => {
        catalogState.off = false;
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            resumeCart: {
                id: 7, customer: null, coupon_code: null,
                items: [
                    { id: 1, name: 'باقة بلا ضريبة', price: 35.664, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null },
                    { id: 2, name: 'باقة بخمسة', price: 19.898, qty: 1, variant_id: null, variant_name: null, season_id: null, note: '', addons: [], custom: null },
                ],
            },
            translations: {},
            catalog: { scope: SCOPE },
            stock: { full: true, v: 's1', b: 1, h: H('0'), scope: '7:3', products: [{ id: 1, qty: 4, stock_status: 'منخفض' }, { id: 2, qty: 9, stock_status: 'متوفر' }], updated_at: '10:00:00' },
            categories: [{ value: 'الكل', label: 'الكل' }],
            seasons: [],
            customers: [],
            addons: [],
            coupons: [],
            settings: { vat: { enabled: true, rate: 5, inclusive: false } },
            customOrder: { templates: [] },
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });
        localStorage.clear();
        const { default: PosIndex } = await import('@/Pages/Pos/Index');

        return render(<PosIndex />);
    };

    it('لا تُركَّب الشاشةُ قبل الأصناف — فالبندُ المعفى المستأنف يبقى معفًى', async () => {
        catalogState.rows = null;
        catalogState.status = 'loading';
        const view = await open();

        expect(screen.getByTestId('catalog-loading')).toBeTruthy();
        expect(screen.queryByTestId('pos-paybar')).toBeNull();

        catalogState.rows = [exempt, taxed];
        catalogState.status = 'ready';
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        view.rerender(<PosIndex />);

        // ‏‏35.664 + 19.898 + 5% من 19.898 وحدها = 56.557 — لا 5% من المجموع (58.340)
        await waitFor(() => expect(screen.getByTestId('pos-paybar').textContent).toContain('56.557'));
    });

    it('وكميّاتُ الفرع من الصفحة لا من يومِ حُفظ الكتالوج', async () => {
        catalogState.rows = [exempt, taxed];
        catalogState.status = 'ready';
        const view = await open();

        const card = [...view.container.querySelectorAll('section button.group')].find((c) => c.querySelector('h3')?.textContent === 'باقة بلا ضريبة');
        expect(card?.textContent).toMatch(/المتوفر:\s*4/);
    });

    it('تعذّر الكتالوجُ وليس في الجهاز شيء: يُقال ذلك، وتُطلب الأصنافُ مع الصفحة كما كانت', async () => {
        catalogState.rows = null;
        catalogState.status = 'failed';
        const { router } = await import('@inertiajs/react');
        (router.reload as ReturnType<typeof vi.fn>).mockClear();
        Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => true });
        await open();

        expect(screen.getByTestId('catalog-failed')).toBeTruthy();
        expect(router.reload).toHaveBeenCalledWith({ only: ['products'] });
    });

    it('أُطفئ والصندوقُ مفتوح: تُطلب أصنافُ الخادم مرّةً، والشاشةُ هي هي بسلّتها — ثمّ تُعرض أصنافُ الخادم', async () => {
        catalogState.rows = [exempt, taxed];
        catalogState.status = 'ready';
        const { router } = await import('@inertiajs/react');
        (router.reload as ReturnType<typeof vi.fn>).mockClear();
        Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => true });
        const view = await open();
        await waitFor(() => expect(screen.getByTestId('pos-paybar').textContent).toContain('56.557'));
        const bar = screen.getByTestId('pos-paybar');

        catalogState.off = true;
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        view.rerender(<PosIndex />);
        await waitFor(() => expect(router.reload).toHaveBeenCalledWith({ only: ['products'] }));
        expect(router.reload).toHaveBeenCalledTimes(1);
        view.rerender(<PosIndex />);
        expect(router.reload).toHaveBeenCalledTimes(1);

        // ‏‏وتصل أصنافُ الخادم — كما كانت الصفحةُ تحملها
        (pageProps as Record<string, unknown>).products = [{ ...exempt, name: 'باقة الخادم', label: 'باقة الخادم' }, taxed];
        view.rerender(<PosIndex />);
        await waitFor(() => expect(view.container.textContent).toContain('باقة الخادم'));

        // ‏‏الشاشةُ لم تُركَّب من جديد، والسلّةُ المستأنفةُ بمبلغها
        expect(screen.getByTestId('pos-paybar')).toBe(bar);
        expect(bar.textContent).toContain('56.557');
    });

    it('أُطفئ والاتّصالُ مقطوعٌ تلك اللحظة: لا يُطلب شيء — ويُطلب مرّةً حين يعود', async () => {
        catalogState.rows = [exempt, taxed];
        catalogState.status = 'ready';
        const { router } = await import('@inertiajs/react');
        (router.reload as ReturnType<typeof vi.fn>).mockClear();
        let online = false;
        Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => online });
        const view = await open();
        catalogState.off = true;
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        view.rerender(<PosIndex />);
        await new Promise((r) => setTimeout(r, 20));
        expect(router.reload).not.toHaveBeenCalled();
        // ‏‏وما يُعرض نسخةُ الجهاز المُتحقَّقُ منها — الشاشةُ لا تُغلق
        expect(screen.getByTestId('pos-paybar').textContent).toContain('56.557');

        online = true;
        await act(async () => window.dispatchEvent(new Event('online')));
        await waitFor(() => expect(router.reload).toHaveBeenCalledWith({ only: ['products'] }));
        expect(router.reload).toHaveBeenCalledTimes(1);
    });
});

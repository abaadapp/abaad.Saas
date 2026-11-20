import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import useLiveStock, { applyStock, type StockReply, type StockRow, type StockSnapshot } from '@/hooks/useLiveStock';

/**
 * ‏‏مخزونُ الصندوق: ما تغيّر وحده — والصنفُ الذي لم يتغيّر يبقى هو هو.
 *
 * ═══ ما كان ═══
 *
 *   • كلُّ سؤالٍ يأتي بالأصناف كلِّها، ويُبنى منها كائنٌ جديدٌ لكلّ صنف: تُرسم
 *     البطاقاتُ الظاهرة كلُّها ليتغيّر رقمٌ في واحدة، أو لا يتغيّر شيء.
 *   • وبعد كلّ بيعةٍ تُعاد قائمةُ الأصناف كلُّها من الخادم (`router.reload`).
 *
 * ═══ ما صار ═══
 *
 * الصندوقُ يحفظ ما أعطاه الخادمُ ويُعيد بصماته (`App\Support\TillStock`)، ويُطبّق
 * السلالَ التي تغيّرت وحدها، ولا يُطبّق جوابًا على غير ما سأل عنه.
 */

// ‏‏خادمٌ بقواعد `TillStock::answer` — والبصمةُ هنا أيُّ دالّةٍ ثابتة: الصندوقُ لا يحسبها، يُعيدها
const B = 4;
const fnv = (s: string) => {
    let h = 0x811c9dc5;
    for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619) >>> 0;

    return h.toString(16).padStart(8, '0');
};
const status = (q: number) => (q <= 0 ? 'نفد المخزون' : q < 5 ? 'منخفض' : 'متوفر');
let stock: Map<number, number>;
let replies: ('ok' | 'offline' | 'scope' | 'late')[];
let asked: { v?: string; h?: string; scope?: string }[];
let held: (() => void) | null;

function serverRows(): StockRow[] {
    return [...stock.entries()].sort(([a], [z]) => a - z).map(([id, qty]) => ({ id, qty, stock_status: status(qty) }));
}
function answer(known: { v?: string; b?: number; h?: string }): StockReply {
    const rows = serverRows();
    const parts = Array.from({ length: B }, () => '');
    for (const r of rows) parts[r.id % B] += `${r.id}:${r.qty}:${r.stock_status};`;
    const h = parts.map((p) => (fnv(p) + fnv(`x${p}`)).slice(0, 16));
    const v = fnv(parts.join('|')).repeat(4);
    if (known.v === v) return { v, b: B, same: true };
    const theirs = known.h?.match(/.{16}/g);
    if (!known.v || known.b !== B || !theirs || theirs.length !== B) return { v, b: B, full: true, h: h.join(''), products: rows };
    const changed = h.map((x, i) => (x !== theirs[i] ? i : -1)).filter((i) => i >= 0);
    if (!changed.length) return { v, b: B, full: true, h: h.join(''), products: rows };

    return { v, b: B, base: known.v, buckets: changed.map((i) => [i, h[i]]), products: rows.filter((r) => changed.includes(r.id % B)) };
}

const product = (id: number, qty: number) => ({ id, name: `صنف ${id}`, qty, stock_status: status(qty) });
const shelf = () => Array.from({ length: 12 }, (_, k) => product(k + 1, 20));

beforeEach(() => {
    stock = new Map(Array.from({ length: 12 }, (_, k) => [k + 1, 20]));
    replies = [];
    asked = [];
    held = null;
    vi.stubGlobal(
        'fetch',
        vi.fn(async (_url: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}'));
            asked.push(body);
            const reply = replies.shift() ?? 'ok';
            if (reply === 'offline') throw new TypeError('Failed to fetch');
            if (reply === 'scope') return { ok: false, status: 409, json: async () => ({ scope_changed: true, scope: '9:9' }) };
            // ‏‏يُحسب الجوابُ الآن ويُسلَّم لاحقًا — كجوابٍ تأخّر في الطريق
            const d = { ...answer(body), scope: '1:1', updated_at: '10:00:00' };
            if (reply === 'late') await new Promise<void>((ok) => (held = ok));

            return { ok: true, status: 200, json: async () => d };
        }),
    );
});
afterEach(() => vi.unstubAllGlobals());

const mount = (initial = shelf()) =>
    renderHook(({ list }) => useLiveStock('/pos/stock-feed', list, { scope: '1:1', intervalMs: 1e9, firstMs: 1e9 }), { initialProps: { list: initial } });

describe('applyStock — ما يفعله الصندوقُ بالجواب', () => {
    const full = (): StockSnapshot => {
        const d = answer({});
        const r = applyStock(null, d);
        if (r === 'stale') throw new Error('full rejected');

        return r.snap;
    };

    it('سلّةٌ تغيّرت تُستبدل كلُّها — وما حُذف منها يُحذف', () => {
        const s = full();
        stock.set(5, 3);
        stock.delete(9); // ‏‏‏9 و5 في السلّة نفسِها (9 % 4 = 1 = 5 % 4)
        const r = applyStock(s, answer({ v: s.v, b: s.b, h: s.h.join('') }));
        if (r === 'stale') throw new Error('stale');

        expect(r.snap.rows.get(5)).toEqual({ id: 5, qty: 3, stock_status: 'منخفض' });
        expect(r.snap.rows.has(9)).toBe(false);
        expect([...r.snap.rows.values()].sort((a, z) => a.id - z.id)).toEqual(serverRows());
        // ‏‏وما سُلّم لا يُمسّ: جوابٌ لم يُطبَّق لا يترك أثرًا
        expect(s.rows.get(5)?.qty).toBe(20);
        expect(s.rows.has(9)).toBe(true);
    });

    it('جوابٌ على غير ما عند الصندوق يُطرح — ولا يُكتب القديمُ فوق الأحدث', () => {
        const s = full();
        stock.set(2, 1);
        const old = answer({ v: s.v, b: s.b, h: s.h.join('') });
        const moved = applyStock(s, old);
        if (moved === 'stale') throw new Error('stale');

        expect(applyStock(moved.snap, old)).toBe('stale');
        expect(applyStock(null, old)).toBe('stale');
        expect(applyStock(moved.snap, { ...old, b: 8, base: moved.snap.v })).toBe('stale');
        expect(applyStock(s, { ...old, products: [...(old.products ?? []), { id: 4, qty: 0, stock_status: 'x' }] })).toBe('stale');
        expect(applyStock(moved.snap, { v: 'other', same: true })).toBe('stale');
        expect(applyStock(null, { v: 'x', b: 4, full: true, h: 'short', products: [] })).toBe('stale');
    });
});

describe('useLiveStock — ما تغيّر وحده', () => {
    it('أوّلُ سؤالٍ بلا بصمة، ثمّ بالبصمات — ولا يتغيّر شيءٌ حين لا يتغيّر شيء', async () => {
        const { result } = mount();
        await act(() => result.current.refresh());
        const first = result.current.products;

        expect(asked[0].v).toBeUndefined();
        expect(asked[0].scope).toBe('1:1');
        await act(() => result.current.refresh());

        expect(asked[1].v).toMatch(/^[0-9a-f]{32}$/);
        expect(asked[1].h).toHaveLength(B * 16);
        expect(result.current.products).toBe(first);
        expect(result.current.updatedAt).toBe('10:00:00');
    });

    it('صنفٌ تغيّر: كائنُه وحده جديد — وما عداه هو هو، فلا تُرسم بطاقتُه', async () => {
        const list = shelf();
        const { result } = mount(list);
        await act(() => result.current.refresh());
        stock.set(6, 2);
        await act(() => result.current.refresh());

        const now = result.current.products;
        expect(now[5]).toEqual({ ...list[5], qty: 2, stock_status: 'منخفض' });
        expect(now.filter((p, i) => p !== list[i]).map((p) => p.id)).toEqual([6]);
    });

    it('جوابٌ تأخّر بعد أن أُجيب ما بعده لا يُطبَّق — يُسأل من جديد', async () => {
        const { result } = mount();
        await act(() => result.current.refresh());
        stock.set(3, 4);
        replies = ['late'];
        const slow = result.current.refresh();
        await waitFor(() => expect(held).not.toBeNull());
        // ‏‏وأثناءه تتغيّر الكميّة ثانيةً، ويُطلب تحديثٌ آخر — يُصفّ خلف الأوّل
        stock.set(3, 9);
        void result.current.refresh();
        await act(async () => {
            held?.();
            await slow;
        });
        await waitFor(() => expect(result.current.products[2].qty).toBe(9));

        expect(asked).toHaveLength(3);
    });

    it('انقطع الاتّصال: تبقى الكميّاتُ الأخيرة، ويُقال إنّ آخر تحديثٍ لم يصل', async () => {
        const { result } = mount();
        stock.set(1, 0);
        await act(() => result.current.refresh());
        replies = ['offline'];
        stock.set(1, 15);
        await act(() => result.current.refresh());

        expect(result.current.products[0].qty).toBe(0);
        expect(result.current.stale).toBe(true);
        await act(() => result.current.refresh());
        expect(result.current.products[0].qty).toBe(15);
        expect(result.current.stale).toBe(false);
    });

    it('فُتح متجرٌ أو فرعٌ آخر في الجلسة: لا تُطبَّق كميّاتُه، ولا يُسأل بعدها', async () => {
        const { result } = mount();
        await act(() => result.current.refresh());
        replies = ['scope'];
        stock.set(1, 0);
        await act(() => result.current.refresh());
        await act(() => result.current.refresh());

        expect(result.current.scopeChanged).toBe(true);
        expect(result.current.products[0].qty).toBe(20);
        expect(asked).toHaveLength(2);
    });

    it('أصنافُ الصفحة الجديدة هي المرجع حتى يُسأل بعدها', async () => {
        const { result, rerender } = mount();
        stock.set(4, 1);
        await act(() => result.current.refresh());
        expect(result.current.products[3].qty).toBe(1);

        const fresh = shelf().map((p) => (p.id === 4 ? { ...p, qty: 30, stock_status: 'متوفر' } : p));
        rerender({ list: fresh });
        expect(result.current.products).toBe(fresh);

        await act(() => result.current.refresh());
        expect(result.current.products[3].qty).toBe(1);
    });
});

describe('الصندوقُ بعد البيع', () => {
    it('يسأل عن المخزون بما تغيّر — لا يُعيد قائمةَ الأصناف كلَّها', async () => {
        const fs = await import('node:fs');
        const page = fs.readFileSync('resources/js/Pages/Pos/Index.tsx', 'utf8');

        // ‏‏بعد البيع يُسأل المخزونُ والكتالوجُ عمّا تغيّر — ولا تُعاد الأصنافُ كلُّها
        const onSynced = page.match(/onSynced: \(\) => \{[\s\S]*?\n {8}\},/)?.[0] ?? '';
        expect(onSynced).toMatch(/void liveStock\.refresh\?\.\(\)/);
        expect(onSynced).toMatch(/void catalog\?\.refresh\(\)/);
        expect(onSynced).not.toMatch(/router\.reload/);
        // ‏‏والإعادةُ الكاملة بابٌ واحد: الكتالوجُ تعذّر وليس في الجهاز شيء (`CatalogGate`)
        expect(page.match(/router\.reload\(\{\s*only:\s*\['products'\]/g)).toHaveLength(1);
        expect(page).toMatch(/if \(!failed \|\| fallback \|\| askedPage\.current[^\n]*\n[^\n]*\n\s*router\.reload\(\{ only: \['products'\] \}\)/);
        // ‏‏ونصُّ البحث من أصناف الصفحة لا من كميّاتها: تحديثُ المخزون لا يُعيد بناءه
        expect(page).toMatch(/const haystacks = useMemo\(\(\) => serverProducts\.map/);
    });
});

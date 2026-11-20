import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { csrfHeaders } from '@/lib/csrf';

/** ما يعيده pos.stock-feed لكل منتج — الكمية وحالتها لا غير */
export interface StockRow {
    id: number;
    qty: number;
    stock_status: string;
}

/** الحد الأدنى المطلوب من المنتج حتى يُدمج معه المخزون الحيّ */
interface HasStock {
    id: number;
    qty: number;
    stock_status: string;
}

/** ‏‏جوابُ الخادم — انظر `App\Support\TillStock::answer` */
export interface StockReply {
    v?: string;
    b?: number;
    scope?: string;
    same?: boolean;
    full?: boolean;
    /** ‏‏بصماتُ السلال كلُّها متّصلة (16 حرفًا لكلٍّ) — مع `full` */
    h?: string;
    /** ‏‏بصمةُ ما سُئل عنه — مع السلال */
    base?: string;
    /** ‏[رقمُ السلّة، بصمتُها الجديدة] — وأصنافُها كلُّها في `products` */
    buckets?: [number, string][];
    products?: StockRow[];
    updated_at?: string;
}

/** ‏ما يعرفه الصندوقُ من المخزون — ما أعطاه الخادمُ آخرَ مرّة، لا غير */
export interface StockSnapshot {
    v: string;
    b: number;
    h: string[];
    rows: Map<number, StockRow>;
}

const HASH = 16;

/**
 * ‏‏يُطبَّق الجوابُ على ما عند الصندوق — أو يُطرح.
 *
 *   - `same`: لا تغيير.
 *   - `full`: يحلّ محلّ ما عنده كلِّه.
 *   - سلال: تُستبدل كلُّ سلّةٍ بما أُرسل منها — فما حُذف منها يُحذف — بشرط أن
 *     يكون الجوابُ على ما عنده الآن (`base`)، وإلّا فـ`stale`: سؤالٌ تأخّر
 *     جوابُه بعد أن تغيّر ما عنده، فلا يُكتب جوابٌ قديم فوق أحدث منه.
 *
 * ولا تُمسّ النسخة المعطاة: يُعاد غيرُها، فما لم يُطبَّق لا يترك أثرًا.
 */
export function applyStock(s: StockSnapshot | null, d: StockReply): { snap: StockSnapshot; changed: boolean } | 'stale' {
    if (d.same) return s && d.v === s.v ? { snap: s, changed: false } : 'stale';
    if (typeof d.v !== 'string' || typeof d.b !== 'number' || !Array.isArray(d.products)) return 'stale';
    if (d.full) {
        const h = typeof d.h === 'string' ? (d.h.match(new RegExp(`.{${HASH}}`, 'g')) ?? []) : [];
        if (h.length !== d.b) return 'stale';

        return { snap: { v: d.v, b: d.b, h, rows: new Map(d.products.map((r) => [r.id, r])) }, changed: true };
    }
    if (!s || d.base !== s.v || d.b !== s.b || !Array.isArray(d.buckets)) return 'stale';
    const touched = new Set(d.buckets.map(([i]) => i));
    if (d.products.some((r) => !touched.has(r.id % s.b))) return 'stale';
    const rows = new Map(s.rows);
    for (const id of s.rows.keys()) if (touched.has(id % s.b)) rows.delete(id);
    for (const r of d.products) rows.set(r.id, r);
    const h = [...s.h];
    for (const [i, hash] of d.buckets) h[i] = hash;

    return { snap: { v: d.v, b: s.b, h, rows }, changed: true };
}

/**
 * مخزون حيّ لشاشة البيع.
 *
 * بيع زميله على جهاز آخر — أو تعديل المخزون من اللوحة، أو استلام أمر شراء —
 * كان يبقى خفيًّا حتى تُحدَّث الصفحة. فيَعِد الزبون بصنف نفد ثم يُرفض عند
 * الدفع. هذا الاستطلاع يُغلق تلك الفجوة.
 *
 * ═══ وما تغيّر وحده ═══
 *
 * كان كلُّ سؤالٍ يأتي بالأصناف كلِّها (709KB عند عشرة آلاف صنف)، ويبني الصندوقُ
 * من جديد كلَّ صنفٍ عنده: تُرسم البطاقاتُ الظاهرة كلُّها ويُعاد نصُّ البحث، ولم
 * يتغيّر شيء. فصار يحفظ ما أعطاه الخادم (`StockSnapshot`) ويُعيد بصماته، فيأتيه
 * ما تغيّر وحده (`App\Support\TillStock`). والصنفُ الذي لم تتغيّر كميّتُه يبقى
 * هو هو، فلا تُرسم بطاقتُه.
 *
 * والبيعُ لا ينتظر شيئًا من هذا: الخادمُ يحرس الكميّة عند الدفع كما كان.
 *
 * قواعد:
 * 1. يتوقف عند إخفاء التبويب، ويسأل حين يعود ويعود الاتصال.
 *    وأوّلُ سؤالٍ بعد الفتح بلحظة (`firstMs`) لا بعد عشرين ثانية: هو وحده يأتي
 *    بالقائمة كاملة، فإن سبقته بيعةٌ صار سؤالُها بعدها القائمةَ كاملة.
 * 2. بيانات الخادم مع كل تنقّل هي المرجع: لا تُفرض عليها اللقطةُ حتى يُسأل بعدها.
 * 3. المنتج الذي لا يرد في التغذية يبقى بكميته الأخيرة لا بصفر.
 * 4. سؤالٌ واحد في وقت واحد، وجوابٌ لا يُطابق ما عند الصندوق يُطرح.
 * 5. صندوقٌ فُتح لمتجرٍ أو فرعٍ غيرِ ما في الجلسة (بُدّل في تبويبٍ آخر) لا
 *    تُعرض عليه كميّاتُ غيره (`scopeChanged`).
 *
 * ويعيد وقت آخر استطلاع: رقمٌ يتحرّك بلا أن يُعرف عمرُه يُقرأ على أنه
 * لحظيّ، فيَعِد الكاشير الزبونَ بصنف نفد قبل عشرين ثانية. و`stale` حين لم
 * يصل السؤالُ الأخير: الأرقامُ أقدمُ ممّا يُظنّ.
 */
export default function useLiveStock<T extends HasStock>(
    url: string,
    products: T[],
    { scope = null, intervalMs = 20000, firstMs = 1500 }: { scope?: string | null; intervalMs?: number; firstMs?: number } = {},
) {
    const snap = useRef<StockSnapshot | null>(null);
    const [version, setVersion] = useState(0);
    // ‏‏اللقطةُ أحدثُ من أصناف الصفحة — تُفرض عليها
    const [overlay, setOverlay] = useState(false);
    const [updatedAt, setUpdatedAt] = useState<string | null>(null);
    const [stale, setStale] = useState(false);
    const [scopeChanged, setScopeChanged] = useState(false);
    const busy = useRef(false);
    const again = useRef(false);
    const lost = useRef(false);
    const epoch = useRef(0);

    // كل تنقّل يجلب منتجات جديدة من الخادم — أحدث مما استطلعناه
    useEffect(() => {
        epoch.current++;
        setOverlay(false);
        setUpdatedAt(null);
    }, [products]);

    const poll = useCallback(async (): Promise<void> => {
        if (lost.current || (typeof document !== 'undefined' && document.hidden)) return;
        if (busy.current) {
            again.current = true;

            return;
        }
        busy.current = true;
        const asked = epoch.current;
        const s = snap.current;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify(s ? { v: s.v, b: s.b, h: s.h.join(''), scope } : { scope }),
            });
            if (res.status === 409) {
                const d = (await res.json().catch(() => null)) as { scope_changed?: boolean } | null;
                if (d?.scope_changed) {
                    lost.current = true;
                    setScopeChanged(true);
                }

                return;
            }
            if (!res.ok) {
                setStale(true);

                return;
            }
            const d = (await res.json()) as StockReply;
            if (scope && d.scope && d.scope !== scope) {
                lost.current = true;
                setScopeChanged(true);

                return;
            }
            const next = applyStock(snap.current, d);
            if (next === 'stale') {
                // ‏‏لا يُعرف أساسُه — يُسأل من جديد بلا بصمة، فيأتي الكلّ
                snap.current = null;
                again.current = true;

                return;
            }
            snap.current = next.snap;
            setStale(false);
            if (d.updated_at) setUpdatedAt(d.updated_at);
            if (next.changed) setVersion((n) => n + 1);
            // ‏‏وأصنافُ الصفحة تغيّرت أثناء السؤال: لا يُعرف أيُّهما أحدث — يُسأل ثانيةً
            if (asked === epoch.current) setOverlay(true);
            else again.current = true;
        } catch {
            // انقطاع عابر — المحاولة التالية بعد الفترة نفسها، واللقطة الأخيرة تبقى
            setStale(true);
        } finally {
            busy.current = false;
            if (again.current) {
                again.current = false;
                void poll();
            }
        }
    }, [url, scope]);

    useEffect(() => {
        const first = setTimeout(() => void poll(), firstMs);
        const id = setInterval(() => void poll(), intervalMs);
        const wake = () => {
            if (!document.hidden) void poll();
        };
        document.addEventListener('visibilitychange', wake);
        window.addEventListener('online', wake);

        return () => {
            clearTimeout(first);
            clearInterval(id);
            document.removeEventListener('visibilitychange', wake);
            window.removeEventListener('online', wake);
        };
    }, [poll, intervalMs, firstMs]);

    /*
     * ‏‏والصنفُ الذي لم تتغيّر كميّتُه يبقى هو هو — نفسَ الكائن.
     *
     * البطاقةُ محفوظة (`memo`) بصنفها: كائنٌ جديدٌ لكلّ صنفٍ في كلّ سؤال كان
     * يرسم البطاقاتِ الظاهرة كلَّها ليغيّر رقمًا في واحدة.
     */
    const made = useRef(new WeakMap<T, T>());
    const last = useRef<T[]>(products);
    const merged = useMemo(() => {
        const rows = overlay ? snap.current?.rows : undefined;
        if (!rows) return (last.current = products);
        let same = last.current.length === products.length;
        const out = products.map((p, i) => {
            const r = rows.get(p.id);
            let m: T = p;
            if (r && (r.qty !== p.qty || r.stock_status !== p.stock_status)) {
                const had = made.current.get(p);
                m = had && had.qty === r.qty && had.stock_status === r.stock_status ? had : { ...p, qty: r.qty, stock_status: r.stock_status };
                made.current.set(p, m);
            }
            if (same && last.current[i] !== m) same = false;

            return m;
        });

        return same ? last.current : (last.current = out);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [products, overlay, version]);

    return { products: merged, updatedAt, refresh: poll, stale, scopeChanged };
}

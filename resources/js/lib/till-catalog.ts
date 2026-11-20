/**
 * ═══ كتالوجُ الصندوق على الجهاز — ما يفعله الجهازُ بجواب الخادم ═══
 *
 * الخادمُ (`App\Support\TillCatalog`) يقسم الأصنافَ سلالًا بمعرّفاتها، ولكلّ سلّةٍ
 * بصمتان: لمُدخلاتها (`ih`) ولصفّها المبنيّ (`oh`)، ومعهما رمزٌ موقَّع (`t`) يقول
 * ما فُحص ومتى. والجهازُ يحفظ ذلك كلَّه ويُعيده مع سؤاله، فيُجاب بأحد ثلاثة:
 *
 *   - **`same`** — ما عنده هو ما في القاعدة (ومعه رمزٌ يُحفظ).
 *   - **سلال** (`base` + `buckets` + `rows`) — ما تغيّرت بصمتُه، وأصنافُ ما تغيّر
 *     صفُّه منها كاملةً: تُستبدل السلّةُ كلُّها، فما حُذف منها يُحذف.
 *   - **`full`** — الكتالوجُ كلُّه بعدده وبصماته.
 *
 * ولا يُطبَّق جوابٌ لا يُطابق ما عند الجهاز الآن (`base`)، ولا جوابٌ ناقصٌ أو
 * مشوَّه: يُطرح كلُّه (`stale`) ويبقى ما عند الجهاز كما هو، ثمّ يُسأل من جديد.
 * فلا يُكتب قديمٌ فوق أحدث، ولا تُعدّ نسخةٌ ناقصةٌ كاملة.
 *
 * والكميّةُ ليست من الكتالوج: لها تغذيتُها (`useLiveStock`)، فيُعرض فوق ما هنا.
 */
import type { TillProductRow } from '@/lib/till-products';

/** ‏طولُ بصمة السلّة (`TillCatalog::HASH`) */
export const HASH = 16;

/** ‏أكثرُ السلال (`TillCatalog::MAX_BUCKETS`) */
export const MAX_BUCKETS = 256;

/** ‏ما يحفظه الجهازُ من الكتالوج — ما أعطاه الخادمُ آخرَ مرّة، لا غير */
export interface CatalogSnapshot {
    /** ‏المتجرُ والفرعُ واللغةُ وبنيةُ الكتالوج — كما قالها الخادم */
    scope: string;
    b: number;
    ih: string[];
    oh: string[];
    /** ‏بصمةُ النسخة كاملةً — يُطابَق بها الجوابُ (`base`) */
    v: string;
    /** ‏رمزُ الخادم — يُعاد معه */
    t: string;
    rows: Map<number, TillProductRow>;
}

/** ‏جوابُ الخادم — انظر `TillCatalog::answer` */
export interface CatalogReply {
    scope?: string;
    v?: string;
    t?: string;
    same?: boolean;
    full?: boolean;
    b?: number;
    ih?: string;
    oh?: string;
    count?: number;
    base?: string;
    /** ‏[السلّة، بصمةُ مُدخلاتها، بصمةُ صفّها] — لكلّ سلّةٍ تغيّرت بصمتُها */
    buckets?: [number, string, string][];
    /** ‏السلالُ التي جاءت أصنافُها — تُستبدل كاملة */
    rows?: number[];
    products?: TillProductRow[];
}

const isHash = (h: unknown): h is string => typeof h === 'string' && h.length === HASH && /^[0-9a-f]+$/.test(h);
const isBuckets = (b: unknown): b is number => typeof b === 'number' && Number.isInteger(b) && b >= 1 && b <= MAX_BUCKETS && (b & (b - 1)) === 0;
const isId = (id: unknown): id is number => typeof id === 'number' && Number.isInteger(id) && id > 0;

/** ‏بصماتٌ متّصلة بعدد السلال — أو `null` */
function split(h: unknown, b: number): string[] | null {
    if (typeof h !== 'string' || h.length !== b * HASH || !/^[0-9a-f]*$/.test(h)) return null;
    const out: string[] = [];
    for (let i = 0; i < b; i++) out.push(h.slice(i * HASH, (i + 1) * HASH));

    return out;
}

/**
 * ‏صفٌّ جاء من الخادم وهو هو ما عند الجهاز — يبقى الكائنَ نفسَه.
 *
 * السلّةُ تُرسل كلُّها وقد تغيّر فيها صنفٌ واحد: والبطاقةُ محفوظةٌ بصنفها
 * (`memo`)، فكائنٌ جديدٌ لصنفٍ لم يتغيّر يعيد رسمَها بلا سبب.
 */
function keep(old: TillProductRow | undefined, fresh: TillProductRow): TillProductRow {
    return old !== undefined && JSON.stringify(old) === JSON.stringify(fresh) ? old : fresh;
}

/**
 * ‏‏يُطبَّق الجوابُ على ما عند الجهاز — أو يُطرح (`stale`).
 *
 * لا تُمسّ النسخةُ المعطاة: يُعاد غيرُها، فما لم يُطبَّق لا يترك أثرًا. و`changed`
 * السلالُ التي يجب أن تُكتب في الجهاز (`'all'` الكلّ)، و`moved` هل تغيّر صفٌّ واحدٌ
 * على الأقلّ — فلا تُعاد قائمةُ الأصناف لجوابٍ لم يغيّر شيئًا يُعرض.
 */
export function applyCatalog(
    s: CatalogSnapshot | null,
    d: CatalogReply,
): { snap: CatalogSnapshot; changed: number[] | 'all'; moved: boolean } | 'stale' {
    if (typeof d.v !== 'string' || typeof d.t !== 'string' || !d.t) return 'stale';

    if (d.same) {
        return s && d.v === s.v ? { snap: { ...s, t: d.t }, changed: [], moved: false } : 'stale';
    }

    if (!isBuckets(d.b) || !Array.isArray(d.products)) return 'stale';

    if (d.full) {
        const ih = split(d.ih, d.b);
        const oh = split(d.oh, d.b);
        if (!ih || !oh || d.count !== d.products.length || typeof d.scope !== 'string') return 'stale';
        const rows = new Map<number, TillProductRow>();
        for (const r of d.products) {
            if (!isId(r?.id) || rows.has(r.id)) return 'stale';
            rows.set(r.id, keep(s?.rows.get(r.id), r));
        }

        return { snap: { scope: d.scope, b: d.b, ih, oh, v: d.v, t: d.t, rows }, changed: 'all', moved: true };
    }

    if (!s || d.base !== s.v || d.b !== s.b || !Array.isArray(d.buckets) || !Array.isArray(d.rows)) return 'stale';

    const declared = new Set<number>();
    for (const entry of d.buckets) {
        if (!Array.isArray(entry) || entry.length !== 3) return 'stale';
        const [i, a, o] = entry;
        if (!Number.isInteger(i) || i < 0 || i >= s.b || declared.has(i) || !isHash(a) || !isHash(o)) return 'stale';
        declared.add(i);
    }
    const replaced = new Set<number>();
    for (const i of d.rows) {
        if (!declared.has(i)) return 'stale';
        replaced.add(i);
    }

    const rows = new Map(s.rows);
    for (const id of s.rows.keys()) if (replaced.has(id % s.b)) rows.delete(id);
    let moved = false;
    const seen = new Set<number>();
    for (const r of d.products) {
        if (!isId(r?.id) || !replaced.has(r.id % s.b) || seen.has(r.id)) return 'stale';
        seen.add(r.id);
        const kept = keep(s.rows.get(r.id), r);
        if (kept !== s.rows.get(r.id)) moved = true;
        rows.set(r.id, kept);
    }
    // ‏‏وصنفٌ كان في سلّةٍ استُبدلت ولم يعد فيها — حُذف أو أُخرج
    if (!moved) for (const id of s.rows.keys()) if (replaced.has(id % s.b) && !rows.has(id)) moved = true;

    const ih = [...s.ih];
    const oh = [...s.oh];
    for (const [i, a, o] of d.buckets) {
        ih[i] = a;
        oh[i] = o;
    }

    return { snap: { scope: s.scope, b: s.b, ih, oh, v: d.v, t: d.t, rows }, changed: [...declared].sort((x, y) => x - y), moved };
}

/** ‏ما يُرسله الجهازُ مع سؤاله — بصماتُه ورمزُه، أو لا شيء لجهازٍ لا يحفظ */
export function askWith(scope: string, s: CatalogSnapshot | null, deep = false): Record<string, unknown> {
    return {
        scope,
        ...(s ? { t: s.t, b: s.b, ih: s.ih.join(''), oh: s.oh.join('') } : {}),
        ...(deep ? { deep: true } : {}),
    };
}

/** ‏أصنافُ سلّةٍ واحدة — لتُكتب في الجهاز */
export function bucketRows(s: CatalogSnapshot, bucket: number): TillProductRow[] {
    const out: TillProductRow[] = [];
    for (const r of s.rows.values()) if (r.id % s.b === bucket) out.push(r);

    return out.sort((a, z) => a.id - z.id);
}

/** ‏الأصنافُ مرتّبةً بالمعرّف — ترتيبُ صفحة الصندوق نفسُه */
export function orderedRows(s: CatalogSnapshot): TillProductRow[] {
    return [...s.rows.values()].sort((a, z) => a.id - z.id);
}

/** ‏رأسُ النسخة في الجهاز — يُكتب مع سلالها في معاملةٍ واحدة */
export interface StoredHead {
    scope: string;
    /** ‏بنيةُ ما في الجهاز — غيرُ هذا الرقم يُمحى ويُنزَّل من جديد */
    schema: number;
    b: number;
    v: string;
    t: string;
    count: number;
    /** ‏رقمُ هذه الكتابة — تبويبٌ آخر كتب بعده يُعرف به */
    rev: string;
}

export interface StoredBucket {
    scope: string;
    bucket: number;
    ih: string;
    oh: string;
    rows: TillProductRow[];
}

export const SCHEMA = 1;

/**
 * ‏‏نسخةُ الجهاز كما قُرئت — أو `null` إن لم تكن كاملةً سليمة.
 *
 * رأسٌ لهذا المفتاح بهذه البنية، وسلالٌ بعدد سلاله لا تنقص واحدةً ولا تتكرّر،
 * وكلُّ صنفٍ في سلّته (`id % b`) مرّةً واحدة، وعددُها عددُ الرأس، وبصماتٌ بشكلها.
 * وما سوى ذلك تالف: يُطرح كلُّه ويُنزَّل من جديد — لا يُعرض نصفُه.
 */
export function snapshotOf(scope: string, head: unknown, buckets: unknown): CatalogSnapshot | null {
    const h = head as Partial<StoredHead> | null;
    if (!h || h.scope !== scope || h.schema !== SCHEMA || !isBuckets(h.b) || typeof h.v !== 'string' || typeof h.t !== 'string' || !Number.isInteger(h.count)) {
        return null;
    }
    if (!Array.isArray(buckets) || buckets.length !== h.b) return null;

    const ih: string[] = new Array(h.b);
    const oh: string[] = new Array(h.b);
    const rows = new Map<number, TillProductRow>();
    for (const raw of buckets as Partial<StoredBucket>[]) {
        const i = raw?.bucket;
        if (raw?.scope !== scope || !Number.isInteger(i) || i! < 0 || i! >= h.b || ih[i!] !== undefined) return null;
        if (!isHash(raw.ih) || !isHash(raw.oh) || !Array.isArray(raw.rows)) return null;
        ih[i!] = raw.ih;
        oh[i!] = raw.oh;
        for (const r of raw.rows) {
            if (!isId(r?.id) || r.id % h.b !== i || rows.has(r.id) || typeof r.name !== 'string' || typeof r.price !== 'number') return null;
            rows.set(r.id, r);
        }
    }
    if (rows.size !== h.count) return null;

    return { scope, b: h.b, ih, oh, v: h.v, t: h.t, rows };
}

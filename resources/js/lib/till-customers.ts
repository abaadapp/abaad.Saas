/**
 * زبائنُ الصندوق في الجهاز — يُحفظون ويُزامَنون بما تغيّر، لا يُنزَّلون كلَّ مرّة.
 *
 * ═══ كيف ═══
 *
 * الخادمُ يقسم الزبائن دلاءً بالمعرّف (`TillCustomers::BUCKETS`)، ولكلّ دلوٍ
 * بصمةُ ما فيه كما يُرسَل. والجهازُ يحفظ كلَّ دلوٍ ببصمته (IndexedDB)، ويرسل
 * بصماته عند فتح الصندوق، فيعود إليه ما تغيّرت بصمتُه وحده، ومعه خريطةُ الدلاء
 * كلِّها: دلوٌ غاب منها لم يبقَ فيه أحد فيُمحى. فالجديدُ والمعدَّلُ والمحذوف
 * يصلون — والفرقُ بالمحتوى نفسِه، لا بتاريخ تعديلٍ قد لا يتحرّك.
 *
 * ═══ ولمن ═══
 *
 * كلُّ ما في الجهاز يحمل متجرَه (`scope`). ويُمحى ما لغير المتجر الذي فُتح
 * صندوقُه قبل أن يُقرأ شيء، وما يعود من الخادم لمتجرٍ غيرِ المنتظَر يُرمى
 * ولا يُكتب. وطابورُ المبيعات المعلّقة لا يُمسّ هنا: يبقى حيث هو (localStorage).
 *
 * ═══ وحين لا يكون IndexedDB ═══
 *
 * (نافذةٌ خاصّة، أو مساحةٌ ممتلئة، أو متصفّحٌ يمنعه) — لا نسخة: الصندوقُ يطلب
 * القائمةَ كاملةً كما كان، ولا يتعطّل شيء.
 */
import type { PosCustomer } from '@/hooks/usePosCart';
import { csrfHeaders } from '@/lib/csrf';

/** صفُّ الزبون كما يرسله الخادم — الفارغُ غائب */
export interface TillRow {
    id: number;
    name: string;
    name_en?: string;
    phone?: string;
    points?: number;
    language?: string;
    context?: PosCustomer['context'];
}

export interface CachedBucket {
    scope: number;
    bucket: number;
    hash: string;
    rows: TillRow[];
}

export interface SyncResponse {
    business: number;
    buckets: Record<string, string>;
    rows: Record<string, TillRow[]>;
    count: number;
}

/** صفُّ الخادم بشكل `PosCustomer` — `label` هو الاسمُ كما كُتب (`Customers::asEntered`) */
export function toPosCustomer(row: TillRow): PosCustomer {
    return {
        id: row.id,
        name: row.name,
        name_en: row.name_en ?? null,
        label: row.name,
        phone: row.phone ?? '',
        points: row.points ?? 0,
        language: row.language ?? null,
        ...(row.context ? { context: row.context } : {}),
    };
}

/** ما يحفظه الجهاز من بصمات — يُرسَل للخادم */
export function haveOf(cached: Map<number, CachedBucket>): Record<number, string> {
    const have: Record<number, string> = {};
    for (const [bucket, entry] of cached) have[bucket] = entry.hash;
    return have;
}

/**
 * الدلاءُ بعد ردّ الخادم — وما يُكتب وما يُمحى من الجهاز.
 *
 * خريطةُ الخادم هي الحقيقة: دلوٌ فيها بصمتُه بصمةُ الجهاز يبقى كما حُفظ، ودلوٌ
 * جاءت صفوفُه يُستبدل، ودلوٌ ليس فيها يُمحى. ودلوٌ في الخريطة لم تأتِ صفوفُه
 * والجهازُ لا يعرفه أو يعرفه ببصمةٍ أخرى — لا يقع من خادمٍ سليم — فيُعدّ
 * ناقصًا، ولا يُوهَم أنّه محفوظ.
 */
export function merge(
    scope: number,
    cached: Map<number, CachedBucket>,
    res: SyncResponse,
): { next: Map<number, CachedBucket>; put: CachedBucket[]; drop: number[]; complete: boolean } {
    const next = new Map<number, CachedBucket>();
    const put: CachedBucket[] = [];
    let complete = true;

    for (const [key, hash] of Object.entries(res.buckets)) {
        const bucket = Number(key);
        const rows = res.rows[key];

        if (rows) {
            const entry = { scope, bucket, hash, rows };
            next.set(bucket, entry);
            put.push(entry);
        } else if (cached.get(bucket)?.hash === hash) {
            next.set(bucket, cached.get(bucket)!);
        } else {
            complete = false;
        }
    }

    const drop = [...cached.keys()].filter((bucket) => !next.has(bucket));

    return { next, put, drop, complete };
}

/** الزبائنُ مرتّبين بالمعرّف — ترتيبُ الخادم نفسُه، فأوّلُ خمسين في القائمة هم أنفسُهم */
export function flatten(buckets: Map<number, CachedBucket>): TillRow[] {
    return [...buckets.values()].flatMap((b) => b.rows).sort((a, b) => a.id - b.id);
}

/* ═══════════════ IndexedDB ═══════════════ */

const DB_NAME = 'abaad-pos';
const DB_VERSION = 1;
const STORE = 'tillCustomers';

function request<T>(req: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function done(tx: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

function open(): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        try {
            if (typeof indexedDB === 'undefined') return resolve(null);
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                // ‏الإصدار ١: مخزنٌ واحد بمفتاح [المتجر، الدلو] — لا بيانات قبله تُنقل
                if (!req.result.objectStoreNames.contains(STORE)) {
                    req.result.createObjectStore(STORE, { keyPath: ['scope', 'bucket'] });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
            req.onblocked = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
}

/**
 * نسخةُ هذا المتجر — وما لغيره يُمحى قبل أن يُقرأ.
 *
 * و`null` حين لا نسخةَ تُحفظ أصلًا (لا IndexedDB): الصندوقُ يطلب الكلّ.
 */
export async function loadCache(scope: number): Promise<Map<number, CachedBucket> | null> {
    const db = await open();
    if (!db) return null;

    try {
        const all = await request(db.transaction(STORE, 'readonly').objectStore(STORE).getAll() as IDBRequest<CachedBucket[]>);
        const mine = new Map<number, CachedBucket>();
        const foreign: IDBValidKey[] = [];

        for (const entry of all) {
            if (entry.scope === scope && Array.isArray(entry.rows) && typeof entry.hash === 'string') {
                mine.set(entry.bucket, entry);
            } else {
                foreign.push([entry.scope, entry.bucket]);
            }
        }

        if (foreign.length) {
            const tx = db.transaction(STORE, 'readwrite');
            for (const key of foreign) tx.objectStore(STORE).delete(key);
            await done(tx);
        }

        return mine;
    } catch {
        return new Map();
    } finally {
        db.close();
    }
}

async function write(put: CachedBucket[], drop: Array<[number, number]>): Promise<void> {
    const db = await open();
    if (!db) return;

    try {
        const tx = db.transaction(STORE, 'readwrite');
        const store = tx.objectStore(STORE);
        for (const entry of put) store.put(entry);
        for (const key of drop) store.delete(key);
        await done(tx);
    } catch {
        /* مساحةٌ ممتلئة أو جهازٌ يرفض — النسخةُ تُبنى من جديد في المرّة القادمة */
    } finally {
        db.close();
    }
}

/** يمحو نسخ الزبائن كلَّها من الجهاز — لمتجرٍ آخر دخل، أو لمن خرج من حسابٍ لا متجرَ له */
export async function clearCache(): Promise<void> {
    const db = await open();
    if (!db) return;

    try {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).clear();
        await done(tx);
    } catch {
        /* لا شيء يُفعل */
    } finally {
        db.close();
    }
}

/* ═══════════════ المزامنة ═══════════════ */

/**
 * يسأل الخادم عمّا تغيّر — ويكتب الجواب في الجهاز، ويردّ الزبائن كلَّهم.
 *
 * يرمي حين لا جواب (لا اتّصال، أو خطأ في الخادم): الصندوقُ يبقى على نسخته
 * ويعيد السؤال حين يعود الاتّصال. وجوابٌ لمتجرٍ غيرِ المنتظَر يُرمى ولا يُكتب.
 */
export async function syncCustomers(
    scope: number,
    cached: Map<number, CachedBucket> | null,
    fetcher: typeof fetch = fetch,
): Promise<{ rows: TillRow[]; complete: boolean; changed: number; dropped: number }> {
    const base = cached ?? new Map<number, CachedBucket>();
    const res = await fetcher('/pos/till-customers/sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
        credentials: 'same-origin',
        body: JSON.stringify({ have: haveOf(base) }),
    });

    if (!res.ok) throw new Error(`till customers: ${res.status}`);

    const data = (await res.json()) as SyncResponse;

    if (data.business !== scope || typeof data.buckets !== 'object' || typeof data.rows !== 'object') {
        throw new Error('till customers: answer for another shop');
    }

    const { next, put, drop, complete } = merge(scope, base, data);

    if (cached) {
        await write(put, drop.map((bucket) => [scope, bucket] as [number, number]));
    }

    return { rows: flatten(next), complete, changed: put.length, dropped: drop.length };
}

/** صفُّ عميلٍ كما هو الآن — `null` لمن حُذف أو ليس من المتجر، ويرمي حين لا اتّصال */
export async function freshCustomer(id: number, fetcher: typeof fetch = fetch, timeoutMs = 4000): Promise<TillRow | null> {
    const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = ctrl ? setTimeout(() => ctrl.abort(), timeoutMs) : null;

    try {
        const res = await fetcher(`/pos/till-customers/${id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: ctrl?.signal,
        });
        if (res.status === 404) return null;
        if (!res.ok) throw new Error(`till customer: ${res.status}`);

        return ((await res.json()) as { customer: TillRow }).customer;
    } finally {
        if (timer) clearTimeout(timer);
    }
}

/**
 * ما في الجهاز لغير متجر الجالس يُمحى — عند أوّل صفحةٍ وكلّما تبدّل المتجر.
 *
 * الصندوقُ يمحوه حين يُفتح (`loadCache`)، وهذا لمن دخل حسابًا آخر ولم يفتح
 * صندوقًا: لا تبقى زبائنُ متجرٍ سابق في الجهاز بعد أن جلس عليه غيرُه. وصفحةُ
 * الدخول (لا جالس) لا تمحو شيئًا: قفلُ الصندوق يعود إليها، والكاشيرُ التالي
 * من المتجر نفسِه لا ينزّل القائمةَ من جديد.
 */
let lastShop: number | null | undefined;

export function forgetOtherShops(props: Record<string, unknown>, keep: (scope: number | null) => Promise<void> = keepOnly): void {
    const user = (props.auth as { user?: { businessId?: number | null } } | null | undefined)?.user;
    if (!user) return;

    const shop = user.businessId ?? null;
    if (shop === lastShop) return;
    lastShop = shop;

    void keep(shop).catch(() => {
        /* لا IndexedDB — لا شيء محفوظٌ أصلًا */
    });
}

/** يُبقي نسخةَ متجرٍ واحد — و`null` (حسابٌ بلا متجر) لا يُبقي شيئًا */
export async function keepOnly(scope: number | null): Promise<void> {
    const db = await open();
    if (!db) return;

    try {
        const keys = (await request(db.transaction(STORE, 'readonly').objectStore(STORE).getAllKeys())) as Array<[number, number]>;
        const foreign = keys.filter((k) => Array.isArray(k) && k[0] !== scope);

        if (foreign.length) {
            const tx = db.transaction(STORE, 'readwrite');
            for (const key of foreign) tx.objectStore(STORE).delete(key);
            await done(tx);
        }
    } finally {
        db.close();
    }
}

/** للاختبارات — يُنسى المتجرُ الأخير فيُفحص من جديد */
export function resetForgetOtherShops(): void {
    lastShop = undefined;
}

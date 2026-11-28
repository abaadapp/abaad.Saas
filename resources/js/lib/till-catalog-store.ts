/**
 * ═══ مخزنُ كتالوج الصندوق في الجهاز (IndexedDB) ═══
 *
 * قاعدةٌ وحدَها (`abaad-pos-catalog`) — لا مخزنُ الزبائن (`abaad-pos`) ولا طابورُ
 * المبيعات المعلّقة (localStorage). فمحوُها أو إعادةُ بنائها أو تلفُها لا يمسّ
 * بيعةً ولا زبونًا: أسوأُ ما يكون أن يُنزَّل الكتالوجُ من الخادم كما كان.
 *
 * ═══ ما يُحفظ ═══
 *
 *   - **رأسٌ لكلّ مفتاح** (`heads`): المتجرُ والفرعُ واللغةُ وبنيةُ الكتالوج، وعددُ
 *     السلال وبصمةُ النسخة ورمزُ الخادم وعددُ الأصناف، ورقمُ آخر كتابة (`rev`).
 *   - **سلالُه** (`buckets`): لكلّ سلّةٍ أصنافُها وبصمتاها معًا.
 *
 * والرأسُ والسلالُ تُكتب في معاملةٍ واحدة: تُكتب كلُّها أو لا يُكتب شيء. فلا رأسَ
 * بلا سلاله، ولا سلّةَ ببصمةٍ ليست لأصنافها. وتبويبٌ آخر كتب بعد ما قرأه هذا
 * التبويب (`rev` تغيّر) تُكتب فوقه نسخةُ هذا التبويب كاملةً لا سلالُه وحدها —
 * فلا تختلط سلالُ نسختين ورمزُ إحداهما.
 *
 * ═══ وحين لا يكون IndexedDB، أو يمتلئ، أو يتلف ═══
 *
 * لا يُرمى شيءٌ إلى الصندوق: `unavailable` (نافذةٌ خاصّة، متصفّحٌ يمنعه) أو
 * `failed` (مساحةٌ ممتلئة، كتابةٌ رُفضت) — فيعمل الصندوقُ من الخادم كما كان.
 * والنسخةُ التالفة تُمحى ويُنزَّل غيرُها؛ والقاعدةُ التي لا تُفتح تُمحى مرّةً.
 */
import { SCHEMA, bucketRows, snapshotOf, type CatalogSnapshot, type StoredBucket, type StoredHead } from '@/lib/till-catalog';

export const DB_NAME = 'abaad-pos-catalog';
const DB_VERSION = 1;
const HEADS = 'heads';
const BUCKETS = 'buckets';

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
        tx.onabort = () => reject(tx.error ?? new DOMException('aborted', 'AbortError'));
    });
}

function openOnce(): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        try {
            if (typeof indexedDB === 'undefined') return resolve(null);
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(HEADS)) db.createObjectStore(HEADS, { keyPath: 'scope' });
                if (!db.objectStoreNames.contains(BUCKETS)) db.createObjectStore(BUCKETS, { keyPath: ['scope', 'bucket'] });
            };
            req.onsuccess = () => {
                const db = req.result;
                // ‏‏تبويبٌ أحدث يرقّي القاعدة: يُغلق هذا الاتّصال ولا يحجزه
                db.onversionchange = () => db.close();
                resolve(db);
            };
            req.onerror = () => resolve(null);
            req.onblocked = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
}

/**
 * ‏‏القاعدة — أو `null` حين لا تكون.
 *
 * وقاعدةٌ فُتحت بلا مخزنَيها (تلفٌ أو إصدارٌ غريب) تُمحى مرّةً وتُفتح من جديد:
 * ما فيها كتالوجٌ يُنزَّل ثانيةً، لا بيعات.
 */
async function open(): Promise<IDBDatabase | null> {
    const db = await openOnce();
    if (!db || (db.objectStoreNames.contains(HEADS) && db.objectStoreNames.contains(BUCKETS))) return db;
    db.close();
    await wipe();

    return openOnce();
}

/** ‏‏يمحو قاعدةَ الكتالوج كلَّها — وحدَها؛ ولا يمسّ `abaad-pos` ولا localStorage */
export function wipe(): Promise<void> {
    return new Promise((resolve) => {
        try {
            const req = indexedDB.deleteDatabase(DB_NAME);
            req.onsuccess = req.onerror = req.onblocked = () => resolve();
        } catch {
            resolve();
        }
    });
}

const range = (scope: string) => IDBKeyRange.bound([scope, 0], [scope, Number.MAX_SAFE_INTEGER]);

export type Loaded = { snap: CatalogSnapshot; rev: string } | null | 'unavailable';

/**
 * ‏‏نسخةُ هذا المفتاح — كاملةً سليمةً، أو `null`.
 *
 * والتالفةُ (`snapshotOf`) تُمحى هنا قبل أن تُعاد: لا تُعرض، ولا تبقى لتُقرأ ثانيةً.
 */
export async function load(scope: string): Promise<Loaded> {
    const db = await open();
    if (!db) return 'unavailable';

    try {
        const tx = db.transaction([HEADS, BUCKETS], 'readonly');
        const [head, buckets] = await Promise.all([
            request(tx.objectStore(HEADS).get(scope)) as Promise<StoredHead | undefined>,
            request(tx.objectStore(BUCKETS).getAll(range(scope))) as Promise<StoredBucket[]>,
        ]);
        if (!head) return null;
        const snap = snapshotOf(scope, head, buckets);
        if (snap) return { snap, rev: head.rev };
    } catch {
        /* ‏قراءةٌ رُفضت — تُعامَل كنسخةٍ تالفة */
    } finally {
        db.close();
    }

    await drop(scope);

    return null;
}

const newRev = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

export type Saved = { rev: string } | 'failed' | 'unavailable';

/**
 * ‏‏يكتب النسخةَ في الجهاز — رأسَها وما تغيّر من سلالها، في معاملةٍ واحدة.
 *
 * `rev` رقمُ الكتابة التي بُنيت عليها هذه النسخة. فإن كتب تبويبٌ آخر بعدها كُتبت
 * هذه كاملةً فوقه (السلالُ كلُّها) — فيبقى في الجهاز نسخةٌ واحدةٌ متّسقة، أيًّا
 * كانت. وتُعاد `failed` حين لا تُكتب (مساحةٌ ممتلئة): وما في الجهاز قبلها باقٍ.
 */
export async function save(snap: CatalogSnapshot, rev: string | null, changed: number[] | 'all'): Promise<Saved> {
    const db = await open();
    if (!db) return 'unavailable';

    try {
        const tx = db.transaction([HEADS, BUCKETS], 'readwrite');
        const finished = done(tx);
        const heads = tx.objectStore(HEADS);
        const buckets = tx.objectStore(BUCKETS);
        const current = (await request(heads.get(snap.scope))) as StoredHead | undefined;
        /*
         * ‏‏ما في الجهاز هو هذه النسخةُ بعينها (بصمتُها بصمتُه) — كتبها تبويبٌ آخر:
         * لا تُعاد سلالُها، يُكتب الرأسُ برمزه. وإلّا فإن كتب غيرُ هذا التبويب بعد
         * ما بُنيت عليه تُكتب كاملة، وإلّا فما تغيّر منها وحده.
         */
        const same = current?.b === snap.b && current?.v === snap.v && current?.schema === SCHEMA;
        const whole = !same && (changed === 'all' || (current?.rev ?? null) !== rev || current?.b !== snap.b);
        if (whole) buckets.delete(range(snap.scope));
        for (const i of same ? [] : whole ? snap.ih.map((_, k) => k) : (changed as number[])) {
            buckets.put({ scope: snap.scope, bucket: i, ih: snap.ih[i], oh: snap.oh[i], rows: bucketRows(snap, i) } satisfies StoredBucket);
        }
        const next = newRev();
        heads.put({ scope: snap.scope, schema: SCHEMA, b: snap.b, v: snap.v, t: snap.t, count: snap.rows.size, rev: next } satisfies StoredHead);
        await finished;

        return { rev: next };
    } catch {
        return 'failed';
    } finally {
        db.close();
    }
}

/** ‏‏يمحو نسخةَ مفتاحٍ واحد — رأسَه وسلالَه معًا */
export async function drop(scope: string): Promise<void> {
    const db = await open();
    if (!db) return;

    try {
        const tx = db.transaction([HEADS, BUCKETS], 'readwrite');
        tx.objectStore(HEADS).delete(scope);
        tx.objectStore(BUCKETS).delete(range(scope));
        await done(tx);
    } catch {
        /* ‏تُمحى في المرّة القادمة — أو تُقرأ تالفةً فتُمحى */
    } finally {
        db.close();
    }
}

/**
 * ‏‏يُبقي نسخةَ هذا المفتاح وحدها — بعد أن كُتبت كاملة.
 *
 * نسخٌ بمفتاحٍ آخر (فرعٌ أو لغةٌ أخرى، أو بنيةُ كتالوجٍ أقدم بعد ترقية) لا تُقرأ
 * هنا، وكلٌّ منها بحجم الكتالوج كلِّه. فالجهازُ يحمل نسخةً واحدة: نسخةَ ما يفتحه.
 */
export async function keepOnly(scope: string): Promise<void> {
    const db = await open();
    if (!db) return;

    try {
        const read = db.transaction([HEADS, BUCKETS], 'readonly');
        const [heads, buckets] = await Promise.all([
            request(read.objectStore(HEADS).getAllKeys()) as Promise<string[]>,
            request(read.objectStore(BUCKETS).getAllKeys()) as Promise<[string, number][]>,
        ]);
        const others = [...new Set([...heads.map(String), ...buckets.map((k) => String(k?.[0]))])].filter((s) => s !== scope);
        if (others.length) {
            const tx = db.transaction([HEADS, BUCKETS], 'readwrite');
            for (const s of others) {
                tx.objectStore(HEADS).delete(s);
                tx.objectStore(BUCKETS).delete(range(s));
            }
            await done(tx);
        }
    } catch {
        /* ‏تُمحى في المرّة القادمة */
    } finally {
        db.close();
    }
}

/**
 * ‏‏ما في الجهاز لغير متجر الجالس يُمحى — عند أوّل صفحةٍ وكلّما تبدّل المتجر.
 *
 * كما تُمحى زبائنُ متجرٍ سابق (`forgetOtherShops` في `till-customers-store.ts`):
 * لا يبقى كتالوجُ متجرٍ في جهازٍ جلس عليه غيرُه. وصفحةُ الدخول (لا جالس) لا تمحو
 * شيئًا: الكاشيرُ التالي من المتجر نفسِه لا ينزّل الكتالوجَ من جديد. ونسخُ
 * المتجر نفسِه بفروعه ولغاته تبقى — ولا تُقرأ إلّا بمفتاحها.
 */
let lastShop: number | null | undefined;

export function forgetOtherCatalogs(props: Record<string, unknown>, keep: (shop: number | null) => Promise<void> = keepShop): void {
    const user = (props.auth as { user?: { businessId?: number | null } } | null | undefined)?.user;
    if (!user) return;

    const shop = user.businessId ?? null;
    if (shop === lastShop) return;
    lastShop = shop;

    void keep(shop).catch(() => {
        /* لا IndexedDB — لا شيء محفوظٌ أصلًا */
    });
}

/** ‏يُبقي نسخَ متجرٍ واحد — و`null` (حسابٌ بلا متجر) لا يُبقي شيئًا */
export async function keepShop(shop: number | null): Promise<void> {
    if (typeof indexedDB === 'undefined') return;
    const db = await open();
    if (!db) return;

    try {
        const read = db.transaction([HEADS, BUCKETS], 'readonly');
        const [heads, buckets] = await Promise.all([
            request(read.objectStore(HEADS).getAllKeys()) as Promise<string[]>,
            // ‏‏وسلالٌ بلا رأس — لا تقع من كتابةٍ سليمة، ولا تُترك إن وقعت
            request(read.objectStore(BUCKETS).getAllKeys()) as Promise<[string, number][]>,
        ]);
        const scopes = new Set([...heads.map(String), ...buckets.map((k) => String(k?.[0]))]);
        const foreign = [...scopes].filter((s) => shop === null || !s.startsWith(`${shop}:`));
        if (foreign.length) {
            const tx = db.transaction([HEADS, BUCKETS], 'readwrite');
            for (const s of foreign) {
                tx.objectStore(HEADS).delete(s);
                tx.objectStore(BUCKETS).delete(range(s));
            }
            await done(tx);
        }
    } finally {
        db.close();
    }
}

/** ‏للاختبارات — يُنسى المتجرُ الأخير فيُفحص من جديد */
export function resetForgetOtherCatalogs(): void {
    lastShop = undefined;
}

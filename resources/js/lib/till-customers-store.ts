/**
 * مخزنُ نسخة زبائن الصندوق في الجهاز (IndexedDB) — وما يُمحى منه لمتجرٍ آخر.
 *
 * منفصلٌ عن المزامنة (`lib/till-customers.ts`) لأنّ كلَّ صفحةٍ تحتاجه: يمحو نسخةَ
 * متجرٍ سابق حين يجلس على الجهاز غيرُه، ولو لم يفتح صندوقًا. فيبقى صغيرًا في
 * الحزمة الأولى، والمزامنةُ تُحمَّل مع الصندوق وحده.
 */
/* ═══════════════ IndexedDB ═══════════════ */

export const DB_NAME = 'abaad-pos';
export const DB_VERSION = 1;
export const STORE = 'tillCustomers';

export function request<T>(req: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

export function done(tx: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

export function open(): Promise<IDBDatabase | null> {
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

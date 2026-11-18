/**
 * ═══ أين تُحفظ البيعةُ المعلّقة على الجهاز ═══
 *
 * كلُّ بيعةٍ في مفتاحها: `abadpos:pos:outbox:<المعرّف>`. تُكتب مرّةً وتُقرأ بعد
 * كتابتها، ولا تُحذف إلّا بمعرّفها حين يقول الخادمُ إنّه كتبها.
 *
 * ═══ لماذا لا قائمةٌ واحدة ═══
 *
 * كان الطابورُ قائمةً في مفتاحٍ واحد (`abadpos:pos:outbox`) يقرؤها كلُّ تبويبٍ
 * من ذاكرته ثمّ يكتبها كاملة. والمتصفّحُ ينقل الكتابةَ بين التبويبات بعد لحظة
 * لا في الحال: فبيعةٌ يكتبها تبويبٌ والآخرُ يُنهي إرسالَ غيرها تمحوها نسخةُ
 * الآخر القديمة. والمفتاحُ لكلّ بيعة لا يكتبه إلّا صاحبُه، ولا يُمحى بنسخة غيره.
 *
 * ═══ والقائمةُ القديمة نسخةٌ لا أصل ═══
 *
 * تُكتب فيها البيعةُ بعد مفتاحها (`v: 2`)، وتُحذف منها معه — لنسخة أبعاد الأقدم
 * إن رجع إليها الجهاز، فترى ما عليه وتُرسله. ونسخةٌ فيها بلا مفتاحٍ لها:
 *   • بعلامة `v: 2` — كُتبت هنا ثمّ أكّدها الخادمُ وحُذف مفتاحُها، وأعادها تبويبٌ
 *     بنسخته القديمة. فلا تُقرأ (الخادمُ كتبها).
 *   • بلا علامة — كتبتها نسخةٌ أقدم من أبعاد، فتُقرأ وتُرسل كما كانت.
 * ولا يُرحَّل شيءٌ ولا يُمسح: ما كان على الجهاز يبقى حتى يؤكّده الخادم.
 *
 * ═══ وما لا يحفظه المتصفّح ═══
 *
 * مساحةٌ ممتلئة ترمي خطأً، ومتصفّحٌ قد يقبل الكتابةَ ولا يحفظها. فالكتابةُ تُقرأ
 * بعدها، و`false` تعني: لم تُحفظ — والصندوقُ يقول ذلك ولا يقول «حُفظت».
 */
import type { OutboxEntry } from '@/lib/outbox';

export const LEGACY_KEY = 'abadpos:pos:outbox';
export const ENTRY_PREFIX = `${LEGACY_KEY}:`;
const PROBE_KEY = 'abadpos:pos:probe';

/** ‏هل يخصّ هذا المفتاحُ الطابور — لأحداث `storage` من تبويبٍ آخر (`null`: مُسح التخزين كلُّه) */
export function isQueueKey(key: string | null): boolean {
    return key === null || key === LEGACY_KEY || key.startsWith(ENTRY_PREFIX);
}

function store(): Storage | null {
    try {
        return typeof window !== 'undefined' ? window.localStorage : null;
    } catch {
        // ‏متصفّحٌ يمنع التخزين كلَّه (إعدادُ خصوصيّة) — لا طابور، والبيعُ المتّصل يعمل
        return null;
    }
}

function valid(e: unknown): e is OutboxEntry {
    return !!e && typeof e === 'object' && typeof (e as OutboxEntry).uuid === 'string' && !!(e as OutboxEntry).payload && typeof (e as OutboxEntry).payload === 'object';
}

function parse(raw: string | null): OutboxEntry | null {
    if (!raw) return null;
    try {
        const e = JSON.parse(raw) as unknown;

        return valid(e) ? e : null;
    } catch {
        return null;
    }
}

/** ‏القائمةُ القديمة كما هي — وما لا يُقرأ منها يبقى فيها ولا يُرمى */
function legacyRaw(s: Storage): unknown[] {
    try {
        const list = JSON.parse(s.getItem(LEGACY_KEY) || '[]') as unknown;

        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

/** ‏تُعدَّل نسخةُ القائمة القديمة — وفشلُها لا يمسّ البيعة: أصلُها في مفتاحها */
function mirror(s: Storage, change: (list: unknown[]) => unknown[]): void {
    try {
        const next = change(legacyRaw(s));
        if (next.length) s.setItem(LEGACY_KEY, JSON.stringify(next));
        else s.removeItem(LEGACY_KEY);
    } catch {
        /* مساحةٌ ممتلئة: النسخةُ تتأخّر، والبيعةُ في مفتاحها */
    }
}

const sameUuid = (uuid: string) => (x: unknown) => valid(x) && x.uuid === uuid;

/** ‏كلُّ ما على الجهاز بترتيب إتمامه — مفاتيحُ البيعات، وما في القائمة القديمة بلا مفتاح */
export function readAll(): OutboxEntry[] {
    const s = store();
    if (!s) return [];
    const own = new Map<string, OutboxEntry>();
    try {
        for (let i = 0; i < s.length; i++) {
            const key = s.key(i);
            if (!key?.startsWith(ENTRY_PREFIX)) continue;
            const e = parse(s.getItem(key));
            if (e) own.set(e.uuid, e);
        }
    } catch {
        /* لا يُقرأ — ما قُرئ يكفي */
    }
    const out: OutboxEntry[] = [];
    const seen = new Set<string>();
    for (const e of legacyRaw(s)) {
        if (!valid(e) || seen.has(e.uuid)) continue;
        seen.add(e.uuid);
        const mine = own.get(e.uuid);
        if (mine) out.push(mine);
        else if (e.v !== 2) out.push(e);
    }
    for (const [uuid, e] of own) if (!seen.has(uuid)) out.push(e);

    return out.sort((a, b) => (a.at ?? 0) - (b.at ?? 0));
}

/** ‏بيعةٌ واحدة بمعرّفها — من مفتاحها، أو من القائمة القديمة إن كتبتها نسخةٌ أقدم */
export function get(uuid: string): OutboxEntry | null {
    const s = store();
    if (!s) return null;
    try {
        const mine = parse(s.getItem(ENTRY_PREFIX + uuid));
        if (mine) return mine;
    } catch {
        return null;
    }
    const old = legacyRaw(s).find(sameUuid(uuid));

    return valid(old) && old.v !== 2 ? old : null;
}

/** ‏تُكتب في مفتاحها وتُقرأ بعدها — `false`: لم تُحفظ */
function write(s: Storage, entry: OutboxEntry): boolean {
    const json = JSON.stringify(entry);
    try {
        s.setItem(ENTRY_PREFIX + entry.uuid, json);

        return s.getItem(ENTRY_PREFIX + entry.uuid) === json;
    } catch {
        return false;
    }
}

/** ‏بيعةٌ جديدة على الجهاز — `false`: لم تُحفظ، فلا تُقال «حُفظت» */
export function add(entry: OutboxEntry): boolean {
    const s = store();
    if (!s) return false;
    const e: OutboxEntry = { ...entry, v: 2 };
    if (!write(s, e)) return false;
    mirror(s, (list) => [...list.filter((x) => !sameUuid(e.uuid)(x)), e]);

    return true;
}

/**
 * ‏حالُ بيعةٍ تغيّر (ردّها الخادم، فشلت محاولة…) — ما دامت على الجهاز.
 *
 * بيعةٌ حذفها تبويبٌ آخر بعد أن أكّدها الخادم لا تُعاد. ونسخةٌ أقدم في القائمة
 * القديمة تصير بالكتابة في مفتاحها. و`false`: بقيت كما كانت — لم تُحذف.
 */
export function replace(uuid: string, next: OutboxEntry): boolean {
    const s = store();
    if (!s || !get(uuid)) return false;
    const e: OutboxEntry = { ...next, uuid, v: 2 };
    if (!write(s, e)) return false;
    mirror(s, (list) => list.map((x) => (sameUuid(uuid)(x) ? e : x)));

    return true;
}

/** ‏كتبها الخادم: تُحذف من مفتاحها ومن القائمة القديمة */
export function drop(uuid: string): void {
    const s = store();
    if (!s) return;
    try {
        s.removeItem(ENTRY_PREFIX + uuid);
    } catch {
        /* لا يُحذف — يُعاد إرسالها فيردّ الخادمُ فاتورتَها الأولى */
    }
    mirror(s, (list) => list.filter((x) => !sameUuid(uuid)(x)));
}

export type Room = 'ok' | 'low' | 'full';

/**
 * ‏كم بقي في مساحة الجهاز — بكتابةٍ تُجرَّب ثمّ تُحذف.
 *
 * `navigator.storage.estimate()` لا يَعُدّ هذا التخزين في كلّ متصفّح، فالتجربةُ
 * هي المقياسُ الصادق. نحو ٦٤ ألف حرف تسع عشرات البيعات (`low` دونها)، ونحو ٤
 * آلاف تسع بيعةً واحدة (`full` دونها).
 */
export function room(): Room {
    const s = store();
    if (!s) return 'full';
    const fits = (size: number) => {
        try {
            s.setItem(PROBE_KEY, 'x'.repeat(size));
            s.removeItem(PROBE_KEY);

            return true;
        } catch {
            try {
                s.removeItem(PROBE_KEY);
            } catch {
                /* لا شيء */
            }

            return false;
        }
    };
    if (fits(64 * 1024)) return 'ok';

    return fits(4 * 1024) ? 'low' : 'full';
}

/**
 * ‏يُطلب من المتصفّح ألّا يمحو تخزينَ الموقع حين تضيق مساحةُ الجهاز.
 *
 * `true` أُذن، و`false` لم يُؤذن، و`null` متصفّحٌ لا يعرفه. وليس وعدًا: مسحُ
 * المستخدم بياناتِ المتصفّح أو تلفُ الجهاز يمحوها كلَّها.
 */
export async function keep(): Promise<boolean | null> {
    const st = typeof navigator !== 'undefined' ? navigator.storage : undefined;
    if (!st?.persisted) return null;
    try {
        if (await st.persisted()) return true;

        return st.persist ? await st.persist() : false;
    } catch {
        return null;
    }
}

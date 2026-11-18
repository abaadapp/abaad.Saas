/**
 * ═══ طابورُ البيعات المعلّقة — قواعدُه بلا شاشة ═══
 *
 * بيعةٌ في الطابور اكتملت عند الصندوق وقُبض ثمنُها. فقواعدُه ثلاث لا تُكسر:
 *   ١. لا تُحذف إلّا حين يقول الخادمُ إنّه كتبها (ردٌّ ناجح، أو سجلُّه بمعرّفها).
 *   ٢. تُرسل بمعرّفها `client_uuid` نفسِه أبدًا — فإن كان كتبها ردّ فاتورتَها
 *      الأولى ولم يكتبها ثانيةً (`orders_business_client_uuid_unique`).
 *   ٣. لا تُغيَّر حمولتُها: المبلغُ والأصنافُ والخصمُ كما قُبضت.
 *
 * والطابورُ نفسُه في `localStorage`، كلُّ بيعةٍ في مفتاحها (`lib/outbox-store.ts`):
 * الكتابةُ فيه متزامنة، فبيعةٌ يُغلق بعدها المتصفّحُ فورًا محفوظة. وكلُّ حقلٍ
 * هنا اختياريّ — فطابورٌ كُتب قبل هذا الملفّ يُقرأ كما هو ولا يُعاد تهيئتُه.
 */

export interface OutboxEntry {
    uuid: string;
    payload: Record<string, unknown>;
    at: number;
    /** متجرُ البيعة — تُرسل من صندوقه وحده (انظر `ownEntry` في `usePosCart`) */
    business?: number;
    /**
     * ‏ردّها الخادمُ لسببٍ في بياناتها (422) أو لتعارض (409) — بعد أن سُئل سجلُّه
     * فلم يجدها. تبقى بسببها، ولا تُعاد وحدَها: السببُ لا يزول بالانتظار.
     * و`fields` أسماءُ الحقول المردودة (`items`، `coupon_code`…) — منها يُعرف السبب.
     */
    held?: { status: number; error: string; at: number; fields?: string[] };
    /** محاولاتٌ فشلت بخطأ خادمٍ في هذه البيعة وحدها (500…) — لا بانقطاع */
    attempts?: number;
    /** لا تُعاد آليًّا قبل هذا الوقت */
    nextAt?: number;
    /** نتيجةُ آخر محاولة — تُعرض للكاشير */
    last?: { status: number | null; error: string; at: number };
    /** تكرّر خطأُ الخادم حتى الحدّ: تنتظر «أعد الإرسال» باليد، ولا توقف ما خلفها */
    stuck?: boolean;
    /**
     * ‏آخرُ ما قاله سجلُّ الخادم عنها (`/pos/outbox/status`): `found: false` سُئل
     * فلم يجدها. وغيابُها: لم يُسأل بعد، أو لم يُجب.
     */
    checked?: { at: number; found: boolean };
    /** ‏كُتبت في مفتاحها (`lib/outbox-store.ts`) — ونسختُها في القائمة القديمة نسخةٌ لا أصل */
    v?: 2;
}

/** ما يعنيه الردّ للبيعة — لا رقمُه */
export type Outcome =
    | 'ok'
    /** ‏401/419: الجلسةُ انتهت — تبقى حتى الدخول */
    | 'session'
    /** ‏403: هذا المستخدمُ أو هذا الجهازُ لا يملك البيع — تبقى حتى مستخدمٍ مخوّل */
    | 'forbidden'
    /** ‏422: ردٌّ لسببٍ في البيانات */
    | 'refused'
    /** ‏409: تعارضٌ — يُسأل سجلُّ الخادم قبل أن يُحكم */
    | 'conflict'
    /** ‏500 وما في حكمه: خطأٌ في هذه البيعة وحدها، يُعاد بتأخيرٍ متزايد */
    | 'server'
    /** ‏429/502/503/504، والبابُ غائب (404/405): الخادمُ كلُّه غير متاح — يُنتظر */
    | 'unavailable'
    /** لا ردّ أصلًا */
    | 'network';

export function classify(status: number | null): Outcome {
    if (status === null) return 'network';
    if (status >= 200 && status < 300) return 'ok';
    if (status === 401 || status === 419) return 'session';
    if (status === 403) return 'forbidden';
    if (status === 422) return 'refused';
    if (status === 409) return 'conflict';
    if ([429, 502, 503, 504, 404, 405].includes(status)) return 'unavailable';

    // ‏500، و400/413 وأخواتها: شيءٌ في هذه البيعة بعينها — لا يُوقف غيرَها
    return 'server';
}

/** محاولاتٌ آليّة لخطأ الخادم قبل أن تنتظر البيعةُ يدًا */
export const MAX_AUTO_ATTEMPTS = 6;

/** تأخيرُ البيعة بعد خطئها رقم `attempts`: ٣٠ ثانية، دقيقة، دقيقتان… حتى ١٥ دقيقة */
export function entryDelay(attempts: number): number {
    return Math.min(30_000 * 2 ** Math.max(0, attempts - 1), 15 * 60_000);
}

/** تأخيرُ الطابور كلِّه حين يغيب الخادم: ٨ ثوانٍ، ١٦… حتى ٥ دقائق */
export function pauseDelay(failures: number): number {
    return Math.min(8_000 * 2 ** Math.max(0, failures - 1), 5 * 60_000);
}

/**
 * ما تتعلّق به البيعةُ من غيرها: صنفٌ (مخزونه)، وإضافة، وعميل (نقاطه ورصيده
 * وحدّ آجله)، وكوبون (مرّاته)، وسلّةٌ معلّقة. بيعتان تشتركان في شيءٍ منها
 * يغيّر ترتيبُ وصولهما أيّتَهما تُقبل — فلا تسبق اللاحقةُ سابقتَها.
 */
export function keysOf(entry: OutboxEntry): Set<string> {
    const p = entry.payload ?? {};
    const keys = new Set<string>();
    const items = Array.isArray(p.items) ? (p.items as Record<string, unknown>[]) : [];
    for (const item of items) {
        if (item?.id != null) keys.add(`p:${item.id}`);
        if (item?.addon_id != null) keys.add(`a:${item.addon_id}`);
        const addons = Array.isArray(item?.addons) ? (item.addons as Record<string, unknown>[]) : [];
        for (const a of addons) if (a?.addon_id != null) keys.add(`a:${a.addon_id}`);
    }
    if (p.customer_id != null) keys.add(`c:${p.customer_id}`);
    if (typeof p.coupon_code === 'string' && p.coupon_code !== '') keys.add(`k:${p.coupon_code.toUpperCase()}`);
    if (p.resume_id != null) keys.add(`r:${p.resume_id}`);

    return keys;
}

const touches = (keys: Set<string>, blocked: Set<string>) => [...keys].some((k) => blocked.has(k));

/**
 * ما يُرسل في هذه الجولة، بترتيب الطابور.
 *
 * - المردودةُ (`held`) والعالقةُ (`stuck`) تنتظر يدًا ولا توقف ما خلفها —
 *   وإلّا وقف الصندوقُ كلُّه على بيعةٍ واحدة إلى الأبد.
 * - والتي تنتظر موعدَ محاولتها (`nextAt`) توقف ما يتعلّق بها وحده: بيعةٌ للصنف
 *   نفسِه أو العميل نفسِه تبقى خلفها، والمستقلّةُ تمضي.
 */
export function due(queue: OutboxEntry[], now: number): OutboxEntry[] {
    const blocked = new Set<string>();
    const send: OutboxEntry[] = [];
    for (const entry of queue) {
        if (entry.held || entry.stuck) continue;
        const keys = keysOf(entry);
        if ((entry.nextAt ?? 0) > now || touches(keys, blocked)) {
            keys.forEach((k) => blocked.add(k));
            continue;
        }
        send.push(entry);
    }

    return send;
}

/** هل تتعلّق هذه البيعةُ بإحدى تلك؟ — يُسأل أثناء الجولة بعد خطأٍ في بيعةٍ سابقة */
export function dependsOn(entry: OutboxEntry, blocked: Set<string>): boolean {
    return touches(keysOf(entry), blocked);
}

/** حالُ البيعة كما يقرؤها الكاشير */
export type SaleState = 'sending' | 'signin' | 'held' | 'stuck' | 'retrying' | 'pending';

export function stateOf(entry: OutboxEntry, ctx: { sending: ReadonlySet<string>; sessionLost: boolean }): SaleState {
    if (ctx.sending.has(entry.uuid)) return 'sending';
    if (entry.held) return 'held';
    if (entry.stuck) return 'stuck';
    if (ctx.sessionLost) return 'signin';
    if ((entry.attempts ?? 0) > 0) return 'retrying';

    return 'pending';
}

/** الرقمُ المحلّيّ — أوّلُ ثمانيةٍ من المعرّف، يُقرأ ويُطابَق في السجلّ */
export const localNumber = (uuid: string) => uuid.replace(/-/g, '').slice(0, 8).toUpperCase();

/**
 * ‏جولةٌ واحدة على الطابور في الجهاز كلِّه.
 *
 * تبويبان للصندوق يقرآن الطابورَ نفسَه؛ فيُرسلان البيعةَ نفسَها معًا. والخادمُ
 * لا يكتبها مرّتين (المعرّف فريد) — لكنّ طلبين لبيعةٍ واحدة ضغطٌ بلا فائدة.
 * فمن وجد القفلَ مأخوذًا تركها لصاحبه. ومتصفّحٌ بلا `navigator.locks` يمضي كما كان.
 */
export async function withQueueLock(fn: () => Promise<void>): Promise<void> {
    const locks = (typeof navigator !== 'undefined' ? (navigator as Navigator & { locks?: LockManager }).locks : undefined) ?? null;
    if (!locks?.request) {
        await fn();

        return;
    }
    await locks.request('abadpos:pos:outbox', { ifAvailable: true }, async (lock) => {
        if (lock) await fn();
    });
}

/** يُسقط ما أضافته المحاولاتُ ويُبقي البيعةَ كما كُتبت (ومتجرَها) — قبل إعادة الإرسال باليد */
export function fresh(entry: OutboxEntry): OutboxEntry {
    const { held: _h, attempts: _a, nextAt: _n, stuck: _s, ...rest } = entry;

    return rest;
}

/**
 * ‏ما تحتاجه بيعةٌ تنتظر يدًا — ليُقال للكاشير قبل أن يضغط شيئًا.
 *
 * - `safe` إعادتُها لا تكتبها مرّتين: تُرسل بمعرّفها، والخادمُ يردّ فاتورتَها
 *   الأولى إن كان كتبها. ودائمًا `true` ما دام المعرّفُ في حمولتها.
 * - `useful` قد تُقبل إن أُعيدت الآن — أم أنّ سببَها باقٍ حتى يُصلَح.
 * - `manager` سببُها يُصلحه مسؤولٌ مخوّل (مخزون، كوبون، عميلٌ موقوف، حدُّ آجل).
 * - `financial` قُبض ثمنُها على غير ما يقبله الخادم (كوبونٌ انتهى، آجلٌ فوق
 *   حدّه، سلّةٌ أُتمّت من جهازٍ آخر): «تحتاج مراجعة ماليّة». لا تسويةَ ولا شطبَ
 *   من الصندوق — قرارُها لصاحب المتجر ولم يُتّخذ بعد.
 */
export interface Review {
    safe: boolean;
    useful: boolean;
    manager: boolean;
    financial: boolean;
}

export function reviewOf(entry: OutboxEntry): Review {
    const safe = entry.payload?.client_uuid === entry.uuid;
    if (!entry.held) return { safe, useful: true, manager: false, financial: false };
    const fields = entry.held.fields ?? [];
    const has = (...names: string[]) => fields.some((f) => names.some((n) => f === n || f.startsWith(`${n}.`)));

    if (has('resume_id')) return { safe, useful: false, manager: true, financial: true };
    if (has('coupon_code', 'redeem_points', 'redeem_discount', 'expected_total', 'credit', 'paid_now', 'due_at', 'credit_override_reason')) {
        return { safe, useful: false, manager: true, financial: true };
    }
    if (has('customer_language')) return { safe, useful: false, manager: false, financial: false };

    // ‏مخزونٌ أو صنفٌ أو عميلٌ موقوف — أو سببٌ لا يُعرف حقلُه (409 وأخواتها)
    return { safe, useful: false, manager: true, financial: false };
}

/**
 * جدولا «المتجر والطلبات» — استقبالُ الطلبات ومواعيدُ التسليم — بصيغتهما التي يحفظها الخادم.
 *
 * والنموذجُ يحمل كلًّا منهما نصَّ JSON كما يُحفظ (`store_order_schedule` و
 * `store_delivery_schedule`)، والمحرّرُ يقرؤه ويكتبه من هنا. فالصيغةُ واحدةٌ في
 * الشاشة والخادم: `Store\OrderWindow::validated` و`Store\DeliverySchedule::validated`
 * تفحصان ما يصل وتردّانه بالترتيب نفسِه.
 *
 * والأيّامُ مرتّبةٌ بعدّ `dayOfWeek`: 0 الأحد … 6 السبت.
 */

export type Window = { from: string; to: string };

export type Closure = { from: string; to: string };

export interface OrderSchedule {
    week: Window[][];
    starts: string | null;
    ends: string | null;
    closed: Closure[];
}

export type Slot = { id: string; from: string; to: string; ar: string; en: string };

export interface DeliveryPlan {
    delivery: Slot[][];
    pickup: Slot[][] | null;
    closed: Closure[];
    lead: number;
}

/** أيّامُ الأسبوع بالترتيب الذي يعرضه المحرّر: السبتُ أوّلًا كما يعدّه الناس هنا */
export const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5] as const;

export const DAY_NAMES = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'] as const;

/** حدودُ الخادم نفسُها — `OrderWindow::MAX_WINDOWS` و`DeliverySchedule::MAX_SLOTS` */
export const MAX_WINDOWS = 4;
export const MAX_SLOTS = 8;
export const MAX_CLOSURES = 60;
export const LABEL_MAX = 40;
export const MESSAGE_MAX = 600;
export const TERMS_MAX = 20000;

const emptyWeek = <T>(): T[][] => Array.from({ length: 7 }, () => [] as T[]);

function week<T>(raw: unknown, read: (x: Record<string, unknown>) => T): T[][] {
    const out = emptyWeek<T>();
    if (!Array.isArray(raw)) return out;

    for (let d = 0; d < 7; d++) {
        const day = raw[d];
        out[d] = Array.isArray(day) ? day.filter((x) => x && typeof x === 'object').map((x) => read(x as Record<string, unknown>)) : [];
    }

    return out;
}

function closures(raw: unknown): Closure[] {
    if (!Array.isArray(raw)) return [];

    return raw
        .filter((r) => r && typeof r === 'object')
        .map((r) => ({ from: String((r as Closure).from ?? ''), to: String((r as Closure).to ?? (r as Closure).from ?? '') }));
}

function parse(json: string): Record<string, unknown> {
    try {
        const data = JSON.parse(json || '{}');

        return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
    } catch {
        return {};
    }
}

/** جدولُ الاستقبال من نصّه — وما لا يُفهم يُقرأ فراغًا لا خطأ */
export function readOrderSchedule(json: string): OrderSchedule {
    const d = parse(json);

    return {
        week: week(d.week, (w) => ({ from: String(w.from ?? ''), to: String(w.to ?? '') })),
        starts: typeof d.starts === 'string' && d.starts !== '' ? d.starts : null,
        ends: typeof d.ends === 'string' && d.ends !== '' ? d.ends : null,
        closed: closures(d.closed),
    };
}

export function writeOrderSchedule(s: OrderSchedule): string {
    return JSON.stringify({ week: s.week, starts: s.starts || null, ends: s.ends || null, closed: s.closed });
}

/** جدولُ التسليم من نصّه */
export function readDeliveryPlan(json: string): DeliveryPlan {
    const d = parse(json);
    const slot = (s: Record<string, unknown>): Slot => ({
        id: String(s.id ?? ''),
        from: String(s.from ?? ''),
        to: String(s.to ?? ''),
        ar: String(s.ar ?? ''),
        en: String(s.en ?? ''),
    });

    return {
        delivery: week(d.delivery, slot),
        pickup: Array.isArray(d.pickup) ? week(d.pickup, slot) : null,
        closed: closures(d.closed),
        lead: Number.isFinite(Number(d.lead)) ? Math.max(0, Math.trunc(Number(d.lead))) : 0,
    };
}

export function writeDeliveryPlan(p: DeliveryPlan): string {
    return JSON.stringify({ delivery: p.delivery, pickup: p.pickup, closed: p.closed, lead: p.lead });
}

/** أفي الأسبوع فترةٌ واحدةٌ على الأقلّ؟ — شرطُ الخادم قبل أن يُفتح جدول */
export function hasAny<T>(w: T[][]): boolean {
    return w.some((day) => day.length > 0);
}

/**
 * «من … إلى» بعبارة الناس — والنهايةُ قبل البداية تعبر منتصف الليل.
 */
export function crossesMidnight(w: Window): boolean {
    return w.to !== '' && w.from !== '' && w.to < w.from;
}

/** بدايةٌ تساوي نهايتَها — أربعٌ وعشرون ساعة، و`00:00 → 00:00` يومٌ كامل (`OrderWindow::windowsOn`) */
export function wholeDay(w: Window): boolean {
    return w.from !== '' && w.from === w.to;
}

/**
 * الرسالةُ بموعدٍ تُعاين به — كما يملؤها الخادم (`OrderWindow::fill`).
 *
 * والسطرُ الذي يذكر موعدًا لا يُعرف يُسقط، ولا يُعرض متغيّرٌ خام. ومن لم يبقَ
 * له سطرٌ يرى `null`، فتقول المعاينةُ إنّ نصَّ النظام يظهر بدلها.
 */
export function fillMessage(text: string, next: { date: string; time: string } | null): string | null {
    const out: string[] = [];

    for (const line of text.replace(/\r\n/g, '\n').trim().split('\n')) {
        if (line.includes('{next_open_date}') || line.includes('{next_open_time}')) {
            if (!next) continue;
            out.push(line.split('{next_open_date}').join(next.date).split('{next_open_time}').join(next.time).trimEnd());
            continue;
        }
        out.push(line.trimEnd());
    }

    const result = out.join('\n').trim();

    return result === '' ? null : result;
}

/** «9:00 ص» — كما يكتبها الخادم (`ShopClock::hm`) */
export function clock(hhmm: string): string {
    const [h, m] = hhmm.split(':').map((x) => parseInt(x, 10));
    if (!Number.isFinite(h) || !Number.isFinite(m)) return hhmm;
    const twelve = h % 12 === 0 ? 12 : h % 12;

    return `${twelve}:${String(m).padStart(2, '0')} ${h >= 12 ? 'م' : 'ص'}`;
}

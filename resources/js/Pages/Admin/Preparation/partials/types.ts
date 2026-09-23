/**
 * ما يصل لوحةَ التجهيز من الخادم — ولا شيء سواه.
 *
 * ولا حقلَ مالٍ في أيٍّ منها: لا `price` ولا `cost` ولا `total`. الخادمُ لا
 * يرسلها أصلًا (انظر `PreparationController::card`)، وهذه الأنواع تقول ذلك
 * للمحرّر فيصير إضافتُها خطأَ ترجمةٍ لا سهوًا يُكتشف في الشاشة.
 */

export interface PrepItem {
    /** معرّفُ صفّ البند — منه يُبنى مفتاحُ علامة التحقّق */
    id: number;
    name: string;
    qty: number;
    note: string | null;
    image: string | null;
    addons?: { id: number; name: string; qty: number }[];
    /** موادُّ الطلب المخصَّص — لقطتُها لحظة البيع، لا وصفةٌ تُقرأ اليوم */
    components?: { name: string; qty: number }[];
    custom?: {
        template: string | null;
        fields: { label: string; internal: boolean; values: string[] }[];
    } | null;
}

/**
 * الموعدُ مفكوكًا كما حسبه الخادم.
 *
 * `minutes_left` موقَّعٌ: سالبُه تأخيرٌ وموجبُه بقيّة. وهو محسوبٌ لحظةَ
 * القراءة، فتُضاف إليه المدّةُ المنقضية منذُها في الشاشة — ولا يُفسَّر تاريخٌ
 * في المتصفّح أبدًا (انظر `schedule.ts`).
 */
export interface PrepSchedule {
    date: string;
    time: string;
    day: 'today' | 'tomorrow' | 'yesterday' | null;
    minutes_left: number;
}

/** علامةُ تحقّقٍ موضوعة: من وضعها ومتى */
export interface PrepCheck {
    by: string | null;
    at: string;
}

export interface PrepOrder {
    number: string;
    status: string;
    /** صاحب الطلب — لا مستلِمه */
    customer: string | null;
    fulfillment: string | null;
    scheduled_for: string | null;
    scheduled: PrepSchedule | null;
    overdue: boolean;
    recipient: string | null;
    recipient_phone: string | null;
    address: string | null;
    occasion: string | null;
    card_message: string | null;
    sender: string | null;
    hide_sender: boolean;
    delivery_notes: string | null;
    internal_notes: string | null;
    branch: string | null;
    items: PrepItem[];
    /** ما يجوز الانتقال إليه — يصل من الخادم، والحارس هناك أيضًا */
    next: string[];
    /** ما أُشّر من قائمة التحقّق — مفتاحٌ إلى واضعه ووقته */
    checks: Record<string, PrepCheck>;
}

export interface PrepProps {
    orders: PrepOrder[];
    filters: { when: string | null; type: string | null };
    counts: { all: number; overdue: number; today: number; tomorrow: number };
    typeCounts: { all: number; delivery: number; pickup: number };
    /** خريطةُ الأعمدة من الخادم — مفتاحٌ إلى الحالات التي يضمّها */
    columns: Record<string, string[]>;
    /** أعدادُ الأعمدة تحت المرشّحين — من الاستعلام لا من البطاقات المعروضة */
    columnCounts: Record<string, number>;
    /** يصل حين تُقصّ اللوحة عند سقفها — وnull حين تُعرض كاملة */
    truncated: { shown: number; total: number } | null;
    fetchedAt: string;
}

/* ═══ قائمةُ التحقّق: مفاتيحُها تُبنى من لقطة الطلب ═══ */

/** المهمّتان الثابتتان — تُعرضان لكلّ طلبٍ مهما كانت بنودُه */
export const TASK_KEYS = ['task:packaging', 'task:instructions'] as const;

export const TASK_LABELS: Record<string, string> = {
    'task:packaging': 'التغليف',
    'task:instructions': 'مراجعة التعليمات والبطاقة',
};

/**
 * كلُّ ما يُؤشَّر في هذا الطلب — بالترتيب الذي يُعرض به.
 *
 * والمصدرُ هو البنودُ نفسُها لا قائمةٌ ثانية: بندٌ يُحذف من طلبٍ يختفي مربّعُه
 * معه، ولا يبقى صفٌّ مؤشَّرٌ لشيءٍ لا وجود له. ويطابق ما يقبله الخادم في
 * `PrepChecklist::keys` — ومَن يزيد هنا وينسى هناك يجد ضغطةً تُردّ.
 */
export function checklistKeys(order: PrepOrder): string[] {
    const keys: string[] = [...TASK_KEYS];

    for (const item of order.items) {
        keys.push(`item:${item.id}`);
        for (const addon of item.addons ?? []) keys.push(`addon:${addon.id}`);
    }

    return keys;
}

/** كم أُشّر من كم — رقمان يُقرآن على البطاقة بلا فتح التفاصيل */
export function checkProgress(order: PrepOrder): { done: number; total: number } {
    const keys = checklistKeys(order);

    return {
        done: keys.filter((k) => order.checks?.[k]).length,
        total: keys.length,
    };
}

import type { PreparationData, PreparationOptions } from '@/Components/CustomerInvoicePreparationFields';

/**
 * ما يصل لوحةَ التجهيز من الخادم — ولا شيء سواه.
 *
 * وما يدفعه الزبون يصل (`price` و`total` وحالُ الدفع) — من يجهّز يسلّم، ويُسأل
 * عند التسليم. والتكلفةُ والربحُ لا يصلان أبدًا: لا حقلَ لهما هنا، فإضافتُهما
 * خطأُ ترجمةٍ لا سهوٌ يُكتشف في الشاشة. انظر `PreparationController::card`.
 */

export interface PrepItem {
    /** معرّفُ صفّ البند — منه يُبنى مفتاحُ علامة التحقّق */
    id: number;
    name: string;
    qty: number;
    /** سعرُ الوحدة وإجماليُّ السطر كما بيع — اختياريّان لطلبات الاختبار الناقصة عمدًا */
    price?: number;
    total?: number;
    note: string | null;
    /** بندُ كرت هدية: نصُّه رسالةٌ لا ملاحظةُ منتج */
    card_line?: boolean;
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
    /**
     * هويّةُ البطاقة مصنَّفةً — `order:{number}` أو `customer-invoice:{id}`.
     *
     * والطلبُ ومهمّةُ الفاتورة على اللوحة نفسِها: رقمان لا يتصادمان بها.
     * واختياريّةٌ في النوع لأنّ الاختبارات تبني طلباتٍ ناقصةً عمدًا (`idOf`).
     */
    key?: string;
    /** طلبٌ أم فاتورةُ عميلٍ سُدّدت — والغائبُ طلب */
    kind?: 'order' | 'invoice';
    /** معرّفُ مهمّة الفاتورة — أبوابُها به لا برقم الفاتورة */
    prep_id?: number;
    /** «فاتورة عميل» — مصدرُ البطاقة كما يُقرأ */
    source_label?: string;
    /** أمسدَّدةٌ الآن؟ ومن أين ثبت سدادُها — لمهمّة الفاتورة */
    payment_verified?: boolean;
    payment_source?: string | null;
    /**
     * ورقةُ الصندوق الداخليّة وحالُها — لمهمّة الفاتورة. وما يجوز فيها من
     * الخادم (`PrintJobs::card`): «خرجت» لما لا يحجزه صندوقٌ الآن، والإعادةُ
     * لما فشل أو فُتح حوارُه أو أُلغي.
     */
    print?: {
        status: string;
        label: string | null;
        error: string | null;
        confirmable?: boolean;
        requeueable?: boolean;
    } | null;
    /**
     * ما يملأ نموذجَ «تعديل تفاصيل التسليم» — لمهمّة الفاتورة، ولمن يملك
     * `customerInvoice.preparation.edit` وحده؛ وإلّا غائبٌ فلا زرّ.
     */
    details?: PreparationData | null;
    number: string;
    status: string;
    /** قناةُ الطلب — `website` لطلب الموقع؛ تقرؤها طباعةُ التأكيد */
    channel?: string | null;
    /**
     * أيُّ سندِ تسليمٍ يُطبع: `thermal` لطلب موقعٍ في متجرٍ فُتح له، و`a4`
     * لكلّ ما سواه. يقيسه الخادم (`DeliveryPaper::paperFor`) — والغائبُ A4.
     */
    delivery_paper?: 'thermal' | 'a4';
    /** صاحب الطلب — لا مستلِمه */
    customer: string | null;
    /** هاتفُ صاحب الطلب — من سجلّ العميل */
    customer_phone?: string | null;
    /** ما يُحصَّل عند التسليم: الإجماليُّ وحالُ الدفع ووسيلتُه */
    total?: number;
    payment_status?: string | null;
    payment_method?: string | null;
    fulfillment: string | null;
    scheduled_for: string | null;
    scheduled: PrepSchedule | null;
    overdue: boolean;
    recipient: string | null;
    recipient_phone: string | null;
    address: string | null;
    /** هديّةٌ لغير مشتريها، وموقعُ مستلِمها كما يُقرأ — انظر Store\GiftOrders */
    is_gift?: boolean;
    location_label?: string | null;
    occasion: string | null;
    card_message: string | null;
    /*
     * ترتيبُ النصّ على الكرت ومرفقُه.
     *
     * واختياريّةٌ في النوع لا في الحمولة: المتحكّمُ يُرسلها دائمًا، وإنّما
     * تُبنى في الاختبارات طلباتٌ ناقصةٌ عمدًا لتُختبر شاشةٌ واحدة.
     */
    card_align?: string | null;
    card_file?: string | null;
    card_file_name?: string | null;
    sender: string | null;
    hide_sender: boolean;
    delivery_notes: string | null;
    internal_notes: string | null;
    /** ملاحظةُ الطلب العامّة (`orders.notes`) — نصُّ كاتبها كما هو، لا يُترجَم */
    order_notes: string | null;
    branch: string | null;
    items: PrepItem[];
    /** ما يجوز الانتقال إليه — يصل من الخادم، والحارس هناك أيضًا */
    next: string[];
    /** ما أُشّر من قائمة التحقّق — مفتاحٌ إلى واضعه ووقته */
    checks: Record<string, PrepCheck>;
}

export interface PrepProps {
    /** فروعُ من يعدّل بيانات التسليم ومناسباتُ البطاقة — وnull لمن لا يملكه */
    invoiceDetails?: { options: PreparationOptions } | null;
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
    // ومهمّةُ الفاتورة بلا علاماتٍ مخزَّنة — جدولُها لبنود الطلبات وحدها (`PrepChecklist`)
    if (order.kind === 'invoice') return [];

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

/** هويّةُ البطاقة على اللوحة — المفتاحُ المصنَّف، أو الرقمُ لطلبٍ بلا مفتاح */
export function idOf(order: PrepOrder): string {
    return order.key ?? order.number;
}

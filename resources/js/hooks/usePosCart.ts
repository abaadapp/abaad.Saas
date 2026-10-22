import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { csrfHeaders } from '@/lib/csrf';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency } from '@/types';

/* ------------------------------- الأنواع ------------------------------- */

export interface CartItem {
    /** مفتاح فريد للبند: 'p'+معرّف المنتج أو 'a'+معرّف الإضافة */
    key: string;
    id: number | null;
    /** معرّف الإضافة حين لا يكون البند منتجًا — الخادم يُسعّر به بدل الوثوق بالسعر المُرسَل */
    addon_id?: number | null;
    /** المقاس المختار — الخادم يقرأ سعره من القاعدة، والاسم للعرض وحده */
    variant_id?: number | null;
    variant_name?: string | null;
    /**
     * إضافاتٌ اختارها الزبون على هذا البند.
     *
     * جزءٌ من مفتاح البند: «بوكيه + شوكولاتة» و«بوكيه» بندان مختلفان في
     * السلّة، وضمُّهما كان سيُضيف شوكولاتةً لمن لم يطلبها.
     */
    addons?: CartAddon[];
    name: string;
    price: number;
    qty: number;
    note: string;
    image?: string | null;
    icon?: string | null;
    /** لقطة المخزون وقت الإضافة — تُستخدم للتحذير من التجاوز */
    stock?: number | null;
    /**
     * نسبة ضريبة هذا البند — نسبته الخاصّة إن كانت له، وإلا نسبة المتجر.
     *
     * الخادم يحتسب الضريبة سطرًا سطرًا بنسبة كل صنف. وكانت الشاشة تضرب
     * السلّة كلَّها بنسبةٍ واحدة، فصنفٌ معفًى — أو نسبةُ متجرٍ غُيّرت وأصنافُه
     * تحمل نسبةً مكتوبة — يجعل ما يقرؤه الزبون غير ما يُخصم منه.
     */
    tax?: number | null;
    /** وصفُ الطلب المخصَّص — `null` لكلّ بندٍ من الكتالوج */
    custom?: CustomCart | null;
    /**
     * الموسمُ الذي كان مختارًا في الصندوق حين أُضيف البند — يثبت مع البند.
     *
     * يُرسَل إلى الخادم ليُكتب لقطةً على بند الفاتورة، والخادمُ يتحقّق منه
     * ولا يصدّقه. و`null` من شاشة «الكل»: لا يُخمَّن موسمٌ لبيعةٍ لم تُختر له.
     */
    season_id?: number | null;
}

export interface CartAddon {
    addon_id: number;
    name: string;
    price: number;
    qty: number;
}

/** مادّةٌ تدخل في طلبٍ مخصَّص — تشير إلى صنفٍ حقيقيّ في المخزون */
export interface CustomComponent {
    product_id: number;
    /** للعرض وحده — الخادمُ يقرأ الاسمَ والتكلفةَ من صفّ الصنف */
    name: string;
    /**
     * `quantity` لا `qty`.
     *
     * الاسمُ هو اسمُ العمود في `order_item_components`، وهو الذي تطلبه
     * `CustomArrangement::rules` والذي تردّه حمولةُ الاستئناف. وكان هنا
     * `qty` — فكانت كلُّ بيعةٍ مخصَّصةٍ تُردّ بـ٤٢٢، وكلُّ سلّةٍ تُستأنف
     * بموادَّ بلا كميّات.
     */
    quantity: number;
    /**
     * أتعود هذه المادّة إلى الرفّ عند الإلغاء؟
     *
     * تُرسَل من الشاشة **اقتراحًا** لا حكمًا: الخادم يعيد قراءتها في
     * `CustomArrangement::components` ويكتبها في اللقطة. وقد كانت تُستنبط
     * من نوع المادّة — `packaging` يعود وما سواه لا — وهو افتراضُ صناعةٍ لا
     * يصلح لمن يؤجّر ولا لمن يبيع طعامًا.
     */
    restockable: boolean;
}

/** إجابةُ حقلٍ من حقول القالب — بمعرّفه لا باسمه */
export interface CustomFieldAnswer {
    field_id: number;
    /** نصٌّ أو رقمٌ أو صحّةٌ أو قائمةُ معرّفاتِ خيارات — على حسب نوع الحقل */
    value: unknown;
}

/**
 * طلبٌ رُكّب على الطاولة — ما يصفه ولا يُقرأ من كتالوج.
 *
 * `price` هو الوحيد الذي يُرسَل ويُقبَل سعرًا: لا صنفَ له يُقرأ منه، وذاك
 * معنى «مخصَّص». وكلُّ ما عداه — التكلفةُ وأسماءُ الموادّ وتسمياتُ الحقول —
 * يُقرأ في الخادم من صفوف المتجر، فلا يُوثق بما يرسله المتصفّح.
 *
 * ولا حقلَ هنا باسمِ صناعة: لا `colors` ولا `packaging_label`. الشكلُ
 * يقوله قالبُ التاجر، والإجاباتُ تصل بمعرّفات حقوله.
 */
export interface CustomCart {
    /** القالبُ الذي رُكّب عليه — الخادمُ يتحقّق أنّه من هذا المتجر وأنّه يُباع */
    template_id: number;
    /** للعرض في السلّة وحدَه — واللقطةُ المحفوظة تُكتب في الخادم */
    template_name: string;
    mode: string;
    price: number;
    /** القيمةُ الأساسية في وضع «قيمة + إضافات» — و`null` في «السعر النهائي» */
    base_value?: number | null;
    fields: CustomFieldAnswer[];
    components: CustomComponent[];
    /** تكلفةُ الموادّ كما حُسبت في الشاشة — تُعرض ولا تُرسَل للحفظ */
    material_cost?: number;
}

export interface PosCustomer {
    id: number;
    name: string;
    name_en?: string | null;
    label: string;
    phone: string;
    points: number;
    /** لغةُ رسائل واتساب — `null` لمن سُجّل قبل أن تُسأل، فيسألها الصندوق قبل البيع */
    language?: string | null;
    /** ما يُقال للكاشير عنه — يبنيه الخادم بالإعدادات، انظر CustomerFlags::context */
    context?: {
        alert: { type: 'warning' | 'block'; reason: string } | null;
        birthday_in: number | null;
        note: string | null;
    };
}

export interface PosVariant {
    id: number;
    name: string;
    label: string;
    price: number;
    sku: string | null;
}

export interface PosProduct {
    id: number;
    label: string;
    price: number;
    image: string | null;
    sku: string;
    barcode: string;
    /** و`null` لما لا لقطةَ مخزونٍ له: ذو الوصفة وغيرُ المرتبط بالمخزون */
    stock: number | null;
    variants?: PosVariant[];
    /** معرّفات الإضافات المسموحة — و`null` يعني «كلّها» (سلوك ما قبل الربط) */
    addon_ids?: number[] | null;
    /** ذو الوصفة رصيدُه مكوّناتُه لا عمودُه — فلا يُحذَّر من نفاده */
    has_recipe?: boolean;
    /** النسبة الفعليّة للصنف — يُرسلها الخادم مشتقّةً لا خامًا */
    tax?: number | null;
    /** الموقوف لا يُعرض ولا يُمسح ولا يُباع — والخادم يردّه كذلك */
    active?: boolean;
    /** وما أُغلقت عنه نقطةُ البيع مثلُه هنا — ويُباع في الموقع (`SaleLines::POS`) */
    pos_enabled?: boolean;
}

export interface AppliedCoupon {
    code: string;
    type: string;
    value: number;
    /**
     * الحدُّ الأدنى للطلب كما قاله الخادم (`PosController::applyCoupon`).
     *
     * به تحكم السلّةُ في الحال حين تنزل تحته: لا خصمَ على الشاشة ولا كودَ في
     * الحمولة. وغيابُه — ردٌّ من خادمٍ أقدم — صفرٌ: لا حدّ.
     */
    min_order?: number;
}

export interface LoyaltySettings {
    redeemMaxPct: number;
    earnRate: number;
    redeemMin: number;
}

/**
 * ضريبة المتجر كما ضبطها صاحبه — لا رقمٌ مكتوب في شيفرة الشاشة.
 *
 * كانت السلّة تحسب ٥٪ ثابتة مهما ضُبط: من نسبته ١٠٪ يقرأ الكاشير رقمًا على
 * شاشته وتُسجَّل الفاتورة بآخر، فيُقال للزبون مبلغٌ ويُقبض منه غيره. ومن
 * أطفأ الضريبة كان سطرُها يبقى في شاشته ويُحسب في مجموعه.
 */
export interface VatSettings {
    enabled: boolean;
    rate: number;
    /** مشمولة في السعر المعروض: تُستخرَج منه لا تُضاف فوقه */
    inclusive: boolean;
}

export interface ResumeCart {
    id: number | null;
    customer: string | null;
    items: Omit<CartItem, 'key' | 'note'>[];
    /** كود الخصم الذي كان مطبَّقًا وقت التعليق — يُعاد تطبيقه لا استعادة قيمته */
    coupon_code?: string | null;
}

interface OutboxEntry {
    uuid: string;
    payload: Record<string, unknown>;
    at: number;
}

export interface CheckoutResult {
    synced: boolean;
    invoice: string | null;
    /** نقاطٌ كسبها من هذا الشراء */
    points: number;
    /** نقاطٌ استُبدلت في هذه الفاتورة */
    redeemed?: number;
    /**
     * رصيدُه بعدها — أو `null` حين لا يردّه الخادم.
     *
     * و`null` ليست صفرًا: بيعةٌ بلا اتصالٍ تبقى في الطابور فلا رصيدَ يُقال
     * عنها، وردٌّ مكرَّرٌ لا يخصم شيئًا جديدًا. وصفرٌ يُعرض في الحالين يقول
     * للزبون إنّ نقاطه نفدت.
     */
    balance?: number | null;
    /** رفضه الخادم (مخزون غير كافٍ أو صنف غير معروف) — لا يُعاد إلى الطابور */
    rejected?: boolean;
}

const OUTBOX_KEY = 'abadpos:pos:outbox';
/* لا نسبة في الشيفرة: النسبة تصل من إعدادات المتجر (انظر VatSettings) */
/**
 * 100 نقطة = وحدة واحدة من العملة الأساسية.
 *
 * ويجب أن يطابق `Loyalty::POINTS_PER_UNIT` في الخادم: الشاشة تحسب الخصم
 * لتعرضه، والخادم يحسبه ليكتبه — ولو افترقا لقرأ الزبون رقمًا ودفع آخر.
 *
 * ويُصدَّر لأنّ الشاشة تعرض قيمة النقاط بالمال (انظر `Pos/Index`): كان
 * الرقم مكتوبًا هناك بيده مرّتين، فصارت للمقسوم ثلاثةُ مواضع — واثنان منها
 * لا يعلمان بتبديل الثالث.
 */
export const POINTS_PER_UNIT = 100;
const CASH_CUSTOMER = 'عميل نقدي';

/**
 * «السعر النهائيّ» في الطلب المخصَّص — `CustomArrangement::MODE_BUDGET` في الخادم.
 */
export const CUSTOM_BUDGET = 'budget';

/**
 * ما يُدفع من إضافات البند — كمياتٌ مطلقة لا مضروبة في كمية البند.
 *
 * ═══ و«السعرُ النهائيّ» لا تُدفع إضافاتُه فوقه ═══
 *
 * الزبون قال «سبعة عشر ومئتان للطلب كلّه»: الكرتُ داخلَها لا فوقها. فسعرُ
 * البند هو الرقمُ نفسُه (`CustomArrangementDialog` يرسله كما هو)، والإضافاتُ
 * تبقى على البند — تُعرض وتُعدَّل وتُخصم من الرفّ — ولا تُجمع إلى ما يُدفع.
 * وهي قاعدةُ `SaleLines` في الخادم: `addons_total` صفرٌ في هذا الوضع.
 *
 * وكانت السلّة تجمعها لكلّ بند، فتقرأ الشاشةُ ١٧٫٤٠٠ لطلبٍ بـ١٧٫٢٠٠،
 * ويُشتقّ منها الخصمُ والضريبةُ والنقاطُ وزرُّ الدفع — والخادمُ يقبض ١٧٫٢٠٠.
 *
 * وفي «قيمة + إضافات» يُرسَل السعرُ ناقصَها، فتُجمع هنا مرّةً — كالمنتج.
 */
export const billableAddons = (item: Pick<CartItem, 'addons' | 'custom'>): number =>
    item.custom?.mode === CUSTOM_BUDGET ? 0 : (item.addons ?? []).reduce((s, a) => s + a.price * a.qty, 0);

/**
 * ثمنُ البند كما يجمعه المجموعُ الفرعيّ — سعرُه في كميّته، وإضافاتُه المدفوعة.
 *
 * المصدرُ الواحد لكلّ رقمٍ ماليّ في الصندوق: المجموع والخصم والضريبة
 * والنقاط وزرّ الدفع، وسطرُ البند في السلّة (`Pos/Index`). ويطابق صافيَ
 * البند في الخادم: `price × qty + addons_total` (`SaleLines::taxFor`).
 */
export const cartLineTotal = (item: Pick<CartItem, 'price' | 'qty' | 'addons' | 'custom'>): number =>
    item.price * item.qty + billableAddons(item);

/**
 * المبلغُ إلى ثلاث منازل كما يقرّبه الخادم — `round($v, 3)` في PHP 8.4 حرفًا.
 *
 * ═══ ولمَ في الشاشة ═══
 *
 * الخادمُ يقرّب كلَّ خطوة: خصمَ الكوبون (`Coupon::discountFor`)، ثمّ الخصمَ كلَّه،
 * ثمّ الضريبة (`SaleLines::taxFor`)، ثمّ الإجماليّ. والشاشةُ كانت تجمع بلا تقريب
 * وتقرّب العرضَ وحدَه — فكوبونٌ بالنسبة على ٢٫٦٥٠ يُقرأ ٢٫٥١٨ ويُكتب في الفاتورة
 * ٢٫٥١٧، في سلّةٍ من كلّ ثمانٍ تقريبًا من سلال الكوبون بالنسبة.
 *
 * ═══ ولمَ لا `toFixed` ولا `Math.round(v * 1000)` ═══
 *
 * يفترقان عن PHP عند حدّ النصف: ‎0.0045‎ يقرّبه PHP إلى ‎0.005‎ و`toFixed` إلى
 * ‎0.004‎، و‎1.1744999999999999‎ يقرّبه PHP إلى ‎1.174‎ وحيلةُ `e3` إلى ‎1.175‎.
 * فهذه خوارزميّةُ PHP نفسُها (`_php_math_round`): الجزءُ الصحيح، ثمّ حدُّ النصف
 * عددًا عائمًا، والنصفُ بعيدًا عن الصفر — وطابقته في ثلاثمئة ألف قيمة.
 */
export function roundMoney(value: number): number {
    if (!Number.isFinite(value) || value === 0) return value;

    const abs = Math.abs(value);
    const whole = Math.floor(abs * 1000);

    // مقرَّبٌ أصلًا
    if (whole / 1000 === abs) return value;

    const edge = (whole + 0.5) / 1000;

    return Math.sign(value) * ((abs >= edge ? whole + 1 : whole) / 1000);
}

/**
 * كم تنتظر السلّةُ بعد آخر تغييرٍ قبل أن تسأل الخادمَ عن كوبونها.
 *
 * نقراتُ الكاشير المتتابعة على «+» سؤالٌ واحدٌ حين تهدأ، لا سؤالٌ لكلّ نقرة.
 * والشاشةُ لا تنتظره لتحكم بالحدّ الأدنى — ذاك في الحال (`couponShort`).
 */
export const COUPON_SETTLE_MS = 400;

function uuid(): string {
    try {
        if (crypto?.randomUUID) return crypto.randomUUID();
    } catch {
        /* بيئة غير آمنة — نلجأ للبديل */
    }
    return `cuid-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function readOutbox(): OutboxEntry[] {
    try {
        return JSON.parse(localStorage.getItem(OUTBOX_KEY) || '[]');
    } catch {
        return [];
    }
}

/**
 * ما يمنع فتحَ الدفع — ولمَ.
 *
 * `'empty'` سلّةٌ فارغة، و`'language'` زبونٌ لم يُجَب عن لغته بعد. تُقرأ
 * في زرّ الدفع قبل فتح النافذة: الكاشيرُ يُقال له السببُ عند الزرّ لا بعد
 * أن يختار وسيلةَ الدفع ويضغط «تأكيد».
 */
export function whatBlocksPayment(cart: {
    items: unknown[];
    needsLanguage: boolean;
    blocked?: boolean;
    blockOverrideReason?: string;
}): 'empty' | 'language' | 'blocked' | null {
    if (cart.items.length === 0) return 'empty';
    if (cart.needsLanguage) return 'language';
    // الزبونُ الموقوف: يُفتح الدفعُ بسببِ تجاوزٍ مكتوبٍ وحده — والخادمُ يقيس الإذن
    if (cart.blocked && (cart.blockOverrideReason ?? '').trim() === '') return 'blocked';

    return null;
}

/* ------------------------------- الـhook ------------------------------- */

interface Options {
    products: PosProduct[];
    customers: PosCustomer[];
    coupons: unknown[];
    loyalty: LoyaltySettings;
    vat?: VatSettings;
    resume: ResumeCart | null;
    currency: Currency;
    onToast: (msg: string, type?: 'success' | 'warning' | 'danger' | 'info') => void;
    /**
     * يُستدعى بعد كل بيع يصل الخادم بنجاح — لتحديث «المتوفر» المعروض.
     *
     * بدونه يبقى الرقم على قيمته الأولى طوال الوردية: الكاشير يبيع عشر
     * قطع والشاشة ما زالت تقول ٣٤، وتحذير «تتجاوز المتوفر» يُحسب على رقم
     * قديم. الخادم يرفض البيع الزائد فعلًا، لكن ما يراه الكاشير خاطئ.
     */
    onSynced?: () => void;
}

/**
 * منطق سلة نقطة البيع — منقول عن Alpine.data('posCart') في app.js
 * بنفس المعادلات والسلوك: الخصم، الضريبة، سقف نقاط الولاء، وطابور
 * الانقطاع (outbox) الذي يكتب البيع محليًا أولًا ثم يرفعه.
 */
export function usePosCart({ products, customers: initialCustomers, loyalty, vat, resume, currency, onToast, onSynced }: Options) {
    /*
     * إشعارات الكاشير تُترجَم هنا لا في موضع العرض.
     *
     * كانت نصوصًا عربيةً صلبة تُمرَّر إلى onToast: النظام كلّه إنجليزي عند
     * الموظف، ثم يبيع فتقفز له رسالة عربية — وهو من لا يقرأ العربية أصلًا.
     * وترجمتها عند العرض لا تصلح: بعضها يحمل اسم صنفٍ أو سبب رفض، فيصير
     * المفتاح نصًّا مركَّبًا لا يُطابق شيئًا.
     */
    const t = useTranslate();
    const [items, setItems] = useState<CartItem[]>(() =>
        (resume?.items ?? []).map((i, idx) => ({
            ...i,
            key: (i as CartItem).key ?? `r${idx}`,
            note: (i as CartItem).note ?? '',
            qty: i.qty ?? 1,
        })),
    );
    const [resumeId] = useState<number | null>(resume?.id ?? null);
    const [customer, setCustomer] = useState<string>(
        resume?.customer || new URLSearchParams(location.search).get('customer') || CASH_CUSTOMER,
    );
    /*
     * المعرّف بجانب الاسم — والنقاط تتبعه لا تتبع الاسم.
     *
     * الاسم ليس مفتاحًا: «محمد» في متجرٍ فيه ثلاثة محمّدين يطابق أوّلهم في
     * القائمة، فتُضاف نقاط الشراء إلى شخصٍ لم يشترِ، ويُخصم رصيد غيره عند
     * الاستبدال. والنقاط مالٌ فعلي.
     */
    const [customerId, setCustomerId] = useState<number | null>(null);
    const [customers, setCustomers] = useState<PosCustomer[]>(initialCustomers);
    const [customerSearch, setCustomerSearch] = useState('');

    const [barcode, setBarcode] = useState('');

    const [couponCode, setCouponCode] = useState('');
    const [coupon, setCoupon] = useState<AppliedCoupon | null>(null);
    const [couponError, setCouponError] = useState('');
    const [couponLoading, setCouponLoading] = useState(false);
    /*
     * ═══ وجوابُ الخادم عن الكوبون يُقرأ لآخر سؤالٍ وحدَه ═══
     *
     * السؤالُ يُعاد كلّما تبدّل الزبونُ أو هدأت السلّةُ على مجموعٍ جديد، وجوابان
     * في الطريق قد يصلان بغير ترتيبهما: «مقبولٌ» عن أحمدَ يصل بعد «بلغ حدَّه» عن
     * محمّد، فيعود الخصمُ على فاتورة محمّد. فلكلّ سؤالٍ رقمٌ ولا يُقرأ إلّا جوابُ
     * آخرِها، وإزالةُ الكوبون تُبطل كلَّ ما في الطريق.
     */
    const couponAsk = useRef(0);
    /** ما يُسأل عنه الآن: الكودُ و«الكود|المجموع» — وفراغُه: لا سؤالَ في الطريق */
    const couponAsking = useRef<{ code: string; state: string } | null>(null);
    /** «الكود|المجموع» الذي أكّده الخادمُ آخرَ مرّة — وما يطابقه لا يُسأل عنه ثانيةً */
    const couponConfirmed = useRef('');

    const [redeemActive, setRedeemActive] = useState(false);

    const [online, setOnline] = useState(() => navigator.onLine);
    const [pending, setPending] = useState<OutboxEntry[]>(readOutbox);
    const flushing = useRef(false);
    /** معرّفات البيوع الجارية الآن — يتخطّاها النبض فلا يعيد إرسالها */
    const inFlight = useRef(new Set<string>());

    const redeemMaxPct = Math.min(100, Math.max(0, Number(loyalty.redeemMaxPct) || 0));
    const earnRate = Math.max(0, Number(loyalty.earnRate) || 0);
    const redeemMin = Math.max(0, Number(loyalty.redeemMin) || 0);

    /* ----------------------------- الحسابات ----------------------------- */

    const count = useMemo(() => items.reduce((s, i) => s + i.qty, 0), [items]);
    /** ثمنُ البند — والسعرُ النهائيّ لا تُجمع إضافاتُه فوقه. انظر `cartLineTotal` */
    const subtotal = useMemo(() => items.reduce((s, i) => s + cartLineTotal(i), 0), [items]);
    /** والمجموعُ كما يقرؤه الدفع: `round(…, 3)` — عليه يُحسب الخصمُ والضريبة */
    const base = roundMoney(subtotal);

    /*
     * ═══ وكوبونٌ نزلت السلّةُ تحت حدّه الأدنى لا يخصم — في الحال ═══
     *
     * كان الخصمُ يُحسب من الكوبون المحفوظ كلّما تبدّل المجموع، ولا يعرف حدَّه.
     * فيُطبَّق كودٌ حدُّه عشرون على سلّةٍ بثلاثين، ويحذف الكاشيرُ صنفًا فتصير
     * عشرة — والخصمُ باقٍ على الشاشة، يُعلَن للزبون، ثمّ يردّه الدرج.
     *
     * والحكمُ هنا لا ينتظر الخادم: الحدُّ معروفٌ من جوابه، والمجموعُ في اليد.
     * فلا خصمَ يُعرض ولا كودَ يُرسل (`activeCoupon`) — وإن هدأت السلّةُ تحته
     * أُسقط الكوبونُ وبقي سببُه (`settle` أدناه).
     */
    const couponShort = coupon !== null && base < Number(coupon.min_order ?? 0);
    const activeCoupon = couponShort ? null : coupon;

    // `Coupon::discountFor` ثمّ `min(…, $subtotal)` في الدفع — بالتقريب نفسِه
    const couponDiscount = useMemo(() => {
        if (!activeCoupon) return 0;
        const d =
            activeCoupon.type === 'نسبة' ? (base * Number(activeCoupon.value)) / 100 : Number(activeCoupon.value);
        return Math.min(roundMoney(Math.min(d, base)), base);
    }, [activeCoupon, base]);

    const selectedCustomer = useMemo(() => {
        // بالمعرّف أولًا. ولا يُلجأ إلى الاسم إلا حين لا معرّف (طلب مستعاد أو
        // رابط فيه ?customer=) — وحتى حينها لا يُختار إلا إن كان الاسم فريدًا،
        // فالمطابقة الغامضة تعرض رصيد شخصٍ آخر للكاشير
        if (customerId !== null) return customers.find((c) => c.id === customerId) ?? null;
        const byName = customers.filter((c) => c.name === customer || c.name_en === customer);
        return byName.length === 1 ? byName[0] : null;
    }, [customers, customer, customerId]);
    const selectedPoints = selectedCustomer?.points ?? 0;
    const canRedeem = selectedPoints > 0 && selectedPoints >= redeemMin;
    const pointsToThreshold = Math.max(0, redeemMin - selectedPoints);

    // سقف الاستبدال: نسبة من المجموع الفرعي، ولا يتجاوز المتبقّي بعد الكوبون
    const redeemCap = useMemo(() => {
        const byPct = (subtotal * redeemMaxPct) / 100;
        const afterCoupon = Math.max(0, subtotal - couponDiscount);
        return Math.min(byPct, afterCoupon);
    }, [subtotal, redeemMaxPct, couponDiscount]);

    const redeemDiscount = useMemo(() => {
        if (!redeemActive || !canRedeem) return 0;
        return Math.min(selectedPoints / POINTS_PER_UNIT, redeemCap);
    }, [redeemActive, canRedeem, selectedPoints, redeemCap]);

    const redeemPointsUsed = Math.round(redeemDiscount * POINTS_PER_UNIT);

    // `round(min($couponDiscount + $redeem, $subtotal), 3)` كما في الدفع
    const discountAmount = roundMoney(Math.min(couponDiscount + redeemDiscount, base));

    /*
     * الضريبة كما يحسبها الخادم لا كما تخمّنها الشاشة.
     *
     * ومطفأةً تساوي صفرًا فلا يظهر سطرها. و«مشمولة» تُستخرَج من السعر
     * المعروض — ما على الرفّ هو ما يدفعه الزبون — فلا يُضاف فوقه شيء.
     */
    const vatRate = vat?.enabled === false ? 0 : Math.max(0, Number(vat?.rate ?? 0));
    /*
     * الضريبة سطرًا سطرًا كما يحتسبها الخادم تمامًا.
     *
     * الخصم يُوزَّع على البنود بنسبة قيمة كلٍّ منها من المجموع، ثم تُضرب حصّةُ
     * البند الصافية بنسبته هو. وأيّ اختلافٍ في هذه المعادلة يعني رقمين
     * لبيعةٍ واحدة: واحدًا على الشاشة وآخر في الفاتورة.
     *
     * والتقريبُ تقريبُه كذلك (`SaleLines::taxFor`): البنودُ بلا تقريب على المجموع
     * المقرَّب، والناتجُ إلى ثلاث منازل.
     */
    const taxAmount = useMemo(() => {
        if (vat?.enabled === false || base <= 0) return 0;

        return roundMoney(
            items.reduce((sum, i) => {
                const net = cartLineTotal(i);
                const taxableLine = net - discountAmount * (net / base);
                const rate = Math.max(0, Number(i.tax ?? vatRate) || 0);

                return sum + (vat?.inclusive ? (taxableLine * rate) / (100 + rate) : (taxableLine * rate) / 100);
            }, 0),
        );
    }, [items, base, discountAmount, vat?.enabled, vat?.inclusive, vatRate]);
    /*
     * والإجماليُّ كما يكتبه الدفع: «مشمولة» يُنقص المجموعُ ما استُخرج ضريبةً ثمّ
     * تُعاد إليه — `round($subtotal - $tax, 3)` ثمّ `round($subtotal - $discount + $tax, 3)`.
     */
    const total = roundMoney((vat?.inclusive ? roundMoney(base - taxAmount) : base) - discountAmount + taxAmount);
    const displayTotal = total * (currency.rate ?? 1);

    const pointsToEarn = useMemo(() => {
        if (earnRate <= 0 || !selectedCustomer) return 0;
        return Math.floor(total * earnRate);
    }, [earnRate, selectedCustomer, total]);

    /**
     * الاسم المعروض للعميل — غير القيمة المرسلة للخادم.
     *
     * `customer` قيمةٌ تُطابَق بها السجلات، وقيمتها الافتراضية النص العربي
     * «عميل نقدي». عرضها خامًا كان يُبقيها عربية في واجهة إنجليزية بينما
     * بقية الشاشة مترجمة. أسماء العملاء الحقيقيين لا تُترجَم — `label` هو
     * الاسمُ كما كُتب، بأيّ لغةٍ كانت الواجهة (انظر `Customers::asEntered`).
     */
    const isWalkIn = customer === CASH_CUSTOMER;
    const customerLabel = selectedCustomer?.label || (isWalkIn ? '' : customer);

    /**
     * زبونٌ مختارٌ لا لغةَ في بطاقته — سُجّل قبل أن تُسأل.
     *
     * الرسالةُ تخرج وحدَها بعد البيعة، فلا لحظةَ سؤالٍ غيرَ هذه: يُعرض
     * الزرّان تحت اسمه ولا يُفتح الدفع حتى يُختار. والجوابُ يُكتب محلّيًّا
     * ويُحمل مع البيعة (`customer_language`) فيكتبه الخادم في بطاقته —
     * حتى بيعةُ الانقطاع ترفعه معها حين تعود الشبكة.
     */
    const needsLanguage = selectedCustomer !== null && !selectedCustomer.language;
    const setCustomerLanguage = useCallback((id: number, language: string) => {
        setCustomers((prev) => prev.map((c) => (c.id === id ? { ...c, language } : c)));
    }, []);

    /*
     * الزبونُ الموقوف — يقوله الخادم في `context.alert` لا الشاشة.
     *
     * الدفعُ لا يُفتح له إلّا بسببِ تجاوزٍ مكتوب، ويُحمل مع البيعة
     * (`block_override_reason`) فيقيس الخادمُ الإذنَ ويقيّده. وإغلاقُ
     * النافذة ليس تجاوزًا: السببُ يُصفَّر مع كلّ اختيارِ زبون.
     */
    const blocked = selectedCustomer?.context?.alert?.type === 'block';
    const [blockOverrideReason, setBlockOverrideReason] = useState('');

    /**
     * لقطة المخزون في السلة تتقادم: الكاشير يضيف 5 قطع وهي متاحة، ثم يبيعها
     * زميله على جهاز آخر قبل أن يُنهي هو الدفع. `products` تصل محدَّثة من
     * التغذية الحيّة، فنُزامن معها بنود السلة ليصدق تحذير «يتجاوز المتوفر».
     *
     * الإضافات (addons) بلا مخزون فلا تُمسّ، والبند الذي لم يعد له منتج
     * مطابق يبقى بلقطته بدل أن يُصفَّر.
     */
    useEffect(() => {
        const stockById = new Map(products.map((p) => [p.id, p.stock]));
        setItems((prev) => {
            let changed = false;
            const next = prev.map((i) => {
                if (i.id == null || !stockById.has(i.id)) return i;
                const stock = stockById.get(i.id)!;
                if (i.stock === stock) return i;
                changed = true;
                return { ...i, stock };
            });
            return changed ? next : prev;
        });
    }, [products]);

    const overStock = useCallback(
        (it: CartItem) => it != null && it.stock != null && it.qty > it.stock,
        [],
    );
    const hasStockWarning = useMemo(() => items.some(overStock), [items, overStock]);

    const filteredCustomers = useMemo(() => {
        const q = customerSearch.trim().toLowerCase();
        const list = q
            ? customers.filter(
                  (c) =>
                      (c.label || '').toLowerCase().includes(q) ||
                      (c.name || '').toLowerCase().includes(q) ||
                      // والاسمُ اللاتينيّ المحفوظ قبلُ — لم يعد يُعرض، لكنّه ما زال يُبحث به
                      (c.name_en || '').toLowerCase().includes(q) ||
                      (c.phone || '').toLowerCase().includes(q),
              )
            : customers;
        return list.slice(0, 50);
    }, [customers, customerSearch]);

    /* --------------------------- تعديل السلة --------------------------- */

    const warnStock = useCallback(
        (list: CartItem[], key: string): boolean => {
            const it = list.find((i) => i.key === key);
            if (!it || !overStock(it)) return false;
            onToast(
                it.stock! <= 0
                    ? t('صنف نفد مخزونه: :name', { name: it.name })
                    : t('الكمية تتجاوز المتوفر (:stock): :name', { stock: it.stock!, name: it.name }),
                'warning',
            );
            return true;
        },
        [overStock, onToast, t],
    );

    const add = useCallback(
        (product: Omit<CartItem, 'qty' | 'note'>) => {
            const key = product.key ?? `p${product.id}`;
            let next: CartItem[] = [];
            setItems((prev) => {
                const existing = prev.find((i) => i.key === key);
                next = existing
                    ? prev.map((i) => (i.key === key ? { ...i, qty: i.qty + 1 } : i))
                    : [...prev, { ...product, key, qty: 1, note: '' }];
                return next;
            });
            // التحذير يُقيَّم على الحالة الجديدة لا القديمة
            queueMicrotask(() => {
                if (!warnStock(next, key)) onToast(t('تمت الإضافة إلى السلة'), 'success');
            });
        },
        [warnStock, onToast, t],
    );

    const inc = useCallback(
        (key: string) => {
            let next: CartItem[] = [];
            setItems((prev) => {
                next = prev.map((i) => (i.key === key ? { ...i, qty: i.qty + 1 } : i));
                return next;
            });
            queueMicrotask(() => warnStock(next, key));
        },
        [warnStock],
    );

    const dec = useCallback((key: string) => {
        setItems((prev) => prev.map((i) => (i.key === key && i.qty > 1 ? { ...i, qty: i.qty - 1 } : i)));
    }, []);

    const remove = useCallback((key: string) => {
        setItems((prev) => prev.filter((i) => i.key !== key));
    }, []);

    /**
     * يستبدل بندًا بمحتواه الجديد — للطلب المخصَّص حين يُعدَّل قبل الدفع.
     *
     * ولا `remove` ثمّ `add`: تلك تُلحق البندَ بآخر السلّة فيقفز من مكانه
     * تحت يد الكاشير، وتُصفّر كميّته. والاستبدالُ في موضعه يُبقي الترتيب.
     *
     * ولا مخزونَ يُمسّ هنا: السلّةُ في الذاكرة، والرفُّ لا يَنقص إلا في
     * `completeSale` — فالتعديلُ قبل الدفع لا يترك أثرًا في الدفتر.
     */
    const replace = useCallback((key: string, next: Omit<CartItem, 'key' | 'qty' | 'note'>) => {
        setItems((prev) => prev.map((i) => (i.key === key ? { ...i, ...next } : i)));
    }, []);

    const setNote = useCallback((key: string, note: string) => {
        setItems((prev) => prev.map((i) => (i.key === key ? { ...i, note } : i)));
    }, []);

    const removeCoupon = useCallback(() => {
        // وسؤالٌ في الطريق لا يُعيد ما أُزيل
        couponAsk.current++;
        couponAsking.current = null;
        couponConfirmed.current = '';
        setCoupon(null);
        setCouponCode('');
        setCouponError('');
        setCouponLoading(false);
    }, []);

    const clear = useCallback(() => {
        setItems([]);
        removeCoupon();
        setRedeemActive(false);
    }, [removeCoupon]);

    /**
     * بدايةٌ نظيفة بعد بيعةٍ تمّت — السلّة **والزبون**.
     *
     * `clear` تُفرغ السلّة ويبقى الزبون: هذا صوابُ زرّ «إفراغ السلّة» — من
     * يُلغي أصنافًا لا يُلغي الزبون الواقف أمامه. لكنّه خطأٌ بعد البيع: يمشي
     * الزبون ويقف غيره، ويظلّ الاسم القديم معلّقًا في الشاشة. فتُقيَّد
     * البيعةُ التالية عليه، **وتُضاف نقاطُ ولائه إلى من لم يشترِ** — والنقاط
     * مالٌ يُستبدَل. ولا يُكتشف ذلك إلا حين يسأل صاحبها عن رصيدٍ ناقص.
     */
    const reset = useCallback(() => {
        clear();
        setCustomer(CASH_CUSTOMER);
        setCustomerId(null);
        setCustomerSearch('');
    }, [clear]);

    /* ------------------------------ الباركود ------------------------------ */

    const scanBarcode = useCallback(
        (code: string) => {
            const c = (code || '').trim();
            if (!c) return;
            // والماسح لا يستثني نفسه من الإيقاف ولا من القناة: صنفٌ لا يُباع هنا لا يدخل السلة
            const p = products.find(
                (x) =>
                    x.active !== false &&
                    x.pos_enabled !== false &&
                    ((x.barcode && String(x.barcode) === c) ||
                        (x.sku && String(x.sku).toLowerCase() === c.toLowerCase())),
            );
            if (!p) {
                onToast(`لا يوجد منتج بهذا الباركود: ${c}`, 'warning');
                setBarcode('');
                return;
            }
            add({ key: `p${p.id}`, id: p.id, name: p.label, price: p.price, image: p.image, stock: p.stock, tax: p.tax });
            setBarcode('');
        },
        [products, add, onToast],
    );

    /* ------------------------------ الكوبون ------------------------------ */

    /** سببُ سقوط الكوبون تحت حدّه — بلفظ الخادم نفسِه (`PosController::applyCoupon`) */
    const belowMinimum = (c: AppliedCoupon) =>
        t('الحد الأدنى للطلب :amount', { amount: money(Number(c.min_order ?? 0), currency) });

    /**
     * يسأل الخادمَ عن الكود للسلّة والزبون كما هما الآن.
     *
     * `manual`: الكاشيرُ ضغط «تطبيق» — فيُمحى خطؤه السابق ويُقال له «طُبّق».
     * وإعادةُ السؤال من تلقاء السلّة لا تقول شيئًا إن قُبل: ضجيجٌ مع كلّ نقرة.
     */
    const askCoupon = async (code: string, manual: boolean) => {
        const ask = ++couponAsk.current;
        couponAsking.current = { code, state: `${code}|${base}` };
        setCouponLoading(true);
        if (manual) setCouponError('');
        try {
            const res = await fetch('/pos/coupon', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...csrfHeaders(),
                    Accept: 'application/json',
                },
                /*
                 * والزبونُ يُرسل مع المعاينة.
                 *
                 * كودٌ حدُّه «مرّتان لكلّ زبون» يُقال عند السلّة لا بعد أن
                 * يُعلَن السعرُ للزبون. والمعاينةُ لا تحتسب استعمالًا —
                 * الاحتسابُ عند الدفع وحده (`CouponLimits::record`).
                 */
                body: JSON.stringify({ code, subtotal: base, customer_id: customerId, customer }),
            });
            const data = await res.json();
            // سؤالٌ أحدثُ سبقه، أو أُزيل الكوبون — فلا يُقرأ هذا الجواب
            if (ask !== couponAsk.current) return;
            if (!res.ok || !data.ok) {
                couponConfirmed.current = '';
                setCoupon(null);
                setCouponError(data.error || t('تعذّر تطبيق الكوبون'));
                return;
            }
            couponConfirmed.current = `${data.code}|${base}`;
            setCoupon({ code: data.code, type: data.type, value: data.value, min_order: Number(data.min_order ?? 0) });
            setCouponCode(data.code);
            setCouponError('');
            if (manual) onToast(data.message || t('تم تطبيق الكوبون'), 'success');
        } catch {
            if (ask !== couponAsk.current) return;
            // والكوبونُ المطبَّق يبقى: الدفعُ يفحصه على كلّ حال، والانقطاعُ ليس رفضًا
            setCouponError(t('تعذّر الاتصال. حاول مرة أخرى.'));
        } finally {
            if (ask === couponAsk.current) {
                couponAsking.current = null;
                setCouponLoading(false);
            }
        }
    };
    /*
     * أحدثُ نسخةٍ منه — بمجموعها وزبونها الآن.
     *
     * المؤقّتُ والمؤثّراتُ تناديه من هنا، فلا تتبدّل هي مع كلّ رسم: الصفحةُ
     * ترسل `onToast` دالّةً جديدةً في كلّ رسم، ومؤقّتٌ يتبعها يبدأ عدَّه من جديد
     * مع كلّ حرفٍ في خانة البحث.
     */
    const ask = useRef(askCoupon);
    ask.current = askCoupon;

    const applyCoupon = useCallback(
        async (codeArg?: string) => {
            const code = (codeArg ?? couponCode ?? '').trim();
            if (!code) return;
            await ask.current(code, true);
        },
        [couponCode],
    );

    /**
     * ═══ والسلّةُ إذا تبدّل مجموعُها يُعاد سؤالُ الخادم حين تهدأ ═══
     *
     * الخصمُ يتبع المجموعَ في الحال، والحدُّ الأدنى يُحكم به في الحال
     * (`couponShort`). وما سواه لا يعرفه إلّا الخادم: كودٌ نفدت مرّاتُه من صندوقٍ
     * آخر، أو أوقفه صاحبُ المحلّ، أو انتهى عند منتصف الليل والوردية مفتوحة.
     *
     * فيُسأل مرّةً حين تهدأ السلّة (`COUPON_SETTLE_MS`) — لا مع كلّ نقرة — وعن
     * مجموعٍ لم يؤكّده من قبل. وما زال تحت حدّه حين هدأت يُسقط بلا سؤال:
     * الجوابُ معروف، وسببُه يبقى للكاشير.
     *
     * والملاحظةُ والزبونُ لا يبدّلان المجموع، فلا يمرّان من هنا — والزبونُ له
     * سؤالُه في حينه أدناه.
     */
    useEffect(() => {
        if (!coupon) return;

        const state = `${coupon.code}|${base}`;
        if (couponConfirmed.current === state) return;

        const timer = setTimeout(() => {
            // سؤالٌ في الطريق عن الحال نفسِه يكفي
            if (couponAsking.current?.state === state) return;

            if (base < Number(coupon.min_order ?? 0)) {
                couponAsk.current++;
                couponAsking.current = null;
                couponConfirmed.current = '';
                setCoupon(null);
                setCouponLoading(false);
                setCouponError(belowMinimum(coupon));
                return;
            }

            void ask.current(coupon.code, false);
        }, COUPON_SETTLE_MS);

        return () => clearTimeout(timer);
    }, [coupon, base]);

    /**
     * ═══ وتغييرُ الزبون يُعيد فحصَ الكوبون في حينه ═══
     *
     * الكودُ قد يكون محدودًا «مرّتين لكلّ زبون». فلو طبّقه الكاشيرُ على أحمد
     * ثمّ بدّل الزبونَ إلى محمّد لَبقي خصمُ أحمد معروضًا على فاتورة محمّد —
     * ويُردّ عند الدفع بعد أن أُعلن السعر. وإن كان محمّدٌ قد بلغ حدَّه فالخصمُ
     * يُسقط هنا ويُقال السبب، لا عند الدرج.
     *
     * والنداءُ هو نداءُ المعاينة نفسُه: الخادمُ يقول، والشاشةُ لا تفترض. وهو
     * لا يحتسب استعمالًا — الاحتسابُ عند الدفع وحدَه. والدفعُ يُعيد الفحصَ
     * مرّةً أخيرةً على كلّ حال، فنجاحُ المعاينة ليس إذنًا.
     *
     * ويُسأل في الحال لا حين تهدأ السلّة. والكودُ الذي ما زال سؤالُه في الطريق
     * — ضغط «تطبيق» ثمّ بدّل الزبونَ قبل الجواب — يُسأل عنه للجديد كذلك،
     * والسؤالُ الجديد يُبطل ما قبله: لا يصل جوابُ أحمد فيُطبَّق على محمّد.
     */
    const couponCustomer = useRef<string>(`${customerId ?? ''}|${customer}`);

    useEffect(() => {
        const now = `${customerId ?? ''}|${customer}`;

        if (couponCustomer.current === now) {
            return;
        }

        couponCustomer.current = now;

        const code = couponAsking.current?.code ?? coupon?.code;

        if (!code) {
            return;
        }

        void ask.current(code, false);
    }, [customerId, customer, coupon]);

    /**
     * إعادة تطبيق كوبون الطلب المستأنف — مرة واحدة عند فتح السلة.
     *
     * التعليق يحفظ الكود لا قيمة الخصم، فنسأل الخادم من جديد: الكوبون قد
     * يكون انتهى أو نفدت مرات استخدامه بين التعليق والاستكمال. وإن رُفض
     * ظهر سبب الرفض للكاشير بدل أن يختفي الخصم صامتًا.
     */
    const resumedCoupon = useRef(false);
    useEffect(() => {
        const code = resume?.coupon_code;
        if (!code || resumedCoupon.current || items.length === 0) return;
        resumedCoupon.current = true;
        void applyCoupon(code);
    }, [resume, items.length, applyCoupon]);

    /* ------------------------------ العملاء ------------------------------ */

    const selectCustomer = useCallback((name: string, id: number | null = null) => {
        setCustomer(name);
        setCustomerId(id);
        setCustomerSearch('');
        setBlockOverrideReason('');
    }, []);

    /** يضيف عميلًا ثم يحدّده للطلب الجاري بلا إعادة تحميل تُفقد السلة */
    const addCustomer = useCallback(
        async (form: HTMLFormElement): Promise<boolean> => {
            const fd = new FormData(form);
            const name = (fd.get('name') || '').toString().trim();
            if (!name) {
                onToast(t('أدخل اسم العميل'), 'warning');
                return false;
            }
            if (!fd.get('language')) {
                onToast(t('اختر لغة رسائل واتساب للعميل'), 'warning');
                return false;
            }
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { ...csrfHeaders(), Accept: 'application/json' },
                    body: fd,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    onToast(data.message || t('تعذّر إضافة العميل'), 'danger');
                    return false;
                }
                const c = data.customer;
                setCustomers((prev) => [
                    { id: c.id, name: c.name, name_en: c.name_en ?? null, label: c.label || c.name, phone: c.phone || '', points: 0, language: c.language ?? null },
                    ...prev,
                ]);
                setCustomer(c.name);
                setCustomerId(c.id);
                setCustomerSearch('');
                form.reset();
                onToast(t('تم إضافة العميل وتحديده للطلب'), 'success');
                return true;
            } catch {
                onToast(t('تعذّر الاتصال. حاول مرة أخرى.'), 'danger');
                return false;
            }
        },
        [onToast],
    );

    /* ------------------ صمود الانقطاع: طابور محلي (outbox) ------------------ */

    const savePending = useCallback((list: OutboxEntry[]) => {
        localStorage.setItem(OUTBOX_KEY, JSON.stringify(list));
        setPending(list);
    }, []);

    const sendOne = useCallback(
        async (
            payload: Record<string, unknown>,
        ): Promise<{
            ok: boolean;
            drop?: boolean;
            invoice?: string;
            points?: number;
            redeemed?: number;
            /** رصيدُ العميل بعد البيعة — كما في القاعدة، لا محسوبًا هنا */
            balance?: number | null;
            error?: string;
        }> => {
            try {
                const res = await fetch('/pos/checkout', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...csrfHeaders(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                if (res.ok) {
                    const d = await res.json();
                    return {
                        ok: true,
                        invoice: d.invoice,
                        points: d.points_earned || 0,
                        redeemed: d.points_redeemed || 0,
                        /*
                         * و`null` تعني «لم يُردّ رصيد» لا «صفر».
                         *
                         * الردُّ المكرَّر (بيعةٌ أُعيد رفعها) لا يحمل نقاطًا:
                         * لم يُخصم شيءٌ جديد. وصفرٌ يُكتب فوق رصيدٍ قائم
                         * يقول للكاشير إنّ العميل أنفق نقاطه كلَّها.
                         */
                        balance: typeof d.points_balance === 'number' ? d.points_balance : null,
                    };
                }
                // 419 جلسة منتهية و422 بيانات مرفوضة — إعادة المحاولة بلا فائدة.
                // نستخرج السبب: الخادم يرفض هنا نقص المخزون وصنفًا غير معروف،
                // وإسقاط البيع صامتًا يترك الكاشير بلا تفسير.
                // 409 لا وردية مفتوحة: إعادة المحاولة لن تفتحها، والبيعة
                // تُسقَط برسالتها كي يفتح الكاشير الوردية ثم يعيد البيع
                if (res.status === 422 || res.status === 419 || res.status === 409) {
                    let error = '';
                    try {
                        const d = await res.json();
                        error = Object.values(d?.errors ?? {}).flat().join(' · ') || d?.message || '';
                    } catch {
                        /* رد بلا JSON — نكتفي برسالة عامة */
                    }
                    return { ok: false, drop: true, error: error || t('تعذّر إتمام البيع') };
                }
                return { ok: false };
            } catch {
                return { ok: false }; // خطأ شبكة → يبقى في الطابور
            }
        },
        [],
    );

    const flushOutbox = useCallback(async () => {
        if (flushing.current || !navigator.onLine) return;
        // بيعة قيد الإرسال الآن ليست بيعة عالقة: البيع يُكتب في الطابور قبل
        // إرساله (حتى لا يضيع لو انطفأ الجهاز)، فلو صادف النبض تلك النافذة
        // أعاد إرسال الطلب نفسه — يردّه الخادم مقبولًا لتكرّر المعرّف، فيظهر
        // للكاشير «تمت مزامنة طلب مُعلّق» في بيعة عادية متصلة.
        const queue = readOutbox().filter((e) => !inFlight.current.has(e.uuid));
        if (!queue.length) return;

        flushing.current = true;
        try {
            let remaining = readOutbox();
            for (const entry of queue) {
                const res = await sendOne(entry.payload);
                if (res.ok || res.drop) {
                    remaining = remaining.filter((x) => x.uuid !== entry.uuid);
                    savePending(remaining);
                    if (res.ok) {
                        onToast(t('تمت مزامنة طلب مُعلّق ✓'), 'success');
                        onSynced?.();
                    } else if (res.error) {
                        // رُفض بعد عودة الاتصال (نفد المخزون مثلًا) — يجب أن يُعلَم لا أن يختفي
                        onToast(t('طلب مُعلّق رُفض: :error', { error: res.error }), 'danger');
                    }
                } else {
                    break; // الشبكة ما زالت منقطعة — نعيد لاحقًا
                }
            }
        } finally {
            flushing.current = false;
        }
    }, [sendOne, savePending, onToast, onSynced]);

    useEffect(() => {
        const goOnline = () => {
            setOnline(true);
            void flushOutbox();
        };
        const goOffline = () => setOnline(false);
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);
        // نبض: يلتقط عودة الاتصال حتى لو لم يُطلَق حدث online
        const beat = setInterval(() => {
            setOnline(navigator.onLine);
            void flushOutbox();
        }, 8000);
        void flushOutbox();

        return () => {
            window.removeEventListener('online', goOnline);
            window.removeEventListener('offline', goOffline);
            clearInterval(beat);
        };
    }, [flushOutbox]);

    /** يحفظ البيع محليًا أولًا ثم يحاول رفعه — لا ينتظر الشبكة لإتمامه */
    const checkoutSale = useCallback(
        /*
         * `details` تفاصيل طلب الورد — اختياريّة كلّها.
         *
         * تُضمّ إلى الحمولة كما هي ولا تُحسب منها قيمة: الخادم يتحقّق منها
         * ويقرّر الإلزام (التوصيل يلزمه مستلِمٌ وعنوان). وتدخل الطابور مع
         * البيعة، فبيعةٌ سُجّلت بلا اتصال ترفع موعدها ومستلِمها معها عند
         * عودة الشبكة — ولا تصل ناقصةً بعد ساعة.
         */
        async (method: string, details?: Record<string, unknown>): Promise<CheckoutResult> => {
            if (needsLanguage) {
                onToast(t('اختر لغة رسائل واتساب للعميل قبل إتمام البيع.'), 'warning');
                return { synced: false, invoice: null, points: 0, rejected: true };
            }
            if (blocked && blockOverrideReason.trim() === '') {
                onToast(t('البيع لهذا العميل موقوف.'), 'danger');
                return { synced: false, invoice: null, points: 0, rejected: true };
            }
            const id = uuid();
            const payload = {
                client_uuid: id,
                items: items.map((i) => ({
                    id: i.id ?? null,
                    addon_id: i.addon_id ?? null,
                    // المقاس بمعرّفه لا بسعره: الخادم يقرأ السعر من القاعدة
                    variant_id: i.variant_id ?? null,
                    addons: (i.addons ?? []).map((a) => ({ addon_id: a.addon_id, qty: a.qty })),
                    name: i.name,
                    price: i.price,
                    qty: i.qty,
                    note: i.note ?? '',
                    // الطلبُ المخصَّص — الخادمُ يقرأ منه السعرَ والموادّ، ويحسب التكلفة بنفسه
                    custom: i.custom ?? null,
                    // موسمُ البند كما اختير — والخادمُ يقرّر (SeasonSales::attribute)
                    season_id: i.season_id ?? null,
                })),
                customer,
                customer_id: customerId,
                customer_language: selectedCustomer?.language ?? null,
                block_override_reason: blocked && blockOverrideReason.trim() !== '' ? blockOverrideReason.trim() : null,
                payment_method: method,
                // الخصم والضريبة والإجمالي تُحتسب خادميًا من أسعار القاعدة؛
                // ما يلي معروض للمستخدم فقط ولا يُقيَّد كما هو
                delivery_fee: 0,
                resume_id: resumeId,
                // الكودُ الذي يخصم على الشاشة وحدَه — وما نزلت السلّةُ تحت حدّه لا يُرسل
                coupon_code: activeCoupon?.code ?? null,
                redeem_points: redeemPointsUsed,
                ...(details ?? {}),
            };

            const queue = [...readOutbox(), { uuid: id, payload, at: Date.now() }];
            savePending(queue);

            // مُعلَّم كجارٍ حتى لا يلتقطه نبض الطابور ويُرسله مرّة ثانية
            inFlight.current.add(id);
            let res: Awaited<ReturnType<typeof sendOne>>;
            try {
                res = await sendOne(payload);
            } finally {
                inFlight.current.delete(id);
            }

            if (res.ok || res.drop) {
                savePending(readOutbox().filter((p) => p.uuid !== id));
            }
            if (res.ok) {
                /*
                 * ═══ ورصيدُ نقاطه يُصحَّح في الحال ═══
                 *
                 * `customers` حالةٌ محليّة تُملأ مرّةً عند فتح الشاشة، وما
                 * يُجلب بعد البيع هو المنتجات وحدها. فكان الرصيدُ يبقى كما
                 * كان قبل الخصم: يُستبدَل من خمسمئة فتبقى الشاشة تقول
                 * «خمسمئة» — ثمّ تُرفض البيعةُ التالية بـ«تغيّر رصيد نقاط
                 * العميل» ولا يفهم الكاشير لماذا.
                 *
                 * والمعرّفُ شرطٌ: زبونٌ نقديٌّ بلا معرّف لا رصيد له يُكتب،
                 * ومطابقةٌ بالاسم تُصيب جاره.
                 */
                if (customerId !== null && typeof res.balance === 'number') {
                    setCustomers((prev) =>
                        prev.map((c) => (c.id === customerId ? { ...c, points: res.balance as number } : c)),
                    );
                }

                onSynced?.();
            }
            if (res.drop && res.error) {
                onToast(res.error, 'danger');
            }
            setOnline(navigator.onLine);

            return {
                synced: !!res.ok,
                invoice: res.invoice ?? null,
                points: res.points ?? 0,
                redeemed: res.redeemed ?? 0,
                balance: res.balance ?? null,
                rejected: !!res.drop,
            };
        },
        [items, customer, customerId, selectedCustomer, needsLanguage, blocked, blockOverrideReason, resumeId, activeCoupon, redeemPointsUsed, savePending, sendOne, onToast, onSynced],
    );

    /** تعليق الطلب أو حفظه — نفس نقطة النهاية باختلاف kind */
    const holdOrder = useCallback(
        async (kind: 'hold' | 'save') => {
            if (!items.length) return;
            await fetch('/pos/hold', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...csrfHeaders(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    items: items.map((i) => ({
                        id: i.id ?? null,
                        addon_id: i.addon_id ?? null,
                        variant_id: i.variant_id ?? null,
                        addons: (i.addons ?? []).map((a) => ({ addon_id: a.addon_id, qty: a.qty })),
                        name: i.name,
                        qty: i.qty,
                        note: i.note ?? '',
                        // وتُعلَّق بموادّها: سلّةٌ تعود ناقصةً تبيع ما لا يَنقص الرفُّ به
                        custom: i.custom ?? null,
                        season_id: i.season_id ?? null,
                    })),
                    customer,
                    // الكوبون يُعلَّق مع الطلب: بدونه كان الكاشير يطبّق خصمًا،
                    // يعلّق الطلب، ثم يستكمله فيدفع الزبون السعر كاملًا بلا
                    // أن ينتبه أحد. الخادم يُعيد التحقق منه عند الدفع دائمًا.
                    coupon_code: activeCoupon?.code ?? null,
                    kind,
                }),
            });
            clear();
            onToast(kind === 'hold' ? t('تم تعليق الطلب') : t('تم حفظ الطلب'), kind === 'hold' ? 'warning' : 'success');
        },
        [items, customer, activeCoupon, clear, onToast, t],
    );

    return {
        // الحالة
        items, customer, customers, customerSearch, barcode,
        couponCode, coupon, couponLoading,
        // سببُ الرفض — أو أنّ السلّة نزلت تحت الحدّ ولم تهدأ بعد
        couponError: couponError || (coupon && couponShort ? belowMinimum(coupon) : ''),
        redeemActive, online, pendingCount: pending.length,
        customerLabel, isWalkIn,
        // المحسوبات
        count, subtotal, couponDiscount, discountAmount, taxAmount, total, displayTotal, vatRate,
        selectedCustomer, selectedPoints, canRedeem, pointsToThreshold, needsLanguage, blocked, blockOverrideReason,
        redeemCap, redeemDiscount, redeemPointsUsed, redeemMaxPct, redeemMin,
        pointsToEarn, hasStockWarning, filteredCustomers,
        // الأفعال
        add, inc, dec, remove, setNote, clear,
        setBarcode, scanBarcode,
        setCouponCode, applyCoupon, removeCoupon,
        setRedeemActive, selectCustomer, setCustomerSearch, addCustomer, setCustomerLanguage, setBlockOverrideReason,
        reset,
        checkoutSale, holdOrder, overStock, replace,
    };
}

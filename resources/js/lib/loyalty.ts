import { exact, halfUp, milli, type Ratio } from '@/lib/baisa';

/**
 * مرآةُ `App\Support\Loyalty::quote` — حرفًا بحرف.
 *
 * الصندوقُ يعمل بلا اتّصال أحيانًا، فيحسب الخصمَ على الشاشة ليعرضه قبل أن
 * يُرسل البيعة. والخادمُ يحسبه ثانيةً ليكتبه. ولو افترقا لقرأ الزبونُ رقمًا
 * ودفع غيرَه — فالحالاتُ المشتركة في `tests/fixtures/loyalty-quote-cases.json`
 * يقرؤها اختبارُ PHP واختبارُ vitest معًا، وأيُّ فرقٍ يُسقط أحدَهما.
 */

/** `Loyalty::FIXED` و`Loyalty::PERCENT` — مبلغٌ ثابتٌ لكلّ نقاط، أو نسبةٌ من الفاتورة المؤهّلة */
export type LoyaltyMode = 'fixed' | 'percent';

export interface LoyaltyRules {
    on: boolean;
    redeem: boolean;
    min_points: number;
    /** غيابُه (حالاتٌ وردودٌ أقدم) = مبلغٌ ثابت */
    mode?: LoyaltyMode;
    points: number;
    value: number;
    /** نسبةُ كلّ `points` نقطةً من الفاتورة المؤهّلة — في طريقة النسبة وحدها */
    percent?: number;
    min_invoice: number;
    /** سقفٌ اختياريٌّ بالنسبة من المجموع — `null` بلا حدّ */
    max_pct: number | null;
    /** سقفٌ اختياريٌّ بالمبلغ — `null` أو غيابُه بلا حدّ */
    max_amount?: number | null;
}

export type LoyaltyReason = 'off' | 'redeem_off' | 'below_min_points' | 'below_min_invoice' | 'nothing_to_discount';

export interface LoyaltyQuote {
    eligible: boolean;
    reason: LoyaltyReason | null;
    balance: number;
    max_points: number;
    max_discount: number;
    points: number;
    discount: number;
}

/**
 * `round($x, $d)` في PHP 8.4 — منقولةٌ خطوةً بخطوة (`_php_math_round`، نصفٌ بعيدًا عن الصفر).
 *
 * كانت هنا تقريبةٌ بـ`toFixed(9)` تُدوّر ما يقع على نصف البيسة إلى أعلى
 * دائمًا. وPHP 8.4 تقارن الرقمَ المخزَّن نفسَه بحدّ النصف: ٣٧٣٥٫٠٣٣٥ المحسوبةُ
 * من ١٤٩٤٠٫١٣٤ × ٢٥ ÷ ١٠٠ تُخزَّن تحت النصف بجزءٍ ضئيل فتصير ٣٧٣٥٫٠٣٣، والمرآةُ
 * كانت تقول ٣٧٣٥٫٠٣٤ — فيختلف السقفُ بيسةً وعددُ النقاط نقطة. والآن الخطواتُ
 * نفسُها على أرقام IEEE نفسِها، فالنتيجةُ واحدة (`loyalty-quote-random-cases.json`).
 */
export function phpRound(x: number, d: number): number {
    if (!Number.isFinite(x) || x === 0) return x;

    const e = 10 ** Math.abs(d);
    const scaled = d > 0 ? x * e : x / e;
    let t = x >= 0 ? Math.floor(scaled) : Math.ceil(scaled);
    const t2 = x >= 0 ? t + 1 : t - 1;
    if ((d > 0 ? t2 / e : t2 * e) === x) t = t2;

    // ما فوق دقّة العدد لا يُدوَّر
    if (Math.abs(t) >= 1e16) return x;

    // `copysign(…, t)`: الصفرُ السالب سالب
    const sign = t > 0 || Object.is(t, 0) ? 1 : -1;
    const edge = Math.abs(d > 0 ? (t + sign * 0.5) / e : (t + sign * 0.5) * e);
    if (Math.abs(x) >= edge) t += sign;

    return d > 0 ? t / e : t * e;
}

const isPercent = (rules: Pick<LoyaltyRules, 'mode'>) => rules.mode === 'percent';

/*
 * ═══ والحسابُ بأعدادٍ صحيحة: بيساتٌ ونسبٌ مضبوطة (`BigInt`) ═══
 *
 * مرآةُ `Loyalty::exact` و`milli` و`perPoint` خطوةً بخطوة. كان بكسورٍ عائمة
 * يُرمَّم ضجيجُها بـ`round(…, 6)`، فتقع حالاتُ الحدّ على هذا الجانب أو ذاك
 * بحسب تمثيل الرقم. والآن لا كسرَ عائمًا في القرار، فالخادمُ والشاشةُ واحد.
 */
// والأدواتُ مشتركةٌ مع محرّك الضريبة (`vat-lines.ts`) — من موضعٍ واحد

/** ما بقي بعد الخصومات الأخرى بالبيسات — ولا سالب */
function remainder(baseB: bigint, other: number): bigint {
    const [on, od] = exact(other);
    const left = baseB * od - on * 1000n;
    return left > 0n ? halfUp(left, od) : 0n;
}

/** قيمةُ النقطة الواحدة بالبيسات — نسبةٌ مضبوطة */
function perPoint(rules: Pick<LoyaltyRules, 'points' | 'value' | 'mode' | 'percent'>, eligibleB: bigint): Ratio {
    const per = BigInt(Math.max(1, Math.trunc(rules.points)));
    if (isPercent(rules)) {
        const [pn, pd] = exact(Math.max(0, Number(rules.percent ?? 0)));
        return [eligibleB * pn, pd * 100n * per];
    }
    const [vn, vd] = exact(Math.max(0, Number(rules.value)));
    return [vn * 1000n, vd * per];
}

/** قيمةُ عددٍ من النقاط بالبيسات — مقصوصة */
function worthBaisa(rules: Pick<LoyaltyRules, 'points' | 'value' | 'mode' | 'percent'>, points: number, eligibleB: bigint): bigint {
    if (points <= 0 || rules.points <= 0) return 0n;
    const [vn, vd] = perPoint(rules, eligibleB);
    return (BigInt(Math.trunc(points)) * vn) / vd;
}

/** السقفُ بالبيسات — وحدُّ صاحب النشاط يُقصّ ولا يُدوَّر (`Loyalty::capBaisa`) */
function capBaisa(rules: Pick<LoyaltyRules, 'max_pct' | 'max_amount'>, baseB: bigint, remB: bigint): bigint {
    let cap = remB;
    if (rules.max_pct !== null && rules.max_pct !== undefined) {
        const [mn, md] = exact(Math.max(0, Number(rules.max_pct)));
        const byPct = (baseB * mn) / (md * 100n);
        if (byPct < cap) cap = byPct;
    }
    if (rules.max_amount !== null && rules.max_amount !== undefined) {
        const [an, ad] = exact(Math.max(0, Number(rules.max_amount)));
        const byAmount = (an * 1000n) / ad;
        if (byAmount < cap) cap = byAmount;
    }
    return cap < 0n ? 0n : cap;
}

/**
 * قيمةُ عددٍ من النقاط — تُقصّ إلى البيسة لا تُدوَّر (`Loyalty::worth`).
 *
 * و`eligible` الفاتورةُ المؤهّلة: لا تُقرأ إلّا في طريقة النسبة.
 */
export function loyaltyWorth(
    rules: Pick<LoyaltyRules, 'points' | 'value' | 'mode' | 'percent'>,
    points: number,
    eligible = 0,
): number {
    return Number(worthBaisa(rules, points, milli(Math.max(0, eligible)))) / 1000;
}

/** أقلُّ عددٍ يبلغ قيمةَ `points` نفسَها — `Loyalty::fewest`، قسمةٌ صحيحة لا تقدير */
function loyaltyFewest(rules: LoyaltyRules, points: number, eligibleB: bigint): number {
    const worth = worthBaisa(rules, points, eligibleB);
    if (points <= 0 || worth <= 0n) return 0;

    const [vn, vd] = perPoint(rules, eligibleB);
    const fewest = Number((worth * vd + vn - 1n) / vn);

    return Math.max(1, Math.min(points, fewest));
}

/** أقصى ما يُخصم بالنقاط من هذه الفاتورة — `Loyalty::cap` */
export function loyaltyCap(rules: Pick<LoyaltyRules, 'max_pct' | 'max_amount'>, base: number, remaining: number): number {
    return Number(capBaisa(rules, milli(Math.max(0, base)), milli(Math.max(0, remaining)))) / 1000;
}

export function loyaltyQuote(
    rules: LoyaltyRules,
    balanceIn: number | null | undefined,
    baseIn: number,
    other = 0,
    requested: number | null = null,
): LoyaltyQuote {
    const balance = Math.max(0, Math.trunc(Number(balanceIn) || 0));
    const none = (reason: LoyaltyReason): LoyaltyQuote => ({
        eligible: false,
        reason,
        balance,
        max_points: 0,
        max_discount: 0,
        points: 0,
        discount: 0,
    });

    if (!rules.on) return none('off');
    if (!rules.redeem) return none('redeem_off');

    const baseB = milli(Math.max(0, baseIn));
    const remB = remainder(baseB, Math.max(0, other));

    if (balance <= 0 || balance < rules.min_points) return none('below_min_points');
    if (remB === 0n) return none('nothing_to_discount');
    const [minN, minD] = exact(Math.max(0, Number(rules.min_invoice)));
    if (remB * minD < minN * 1000n) return none('below_min_invoice');

    const capB = capBaisa(rules, baseB, remB);
    const [vn, vd] = perPoint(rules, remB);
    if (vn <= 0n) return none('nothing_to_discount');

    // أكبرُ عددٍ لا تتجاوز قيمتُه السقف — ولا كسرَ نقطة
    const byCap = (capB * vd) / vn;
    const max = loyaltyFewest(rules, byCap < BigInt(balance) ? Number(byCap) : balance, remB);

    if (max <= 0) return none('nothing_to_discount');

    const points =
        requested === null ? max : loyaltyFewest(rules, Math.max(0, Math.min(Math.trunc(requested), max)), remB);

    return {
        eligible: true,
        reason: null,
        balance,
        max_points: max,
        max_discount: Number(worthBaisa(rules, max, remB)) / 1000,
        points,
        discount: Number(worthBaisa(rules, points, remB)) / 1000,
    };
}

/**
 * كم بالمئة من الفاتورة المؤهّلة يخصمها عددٌ من النقاط — في طريقة النسبة.
 *
 * للعرض بجانب الرصيد («٥٠٠ نقطة = ٥٠٪»)، لا للحساب: الحسابُ `loyaltyQuote`.
 * ولا يتجاوز مئة — ما فوقها لا يُخصم من فاتورةٍ واحدة.
 */
export function loyaltyPercentOf(rules: Pick<LoyaltyRules, 'points' | 'percent'>, points: number): number {
    if (points <= 0 || rules.points <= 0) return 0;
    const [pn, pd] = exact(Math.max(0, Number(rules.percent ?? 0)));
    const hundredths = (BigInt(Math.trunc(points)) * pn * 100n) / (pd * BigInt(Math.trunc(rules.points)));
    return hundredths > 10000n ? 100 : Number(hundredths) / 100;
}

/** لماذا لا يُستبدل — جملةٌ تُعرض تحت رصيد العميل */
export function loyaltyReasonText(reason: LoyaltyReason | null, t: (s: string) => string): string | null {
    switch (reason) {
        case 'redeem_off':
            return t('الاستبدال متوقّف في إعدادات برنامج الولاء — النقاط تتراكم.');
        case 'below_min_points':
            return t('الرصيد دون الحدّ الأدنى للاستبدال — النقاط تتراكم.');
        case 'below_min_invoice':
            return t('قيمة الفاتورة دون الحدّ الأدنى للاستبدال.');
        case 'nothing_to_discount':
            return t('لا يبقى في الفاتورة ما يُخصم بالنقاط.');
        default:
            return null;
    }
}

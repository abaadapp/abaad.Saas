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

const round = phpRound;

const isPercent = (rules: Pick<LoyaltyRules, 'mode'>) => rules.mode === 'percent';

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
    if (points <= 0 || rules.points <= 0) return 0;
    if (isPercent(rules)) {
        const e = Math.max(0, round(eligible, 3));
        return Math.floor(round(((((points * e * Number(rules.percent ?? 0)) / 100) / rules.points) * 1000), 6)) / 1000;
    }
    return Math.floor(round(((points * rules.value) / rules.points) * 1000, 6)) / 1000;
}

/** أقلُّ عددٍ يبلغ قيمةَ `points` نفسَها — `Loyalty::fewest` */
function loyaltyFewest(rules: LoyaltyRules, points: number, eligible: number): number {
    const worth = loyaltyWorth(rules, points, eligible);
    if (points <= 0 || worth <= 0) return 0;

    const unit = isPercent(rules)
        ? (Math.max(0, round(eligible, 3)) * Number(rules.percent ?? 0)) / 100 / rules.points
        : rules.value / rules.points;

    let fewest = Math.max(1, Math.min(points, Math.ceil(round(worth / unit, 6))));
    while (fewest < points && loyaltyWorth(rules, fewest, eligible) < worth) fewest++;
    while (fewest > 1 && loyaltyWorth(rules, fewest - 1, eligible) >= worth) fewest--;

    return fewest;
}

/** أقصى ما يُخصم بالنقاط من هذه الفاتورة — `Loyalty::cap` */
export function loyaltyCap(rules: Pick<LoyaltyRules, 'max_pct' | 'max_amount'>, base: number, remaining: number): number {
    let cap = Math.max(0, remaining);
    if (rules.max_pct !== null && rules.max_pct !== undefined) cap = Math.min(cap, round((base * rules.max_pct) / 100, 3));
    if (rules.max_amount !== null && rules.max_amount !== undefined) cap = Math.min(cap, round(Number(rules.max_amount), 3));
    return Math.max(0, cap);
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

    const base = Math.max(0, round(baseIn, 3));
    const remaining = Math.max(0, round(base - Math.max(0, other), 3));

    if (balance <= 0 || balance < rules.min_points) return none('below_min_points');
    if (remaining <= 0) return none('nothing_to_discount');
    if (remaining < rules.min_invoice) return none('below_min_invoice');

    const cap = loyaltyCap(rules, base, remaining);
    let byCap = isPercent(rules)
        ? Math.floor(round((cap * rules.points * 100) / (remaining * Number(rules.percent ?? 0)), 6))
        : Math.floor(round((cap * rules.points) / rules.value, 6));
    // والتقريبُ لا يرفع نقطةً فوق السقف — مرآةُ `Loyalty::quote` حرفًا بحرف
    while (byCap > 0 && round(loyaltyWorth(rules, byCap, remaining) - cap, 3) > 0) byCap--;
    const max = loyaltyFewest(rules, Math.min(balance, byCap), remaining);

    if (max <= 0) return none('nothing_to_discount');

    const points =
        requested === null ? max : loyaltyFewest(rules, Math.max(0, Math.min(Math.trunc(requested), max)), remaining);

    return {
        eligible: true,
        reason: null,
        balance,
        max_points: max,
        max_discount: loyaltyWorth(rules, max, remaining),
        points,
        discount: loyaltyWorth(rules, points, remaining),
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
    return Math.min(100, Math.floor(round(((points * Number(rules.percent ?? 0)) / rules.points) * 100, 6)) / 100);
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

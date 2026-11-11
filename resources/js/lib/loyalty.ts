/**
 * مرآةُ `App\Support\Loyalty::quote` — حرفًا بحرف.
 *
 * الصندوقُ يعمل بلا اتّصال أحيانًا، فيحسب الخصمَ على الشاشة ليعرضه قبل أن
 * يُرسل البيعة. والخادمُ يحسبه ثانيةً ليكتبه. ولو افترقا لقرأ الزبونُ رقمًا
 * ودفع غيرَه — فالحالاتُ المشتركة في `tests/fixtures/loyalty-quote-cases.json`
 * يقرؤها اختبارُ PHP واختبارُ vitest معًا، وأيُّ فرقٍ يُسقط أحدَهما.
 */

export interface LoyaltyRules {
    on: boolean;
    redeem: boolean;
    min_points: number;
    points: number;
    value: number;
    min_invoice: number;
    max_pct: number;
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

/** `round($x, $d)` في PHP — نصفٌ بعيدًا عن الصفر */
function round(x: number, d: number): number {
    const f = 10 ** d;
    const v = Math.abs(x) * f;
    // تصحيحُ تمثيل الفاصلة: ٢٫٦٧٥ تُخزَّن ٢٫٦٧٤٩٩… فتُدوَّر إلى أسفل
    const r = Math.round(Number(v.toFixed(9)));
    return (Math.sign(x) * r) / f;
}

/** قيمةُ عددٍ من النقاط — تُقصّ إلى البيسة لا تُدوَّر (`Loyalty::worth`) */
export function loyaltyWorth(rules: Pick<LoyaltyRules, 'points' | 'value'>, points: number): number {
    if (points <= 0 || rules.points <= 0) return 0;
    return Math.floor(round(((points * rules.value) / rules.points) * 1000, 6)) / 1000;
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

    const cap = Math.min(round((base * rules.max_pct) / 100, 3), remaining);
    const byCap = Math.floor(round((cap * rules.points) / rules.value, 6));
    const max = Math.min(balance, byCap);

    if (max <= 0) return none('nothing_to_discount');

    const points = requested === null ? max : Math.max(0, Math.min(Math.trunc(requested), max));

    return {
        eligible: true,
        reason: null,
        balance,
        max_points: max,
        max_discount: loyaltyWorth(rules, max),
        points,
        discount: loyaltyWorth(rules, points),
    };
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

import type { Currency } from '@/types';

/** العملات ذات ثلاث منازل عشرية — مطابق لـMoney::THREE على الخادم */
const THREE_DECIMAL = ['OMR', 'KWD', 'BHD'];

/** دقّةُ التخزين — كلُّ عمودٍ ماليّ `decimal(…, 3)`. مطابق لـMoney::SCALE */
const SCALE = 3;

export function decimalsFor(code: string): number {
    return THREE_DECIMAL.includes(code) ? 3 : 2;
}

/* و`toFixed` لا الضربُ في قوّةِ عشرة: 1.005 * 100 = 100.49999999999999 في العائم */
function round(value: number, decimals: number): number {
    return Number(value.toFixed(decimals));
}

/**
 * كم منزلةً تُكتب لهذا المبلغ بعينه — نسخةُ `Money::shown` على الخادم.
 *
 * الحسابُ يُقرَّب إلى ثلاثِ منازل دائمًا، والعرضُ إلى منازل العملة. وبينهما
 * فجوةٌ لعملةٍ منزلتاها اثنتان: إجماليٌّ مخزَّنٌ 12.894 كان يُكتب «12.89»،
 * فيدفع الزبونُ ما قرأ ويبقى 0.004 لا تُغلَق الورقةُ دونه.
 *
 * فلا تُحذف منزلةٌ فيها رقم: تُكتب منازلُ العملة ما دامت تكفي، فإن أخفت
 * شيئًا امتدّت حتى تقوله. و«5.25» تبقى كما هي — الصفرُ لا يحمل خبرًا.
 *
 * وهي هنا لأنّ اللوحةَ والورقةَ يجب أن تكتبا المبلغ نفسه حرفًا بحرف.
 */
export function shownDecimals(value: number, chosen: number): number {
    if (chosen >= SCALE) return chosen;

    const stored = round(value, SCALE);
    for (let d = chosen; d < SCALE; d++) {
        if (Math.abs(round(value, d) - stored) < 0.0005) return d;
    }

    return SCALE;
}

/**
 * تنسيق المبلغ بعملة العرض — يطابق Demo::money حرفيًا:
 * يضرب في سعر الصرف، ثم يفصل الآلاف بفاصلة، ثم يلحق الرمز.
 */
/**
 * الرمز المعروض بجانب المبلغ.
 *
 * رمز الريال «ر.ع» عربيٌّ، وكان يُطبع كما هو في الواجهة الإنجليزية — فيرى
 * الموظف الذي لا يقرأ العربية حرفين لا يفهمهما بجانب كل رقم في النظام، وهو
 * نقضٌ لغرض الواجهة الإنجليزية من أصله. في الإنجليزية يُستبدل بالرمز الدولي
 * (OMR). أما الرموز اللاتينية ($ و€) فتبقى كما هي: مفهومة في اللغتين.
 */
export function currencyLabel(currency: Currency): string {
    const symbol = currency.symbol || currency.code;
    const arabic = /[\u0600-\u06FF]/.test(symbol);
    const english = typeof document !== 'undefined' && document.documentElement.lang === 'en';

    return arabic && english ? currency.code : symbol;
}

export function money(value: number | string | null | undefined, currency: Currency): string {
    const amount = Number(value ?? 0) * (currency.rate ?? 1);
    // ما اختاره التاجر أوّلًا، ثم الاشتقاق من رمز العملة — والخادم يرسل الاثنين
    const chosen = currency.decimals ?? decimalsFor(currency.code);
    const decimals = shownDecimals(amount, chosen);
    const text = amount.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
    const symbol = currencyLabel(currency);

    return currency.before ? `${symbol} ${text}` : `${text} ${symbol}`;
}

/** رقم بلا عملة، بفاصلة آلاف */
export function number(value: number | string | null | undefined, decimals = 0): string {
    return Number(value ?? 0).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

/** نسبة مئوية بعلامتها */
export function percent(value: number | null | undefined, decimals = 0): string {
    const n = Number(value ?? 0);
    return `${n > 0 ? '+' : ''}${n.toFixed(decimals)}%`;
}

/** الأحرف الأولى للاسم — بديل الصورة الرمزية */
export function initials(name: string | null | undefined): string {
    if (!name) return '؟';
    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('');
}

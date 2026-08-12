import type { Currency } from '@/types';

/** العملات ذات ثلاث منازل عشرية — مطابق لـDemo::formatMoney على الخادم */
const THREE_DECIMAL = ['OMR', 'KWD', 'BHD'];

export function decimalsFor(code: string): number {
    return THREE_DECIMAL.includes(code) ? 3 : 2;
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
    const decimals = currency.decimals ?? decimalsFor(currency.code);
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

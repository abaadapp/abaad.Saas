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
export function money(value: number | string | null | undefined, currency: Currency): string {
    const amount = Number(value ?? 0) * (currency.rate ?? 1);
    const decimals = decimalsFor(currency.code);

    return `${amount.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })} ${currency.symbol || currency.code}`;
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

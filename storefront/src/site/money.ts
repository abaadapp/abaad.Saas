import type { Currency } from './types';

/**
 * كتابة المبلغ كما اختارها التاجر — لا كما يفترضها العارض.
 *
 * المنازل وموضع الرمز إعدادان في أبعاد يصلان في المستند. ورقمٌ مثبّت هنا
 * يعني تاجرًا في دبي يرى ثلاث منازل ورمزًا في غير موضعه في موقعه هو.
 */

const THREE_DECIMALS = ['OMR', 'KWD', 'BHD'];

export const FALLBACK_CURRENCY: Currency = {
    code: 'OMR',
    symbol: 'ر.ع',
    rate: 1,
    is_base: true,
    decimals: 3,
    before: false,
};

export function decimalsFor(code: string): number {
    return THREE_DECIMALS.includes(code) ? 3 : 2;
}

export function money(value: number | string | null | undefined, currency: Currency | undefined): string {
    const cur = currency ?? FALLBACK_CURRENCY;
    const amount = Number(value ?? 0) * (cur.rate ?? 1);
    const decimals = cur.decimals ?? decimalsFor(cur.code);
    const text = amount.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
    const symbol = cur.symbol || cur.code;

    return cur.before ? `${symbol} ${text}` : `${text} ${symbol}`;
}

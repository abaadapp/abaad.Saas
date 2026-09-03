import type { DocBrand, DocProduct, Currency } from './types';
import { money } from './money';

/**
 * ما يمكن للزائر أن يفعله فعلًا — لا أكثر.
 *
 * لا سلّة في هذا العارض ولا دفع: البيع في أبعاد يمرّ بنقطة البيع وبالطلبات،
 * ولم يُوصَل بعد. وزرُّ «أضف إلى السلّة» لا يضيف شيئًا هو أسوأ من غيابه —
 * زبونٌ يضغطه ثلاثًا ثم يغادر ظانًّا أنّ المتجر معطوب.
 *
 * والموجود فعلًا هو واتساب: رقمٌ في إعدادات التاجر يقرؤه المستند. فمن له
 * رقمٌ يظهر عنده «اطلب عبر واتساب» ويفتح محادثةً باسم المنتج وسعره، ومن لا
 * رقم له لا يظهر عنده زرٌّ أصلًا.
 */

/** الرقم بصيغة wa.me — أرقامٌ فقط */
export function waNumber(raw: string | undefined): string {
    return (raw ?? '').replace(/\D+/g, '');
}

export function whatsappUrl(number: string | undefined, message?: string): string | null {
    const digits = waNumber(number);

    if (digits.length < 8) {
        return null;
    }

    const text = message ? `?text=${encodeURIComponent(message)}` : '';

    return `https://wa.me/${digits}${text}`;
}

/**
 * رابط طلب منتجٍ بعينه — الرسالة تحمل اسمه وسعره.
 *
 * فالتاجر يقرأ رسالةً تقول ما يريده الزبون، لا «مرحبًا» يتبعها سؤالٌ عن أيّ
 * منتجٍ يقصد.
 */
export function orderUrl(
    brand: DocBrand | undefined,
    product: DocProduct,
    currency: Currency | undefined,
    label: string,
): string | null {
    return whatsappUrl(brand?.whatsapp, `${label}: ${product.name} — ${money(product.final, currency)}`);
}

/** هل يبيع هذا الموقع أصلًا؟ — الهدف يقول */
export function sells(goal: string): boolean {
    return goal === 'store' || goal === 'catalog';
}

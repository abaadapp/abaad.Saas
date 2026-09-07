/**
 * إجماليُّ أمر الشراء في الشاشة — نسخةٌ حرفيّة من `App\Support\PurchaseOrderTotals`.
 *
 * والشاشةُ تحسب لتُري التاجرَ ما يوقّع عليه، والخادمُ يحسب ليكتب. والخادمُ
 * هو المرجع: ما يُرسل من إجماليّاتٍ يُهمل جملةً. وهذه هنا كي لا يرى التاجرُ
 * رقمًا ويُحفظ غيرُه — واختبارٌ في `tests/Feature` يقابل الصيغتين حسابًا
 * برقم، فلو افترقتا سقط.
 *
 * الترتيب: مجموعٌ − خصمٌ + شحنٌ = وعاءٌ، ثمّ ضريبةٌ عليه.
 */

export interface TotalsInput {
    items: { cost: string | number; quantity: string | number }[];
    discount: string | number;
    shipping: string | number;
    taxRate: number;
}

export interface Totals {
    items_subtotal: number;
    supplier_discount: number;
    shipping_cost: number;
    taxable: number;
    tax: number;
    total: number;
}

/** ثلاثُ منازل — الريال العماني. والتقريب على كل خطوة كما في الخادم */
const r3 = (v: number) => Math.round((v + Number.EPSILON) * 1000) / 1000;

const num = (v: string | number): number => {
    const n = Number(v);

    return Number.isFinite(n) ? n : 0;
};

/** إجماليُّ سطر: الكميّةُ بوحدة الشراء × تكلفة الوحدة منها */
export const lineTotal = (cost: string | number, quantity: string | number): number =>
    r3(num(cost) * num(quantity));

export function purchaseTotals({ items, discount, shipping, taxRate }: TotalsInput): Totals {
    const items_subtotal = r3(items.reduce((sum, i) => sum + lineTotal(i.cost, i.quantity), 0));

    // ‏والخصمُ يُحصر بقيمة البضاعة: إجماليٌّ سالبٌ لا معنى له
    const supplier_discount = Math.min(r3(Math.max(0, num(discount))), items_subtotal);
    const shipping_cost = r3(Math.max(0, num(shipping)));
    const taxable = r3(items_subtotal - supplier_discount + shipping_cost);
    const tax = r3(taxable * (Math.max(0, taxRate) / 100));

    return { items_subtotal, supplier_discount, shipping_cost, taxable, tax, total: r3(taxable + tax) };
}

/** كم وحدةَ تخزينٍ يعني هذا السطر — والمعاملُ الفارغ يُقرأ واحدًا */
export const baseQuantity = (quantity: string | number, unitsPer: string | number): number => {
    const factor = num(unitsPer) > 0 ? num(unitsPer) : 1;

    return r3(num(quantity) * factor);
};

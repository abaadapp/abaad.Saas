import { halfUp, milli, toMoney } from '@/lib/baisa';
import { vatLineTax } from '@/lib/vat-lines';

/**
 * ملخّصُ فاتورة العميل في الشاشة — نسخةٌ حرفيّة من `App\Support\CustomerInvoices::compute`.
 *
 * والشاشةُ تحسب لتُري التاجرَ ما سيصدره، والخادمُ يحسب ليكتب. والخادمُ هو
 * المرجع: لا إجماليَّ يُرسَل من هنا أصلًا. وهذه هنا كي لا يرى التاجرُ رقمًا
 * ويُحفظ غيرُه — واختبارٌ في `tests/Feature` يقابل الصيغتين حسابًا برقم،
 * فلو افترقتا سقط. وهو النمطُ نفسُه المتّبع في `purchase-totals`.
 *
 * وكانت الصيغةُ مكتوبةً داخل الشاشة، فافترقت عن الخادم في ثلاثة — وكلُّها
 * قِيست قبل الإصلاح:
 *
 * — **خصمٌ أكبر من بنده**: الخادمُ يقصُره على قيمة البند، والشاشةُ تجمعه كما
 *   طُلب. بندٌ بعشرةٍ وخصمٍ بخمسةٍ وعشرين كان يُظهر إجماليًّا **سالبًا بخمسة
 *   عشر** ثمّ يُحفظ صفرًا.
 * — **خصمٌ سالب**: الخادمُ يقصُره إلى صفر، والشاشةُ كانت تطرحه فتضخّم الإجمالي.
 * — **«مشمولة في السعر»**: الخادمُ يستخرج الضريبةَ من المبلغ، والشاشةُ تضيفها.
 */

export interface InvoiceLine {
    quantity: string | number;
    unit_price: string | number;
    discount: string | number;
    tax_rate: string | number;
}

export interface InvoiceTotals {
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
}

const num = (v: string | number): number => {
    const n = Number(v);

    return Number.isFinite(n) ? n : 0;
};

/**
 * وبالبيسات الصحيحة كما يكتبها الخادم — لا تقريبَ عند العرض وحده.
 *
 * كانت تجمع بكسورٍ عائمة وتقرّب عند العرض، فتختلف عن الورقة ببيسةٍ أحيانًا.
 * والآن الخطواتُ خطواتُ `compute` نفسُها: الكمّيّةُ في السعر مدوَّرةً إلى البيسة،
 * والخصمُ مقصورٌ إلى [صفر، قيمة البند]، وضريبةُ كلّ بندٍ تُدوَّر وحدَها
 * (`vatLineTax` — القاعدةُ الواحدة مع الصندوق) ثمّ تُجمع.
 */
export function invoiceTotals(lines: InvoiceLine[], inclusive: boolean): InvoiceTotals {
    let grossB = 0n;
    let discountB = 0n;
    let taxB = 0n;

    for (const line of lines) {
        if (!(num(line.quantity) > 0)) continue;

        const g = halfUp(milli(num(line.quantity)) * milli(num(line.unit_price)), 1000n);
        // ‏والخصمُ المحتسَب هو المطبَّق: يُقصَر إلى [صفر، قيمة البند] كما في الخادم
        const offRaw = milli(num(line.discount));
        const off = offRaw < g ? offRaw : g;

        grossB += g;
        discountB += off;
        taxB += vatLineTax(g - off, num(line.tax_rate), inclusive);
    }

    // ‏والمجموعُ الفرعيُّ صافٍ من الضريبة حين تكون مشمولة — كما في الخادم
    const subtotalB = inclusive ? grossB - taxB : grossB;

    return {
        subtotal: toMoney(subtotalB),
        discount: toMoney(discountB),
        tax: toMoney(taxB),
        total: toMoney(subtotalB - discountB + taxB),
    };
}

/**
 * البنودُ كما يحسبها الخادم — والإضافةُ المدفوعةُ سطرٌ بثمنها تحت صنفها.
 *
 * والثمنُ من الكتالوج المعروض، والضريبةُ ضريبةُ صنفها، والمشمولةُ في الثمن لا
 * تُحسب، والبندُ اليدويُّ بلا إضافات (`InvoiceLineDetails::expand`). معاينةٌ لا
 * مصدر: الخادمُ يعيد الحساب.
 *
 * والمشمولةُ ما ثمنُه في الكتالوج صفرٌ وحده (`includable`): مدفوعةٌ وصلت معلَّمةً
 * تُحسب بثمنها هنا — والخادمُ يردّها ولا يكتبها مجّانًا.
 */
export interface LineWithAddons extends InvoiceLine {
    product_id?: string;
    addons?: { addon_id: string; quantity: string; included: boolean }[];
}

/** أتُشمل الإضافةُ في ثمن صنفها؟ — لما ثمنُه في الكتالوج صفرٌ وحده، كما يقبلها الخادم */
export const includable = (price: number) => Math.round(price * 1000) === 0;

export function withAddons<T extends LineWithAddons>(lines: T[], catalog: { id: number; price: number }[]): InvoiceLine[] {
    const out: InvoiceLine[] = [];

    for (const line of lines) {
        out.push(line);

        for (const a of line.product_id ? (line.addons ?? []) : []) {
            const row = catalog.find((c) => String(c.id) === a.addon_id);

            if (!row || (a.included && includable(row.price))) continue;

            out.push({ quantity: a.quantity || '1', unit_price: row.price, discount: 0, tax_rate: line.tax_rate });
        }
    }

    return out;
}

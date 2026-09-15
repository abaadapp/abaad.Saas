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
 * ولا تقريبَ على كلّ خطوةٍ هنا.
 *
 * الخادمُ يقرّب إلى ثلاث منازل في كلّ خطوة لأنّه يكتب في أعمدة
 * `decimal(12,3)`. وهذا صندوقُ عرضٍ يُعاد رسمُه مع كلّ حرف، ويُطبع بـ
 * `Money` التي تقرّب عند العرض. فالتقريبُ عند الخرج وحده، والفرقُ بين
 * الاثنين دون منزلةٍ واحدة — أصغرُ من أن يُرى على الشاشة.
 */
export function invoiceTotals(lines: InvoiceLine[], inclusive: boolean): InvoiceTotals {
    let gross_sum = 0;
    let discount = 0;
    let tax = 0;

    for (const line of lines) {
        const gross = num(line.quantity) * num(line.unit_price);
        // ‏والخصمُ المحتسَب هو المطبَّق: يُقصَر إلى [صفر، قيمة البند] كما في الخادم
        const off = Math.min(Math.max(0, num(line.discount)), gross);
        const net = gross - off;
        const rate = num(line.tax_rate);

        gross_sum += gross;
        discount += off;
        tax += inclusive ? (net * rate) / (100 + rate) : (net * rate) / 100;
    }

    // ‏والمجموعُ الفرعيُّ صافٍ من الضريبة حين تكون مشمولة — كما في الخادم
    const subtotal = inclusive ? gross_sum - tax : gross_sum;

    return { subtotal, discount, tax, total: subtotal - discount + tax };
}

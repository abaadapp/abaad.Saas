import { exact, halfUp, milli, spread, toMoney } from '@/lib/baisa';

/**
 * ضريبةُ القيمة المضافة سطرًا سطرًا — مرآةُ `App\Support\VatLines` حرفًا بحرف.
 *
 * الصندوقُ يحسب ليُري الكاشيرَ ما يقبضه، والخادمُ يحسب ليكتب. فلو افترقا
 * لقبض الكاشيرُ رقمًا وكُتب غيرُه. والقاعدةُ قاعدةُ PINT-OM: ضريبةُ كلّ سطرٍ =
 * صافيه × نسبته، مدوَّرةً إلى البيسة (IBR-168-OM)، وضريبةُ المستند مجموعُها
 * (ALIGNED-IBRP-S-09-OM). وخصمُ المستند يُوزَّع على الأسطر بالبيسات بنسبة
 * صافيها (`spread`).
 *
 * والحالاتُ المشتركة في `tests/fixtures/vat-lines-random-cases.json` يقرؤها
 * اختبارُ PHP واختبارُ vitest معًا.
 */
export function vatLineTax(netB: bigint, rate: number, inclusive: boolean): bigint {
    if (netB <= 0n || !(rate > 0)) return 0n;
    const [rn, rd] = exact(rate);
    const den = inclusive ? rd * 100n + rn : rd * 100n;

    return halfUp(netB * rn, den);
}

export interface VatLine {
    /** صافي السطر بالبيسات قبل خصم المستند */
    net: bigint;
    rate: number;
}

/** ضريبةُ مستندٍ بخصمٍ يُوزَّع على أسطره — بالبيسات */
export function vatDocument(lines: VatLine[], discountB: bigint, inclusive: boolean): { tax: bigint; lines: { discount: bigint; taxable: bigint; tax: bigint }[] } {
    const nets = lines.map((l) => (l.net > 0n ? l.net : 0n));
    const shares = spread(discountB, nets);
    let tax = 0n;
    const out = lines.map((l, i) => {
        const taxable = nets[i] - shares[i];
        const t = vatLineTax(taxable, l.rate, inclusive);
        tax += t;
        return { discount: shares[i], taxable, tax: t };
    });

    return { tax, lines: out };
}

/** صافي بند الصندوق بالبيسات — `price × qty + addons_total` كما يحسبه `SaleLines::taxFor` */
export const tillLineNet = (price: number, qty: number, addonsTotal: number): bigint =>
    milli(price) * BigInt(Math.max(0, Math.trunc(qty))) + milli(addonsTotal);

/** ضريبةُ سلّة الصندوق ريالاتٍ — `SaleLines::taxFor` */
export function tillTax(lines: { price: number; qty: number; addons: number; rate: number }[], discount: number, subtotal: number, inclusive: boolean): number {
    const rows = lines.map((l) => ({ net: tillLineNet(l.price, l.qty, l.addons), rate: l.rate }));

    return toMoney(vatDocument(rows, milli(Math.min(discount, subtotal)), inclusive).tax);
}

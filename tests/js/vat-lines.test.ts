import { describe, expect, it } from 'vitest';
import { spread } from '@/lib/baisa';
import { invoiceTotals } from '@/lib/invoice-totals';
import { tillTax, vatDocument, vatLineTax } from '@/lib/vat-lines';
import shared from '../fixtures/vat-lines-random-cases.json';

/**
 * ضريبةُ الصندوق وفاتورة العميل في الشاشة بقاعدة الخادم — سطرًا سطرًا (PINT-OM).
 *
 * الحالاتُ نفسُها يقرؤها `VatIsRoundedLineByLineAsTheOmaniSpecAsksTest` في PHP:
 * الصندوقُ (`SaleLines::taxFor` ↔ `tillTax`) وفاتورةُ العميل
 * (`CustomerInvoices::compute` ↔ `invoiceTotals`). يوم يفترق طرفٌ يسقط.
 */
type TillCase = {
    lines: { price: number; qty: number; addons: number; rate: number }[];
    discount: number;
    inclusive: boolean;
    expect: { tax: number; shares: number[] };
};
type InvoiceCase = {
    lines: { quantity: number; unit_price: number; discount: number; tax_rate: number }[];
    inclusive: boolean;
    expect: { subtotal: number; discount: number; tax: number; total: number };
};

const till = (shared as { till: TillCase[] }).till;
const invoice = (shared as { invoice: InvoiceCase[] }).invoice;

describe('ضريبةُ السطر', () => {
    it('نصفُ البيسة إلى أعلى، والشاملةُ تُستخرج', () => {
        expect(vatLineTax(25710n, 5, false)).toBe(1286n);
        expect(vatLineTax(25700n, 5, false)).toBe(1285n);
        expect(vatLineTax(105000n, 5, true)).toBe(5000n);
        expect(vatLineTax(105000n, 0, true)).toBe(0n);
        expect(vatLineTax(20n, 2.5, false)).toBe(1n);
    });

    it('الخصمُ يجمع إلى نفسه ولا يتجاوز سطرًا — والباقي لأكبرها', () => {
        expect(spread(4000n, [13050n, 14300n])).toEqual([1908n, 2092n]);
        expect(spread(5n, [1n, 1n, 1n])).toEqual([1n, 1n, 1n]);
        // ما بقي من القصّ كلُّه لأكبرها ما اتّسع له — والتساوي بالترتيب (كـ`withLoyalty`)
        expect(spread(2n, [3n, 3n, 3n])).toEqual([2n, 0n, 0n]);
    });

    it('المستندُ مجموعُ أسطره', () => {
        const doc = vatDocument([{ net: 7000n, rate: 5 }, { net: 1250n, rate: 5 }], 2000n, false);
        expect(doc.lines.map((l) => l.tax)).toEqual([265n, 47n]);
        expect(doc.tax).toBe(312n);
    });
});

describe('الحالاتُ المشتركة مع الخادم', () => {
    it('ثلاثمئة سلّة صندوق', () => {
        expect(till).toHaveLength(300);
        till.forEach((c, n) => {
            const subtotal = c.lines.reduce((s, l) => s + l.price * l.qty + l.addons, 0);
            expect(tillTax(c.lines, c.discount, Math.max(subtotal, c.discount), c.inclusive), `سلّة ${n}`).toBe(c.expect.tax);
        });
    });

    it('حصصُ الخصم بالبيسة', () => {
        till.forEach((c, n) => {
            const nets = c.lines.map((l) => BigInt(Math.round(l.price * 1000)) * BigInt(l.qty) + BigInt(Math.round(l.addons * 1000)));
            expect(spread(BigInt(Math.round(c.discount * 1000)), nets).map(Number), `سلّة ${n}`).toEqual(c.expect.shares);
        });
    });

    it('مئتا فاتورة عميل', () => {
        expect(invoice).toHaveLength(200);
        invoice.forEach((c, n) => {
            expect(invoiceTotals(c.lines, c.inclusive), `فاتورة ${n}`).toEqual(c.expect);
        });
    });
});

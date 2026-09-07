import { describe, expect, it } from 'vitest';
import { baseQuantity, lineTotal, purchaseTotals } from '@/lib/purchase-totals';

/**
 * حسابُ أمر الشراء في الشاشة — والخادمُ يقول الشيء نفسه بالأرقام نفسها.
 *
 * والأرقامُ هنا هي أرقامُ `TheOrderSaysWhatItBuysAndInWhatUnitTest` بعينها:
 * لو افترقت الصيغتان سقط أحدُ الاختبارين.
 */
describe('الإجمالي', () => {
    const items = [{ cost: 6, quantity: 5 }];

    it('مجموعُ البنود × الكميّات', () => {
        expect(purchaseTotals({ items, discount: 0, shipping: 0, taxRate: 0 }).items_subtotal).toBe(30);
    });

    it('الخصمُ يُنقص قبل الضريبة، والشحنُ يدخل الوعاء', () => {
        const t = purchaseTotals({ items, discount: 2, shipping: 3.5, taxRate: 10 });

        expect(t.taxable).toBe(31.5);
        expect(t.tax).toBe(3.15);
        expect(t.total).toBe(34.65);
    });

    it('وضريبةٌ مطفأة تعني صفرًا لا نسبةً مخبّأة', () => {
        expect(purchaseTotals({ items, discount: 0, shipping: 0, taxRate: 0 }).tax).toBe(0);
    });

    /* وخصمٌ أكبرُ من البضاعة يُحصر: إجماليٌّ سالبٌ لا معنى له */
    it('الخصمُ لا يتجاوز قيمة الأصناف', () => {
        const t = purchaseTotals({ items, discount: 500, shipping: 0, taxRate: 0 });

        expect(t.supplier_discount).toBe(30);
        expect(t.total).toBe(0);
    });

    it('والسالبُ يُقرأ صفرًا', () => {
        const t = purchaseTotals({ items, discount: -5, shipping: -2, taxRate: -3 });

        expect(t.supplier_discount).toBe(0);
        expect(t.shipping_cost).toBe(0);
        expect(t.tax).toBe(0);
    });

    it('والنصُّ الفارغ لا يُفسد الجمع', () => {
        const t = purchaseTotals({ items: [{ cost: '', quantity: '' }], discount: '', shipping: '', taxRate: 5 });

        expect(t.total).toBe(0);
    });

    /* ثلاثُ منازل — الريال العماني. و0.1+0.2 لا تُكتب 0.30000000000000004 */
    it('ثلاثُ منازل لا أكثر', () => {
        expect(lineTotal(0.1, 3)).toBe(0.3);
        expect(lineTotal(1.0005, 1)).toBe(1.001);
    });
});

describe('وحدةُ الشراء', () => {
    it('خمسُ ربطاتٍ في العشرين مئةُ حبّة', () => {
        expect(baseQuantity(5, 20)).toBe(100);
    });

    it('وبلا معاملٍ تُقرأ كما هي', () => {
        expect(baseQuantity(7, '')).toBe(7);
        expect(baseQuantity(7, 0)).toBe(7);
        expect(baseQuantity(7, 1)).toBe(7);
    });
});

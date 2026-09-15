import { describe, expect, it } from 'vitest';
import { invoiceTotals } from '@/lib/invoice-totals';

/**
 * ملخّصُ فاتورة العميل يحسب بقاعدة الخادم.
 *
 * الصندوقُ لا يُرسَل — لكنّه ما يقرؤه التاجر قبل أن يضغط «حفظ». وقد افترق
 * عن `CustomerInvoices::compute` في ثلاثة، وكلُّها قِيست. ويقابل هذا
 * الاختبارَ في الخادم `TheInvoiceChargesWhatTheShopSettingsSayTest` بالأرقام
 * نفسِها، فلو افترقت الصيغتان سقط أحدُهما.
 */
const line = (quantity: string, unit_price: string, discount = '0', tax_rate = '5') => ({
    quantity,
    unit_price,
    discount,
    tax_rate,
});

describe('ملخّص فاتورة العميل', () => {
    it('لا يُظهر إجماليًّا سالبًا عن خصمٍ أكبر من بنده', () => {
        const t = invoiceTotals([line('1', '10', '25')], false);

        // الخادمُ يقصُره على قيمة البند فيحفظ صفرًا — وكان الصندوق يقول −15
        expect(t.discount).toBe(10);
        expect(t.total).toBe(0);
    });

    it('ولا يضخّم الإجماليَّ بخصمٍ سالب', () => {
        const t = invoiceTotals([line('1', '10', '-50')], false);

        expect(t.discount).toBe(0);
        expect(t.total).toBeCloseTo(10.5, 6);
    });

    it('ويستخرج الضريبةَ من السعر حين تكون مشمولة', () => {
        const t = invoiceTotals([line('1', '105')], true);

        expect(t.total).toBeCloseTo(105, 6);
        expect(t.tax).toBeCloseTo(5, 6);
        expect(t.subtotal).toBeCloseTo(100, 6);
    });

    it('ويضيفها فوقه حين لا تكون', () => {
        const t = invoiceTotals([line('1', '100')], false);

        expect(t.total).toBeCloseTo(105, 6);
        expect(t.subtotal).toBe(100);
    });

    it('والمجاميعُ تتّسق في الحالين', () => {
        for (const inclusive of [false, true]) {
            const t = invoiceTotals([line('2', '30', '5'), line('1', '105')], inclusive);

            expect(t.total).toBeCloseTo(t.subtotal - t.discount + t.tax, 6);
        }
    });

    it('وبندٌ بلا كمّيةٍ لا يُسقط الحساب', () => {
        const t = invoiceTotals([line('', '', '', '')], false);

        expect(t.total).toBe(0);
    });
});

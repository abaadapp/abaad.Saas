import { describe, expect, it } from 'vitest';
import { receiptPrinter } from '@/lib/printing/printer-service';
import type { PrinterLike } from '@/lib/printing/types';

/**
 * طابعةُ إيصال البيع هي التي علّمها الخادم (`PosPeripheral::receiptAmong`)
 * — لا «أوّلُ طابعة» في القائمة.
 *
 * كانت الأولى: طابعةٌ أُضيفت مرّتين («USB» ثمّ «شبكة» بطباعةٍ تلقائية) تطبع
 * على القديمة — حوارَ نظامٍ لا يجد طابعة — ولا يقع التلقائيّ أبدًا.
 */
const usb: PrinterLike = { id: 1, type: 'طابعة', name: 'قديمة', connection: 'usb', autoPrint: false };
const network: PrinterLike = {
    id: 2,
    type: 'طابعة',
    name: 'شبكة',
    connection: 'network',
    address: '192.168.1.50',
    autoPrint: true,
    receipt: true,
};
const scanner: PrinterLike = { id: 3, type: 'ماسح باركود', name: 'ماسح', receipt: false };

describe('the receipt printer', () => {
    it('is the one the server named, not the first row', () => {
        expect(receiptPrinter([usb, network, scanner])?.name).toBe('شبكة');
    });

    it('falls back to the first printer on a page that does not carry the mark yet', () => {
        const { receipt: _mark, ...unmarked } = network;
        expect(receiptPrinter([scanner, usb, unmarked])?.name).toBe('قديمة');
    });

    it('is never a scanner, even one wrongly marked', () => {
        expect(receiptPrinter([{ ...scanner, receipt: true }])).toBeNull();
        expect(receiptPrinter([])).toBeNull();
        expect(receiptPrinter(null)).toBeNull();
    });
});

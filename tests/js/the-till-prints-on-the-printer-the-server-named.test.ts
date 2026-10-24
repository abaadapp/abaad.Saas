import { afterEach, describe, expect, it, vi } from 'vitest';
import { mayHavePrinted, printReceipt, receiptPrinter } from '@/lib/printing/printer-service';
import type { PrinterLike } from '@/lib/printing/types';

/**
 * طابعةُ إيصال البيع هي التي علّمها الخادم (`PosPeripheral::receiptAmong`)
 * — لا «أوّلُ طابعة» في القائمة.
 *
 * كانت الأولى: طابعةٌ أُضيفت مرّتين («USB» ثمّ «شبكة») تطبع على القديمة —
 * حوارَ نظامٍ لا يجد طابعة.
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

/**
 * عطلٌ لا يُعرف موضعُه من الجسر — رميٌ أو ردٌّ لا يطابق العقد — قد يقع بعد أن
 * بلغت الطابعةَ بايتات. فيُقال «ربّما طُبعت»، فلا يُعيده الطابورُ وحده.
 */
describe('an unexplained bridge failure', () => {
    afterEach(() => vi.unstubAllGlobals());

    const app = (printReceipt: () => Promise<unknown>) =>
        vi.stubGlobal('AbaadPrinter', {
            version: 1,
            platform: 'android',
            isAvailable: () => true,
            printReceipt,
            testPrinter: vi.fn(),
            openReceipt: vi.fn(),
        });

    it('may have printed when the bridge throws mid-print', async () => {
        app(async () => {
            throw new Error('socket gone');
        });

        const outcome = await printReceipt({ invoice: 'INV-1', url: '/pos/receipt/INV-1/pdf', printer: network });

        expect(outcome.ok).toBe(false);
        expect(mayHavePrinted(outcome)).toBe(true);
    });

    it('may have printed when the reply does not match the contract', async () => {
        app(async () => 'printed?');

        expect(mayHavePrinted(await printReceipt({ invoice: 'INV-1', url: '/pos/receipt/INV-1/pdf', printer: network }))).toBe(true);
    });

    it('a refusal the app names is still safe to resend', async () => {
        app(async () => ({ ok: false, code: 'CONNECT_TIMEOUT', mayHavePrinted: false }));

        expect(mayHavePrinted(await printReceipt({ invoice: 'INV-1', url: '/pos/receipt/INV-1/pdf', printer: network }))).toBe(false);
    });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import InvoicePrintQueue from '@/Components/InvoicePrintQueue';
import { invoicePrinter } from '@/lib/printing/print-jobs';
import type { PrinterLike } from '@/lib/printing/types';
import { pageProps } from './setup';

const toasts = { warning: vi.fn(), error: vi.fn(), success: vi.fn(), info: vi.fn() };
vi.mock('sonner', () => ({
    toast: {
        warning: (...a: unknown[]) => toasts.warning(...a),
        error: (...a: unknown[]) => toasts.error(...a),
        success: (...a: unknown[]) => toasts.success(...a),
        info: (...a: unknown[]) => toasts.info(...a),
    },
}));

/**
 * «فاتورة عميل — نسخة داخلية» على صندوقها — من جهة الصندوق.
 *
 * ═══ ما يُحرس ═══
 *
 *   - صندوقٌ بلا طابعةٍ معيَّنة لا يسأل أحدًا.
 *   - تطبيقُ أبعاد بطابعة شبكةٍ معيَّنة: يحجز ويطبع على الجسر بلا ضغطة،
 *     ويقول ما جرى — وما قالت الطابعةُ إنّه ربّما خرج يُقال كما هو.
 *   - المتصفّح: لا طباعةَ صامتة — تنبيهٌ بعدد ما ينتظر وزرٌّ يطبع، والنافذةُ
 *     تُفتح في الضغطة نفسِها قبل انتظار الحجز.
 *   - وفتحُ الحوار ليس ورقًا: ما فُتح حوارُه يُسأل عنه «خرجت؟» من الخادم،
 *     و«لم تخرج» لا تطبع شيئًا وحدها.
 *
 * والطابعةُ الحقيقيّة لا تُقاس هنا: ما يخرج منها يُرى على جهاز.
 */

const PRINTER: PrinterLike = {
    id: 7, type: 'طابعة', name: 'طابعة الكاشير', model: null,
    connection: 'network', address: '192.168.1.50', port: null, paperWidth: 80,
    autoPrint: false, autoPrintCustomerInvoices: true,
};

type Call = { url: string; method: string; body: Record<string, unknown> | null };
let calls: Call[];
let job: Record<string, unknown> | null;
let waiting: number;
let awaiting: { id: number; number: string | null }[];

const stubFetch = () =>
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            calls.push({ url: String(url), method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : null });
            const answer = String(url).includes('pos.printJobs.claim')
                ? { job }
                : String(url).includes('pos.printJobs.index')
                  ? { designated: true, waiting, awaiting }
                  : { recorded: true };

            return { ok: true, json: async () => answer } as Response;
        }),
    );

const till = (printer: PrinterLike | null) =>
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: printer ? [printer] : [] },
        auth: { abilities: [], mayActions: [], user: { businessId: 3 } },
    });

/** تطبيقُ أبعاد كما يراه الويب — `window.AbaadPrinter` */
const app = (result: Record<string, unknown> = { ok: true, jobId: 'j', printed: true, connected: true, effectivePort: 9100 }) => {
    const bridge = {
        version: 1,
        platform: 'android',
        isAvailable: vi.fn(() => true),
        printReceipt: vi.fn(async (_job: unknown) => result),
        testPrinter: vi.fn(),
        openReceipt: vi.fn(async () => ({ ok: true })),
    };
    vi.stubGlobal('AbaadPrinter', bridge);

    return bridge;
};

const acks = () => calls.filter((c) => c.url.includes('pos.printJobs.ack'));
const claims = () => calls.filter((c) => c.url.includes('pos.printJobs.claim'));

beforeEach(() => {
    Object.values(toasts).forEach((f) => f.mockClear());
    calls = [];
    job = { id: 41, kind: 'customer_invoice_internal', number: 'CINV-000041', url: '/pos.printJobs.pdf/41' };
    waiting = 0;
    awaiting = [];
    stubFetch();
});

afterEach(() => {
    vi.unstubAllGlobals();
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
    });
});

describe('الصندوقُ المعيَّن وطابورُه', () => {
    it('صندوقٌ بلا طابعةٍ معيَّنة لا يسأل الطابورَ أصلًا', async () => {
        till({ ...PRINTER, autoPrintCustomerInvoices: false });
        render(<InvoicePrintQueue />);

        await new Promise((r) => setTimeout(r, 20));
        expect(calls).toHaveLength(0);
        expect(screen.queryByTestId('invoice-print-queue')).toBeNull();
        expect(invoicePrinter([{ ...PRINTER, autoPrintCustomerInvoices: false }])).toBeNull();
    });

    it('تطبيقُ أبعاد بطابعة شبكة: يحجز ويطبع على الجسر بلا ضغطة، ويقول إنّها خرجت', async () => {
        const bridge = app();
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await waitFor(() => expect(acks()).toHaveLength(1));

        expect(claims()[0].method).toBe('POST');
        const sent = bridge.printReceipt.mock.calls[0][0] as unknown as { invoice: string; receiptUrl: string; printer: { address: string } };
        expect(sent.invoice).toBe('CINV-000041');
        expect(sent.receiptUrl).toMatch(/\/pos\.printJobs\.pdf\/41\?expected_business_id=3$/);
        expect(sent.printer.address).toBe('192.168.1.50');
        expect(acks()[0].body).toEqual({ ok: true, via: 'native' });
        expect(acks()[0].url).toBe('/pos.printJobs.ack/41');
        // ولا تنبيهَ في التطبيق — الورقةُ تخرج وحدها
        expect(screen.queryByTestId('invoice-print-queue')).toBeNull();
    });

    it('وطابعةٌ قالت إنّ بعضَها ربّما خرج — يُقال كما هو، ولا يُعاد وحده', async () => {
        app({ ok: false, code: 'SEND_FAILED', message: 'انقطع', mayHavePrinted: true });
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await waitFor(() => expect(acks()).toHaveLength(1));
        expect(acks()[0].body).toEqual({ ok: false, via: 'native', error: 'SEND_FAILED', may_have_printed: true });
        expect(toasts.warning).toHaveBeenCalledTimes(1);
        expect(String(toasts.warning.mock.calls[0][0])).toContain('فاتورة عميل — نسخة داخلية');
    });

    it('ولا ورقةَ تنتظر ← لا طباعة ولا ردّ', async () => {
        job = null;
        const bridge = app();
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await waitFor(() => expect(claims()).toHaveLength(1));
        expect(bridge.printReceipt).not.toHaveBeenCalled();
        expect(acks()).toHaveLength(0);
    });
});

describe('والمتصفّح — لا طباعةَ صامتة', () => {
    it('يُرى عددُ ما ينتظر وزرٌّ يطبع — ولا يحجز شيئًا وحده', async () => {
        waiting = 2;
        till(PRINTER);
        render(<InvoicePrintQueue />);

        const bar = await screen.findByTestId('invoice-print-queue');
        expect(bar).toHaveTextContent('فواتير عملاء مسددة بانتظار الطباعة');
        expect(bar).toHaveTextContent('2');
        expect(claims()).toHaveLength(0);
    });

    it('والضغطةُ تفتح النافذةَ فيها نفسِها، ثمّ تحجز وتوجّهها — ولا تقول «طُبعت»', async () => {
        waiting = 1;
        const sheet = { location: { replace: vi.fn() }, close: vi.fn() };
        const open = vi.fn(() => {
            // النافذةُ قبل الحجز: لا طلبَ حجزٍ سبقها
            expect(claims()).toHaveLength(0);

            return sheet;
        });
        vi.stubGlobal('open', open);
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await userEvent.click(await screen.findByTestId('invoice-print-next'));

        await waitFor(() => expect(acks()).toHaveLength(1));
        expect(open).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).toHaveBeenCalledWith('/pos.printJobs.pdf/41?expected_business_id=3');
        expect(acks()[0].body).toEqual({ ok: true, via: 'browser' });
    });

    it('ولا ورقةَ حين ضُغط ← تُغلق النافذةُ الفارغة', async () => {
        waiting = 1;
        job = null;
        const sheet = { location: { replace: vi.fn() }, close: vi.fn() };
        vi.stubGlobal('open', vi.fn(() => sheet));
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await userEvent.click(await screen.findByTestId('invoice-print-next'));

        await waitFor(() => expect(sheet.close).toHaveBeenCalled());
        expect(sheet.location.replace).not.toHaveBeenCalled();
        expect(acks()).toHaveLength(0);
    });
});

describe('وبعد الحوار — سؤالٌ لا وعد', () => {
    const confirms = () => calls.filter((c) => c.url.includes('pos.printJobs.confirm'));

    it('ما فُتح حوارُه يُسأل عنه — و«خرجت» تقولها، ولا حجزَ ولا طباعة', async () => {
        awaiting = [{ id: 41, number: 'CINV-000041' }];
        const open = vi.fn();
        vi.stubGlobal('open', open);
        till(PRINTER);
        render(<InvoicePrintQueue />);

        const row = await screen.findByTestId('invoice-print-awaiting');
        expect(row).toHaveTextContent('هل خرجت الفاتورة الداخلية؟');
        expect(row).toHaveTextContent('CINV-000041');
        expect(screen.queryByTestId('invoice-print-next')).toBeNull();

        await userEvent.click(screen.getByTestId('invoice-print-confirmed'));

        await waitFor(() => expect(confirms()).toHaveLength(1));
        expect(confirms()[0]).toEqual({ url: '/pos.printJobs.confirm/41', method: 'POST', body: { printed: true } });
        expect(claims()).toHaveLength(0);
        expect(acks()).toHaveLength(0);
        expect(open).not.toHaveBeenCalled();
    });

    it('و«لم تخرج» تُعيدها إلى ما ينتظر — ولا تطبعها وحدها', async () => {
        awaiting = [{ id: 41, number: 'CINV-000041' }];
        const open = vi.fn();
        vi.stubGlobal('open', open);
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await userEvent.click(await screen.findByTestId('invoice-print-not-printed'));

        await waitFor(() => expect(confirms()).toHaveLength(1));
        expect(confirms()[0].body).toEqual({ printed: false });
        // يُعاد سؤالُ الطابور بعدها — لا حجزٌ ولا نافذة
        await waitFor(() => expect(calls.filter((c) => c.url.includes('pos.printJobs.index')).length).toBeGreaterThan(1));
        expect(claims()).toHaveLength(0);
        expect(open).not.toHaveBeenCalled();
    });

    it('وبعد الضغطة يُسأل الطابورُ من جديد — فيظهر السؤالُ عن الورقة التي فُتحت', async () => {
        waiting = 1;
        const sheet = { location: { replace: vi.fn() }, close: vi.fn() };
        vi.stubGlobal('open', vi.fn(() => sheet));
        till(PRINTER);
        render(<InvoicePrintQueue />);

        await userEvent.click(await screen.findByTestId('invoice-print-next'));
        await waitFor(() => expect(acks()).toHaveLength(1));

        // الردُّ ثمّ سؤالُ الطابور — والسؤالُ «خرجت؟» من جوابه، لا من ذاكرة الصفحة
        const order = calls.map((c) => (c.url.includes('claim') ? 'claim' : c.url.includes('ack') ? 'ack' : c.url.includes('index') ? 'index' : c.url));
        expect(order.slice(order.indexOf('claim'))).toEqual(['claim', 'ack', 'index']);
    });
});

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PaymentDialog from '@/Pages/Pos/partials/PaymentDialog';
import PrintReceiptButton from '@/Components/PrintReceiptButton';
import ReceiptPreviewButton from '@/Pages/Pos/partials/ReceiptPreview';
import { inPagePrintSupported, printPdfInPage, resetPrintFrame, setPagePrintTiming } from '@/lib/printing/page-print';
import { forgetPreparedReceipts } from '@/lib/printing/share-print';
import { printReceipt } from '@/lib/printing/printer-service';
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
 * «طباعة الفاتورة» في نقطة البيع — والكاشيرُ في شاشته.
 *
 * ═══ ما كان ═══
 *
 * في المتصفّح كانت تفتح الـPDF في لسانٍ جديد. وعلى الآيباد والهاتف يملأ
 * قارئُه الشاشة، فتختفي شاشةُ البيع والزبونُ واقف.
 *
 * ═══ وما يُحرس ═══
 *
 *   - حوارُ الطباعة على **الـPDF الرسميّ نفسِه** في إطارٍ مخفيّ — بلا لسانٍ
 *     ولا ملاحة — حيث يُعرف أنّه يعمل (Chrome وEdge على الحاسوب).
 *   - وحيث لا يُعرف: رسالةٌ صريحة و«فتح PDF» — ولا «طُبع» عن حوارٍ فُتح.
 *   - والتطبيقُ على جسره كما كان، والطباعةُ التلقائيّة على نافذتها كما
 *     كانت، واللوحةُ على لسانها كما كانت.
 */

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const EDGE = `${CHROME} Edg/129.0.0.0`;
const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const CHROME_IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.0.0 Mobile/15E148 Safari/604.1';

const PDF = new Blob(['%PDF-1.7 receipt'], { type: 'application/pdf' });

/** المتصفّحُ الذي يقف أمامه الكاشير */
const browser = (ua: string, pdfViewerEnabled = true) => {
    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(ua);
    Object.defineProperty(window.navigator, 'pdfViewerEnabled', { value: pdfViewerEnabled, configurable: true });
};

/** ردُّ الخادم على بابِ الإيصال */
const answer = (status: number, type: string, body: Blob | string = PDF) =>
    ({
        ok: status >= 200 && status < 300,
        status,
        headers: { get: (h: string) => (h.toLowerCase() === 'content-type' ? type : null) },
        blob: async () => (typeof body === 'string' ? new Blob([body], { type }) : body),
    }) as unknown as Response;

let fetchMock: ReturnType<typeof vi.fn>;
let openMock: ReturnType<typeof vi.fn>;
let created: Blob[];
let revoked: string[];

/** الإطارُ المخفيّ — يُحمَّل ويُطلب حوارُه كما يفعل المتصفّح */
const frame = () => document.querySelector<HTMLIFrameElement>('iframe[data-print-frame]');

/** يُحمَّل الإطار — وما فيه PDF كما يقول قارئُ Chrome (`contentType`) */
const fire = (f: HTMLIFrameElement, type = 'application/pdf') => {
    Object.defineProperty(f, 'contentDocument', { value: { contentType: type }, configurable: true });
    f.dispatchEvent(new Event('load'));
};

const loadFrame = async (print: () => void = () => {}, type = 'application/pdf') => {
    await waitFor(() => expect(frame()?.getAttribute('src')).toMatch(/^blob:/));
    const f = frame()!;
    const spy = vi.fn(print);
    Object.defineProperty(f.contentWindow!, 'print', { value: spy, configurable: true });
    fire(f, type);

    return spy;
};

const till = (printer: PrinterLike | null) =>
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: printer ? [printer] : [] },
        auth: { abilities: [], mayActions: [], user: { businessId: 3 } },
    });

const NETWORK: PrinterLike = {
    id: 7, type: 'طابعة', name: 'طابعة الكاشير', model: null,
    connection: 'network', address: '192.168.1.50', port: null, paperWidth: 80, autoPrint: false,
};

beforeEach(() => {
    Object.values(toasts).forEach((f) => f.mockClear());
    resetPrintFrame();
    forgetPreparedReceipts();
    // مهلةُ القارئ تُقاس في اختبارها وحده — انظر «لا يُطلب الحوار لحظةَ load»
    setPagePrintTiming({ settleMs: 0, timeoutMs: 20000, downloadMs: 30000 });
    // ولا قائمةَ مشاركةٍ إلّا حيث تُعطى — انظر `the-ipad-prints-through-the-share-sheet`
    Object.defineProperty(window.navigator, 'share', { value: undefined, configurable: true });
    Object.defineProperty(window.navigator, 'canShare', { value: undefined, configurable: true });
    Object.defineProperty(window.navigator, 'maxTouchPoints', { value: 0, configurable: true });
    created = [];
    revoked = [];
    let n = 0;
    vi.stubGlobal('URL', Object.assign(URL, {
        createObjectURL: vi.fn((b: Blob) => {
            created.push(b);

            return `blob:receipt-${++n}`;
        }),
        revokeObjectURL: vi.fn((u: string) => revoked.push(u)),
    }));
    fetchMock = vi.fn(async () => answer(200, 'application/pdf'));
    vi.stubGlobal('fetch', fetchMock);
    openMock = vi.fn().mockReturnValue({ location: { replace: vi.fn() }, close: vi.fn() });
    vi.stubGlobal('open', openMock);
    browser(CHROME);
    till(NETWORK);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    resetPrintFrame();
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
    });
});

/* ═══════════════════════════ أيُّ متصفّح ═══════════════════════════ */

describe('حيث يُعرف أنّه يعمل وحده', () => {
    it.each([
        ['Chrome على ويندوز', CHROME, true, true],
        ['Chrome على الماك', CHROME_MAC, true, true],
        ['Edge', EDGE, true, true],
        ['Chrome بقارئ PDF مطفأ', CHROME, false, false],
        ['Safari على الماك', SAFARI, true, false],
        ['Firefox', FIREFOX, true, false],
        ['Chrome على أندرويد', CHROME_ANDROID, true, false],
        ['Safari على الآيفون', IPHONE, true, false],
        ['Chrome على الآيباد (WebKit)', CHROME_IPAD, true, false],
    ])('%s', (_name, ua, viewer, expected) => {
        expect(inPagePrintSupported({ userAgent: ua, pdfViewerEnabled: viewer })).toBe(expected);
    });

    it('والآيباد في «وضع الحاسوب» يقول إنّه ماك — فيُعرف بشاشة اللمس', () => {
        expect(inPagePrintSupported({ userAgent: CHROME_MAC, maxTouchPoints: 5 })).toBe(false);
        expect(inPagePrintSupported({ userAgent: SAFARI, maxTouchPoints: 5 })).toBe(false);
    });
});

/* ═══════════════════════════ الطباعةُ في الصفحة ═══════════════════════════ */

describe('حوارُ الطباعة على الـPDF نفسِه — والصفحةُ لا تتحرّك', () => {
    it('يُنزَّل الإيصالُ الرسميّ ويُطلب حوارُه في إطارٍ مخفيّ — بلا لسانٍ ولا ملاحة', async () => {
        const before = window.location.href;
        const pending = printPdfInPage('/pos.receipt.pdf/INV-7');
        const print = await loadFrame();

        await expect(pending).resolves.toEqual({ ok: true, via: 'page' });
        expect(fetchMock).toHaveBeenCalledWith('/pos.receipt.pdf/INV-7', expect.objectContaining({ credentials: 'same-origin' }));
        expect(print).toHaveBeenCalledTimes(1);
        expect(openMock).not.toHaveBeenCalled();
        expect(window.location.href).toBe(before);

        // والإطارُ لا يُرى ولا يُقرأ — ولا `display: none` يمنع قارئ الـPDF من التحميل
        const f = frame()!;
        expect(f.getAttribute('aria-hidden')).toBe('true');
        expect(f.style.visibility).toBe('hidden');
        expect(f.style.display).not.toBe('none');
    });

    /*
     * والورقةُ هي ما ردّه الخادم بعينه — لا HTML يُعاد رسمُه.
     *
     * فالإيصالُ الطويل (#125) يُطبع كما كان يُطبع من اللسان: ملفٌّ واحدٌ
     * بطوله، من الباب نفسِه الذي يطبع منه التطبيق.
     */
    it('والملفُّ المطبوع هو الملفُّ الذي نزل — بايتًا ببايت', async () => {
        const long = new Blob(['%PDF-1.7 ' + 'x'.repeat(200000)], { type: 'application/pdf' });
        fetchMock.mockResolvedValueOnce(answer(200, 'application/pdf', long));

        const pending = printPdfInPage('/pos.receipt.pdf/INV-LONG');
        await loadFrame();
        await pending;

        expect(created).toHaveLength(1);
        expect(created[0]).toBe(long);
    });

    it('ومتصفّحٌ لا يُعرف أنّه يطبع من إطار ← يُقال، ولا تنزيلَ ولا لسان', async () => {
        browser(SAFARI);

        await expect(printPdfInPage('/pos.receipt.pdf/INV-7')).resolves.toMatchObject({ ok: false, code: 'IN_PAGE_UNSUPPORTED', via: 'page' });
        expect(fetchMock).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
        expect(frame()).toBeNull();
    });

    it.each([
        ['خطأٌ في الخادم', answer(500, 'text/html', 'oops'), 'RECEIPT_DOWNLOAD_FAILED'],
        ['ليس PDF', answer(200, 'application/json', '{}'), 'RECEIPT_DOWNLOAD_FAILED'],
        ['انتهت الجلسة (419)', answer(419, 'text/html', ''), 'SESSION_EXPIRED'],
        ['صفحةُ الدخول بعد التحويل', answer(200, 'text/html; charset=UTF-8', '<html>login</html>'), 'SESSION_EXPIRED'],
    ])('والجوابُ يُقرأ قبل الإطار — %s', async (_name, response, code) => {
        fetchMock.mockResolvedValueOnce(response);

        await expect(printPdfInPage('/pos.receipt.pdf/INV-7')).resolves.toMatchObject({ ok: false, code });
        // فلا تُطبع صفحةُ خطأٍ ولا صفحةُ دخولٍ على الورق
        expect(frame()).toBeNull();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('وانقطاعُ الشبكة ← فشلُ تنزيل', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        await expect(printPdfInPage('/pos.receipt.pdf/INV-7')).resolves.toMatchObject({ ok: false, code: 'RECEIPT_DOWNLOAD_FAILED' });
    });

    it('وحوارٌ رفضه المتصفّح ← يُقال، لا «طُبع»', async () => {
        const pending = printPdfInPage('/pos.receipt.pdf/INV-7');
        await loadFrame(() => {
            throw new DOMException('blocked', 'SecurityError');
        });

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'PRINT_DIALOG_FAILED' });
    });

    it('وإطارٌ لا يُحمَّل في مهلته ← يُقال', async () => {
        const pending = printPdfInPage('/pos.receipt.pdf/INV-7', 30);

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'PRINT_DIALOG_FAILED' });
    });

    it('وإطارٌ واحدٌ للصفحة — والإيصالُ الماضي يُحرَّر حين يأتي غيرُه، لا قبل', async () => {
        const first = printPdfInPage('/pos.receipt.pdf/INV-1');
        await loadFrame();
        await first;
        expect(revoked).toEqual([]);

        const second = printPdfInPage('/pos.receipt.pdf/INV-2');
        await waitFor(() => expect(frame()?.getAttribute('src')).toBe('blob:receipt-2'));
        fire(frame()!);
        await second;

        expect(document.querySelectorAll('iframe[data-print-frame]')).toHaveLength(1);
        expect(revoked).toEqual(['blob:receipt-1']);
    });
});

/* ═══════════════════════════ زرُّ الصندوق ═══════════════════════════ */

describe('Chrome وEdge — ما لا يُفترض', () => {
    /*
     * `load` يقول إنّ مستندَ الإطار حُمِّل، لا إنّ قارئَ الـPDF رسم صفحاته.
     * فلا يُطلب الحوارُ لحظتَها — يُمهَل القارئ.
     */
    it('لا يُطلب الحوارُ لحظةَ `load` — يُمهَل القارئ ثمّ يُطلب مرّةً واحدة', async () => {
        setPagePrintTiming({ settleMs: 60 });
        const pending = printPdfInPage('/pos.receipt.pdf/INV-7');
        const print = await loadFrame();

        // وبعد `load` بقليل ما زال القارئُ يُمهَل
        await new Promise((r) => setTimeout(r, 15));
        expect(print).not.toHaveBeenCalled();
        await expect(pending).resolves.toEqual({ ok: true, via: 'page' });
        expect(print).toHaveBeenCalledTimes(1);
    });

    it('وإطارٌ لم يحمل PDF (قارئٌ مطفأ نزّل الملفّ) ← لا ورقةٌ بيضاء تُطبع', async () => {
        const pending = printPdfInPage('/pos.receipt.pdf/INV-7');
        const print = await loadFrame(() => {}, 'text/html');

        await expect(pending).resolves.toMatchObject({ ok: false, code: 'PRINT_DIALOG_FAILED' });
        expect(print).not.toHaveBeenCalled();
    });

    it('وطباعتان متسابقتان في الصفحة ← الثانية تُردّ، ولا إيصالٌ لم يُقصد', async () => {
        const first = printPdfInPage('/pos.receipt.pdf/INV-1');
        await expect(printPdfInPage('/pos.receipt.pdf/INV-2')).resolves.toMatchObject({ ok: false, code: 'QUEUE_FULL' });

        await loadFrame();
        await first;
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toMatch(/INV-1/);

        // وبعدها تعود الطباعةُ ممكنة
        const again = printPdfInPage('/pos.receipt.pdf/INV-2');
        await waitFor(() => expect(frame()?.getAttribute('src')).toBe('blob:receipt-2'));
        fire(frame()!);
        await expect(again).resolves.toEqual({ ok: true, via: 'page' });
    });

    it('ومغادرةُ الصفحة تحرّر الملفَّ وتُزيل الإطار', async () => {
        const pending = printPdfInPage('/pos.receipt.pdf/INV-7');
        await loadFrame();
        await pending;

        window.dispatchEvent(new Event('pagehide'));

        expect(frame()).toBeNull();
        expect(revoked).toEqual(['blob:receipt-1']);
    });

    it('وتنزيلٌ علق في الشبكة ← يُقطع في مهلته ويُقال، والطباعةُ التالية لا تُردّ بسببه', async () => {
        setPagePrintTiming({ downloadMs: 30 });
        fetchMock.mockImplementationOnce(
            (_url: string, init: RequestInit) =>
                new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('stalled', 'AbortError')))),
        );

        await expect(printPdfInPage('/pos.receipt.pdf/INV-7')).resolves.toMatchObject({ ok: false, code: 'RECEIPT_DOWNLOAD_FAILED' });

        const next = printPdfInPage('/pos.receipt.pdf/INV-8');
        const print = await loadFrame();
        await expect(next).resolves.toEqual({ ok: true, via: 'page' });
        expect(print).toHaveBeenCalledTimes(1);
    });

    /*
     * وChrome يُبقي التركيزَ في الإطار بعد الحوار: لوحةُ المفاتيح وماسحُ الباركود
     * يكتبان فيما لا يُرى. فالطباعةُ هنا تنقل التركيزَ إليه كما يفعل هو.
     */
    it('والتركيزُ يعود إلى الصفحة بعد الحوار — لا يبقى في الإطار المخفيّ', async () => {
        render(<PrintReceiptButton number="INV-7" inPage />);
        const button = screen.getByRole('button', { name: /طباعة الفاتورة/ });

        await userEvent.click(button);
        const print = await loadFrame(() => frame()!.focus());

        await waitFor(() => expect(print).toHaveBeenCalledTimes(1));
        expect(document.activeElement).not.toBe(frame());
    });

    it('وما كان مركَّزًا قبل الحوار يعود إليه', async () => {
        render(<input aria-label="الباركود" />);
        const scanner = screen.getByRole('textbox', { name: 'الباركود' });
        scanner.focus();

        const pending = printPdfInPage('/pos.receipt.pdf/INV-7');
        await loadFrame(() => frame()!.focus());
        await pending;

        expect(document.activeElement).toBe(scanner);
    });

    it('وEdge كـChrome — زرُّ الصندوق يطبع في مكانه', async () => {
        browser(EDGE);
        render(<PrintReceiptButton number="INV-8" inPage />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));
        const print = await loadFrame();

        await waitFor(() => expect(print).toHaveBeenCalledTimes(1));
        expect(openMock).not.toHaveBeenCalled();
        expect(toasts.success).not.toHaveBeenCalled();
    });
});

describe('«طباعة الفاتورة» في نقطة البيع', () => {
    it('زرٌّ لا رابطٌ إلى لسان — يطبع الفاتورةَ المضغوطةَ وحدها، ولا يقول «طُبعت»', async () => {
        render(
            <>
                <PrintReceiptButton number="INV-1" inPage label="INV-1" />
                <PrintReceiptButton number="INV-2" inPage label="INV-2" />
            </>,
        );

        expect(screen.queryByRole('link')).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'INV-2' }));
        const print = await loadFrame();

        await waitFor(() => expect(print).toHaveBeenCalledTimes(1));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toMatch(/pos\.receipt\.pdf\/INV-2\?expected_business_id=3$/);
        expect(openMock).not.toHaveBeenCalled();
        expect(toasts.success).not.toHaveBeenCalled();
        expect(toasts.error).not.toHaveBeenCalled();
    });

    it('وضغطتان سريعتان طلبٌ واحد', async () => {
        render(<PrintReceiptButton number="INV-4" inPage />);
        const button = screen.getByRole('button', { name: /طباعة الفاتورة/ });

        await userEvent.click(button);
        await userEvent.click(button);
        await loadFrame();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('ومتصفّحٌ لا يطبع من الصفحة ← رسالةٌ صريحة و«فتح PDF» — بلا إعادةٍ لا تُجدي', async () => {
        browser(IPHONE);
        render(<PrintReceiptButton number="INV-4" inPage />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));

        await waitFor(() => expect(toasts.error).toHaveBeenCalledTimes(1));
        const [message, options] = toasts.error.mock.calls[0];
        expect(message).toBe('هذا المتصفح لا يطبع الفاتورة من داخل الصفحة — افتح PDF واطبعها منه.');
        expect(options.action.label).toBe('فتح PDF');
        expect(options.cancel).toBeUndefined();
        expect(openMock).not.toHaveBeenCalled();

        // و«فتح PDF» ضغطةٌ من الكاشير — الملفُّ نفسُه في لسان
        options.action.onClick();
        expect(openMock).toHaveBeenCalledTimes(1);
        expect(String(openMock.mock.calls[0][0])).toMatch(/pos\.receipt\.pdf\/INV-4/);
    });

    it('وفي درج الفواتير السطرُ تحته: السببُ و«فتح PDF»', async () => {
        browser(CHROME_ANDROID);
        render(<PrintReceiptButton number="INV-5" inPage inline />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));

        const status = await screen.findByTestId('print-status');
        expect(status).toHaveTextContent('هذا المتصفح لا يطبع الفاتورة من داخل الصفحة');
        expect(within(status).queryByRole('button', { name: /إعادة الطباعة/ })).toBeNull();
        await userEvent.click(within(status).getByRole('button', { name: /فتح PDF/ }));
        expect(openMock).toHaveBeenCalledTimes(1);
    });

    it('وحوارٌ لم يُفتح ← «إعادة الطباعة» تُعيد المحاولة نفسَها', async () => {
        render(<PrintReceiptButton number="INV-6" inPage inline />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));
        await loadFrame(() => {
            throw new Error('blocked');
        });

        // «جارٍ تجهيز الإيصال…» أوّلًا، ثمّ السبب
        await waitFor(() => expect(screen.getByTestId('print-status')).toHaveTextContent('لم يُفتح حوار الطباعة'));
        const status = screen.getByTestId('print-status');
        await userEvent.click(within(status).getByRole('button', { name: /إعادة الطباعة/ }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(String(fetchMock.mock.calls[1][0])).toMatch(/INV-6/);
    });

    it('واللوحةُ على لسانها كما كانت — الطباعةُ في الصفحة لنقطة البيع وحدها', () => {
        render(<PrintReceiptButton number="INV-4" routeName="admin.orders.receipt" />);

        const link = screen.getByRole('link', { name: /طباعة الفاتورة/ });
        expect(link).toHaveAttribute('target', '_blank');
        expect(link.getAttribute('href')).toContain('admin.orders.receipt/INV-4');
    });
});

/* ═══════════════════════════ التطبيقُ كما كان ═══════════════════════════ */

describe('وفي تطبيق أبعاد الجسرُ كما كان', () => {
    const app = () => {
        const bridge = {
            version: 1,
            platform: 'android',
            isAvailable: vi.fn(() => true),
            printReceipt: vi.fn(async (_job: { printer: Record<string, unknown> }) => ({ ok: true, printed: true, connected: true })),
            testPrinter: vi.fn(),
            openReceipt: vi.fn(async () => ({ ok: true })),
        };
        vi.stubGlobal('AbaadPrinter', bridge);

        return bridge;
    };

    it('طابعةُ شبكة ← مباشرةً، بلا تنزيلٍ في الصفحة ولا إطار', async () => {
        const bridge = app();

        const outcome = await printReceipt({ invoice: 'INV-9', url: '/pos.receipt.pdf/INV-9', printer: NETWORK, inPage: true });

        expect(outcome).toMatchObject({ ok: true, via: 'native' });
        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
        expect(bridge.printReceipt.mock.calls[0][0].printer).toMatchObject({ address: '192.168.1.50', port: null, paperWidth: 80 });
        expect(fetchMock).not.toHaveBeenCalled();
        expect(frame()).toBeNull();
    });

    it('وطابعةٌ ليست على الشبكة ← حوارُ النظام كما كان', async () => {
        const bridge = app();

        const outcome = await printReceipt({ invoice: 'INV-9', url: '/pos.receipt.pdf/INV-9', printer: { ...NETWORK, connection: 'usb' }, inPage: true });

        expect(outcome).toMatchObject({ ok: true, via: 'system' });
        expect(bridge.openReceipt).toHaveBeenCalledTimes(1);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('وزرُّ الصندوق في التطبيق يطبع مباشرةً ويقول «تمت طباعة الإيصال»', async () => {
        const bridge = app();
        render(<PrintReceiptButton number="INV-4" inPage />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));

        await waitFor(() => expect(toasts.success).toHaveBeenCalledWith('تمت طباعة الإيصال'));
        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ شاشةُ الدفع ═══════════════════════════ */

describe('شاشةُ النجاح بعد البيع', () => {
    const dialog = (result: Record<string, unknown>) =>
        render(
            <PaymentDialog
                open
                onOpenChange={() => {}}
                total={12.5}
                displayTotal={12.5}
                customer="عميل نقدي"
                money={(v) => String(v)}
                fmt={(v) => String(v)}
                onCheckout={(async () => result) as never}
                onNewOrder={() => {}}
                methods={['نقدي']}
            />,
        );

    const pay = async () => {
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /نقدي/ }));
        await user.click(screen.getByRole('button', { name: /تأكيد الدفع/ }));

        return user;
    };

    it('«طباعة الفاتورة» تطبع في مكانها — وشاشةُ النجاح باقية', async () => {
        dialog({ synced: true, invoice: 'INV-9', points: 0, rejected: false });
        const user = await pay();

        await user.click(await screen.findByRole('button', { name: /طباعة الفاتورة/ }));
        const print = await loadFrame();

        await waitFor(() => expect(print).toHaveBeenCalledTimes(1));
        expect(String(fetchMock.mock.calls[0][0])).toMatch(/pos\.receipt\.pdf\/INV-9/);
        expect(openMock).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: /طلب جديد/ })).toBeInTheDocument();
        expect(screen.queryByText('تمت طباعة الإيصال')).toBeNull();
    });

    it('والطباعةُ التلقائيّة على نافذتها المحضَّرة كما كانت — ولا طباعةَ ثانيةٌ في الصفحة', async () => {
        till({ ...NETWORK, connection: 'usb', address: '', autoPrint: true });
        dialog({ synced: true, invoice: 'INV-9', points: 0, rejected: false });
        await pay();

        await screen.findByRole('button', { name: /طلب جديد/ });
        expect(openMock).toHaveBeenCalledTimes(1);
        expect(openMock).toHaveBeenCalledWith('', '_blank');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(frame()).toBeNull();
    });

    it('وبيعةٌ حُفظت بلا اتصال ← لا زرَّ طباعةٍ ولا تنزيل', async () => {
        dialog({ synced: false, invoice: null, points: 0, rejected: false });
        await pay();

        await screen.findByRole('button', { name: /طلب جديد/ });
        expect(screen.queryByRole('button', { name: /طباعة الفاتورة/ })).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('وفشلُ الطباعة في الصفحة يُقال تحت البيع المحفوظ — ومعه «فتح PDF»', async () => {
        browser(SAFARI);
        dialog({ synced: true, invoice: 'INV-9', points: 0, rejected: false });
        const user = await pay();

        await user.click(await screen.findByRole('button', { name: /طباعة الفاتورة/ }));

        const status = await screen.findByTestId('print-status');
        expect(status).toHaveTextContent('تم حفظ البيع، لكن تعذرت طباعة الإيصال.');
        expect(within(status).getByRole('button', { name: /فتح PDF/ })).toBeInTheDocument();
        expect(openMock).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ المعاينة ═══════════════════════════ */

describe('«معاينة الفاتورة» ثمّ «طباعة»', () => {
    it('تُطبع والمعاينةُ مفتوحة — ثمّ تُغلق والشاشةُ تحتها', async () => {
        fetchMock.mockImplementation(async (url: string) =>
            String(url).includes('pos.receipt.paper')
                ? ({ ok: true, json: async () => ({ html: '<p>INV-1042</p>', size: '80mm' }) } as unknown as Response)
                : answer(200, 'application/pdf'),
        );
        render(
            <>
                <p>سلة البيع</p>
                <ReceiptPreviewButton number="INV-1042" />
            </>,
        );
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));
        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: /طباعة/ }));
        const print = await loadFrame();

        await waitFor(() => expect(print).toHaveBeenCalledTimes(1));
        expect(String(fetchMock.mock.calls.at(-1)![0])).toMatch(/pos\.receipt\.pdf\/INV-1042/);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(openMock).not.toHaveBeenCalled();

        await user.keyboard('{Escape}');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(screen.getByText('سلة البيع')).toBeInTheDocument();
    });

    const preview = async () => {
        render(
            <>
                <p>سلة البيع</p>
                <ReceiptPreviewButton number="INV-1042" />
            </>,
        );
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));

        return { user, dialog: await screen.findByRole('dialog') };
    };
    const paperThen = (pdf: Response) =>
        fetchMock.mockImplementation(async (url: string) =>
            String(url).includes('pos.receipt.paper')
                ? ({ ok: true, json: async () => ({ html: '<p>INV-1042</p>', size: '80mm' }) } as unknown as Response)
                : pdf,
        );

    it('وEscape يُغلقها بعد الطباعة — والتركيزُ لم يبقَ في الإطار', async () => {
        paperThen(answer(200, 'application/pdf'));
        const { user, dialog } = await preview();

        await user.click(within(dialog).getByRole('button', { name: /طباعة/ }));
        const print = await loadFrame(() => frame()!.focus());
        await waitFor(() => expect(print).toHaveBeenCalledTimes(1));

        await user.keyboard('{Escape}');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    /*
     * والفشلُ يُقال داخلها لا في إشعار: النافذةُ المفتوحة تمنع الضغطَ خارجها،
     * فـ«فتح PDF» في إشعارٍ تحتها يُرى ولا يُضغط.
     */
    it('وفشلُ التنزيل يُقال داخلها — و«فتح PDF» فيها يُضغط والمعاينةُ مفتوحة', async () => {
        paperThen(answer(500, 'text/html', 'oops'));
        const { user, dialog } = await preview();

        await user.click(within(dialog).getByRole('button', { name: /طباعة/ }));
        const status = await within(dialog).findByRole('alert');
        expect(status).toHaveTextContent('تعذر تنزيل الإيصال');
        expect(toasts.error).not.toHaveBeenCalled();

        await user.click(within(status).getByRole('button', { name: /فتح PDF/ }));
        expect(openMock).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('ومتصفّحٌ لا يطبع من الصفحة ← السببُ و«فتح PDF» داخلها، بلا إعادة', async () => {
        browser(FIREFOX);
        paperThen(answer(200, 'application/pdf'));
        const { user, dialog } = await preview();

        await user.click(within(dialog).getByRole('button', { name: /طباعة/ }));
        const status = await within(dialog).findByRole('alert');
        expect(status).toHaveTextContent('هذا المتصفح لا يطبع الفاتورة من داخل الصفحة');
        expect(within(status).getByRole('button', { name: /فتح PDF/ })).toBeInTheDocument();
        expect(within(status).queryByRole('button', { name: /إعادة الطباعة/ })).toBeNull();
        expect(toasts.error).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ النطاق ═══════════════════════════ */

describe('في نقطة البيع وحدها', () => {
    const read = (path: string) => readFileSync(resolve(__dirname, '../../resources/js', path), 'utf8');
    const buttons = (src: string) => src.match(/<PrintReceiptButton\b[^>]*\/>/g) ?? [];

    it.each(['Pages/Pos/Receipts.tsx', 'Pages/Pos/OrderDetails.tsx'])('كلُّ زرّ طباعةٍ في %s يطبع في مكانه', (file) => {
        const found = buttons(read(file));

        expect(found.length).toBeGreaterThan(0);
        found.forEach((b) => expect(b).toMatch(/\binPage\b/));
    });

    it.each(['Pages/Pos/partials/PaymentDialog.tsx', 'Pages/Pos/partials/ReceiptPreview.tsx'])('و%s يطبع في مكانه', (file) => {
        expect(read(file)).toContain("useReceiptPrint('pos.receipt.pdf', { inPage: true })");
    });

    it.each(['Pages/Admin/Orders/Show.tsx', 'Pages/Admin/Preparation/partials/PrepDetails.tsx', 'lib/website-confirm-print.ts'])(
        'و%s على ما كان — لسانٌ في المتصفّح',
        (file) => {
            const src = read(file);

            expect(src).not.toMatch(/\binPage\b/);
            buttons(src).forEach((b) => expect(b).not.toMatch(/\binPage\b/));
        },
    );
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PaymentDialog from '@/Pages/Pos/partials/PaymentDialog';
import PrintReceiptButton from '@/Components/PrintReceiptButton';
import ReceiptPreviewButton from '@/Pages/Pos/partials/ReceiptPreview';
import { resetPrintFrame, setPagePrintTiming } from '@/lib/printing/page-print';
import { pagePrintMode } from '@/lib/printing/printer-service';
import { forgetPreparedReceipts, sharePrintSupported } from '@/lib/printing/share-print';
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
 * الآيفون والآيباد: «طباعة أو مشاركة PDF» — قائمةُ النظام فوق شاشة البيع.
 *
 * ═══ لمَ لا إطار ═══
 *
 * Safari في iOS لا يطبع PDF من إطار — يطبع الصفحةَ التي تحته أو صفحةً
 * فارغة. فالطريقُ ما تدعمه Apple: مشاركةُ الملفّ (`navigator.share`)، وفي
 * قائمتها «طباعة» لملفّ PDF. والصفحةُ تحتها كما هي.
 *
 * ═══ وما يُحرس ═══
 *
 *   - القائمةُ تُفتح **في الضغطة نفسِها**: الإيصالُ مجهَّزٌ قبلها، والضغطةُ لا
 *     تنتظر تنزيلًا (Safari يرفض مشاركةً تأخّرت).
 *   - والملفُّ هو الـPDF الرسميّ كاملًا، باسم الفاتورة.
 *   - ولا «طُبع» عن قائمةٍ اكتملت، ولا خطأَ عن قائمةٍ أغلقها الكاشير.
 *   - ولا تُفتح القائمةُ وحدها أبدًا — لا بعد البيع ولا بعد التجهيز.
 *   - وحيث لا مشاركةَ بملفّات: رسالةٌ صريحة و«فتح PDF».
 *   - والتطبيقُ على جسره، والطباعةُ التلقائيّة كما كانت.
 *
 * والمحاكاةُ هنا لـ`navigator.share`: ما يظهر في قائمة iOS وما يخرج من
 * الطابعة لا يُقاس إلّا على جهاز — انظر وصف #129.
 */

const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const IPAD_DESKTOP_MODE = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const MAC_SAFARI = IPAD_DESKTOP_MODE;
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

const PDF = new Blob(['%PDF-1.7 receipt'], { type: 'application/pdf' });

const answer = (status: number, type: string, body: Blob | string = PDF) =>
    ({
        ok: status >= 200 && status < 300,
        status,
        headers: { get: (h: string) => (h.toLowerCase() === 'content-type' ? type : null) },
        blob: async () => (typeof body === 'string' ? new Blob([body], { type }) : body),
    }) as unknown as Response;

let fetchMock: ReturnType<typeof vi.fn>;
let openMock: ReturnType<typeof vi.fn>;
let share: ReturnType<typeof vi.fn>;

/** الجهازُ الذي يقف أمامه الكاشير — وما تقوله قائمةُ مشاركته */
const device = (
    ua: string,
    { touch = 5, canShare = true, shares = true }: { touch?: number; canShare?: boolean | 'missing'; shares?: boolean } = {},
) => {
    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(ua);
    Object.defineProperty(window.navigator, 'maxTouchPoints', { value: touch, configurable: true });
    Object.defineProperty(window.navigator, 'pdfViewerEnabled', { value: true, configurable: true });
    Object.defineProperty(window.navigator, 'share', { value: shares ? share : undefined, configurable: true });
    Object.defineProperty(window.navigator, 'canShare', {
        value: canShare === 'missing' ? undefined : vi.fn(() => canShare),
        configurable: true,
    });
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

/** ما شوركَ — الملفُّ واسمُه */
const shared = (call = 0) => (share.mock.calls[call][0] as { files: File[]; title: string });

beforeEach(() => {
    Object.values(toasts).forEach((f) => f.mockClear());
    resetPrintFrame();
    forgetPreparedReceipts();
    setPagePrintTiming({ settleMs: 0, timeoutMs: 20000, downloadMs: 30000 });
    fetchMock = vi.fn(async () => answer(200, 'application/pdf'));
    vi.stubGlobal('fetch', fetchMock);
    openMock = vi.fn().mockReturnValue({ location: { replace: vi.fn() }, close: vi.fn() });
    vi.stubGlobal('open', openMock);
    share = vi.fn(async () => {});
    device(IPAD);
    till(NETWORK);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    resetPrintFrame();
    for (const key of ['share', 'canShare', 'maxTouchPoints']) {
        Object.defineProperty(window.navigator, key, { value: undefined, configurable: true });
    }
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
    });
});

/* ═══════════════════════════ أيُّ جهاز ═══════════════════════════ */

describe('قائمةُ المشاركة — حيث تعرض «طباعة» لملفّ PDF', () => {
    it.each([
        ['Safari على الآيباد', IPAD, 5, 'share'],
        ['الآيباد في «وضع الحاسوب»', IPAD_DESKTOP_MODE, 5, 'share'],
        ['Safari على الآيفون', IPHONE, 5, 'share'],
        ['Safari على الماك — قائمتُه بلا «طباعة»', MAC_SAFARI, 0, 'none'],
        ['Chrome على أندرويد — قائمتُه بلا «طباعة»', CHROME_ANDROID, 5, 'none'],
    ])('%s', (_name, ua, touch, mode) => {
        device(ua, { touch });

        expect(pagePrintMode()).toBe(mode);
    });

    it('ويُسأل عن الملفّ لا عن الوجود: iOS 14 يشارك روابطَ لا ملفّات', () => {
        device(IPAD, { canShare: false });
        expect(sharePrintSupported()).toBe(false);

        device(IPAD, { canShare: 'missing' });
        expect(sharePrintSupported()).toBe(false);

        device(IPAD, { shares: false });
        expect(sharePrintSupported()).toBe(false);
    });
});

/* ═══════════════════════════ الضغطةُ تفتح القائمة ═══════════════════════════ */

describe('«طباعة أو مشاركة PDF» في الآيباد', () => {
    it('يُجهَّز الإيصالُ حين يُعرض الزرّ — والضغطةُ تفتح القائمةَ فيها نفسِها، بالملفّ كاملًا', async () => {
        const long = new Blob(['%PDF-1.7 ' + 'x'.repeat(200000)], { type: 'application/pdf' });
        fetchMock.mockResolvedValue(answer(200, 'application/pdf', long));
        render(<PrintReceiptButton number="INV-7" inPage prefetch />);

        const button = screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ });
        await waitFor(() => expect(button).toBeEnabled());
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        // تجهيزٌ لا غير — لا قائمةَ تُفتح وحدها، ولا «جاهز» يُقال عمّا لم يُطلب
        await act(async () => {});
        expect(share).not.toHaveBeenCalled();
        expect(toasts.info).not.toHaveBeenCalled();

        /*
         * والدعوى كلُّها هنا: `fireEvent.click` متزامن — فإن نُوديت المشاركةُ
         * بعد أيّ انتظار لم تُرَ في السطر التالي. Safari يرفض ما تأخّر.
         */
        fireEvent.click(button);
        expect(share).toHaveBeenCalledTimes(1);

        const { files, title } = shared();
        expect(title).toBe('INV-7');
        expect(files).toHaveLength(1);
        expect(files[0].name).toBe('INV-7.pdf');
        expect(files[0].type).toBe('application/pdf');
        expect(files[0].size).toBe(long.size);
        expect(String(fetchMock.mock.calls[0][0])).toMatch(/pos\.receipt\.pdf\/INV-7\?expected_business_id=3$/);

        // ولا لسانَ ولا إطار — ولا «طُبع» عن قائمةٍ اكتملت
        await act(async () => {});
        expect(openMock).not.toHaveBeenCalled();
        expect(document.querySelector('iframe[data-print-frame]')).toBeNull();
        expect(toasts.success).not.toHaveBeenCalled();
        expect(toasts.error).not.toHaveBeenCalled();
    });

    it('وفي الآيفون كذلك', async () => {
        device(IPHONE);
        render(<PrintReceiptButton number="INV-9" inPage prefetch />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {});

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));

        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].name).toBe('INV-9.pdf');
    });

    /*
     * وصفٌّ في الجدول لا يُجهَّز سلفًا — ثلاثون صفًّا لا ثلاثون ملفًّا.
     * فالضغطةُ الأولى تُجهّزه، ويُقال «جاهز» ومعه زرٌّ هو ضغطةٌ جديدة.
     */
    it('وما لم يُجهَّز: الضغطةُ الأولى تُجهّزه، والإشعارُ بزرٍّ يفتح القائمة', async () => {
        render(<PrintReceiptButton number="INV-4" inPage iconOnly />);
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(share).not.toHaveBeenCalled();

        await waitFor(() => expect(toasts.info).toHaveBeenCalledTimes(1));
        const [message, options] = toasts.info.mock.calls[0];
        expect(message).toBe('الإيصال جاهز — اختر «طباعة» من قائمة المشاركة.');
        expect(options.action.label).toBe('طباعة أو مشاركة PDF');
        expect(share).not.toHaveBeenCalled();

        act(() => options.action.onClick());
        expect(share).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('وفي سطر الدرج: «جاهز» وزرُّه تحت الزرّ', async () => {
        render(<PrintReceiptButton number="INV-5" inPage inline />);

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        await waitFor(() => expect(screen.getByTestId('print-status')).toHaveTextContent('الإيصال جاهز'));

        fireEvent.click(within(screen.getByTestId('print-status')).getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(share).toHaveBeenCalledTimes(1);
        await waitFor(() => expect(screen.queryByTestId('print-status')).toBeNull());
    });

    it('وضغطاتٌ متتابعةٌ قبل الجاهزيّة تنزيلٌ واحد', async () => {
        let finish: (r: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((r) => { finish = r; }));
        render(<PrintReceiptButton number="INV-6" inPage inline />);
        const button = screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ });

        fireEvent.click(button);
        fireEvent.click(button);
        fireEvent.click(button);
        await act(async () => finish(answer(200, 'application/pdf')));

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(share).not.toHaveBeenCalled();
    });

    it('وضغطةٌ والتجهيزُ الصامت لم ينتهِ ← تلحق به، لا تنزيلٌ ثانٍ', async () => {
        let finish: (r: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((r) => { finish = r; }));
        render(<PrintReceiptButton number="INV-6" inPage prefetch inline />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        await act(async () => finish(answer(200, 'application/pdf')));

        expect(fetchMock).toHaveBeenCalledTimes(1);
        await waitFor(() => expect(screen.getByTestId('print-status')).toHaveTextContent('الإيصال جاهز'));
        expect(share).not.toHaveBeenCalled();
    });

    it('وكلُّ زرٍّ يشارك فاتورتَه هو', async () => {
        render(
            <>
                <PrintReceiptButton number="INV-1" inPage prefetch label="INV-1" />
                <PrintReceiptButton number="INV-2" inPage prefetch label="INV-2" />
            </>,
        );
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await act(async () => {});

        fireEvent.click(screen.getByRole('button', { name: 'INV-2' }));

        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].name).toBe('INV-2.pdf');
    });
});

/* ═══════════════════════════ ما تردّه القائمة ═══════════════════════════ */

/*
 * والمجهَّزُ آخرُ ما نزل — لا نسخةٌ قبل تعديل الطلب.
 *
 * رابطُ الإيصال لا يتبدّل حين يُعدَّل الطلب: صنفٌ يُضاف في تفاصيله، دفعةٌ
 * تُصحَّح. فلو أُعيد الملفُّ المجهَّزُ برابطه لشاركه الكاشيرُ بأصنافه القديمة
 * ومبلغه القديم، والشاشةُ أمامه تقول غيرَ ذلك.
 */
describe('لا يُشارَك إيصالٌ قبل تعديل طلبه', () => {
    const BEFORE = new Blob(['%PDF-1.7 before the edit'], { type: 'application/pdf' });
    const AFTER = new Blob(['%PDF-1.7 after the edit — one more line, a new total'], { type: 'application/pdf' });
    const pdfs = (...blobs: Blob[]) => blobs.forEach((b) => fetchMock.mockResolvedValueOnce(answer(200, 'application/pdf', b)));

    it('الطلبُ عُدِّل في شاشته (`version`) ← يُجهَّز من جديد، والضغطةُ تشارك ما بعد التعديل', async () => {
        pdfs(BEFORE, AFTER);
        const { rerender } = render(<PrintReceiptButton number="INV-7" inPage prefetch version="v1" />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {});

        rerender(<PrintReceiptButton number="INV-7" inPage prefetch version="v2" />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await act(async () => {});

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].size).toBe(AFTER.size);
    });

    it('وشاشةٌ تُفتح من جديد ← تنزيلٌ جديد، لا ما جُهّز في فتحٍ سبقها', async () => {
        pdfs(BEFORE, AFTER);
        const first = render(<PrintReceiptButton number="INV-7" inPage prefetch />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {});
        first.unmount();

        render(<PrintReceiptButton number="INV-7" inPage prefetch />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await act(async () => {});

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(shared().files[0].size).toBe(AFTER.size);
    });

    it('وضغطةٌ والنسخةُ الجديدة تنزل ← لا تُشارَك القديمة، وتُشارَك الجديدةُ بعدها', async () => {
        let land: (r: Response) => void = () => {};
        fetchMock
            .mockResolvedValueOnce(answer(200, 'application/pdf', BEFORE))
            .mockImplementationOnce(() => new Promise<Response>((r) => (land = r)));
        const { rerender } = render(<PrintReceiptButton number="INV-7" inPage prefetch inline version="v1" />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {});

        rerender(<PrintReceiptButton number="INV-7" inPage prefetch inline version="v2" />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(share).not.toHaveBeenCalled();

        await act(async () => land(answer(200, 'application/pdf', AFTER)));
        const status = await screen.findByTestId('print-status');
        await waitFor(() => expect(status).toHaveTextContent('الإيصال جاهز'));

        fireEvent.click(within(status).getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].size).toBe(AFTER.size);
    });
});

describe('ما تردّه قائمةُ المشاركة', () => {
    const ready = async (number = 'INV-7') => {
        render(<PrintReceiptButton number={number} inPage prefetch inline />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {});

        return screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ });
    };

    it('أغلقها الكاشير ← لا خطأَ يُقال، والضغطةُ التالية تفتحها بلا تنزيلٍ جديد', async () => {
        share.mockRejectedValueOnce(new DOMException('cancelled', 'AbortError'));
        const button = await ready();

        fireEvent.click(button);
        await act(async () => {});

        expect(screen.queryByTestId('print-status')).toBeNull();
        expect(toasts.error).not.toHaveBeenCalled();

        fireEvent.click(button);
        expect(share).toHaveBeenCalledTimes(2);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('فاتت الضغطة (`NotAllowedError`) ← يُقال، و«إعادة الطباعة» ضغطةٌ جديدة تفتحها', async () => {
        share.mockRejectedValueOnce(new DOMException('gesture', 'NotAllowedError'));
        const button = await ready();

        fireEvent.click(button);
        await waitFor(() => expect(screen.getByTestId('print-status')).toHaveTextContent('لم تُفتح قائمة المشاركة'));

        fireEvent.click(within(screen.getByTestId('print-status')).getByRole('button', { name: /إعادة الطباعة/ }));
        expect(share).toHaveBeenCalledTimes(2);
    });

    it('ورُفض الملفّ ← «تعذّرت مشاركة الإيصال» ومعه «فتح PDF»', async () => {
        share.mockRejectedValueOnce(new TypeError('files not supported'));
        const button = await ready();

        fireEvent.click(button);
        const status = await screen.findByTestId('print-status');
        await waitFor(() => expect(status).toHaveTextContent('تعذّرت مشاركة الإيصال'));

        await userEvent.click(within(status).getByRole('button', { name: /فتح PDF/ }));
        expect(openMock).toHaveBeenCalledTimes(1);
    });

    it.each([
        ['خطأٌ في الخادم', answer(500, 'text/html', 'oops'), 'تعذر تنزيل الإيصال'],
        ['انتهت الجلسة', answer(200, 'text/html', '<html>login</html>'), 'انتهت الجلسة'],
    ])('والتنزيلُ يُسأل قبل القائمة — %s', async (_name, response, message) => {
        fetchMock.mockResolvedValueOnce(response);
        render(<PrintReceiptButton number="INV-7" inPage inline />);

        fireEvent.click(screen.getByRole('button', { name: /طباعة أو مشاركة PDF/ }));

        await waitFor(() => expect(screen.getByTestId('print-status')).toHaveTextContent(message));
        expect(share).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ حيث لا مشاركةَ بملفّات ═══════════════════════════ */

describe('وحيث لا تُشارَك الملفّات', () => {
    it.each([
        ['آيباد لا يشارك ملفّات PDF', () => device(IPAD, { canShare: false })],
        ['آيباد بلا قائمة مشاركة', () => device(IPAD, { shares: false })],
        ['Chrome على أندرويد', () => device(CHROME_ANDROID)],
        ['Safari على الماك', () => device(MAC_SAFARI, { touch: 0 })],
    ])('%s ← رسالةٌ صريحة و«فتح PDF» — ولا قائمةَ ولا لسانَ وحده', async (_name, setup) => {
        setup();
        render(<PrintReceiptButton number="INV-4" inPage />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));

        await waitFor(() => expect(toasts.error).toHaveBeenCalledTimes(1));
        expect(toasts.error.mock.calls[0][0]).toBe('هذا المتصفح لا يطبع الفاتورة من داخل الصفحة — افتح PDF واطبعها منه.');
        expect(toasts.error.mock.calls[0][1].action.label).toBe('فتح PDF');
        expect(share).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ التطبيقُ أوّلًا ═══════════════════════════ */

describe('وفي تطبيق أبعاد على الآيباد: الجسرُ أوّلًا', () => {
    it('طابعةُ شبكة ← مباشرةً، بلا قائمةِ مشاركةٍ ولا تنزيلٍ في الصفحة', async () => {
        const bridge = {
            version: 1,
            platform: 'ios',
            isAvailable: vi.fn(() => true),
            printReceipt: vi.fn(async () => ({ ok: true, printed: true, connected: true })),
            testPrinter: vi.fn(),
            openReceipt: vi.fn(async () => ({ ok: true })),
        };
        vi.stubGlobal('AbaadPrinter', bridge);
        render(<PrintReceiptButton number="INV-4" inPage prefetch />);

        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));

        await waitFor(() => expect(toasts.success).toHaveBeenCalledWith('تمت طباعة الإيصال'));
        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
        expect(share).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ شاشةُ النجاح ═══════════════════════════ */

describe('شاشةُ النجاح في الآيباد', () => {
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
    };

    it('يُجهَّز إيصالُ البيعة ولا تُفتح القائمةُ وحدها — والضغطةُ تفتحها وشاشةُ النجاح باقية', async () => {
        dialog({ synced: true, invoice: 'INV-9', points: 0, rejected: false });
        await pay();

        const button = await screen.findByRole('button', { name: /طباعة أو مشاركة PDF/ });
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {});
        expect(share).not.toHaveBeenCalled();

        fireEvent.click(button);
        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].name).toBe('INV-9.pdf');

        await act(async () => {});
        expect(screen.getByRole('button', { name: /طلب جديد/ })).toBeInTheDocument();
        expect(screen.queryByText('تمت طباعة الإيصال')).toBeNull();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('والطباعةُ التلقائيّة كما كانت — نافذتُها المحضَّرة، ولا قائمةَ مشاركةٍ تُفتح', async () => {
        till({ ...NETWORK, connection: 'usb', address: '', autoPrint: true });
        dialog({ synced: true, invoice: 'INV-9', points: 0, rejected: false });
        await pay();

        await screen.findByRole('button', { name: /طلب جديد/ });
        expect(openMock).toHaveBeenCalledTimes(1);
        expect(openMock).toHaveBeenCalledWith('', '_blank');
        await act(async () => {});
        expect(share).not.toHaveBeenCalled();
    });

    it('وبيعةٌ حُفظت بلا اتصال ← لا زرَّ ولا تنزيلَ ولا قائمة', async () => {
        dialog({ synced: false, invoice: null, points: 0, rejected: false });
        await pay();

        await screen.findByRole('button', { name: /طلب جديد/ });
        expect(screen.queryByRole('button', { name: /طباعة أو مشاركة PDF/ })).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(share).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ المعاينة ═══════════════════════════ */

describe('«معاينة الفاتورة» في الآيباد', () => {
    it('تُجهّز الملفَّ حين تُفتح — و«طباعة أو مشاركة PDF» تفتح القائمةَ والمعاينةُ مفتوحة، ثمّ تُغلق والشاشةُ تحتها', async () => {
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
        const print = within(dialog).getByRole('button', { name: /طباعة أو مشاركة PDF/ });
        await waitFor(() => expect(print).toBeEnabled());
        expect(share).not.toHaveBeenCalled();

        fireEvent.click(print);
        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].name).toBe('INV-1042.pdf');

        await act(async () => {});
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        print.focus();
        await user.keyboard('{Escape}');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(screen.getByText('سلة البيع')).toBeInTheDocument();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('وتُفتح ثانيةً بعد تعديل الطلب ← تُجهّز من جديد، ولا تشارك ما جُهّز في فتحها الأوّل', async () => {
        const BEFORE = new Blob(['%PDF-1.7 before the edit'], { type: 'application/pdf' });
        const AFTER = new Blob(['%PDF-1.7 after the edit — one more line'], { type: 'application/pdf' });
        const pdfs = [BEFORE, AFTER];
        fetchMock.mockImplementation(async (url: string) =>
            String(url).includes('pos.receipt.paper')
                ? ({ ok: true, json: async () => ({ html: '<p>INV-1042</p>', size: '80mm' }) } as unknown as Response)
                : answer(200, 'application/pdf', pdfs.shift()!),
        );
        render(<ReceiptPreviewButton number="INV-1042" />);
        const user = userEvent.setup();
        const open = async () => {
            await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));
            const dialog = await screen.findByRole('dialog');
            const print = within(dialog).getByRole('button', { name: /طباعة أو مشاركة PDF/ });
            await waitFor(() => expect(print).toBeEnabled());

            return print;
        };

        (await open()).focus();
        await user.keyboard('{Escape}');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());

        const print = await open();
        await waitFor(() => expect(pdfs).toHaveLength(0));
        await act(async () => {});
        fireEvent.click(print);
        expect(shared().files[0].size).toBe(AFTER.size);
    });

    /*
     * والفشلُ داخلها لا في إشعار: النافذةُ المفتوحة تمنع الضغطَ خارجها، فزرُّ
     * الإشعار يُرى ولا يُضغط.
     */
    it('وتجهيزٌ فشل حين فُتحت ← يُقال داخلها، و«إعادة الطباعة» فيها تُجهّزه ثمّ تفتح القائمة', async () => {
        let pdf = 0;
        fetchMock.mockImplementation(async (url: string) => {
            if (String(url).includes('pos.receipt.paper')) {
                return { ok: true, json: async () => ({ html: '<p>INV-1042</p>', size: '80mm' }) } as unknown as Response;
            }

            return ++pdf === 1 ? answer(500, 'text/html', 'oops') : answer(200, 'application/pdf');
        });
        render(<ReceiptPreviewButton number="INV-1042" />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));
        const dialog = await screen.findByRole('dialog');
        const failed = await within(dialog).findByRole('alert');
        expect(failed).toHaveTextContent('تعذر تنزيل الإيصال');
        expect(toasts.error).not.toHaveBeenCalled();
        expect(toasts.info).not.toHaveBeenCalled();

        await user.click(within(failed).getByRole('button', { name: /إعادة الطباعة/ }));
        const ready = await within(dialog).findByTestId('print-status');
        await waitFor(() => expect(ready).toHaveTextContent('الإيصال جاهز'));
        expect(share).not.toHaveBeenCalled();

        fireEvent.click(within(ready).getByRole('button', { name: /طباعة أو مشاركة PDF/ }));
        expect(share).toHaveBeenCalledTimes(1);
        expect(shared().files[0].name).toBe('INV-1042.pdf');
    });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PaymentDialog from '@/Pages/Pos/partials/PaymentDialog';
import PrintReceiptButton from '@/Components/PrintReceiptButton';
import useReceiptPrint from '@/hooks/useReceiptPrint';
import { normalizeResult } from '@/lib/printing/bridge';
import {
    abandonAutoPrint,
    commitAutoPrint,
    nativePrintingAvailable,
    prepareAutoPrint,
    printReceipt,
    testPrinter,
} from '@/lib/printing/printer-service';
import type { PrinterLike } from '@/lib/printing/types';
import { CONFIRMED, PENDING, prepareWebsiteConfirmPrint, websiteConfirmPrintCallbacks } from '@/lib/website-confirm-print';
import { pageProps } from './setup';

const toasts = { warning: vi.fn(), error: vi.fn(), success: vi.fn() };
vi.mock('sonner', () => ({
    toast: {
        warning: (...a: unknown[]) => toasts.warning(...a),
        error: (...a: unknown[]) => toasts.error(...a),
        success: (...a: unknown[]) => toasts.success(...a),
    },
}));

/**
 * تطبيقُ أبعاد يطبع على طابعة الشبكة مباشرةً — والمتصفّحُ يبقى كما كان.
 *
 * ═══ ما يُقاس هنا ═══
 *
 * القرارُ لا الطابعة: أيُّ طريقٍ يُسلك (مباشرةً، حوارُ النظام، لسانُ
 * المتصفّح)، وما يُرسل إلى الجسر بالضبط، ومتى لا يُرسل شيء — بيعةٌ بلا رقم
 * فاتورة، وطباعةٌ تلقائيّةٌ مطفأة، وطلبُ موقعٍ لم يُؤكَّد الآن.
 *
 * والبيعُ والطباعةُ نتيجتان منفصلتان: فشلُ الطباعة يُقال في شاشة النجاح ولا
 * يمسّ البيع، ولا يُعاد تلقائيًّا.
 *
 * والطرفُ الأصليّ (Kotlin وSwift) يُختبر في مكانه — `mobile/` ومتّجهاتُه
 * المشتركة. وسلوكُ المتصفّح القديم حارسُه `the-till-prints-what-it-promised`
 * و`a-website-order-prints-when-it-is-confirmed`.
 */

const NETWORK: PrinterLike = {
    id: 7,
    type: 'طابعة',
    name: 'طابعة الكاشير',
    model: null,
    connection: 'network',
    address: ' 192.168.1.50 ',
    port: null,
    paperWidth: 80,
    autoPrint: true,
};

type Bridge = {
    version: number;
    platform: string;
    isAvailable: ReturnType<typeof vi.fn>;
    printReceipt: ReturnType<typeof vi.fn>;
    testPrinter: ReturnType<typeof vi.fn>;
    openReceipt: ReturnType<typeof vi.fn>;
};

/** تطبيقُ أبعاد كما يراه الويب — `window.AbaadPrinter` */
const app = (over: Partial<Bridge> = {}): Bridge => {
    const bridge: Bridge = {
        version: 1,
        platform: 'android',
        isAvailable: vi.fn(() => true),
        printReceipt: vi.fn(async (_job: unknown, onProgress?: (s: string) => void) => {
            onProgress?.('downloading_receipt');
            onProgress?.('sending');

            return { ok: true, jobId: 'job-1', printed: true, connected: true, effectivePort: 9100 };
        }),
        testPrinter: vi.fn(async () => ({ ok: true, jobId: 'job-2', printed: true, connected: true, effectivePort: 9100 })),
        openReceipt: vi.fn(async () => ({ ok: true, jobId: 'job-3' })),
        ...over,
    };
    vi.stubGlobal('AbaadPrinter', bridge);

    return bridge;
};

const till = (printer: Record<string, unknown> | null) =>
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: printer ? [printer] : [] },
        auth: { abilities: [], mayActions: [], user: { businessId: 3 } },
    });

let sheet: { location: { replace: ReturnType<typeof vi.fn> }; close: ReturnType<typeof vi.fn> };
let openMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    Object.values(toasts).forEach((f) => f.mockClear());
    sheet = { location: { replace: vi.fn() }, close: vi.fn() };
    openMock = vi.fn().mockReturnValue(sheet);
    vi.stubGlobal('open', openMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
    });
});

/* ═══════════════════════════ القرار ═══════════════════════════ */

describe('أيُّ طريقٍ يُسلك', () => {
    it('التطبيقُ وطابعةُ شبكة ← مباشرةً، بلا نافذة', () => {
        app();

        expect(nativePrintingAvailable(NETWORK)).toBe(true);
        expect(prepareAutoPrint(NETWORK).mode).toBe('native');
        expect(openMock).not.toHaveBeenCalled();
    });

    it('والمتصفّح ← النافذةُ الفارغة في الضغطة كما كانت', () => {
        const attempt = prepareAutoPrint(NETWORK);

        expect(attempt.mode).toBe('browser');
        expect(openMock).toHaveBeenCalledWith('', '_blank');
        expect(attempt.sheet).toBe(sheet);
    });

    it('والطباعةُ التلقائيّة مطفأة ← لا نافذةَ ولا جسر', async () => {
        const bridge = app();
        const attempt = prepareAutoPrint({ ...NETWORK, autoPrint: false });

        expect(attempt.mode).toBe('none');
        expect(await commitAutoPrint(attempt, 'INV-9', () => '/pos.receipt.pdf/INV-9')).toBeNull();
        expect(bridge.printReceipt).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('والتطبيقُ وطابعةٌ على USB ← حوارُ الطباعة في النظام، لا TCP', async () => {
        const bridge = app();
        const attempt = prepareAutoPrint({ ...NETWORK, connection: 'usb', address: null });

        expect(attempt.mode).toBe('system');
        const outcome = await commitAutoPrint(attempt, 'INV-9', () => '/pos.receipt.pdf/INV-9');

        expect(outcome).toMatchObject({ ok: true, via: 'system' });
        expect(bridge.openReceipt).toHaveBeenCalledTimes(1);
        expect(bridge.printReceipt).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('وجسرٌ لا يتاح لهذه الصفحة أو بإصدارٍ آخر ← المتصفّح', () => {
        app({ isAvailable: vi.fn(() => false) });
        expect(nativePrintingAvailable(NETWORK)).toBe(false);

        app({ version: 2 });
        expect(nativePrintingAvailable(NETWORK)).toBe(false);
    });

    it('وما يُرسل إلى الجسر: رابطٌ مطلق، وطابعةٌ بلا مسافات، ولا بايتات', async () => {
        const bridge = app();

        const outcome = await printReceipt({ invoice: 'INV-9', url: '/pos.receipt.pdf/INV-9', printer: NETWORK });

        expect(outcome).toMatchObject({ ok: true, via: 'native', effectivePort: 9100 });
        const job = bridge.printReceipt.mock.calls[0][0];
        expect(job).toEqual({
            invoice: 'INV-9',
            receiptUrl: `${window.location.origin}/pos.receipt.pdf/INV-9`,
            printer: {
                id: 7,
                name: 'طابعة الكاشير',
                model: null,
                connection: 'network',
                address: '192.168.1.50',
                port: null,
                paperWidth: 80,
            },
        });
        expect(openMock).not.toHaveBeenCalled();
    });
});

describe('بيعةٌ بلا رقم فاتورة', () => {
    it('لا إيصالَ رسميًّا يُطبع ولا رقمَ يُخترع', async () => {
        const bridge = app();
        const attempt = prepareAutoPrint(NETWORK);

        expect(await commitAutoPrint(attempt, null, () => '/x')).toBeNull();
        expect(bridge.printReceipt).not.toHaveBeenCalled();
    });

    it('وفي المتصفّح تُغلق الفارغةُ — مرّةً واحدة', async () => {
        const attempt = prepareAutoPrint(NETWORK);

        await commitAutoPrint(attempt, null, () => '/x');
        abandonAutoPrint(attempt);

        expect(sheet.close).toHaveBeenCalledTimes(1);
        expect(sheet.location.replace).not.toHaveBeenCalled();
    });

    it('ومنعُ المتصفّح النافذةَ يعود نتيجةً تُقال — لا صمتًا', async () => {
        openMock.mockReturnValue(null);
        const attempt = prepareAutoPrint(NETWORK);

        expect(await commitAutoPrint(attempt, 'INV-9', () => '/x')).toMatchObject({
            ok: false,
            code: 'POPUP_BLOCKED',
            via: 'browser',
        });
    });
});

describe('ما يردّه الجسر لا يُصدَّق إلّا صريحًا', () => {
    it('النجاحُ `true` وحده — لا نصّ ولا رقم', () => {
        expect(normalizeResult({ ok: 'true' })).toMatchObject({ ok: false, code: 'INTERNAL' });
        expect(normalizeResult(null)).toMatchObject({ ok: false, code: 'INTERNAL' });
        expect(normalizeResult({ ok: false, code: 'SOMETHING_NEW' })).toMatchObject({ ok: false, code: 'INTERNAL' });
        expect(normalizeResult({ ok: false, code: 'PORT_REFUSED', effectivePort: 9101 })).toMatchObject({
            ok: false,
            code: 'PORT_REFUSED',
            effectivePort: 9101,
        });
    });

    it('وجسرٌ يرمي استثناءً ← فشلٌ يُعرض، لا انهيار', async () => {
        app({ printReceipt: vi.fn(() => Promise.reject(new Error('boom'))) });
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const outcome = await printReceipt({ invoice: 'INV-9', url: '/x', printer: NETWORK });

        expect(outcome).toMatchObject({ ok: false, code: 'INTERNAL', via: 'native' });
        warn.mockRestore();
    });
});

/* ═══════════════════════════ شاشة الدفع ═══════════════════════════ */

describe('شاشةُ الدفع في تطبيق أبعاد', () => {
    const dialog = (result: Record<string, unknown> = { synced: true, invoice: 'INV-9', points: 0, rejected: false }) =>
        render(
            <PaymentDialog
                open
                onOpenChange={() => {}}
                total={12.5}
                displayTotal={12.5}
                customer="عميل نقدي"
                money={(v) => String(v)}
                fmt={(v) => String(v)}
                onCheckout={vi.fn().mockResolvedValue(result) as never}
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

    it('يطبع بعد البيع مباشرةً — ولا يفتح نافذة', async () => {
        const bridge = app();
        till(NETWORK);
        dialog();
        await pay();

        await screen.findByText('تمت طباعة الإيصال');
        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
        expect(bridge.printReceipt.mock.calls[0][0]).toMatchObject({ invoice: 'INV-9' });
        expect(String(bridge.printReceipt.mock.calls[0][0].receiptUrl)).toMatch(/pos\.receipt\.pdf\/INV-9\?expected_business_id=3$/);
        expect(openMock).not.toHaveBeenCalled();
    });

    it('وفشلُ الطابعة لا يمسّ البيع — يُقال، ومعه إعادةٌ يطلبها الكاشير', async () => {
        const bridge = app({
            printReceipt: vi.fn(async () => ({ ok: false, code: 'PRINTER_UNREACHABLE', message: '', connected: false })),
        });
        till(NETWORK);
        dialog();
        const user = await pay();

        const alert = await screen.findByRole('alert');
        expect(screen.getByText('تم الدفع بنجاح')).toBeInTheDocument();
        expect(within(alert).getByText('تم حفظ البيع، لكن تعذرت طباعة الإيصال.')).toBeInTheDocument();
        expect(within(alert).getByText('تعذر الوصول إلى عنوان الطابعة')).toBeInTheDocument();

        // ولا إعادةَ تلقائيّة — ما بلغ الورقَ بعضُه لا يُطبع مرّتين بلا قرار
        await new Promise((r) => setTimeout(r, 20));
        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);

        await user.click(within(alert).getByRole('button', { name: /إعادة الطباعة/ }));
        await waitFor(() => expect(bridge.printReceipt).toHaveBeenCalledTimes(2));
    });

    it('و«فتح PDF» يفتح الملفَّ نفسه بحوار النظام', async () => {
        const bridge = app({
            printReceipt: vi.fn(async () => ({ ok: false, code: 'CONNECT_TIMEOUT', message: '' })),
        });
        till(NETWORK);
        dialog();
        const user = await pay();

        const alert = await screen.findByRole('alert');
        await user.click(within(alert).getByRole('button', { name: /فتح PDF/ }));

        await waitFor(() => expect(bridge.openReceipt).toHaveBeenCalledTimes(1));
        expect(bridge.openReceipt.mock.calls[0][0]).toMatchObject({ invoice: 'INV-9' });
    });

    it('وبيعةٌ حُفظت بلا اتصال لا تُطبع — لا رقمَ فاتورةٍ بعد', async () => {
        const bridge = app();
        till(NETWORK);
        dialog({ synced: false, invoice: null, points: 0, rejected: false });
        await pay();

        await screen.findByText(/حُفظ البيع وسيُرسَل تلقائيًا/);
        expect(bridge.printReceipt).not.toHaveBeenCalled();
        expect(screen.queryByTestId('print-status')).toBeNull();
    });

    it('وطباعةٌ تلقائيّةٌ مطفأة ← لا شيء حتى يضغط «طباعة الفاتورة»', async () => {
        const bridge = app();
        till({ ...NETWORK, autoPrint: false });
        dialog();
        const user = await pay();

        await screen.findByText('تم الدفع بنجاح');
        expect(bridge.printReceipt).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));
        await waitFor(() => expect(bridge.printReceipt).toHaveBeenCalledTimes(1));
        expect(openMock).not.toHaveBeenCalled();
    });
});

/* ═══════════════════════════ زرّ الطباعة ═══════════════════════════ */

describe('زرُّ «طباعة» في الشاشات', () => {
    it('في المتصفّح هو الرابطُ نفسُه الذي كان', () => {
        till(NETWORK);
        render(<PrintReceiptButton number="INV-4" />);

        const link = screen.getByRole('link', { name: /طباعة الفاتورة/ });
        expect(link.getAttribute('href')).toContain('pos.receipt.pdf/INV-4');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('وفي التطبيق زرٌّ يطبع مباشرةً — ونتيجتُه في إشعار', async () => {
        const bridge = app();
        till(NETWORK);
        render(<PrintReceiptButton number="INV-4" routeName="admin.orders.receipt" />);

        expect(screen.queryByRole('link')).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: /طباعة الفاتورة/ }));

        await waitFor(() => expect(toasts.success).toHaveBeenCalledWith('تمت طباعة الإيصال'));
        expect(String(bridge.printReceipt.mock.calls[0][0].receiptUrl)).toContain('admin.orders.receipt/INV-4');
    });

    it('وطلبان قبل أن يُعاد الرسم — إيصالٌ واحد', async () => {
        let finish: (v: unknown) => void = () => {};
        const bridge = app({ printReceipt: vi.fn(() => new Promise((r) => { finish = r; })) });
        till(NETWORK);
        const { result } = renderHook(() => useReceiptPrint());

        await act(async () => {
            void result.current.print('INV-4');
            void result.current.print('INV-4');
        });

        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
        await act(async () => finish({ ok: true }));
    });

    it('وضغطتان سريعتان إيصالٌ واحد', async () => {
        let finish: (v: unknown) => void = () => {};
        const bridge = app({ printReceipt: vi.fn(() => new Promise((r) => { finish = r; })) });
        till(NETWORK);
        render(<PrintReceiptButton number="INV-4" />);

        const button = screen.getByRole('button', { name: /طباعة الفاتورة/ });
        await userEvent.click(button);
        await userEvent.click(button);

        expect(bridge.printReceipt).toHaveBeenCalledTimes(1);
        finish({ ok: true });
    });
});

/* ═══════════════════════════ طلبُ الموقع ═══════════════════════════ */

describe('طلبُ الموقع يُؤكَّد في تطبيق أبعاد', () => {
    const input = {
        peripherals: [{ ...NETWORK, autoPrint: false, autoPrintWebsite: true }],
        receipt: 'admin.orders.receipt',
        channel: 'website',
        from: PENDING,
        to: CONFIRMED,
    };
    const t = (k: string) => k;
    const page = (websiteConfirmed: string | null) => ({ props: { flash: { websiteConfirmed }, auth: { user: { businessId: 3 } } } });

    it('أُكّد الآن ← يطبع مباشرةً بعد الجواب، بلا نافذة', async () => {
        const bridge = app();
        const attempt = prepareWebsiteConfirmPrint(input);

        expect(openMock).not.toHaveBeenCalled();
        const callbacks = websiteConfirmPrintCallbacks(attempt, 'ORD-1', t);
        callbacks.onSuccess(page('ORD-1'));
        callbacks.onFinish();

        await waitFor(() => expect(bridge.printReceipt).toHaveBeenCalledTimes(1));
        expect(String(bridge.printReceipt.mock.calls[0][0].receiptUrl)).toMatch(
            /admin\.orders\.receipt\/ORD-1\?expected_business_id=3$/,
        );
    });

    it('ولم يقل الخادم «أُكّد الآن» ← لا شيء يُطبع', async () => {
        const bridge = app();
        const callbacks = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'ORD-1', t);

        callbacks.onSuccess(page(null));
        callbacks.onFinish();
        await new Promise((r) => setTimeout(r, 10));

        expect(bridge.printReceipt).not.toHaveBeenCalled();
    });

    it('وفشلُ الطابعة يُقال، والطلبُ مؤكَّدٌ على كلّ حال', async () => {
        app({ printReceipt: vi.fn(async () => ({ ok: false, code: 'PORT_REFUSED', message: '' })) });
        const callbacks = websiteConfirmPrintCallbacks(prepareWebsiteConfirmPrint(input), 'ORD-1', t);

        callbacks.onSuccess(page('ORD-1'));

        await waitFor(() => expect(toasts.error).toHaveBeenCalledTimes(1));
        expect(String(toasts.error.mock.calls[0][0])).toContain('المنفذ لا يستجيب');
        expect(toasts.error.mock.calls[0][1].action.label).toBe('إعادة الطباعة');
    });
});

/* ═══════════════════════════ اختبار الطابعة ═══════════════════════════ */

describe('«اختبار الطابعة»', () => {
    const editPrinter = async (over: Record<string, unknown> = {}) => {
        const { default: PeripheralsDialog } = await import('@/Pages/Admin/Devices/partials/PeripheralsDialog');
        render(
            <PeripheralsDialog
                deviceId={3}
                deviceName="الصندوق 1"
                peripherals={[
                    {
                        id: 7, name: 'طابعة الكاشير', type: 'طابعة', connection: 'network', model: null,
                        address: '192.168.1.50', port: null, paperWidth: 80, autoPrint: false, autoPrintWebsite: false,
                        notes: null, active: true, drivable: true, ...over,
                    },
                ] as never}
                types={['طابعة', 'ماسح باركود']}
                drivableTypes={['طابعة', 'ماسح باركود']}
                paperWidths={[58, 80]}
                onClose={() => {}}
            />,
        );
        await userEvent.click(document.querySelector('svg.lucide-pencil')!.closest('button')!);
    };

    it('في المتصفّح لا يُوهم باختبار — يقول إنّه يحتاج التطبيق', async () => {
        await editPrinter();
        await userEvent.click(screen.getByRole('button', { name: /اختبار الطابعة/ }));

        expect(await screen.findByText('الطباعة المباشرة تحتاج تطبيق أبعاد')).toBeInTheDocument();
        expect(screen.getByText(/فلم يُجرَ أي اختبار/)).toBeInTheDocument();
        expect(screen.queryByText('تم الاتصال بالطابعة')).toBeNull();
    });

    it('وفي التطبيق: اتّصالٌ وصفحةٌ تُطبع، والمنفذُ الذي طُلب فعلًا', async () => {
        const bridge = app();
        await editPrinter();
        await userEvent.click(screen.getByRole('button', { name: /اختبار الطابعة/ }));

        const result = await screen.findByTestId('printer-test-result');
        expect(within(result).getByText('تم الاتصال بالطابعة')).toBeInTheDocument();
        expect(within(result).getByText('تمت طباعة صفحة الاختبار')).toBeInTheDocument();
        expect(result.textContent).toContain('9100');
        expect(bridge.testPrinter.mock.calls[0][0]).toEqual({
            printer: {
                id: 7, name: 'طابعة الكاشير', model: null, connection: 'network',
                address: '192.168.1.50', port: null, paperWidth: 80,
            },
            deviceName: 'الصندوق 1',
            printerName: 'طابعة الكاشير',
        });
    });

    it('وردّت الطابعةُ ولم تطبع ← يُقال الاثنان منفصلين', async () => {
        app({
            testPrinter: vi.fn(async () => ({
                ok: false, code: 'SEND_FAILED', message: '', connected: true, mayHavePrinted: true, effectivePort: 9101,
            })),
        });
        await editPrinter({ port: 9101 });
        await userEvent.click(screen.getByRole('button', { name: /اختبار الطابعة/ }));

        const result = await screen.findByTestId('printer-test-result');
        expect(within(result).getByText('تم الاتصال بالطابعة')).toBeInTheDocument();
        expect(within(result).getByText(/انقطع الاتصال أثناء الإرسال/)).toBeInTheDocument();
        expect(result.textContent).toContain('9101');
    });

    it('ومنفذٌ لا يردّ ← لا «تم الاتصال»', async () => {
        app({ testPrinter: vi.fn(async () => ({ ok: false, code: 'PORT_REFUSED', message: '', effectivePort: 9100 })) });
        await editPrinter();
        await userEvent.click(screen.getByRole('button', { name: /اختبار الطابعة/ }));

        const result = await screen.findByTestId('printer-test-result');
        expect(within(result).getByText('المنفذ لا يستجيب')).toBeInTheDocument();
        expect(within(result).queryByText('تم الاتصال بالطابعة')).toBeNull();
    });

    it('ومنفذٌ مكتوبٌ خطأً لا يُستبدل بالافتراضيّ صامتًا', async () => {
        const bridge = app();
        await editPrinter();
        const port = screen.getByPlaceholderText('9100');
        await userEvent.clear(port);
        await userEvent.type(port, 'abc');
        await userEvent.click(screen.getByRole('button', { name: /اختبار الطابعة/ }));

        expect(await screen.findByText('رقم المنفذ غير صالح')).toBeInTheDocument();
        expect(bridge.testPrinter).not.toHaveBeenCalled();
    });

    it('والخدمةُ نفسُها: طابعةٌ ليست على الشبكة لا تُختبر عبر TCP', async () => {
        const bridge = app();

        expect(await testPrinter({ ...NETWORK, connection: 'usb' }, 'الصندوق 1')).toMatchObject({
            ok: false,
            code: 'UNSUPPORTED_CONNECTION',
        });
        expect(bridge.testPrinter).not.toHaveBeenCalled();
    });
});

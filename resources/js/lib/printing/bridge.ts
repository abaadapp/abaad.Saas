import type { AbaadPrinterNative, BridgeResult, PrintErrorCode } from './types';

/**
 * جسرُ تطبيق أبعاد — يُوجد أو لا يُوجد، ولا شيءَ بينهما.
 *
 * في المتصفّح العاديّ لا `window.AbaadPrinter` أصلًا، فتبقى الطباعةُ كما كانت.
 * وفي التطبيق يحقنه الطرفُ الأصليّ عند بدء المستند — على الأصول الموثوقة
 * وحدها. وما يأتي منه يُطبَّع هنا قبل أن تراه الشاشة: ردٌّ ناقصٌ أو استثناءٌ
 * لا يُقرأ «طُبع».
 */

const VERSION = 1;

const CODES: readonly PrintErrorCode[] = [
    'UNTRUSTED_ORIGIN',
    'INVALID_JOB',
    'RECEIPT_URL_REJECTED',
    'UNSUPPORTED_CONNECTION',
    'INVALID_PRINTER_ADDRESS',
    'INVALID_PORT',
    'SESSION_EXPIRED',
    'RECEIPT_DOWNLOAD_FAILED',
    'RECEIPT_RENDER_FAILED',
    'PRINTER_UNREACHABLE',
    'PORT_REFUSED',
    'CONNECT_TIMEOUT',
    'SEND_FAILED',
    'QUEUE_FULL',
    'INTERNAL',
    'BRIDGE_TIMEOUT',
    'BRIDGE_UNAVAILABLE',
    'NATIVE_REQUIRED',
    'POPUP_BLOCKED',
];

/** الجسرُ إن كان صالحًا ومتاحًا لهذه الصفحة — وإلّا `null` */
export function nativeBridge(): AbaadPrinterNative | null {
    if (typeof window === 'undefined') return null;

    const bridge = window.AbaadPrinter;
    if (
        !bridge ||
        bridge.version !== VERSION ||
        typeof bridge.isAvailable !== 'function' ||
        typeof bridge.printReceipt !== 'function' ||
        typeof bridge.testPrinter !== 'function' ||
        typeof bridge.openReceipt !== 'function'
    ) {
        return null;
    }

    try {
        return bridge.isAvailable() === true ? bridge : null;
    } catch {
        return null;
    }
}

export function failure(code: PrintErrorCode, extra: Partial<Extract<BridgeResult, { ok: false }>> = {}): BridgeResult {
    return { ok: false, code, message: '', ...extra };
}

/** ما يردّه الجسرُ مُطبَّعًا — والنجاحُ لا يُقال إلّا صريحًا */
export function normalizeResult(raw: unknown): BridgeResult {
    if (!raw || typeof raw !== 'object') return failure('INTERNAL');

    const r = raw as Record<string, unknown>;
    const port = typeof r.effectivePort === 'number' ? r.effectivePort : undefined;
    const jobId = typeof r.jobId === 'string' ? r.jobId : undefined;

    if (r.ok === true) {
        return {
            ok: true,
            jobId,
            printed: r.printed === true,
            connected: r.connected === true,
            effectivePort: port,
            message: typeof r.message === 'string' ? r.message : undefined,
        };
    }

    const code = CODES.includes(r.code as PrintErrorCode) ? (r.code as PrintErrorCode) : 'INTERNAL';

    return failure(code, {
        message: typeof r.message === 'string' ? r.message : '',
        jobId,
        connected: r.connected === true,
        mayHavePrinted: r.mayHavePrinted === true,
        effectivePort: port,
    });
}

/** نداءٌ للجسر لا يرمي — كلُّ فشلٍ يعود نتيجةً تُعرض */
export async function callBridge(run: () => Promise<unknown>): Promise<BridgeResult> {
    try {
        const result = normalizeResult(await run());
        if (!result.ok) {
            // للتشخيص وحده: الرمزُ والمنفذ — لا رابطَ ولا محتوى إيصال
            console.warn('[AbaadPrinter]', result.code, result.effectivePort ?? '');
        }

        return result;
    } catch {
        console.warn('[AbaadPrinter]', 'INTERNAL');

        return failure('INTERNAL');
    }
}

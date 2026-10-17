import type { PosPeripheral } from '@/types';

/**
 * أنواعُ طبقة الطباعة — وما يمرّ عبر جسر التطبيق كما في `mobile/PROTOCOL.md`.
 *
 * والجسرُ يقبل طلبًا مُهيكلًا وحده: رقمَ فاتورة، ورابطَ إيصالها الحراريّ،
 * وإعدادَ الطابعة. لا بايتاتِ طابعة ولا مقبسًا مفتوحًا لصفحة ويب.
 */

/** ما تراه الشاشة من مراحل — ومنها ما يقوله الطرفُ الأصليّ أثناء العمل */
export type PrintStage =
    | 'idle'
    | 'connecting'
    | 'downloading_receipt'
    | 'rendering'
    | 'sending'
    | 'success'
    | 'failed';

/** رموزُ الفشل — جدولُ PROTOCOL §8، وما يضيفه الويب في آخره */
export type PrintErrorCode =
    | 'UNTRUSTED_ORIGIN'
    | 'INVALID_JOB'
    | 'RECEIPT_URL_REJECTED'
    | 'UNSUPPORTED_CONNECTION'
    | 'INVALID_PRINTER_ADDRESS'
    | 'INVALID_PORT'
    | 'SESSION_EXPIRED'
    | 'RECEIPT_DOWNLOAD_FAILED'
    | 'RECEIPT_RENDER_FAILED'
    | 'PRINTER_UNREACHABLE'
    | 'PORT_REFUSED'
    | 'CONNECT_TIMEOUT'
    | 'SEND_FAILED'
    | 'QUEUE_FULL'
    | 'INTERNAL'
    | 'BRIDGE_TIMEOUT'
    | 'BRIDGE_UNAVAILABLE'
    // من جهة الويب: لا تطبيقَ على هذا الجهاز، أو منع المتصفّحُ النافذة
    | 'NATIVE_REQUIRED'
    | 'POPUP_BLOCKED';

/** طابعةُ الشبكة كما تُرسل إلى التطبيق — والتطبيقُ يتحقّق منها كلِّها من جديد */
export interface PrinterTarget {
    id: number | null;
    name: string;
    model: string | null;
    connection: 'network';
    address: string;
    port: number | null;
    paperWidth: number | null;
}

export interface ReceiptJob {
    invoice: string;
    /** رابطٌ مطلقٌ على أصل الصفحة نفسِه — والتطبيقُ يرفض غيرَه */
    receiptUrl: string;
    printer: PrinterTarget;
}

export interface OpenJob {
    invoice: string;
    receiptUrl: string;
}

export interface TestJob {
    printer: PrinterTarget;
    deviceName?: string | null;
    printerName?: string;
}

export type BridgeResult =
    | {
          ok: true;
          jobId?: string;
          printed?: boolean;
          connected?: boolean;
          effectivePort?: number;
          message?: string;
      }
    | {
          ok: false;
          code: PrintErrorCode;
          message: string;
          jobId?: string;
          connected?: boolean;
          /** قد يكون بعضُ الإيصال بلغ الطابعة — فلا يُعاد إلّا بقرار الكاشير */
          mayHavePrinted?: boolean;
          effectivePort?: number;
      };

/**
 * طريقُ الطباعة:
 *  - `native` مباشرةً من التطبيق إلى طابعة الشبكة.
 *  - `system` حوارُ الطباعة في نظام الجهاز (التطبيقُ وطابعةٌ ليست على الشبكة).
 *  - `browser` لسانُ الـPDF في المتصفّح — كما كان.
 */
export type PrintVia = 'native' | 'system' | 'browser';

export type PrintOutcome = BridgeResult & { via: PrintVia };

/** `window.AbaadPrinter` — يحقنه التطبيقُ وحده (`mobile/shared/js/abaad-printer-shim.js`) */
export interface AbaadPrinterNative {
    version: number;
    platform: string;
    isAvailable(): boolean;
    printReceipt(job: ReceiptJob, onProgress?: (stage: string) => void): Promise<unknown>;
    testPrinter(job: TestJob, onProgress?: (stage: string) => void): Promise<unknown>;
    openReceipt(job: OpenJob): Promise<unknown>;
}

/** الطابعةُ كما تُقرأ من سياق الصندوق — وقد تصل ناقصةً من صفحةٍ قديمة */
export type PrinterLike = Partial<PosPeripheral> & { type?: string };

declare global {
    interface Window {
        AbaadPrinter?: AbaadPrinterNative;
    }
}

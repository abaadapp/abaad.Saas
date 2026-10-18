import { callBridge, failure, nativeBridge } from './bridge';
import { inPagePrintSupported, printPdfInPage } from './page-print';
import { preparedReceipt, sharePrintSupported, shareReceipt } from './share-print';
import type { PrintErrorCode, PrinterLike, PrinterTarget, PrintOutcome, PrintStage } from './types';

/**
 * طبقةُ الطباعة في أبعاد — البابُ الواحد الذي تمرّ منه كلُّ «طباعة».
 *
 * ═══ الطرقُ الثلاثة ═══
 *
 *   - **تطبيقُ أبعاد + طابعةُ شبكة:** يطبع التطبيقُ مباشرةً عبر TCP. يُنزّل
 *     الإيصالَ الحراريّ نفسَه بجلسة الصفحة، ويرسمه نقاطًا، ويرسله بأوامر
 *     ESC/POS. فلا يعتمد على صفحة رموزٍ عربيّة في الطابعة.
 *   - **تطبيقُ أبعاد + طابعةٌ ليست على الشبكة:** حوارُ الطباعة في نظام
 *     الجهاز. فالنوافذُ المنبثقة لا تُفتح داخل التطبيق.
 *   - **المتصفّح:** لسانُ الـPDF كما كان تمامًا — إلّا حيث تطلب الشاشةُ
 *     الطباعةَ داخل الصفحة (`inPage`، نقطةُ البيع)، والكاشيرُ في شاشته:
 *       - Chrome وEdge على الحاسوب: حوارُ الطباعة على الـPDF نفسِه في إطارٍ
 *         مخفيّ (`page-print`).
 *       - الآيفون والآيباد: قائمةُ المشاركة بالـPDF نفسِه، وفيها «طباعة»
 *         (`share-print`).
 *       - وما سواهما: يُقال إنّه لا يُطبع من الصفحة، ومعه «فتح PDF».
 *
 * والإيصالُ واحدٌ في الثلاثة: `PdfController::orderThermal`. لا يُعاد حسابُ
 * فاتورةٍ ولا تنسيقُها في أيّ تطبيق.
 *
 * ═══ ولا اسمَ طابعةٍ هنا ═══
 *
 * لا طرازَ ولا شركةَ ولا عنوانَ مكتوبًا. ما يُعرف عن الطابعة يأتي من إعداد
 * الصندوق (`context.peripherals`)، وما يُعرف عن الأوامر في التطبيق وحده.
 */

export const PRINTER = 'طابعة';

/** المنفذُ الذي يطلبه التطبيقُ حين لا يُضبط منفذ — الافتراضيُّ الموثَّق للطباعة الخام، لا قاعدةٌ لكلّ طابعة */
export const DEFAULT_RAW_PORT = 9100;

/** طابعةُ هذا الصندوق — الأولى بين ملحقاته النشطة، كما كانت تُقرأ */
export function receiptPrinter(peripherals?: PrinterLike[] | null): PrinterLike | null {
    return peripherals?.find((p) => p.type === PRINTER) ?? null;
}

/** طابعةُ شبكةٍ لها عنوان — ما يقدر التطبيقُ أن يطبع عليه مباشرةً */
export function isNetworkPrinter(printer?: PrinterLike | null): boolean {
    return printer?.connection === 'network' && !!printer.address?.trim();
}

/** هل يعمل هذا الجهازُ داخل تطبيق أبعاد؟ */
export function insideAbaadApp(): boolean {
    return nativeBridge() !== null;
}

/** طباعةٌ مباشرةٌ ممكنةٌ على هذه الطابعة من هذا الجهاز */
export function nativePrintingAvailable(printer?: PrinterLike | null): boolean {
    return insideAbaadApp() && isNetworkPrinter(printer);
}

/** المنفذُ الذي سيُطلب فعلًا — ويُعرض كما هو، لا يُفترض */
export function effectivePort(printer?: PrinterLike | null): number {
    return printer?.port && printer.port > 0 ? printer.port : DEFAULT_RAW_PORT;
}

/** رابطٌ مطلقٌ على أصل الصفحة — والتطبيقُ يرفض ما سواه */
export function absoluteUrl(url: string): string {
    return new URL(url, window.location.href).href;
}

function target(printer: PrinterLike): PrinterTarget {
    return {
        id: typeof printer.id === 'number' ? printer.id : null,
        name: printer.name ?? '',
        model: printer.model ?? null,
        connection: 'network',
        address: (printer.address ?? '').trim(),
        port: printer.port && printer.port > 0 ? printer.port : null,
        paperWidth: printer.paperWidth ?? null,
    };
}

const STAGES: readonly PrintStage[] = ['connecting', 'downloading_receipt', 'rendering', 'sending'];

function relay(onProgress?: (stage: PrintStage) => void) {
    return (stage: string) => {
        if (onProgress && STAGES.includes(stage as PrintStage)) onProgress(stage as PrintStage);
    };
}

export interface ReceiptRequest {
    invoice: string;
    /** بابُ الإيصال الحراريّ مُحمَّلًا بنشاط الصفحة — `tenant(route(…))` */
    url: string;
    printer: PrinterLike | null;
    onProgress?: (stage: PrintStage) => void;
    /**
     * في المتصفّح: حوارُ الطباعة داخل الصفحة بدل لسانٍ جديد — لنقطة البيع.
     * ولا أثرَ له في تطبيق أبعاد: الجسرُ يُسأل أوّلًا كما كان.
     */
    inPage?: boolean;
}

/**
 * «طباعة» بضغطة.
 *
 * وفي المتصفّح تُفتح النافذةُ قبل أيّ انتظار: الدالّةُ لا تنتظر شيئًا قبل
 * `window.open`، فتبقى في إيماءة الكاشير ولا يبتلعها مانعُ النوافذ.
 */
export async function printReceipt(request: ReceiptRequest): Promise<PrintOutcome> {
    const bridge = nativeBridge();

    if (bridge && isNetworkPrinter(request.printer)) {
        const receiptUrl = absoluteUrl(request.url);
        const result = await callBridge(() =>
            bridge.printReceipt(
                { invoice: request.invoice, receiptUrl, printer: target(request.printer!) },
                relay(request.onProgress),
            ),
        );

        return { ...result, via: 'native' };
    }

    if (bridge) return openReceiptFile(request);

    // ونقطةُ البيع تطبع في مكانها — وحيث لا يُضمن ذلك يُقال، ولا لسانَ يُفتح وحده
    if (request.inPage) return printInPage(request);

    // والمتصفّحُ كما كان: لسانٌ جديد، و`noopener` كما كان
    window.open(request.url, '_blank', 'noopener');

    return { ok: true, via: 'browser' };
}

/**
 * طريقُ الطباعة في الصفحة على هذا الجهاز — يُسأل خارج التطبيق وحده.
 *
 *   - `frame` حوارُ الطباعة على إطارٍ مخفيّ (Chrome · Edge على الحاسوب).
 *   - `share` قائمةُ المشاركة (الآيفون والآيباد بملفّات).
 *   - `none` لا هذا ولا ذاك — «فتح PDF».
 */
export type PageMode = 'frame' | 'share' | 'none';

export function pagePrintMode(): PageMode {
    if (inPagePrintSupported()) return 'frame';
    if (sharePrintSupported()) return 'share';

    return 'none';
}

/**
 * الطباعةُ في الصفحة — والمشاركةُ تُفتح في الضغطة نفسِها.
 *
 * لا `async` هنا ولا `await` قبل `shareReceipt`: الضغطةُ تصل إلى
 * `navigator.share` متزامنةً. والملفُّ لم يُجهَّز بعد ← `SHARE_FAILED` لا تنزيلٌ
 * هنا: التجهيزُ قبل الضغطة (`useReceiptPrint`).
 */
function printInPage(request: ReceiptRequest): Promise<PrintOutcome> {
    switch (pagePrintMode()) {
        case 'frame':
            return printPdfInPage(request.url);
        case 'share': {
            const file = preparedReceipt(request.url);

            return file ? shareReceipt(file, request.invoice) : Promise.resolve({ ...failure('SHARE_FAILED'), via: 'share' });
        }
        default:
            return Promise.resolve({ ...failure('IN_PAGE_UNSUPPORTED'), via: 'page' });
    }
}

/** «فتح PDF» — الملفُّ نفسُه، بحوار النظام في التطبيق وبلسانٍ في المتصفّح */
export async function openReceiptFile(request: Pick<ReceiptRequest, 'invoice' | 'url'>): Promise<PrintOutcome> {
    const bridge = nativeBridge();

    if (bridge) {
        const receiptUrl = absoluteUrl(request.url);

        return { ...(await callBridge(() => bridge.openReceipt({ invoice: request.invoice, receiptUrl }))), via: 'system' };
    }

    window.open(request.url, '_blank', 'noopener');

    return { ok: true, via: 'browser' };
}

/* ═══════════════════════════ الطباعة التلقائية ═══════════════════════════ */

/**
 * محاولةُ طباعةٍ تلقائيّة على مرحلتين — تُحضَّر في الضغطة، وتُنفَّذ بعد الجواب.
 *
 * المتصفّحاتُ لا تفتح نافذةً إلّا من إيماءةٍ حيّة، فالنافذةُ الفارغة تُفتح في
 * `prepareAutoPrint` قبل انتظار الخادم، وتُوجَّه في `commitAutoPrint` حين
 * يُعرف رقمُ الفاتورة. وفي التطبيق لا نافذة: يُرسل الإيصالُ بعد الجواب.
 */
export interface AutoPrintAttempt {
    mode: 'none' | 'native' | 'system' | 'browser';
    printer: PrinterLike | null;
    /** النافذةُ الفارغة في المتصفّح — `null` حين منعها أو حين لا حاجة إليها */
    sheet: Window | null;
    settled: boolean;
}

/** يُنادى في معالج الضغطة نفسِه — قبل أيّ `await` */
export function prepareAutoPrint(printer: PrinterLike | null | undefined, enabled = !!printer?.autoPrint): AutoPrintAttempt {
    const base = { printer: printer ?? null, sheet: null, settled: false };

    if (!printer || !enabled) return { ...base, mode: 'none' };
    if (nativePrintingAvailable(printer)) return { ...base, mode: 'native' };
    if (insideAbaadApp()) return { ...base, mode: 'system' };

    /*
     * وبلا `noopener` عن ضرورة: المواصفةُ تجعل `open` تردّ `null` معه، فلا
     * يبقى مقبضٌ تُوجَّه به النافذة بعد الانتظار. والوجهةُ مسارُنا على أصلنا.
     */
    return { ...base, mode: 'browser', sheet: window.open('', '_blank') };
}

/**
 * تُنفَّذ المحاولةُ مرّةً واحدة — بعد أن يردّ الخادم.
 *
 * و`invoice` الفارغ يعني بيعةً حُفظت بلا اتصال: لا رقمَ فاتورةٍ رسميّ بعد،
 * فلا إيصالَ يُطبع ولا رقمَ يُخترع. تُغلق النافذةُ الفارغة ويعود `null`.
 *
 * و`settled` تُضبط قبل أيّ انتظار، فـ`abandonAutoPrint` في `finally` لا تُغلق
 * نافذةً وُجّهت للتوّ.
 */
export async function commitAutoPrint(
    attempt: AutoPrintAttempt,
    invoice: string | null | undefined,
    url: () => string,
    onProgress?: (stage: PrintStage) => void,
): Promise<PrintOutcome | null> {
    if (attempt.settled) return null;
    attempt.settled = true;

    if (attempt.mode === 'none') return null;

    if (!invoice) {
        attempt.sheet?.close();

        return null;
    }

    if (attempt.mode === 'browser') {
        if (!attempt.sheet) return { ...failure('POPUP_BLOCKED'), via: 'browser' };

        // و`replace`: الصفحةُ الفارغة لا تدخل تاريخَ اللسان
        attempt.sheet.location.replace(url());

        return { ok: true, via: 'browser' };
    }

    return printReceipt({ invoice, url: url(), printer: attempt.printer, onProgress });
}

/** ما حُضّر ولم يُنفَّذ (خطأٌ في البيع مثلًا) يُغلق — ولا يُترك لسانٌ أبيض */
export function abandonAutoPrint(attempt: AutoPrintAttempt | null | undefined): void {
    if (!attempt || attempt.settled) return;
    attempt.settled = true;
    attempt.sheet?.close();
}

/* ═══════════════════════════ اختبار الطابعة ═══════════════════════════ */

/**
 * «اختبار الطابعة» — اتّصالٌ حقيقيّ وصفحةٌ تُطبع، أو قولٌ صريحٌ إنّه لم يقع.
 *
 * المتصفّحُ لا يبلغ طابعةً على الشبكة المحليّة، فلا يُوهم باختبارٍ لم يُجرَ:
 * يعود `NATIVE_REQUIRED` وتقول الشاشةُ إنّ الاختبار يحتاج التطبيق.
 */
export async function testPrinter(
    printer: PrinterLike,
    deviceName: string | null | undefined,
    onProgress?: (stage: PrintStage) => void,
): Promise<PrintOutcome> {
    const bridge = nativeBridge();
    if (!bridge) return { ...failure('NATIVE_REQUIRED'), via: 'browser' };
    if (!isNetworkPrinter(printer)) {
        return { ...failure('UNSUPPORTED_CONNECTION', { effectivePort: effectivePort(printer) }), via: 'native' };
    }

    const result = await callBridge(() =>
        bridge.testPrinter(
            { printer: target(printer), deviceName: deviceName ?? null, printerName: printer.name ?? '' },
            relay(onProgress),
        ),
    );

    return { ...result, via: 'native' };
}

/* ═══════════════════════════ ما يُقال للكاشير ═══════════════════════════ */

type Translate = (key: string) => string;

/** سببُ الفشل بجملةٍ بسيطة — بلا رمزٍ ولا تفصيلٍ تقنيّ */
export function failureMessage(code: PrintErrorCode, t: Translate): string {
    switch (code) {
        case 'PRINTER_UNREACHABLE':
            return t('تعذر الوصول إلى عنوان الطابعة');
        case 'PORT_REFUSED':
            return t('المنفذ لا يستجيب');
        case 'CONNECT_TIMEOUT':
            return t('انتهت مهلة الاتصال');
        case 'SEND_FAILED':
        case 'BRIDGE_TIMEOUT':
            return t('انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة');
        case 'SESSION_EXPIRED':
            return t('انتهت الجلسة — سجّل الدخول من جديد');
        case 'RECEIPT_DOWNLOAD_FAILED':
            return t('تعذر تنزيل الإيصال');
        case 'RECEIPT_RENDER_FAILED':
            return t('تعذر تجهيز الإيصال للطباعة');
        case 'INVALID_PRINTER_ADDRESS':
            return t('عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية');
        case 'INVALID_PORT':
            return t('رقم المنفذ غير صالح');
        case 'UNSUPPORTED_CONNECTION':
            return t('الطباعة المباشرة تدعم طابعات الشبكة فقط حاليًا');
        case 'QUEUE_FULL':
            return t('الطابعة مشغولة — حاول بعد لحظات');
        case 'NATIVE_REQUIRED':
            return t('الطباعة المباشرة تحتاج تطبيق أبعاد');
        case 'POPUP_BLOCKED':
            return t('منع المتصفح فتح نافذة الطباعة — اطبع الفاتورة من الزر أدناه.');
        case 'IN_PAGE_UNSUPPORTED':
            return t('هذا المتصفح لا يطبع الفاتورة من داخل الصفحة — افتح PDF واطبعها منه.');
        case 'PRINT_DIALOG_FAILED':
            return t('لم يُفتح حوار الطباعة — أعد المحاولة أو افتح PDF.');
        case 'SHARE_BLOCKED':
            return t('لم تُفتح قائمة المشاركة — اضغط «طباعة أو مشاركة PDF» مرّة أخرى.');
        case 'SHARE_FAILED':
            return t('تعذّرت مشاركة الإيصال — افتح PDF واطبعه منه.');
        case 'SHARE_CANCELLED':
            return t('أُغلقت قائمة المشاركة.');
        case 'UNTRUSTED_ORIGIN':
        case 'INVALID_JOB':
        case 'RECEIPT_URL_REJECTED':
            return t('طلب طباعة مرفوض');
        default:
            return t('تعذرت الطباعة');
    }
}

/** ما يجري الآن — سطرٌ واحد */
export function stageMessage(stage: PrintStage, t: Translate): string {
    switch (stage) {
        case 'downloading_receipt':
        case 'rendering':
            return t('جارٍ تجهيز الإيصال…');
        case 'connecting':
            return t('جارٍ الاتصال بالطابعة…');
        case 'sending':
            return t('جارٍ الطباعة…');
        case 'success':
            return t('تمت طباعة الإيصال');
        case 'ready':
            return t('الإيصال جاهز — اختر «طباعة» من قائمة المشاركة.');
        default:
            return '';
    }
}

/**
 * أتُجدي «إعادة الطباعة»؟ — متصفّحٌ لا يطبع من الصفحة لن يطبع في الثانية،
 * فيُعرض له «فتح PDF» وحده.
 */
export function retryable(outcome: PrintOutcome | null | undefined): boolean {
    return !(outcome && !outcome.ok && outcome.code === 'IN_PAGE_UNSUPPORTED');
}

/**
 * أيُسكت عنه؟ — لا «طُبع» عن حوارٍ فُتح أو قائمةٍ اكتملت، ولا خطأَ عن قائمةٍ
 * أغلقها الكاشيرُ بنفسه.
 */
export function quietOutcome(outcome: PrintOutcome | null | undefined): boolean {
    if (!outcome || outcome.via === 'browser') return true;
    if (outcome.ok) return outcome.via === 'page' || outcome.via === 'share';

    return outcome.code === 'SHARE_CANCELLED';
}

/** الأولى أن يُعاد؟ — ما بلغ الطابعةَ بعضُه لا يُعاد بلا قرار، ولا يُعاد تلقائيًّا أصلًا */
export function mayHavePrinted(outcome: PrintOutcome | null | undefined): boolean {
    return !!outcome && !outcome.ok && outcome.mayHavePrinted === true;
}

import { failure } from './bridge';
import type { PrintOutcome } from './types';

/**
 * طباعةُ الإيصال من داخل الصفحة — حوارُ طباعة المتصفّح، والكاشيرُ في شاشته.
 *
 * ═══ العطبُ الذي يعالجه ═══
 *
 * «طباعة الفاتورة» في المتصفّح كانت تفتح الـPDF في لسانٍ جديد. وعلى الآيباد
 * والهاتف يملأ قارئُ الـPDF الشاشةَ ولا شريطَ ألسنةٍ يُرى، فتختفي شاشةُ البيع
 * والزبونُ واقف.
 *
 * ═══ والورقةُ هي الـPDF الرسميّ نفسُه ═══
 *
 * يُنزَّل الإيصالُ الحراريّ من بابه (`pos.receipt.pdf`) بجلسة الصفحة، ويوضع
 * في إطارٍ مخفيّ، ويُطلب حوارُ الطباعة على الإطار. فما يُطبع هو ما كان يُطبع
 * من اللسان حرفًا بحرف — بطوله وعرضه (#125) — لا ورقةٌ تُعاد كتابتُها HTML.
 *
 * ═══ وحيث يُعرف أنّه يعمل وحده ═══
 *
 * طباعةُ PDF من إطارٍ ليست في المواصفة: هي ما يفعله قارئُ PDF المدمج في
 * Chrome وEdge على الحاسوب. وSafari وFirefox وأندرويد وiOS لا يُضمن فيها
 * شيء — قد يُطبع إطارٌ فارغ أو الصفحةُ التي تحته، بلا خطأٍ يُقرأ. فلا تُجرَّب
 * فيها. والآيباد والآيفون لهما طريقٌ آخر: قائمةُ المشاركة (`share-print`).
 *
 * ═══ وفتحُ الحوار ليس طباعة ═══
 *
 * المتصفّح لا يقول أطَبع الكاشيرُ أم ألغى. فالنجاحُ هنا `via: 'page'` —
 * «طُلب الحوار» — ولا تقول الشاشةُ «تمت الطباعة» عنه أبدًا.
 */

/** المهلةُ حتى يُحمَّل الإطار — بعدها يُقال إنّ الحوار لم يُفتح */
export const FRAME_TIMEOUT_MS = 20000;

/**
 * مهلةُ القارئ بعد `load`.
 *
 * `load` يقول إنّ مستندَ الإطار حُمِّل، لا إنّ قارئَ الـPDF فيه رسم صفحاته:
 * قارئُ Chrome يعمل في عمليّةٍ أخرى ويستلم الملفَّ بعدها. وطلبُ الحوار قبل أن
 * يستعدّ قد يُطبع منه فارغًا. فيُمهَل قليلًا — ولا علامةَ «جاهز» يُنتظر عليها.
 */
export const VIEWER_SETTLE_MS = 600;

/**
 * مهلةُ تنزيل الإيصال.
 *
 * شبكةٌ تعلق لا تردّ بخطأ: كان الزرُّ يبقى على «جارٍ تجهيز الإيصال…» والطباعاتُ
 * كلُّها في الصفحة تُردّ بعده (طباعةٌ واحدةٌ في كلّ مرّة). فيُقطع ويُقال.
 */
export const DOWNLOAD_TIMEOUT_MS = 30000;

const timing = { timeoutMs: FRAME_TIMEOUT_MS, settleMs: VIEWER_SETTLE_MS, downloadMs: DOWNLOAD_TIMEOUT_MS };

/** للاختبار وحده — مُهلٌ تُقاس بالمللي لا بالثواني */
export function setPagePrintTiming(next: Partial<typeof timing>): void {
    Object.assign(timing, next);
}

interface Environment {
    userAgent: string;
    pdfViewerEnabled?: boolean;
    maxTouchPoints?: number;
}

export function environment(): Environment {
    if (typeof navigator === 'undefined') return { userAgent: '' };

    return {
        userAgent: navigator.userAgent ?? '',
        pdfViewerEnabled: (navigator as Navigator & { pdfViewerEnabled?: boolean }).pdfViewerEnabled,
        maxTouchPoints: navigator.maxTouchPoints,
    };
}

/** آيفون أو آيباد — والآيباد في «وضع الحاسوب» يقول إنّه ماك، فيُعرف بشاشة اللمس */
export function appleMobile(env: Environment = environment()): boolean {
    if (/iPhone|iPad|iPod/i.test(env.userAgent)) return true;

    return /Macintosh/.test(env.userAgent) && (env.maxTouchPoints ?? 0) > 1;
}

/**
 * أيطبع هذا المتصفّحُ PDF من إطارٍ في الصفحة؟
 *
 * Chromium على الحاسوب (Chrome · Edge) وقارئُه المدمج مفعَّل — وحده. والهاتفُ
 * والآيباد خارجه أيًّا كان المتصفّح: Chrome على iOS هو WebKit، وعلى أندرويد
 * لا قارئَ PDF في الصفحة.
 */
export function inPagePrintSupported(env: Environment = environment()): boolean {
    const ua = env.userAgent;

    if (/Android|Mobile|CriOS|FxiOS|EdgiOS/i.test(ua) || appleMobile(env)) return false;
    if (!/Chrome\/|Chromium\/|Edg\//.test(ua)) return false;

    return env.pdfViewerEnabled !== false;
}

/* ═══════════════════════════ التنزيل ═══════════════════════════ */

/**
 * الإيصالُ الرسميّ ملفًّا — أو سببٌ يُقال.
 *
 * والجوابُ يُقرأ قبل أن يُستعمل: صفحةُ خطأٍ أو صفحةُ الدخول تُطبع كما هي ولا
 * تقول شيئًا. فـPDF أو لا — ولا شيءَ بينهما.
 */
export async function downloadReceipt(url: string, via: PrintOutcome['via']): Promise<Blob | PrintOutcome> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timing.downloadMs);

    try {
        return await download(url, via, controller.signal);
    } finally {
        clearTimeout(timer);
    }
}

async function download(url: string, via: PrintOutcome['via'], signal: AbortSignal): Promise<Blob | PrintOutcome> {
    let response: Response;
    try {
        response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' }, signal });
    } catch {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via };
    }

    const type = response.headers.get('content-type') ?? '';
    if (response.status === 401 || response.status === 419 || (response.ok && type.includes('text/html'))) {
        // وصفحةُ الدخول تعود 200 بعد التحويل — فالنوعُ يقول ما لا يقوله الرمز
        return { ...failure('SESSION_EXPIRED'), via };
    }
    if (!response.ok || !type.includes('application/pdf')) {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via };
    }

    try {
        return await response.blob();
    } catch {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via };
    }
}

/* ═══════════════════════════ الإطار ═══════════════════════════ */

let frame: HTMLIFrameElement | null = null;
let objectUrl: string | null = null;
let inFlight = false;

/**
 * الإطارُ المخفيّ — واحدٌ للصفحة، يُعاد استعمالُه.
 *
 * بلا `display: none`: إطارٌ لا يُرسم لا يُحمِّل قارئَ الـPDF في Chrome، فيُطبع
 * فارغًا. فيُصغَّر ويُخفى عن العين وعن قارئ الشاشة — كما تفعل المكتباتُ التي
 * تطبع PDF من الصفحة منذ سنين.
 */
function printFrame(): HTMLIFrameElement {
    if (frame && frame.isConnected) return frame;

    frame = document.createElement('iframe');
    frame.setAttribute('data-print-frame', '');
    frame.setAttribute('aria-hidden', 'true');
    frame.setAttribute('tabindex', '-1');
    frame.title = 'print';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(frame);

    return frame;
}

/** الإيصالُ الماضي يُحرَّر حين يأتي غيرُه، أو حين تُغادَر الصفحة — لا قبل: قد يكون حوارُه ما زال مفتوحًا */
function swap(next: string | null): void {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = next;
}

if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => resetPrintFrame());
}

/** ما في الإطار بعد التحميل — `null` حين لا يُقرأ */
function frameType(target: HTMLIFrameElement): string | null {
    try {
        return target.contentDocument?.contentType ?? null;
    } catch {
        return null;
    }
}

/**
 * يُنزّل الإيصالَ ويطلب حوارَ الطباعة عليه — والصفحةُ لا تتحرّك.
 *
 * وطباعةٌ واحدةٌ في الصفحة كلِّها: زرّان في شاشتين يتسابقان على إطارٍ واحد
 * يطبعان إيصالًا لم يُقصد. فالثانية تُردّ ما دامت الأولى تُحمَّل.
 */
export async function printPdfInPage(url: string, timeoutMs = timing.timeoutMs): Promise<PrintOutcome> {
    if (!inPagePrintSupported()) return { ...failure('IN_PAGE_UNSUPPORTED'), via: 'page' };
    if (inFlight) return { ...failure('QUEUE_FULL'), via: 'page' };
    inFlight = true;

    try {
        const pdf = await downloadReceipt(url, 'page');
        if (!(pdf instanceof Blob)) return pdf;

        return await showDialog(pdf, timeoutMs);
    } finally {
        inFlight = false;
    }
}

function showDialog(pdf: Blob, timeoutMs: number): Promise<PrintOutcome> {
    const target = printFrame();
    const src = URL.createObjectURL(pdf);

    return new Promise<PrintOutcome>((resolve) => {
        let done = false;
        const finish = (outcome: PrintOutcome) => {
            if (done) return;
            done = true;
            window.clearTimeout(timer);
            target.onload = null;
            resolve(outcome);
        };

        const timer = window.setTimeout(() => finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' }), timeoutMs);

        target.onload = () => {
            /*
             * وما حُمِّل يُسأل عنه: Chrome بقارئٍ مطفأٍ يُنزل الملفَّ ويترك
             * الإطارَ صفحةً فارغة — وطباعتُها ورقةٌ بيضاء بلا خطأ.
             */
            const type = frameType(target);
            if (type !== null && type !== 'application/pdf') {
                finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' });

                return;
            }

            window.setTimeout(() => {
                if (done) return;
                try {
                    const win = target.contentWindow;
                    if (!win) throw new Error('no window');
                    const before = document.activeElement;
                    try {
                        win.focus();
                    } catch {
                        // التركيزُ تحسينٌ لا شرط — نافذةُ النظام تُفتح بدونه
                    }
                    win.print();
                    giveFocusBack(target, before);
                    finish({ ok: true, via: 'page' });
                } catch {
                    finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' });
                }
            }, timing.settleMs);
        };

        swap(src);
        target.src = src;
    });
}

/**
 * ويعود التركيزُ إلى الصفحة بعد الحوار.
 *
 * تركيزُ الإطار المخفيّ يبقى فيه بعد أن يُغلق الحوار: لوحةُ المفاتيح وماسحُ
 * الباركود يكتبان في إطارٍ لا يُرى، وEscape لا يُغلق المعاينة. فيعود إلى ما
 * كان مركَّزًا قبله — وإن لم يقبله (زرٌّ معطَّلٌ وهو ينتظر) يُترك الإطارُ إلى
 * الصفحة.
 */
function giveFocusBack(target: HTMLIFrameElement, before: Element | null): void {
    try {
        if (before instanceof HTMLElement && before !== document.body && before.isConnected) {
            before.focus({ preventScroll: true });
        }
        if (document.activeElement === target) target.blur();
    } catch {
        // لا يمنع شيءٌ هنا حوارًا فُتح
    }
}

/** للاختبار ولمغادرة الصفحة — إطارٌ جديدٌ وملفٌّ محرَّر */
export function resetPrintFrame(): void {
    swap(null);
    frame?.remove();
    frame = null;
    inFlight = false;
}

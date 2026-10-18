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
 * فيها: يعود `IN_PAGE_UNSUPPORTED`، وتقول الشاشةُ ذلك وتعرض «فتح PDF».
 *
 * ═══ وفتحُ الحوار ليس طباعة ═══
 *
 * المتصفّح لا يقول أطَبع الكاشيرُ أم ألغى. فالنجاحُ هنا `via: 'page'` —
 * «طُلب الحوار» — ولا تقول الشاشةُ «تمت الطباعة» عنه أبدًا.
 */

/** المهلةُ حتى يُحمَّل الإطار — بعدها يُقال إنّ الحوار لم يُفتح */
export const FRAME_TIMEOUT_MS = 20000;

interface Environment {
    userAgent: string;
    pdfViewerEnabled?: boolean;
    maxTouchPoints?: number;
}

function environment(): Environment {
    if (typeof navigator === 'undefined') return { userAgent: '' };

    return {
        userAgent: navigator.userAgent ?? '',
        pdfViewerEnabled: (navigator as Navigator & { pdfViewerEnabled?: boolean }).pdfViewerEnabled,
        maxTouchPoints: navigator.maxTouchPoints,
    };
}

/**
 * أيطبع هذا المتصفّحُ PDF من إطارٍ في الصفحة؟
 *
 * Chromium على الحاسوب (Chrome · Edge) وقارئُه المدمج مفعَّل — وحده. والهاتفُ
 * والآيباد خارجه أيًّا كان المتصفّح: Chrome على iOS هو WebKit، وعلى أندرويد
 * لا قارئَ PDF في الصفحة. والآيباد في «وضع الحاسوب» يقول إنّه ماك — فيُعرف
 * بشاشة اللمس.
 */
export function inPagePrintSupported(env: Environment = environment()): boolean {
    const ua = env.userAgent;

    if (/Android|iPhone|iPad|iPod|Mobile|CriOS|FxiOS|EdgiOS/i.test(ua)) return false;
    if (/Macintosh/.test(ua) && (env.maxTouchPoints ?? 0) > 1) return false;
    if (!/Chrome\/|Chromium\/|Edg\//.test(ua)) return false;

    return env.pdfViewerEnabled !== false;
}

/* ═══════════════════════════ الإطار ═══════════════════════════ */

let frame: HTMLIFrameElement | null = null;
let objectUrl: string | null = null;

/**
 * الإطارُ المخفيّ — واحدٌ للصفحة، يُعاد استعمالُه.
 *
 * بلا `display: none`: إطارٌ لا يُرسم لا يُحمِّل قارئَ الـPDF في Chrome، فيُطبع
 * فارغًا. فيُصغَّر ويُخفى عن العين وعن قارئ الشاشة.
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

/** الإيصالُ الماضي يُحرَّر حين يأتي غيرُه — لا قبل: قد يكون حوارُه ما زال مفتوحًا */
function swap(next: string | null): void {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = next;
}

/**
 * يُنزّل الإيصالَ ويطلب حوارَ الطباعة عليه — والصفحةُ لا تتحرّك.
 *
 * والتنزيلُ يُسأل قبل الإطار: إطارٌ يُحمِّل صفحةَ خطأٍ أو صفحةَ الدخول يطبعها
 * كما هي ولا يقول شيئًا. فالجوابُ يُقرأ أوّلًا — PDF أو لا.
 */
export async function printPdfInPage(url: string, timeoutMs = FRAME_TIMEOUT_MS): Promise<PrintOutcome> {
    if (!inPagePrintSupported()) return { ...failure('IN_PAGE_UNSUPPORTED'), via: 'page' };

    let response: Response;
    try {
        response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
    } catch {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via: 'page' };
    }

    const type = response.headers.get('content-type') ?? '';
    if (response.status === 401 || response.status === 419 || (response.ok && type.includes('text/html'))) {
        // وصفحةُ الدخول تعود 200 بعد التحويل — فالنوعُ يقول ما لا يقوله الرمز
        return { ...failure('SESSION_EXPIRED'), via: 'page' };
    }
    if (!response.ok || !type.includes('application/pdf')) {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via: 'page' };
    }

    let blob: Blob;
    try {
        blob = await response.blob();
    } catch {
        return { ...failure('RECEIPT_DOWNLOAD_FAILED'), via: 'page' };
    }

    const target = printFrame();
    const src = URL.createObjectURL(blob);

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
            try {
                const win = target.contentWindow;
                if (!win) throw new Error('no window');
                try {
                    win.focus();
                } catch {
                    // التركيزُ تحسينٌ لا شرط — نافذةُ النظام تُفتح بدونه
                }
                win.print();
                finish({ ok: true, via: 'page' });
            } catch {
                finish({ ...failure('PRINT_DIALOG_FAILED'), via: 'page' });
            }
        };

        swap(src);
        target.src = src;
    });
}

/** للاختبار وحده — إطارٌ جديدٌ لكلّ حالة */
export function resetPrintFrame(): void {
    swap(null);
    frame?.remove();
    frame = null;
}

import { failure } from './bridge';
import { appleMobile, downloadReceipt, environment } from './page-print';
import type { PrintOutcome } from './types';

/**
 * «طباعة أو مشاركة PDF» — قائمةُ المشاركة في الآيفون والآيباد.
 *
 * ═══ لمَ لا إطار ═══
 *
 * Safari في iOS لا يطبع PDF من إطار: يطبع الصفحةَ التي تحته أو صفحةً
 * فارغة، بلا خطأٍ يُقرأ. و`window.print()` على شاشة البيع يطبع شاشةَ البيع.
 * فلا يُجرَّب شيءٌ منهما.
 *
 * ═══ وما تدعمه Apple ═══
 *
 * مشاركةُ ملف (`navigator.share({ files })`، Safari 15+): تُفتح قائمةُ النظام
 * فوق الصفحة — والصفحةُ تحتها كما هي — وفيها «طباعة» لملفّ PDF، ومعها
 * AirDrop والحفظ في الملفّات. والملفُّ هو الإيصالُ الرسميّ نفسُه (#125).
 * ولا يُدّعى أنّ «طباعة» فيها دائمًا، ولا أنّ فتحَها طباعة.
 *
 * ═══ والضغطةُ لا تنتظر ═══
 *
 * المشاركةُ لا تُفتح إلّا في ضغطةٍ حيّة. وتنزيلُ الملفّ بعد الضغطة يُطفئها:
 * Safari يردّ `NotAllowedError` لمشاركةٍ تأخّرت. فيُجهَّز الملفُّ قبل الضغطة
 * (`prepareReceipt`) ويُشارَك فيها بلا انتظار (`shareReceipt`). وما لم
 * يُجهَّز بعد تُجهّزه الضغطةُ الأولى، ويُشارَك في الثانية.
 *
 * ═══ والمجهَّزُ آخرُ ما نزل ═══
 *
 * الطلبُ يُعدَّل بعد أن يُجهَّز إيصالُه — صنفٌ يُضاف، دفعةٌ تُصحَّح — ورابطُه
 * لا يتبدّل. فلا يُعاد ملفٌّ قديمٌ برابطه: كلُّ تجهيزٍ تنزيلٌ جديد، والقديمُ
 * يُترك حين يبدأ، فلا يُشارَك وهو يُستبدل. وكلُّ شاشةٍ تُجهّز حين تُفتح.
 *
 * ولا تُفتح القائمةُ وحدها أبدًا: لا بعد البيع ولا بعد التجهيز.
 */

/** الإيصالاتُ المجهَّزة — آخرُها وحدها، بمفتاح رابطها */
const PREPARED_LIMIT = 6;
const prepared = new Map<string, File>();
const preparing = new Map<string, Promise<File | PrintOutcome>>();
/** كم مرّةً نُسي رابطٌ — تنزيلٌ بدأ قبل النسيان لا يحفظ ما جاء به */
const forgotten = new Map<string, number>();

function probe(): File | null {
    try {
        return new File([new Blob(['%PDF-1.4'], { type: 'application/pdf' })], 'receipt.pdf', { type: 'application/pdf' });
    } catch {
        return null;
    }
}

/**
 * أتُفتح قائمةُ المشاركة بملفّ PDF هنا؟
 *
 * الآيفون والآيباد وحدهما — قائمةُ النظام فيهما تعرض «طباعة» لملفّ PDF.
 * وقائمةُ أندرويد والماك لا طباعةَ فيها، فلا يُسمّى الزرُّ «طباعة» هناك.
 * والسؤالُ بـ`canShare` على ملفٍّ لا بالوجود وحده: iOS 14 يشارك روابطَ لا ملفّات.
 */
export function sharePrintSupported(env = environment()): boolean {
    if (!appleMobile(env)) return false;
    if (typeof navigator === 'undefined' || typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
        return false;
    }

    const file = probe();
    try {
        return !!file && navigator.canShare({ files: [file] });
    } catch {
        return false;
    }
}

/** الملفُّ المجهَّز لهذا الإيصال — بلا انتظار، ليُشارَك في الضغطة نفسِها */
export function preparedReceipt(url: string): File | null {
    return prepared.get(url) ?? null;
}

/**
 * يُنزّل الإيصالَ ويحفظه ملفًّا — ولا يفتح شيئًا.
 *
 * ودائمًا من الخادم: ما جُهّز قبلُ قد يكون لطلبٍ عُدِّل بعده، فيُترك الآن.
 * وتنزيلان للإيصال نفسِه في وقتٍ واحد تنزيلٌ واحد: الشاشةُ تُجهّزه حين
 * تُفتح، والكاشيرُ يضغط قبل أن ينتهي — فيلحق بالأوّل.
 */
export function prepareReceipt(url: string, invoice: string): Promise<File | PrintOutcome> {
    const pending = preparing.get(url);
    if (pending) return pending;

    prepared.delete(url);
    const generation = forgotten.get(url) ?? 0;

    const job = downloadReceipt(url, 'share')
        .then((pdf): File | PrintOutcome => {
            if (!(pdf instanceof Blob)) return pdf;

            const file = new File([pdf], `${invoice}.pdf`, { type: 'application/pdf' });
            // ونُسي وهو يتنزّل: ما جاء به قد يكون من قبل التعديل — لا يُحفظ
            if ((forgotten.get(url) ?? 0) === generation) prepared.set(url, file);
            while (prepared.size > PREPARED_LIMIT) prepared.delete(prepared.keys().next().value as string);

            return file;
        })
        // وتنزيلٌ نُسي ثمّ بدأ غيرُه للرابط نفسِه لا يمحو الذي بعده
        .finally(() => {
            if (preparing.get(url) === job) preparing.delete(url);
        });

    preparing.set(url, job);

    return job;
}

/**
 * يُنسى ما جُهّز لورقةٍ تغيّرت بياناتُها — فلا تُشارَك نسخةٌ من قبل التعديل.
 *
 * الملفُّ المجهَّز يُشارَك في الضغطة التالية بلا تنزيل (`preparedReceipt`).
 * فشاشةٌ عدّلت موعدَ التسليم أو عنوانَه تنسى ملفّاتِ تلك الورقة هنا، فتنزل
 * الضغطةُ التالية من الخادم ببياناتها الجديدة. والتنزيلُ الجاري لا يُحفظ.
 * وبلا شرطٍ يُنسى كلُّ شيء — كما تبدأ الاختباراتُ نظيفة.
 */
export function forgetPreparedReceipts(match: (url: string) => boolean = () => true): void {
    for (const url of new Set([...prepared.keys(), ...preparing.keys()])) {
        if (!match(url)) continue;
        prepared.delete(url);
        preparing.delete(url);
        forgotten.set(url, (forgotten.get(url) ?? 0) + 1);
    }
}

/**
 * تُفتح قائمةُ المشاركة بالملفّ — في الضغطة نفسِها.
 *
 * و`navigator.share` تُنادى قبل أيّ `await`: ما قبلها هنا متزامنٌ كلُّه، فتبقى
 * في إيماءة الكاشير. وما تردّه:
 *   - اكتملت ← `ok` و`via: 'share'` — لا «طُبع»: قد يكون حفظًا أو AirDrop.
 *   - أغلقها الكاشير ← `SHARE_CANCELLED` — لا خطأَ يُقال.
 *   - ضغطةٌ فاتت ← `SHARE_BLOCKED` — يضغط مرّةً أخرى.
 *   - غيرُ ذلك ← `SHARE_FAILED` — ومعه «فتح PDF».
 */
export function shareReceipt(file: File, invoice: string): Promise<PrintOutcome> {
    let shared: Promise<void>;
    try {
        shared = navigator.share({ files: [file], title: invoice });
    } catch (e) {
        return Promise.resolve(shareFailure(e));
    }

    return shared.then(
        (): PrintOutcome => ({ ok: true, via: 'share' }),
        (e: unknown) => shareFailure(e),
    );
}

function shareFailure(e: unknown): PrintOutcome {
    const name = e instanceof Error || e instanceof DOMException ? e.name : '';
    if (name === 'AbortError') return { ...failure('SHARE_CANCELLED'), via: 'share' };
    if (name === 'NotAllowedError') return { ...failure('SHARE_BLOCKED'), via: 'share' };

    return { ...failure('SHARE_FAILED'), via: 'share' };
}

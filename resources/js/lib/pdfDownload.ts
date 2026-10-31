import { appleMobile, downloadReceipt } from '@/lib/printing/page-print';

/**
 * المستندُ يُحفظ PDF أو لا يُحفظ.
 *
 * ═══ لمَ لا رابطٌ بـ`download` ═══
 *
 * الرابطُ يحفظ أيَّ جوابٍ باسم الملفّ. ويومَ سقط توليدُ الـPDF على الخادم
 * حُفظت صفحةُ الخطأ 500 على iPhone باسم «CINV-000049.pdf» ففُتحت سوداء، ولم
 * يقل شيءٌ للتاجر إنّ الملفّ لم يُصنع. فالجوابُ يُقرأ أوّلًا — بـ`downloadReceipt`
 * نفسِها التي يقرأ بها الصندوقُ إيصالَه: PDF يُحفظ، وغيرُه سببٌ يُقال.
 *
 * ═══ والآيفون يحفظ من الرابط نفسِه ═══
 *
 * على الحاسوب يُحفظ الملفُّ الذي نزل (`blob:`) بلا طلبٍ ثانٍ. وعلى iPhone
 * وiPad يُحفظ من رابط الخادم بعد أن ثبت أنّه PDF: تنزيلُ `blob:` لا يُعتمد
 * عليه في كلّ متصفّحات iOS، والرابطُ هو ما كان يعمل هناك قبل هذا.
 */
export type PdfDownload = { ok: true } | { ok: false; reason: 'session' | 'failed' };

export async function savePdf(url: string, filename: string): Promise<PdfDownload> {
    const file = await downloadReceipt(url, 'browser');
    if (!(file instanceof Blob)) {
        return { ok: false, reason: !file.ok && file.code === 'SESSION_EXPIRED' ? 'session' : 'failed' };
    }

    if (appleMobile()) {
        click(url, filename);
        return { ok: true };
    }

    const href = URL.createObjectURL(file);
    click(href, filename);
    // والرابطُ المؤقّت يُترك قليلًا: بعضُ المتصفّحات تقرأه بعد أن تعود النقرة
    setTimeout(() => URL.revokeObjectURL(href), 60_000);

    return { ok: true };
}

function click(href: string, filename: string): void {
    const link = document.createElement('a');
    link.href = href;
    link.download = filename;
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
}

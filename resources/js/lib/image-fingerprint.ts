/**
 * بصمةُ الصورة الأصليّة قبل أن يصغّرها المتصفّح — يقرؤها الخادم تلميحًا (`ImageFingerprints`).
 *
 * الصورةُ الكبيرة تُصغَّر هنا قبل الرفع (`shrink`)، فلا يرى الخادمُ أصلَها. فتُحسب
 * بصمتُه SHA-256 قبل التصغير وتُرسَل معها، فيعرف الخادمُ صورةً رُفعت من قبل في
 * المتجر نفسه ولو اختلفت بايتاتُ تصغيرها. والصورةُ التي لا تُصغَّر تُرسَل كما هي،
 * فبصمتُها في بايتاتها — ولا يُرسَل لها شيء.
 *
 * والبصمةُ تتبع الملفَّ المصغَّر نفسَه (`WeakMap`): تُنسى معه، ولا تُخلط بغيره.
 * وبلا `crypto.subtle` (صفحةٌ بلا https) لا بصمة — ويبقى الرفعُ كما كان.
 */
const originals = new WeakMap<File, string>();

export async function sha256(file: Blob): Promise<string | null> {
    const subtle = globalThis.crypto?.subtle;

    if (! subtle) return null;

    try {
        const digest = await subtle.digest('SHA-256', await file.arrayBuffer());

        return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
    } catch {
        return null;
    }
}

/** يُصغَّر الملفّ — وتُحفظ بصمةُ أصله مع المصغَّر. والبصمةُ مع التصغير لا قبله: لا انتظارَ يُزاد */
export async function shrunkWithOriginal(file: File, shrink: (f: File) => Promise<File>): Promise<File> {
    const [hash, small] = await Promise.all([sha256(file), shrink(file)]);

    if (small !== file && hash) originals.set(small, hash);

    return small;
}

/** بصمةُ أصل ملفٍّ مصغَّر — أو نصٌّ فارغ لما رُفع كما هو */
export function originalOf(file: File): string {
    return originals.get(file) ?? '';
}

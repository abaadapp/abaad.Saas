/**
 * روابط التاجر ← إطاراتٌ تُعرض.
 *
 * التاجر يلصق ما ينسخه: رابط يوتيوب من شريط العنوان، ورابط «مشاركة» من
 * خرائط غوغل. وأيٌّ منهما لا يُعرض في `iframe` كما هو. فيُترجَم هنا.
 *
 * ولا يُوضع في `iframe` إلا مضيفٌ معروف: `src` من نصٍّ كتبه مستخدمٌ هو باب
 * إدخالِ صفحةٍ كاملةٍ في موقع التاجر. فما لم يُعرف يُعرض رابطًا يُفتح، لا
 * إطارًا يُثق به.
 */

/** معرّف فيديو يوتيوب من أيّ صيغةٍ من صيغ روابطه */
export function youtubeId(url: string): string | null {
    const patterns = [
        /youtube\.com\/watch\?(?:.*&)?v=([A-Za-z0-9_-]{6,20})/,
        /youtu\.be\/([A-Za-z0-9_-]{6,20})/,
        /youtube\.com\/embed\/([A-Za-z0-9_-]{6,20})/,
        /youtube\.com\/shorts\/([A-Za-z0-9_-]{6,20})/,
    ];

    for (const re of patterns) {
        const m = url.match(re);

        if (m) return m[1];
    }

    return null;
}

export function vimeoId(url: string): string | null {
    const m = url.match(/vimeo\.com\/(?:video\/)?(\d{6,12})/);

    return m ? m[1] : null;
}

export type VideoEmbed =
    | { kind: 'iframe'; src: string; title: string }
    | { kind: 'file'; src: string }
    | { kind: 'link'; href: string }
    | null;

export function videoEmbed(rawUrl: string, title: string): VideoEmbed {
    const url = rawUrl.trim();

    if (url === '') return null;

    const yt = youtubeId(url);

    if (yt) {
        // `nocookie` لا يزرع تتبّعًا قبل أن يضغط الزائر تشغيل
        return { kind: 'iframe', src: `https://www.youtube-nocookie.com/embed/${yt}?rel=0`, title };
    }

    const vimeo = vimeoId(url);

    if (vimeo) {
        return { kind: 'iframe', src: `https://player.vimeo.com/video/${vimeo}`, title };
    }

    if (/^https?:\/\/\S+\.(mp4|webm|ogg)(\?\S*)?$/i.test(url)) {
        return { kind: 'file', src: url };
    }

    if (/^https?:\/\//i.test(url)) {
        return { kind: 'link', href: url };
    }

    return null;
}

/**
 * الخريطة — من العنوان المكتوب أوّلًا، ثمّ من إحداثيّاتٍ في الرابط.
 *
 * رابط «مشاركة» المختصر (`maps.app.goo.gl/…`) لا يُعرض في إطار: هو تحويلةٌ
 * لا صفحة. و`output=embed` على بحثٍ بالعنوان يعمل بلا مفتاحٍ ولا حساب — وهو
 * ما يملكه التاجر فعلًا: عنوانُ محلّه.
 */
export function mapEmbed(address: string, url: string): string | null {
    const query = address.trim();

    if (query !== '') {
        return `https://www.google.com/maps?q=${encodeURIComponent(query)}&output=embed&hl=ar`;
    }

    const coords = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/) ?? url.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/);

    if (coords) {
        return `https://www.google.com/maps?q=${coords[1]},${coords[2]}&output=embed&hl=ar`;
    }

    return null;
}

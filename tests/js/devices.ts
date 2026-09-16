import { vi } from 'vitest';

/**
 * أجهزةٌ يُشغَّل عليها النظام — تُوصف مرّةً وتُقرأ في كلّ اختبارٍ يسأل عنها.
 *
 * والسؤالُ يُسأل في موضعين (`lib/touch` و`lib/enter-key`)، فلو وُصف
 * الآيبادُ في ملفّي اختبارٍ لَافترق الوصفان يومَ يُبدَّل أحدهما — ومرّ
 * حارسٌ وهو يقيس جهازًا لا وجودَ له.
 */
export interface Device {
    /** ما يردّه استعلامُ `(hover: none) and (pointer: coarse)` */
    coarse: boolean;
    touchPoints: number;
    ua: string;
}

const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15';

/** هاتفٌ يقول ما هو: استعلامٌ صادقٌ ولمسٌ ظاهر */
export const PHONE: Device = {
    coarse: true,
    touchPoints: 5,
    ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1',
};

/**
 * والآيباد يقول إنّه حاسوب.
 *
 * «عرض كموقع حاسوب» هو الافتراض عليه منذ iPadOS 13: وكيلُ المستخدم يصير
 * وكيلَ ماك، والاستعلامُ يردّ `hover: hover` و`pointer: fine`. ولا يبقى
 * ممّا يفضحه إلّا `maxTouchPoints`.
 */
export const IPAD: Device = { coarse: false, touchPoints: 5, ua: MAC };

/** وحاسوبُ ماك حقيقيّ: وكيلٌ مثلُه ولا لمسَ فيه */
export const DESKTOP: Device = { coarse: false, touchPoints: 0, ua: MAC };

/**
 * وحاسوبٌ محمولٌ بشاشةٍ لمسيّة — لمسٌ ظاهرٌ وتحته لوحةُ مفاتيحَ حقيقيّة.
 *
 * لا يُمسّ: لا لوحةَ تنغلق، وتكبيرُ أزراره ومنعُ Enter فيه إزعاجٌ لا حماية.
 */
export const TOUCH_LAPTOP: Device = {
    coarse: false,
    touchPoints: 10,
    ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
};

export function device(d: Device): void {
    window.matchMedia = vi.fn().mockReturnValue({ matches: d.coarse }) as never;
    Object.defineProperty(navigator, 'maxTouchPoints', { value: d.touchPoints, configurable: true });
    Object.defineProperty(navigator, 'userAgent', { value: d.ua, configurable: true });
}

/**
 * وصفاتُ المتصفّح تُنزع لا تُعاد بقيمةٍ مكتوبة.
 *
 * `restoreMocks` تُعيد ما صنعته `vi.spyOn` ولا تعرف شيئًا عن خاصيّةٍ كُتبت
 * فوق `navigator`. وكتابةُ قيمةٍ «أصليّة» باليد تجعل بيئةَ الاختبار تدّعي
 * جهازًا لم يُشغّلها — فتُحذف الخاصيّةُ المكتوبة ويعود ما في jsdom.
 */
export function forgetDevice(): void {
    const browser = navigator as unknown as Record<string, unknown>;
    delete browser.maxTouchPoints;
    delete browser.userAgent;
}

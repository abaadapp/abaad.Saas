/**
 * أبعاد تطبيقًا — هل تعمل الصفحةُ في التطبيق المثبَّت، وعلى أيّ جهاز.
 *
 * التطبيقُ المثبَّت (`manifest.webmanifest`، `display: standalone`) لا شريطَ
 * متصفّحٍ فيه: لا زرَّ رجوع ولا عنوان. فما يحتاج طريقًا إلى الشاشة الرئيسيّة
 * يسأل هنا، والمتصفّحُ العاديّ لا يتغيّر فيه شيء.
 */

/**
 * هاتفٌ أم آيباد وحاسوب — بأقصر ضلعَي الشاشة نفسِها.
 *
 * أكبرُ هاتفٍ أقصرُ ضلعيه نحو ٤٤٠ نقطة (والمطويُّ مغلقًا أقلّ)، وأصغرُ
 * آيباد (mini) ٧٤٤، والمطويُّ مفتوحًا فوق ٦٠٠ — لوحيٌّ بحجمه. فالحدُّ
 * ٦٠٠ بينهما بسعة. ولا يُقاس عرضُ النافذة: هاتفٌ مُدارٌ عرضُه ٩٣٢،
 * وحاسوبٌ نافذتُه مصغّرة يبقى حاسوبًا. ولا User-Agent: iPadOS يقول إنّه Mac.
 */
export const PHONE_SHORT_SIDE_BELOW = 600;

export function isPhone(screen: { width: number; height: number } = window.screen): boolean {
    return Math.min(screen.width, screen.height) < PHONE_SHORT_SIDE_BELOW;
}

/**
 * يعمل تطبيقًا مثبَّتًا لا صفحةً في متصفّح.
 *
 * `display-mode` يقوله Chrome وEdge وSafari الحديث، و`navigator.standalone`
 * يقوله Safari على iPhone وiPad منذ القديم.
 */
export function isInstalledApp(): boolean {
    const mode = (query: string) => typeof window.matchMedia === 'function' && window.matchMedia(query).matches;

    return (
        mode('(display-mode: standalone)') ||
        mode('(display-mode: fullscreen)') ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

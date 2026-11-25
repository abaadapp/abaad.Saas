import { useCallback, useEffect, useRef, useState } from 'react';

/** كم يبقى «نُسخ» ظاهرًا — طويلٌ بما يُقرأ، قصيرٌ بما لا يُشوّش الضغطة التالية */
const HOLD = 1600;

/**
 * النسخُ إلى الحافظة — موضعٌ واحد يقرأ منه كلُّ زرّ نسخٍ في النظام.
 *
 * ═══ ولماذا جُمعت ═══
 *
 * كان النمطُ مكتوبًا بستّ أيدٍ: كلمةُ مرورٍ في لوحة المنصّة، وأخرى في صفحة
 * الموظّف، وحسابات متجرٍ تجريبيّ، ورابطٌ في «الظهور في البحث»، وآخرُ في
 * Google، ورقمُ فاتورة. وستُّ نسخٍ من عشرة أسطرٍ تفترق — وقد افترقت فعلًا:
 * ثلاثةٌ منها كانت `navigator.clipboard?.writeText(x)` ثمّ `setCopied(true)`
 * في السطر الذي يليه.
 *
 * ═══ والعطبُ الذي كشفه الجمع ═══
 *
 * `navigator.clipboard` غيرُ موجودٍ على أصلٍ غير آمن (http) ومحجوبٌ في
 * بعض المتصفّحات. و`?.` تُسكت الغياب فتردّ `undefined` بلا خطأ — فيُعلَن
 * «نُسخ» على نسخٍ لم يقع. وأخطرُ مواضعها اثنان: كلمةُ مرور التاجر وكلمةُ
 * مرور الموظّف — تُعرضان مرّةً واحدة ولا تُقرآن من القاعدة أبدًا. فيقرأ
 * المشغّلُ «نُسخت»، ويغلق النافذة، وتذهب الكلمةُ التي لا تُسترجَع.
 *
 * فلا يُعلَن هنا نجاحٌ إلّا بعد أن يفي الوعد.
 */
/**
 * نصٌّ يأتي من الخادم بعد الضغطة.
 *
 * Safari لا يأذن بالنسخ إلّا والضغطةُ قائمة، فـ`writeText` بعد انتظار الردّ
 * تُرفض فيه. فيُسلَّم الوعدُ نفسُه للحافظة ساعةَ الضغط (`ClipboardItem`)،
 * وما لا يقبل الوعدَ ينتظر النصَّ ثمّ يكتبه.
 */
async function writeLater(text: Promise<string>): Promise<void> {
    if (typeof ClipboardItem !== 'undefined' && typeof navigator.clipboard?.write === 'function') {
        const blob = text.then((value) => new Blob([value], { type: 'text/plain' }));
        // ومن لم يقرأ الوعدَ لا يتركه رفضًا بلا مستمع
        blob.catch(() => undefined);

        try {
            return await navigator.clipboard.write([new ClipboardItem({ 'text/plain': blob })]);
        } catch {
            // متصفّحٌ يريد النصَّ لا وعدَه — يُجرَّب ما بعده
        }
    }

    await navigator.clipboard.writeText(await text);
}

export function useCopy(hold: number = HOLD) {
    /*
     * والحالةُ رايةٌ لا نصّ — لأنّها حالةُ مقبضٍ واحد.
     *
     * كانت نصًّا يُقارَن ليخدم الخطّافُ الواحدُ أزرارًا عدّة، وهو ما كانت
     * تفعله لوحةُ المنصّة قبل الجمع. ولا يفعله شيءٌ اليوم: لكلّ زرٍّ
     * خطّافُه. فجُرّبت المقارنةُ بنزعها فلم يسقط اختبار — وما لا يحرسه شيء
     * لا يُترك يوهم أنّه يحرس.
     */
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | null>(null);

    /*
     * والمؤقّتُ يُلغى عند رفع المكوّن.
     *
     * نافذةُ كلمة المرور تُغلق بعد النسخ بلحظة، فيوقظ المؤقّتُ حالةً في
     * مكوّنٍ رُفع — تحذيرٌ في التطوير، وعملٌ بلا فائدة في الإنتاج.
     */
    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    const said = useCallback(() => {
        setCopied(true);

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }
        timer.current = window.setTimeout(() => setCopied(false), hold);

        return true;
    }, [hold]);

    const copy = useCallback(
        async (text: string): Promise<boolean> => {
            try {
                await navigator.clipboard.writeText(text);
            } catch {
                // متصفّحٌ يمنع الحافظة أو أصلٌ غير آمن — ولا يُعلَن ما لم يقع
                return false;
            }

            return said();
        },
        [said],
    );

    /** ونصٌّ لم يصل بعد — يُنسخ بالضغطة نفسها متى وصل */
    const copyLater = useCallback(
        async (text: Promise<string>): Promise<boolean> => {
            try {
                await writeLater(text);
            } catch {
                return false;
            }

            return said();
        },
        [said],
    );

    return { copy, copyLater, copied };
}

import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * لوحةٌ تُحدِّث نفسَها — بآلية Inertia لا بنافذةٍ ثانية إلى الخادم.
 *
 * ═══ لمَ `router.reload({ only })` ═══
 *
 * اللوحة تُقرأ من العنوان: المرشّحان في `?when=&type=`. فإعادةُ التحميل
 * الجزئيّة تسأل الخادمَ عن العنوان نفسِه فتحفظ المرشّحين بلا أن يُمرَّرا،
 * وتُرجع الخصائصَ المطلوبة وحدها. ونافذةٌ ثانية (`fetch` إلى مسارٍ خاصّ)
 * كانت ستعني تشكيلَ الحمولة مرّتين: مرّةً للصفحة ومرّةً للتغذية — وأوّلُ
 * حقلٍ يُضاف يُنسى في إحداهما.
 *
 * و`reload` تحفظ حالَ الشاشة وموضعَ التمرير في نفسها — تفرض `preserveState`
 * و`preserveScroll` ولا تقبلهما وسيطين. فالنافذةُ المفتوحة والعرضُ المختار
 * يبقيان: من يقرأ تفاصيل طلبٍ لا تُغلق نافذتُه لأنّ عشرين ثانية مضت.
 *
 * ═══ وثلاثةُ أبوابٍ تُغلق الاستطلاع ═══
 *
 * ١) تبويبٌ مخفيّ: شاشةٌ لا يراها أحد لا تسأل الخادم. واللوحةُ تبقى مفتوحةً
 *    طولَ اليوم على جهاز الطاولة، فاستطلاعٌ لا يتوقّف يعني ألفَ طلبٍ في
 *    النهار لا يُقرأ منها شيء.
 * ٢) طلبٌ جارٍ: نداءان متداخلان يعني أن يصل الأقدمُ بعد الأحدث فيكتب فوقه —
 *    فترتدّ بطاقةٌ نُقلت إلى حالها القديم أمام من نقلها.
 * ٣) فعلٌ قائم (`paused`): من يضغط «جاهز» أو يؤشّر مربّعًا لا تُسحب البطاقةُ
 *    من تحت يده. وهذا هو الشرطُ الذي لا تكفي فيه آليّةُ Inertia وحدها.
 *
 * ═══ والفشلُ يُقال ولا يمسح ═══
 *
 * انقطاعُ الشبكة يترك المعروضَ كما هو ويرفع علمًا: من يجهّز يقرأ لوحةً
 * عمرُها دقيقتان ويعلم ذلك — أفضلُ من شاشةٍ تُفرَّغ، وأصدقُ من شاشةٍ تكذب.
 */
interface Options {
    /** الخصائص التي تُجلب وحدها — لا الصفحة كلّها */
    only: string[];
    intervalMs?: number;
    /** فعلٌ قائم على بطاقة: لا يُسحب ما تحت اليد */
    paused?: boolean;
}

export default function useBoardLive({ only, intervalMs = 20000, paused = false }: Options) {
    const [refreshing, setRefreshing] = useState(false);
    const [failed, setFailed] = useState(false);
    const busy = useRef(false);
    // القيمُ المتغيّرة تُقرأ من مرجعٍ داخل المؤقّت: ولو أُدرجت في اعتماديّاته
    // لَأُعيد بناؤه عند كلّ ضغطة، فبدأت العشرون ثانية من جديدٍ في كلّ مرّة
    const pausedRef = useRef(paused);
    pausedRef.current = paused;
    const onlyRef = useRef(only);
    onlyRef.current = only;

    const pull = useCallback((manual: boolean) => {
        if (busy.current) return;
        if (!manual && pausedRef.current) return;

        busy.current = true;
        setRefreshing(true);

        /*
         * و`preserveState`/`preserveScroll` لا تُمرَّران: `reload` تفرضهما
         * `true` في نفسها وتحذفهما من نوعها. فتمريرُهما خطأُ ترجمةٍ لا توكيد.
         */
        router.reload({
            only: onlyRef.current,
            onSuccess: () => setFailed(false),
            onError: () => setFailed(true),
            /*
             * و`onFinish` لا `onSuccess` وحدها: الزيارةُ قد تُلغى أو تفشل،
             * وعلمٌ لا يُنزَّل إلّا عند النجاح يُقفل الاستطلاع إلى الأبد بعد
             * أوّل انقطاع — لوحةٌ تتوقّف بلا أن يقول أحدٌ إنّها توقّفت.
             */
            onFinish: () => {
                busy.current = false;
                setRefreshing(false);
            },
        });
    }, []);

    const refresh = useCallback(() => pull(true), [pull]);

    useEffect(() => {
        const id = setInterval(() => {
            if (typeof document !== 'undefined' && document.hidden) return;
            pull(false);
        }, intervalMs);

        return () => clearInterval(id);
    }, [pull, intervalMs]);

    return { refresh, refreshing, failed };
}

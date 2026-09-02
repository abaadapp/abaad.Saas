import { useEffect } from 'react';

/**
 * ارتفاع لوحة المفاتيح المعروضة — بالبكسل، في متغيّر `--kb`.
 *
 * الحاجة إليه تخصّ الأجهزة اللوحية وحدها: على الآيباد **لا تُقلّص** لوحةُ
 * المفاتيح صفحةَ الويب. `100dvh` تبقى كما هي، فتحسب الواجهة أنّها تملك
 * الشاشة كلّها بينما نصفُها السفليّ مغطّى — وفي ذلك النصف يقع زرُّ الدفع،
 * وحقلُ الكوبون، وزرُّ تأكيد الدفع في نافذة السداد. وشاشةُ البيع
 * `overflow-hidden`، فلا يستطيع الكاشير حتى أن يمرّرها ليصل إليها.
 *
 * والمقاس يُقرأ من `visualViewport` لأنّه الشيء الوحيد الذي يعرف بلوحة
 * المفاتيح: `innerHeight` لا تتغيّر عند فتحها، و`dvh` تتبع شريطَ المتصفّح
 * لا اللوحة.
 *
 * ثمّ يُطرح من ارتفاع الشاشة في الأماكن التي تُبنى على الارتفاع الكامل
 * (شاشة البيع، والنوافذ المنبثقة) — فيُقلَّص المرسوم إلى ما يُرى فعلًا،
 * ويبقى كلُّ زرٍّ في متناول الإصبع واللوحةُ مفتوحة.
 */

/**
 * أقلّ ما يُعدّ لوحةَ مفاتيح — بالبكسل.
 *
 * شريطُ العنوان في سفاري ينطوي ويُفتح فيُغيّر `visualViewport` بعشرات
 * البكسلات، ولو عُدَّ ذلك لوحةً لَقفزت الواجهةُ مع كلّ تمرير. ولا لوحةَ
 * مفاتيحَ على جهازٍ لوحيّ أقصر من هذا.
 */
const MIN_KEYBOARD = 120;

export function useOnScreenKeyboard(): void {
    useEffect(() => {
        const viewport = window.visualViewport;
        const root = document.documentElement;

        if (!viewport) {
            return;
        }

        const measure = () => {
            /*
             * وما تحت المرئيّ هو المحجوب.
             *
             * `offsetTop` يدخل الحساب: حين يُزيح المتصفّح الصفحةَ إلى أعلى
             * ليُظهر الحقل المركَّز، ينزاح المرئيُّ عن أعلى النافذة — وطرحُ
             * الارتفاع وحده كان يعدّ الإزاحة لوحةَ مفاتيحَ إضافية.
             */
            const hidden = window.innerHeight - viewport.height - viewport.offsetTop;
            const keyboard = hidden > MIN_KEYBOARD ? Math.round(hidden) : 0;

            root.style.setProperty('--kb', `${keyboard}px`);
        };

        measure();
        viewport.addEventListener('resize', measure);
        viewport.addEventListener('scroll', measure);

        return () => {
            viewport.removeEventListener('resize', measure);
            viewport.removeEventListener('scroll', measure);
            root.style.setProperty('--kb', '0px');
        };
    }, []);
}

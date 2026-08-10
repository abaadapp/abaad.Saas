/**
 * «تم» على لوحة مفاتيح الآيباد تُنهي الكتابة، ولا تحفظ.
 *
 * المتصفّحات ترسل النموذج حين تُضغط Enter داخل حقلٍ فيه — «الإرسال الضمني».
 * على سطح المكتب هذا متوقَّع: يد المستخدم على المفاتيح ويعرف أنه ضغط Enter.
 * وعلى الآيباد ليس كذلك: المفتاح مكتوبٌ عليه «تم»، وهي كلمةٌ تعني إنهاءَ
 * الكتابة في الخانة لا حفظَ النموذج. فيكتب التاجر اسم المنتج، يضغط «تم»
 * ليغلق اللوحة ويكمل بقيّة الحقول، فيُحفظ المنتج ناقصًا — وقد أُنشئ سجلٌّ
 * لا يريده أحد.
 *
 * فعلى الأجهزة اللمسية وحدها: Enter داخل حقلٍ في نموذج تُنهي التركيز فقط،
 * والحفظ يبقى بضغطة الزرّ. وسطح المكتب لا يتغيّر.
 *
 * ثلاثة استثناءات مقصودة:
 * — النماذج التي تحمل `data-enter-submits`: الدخول والرمز واستعادة كلمة
 *   المرور. حقلٌ أو حقلان و«اذهب» على المفتاح، والمنعُ فيها إزعاجٌ لا حماية.
 * — ما استهلك المفتاح قبلنا (defaultPrevented): حقل الباركود يقرأ Enter من
 *   الماسح، وحقل الكوبون يطبّقه. المستمع في الفقاعة فيصل بعد React.
 * — textarea: Enter فيها سطرٌ جديد ولا تُرسل نموذجًا أصلًا.
 */
export function enterEndsTypingOnTouch(): void {
    if (!window.matchMedia?.('(hover: none) and (pointer: coarse)').matches) {
        return;
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.defaultPrevented) return;

        const el = event.target;
        if (!(el instanceof HTMLInputElement)) return;
        // لا نموذج فلا إرسال ضمنيًّا — ولا شأن لنا بالمفتاح
        if (!el.form || el.form.dataset.enterSubmits !== undefined) return;
        if (['submit', 'button', 'reset', 'image'].includes(el.type)) return;

        event.preventDefault();
        el.blur();
    });
}

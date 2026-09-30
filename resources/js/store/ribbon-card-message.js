/*
 * رسالةُ كرت الهدية بلا ثمن في إتمام RIBBON — لمن في قائمتها وحده.
 *
 * ═══ ولمَ ملفٌّ لا سطورٌ في القالب ═══
 *
 * صفحاتُ RIBBON بلا حزمة Vite، وما داخل `<script>` في بليد لا يبلغه اختبار.
 * وهنا قرارٌ يُحرس: الخانةُ لا تُسعِّر ولا تُرسل `gift_card`، والنصُّ لا
 * يُرسَل إلّا والخانةُ مختارة. فيُكتب هنا، ويُضمَّن في الصفحة كما هو
 * (`checkout.blade.php`)، ويستورده Vitest فيضغط عليه.
 *
 * ═══ والقاعدة ═══
 *
 * الخانةُ حالٌ في الشاشة لا مالٌ في الطلب: تفتح خانةَ الكتابة وتطويها،
 * ولا تُعيد التسعير — فالإجماليُّ لا يتبدّل بها. وطيُّها يمحو ما كُتب،
 * فلا يُرسَل نصٌّ خفيٌّ لم يعد الزبونُ يريده. والنصُّ يذهب في `card`
 * وحده (`orders.card_message`).
 */
window.RBCardMessage = (function () {
    /*
     * toggle — الخانة، box — ما يُفتح بها، text — خانةُ الكتابة،
     * preview — معاينةُ النصّ (اختياريّة).
     */
    function mount(toggle, box, text, preview) {
        function paint() { if (preview) preview.textContent = text.value; }

        toggle.addEventListener('change', function () {
            box.hidden = !toggle.checked;
            if (!toggle.checked) { text.value = ''; paint(); }
        });
        text.addEventListener('input', paint);
        box.hidden = !toggle.checked;

        return {
            /* ما يُرسَل في `card` — والخانةُ مطويّةً لا نصّ */
            card: function () { return toggle.checked ? text.value : ''; },
        };
    }

    return { mount: mount };
})();

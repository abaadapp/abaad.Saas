/*
 * «هذا الطلب هدية» في إتمام RIBBON — لمن فتح له مديرُ المنصّة الإهداء (`Store\GiftOrders`).
 *
 * ═══ ولمَ ملفٌّ لا سطورٌ في القالب ═══
 *
 * صفحاتُ RIBBON بلا حزمة Vite، وما داخل `<script>` في بليد لا يبلغه اختبار.
 * فيُكتب هنا ويُضمَّن في الصفحة كما هو (`checkout.blade.php`)، ويستورده Vitest.
 *
 * ═══ والقاعدة ═══
 *
 * - الخانةُ تفتح المستلِمَ والمناسبةَ وإخفاءَ الاسم، وطيُّها يمحو المستلِمَ
 *   المكتوب فيها — فلا يُرسَل مستلِمٌ لطلبٍ لم يعد هديّة.
 * - «أخرى» تفتح خانةَ نصّ المناسبة، والمناسبةُ اختياريّةٌ كلُّها.
 * - والعنوانُ عنوانُ قسم التوصيل نفسِه — لا طريقةَ موقعٍ تُختار، ولا «تواصلوا
 *   مع المستلم» يُخفي العنوان. وسطرٌ يقول ذلك لمن يُوصَّل إليه وحده.
 * - ولا تُسعِّر شيئًا: الهديّةُ لا تمسّ الإجماليّ.
 */
window.RBGiftOrder = (function () {
    function mount(form, toggle) {
        var box = form.querySelector('[data-rb-giftbox]');
        var occasion = form.querySelector('[data-rb-occasion]');
        var otherBox = form.querySelector('[data-rb-occasion-other]');
        var otherText = form.querySelector('[name=occasion_text]');
        var hide = form.querySelector('[data-rb-hide-sender]');
        var addressHint = form.querySelector('[data-rb-gift-address-hint]');
        var listeners = [];
        var current = 'delivery';

        function paint() {
            box.hidden = !toggle.checked;
            if (otherBox) otherBox.hidden = !(occasion && occasion.value === 'other');
            if (addressHint) addressHint.hidden = current !== 'delivery';
        }

        function changed() {
            paint();
            listeners.forEach(function (fn) { fn(); });
        }

        toggle.addEventListener('change', function () {
            if (!toggle.checked) {
                box.querySelectorAll('[name=recipient_name],[name=recipient_phone]').forEach(function (i) { i.value = ''; });
            }
            changed();
        });
        if (occasion) occasion.addEventListener('change', function () {
            if (occasion.value !== 'other' && otherText) otherText.value = '';
            paint();
        });
        paint();

        return {
            fulfil: function (fulfil) { current = fulfil; paint(); },
            onChange: function (fn) { listeners.push(fn); },
            /* ما يُرسَل مع الطلب — وطلبٌ ليس هديّةً لا يحمل إلّا `is_gift: false` */
            payload: function () {
                if (!toggle.checked) return { is_gift: false };
                var oc = occasion ? occasion.value : '';
                return {
                    is_gift: true,
                    occasion: oc,
                    occasion_text: oc === 'other' && otherText ? otherText.value : '',
                    hide_sender: !!(hide && hide.checked),
                };
            },
        };
    }

    return { mount: mount };
})();

/*
 * «هذا الطلب هدية» في إتمام RIBBON — لمن رفع ميزةَ الإهداء (`Store\GiftOrders`).
 *
 * ═══ ولمَ ملفٌّ لا سطورٌ في القالب ═══
 *
 * صفحاتُ RIBBON بلا حزمة Vite، وما داخل `<script>` في بليد لا يبلغه اختبار.
 * فيُكتب هنا ويُضمَّن في الصفحة كما هو (`checkout.blade.php`)، ويستورده Vitest.
 *
 * ═══ والقاعدة ═══
 *
 * - الخانةُ تفتح المستلِمَ والمناسبةَ وإخفاءَ الاسم وطريقةَ الموقع، وطيُّها
 *   يمحو المستلِمَ المكتوب فيها — فلا يُرسَل مستلِمٌ لطلبٍ لم يعد هديّة.
 * - «أخرى» تفتح خانةَ نصّ المناسبة، والمناسبةُ اختياريّةٌ كلُّها.
 * - طريقةُ الموقع للتوصيل وحده؛ و«تواصلوا مع المستلم» تُخفي العنوانَ في
 *   الصفحة (`contacts`) — والخادمُ يقبل الطلبَ بلا عنوان في هذه وحدها.
 * - ولا تُسعِّر شيئًا: الهديّةُ لا تمسّ الإجماليّ.
 */
window.RBGiftOrder = (function () {
    function mount(form, toggle) {
        var box = form.querySelector('[data-rb-giftbox]');
        var occasion = form.querySelector('[data-rb-occasion]');
        var otherBox = form.querySelector('[data-rb-occasion-other]');
        var otherText = form.querySelector('[name=occasion_text]');
        var hide = form.querySelector('[data-rb-hide-sender]');
        var loc = form.querySelector('[data-rb-giftloc]');
        var note = form.querySelector('[data-rb-locnote]');
        var listeners = [];
        var current = 'delivery';

        function mode() {
            var r = form.querySelector('[name=recipient_location]:checked');
            return r ? r.value : 'provided';
        }

        function paint() {
            box.hidden = !toggle.checked;
            if (otherBox) otherBox.hidden = !(occasion && occasion.value === 'other');
            if (loc) loc.hidden = current !== 'delivery';
            if (note) note.hidden = mode() !== 'contact_recipient';
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
        form.querySelectorAll('[name=recipient_location]').forEach(function (r) { r.addEventListener('change', changed); });
        paint();

        return {
            /* هديّةٌ تُوصَّل ويتواصل المتجرُ مع مستلِمها — فلا يُسأل عن عنوان */
            contacts: function (fulfil) {
                return toggle.checked && fulfil === 'delivery' && mode() === 'contact_recipient';
            },
            fulfil: function (fulfil) { current = fulfil; paint(); },
            onChange: function (fn) { listeners.push(fn); },
            /* ما يُرسَل مع الطلب — وطلبٌ ليس هديّةً لا يحمل إلّا `is_gift: false` */
            payload: function (fulfil) {
                if (!toggle.checked) return { is_gift: false };
                var oc = occasion ? occasion.value : '';
                return {
                    is_gift: true,
                    occasion: oc,
                    occasion_text: oc === 'other' && otherText ? otherText.value : '',
                    hide_sender: !!(hide && hide.checked),
                    recipient_location: fulfil === 'delivery' ? mode() : null,
                };
            },
        };
    }

    return { mount: mount };
})();

/*
 * «لا تذكر اسمي» ورسالةُ البطاقة في إتمام RIBBON — لمن فُتح له الطرح (`Store\CardIdentity`).
 *
 * ═══ ولمَ ملفٌّ لا سطورٌ في القالب ═══
 *
 * صفحاتُ RIBBON بلا حزمة Vite، وما داخل `<script>` في بليد لا يبلغه اختبار.
 * فيُكتب هنا ويُضمَّن في الصفحة كما هو (`checkout.blade.php`)، ويستورده Vitest.
 *
 * ═══ والقاعدة ═══
 *
 * - الكشفُ في الخادم وحده: يردّ الطلبَ ومعه رمزٌ ومصدرُ النصّ، والصفحةُ
 *   تسأل — ولا تحكم من عندها، ولا تعدّل الرسالةَ أبدًا.
 * - «إرسال الرسالة كما هي» يحفظ الرمزَ ويُعيد الإرسال. والرمزُ يُنسى متى
 *   تبدّل الاسمُ أو الهاتفُ أو المستلِمُ أو الرسالةُ أو خيارُ الإخفاء —
 *   والخادمُ يربطه بها كذلك، فلا يمرّ إقرارٌ قديمٌ على نصٍّ جديد.
 * - «تعديل الرسالة» لا يُرسل شيئًا: يُعيد المؤشّرَ إلى خانة البطاقة، أو —
 *   حيث الكرتُ صنفٌ في السلّة — يفتح رسائلَ الكروت لتُعدَّل هنا، وتُحفظ في
 *   السلّة نفسِها بلا أن يُترك الإتمام أو يضيع ما كُتب فيه.
 * - وملفُّ الكرت لا يُقرأ: تذكيرٌ عامٌّ تحته ما دام الإخفاءُ مختارًا.
 */
window.RBCardConsent = (function () {
    /* ما يُبطل الإقرار: هويّةُ المشتري، والمستلِم، والرسالة، وخيارُ الإخفاء والهديّة والكرت */
    var WATCH = '[name=name],[name=phone],[name=recipient_name],[name=card],[data-rb-hide-sender],[data-rb-gift],[data-rb-cardmsg],[data-rb-giftcard]';

    /*
     * بندُ السلّة بنصٍّ جديد — والبندان اللذان صارا واحدًا يُجمعان، كما
     * يجمعهما `RBLines.add`: النصُّ من هويّة البند.
     */
    function rewrite(items, id, variantId, from, to) {
        var out = [];
        items.forEach(function (it) {
            var hit = it.id === id && (it.variant_id || null) === (variantId || null) && (it.note || '') === from;
            var row = Object.assign({}, it);
            var note = hit ? to : (it.note || '');
            if (note) row.note = note; else delete row.note;

            var twin = null;
            out.forEach(function (o) {
                if (o.id === row.id && (o.variant_id || null) === (row.variant_id || null) && (o.note || '') === note) twin = o;
            });
            if (twin) { twin.qty += row.qty; return; }
            out.push(row);
        });
        return out;
    }

    /* تذكيرُ الملفّ — هديّةٌ أُخفي فيها الاسمُ، وكرتٌ يُرفق ملفًّا */
    function fileReminder(state) {
        return !!(state && state.gift && state.hidden && state.card && state.way === 'file');
    }

    /*
     * form — نموذجُ الإتمام، box — صندوقُ السؤال، cart — `{ read, write }`
     * سلّةُ الصفحة، opts.onSend — يُعيد الإرسال، opts.onSaved — بعد حفظ رسائل
     * الكروت (يُعيد التسعير)، opts.max — أطولُ رسالة.
     */
    function mount(form, box, cart, opts) {
        opts = opts || {};
        var ack = null, offered = null, source = 'card', lines = [];
        var editor = box.querySelector('[data-rb-cardlines]');
        var saveBtn = box.querySelector('[data-rb-consent-save]');

        function forget() { ack = null; }
        function watched(e) { return e.target && e.target.matches && e.target.matches(WATCH); }
        form.addEventListener('input', function (e) { if (watched(e)) forget(); });
        form.addEventListener('change', function (e) { if (watched(e)) forget(); });

        function closeEditor() {
            if (editor) { editor.hidden = true; editor.textContent = ''; }
            if (saveBtn) saveBtn.hidden = true;
        }
        function hide() { box.hidden = true; closeEditor(); }

        function warn(offer) {
            offered = offer && offer.token ? String(offer.token) : null;
            source = offer && offer.source === 'items' ? 'items' : 'card';
            ack = null;
            closeEditor();
            box.hidden = false;
        }

        /* رسائلُ الكروت في السلّة — خانةٌ لكلّ كرت، بنصّه كما هو */
        function openEditor() {
            if (!editor) return;
            editor.textContent = '';
            lines.filter(function (l) { return l.gift_card && l.note; }).forEach(function (l) {
                var t = document.createElement('textarea');
                t.className = 'rb-input';
                t.rows = 4;
                if (opts.max) t.maxLength = opts.max;
                t.value = l.note;
                t.dataset.id = String(l.id);
                t.dataset.variant = l.variant_id == null ? '' : String(l.variant_id);
                t.dataset.from = l.note;
                t.setAttribute('data-rb-cardline', '');
                editor.appendChild(t);
            });
            editor.hidden = false;
            if (saveBtn) saveBtn.hidden = false;
            var first = editor.querySelector('textarea');
            if (first) first.focus();
        }

        function edit() {
            offered = null; ack = null;
            if (source === 'items') { openEditor(); return; }
            hide();
            var t = form.querySelector('[name=card]');
            if (t) {
                if (t.scrollIntoView) t.scrollIntoView({ block: 'center' });
                t.focus();
            }
        }

        function save() {
            var items = cart.read();
            editor.querySelectorAll('[data-rb-cardline]').forEach(function (t) {
                var to = t.value.trim();
                if (to === t.dataset.from) return;
                items = rewrite(items, +t.dataset.id, t.dataset.variant === '' ? null : +t.dataset.variant, t.dataset.from, to);
            });
            cart.write(items);
            hide();
            if (opts.onSaved) opts.onSaved();
        }

        function send() {
            ack = offered;
            hide();
            if (opts.onSend) opts.onSend();
        }

        box.querySelector('[data-rb-consent-edit]').addEventListener('click', edit);
        box.querySelector('[data-rb-consent-send]').addEventListener('click', send);
        if (saveBtn) saveBtn.addEventListener('click', save);
        hide();

        return {
            /* ما يُرسَل في `card_identity_ack` — أو `null` */
            ack: function () { return ack; },
            warn: warn,
            /* أسطرُ آخر تسعيرة — منها تُعرف رسائلُ الكروت في السلّة */
            lines: function (l) { lines = l || []; },
        };
    }

    return { mount: mount, rewrite: rewrite, fileReminder: fileReminder };
})();

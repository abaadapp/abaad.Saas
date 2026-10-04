/*
 * «أضف مع طلبك» في صفحة صنف RIBBON — يركّب السلّةَ ولا يسعّر شيئًا.
 *
 * ═══ ولمَ ملفٌّ لا سطورٌ في القالب ═══
 *
 * صفحاتُ RIBBON بلا حزمة Vite: سكربتُها مكتوبٌ داخل القالب، وما داخل
 * `<script>` في بليد لا يبلغه اختبار. وهنا قرارٌ يُحرس: ما يدخل السلّة
 * وبأيّ مقاسٍ وكمّيّة، ومتى يُمنع الزرّ. فيُكتب هنا، ويُضمَّن في الصفحة
 * كما هو (`product.blade.php`)، ويستورده Vitest فيضغط عليه.
 *
 * ═══ والقاعدة ═══
 *
 * ضغطةٌ واحدة تُدخل الصنفَ بمقاسه وكمّيّته، وكلَّ إضافةٍ مختارة بندًا
 * عاديًّا بكمّيّة ١ — لا تُضرب في كمّيّة الصنف، والسلّةُ تعدّلها بعد ذلك.
 * وإضافةٌ لها مقاساتٌ لا يُختار لها مقاسٌ بالنيابة عن الزبون: إن لم يختر
 * لا يدخل شيء — لا الصنفُ ولا غيرُه — ويُقال له أيّها ينقصه. والمقاسُ وحده
 * ما ينقص: رسالةُ كرت الهدية لا تُعدّ نقصًا.
 *
 * والبنودُ بصيغة السلّة نفسِها `{id, variant_id, qty}`، والإتمامُ يسعّرها
 * من القاعدة كأيّ صنف. فلا ثمنَ ولا اسمَ يُقرأ من الصفحة.
 *
 * ═══ وكرتُ الهدية إضافةً ═══
 *
 * بطاقتُه (`data-gift-card`) تفتح خانةَ نصّه حين تُختار، والنصُّ يدخل مع
 * بنده هو وحده — الوسيطُ الرابع لـ`RB.add`، كما تُدخله صفحةُ الكرت نفسُها
 * (`ribbon-card-note.js`). **والنصُّ اختياريّ:** كرتٌ بلا رسالةٍ يدخل بلا
 * `note`، والخادمُ يقبله كذلك (`GiftCardProduct::settle`).
 */
window.RBUpsells = (function () {
    /* ما اختاره الزبون، بترتيب البطاقات */
    function picked(box) {
        return Array.prototype.map.call(box.querySelectorAll('[data-rb-up].on'), function (card) {
            return {
                id: +card.getAttribute('data-rb-up'),
                needs: card.hasAttribute('data-needs-variant'),
                variant: card.getAttribute('data-variant') ? +card.getAttribute('data-variant') : null,
                gift: card.hasAttribute('data-gift-card'),
                note: noteOf(card),
                card: card,
            };
        });
    }

    /* نصُّ كرت الهدية في بطاقته — مقصوصَ الأطراف، أو '' */
    function noteOf(card) {
        var box = card.querySelector('[data-rb-up-note]');

        return box ? box.value.trim() : '';
    }

    /* البنود — أو ما ينقصه مقاس، ولا بندَ حينها. ورسالةُ الكرت ليست نقصًا */
    function compose(main, picks) {
        var missing = picks.filter(function (p) { return p.needs && !p.variant; });
        if (missing.length) return { ok: false, missing: missing, lines: [] };

        return {
            ok: true,
            missing: [],
            lines: [{ id: main.id, variant_id: main.variant_id || null, qty: main.qty }].concat(picks.map(function (p) {
                var line = { id: p.id, variant_id: p.variant || null, qty: 1 };
                // والنصُّ لبند الكرت وحده إن كُتب — لا يُلصق بالصنف ولا بإضافةٍ أخرى
                if (p.gift && p.note) line.note = p.note;

                return line;
            })),
        };
    }

    /* ما ينقص البطاقة يُقال تحتها: المقاسُ وحده */
    function flag(p, on) {
        show(p.card.querySelector('[data-rb-up-need]'), on && p.needs && !p.variant);
    }

    function show(el, on) { if (el) el.hidden = !on; }

    function toggle(card, on) {
        card.classList.toggle('on', on);
        card.querySelector('[data-rb-up-pick]').setAttribute('aria-pressed', on ? 'true' : 'false');
        show(card.querySelector('[data-rb-up-sizes]'), on);
        show(card.querySelector('[data-rb-up-note-box]'), on);
        if (!on) {
            // من أعاد الاختيار يختار المقاسَ من جديد — لا يُحفظ له ما تركه
            card.removeAttribute('data-variant');
            card.querySelectorAll('[data-rb-up-size]').forEach(function (b) { b.classList.remove('on'); b.setAttribute('aria-pressed', 'false'); });
            show(card.querySelector('[data-rb-up-need]'), false);
            // ونصُّ كرتٍ تُرك يُمحى معه — لا يعود بلا قصد مع اختيارٍ تالٍ
            var note = card.querySelector('[data-rb-up-note]');
            if (note) note.value = '';
        }
    }

    /*
     * opts: button — زرّ السلّة، main() ← {id, variant_id, qty}، add — `RB.add`،
     * done() بعد الإضافة، missing(cards) ببطاقات ما ينقصه مقاس، wait — مدّةُ قفل الزرّ.
     */
    function mount(box, opts) {
        box.addEventListener('click', function (e) {
            var size = e.target.closest('[data-rb-up-size]');
            if (size) {
                var owner = size.closest('[data-rb-up]');
                owner.querySelectorAll('[data-rb-up-size]').forEach(function (b) { b.classList.remove('on'); b.setAttribute('aria-pressed', 'false'); });
                size.classList.add('on'); size.setAttribute('aria-pressed', 'true');
                owner.setAttribute('data-variant', size.getAttribute('data-rb-up-size'));
                show(owner.querySelector('[data-rb-up-need]'), false);
                return;
            }
            var pick = e.target.closest('[data-rb-up-pick]');
            if (pick) {
                var card = pick.closest('[data-rb-up]');
                toggle(card, !card.classList.contains('on'));
            }
        });

        /* ضغطةٌ واحدةٌ تُضيف مرّةً واحدة: الزرُّ مقفلٌ حتّى يعود نصُّه */
        var busy = false;
        opts.button.addEventListener('click', function () {
            if (busy) return;
            var picks = picked(box);
            var r = compose(opts.main(), picks);
            picks.forEach(function (p) { flag(p, false); });
            if (!r.ok) {
                r.missing.forEach(function (p) { flag(p, true); });
                opts.missing(r.missing.map(function (p) { return p.card; }));
                return;
            }
            busy = true; opts.button.disabled = true;
            r.lines.forEach(function (l) {
                if (l.note) opts.add(l.id, l.variant_id, l.qty, l.note);
                else opts.add(l.id, l.variant_id, l.qty);
            });
            // ما دخل السلّة لا يبقى مختارًا — فلا تُعيده ضغطةٌ تالية بلا قصد
            picks.forEach(function (p) { toggle(p.card, false); });
            opts.done();
            setTimeout(function () { busy = false; opts.button.disabled = false; }, opts.wait || 1500);
        });
    }

    return { mount: mount, compose: compose };
})();

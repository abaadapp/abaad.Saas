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
 * عاديًّا **بكمّيّتها هي** — لكلّ بطاقةٍ عدّادُها، لا تُضرب في كمّيّة الصنف
 * ولا يُضرب الصنفُ فيها. والإضافةُ غيرُ المختارة كمّيّتُها صفر؛ اختيارُها
 * يبدأ بواحد، و«−» عند الواحد يُعيدها صفرًا فتُترك.
 *
 * وكان كلُّ بندٍ يدخل بكمّيّة ١ ثابتة: من أراد علبتَي شوكولاتة وثلاثةَ
 * بالونات أضافها واحدةً ثمّ عدّلها في السلّة — أو لم يعرف أنّه يستطيع.
 *
 * وإضافةٌ لها مقاساتٌ لا يُختار لها مقاسٌ بالنيابة عن الزبون: إن لم يختر
 * لا يدخل شيء — لا الصنفُ ولا غيرُه — ويُقال له أيّها ينقصه. والمقاسُ وحده
 * ما ينقص: رسالةُ كرت الهدية لا تُعدّ نقصًا.
 *
 * ═══ وكلُّه أو لا شيء ═══
 *
 * البنودُ تُسلَّم معًا إلى `opts.addAll` (`RB.addMany`)، فتُكتب السلّةُ مرّةً
 * واحدة أو لا تُكتب: سلّةٌ تجاوزت حدَّ الكمّيّة أو البنود تُردّ كلُّها ويُقال
 * لمَ — لا يدخل نصفُ ما اختاره ويُسقَط نصفُه بلا كلمة.
 *
 * والبنودُ بصيغة السلّة نفسِها `{id, variant_id, qty}`، والإتمامُ يسعّرها
 * من القاعدة كأيّ صنف. والمجاميعُ في الصفحة عرضٌ من أسعارٍ كتبها الخادم
 * عليها (`data-amount`) — لا يُصدَّق منها ثمنٌ في الإتمام.
 *
 * ═══ وكرتُ الهدية إضافةً ═══
 *
 * بطاقتُه (`data-gift-card`) تفتح خانةَ نصّه حين تُختار، والنصُّ يدخل مع
 * بنده هو وحده — كما تُدخله صفحةُ الكرت نفسُها (`ribbon-card-note.js`).
 * **والنصُّ اختياريّ:** كرتٌ بلا رسالةٍ يدخل بلا `note`، والخادمُ يقبله
 * كذلك (`GiftCardProduct::settle`). وكرتان من البطاقة نفسِها رسالتُهما واحدة.
 */
window.RBUpsells = (function () {
    /* كمّيّةُ البطاقة — صفرٌ لما لم يُختر */
    function qtyOf(card) {
        var n = parseInt(card.getAttribute('data-qty') || '0', 10);

        return n > 0 ? n : 0;
    }

    /* سعرُ الوحدة للعرض — المقاسُ المختار، وإلّا سعرُ البطاقة؛ و`null` لمن ينقصه مقاس */
    function unitOf(card) {
        var variant = card.getAttribute('data-variant');
        if (variant) {
            var size = card.querySelector('[data-rb-up-size="' + variant + '"]');

            return size && size.hasAttribute('data-amount') ? +size.getAttribute('data-amount') : null;
        }
        if (card.hasAttribute('data-needs-variant') || !card.hasAttribute('data-amount')) return null;

        return +card.getAttribute('data-amount');
    }

    /* ما اختاره الزبون، بترتيب البطاقات — وكلٌّ بكمّيّته */
    function picked(box) {
        return Array.prototype.filter.call(box.querySelectorAll('[data-rb-up]'), function (card) {
            return qtyOf(card) > 0;
        }).map(function (card) {
            return {
                id: +card.getAttribute('data-rb-up'),
                needs: card.hasAttribute('data-needs-variant'),
                variant: card.getAttribute('data-variant') ? +card.getAttribute('data-variant') : null,
                gift: card.hasAttribute('data-gift-card'),
                note: noteOf(card),
                qty: qtyOf(card),
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
            // وملاحظةُ المنتج للصنف نفسِه إن كُتبت — لا تُلصق بإضافةٍ معه
            lines: [main.note ? { id: main.id, variant_id: main.variant_id || null, qty: main.qty, note: main.note } : { id: main.id, variant_id: main.variant_id || null, qty: main.qty }].concat(picks.map(function (p) {
                // وكمّيّةُ الإضافة كمّيّتُها — لا كمّيّةُ الصنف ولا واحدٌ ثابت
                var line = { id: p.id, variant_id: p.variant || null, qty: p.qty > 0 ? p.qty : 1 };
                // والنصُّ لبند الكرت وحده إن كُتب — لا يُلصق بالصنف ولا بإضافةٍ أخرى
                if (p.gift && p.note) line.note = p.note;

                return line;
            })),
        };
    }

    /*
     * المبلغُ مكتوبًا كما يكتبه الخادم (`Money::format`) — للعرض وحده.
     *
     * `spec`: `d` منازلُ العملة، و`symbol` رمزُها مترجَمًا، و`before` موضعُه.
     * وقاعدةُ `Money::shown` نفسُها: لا تُحذف منزلةٌ فيها رقم — تبدأ من منازل
     * العملة وتزيد حتّى يصير المكتوبُ هو المخزَّن بثلاث. والحسابُ بالألف صحيحًا
     * (×١٠٠٠) لا بكسورٍ عائمة.
     */
    function money(spec) {
        var d0 = Math.max(0, Math.min(4, spec && spec.d != null ? (spec.d | 0) : 3));
        var symbol = spec && spec.symbol ? String(spec.symbol) : '';

        return function (value) {
            var mills = Math.round((+value || 0) * 1000);
            var d = d0;
            if (d < 3) {
                for (d = d0; d < 3; d++) { if (mills % Math.pow(10, 3 - d) === 0) break; }
            }
            var text = (mills / 1000).toFixed(Math.min(d, 3)) + (d > 3 ? '0' : '');
            var parts = text.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            text = parts.join('.');

            if (!symbol) return text;

            return spec.before ? symbol + ' ' + text : text + ' ' + symbol;
        };
    }

    /* ما ينقص البطاقة يُقال تحتها: المقاسُ وحده */
    function flag(p, on) {
        show(p.card.querySelector('[data-rb-up-need]'), on && p.needs && !p.variant);
    }

    function show(el, on) { if (el) el.hidden = !on; }

    /*
     * كمّيّةُ البطاقة — وبها يُفتح ما يُفتح ويُطوى ما يُطوى.
     *
     * الصفرُ تركٌ: المقاسُ يُنسى، ونصُّ الكرت يُمحى — لا يعود بلا قصد مع
     * اختيارٍ تالٍ. وما فوق الصفر اختيارٌ، والحدُّ حدُّ البطاقة (`data-max`)
     * وإلّا حدُّ السلّة (`opts.max`).
     */
    function setQty(card, n, max) {
        var cap = parseInt(card.getAttribute('data-max') || '', 10) || max || 50;
        var qty = Math.max(0, Math.min(cap, n | 0));
        var on = qty > 0;

        card.setAttribute('data-qty', String(qty));
        card.classList.toggle('on', on);
        card.querySelector('[data-rb-up-pick]').setAttribute('aria-pressed', on ? 'true' : 'false');
        show(card.querySelector('[data-rb-up-qtybox]'), on);
        show(card.querySelector('[data-rb-up-sizes]'), on);
        show(card.querySelector('[data-rb-up-note-box]'), on);

        var count = card.querySelector('[data-rb-up-count]');
        if (count) count.textContent = String(qty);
        var inc = card.querySelector('[data-rb-up-inc]');
        if (inc) inc.disabled = qty >= cap;

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
     * opts:
     *   button — زرّ السلّة، main() ← {id, variant_id, qty[, note]} أو `null`،
     *   addAll(lines) ← {ok, message?} — `RB.addMany`: كلُّها أو لا شيء،
     *   done() بعد الإضافة، missing(cards) ببطاقات ما ينقصه مقاس،
     *   refused(message) إن رُدّت الإضافةُ كلُّها،
     *   format(amount) ← نصّ، mainAmount() ← سعرُ وحدة الصنف أو `null`،
     *   mainQty() ← كمّيّتُه، total — عنصرُ المجموع، max — حدُّ الكمّيّة،
     *   wait — مدّةُ قفل الزرّ.
     *
     * ويردّ `{ repaint }`: الصفحةُ تناديه حين تتبدّل كمّيّةُ الصنف أو مقاسُه.
     */
    function mount(box, opts) {
        var format = opts.format || function (v) { return String(v); };

        /*
         * سطرُ كلّ بطاقة ومجموعُ ما سيُضاف — عرضٌ من أسعار الخادم المكتوبة.
         *
         * والمجموعُ لا يُعرض ناقصًا: إضافةٌ ينقصها مقاسٌ لا سعرَ لها بعد،
         * فيُطوى المجموعُ حتّى يُختار — لا رقمٌ أقلُّ ممّا سيدفع.
         */
        function repaint() {
            var sum = 0, known = true;
            var unit = opts.mainAmount ? opts.mainAmount() : null;
            var mainQty = opts.mainQty ? opts.mainQty() : 0;
            if (unit != null) sum += unit * mainQty; else known = false;

            box.querySelectorAll('[data-rb-up]').forEach(function (card) {
                var qty = qtyOf(card);
                var line = card.querySelector('[data-rb-up-line]');
                var each = unitOf(card);
                if (!qty || each == null) {
                    if (qty) known = false;
                    if (line) line.textContent = '';

                    return;
                }
                sum += each * qty;
                if (line) line.textContent = qty + ' × ' + format(each) + ' = ' + format(each * qty);
            });

            if (opts.total) {
                opts.total.hidden = !known;
                var out = opts.total.querySelector('[data-rb-up-total-text]');
                if (out) out.textContent = known ? format(sum) : '';
            }
        }

        box.addEventListener('click', function (e) {
            var size = e.target.closest('[data-rb-up-size]');
            if (size) {
                var owner = size.closest('[data-rb-up]');
                owner.querySelectorAll('[data-rb-up-size]').forEach(function (b) { b.classList.remove('on'); b.setAttribute('aria-pressed', 'false'); });
                size.classList.add('on'); size.setAttribute('aria-pressed', 'true');
                owner.setAttribute('data-variant', size.getAttribute('data-rb-up-size'));
                show(owner.querySelector('[data-rb-up-need]'), false);
                repaint();

                return;
            }
            var step = e.target.closest('[data-rb-up-inc], [data-rb-up-dec]');
            if (step) {
                var stepped = step.closest('[data-rb-up]');
                setQty(stepped, qtyOf(stepped) + (step.hasAttribute('data-rb-up-inc') ? 1 : -1), opts.max);
                repaint();

                return;
            }
            var pick = e.target.closest('[data-rb-up-pick]');
            if (pick) {
                var card = pick.closest('[data-rb-up]');
                // الضغطُ على البطاقة اختيارٌ بواحدٍ أو تركٌ — والعدّادُ لما بعده
                setQty(card, qtyOf(card) > 0 ? 0 : 1, opts.max);
                repaint();
            }
        });

        /* ضغطةٌ واحدةٌ تُضيف مرّةً واحدة: الزرُّ مقفلٌ حتّى يعود نصُّه */
        var busy = false;
        opts.button.addEventListener('click', function () {
            if (busy) return;
            // وصنفٌ ملاحظتُه مردودةٌ لا يدخل السلّة — والخطأُ تحت خانتها (`ribbon-notes.js`)
            var main = opts.main();
            if (!main) return;
            var picks = picked(box);
            var r = compose(main, picks);
            picks.forEach(function (p) { flag(p, false); });
            if (!r.ok) {
                r.missing.forEach(function (p) { flag(p, true); });
                opts.missing(r.missing.map(function (p) { return p.card; }));

                return;
            }
            busy = true; opts.button.disabled = true;
            var added = opts.addAll(r.lines) || { ok: true };
            if (!added.ok) {
                // ولا يدخل شيء — ويبقى ما اختاره كما هو ليُعدّله
                busy = false; opts.button.disabled = false;
                if (opts.refused) opts.refused(added.message || '');

                return;
            }
            // ما دخل السلّة لا يبقى مختارًا — فلا تُعيده ضغطةٌ تالية بلا قصد
            picks.forEach(function (p) { setQty(p.card, 0, opts.max); });
            repaint();
            opts.done();
            setTimeout(function () { busy = false; opts.button.disabled = false; }, opts.wait || 1500);
        });

        repaint();

        return { repaint: repaint };
    }

    return { mount: mount, compose: compose, money: money };
})();

/*
 * «إضافات اختيارية» في صفحة صنف RIBBON — إضافاتُ الصنف نفسِه، تُحمل على بنده.
 *
 * ═══ وليست «أضف مع طلبك» ═══
 *
 * تلك (`ribbon-upsells.js`) أصنافٌ مستقلّةٌ تدخل السلّةَ بنودًا لها. وهذه
 * إضافاتُ المنتج (`addons`) التي فتحها صاحبُ المحلّ للموقع: تدخل مع بند الصنف
 * نفسِه (`{id, variant_id, qty, addons: [{id, qty}]}`)، ويكتبها الخادمُ على
 * بنده لقطاتٍ (`order_item_addons`). فلا يُباع شيءٌ مرّتين.
 *
 * ═══ والكمّيّةُ للبند كلِّه ═══
 *
 * «شوكولاتة ×١» على باقتين واحدةٌ لا اثنتان — قاعدةُ الصندوق نفسُها
 * (`SaleLines::addonConsumption`). فالمجموعُ هنا ثمنُ الصنف في كمّيّته ثمّ
 * ثمنُ كلّ إضافةٍ في كمّيّتها هي، مرّةً. وتقول الصفحةُ ذلك تحت العنوان.
 *
 * ═══ ولا سعرَ يُصدَّق من هنا ═══
 *
 * الأسعارُ على البطاقات (`data-amount`) كتبها الخادمُ للعرض، والإتمامُ يسعّر
 * كلَّ إضافةٍ من القاعدة ويأذن بها ببابه (`ProductAddons::allows`). والحسابُ
 * بالألف صحيحًا (×١٠٠٠) لا بكسورٍ عائمة.
 *
 * يُضمَّن في الصفحة كما هو (`_addons.blade.php`)، ويستورده Vitest فيضغط عليه.
 */
window.RBAddons = (function () {
    function qtyOf(card) {
        var n = parseInt(card.getAttribute('data-qty') || '0', 10);

        return n > 0 ? n : 0;
    }

    function mills(value) {
        return Math.round((+value || 0) * 1000);
    }

    /*
     * opts:
     *   mainAmount() ← سعرُ وحدة الصنف (المقاسُ المختار)، mainQty() ← كمّيّتُه،
     *   format(amount) ← نصّ، total — عنصرُ «إجمالي البند»، max — حدُّ كمّيّة
     *   الإضافة (`WebCheckout::MAX_ADDON_QTY`)، changed() — يُنادى بعد كلّ تبديل.
     *
     * ويردّ `{ chosen, total, clear, repaint }`.
     */
    function mount(box, opts) {
        opts = opts || {};
        var none = { chosen: function () { return []; }, total: function () { return 0; }, clear: function () {}, repaint: function () {} };
        if (!box) return none;

        var max = opts.max || 20;
        var format = opts.format || function (v) { return String(v); };

        function cards() {
            return Array.prototype.slice.call(box.querySelectorAll('[data-rb-addon]'));
        }

        // ما اختير — مرتّبًا بالمعرّف، كما يحفظه `RBLines.tidy` فتطابق هويّةُ البند
        function chosen() {
            return cards()
                .filter(function (c) { return qtyOf(c) > 0; })
                .map(function (c) { return { id: +c.getAttribute('data-rb-addon'), qty: qtyOf(c) }; })
                .sort(function (a, b) { return a.id - b.id; });
        }

        function totalMills() {
            return cards().reduce(function (s, c) { return s + qtyOf(c) * mills(c.getAttribute('data-amount')); }, 0);
        }

        function paint(card) {
            var qty = qtyOf(card);
            var count = card.querySelector('[data-rb-addon-count]');
            var dec = card.querySelector('[data-rb-addon-dec]');
            var inc = card.querySelector('[data-rb-addon-inc]');
            if (count) count.textContent = String(qty);
            if (dec) dec.disabled = qty <= 0;
            if (inc) inc.disabled = qty >= max;
            card.classList.toggle('is-on', qty > 0);
        }

        /*
         * «إجمالي البند» يظهر حين تُختار إضافة — ثمنُ الصنف في كمّيّته
         * وثمنُ ما اختير معه. ويُطوى ما دام سعرُ الصنف لم يُعرف.
         */
        function repaint() {
            cards().forEach(paint);
            if (opts.total) {
                var unit = opts.mainAmount ? opts.mainAmount() : null;
                var mainQty = opts.mainQty ? opts.mainQty() : 1;
                var shown = chosen().length > 0 && unit != null;
                opts.total.hidden = !shown;
                var out = opts.total.querySelector('[data-rb-line-total-text]');
                if (out) out.textContent = shown ? format((mills(unit) * mainQty + totalMills()) / 1000) : '';
            }
            if (opts.changed) opts.changed();
        }

        box.addEventListener('click', function (e) {
            var card = e.target.closest('[data-rb-addon]');
            if (!card || card.hasAttribute('data-soldout')) return;
            var qty = qtyOf(card);
            if (e.target.closest('[data-rb-addon-inc]')) qty = Math.min(max, qty + 1);
            else if (e.target.closest('[data-rb-addon-dec]')) qty = Math.max(0, qty - 1);
            else return;
            card.setAttribute('data-qty', String(qty));
            repaint();
        });

        function clear() {
            cards().forEach(function (c) { c.setAttribute('data-qty', '0'); });
            repaint();
        }

        repaint();

        return {
            chosen: chosen,
            total: function () { return totalMills() / 1000; },
            clear: clear,
            repaint: repaint,
        };
    }

    return { mount: mount };
})();

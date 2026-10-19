/*
 * بنودُ سلّة RIBBON — من هو البندُ نفسُه، وكيف يُضاف ويُعدَّل.
 *
 * ═══ والنصُّ من هويّة البند ═══
 *
 * كان البندُ صنفًا ومقاسًا: كرتان بنصّين يُدمجان بندًا واحدًا فيضيع
 * أحدُهما. فصار النصُّ (`note`، نصُّ كرت الهدية) من الهويّة: النصُّ نفسُه
 * يزيد الكمّيّة، ونصٌّ آخرُ بندٌ آخر. وما لا نصَّ له يبقى كما كان.
 *
 * والملفُّ يُضمَّن في `layout.blade.php` كما هو ويستورده Vitest.
 * و`RB.add`/`RB.set` يقرآن منه.
 */
window.RBLines = (function () {
    function same(it, id, variantId, note) {
        return it.id === id
            && (it.variant_id || null) === (variantId || null)
            && (it.note || '') === (note || '');
    }

    function add(items, id, variantId, qty, note) {
        var hit = null;
        items.forEach(function (it) { if (same(it, id, variantId, note)) hit = it; });
        if (hit) { hit.qty += qty; return items; }
        var row = { id: id, variant_id: variantId || null, qty: qty };
        if (note) row.note = note;
        items.push(row);
        return items;
    }

    function set(items, id, variantId, qty, note) {
        return items
            .map(function (it) { if (same(it, id, variantId, note)) it.qty = qty; return it; })
            .filter(function (it) { return it.qty > 0; });
    }

    /*
     * بنودٌ معًا — كلُّها أو لا شيء.
     *
     * تُضاف على نسخةٍ من السلّة لا على السلّة نفسِها، ثمّ تُسأل الحدود:
     * كمّيّةُ كلّ بندٍ مسَّته الإضافة (`limits.qty`) وعددُ البنود (`limits.lines`)
     * — حدّا الخادم نفسُهما (`WebCheckout::MAX_QTY` و`MAX_LINES`). فإن تجاوز
     * أحدُهما رُدّت كلُّها وبقيت السلّةُ كما كانت، ويُقال أيُّ الحدّين.
     *
     * ولولا هذا لَدخل الصنفُ وبعضُ ما معه ثمّ قصّ الخادمُ الباقي بلا كلمة.
     *
     * @return {ok: true, items} | {ok: false, reason: 'qty'|'lines', max}
     */
    function merge(items, lines, limits) {
        var copy = items.map(function (it) { return Object.assign({}, it); });
        var touched = [];

        lines.forEach(function (l) {
            copy = add(copy, l.id, l.variant_id, l.qty, l.note);
            touched.push(l);
        });

        var maxQty = limits && limits.qty ? limits.qty : Infinity;
        var maxLines = limits && limits.lines ? limits.lines : Infinity;

        var over = touched.some(function (l) {
            return copy.some(function (it) { return same(it, l.id, l.variant_id, l.note) && it.qty > maxQty; });
        });
        if (over) return { ok: false, reason: 'qty', max: maxQty };
        if (copy.length > maxLines) return { ok: false, reason: 'lines', max: maxLines };

        return { ok: true, items: copy };
    }

    return { same: same, add: add, set: set, merge: merge };
})();

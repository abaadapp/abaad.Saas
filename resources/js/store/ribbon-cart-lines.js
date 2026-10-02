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

    return { same: same, add: add, set: set };
})();

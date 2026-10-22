/*
 * بنودُ سلّة RIBBON — من هو البندُ نفسُه، وكيف يُضاف ويُعدَّل.
 *
 * ═══ والنصُّ من هويّة البند ═══
 *
 * كان البندُ صنفًا ومقاسًا: كرتان بنصّين يُدمجان بندًا واحدًا فيضيع
 * أحدُهما. فصار النصُّ (`note`، نصُّ كرت الهدية) من الهويّة: النصُّ نفسُه
 * يزيد الكمّيّة، ونصٌّ آخرُ بندٌ آخر. وما لا نصَّ له يبقى كما كان.
 *
 * ═══ والإضافاتُ من هويّته كذلك ═══
 *
 * باقةٌ بشوكولاتة وباقةٌ بلا شيء بندان: لو دُمجتا لَصارت الشوكولاتةُ على
 * الاثنتين أو ضاعت عن واحدة. فإضافاتُ البند (معرّفٌ وكمّيّة) من هويّته:
 * الإضافاتُ نفسُها تزيد الكمّيّة، وغيرُها بندٌ آخر. وترتيبُها لا يغيّرها.
 * وبندٌ بلا إضافات هو بندُ ما قبلها — سلّةٌ قديمةٌ تبقى كما كانت.
 *
 * والملفُّ يُضمَّن في `layout.blade.php` كما هو ويستورده Vitest.
 * و`RB.add`/`RB.set` يقرآن منه.
 */
window.RBLines = (function () {
    // الإضافاتُ كما تُحفظ: معرّفٌ وكمّيّةٌ موجبة، مرتّبةً بالمعرّف — ولا سعرَ فيها
    function tidy(addons) {
        return (addons || [])
            .filter(function (a) { return a && +a.id > 0 && +a.qty > 0; })
            .map(function (a) { return { id: +a.id, qty: +a.qty }; })
            .sort(function (a, b) { return a.id - b.id; });
    }

    function key(addons) {
        return tidy(addons).map(function (a) { return a.id + ':' + a.qty; }).join(',');
    }

    function same(it, id, variantId, note, addons) {
        return it.id === id
            && (it.variant_id || null) === (variantId || null)
            && (it.note || '') === (note || '')
            && key(it.addons) === key(addons);
    }

    function add(items, id, variantId, qty, note, addons) {
        var hit = null;
        items.forEach(function (it) { if (same(it, id, variantId, note, addons)) hit = it; });
        if (hit) { hit.qty += qty; return items; }
        var row = { id: id, variant_id: variantId || null, qty: qty };
        if (note) row.note = note;
        var chosen = tidy(addons);
        if (chosen.length) row.addons = chosen;
        items.push(row);
        return items;
    }

    function set(items, id, variantId, qty, note, addons) {
        return items
            .map(function (it) { if (same(it, id, variantId, note, addons)) it.qty = qty; return it; })
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
            copy = add(copy, l.id, l.variant_id, l.qty, l.note, l.addons);
            touched.push(l);
        });

        var maxQty = limits && limits.qty ? limits.qty : Infinity;
        var maxLines = limits && limits.lines ? limits.lines : Infinity;

        var over = touched.some(function (l) {
            return copy.some(function (it) { return same(it, l.id, l.variant_id, l.note, l.addons) && it.qty > maxQty; });
        });
        if (over) return { ok: false, reason: 'qty', max: maxQty };
        if (copy.length > maxLines) return { ok: false, reason: 'lines', max: maxLines };

        return { ok: true, items: copy };
    }

    return { same: same, add: add, set: set, merge: merge, tidy: tidy, key: key };
})();

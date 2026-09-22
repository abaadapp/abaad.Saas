@extends('store.ribbon.layout')
@section('title', $t['cartTitle'].' — '.$business->name)
@section('content')
<section class="rb-screen" style="max-width:760px;margin:0 auto;padding:40px 24px" data-testid="rb-cart-page">
    <h1 class="rb-h1" style="margin-bottom:24px">{{ $t['cartTitle'] }}</h1>
    <div data-rb-empty style="display:none;text-align:center;padding:60px 0">
        <p style="margin:0 0 20px">{{ $t['cartEmpty'] }}</p>
        <a class="rb-btn-ghost" href="{{ $base }}/shop">{{ $t['continueShopping'] }}</a>
    </div>
    <div data-rb-full style="display:none">
        <div data-rb-lines style="display:flex;flex-direction:column;border-top:1px solid var(--rb-line)"></div>
        <div data-rb-errors class="rb-error"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:24px 0;font-size:16px">
            <span>{{ $t['subtotal'] }}</span>
            <span style="font-size:20px;font-weight:600" data-rb-subtotal></span>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
            <a class="rb-btn" href="{{ $base }}/checkout" style="flex:1;min-width:200px" data-testid="rb-to-checkout">{{ $t['toCheckout'] }}</a>
            <a class="rb-btn-ghost" href="{{ $base }}/shop" style="border-color:var(--rb-border)">{{ $t['continueShopping'] }}</a>
        </div>
    </div>
</section>
@endsection
@section('scripts')
<script>
(function () {
    var T = @json($t);
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function render() {
        var items = RB.read();
        document.querySelector('[data-rb-empty]').style.display = items.length ? 'none' : 'block';
        document.querySelector('[data-rb-full]').style.display = items.length ? 'block' : 'none';
        if (!items.length) return;
        RB.quote().then(function (q) {
            var box = document.querySelector('[data-rb-lines]'), err = document.querySelector('[data-rb-errors]');
            err.textContent = '';
            if (!q.ok) { err.textContent = Object.values(q.errors || {}).flat().join(' · '); box.innerHTML = ''; return; }
            box.innerHTML = q.lines.map(function (l) {
                return '<div style="display:grid;grid-template-columns:88px 1fr auto;gap:18px;align-items:center;padding:18px 0;border-bottom:1px solid var(--rb-line)" data-rb-line data-id="' + l.id + '" data-variant="' + (l.variant_id || '') + '">'
                    + '<div style="width:88px;height:88px;border-radius:var(--rb-r);overflow:hidden;background:var(--rb-soft)">' + (l.image ? '<img src="' + esc(l.image) + '" alt="" style="width:100%;height:100%;object-fit:cover">' : '') + '</div>'
                    + '<div><div style="font-size:15px">' + esc(l.name) + '</div>' + (l.variant ? '<div style="font-size:13px;margin-top:2px">' + esc(l.variant) + '</div>' : '')
                    + '<div style="display:flex;align-items:center;gap:14px;margin-top:10px"><div class="rb-qty" style="height:34px"><button type="button" data-dec style="width:36px;height:34px">−</button><span style="min-width:24px;font-size:14px">' + l.qty + '</span><button type="button" data-inc style="width:36px;height:34px">+</button></div>'
                    + '<button type="button" data-remove style="border:0;background:transparent;color:#8a6d3b;font-size:13px;cursor:pointer;padding:0;text-decoration:underline">' + esc(T.remove) + '</button></div></div>'
                    + '<div style="font-size:17px;font-weight:600">' + esc(l.line_text) + '</div></div>';
            }).join('');
            document.querySelector('[data-rb-subtotal]').textContent = q.subtotal_text;
        });
    }
    document.addEventListener('click', function (e) {
        var row = e.target.closest('[data-rb-line]'); if (!row) return;
        var id = +row.dataset.id, v = row.dataset.variant ? +row.dataset.variant : null;
        var cur = (RB.read().find(function (it) { return it.id === id && (it.variant_id || null) === v; }) || { qty: 0 }).qty;
        if (e.target.closest('[data-inc]')) RB.set(id, v, cur + 1);
        else if (e.target.closest('[data-dec]')) RB.set(id, v, Math.max(1, cur - 1));
        else if (e.target.closest('[data-remove]')) RB.set(id, v, 0);
        else return;
        render();
    });
    render();
})();
</script>
@endsection

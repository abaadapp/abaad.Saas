@extends('store.ribbon.layout')
@section('title', $t['checkoutTitle'].' — '.$business->name)
@section('content')
<section class="rb-screen rb-wrap" style="padding:40px 24px" data-testid="rb-checkout-page">
    <h1 class="rb-h1" style="margin-bottom:28px">{{ $t['checkoutTitle'] }}</h1>
    @if (! $accepts)
        <div class="rb-box" data-testid="rb-closed">{{ $t['closed'] }}</div>
    @else
    <form data-rb-form novalidate style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:48px;align-items:start">
        <div style="display:flex;flex-direction:column;gap:36px;min-width:0">
            @php $step = fn ($n, $title) => '<h2 style="margin:0 0 14px;font-size:17px;font-weight:500;display:flex;align-items:center;gap:10px"><span style="width:24px;height:24px;border-radius:50%;background:var(--rb-olive);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px">'.$n.'</span>'.e($title).'</h2>'; @endphp
            <div>
                {!! $step(1, $t['s1']) !!}
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    <div><input class="rb-input" name="name" placeholder="{{ $t['fName'] }}" aria-label="{{ $t['fName'] }}"><div class="rb-error" data-err="name"></div></div>
                    <div><input class="rb-input" name="phone" dir="ltr" placeholder="{{ $t['fPhone'] }}" aria-label="{{ $t['fPhone'] }}"><div class="rb-error" data-err="phone"></div></div>
                </div>
            </div>
            <div>
                {!! $step(2, $t['s2']) !!}
                <div style="display:flex;gap:8px;margin-bottom:14px" data-rb-fulfil>
                    <button type="button" class="rb-pill on" data-v="delivery" style="flex:1;height:46px">{{ $t['delivery'] }}</button>
                    <button type="button" class="rb-pill" data-v="pickup" style="flex:1;height:46px">{{ $t['pickup'] }}</button>
                </div>
                <div data-rb-delivery style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    <div>
                        @if (count($delivery['areas']))
                            <select class="rb-input" name="area" aria-label="{{ $t['fArea'] }}">
                                <option value="">{{ $t['fArea'] }}</option>
                                @foreach ($delivery['areas'] as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
                            </select>
                        @else
                            <input class="rb-input" name="area" placeholder="{{ $t['fArea'] }}" aria-label="{{ $t['fArea'] }}">
                        @endif
                        <div class="rb-error" data-err="area"></div>
                    </div>
                    <div><input class="rb-input" name="address" placeholder="{{ $t['fAddress'] }}" aria-label="{{ $t['fAddress'] }}"><div class="rb-error" data-err="address"></div></div>
                </div>
                <div data-rb-pickup style="display:none;border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px;font-size:14px;line-height:1.7">{{ $t['pickupAddr'] }}@if ($identity['address'] !== '') — {{ $identity['address'] }}@endif @if ($hours !== '') · {{ $hours }}@endif</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:12px">
                    <div><input class="rb-input" type="date" name="date" min="{{ $minDate }}" max="{{ $maxDate }}" aria-label="{{ $t['date'] }}"><div class="rb-error" data-err="date"></div></div>
                    @if (count($delivery['slots']))
                        <div>
                            <select class="rb-input" name="slot" aria-label="{{ $t['slot'] }}">
                                @foreach ($delivery['slots'] as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                            </select>
                            <div class="rb-error" data-err="slot"></div>
                        </div>
                    @endif
                </div>
            </div>
            <div>
                {!! $step(3, $t['s3']) !!}
                <textarea class="rb-input" name="card" rows="3" maxlength="500" placeholder="{{ $t['fCard'] }}" aria-label="{{ $t['fCard'] }}"></textarea>
                <div style="font-size:12px;margin-top:6px">{{ $t['cardHint'] }}</div>
            </div>
            <div>
                {!! $step(4, $t['s4']) !!}
                <div style="display:flex;flex-direction:column;gap:8px" data-rb-pay>
                    @foreach ($payments as $i => $p)
                        <button type="button" class="rb-payopt {{ $i === 0 ? 'on' : '' }}" data-v="{{ $p }}" style="display:flex;align-items:center;gap:12px;height:52px;border:1px solid var(--rb-border);border-radius:var(--rb-r);background:#fff;padding:0 16px;font-size:14px;cursor:pointer;text-align:start">
                            <span style="width:18px;height:18px;border-radius:50%;border:1.5px solid var(--rb-olive);display:inline-flex;align-items:center;justify-content:center;flex:none"><span class="dot" style="width:10px;height:10px;border-radius:50%"></span></span>
                            <span style="flex:1">{{ $p === 'transfer' ? $t['payBank'] : $t['payCod'] }}</span>
                            <span style="font-size:12px">{{ $p === 'transfer' ? $t['payBankNote'] : $t['payCodNote'] }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="rb-error" data-err="pay"></div>
                <div data-rb-banknote style="display:none;border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px;font-size:14px;line-height:1.8;margin-top:12px">{{ $t['bankNote'] }}</div>
            </div>
        </div>
        <aside class="rb-box">
            <h2 style="margin:0 0 16px;font-size:17px;font-weight:500">{{ $t['summary'] }}</h2>
            <div data-rb-summary style="display:flex;flex-direction:column;gap:12px;border-bottom:1px solid var(--rb-line);padding-bottom:16px;font-size:14px"></div>
            <div style="display:flex;gap:8px;margin:16px 0">
                <input class="rb-input" name="promo" placeholder="{{ $t['promo'] }}" aria-label="{{ $t['promo'] }}" style="flex:1">
                <button type="button" class="rb-btn-ghost" data-rb-apply style="height:46px;padding:0 16px">{{ $t['apply'] }}</button>
            </div>
            <div class="rb-error" data-rb-promo-msg></div>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:14px;margin-top:8px">
                <div style="display:flex;justify-content:space-between"><span>{{ $t['subtotal'] }}</span><span data-rb-subtotal></span></div>
                <div style="display:none;justify-content:space-between" data-rb-discount-row><span>{{ $t['discount'] }}</span><span data-rb-discount></span></div>
                <div style="display:none;justify-content:space-between" data-rb-tax-row><span>{{ $t['tax'] }}</span><span data-rb-tax></span></div>
                <div style="display:flex;justify-content:space-between"><span>{{ $t['shipping'] }}</span><span data-rb-ship></span></div>
                <div style="display:flex;justify-content:space-between;border-top:1px solid var(--rb-line);padding-top:12px;font-size:16px"><span>{{ $t['total'] }}</span><strong data-rb-total></strong></div>
            </div>
            <div class="rb-error" data-rb-form-error style="margin-top:12px"></div>
            <button type="submit" class="rb-btn" style="width:100%;margin-top:16px" data-testid="rb-place"><span data-rb-place-label>{{ $t['place'] }}</span></button>
        </aside>
    </form>
    @endif
</section>
@endsection
@section('scripts')
@if ($accepts)
<script>
(function () {
    var T = @json($t), fulfil = 'delivery', pay = @json($payments[0] ?? null), promo = '';
    var form = document.querySelector('[data-rb-form]');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function $(sel) { return form.querySelector(sel); }
    function paintPay() {
        form.querySelectorAll('.rb-payopt').forEach(function (b) {
            var on = b.dataset.v === pay; b.classList.toggle('on', on);
            b.style.borderColor = on ? 'var(--rb-olive)' : 'var(--rb-border)'; b.style.background = on ? 'var(--rb-soft)' : '#fff';
            b.querySelector('.dot').style.background = on ? 'var(--rb-olive)' : 'transparent';
        });
        $('[data-rb-banknote]').style.display = pay === 'transfer' ? 'block' : 'none';
    }
    function refresh() {
        if (!RB.read().length) { location.href = RB.base + '/cart'; return; }
        RB.quote({ fulfil: fulfil, promo: promo }).then(function (q) {
            var msg = $('[data-rb-promo-msg]'); msg.textContent = '';
            if (!q.ok) { $('[data-rb-form-error]').textContent = Object.values(q.errors || {}).flat().join(' · '); return; }
            $('[data-rb-summary]').innerHTML = q.lines.map(function (l) {
                return '<div style="display:flex;justify-content:space-between;gap:12px"><span>' + esc(l.name) + (l.variant ? ' · ' + esc(l.variant) : '') + ' × ' + l.qty + '</span><span>' + esc(l.line_text) + '</span></div>';
            }).join('');
            $('[data-rb-subtotal]').textContent = q.subtotal_text;
            $('[data-rb-discount-row]').style.display = q.discount > 0 ? 'flex' : 'none'; $('[data-rb-discount]').textContent = '− ' + q.discount_text;
            $('[data-rb-tax-row]').style.display = q.tax > 0 ? 'flex' : 'none'; $('[data-rb-tax]').textContent = q.tax_text;
            $('[data-rb-ship]').textContent = q.delivery > 0 ? q.delivery_text : T.free;
            $('[data-rb-total]').textContent = q.total_text;
            $('[data-rb-place-label]').textContent = T.place + ' · ' + q.total_text;
            if (q.promo_error) { msg.textContent = q.promo_error; } else if (q.coupon) { msg.style.color = 'var(--rb-olive)'; msg.textContent = '✓ ' + q.coupon; }
        });
    }
    $('[data-rb-fulfil]').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        fulfil = b.dataset.v;
        form.querySelectorAll('[data-rb-fulfil] button').forEach(function (x) { x.classList.toggle('on', x === b); });
        $('[data-rb-delivery]').style.display = fulfil === 'delivery' ? 'grid' : 'none';
        $('[data-rb-pickup]').style.display = fulfil === 'pickup' ? 'block' : 'none';
        refresh();
    });
    $('[data-rb-pay]').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; pay = b.dataset.v; paintPay(); });
    $('[data-rb-apply]').addEventListener('click', function () { promo = $('[name=promo]').value.trim(); refresh(); });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        form.querySelectorAll('[data-err]').forEach(function (d) { d.textContent = ''; });
        form.querySelectorAll('.rb-input').forEach(function (i) { i.classList.remove('err'); });
        $('[data-rb-form-error]').textContent = '';
        var btn = $('[data-testid=rb-place]'); btn.disabled = true;
        var payload = {
            items: RB.read(), fulfil: fulfil, pay: pay, promo: promo,
            name: $('[name=name]').value, phone: $('[name=phone]').value,
            area: $('[name=area]') ? $('[name=area]').value : '', address: $('[name=address]').value,
            date: $('[name=date]').value, slot: $('[name=slot]') ? $('[name=slot]').value : '', card: $('[name=card]').value,
        };
        RB.post('/checkout', payload).then(function (r) {
            btn.disabled = false;
            if (r.ok) { RB.clear(); location.href = r.redirect; return; }
            var errs = r.errors || {}; var any = false;
            Object.keys(errs).forEach(function (k) {
                var d = form.querySelector('[data-err="' + k + '"]'); var i = form.querySelector('[name="' + k + '"]');
                if (d) { d.textContent = errs[k].join(' '); any = true; } if (i) i.classList.add('err');
            });
            $('[data-rb-form-error]').textContent = any ? T.errReq : Object.values(errs).flat().join(' · ');
        }).catch(function () { btn.disabled = false; $('[data-rb-form-error]').textContent = T.errReq; });
    });
    paintPay(); refresh();
})();
</script>
@endif
@endsection

{{-- بطاقةُ صنفٍ في الشبكة — صورتُه إن كانت، وإلّا خطوطُ التصميم بلونٍ من لوحته --}}
{{--
    و«استفسر عبر واتساب» لمن شُغّل له الزرّ — باسم الصنف ورابطه، بلا فتح صفحته
    (`StoreWhatsapp::product`). بجوار رابط البطاقة لا داخله: رابطٌ داخل رابطٍ لا يصحّ.
--}}
@php($rbAsk = ($waNumber ?? '') !== '')
@if ($rbAsk)<div class="rb-card-wrap">@endif
<a href="{{ $base }}/p/{{ $p['id'] }}" class="rb-card" data-testid="rb-product">
    <div class="rb-img">
        @if ($p['image'])
            <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" loading="lazy">
        @else
            <div class="rb-stripes" style="--tint: {{ $p['tint'] }}"></div>
        @endif
    </div>
    <div class="rb-name">{{ $p['name'] }}</div>
    <div class="rb-price">@if ($p['from'])<span style="font-size:12px;font-weight:400">{{ $t['from'] }}</span> @endif{{ $p['price_text'] }}</div>
</a>
@if ($rbAsk)
    <a class="rb-card-wa" href="{{ \App\Support\Store\StoreWhatsapp::url($waNumber, \App\Support\Store\StoreWhatsapp::product($t, $p['name'], \App\Support\Store\StoreWhatsapp::productLink($base, (int) $p['id']))) }}" target="_blank" rel="noopener" aria-label="{{ $t['waAskCard'] }}: {{ $p['name'] }}" title="{{ $t['waAskCard'] }}" data-testid="rb-card-wa"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#rb-wa-icon"/></svg></a>
</div>
@endif

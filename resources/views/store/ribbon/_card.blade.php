{{-- بطاقةُ صنفٍ في الشبكة — صورتُه إن كانت، وإلّا خطوطُ التصميم بلونٍ من لوحته --}}
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

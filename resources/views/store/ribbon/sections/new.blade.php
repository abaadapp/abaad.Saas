{{-- New arrivals --}}
    @if (count($new))
    <div class="rb-section" data-testid="rb-sec-new">
        <div class="rb-section-head">
            <h2 class="rb-h2">{{ $t['newTitle'] }}</h2>
            <a class="rb-link" href="{{ $base }}/shop">{{ $t['viewAll'] }}</a>
        </div>
        <div class="rb-grid">
            @foreach ($new as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    </div>
    @endif

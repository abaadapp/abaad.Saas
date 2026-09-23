{{-- Best sellers --}}
    @if (count($best))
    <div class="rb-section" data-testid="rb-sec-best">
        <div class="rb-section-head">
            <h2 class="rb-h2">{{ $t['bestTitle'] }}</h2>
            <a class="rb-link" href="{{ $base }}/shop">{{ $t['viewAll'] }}</a>
        </div>
        <div class="rb-grid" data-testid="rb-best">
            @foreach ($best as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    </div>
    @endif

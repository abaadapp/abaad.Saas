{{-- Categories --}}
    @if (count($categories))
    <div id="rb-cats" class="rb-section" data-testid="rb-sec-cats">
        <div class="rb-section-head"><h2 class="rb-h2">{{ $t['catsTitle'] }}</h2></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,160px),1fr));gap:16px">
            @foreach ($categories as $i => $c)
                <a href="{{ $base }}/shop?cat={{ $c['id'] }}" style="display:block;background:#fff;border:1px solid var(--rb-line);border-radius:var(--rb-r-lg);overflow:hidden;color:inherit" data-testid="rb-cat">
                    <div style="aspect-ratio:4/3;background:repeating-linear-gradient(135deg,{{ ['#f2d6dc','#d9e3e0','#e6dcc8','#e3d5e4'][$i % 4] }} 0 12px,#f3efe9 12px 24px)"></div>
                    <div style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;min-height:44px">
                        <span style="font-size:15px">{{ $c['name'] }}</span>
                        <span style="font-size:16px">{{ $dir === 'rtl' ? '←' : '→' }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
    @endif

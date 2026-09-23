{{-- About --}}
    @if ($identity['about'] !== '')
    <div class="rb-section" data-testid="rb-sec-about">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:40px;align-items:center">
            <div style="display:flex;flex-direction:column;gap:14px">
                <div style="font-size:12px;letter-spacing:.22em">{{ $t['aboutKicker'] }}</div>
                <h2 class="rb-h2">{{ $business->name }}</h2>
                <p style="margin:0;font-size:15px;line-height:1.8;text-wrap:pretty">{{ $identity['about'] }}</p>
            </div>
            <div style="aspect-ratio:4/3;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)">
                @if ($logo)<img src="{{ $logo }}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">@endif
            </div>
        </div>
    </div>
    @endif

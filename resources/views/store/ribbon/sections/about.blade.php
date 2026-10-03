{{-- About --}}
    @if ($identity['about'] !== '')
    <div class="rb-section" data-testid="rb-sec-about">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:40px;align-items:center">
            <div style="display:flex;flex-direction:column;gap:14px">
                <div class="rb-track" style="font-size:12px;--rb-track:.22em">{{ $t['aboutKicker'] }}</div>
                <h2 class="rb-h2">{{ $business->name }}</h2>
                <p style="margin:0;font-size:15px;line-height:1.8;text-wrap:pretty">{{ $identity['about'] }}</p>
            </div>
            {{-- والشعارُ كاملًا بنسبته — لا يُكبَّر في ٤:٣ ولا يُقصّ. وبلا شعارٍ يبقى الإطارُ كما كان --}}
            @if ($logo)
                <div style="border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)" data-testid="rb-sec-about-frame">
                    <img src="{{ $logo }}" alt="" style="width:100%;height:auto;display:block" data-testid="rb-sec-about-image">
                </div>
            @else
                <div style="aspect-ratio:4/3;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)"></div>
            @endif
        </div>
    </div>
    @endif

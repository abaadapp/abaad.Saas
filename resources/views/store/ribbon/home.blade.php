@extends('store.ribbon.layout')
@section('title', $business->name)
@section('content')
<section class="rb-screen" data-testid="rb-landing">
    {{-- Hero --}}
    <div style="background:var(--rb-soft)">
        <div class="rb-wrap" style="padding:clamp(32px,5vw,72px) 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(28px,4vw,56px);align-items:center">
            <div style="display:flex;flex-direction:column;gap:20px">
                <div style="font-size:12px;letter-spacing:.22em">{{ $t['heroKicker'] }}@if ($identity['city'] !== '') · {{ $identity['city'] }}@endif</div>
                <h1 style="margin:0;font-size:clamp(32px,4.4vw,58px);line-height:1.15;font-weight:500;text-wrap:balance">{{ $identity['tagline'] !== '' ? $identity['tagline'] : $t['heroTitle'] }}</h1>
                <p style="margin:0;font-size:clamp(15px,1.3vw,18px);line-height:1.7;max-width:520px;text-wrap:pretty">{{ $t['heroSub'] }}</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
                    <a class="rb-btn" href="{{ $base }}/shop">{{ $t['shopNow'] }}</a>
                    <a class="rb-btn-ghost" href="#rb-cats">{{ $t['explore'] }}</a>
                </div>
            </div>
            <div style="aspect-ratio:5/4;border-radius:var(--rb-r-lg);overflow:hidden;background:repeating-linear-gradient(135deg,#e6dcc8 0 14px,#f3efe9 14px 28px);min-height:240px">
                @if (($best[0]['image'] ?? null))
                    <img src="{{ $best[0]['image'] }}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">
                @endif
            </div>
        </div>
    </div>

    {{-- Categories --}}
    @if (count($categories))
    <div id="rb-cats" class="rb-section">
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

    {{-- Best sellers --}}
    @if (count($best))
    <div class="rb-section">
        <div class="rb-section-head">
            <h2 class="rb-h2">{{ $t['bestTitle'] }}</h2>
            <a class="rb-link" href="{{ $base }}/shop">{{ $t['viewAll'] }}</a>
        </div>
        <div class="rb-grid" data-testid="rb-best">
            @foreach ($best as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    </div>
    @endif

    {{-- New arrivals --}}
    @if (count($new))
    <div class="rb-section">
        <div class="rb-section-head">
            <h2 class="rb-h2">{{ $t['newTitle'] }}</h2>
            <a class="rb-link" href="{{ $base }}/shop">{{ $t['viewAll'] }}</a>
        </div>
        <div class="rb-grid">
            @foreach ($new as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    </div>
    @endif

    {{-- Occasions banner --}}
    <div class="rb-section">
        <div style="background:var(--rb-olive);color:var(--rb-cream);border-radius:var(--rb-r-lg);padding:clamp(32px,4vw,56px) clamp(24px,4vw,56px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:32px;align-items:center">
            <div style="display:flex;flex-direction:column;gap:14px">
                <div style="font-size:12px;letter-spacing:.22em;color:#d9d4c0">{{ $t['bannerKicker'] }}</div>
                <h2 style="margin:0;font-size:clamp(24px,3vw,40px);font-weight:500;line-height:1.2">{{ $t['bannerTitle'] }}</h2>
                <p style="margin:0;color:#d9d4c0;line-height:1.8;max-width:520px">{{ $t['bannerSub'] }}</p>
                <div><a href="{{ $base }}/shop" style="display:inline-flex;align-items:center;height:48px;padding:0 24px;background:var(--rb-cream);color:var(--rb-olive);border-radius:var(--rb-r);font-size:14px">{{ $t['bannerBtn'] }}</a></div>
            </div>
            <div style="aspect-ratio:4/3;border-radius:var(--rb-r-lg);background:repeating-linear-gradient(135deg,#6b694c 0 14px,#5f5c43 14px 28px)"></div>
        </div>
    </div>

    {{-- About --}}
    @if ($identity['about'] !== '')
    <div class="rb-section">
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

    {{-- Reviews — ما كُتب فعلًا، وإلّا لا شيء --}}
    @if (count($reviews))
    <div class="rb-section">
        <div class="rb-section-head"><h2 class="rb-h2">{{ $t['reviewsTitle'] }}</h2></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:16px">
            @foreach ($reviews as $r)
                <div class="rb-box">
                    <div style="color:#b8860b;letter-spacing:2px;font-size:13px">{{ str_repeat('★', max(1, min(5, $r['rating']))) }}</div>
                    <p style="margin:10px 0;font-size:15px;line-height:1.7">{{ $r['text'] }}</p>
                    <div style="font-size:13px;color:#555">{{ $r['name'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</section>
@endsection

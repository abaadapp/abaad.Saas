@extends('store.ribbon.layout')
@section('title', $customPage['title'].' — '.$seo['brand'])
@section('content')
{{--
    صفحةٌ كتبها صاحبُ المتجر — `Store\StoreCustomPages`.

    عنوانٌ ونبذة، ثمّ وحداتٌ بترتيبه: عنوانٌ فرعيّ، فقرة، صورة، معرض، قسمٌ من نصٍّ
    وصورة، منتجاتٌ اختارها، مجموعة، زرّ، عرض. والنصوصُ مُهرَّبةٌ وأسطرُها أسطر
    (`pre-line`) — لا وسمَ يُرسم. والصورُ كاملةٌ أو تملأ إطارها كما اختار لكلٍّ
    منها؛ و«كاملة» إطارُها نسبتُها هي، وطولُها محدودٌ بالشاشة فلا تبتلع صورةٌ
    طويلةٌ الصفحةَ كلَّها. والمنتجاتُ بطاقاتُ الرفّ نفسُها بأسعارها وروابطها.
--}}
<style>
    .rb-cp-figure { margin: 0; border-radius: var(--rb-r-lg); overflow: hidden; background: var(--rb-soft); display: flex; align-items: center; justify-content: center; }
    .rb-cp-figure img { display: block; width: 100%; height: 100%; }
    .rb-cp-figure.is-contain img { width: auto; height: auto; max-width: 100%; max-height: min(78vh, 720px); object-fit: contain; }
    .rb-cp-figure.is-cover { aspect-ratio: 16 / 9; }
    .rb-cp-figure.is-cover img { object-fit: cover; }
    .rb-cp-text { margin: 0; font-size: 16px; line-height: 1.9; white-space: pre-line; text-wrap: pretty; overflow-wrap: anywhere; }
    .rb-cp-sec { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: clamp(20px, 4vw, 48px); align-items: center; }
    .rb-cp-sec.is-text-only { grid-template-columns: minmax(0, 760px); justify-content: start; }
    .rb-cp-sec.is-top { grid-template-columns: minmax(0, 1fr); gap: 20px; }
    .rb-cp-sec.is-top > div { max-width: 760px; }
    @media (min-width: 900px) { .rb-cp-sec.is-flip > .rb-cp-figure { order: 2; } }
    .rb-cp-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr)); gap: 16px; align-items: start; }
    .rb-cp-gallery .rb-cp-figure.is-contain img { max-height: min(60vh, 520px); }
    .rb-cp-gallery .rb-cp-figure.is-cover { aspect-ratio: 1 / 1; }
    .rb-cp-promo { background: var(--rb-soft); border-radius: var(--rb-r-lg); padding: clamp(24px, 4vw, 48px); display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: clamp(20px, 4vw, 40px); align-items: center; }
    .rb-cp-promo .rb-cp-figure { background: #fff; }
    .rb-cp-promo .rb-cp-figure.is-contain img { max-height: min(56vh, 460px); }
</style>
<article class="rb-screen" data-testid="rb-custom-page" data-slug="{{ $customPage['slug'] }}" data-kind="{{ $customPage['kind'] }}">
    @if (! $customPage['visible'])
        {{-- والمخفيّةُ تُعايَن وحدها — ويُقال لصاحبها إنّ زبونه لا يراها --}}
        <p class="rb-wrap" style="margin:16px auto 0;padding:10px 24px;font-size:13px;color:#6b655c" data-testid="rb-page-hidden">{{ $t['pageHidden'] }}</p>
    @endif
    {{-- والعنوانُ على حافّة الصور نفسِها — والنبذةُ سطورُها بطولٍ يُقرأ --}}
    <header class="rb-wrap" style="padding:clamp(32px,5vw,64px) 24px 0;display:flex;flex-direction:column;gap:16px">
        <h1 class="rb-h1" style="font-size:clamp(28px,3.6vw,44px);line-height:1.2" data-testid="rb-page-title">{{ $customPage['title'] }}</h1>
        @if ($customPage['intro'] !== '')
            <p class="rb-cp-text" style="font-size:17px;color:#3f3d2c;max-width:760px" data-testid="rb-page-intro">{{ $customPage['intro'] }}</p>
        @endif
    </header>
    @foreach ($customPage['blocks'] as $rbB)
        @switch ($rbB['type'])
            @case ('heading')
                <div class="rb-wrap" style="padding-top:clamp(36px,5vw,56px)" data-testid="rb-page-heading">
                    <h2 class="rb-h2">{{ $rbB['text'] }}</h2>
                </div>
                @break
            @case ('text')
                <div class="rb-wrap" style="padding-top:20px" data-testid="rb-page-text">
                    <p class="rb-cp-text" style="max-width:760px">{{ $rbB['body'] }}</p>
                </div>
                @break
            @case ('image')
                <div class="rb-wrap" style="padding-top:28px">
                    <figure class="rb-cp-figure is-{{ $rbB['image']['fit'] }}" data-testid="rb-page-image" data-fit="{{ $rbB['image']['fit'] }}">
                        <img src="{{ $rbB['image']['url'] }}" alt="" decoding="async" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif
                             @if ($rbB['image']['w']) width="{{ $rbB['image']['w'] }}" height="{{ $rbB['image']['h'] }}" @endif>
                    </figure>
                </div>
                @break
            @case ('gallery')
                <div class="rb-wrap" style="padding-top:28px">
                    <div class="rb-cp-gallery" data-testid="rb-page-gallery">
                        @foreach ($rbB['images'] as $rbImg)
                            <figure class="rb-cp-figure is-{{ $rbImg['fit'] }}" data-testid="rb-page-gallery-image" data-fit="{{ $rbImg['fit'] }}">
                                <img src="{{ $rbImg['url'] }}" alt="" loading="lazy" decoding="async"
                                     @if ($rbImg['w']) width="{{ $rbImg['w'] }}" height="{{ $rbImg['h'] }}" @endif>
                            </figure>
                        @endforeach
                    </div>
                </div>
                @break
            @case ('textimage')
                <section class="rb-section" data-testid="rb-page-section" data-layout="{{ $rbB['layout'] }}">
                    <div class="rb-cp-sec @if (! $rbB['image']) is-text-only @elseif ($rbB['layout'] === 'top') is-top @endif @if ($rbB['flip']) is-flip @endif">
                        @if ($rbB['image'])
                            <figure class="rb-cp-figure is-{{ $rbB['image']['fit'] }}" data-testid="rb-page-section-image" data-fit="{{ $rbB['image']['fit'] }}">
                                <img src="{{ $rbB['image']['url'] }}" alt="" loading="lazy" decoding="async"
                                     @if ($rbB['image']['w']) width="{{ $rbB['image']['w'] }}" height="{{ $rbB['image']['h'] }}" @endif>
                            </figure>
                        @endif
                        @if ($rbB['title'] !== '' || $rbB['body'] !== '')
                            <div style="display:flex;flex-direction:column;gap:12px">
                                @if ($rbB['title'] !== '')
                                    <h2 class="rb-h2">{{ $rbB['title'] }}</h2>
                                @endif
                                @if ($rbB['body'] !== '')
                                    <p class="rb-cp-text">{{ $rbB['body'] }}</p>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>
                @break
            @case ('products')
            @case ('collection')
                {{-- بطاقاتُ الرفّ نفسُها — صورةٌ واسمٌ وسعرٌ ورابطُ المنتج --}}
                <section class="rb-section" data-testid="rb-page-{{ $rbB['type'] }}">
                    @if ($rbB['title'] !== '' || isset($rbB['href']))
                        <div class="rb-section-head">
                            <h2 class="rb-h2">{{ $rbB['title'] }}</h2>
                            @isset ($rbB['href'])
                                <a class="rb-link" href="{{ $rbB['href'] }}" data-testid="rb-page-collection-all">{{ $t['viewAll'] }}</a>
                            @endisset
                        </div>
                    @endif
                    <div class="rb-grid">
                        @foreach ($rbB['cards'] as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
                    </div>
                </section>
                @break
            @case ('button')
                <div class="rb-wrap" style="padding-top:clamp(36px,5vw,56px);display:flex;justify-content:center">
                    <a class="rb-btn" href="{{ $rbB['href'] }}" data-testid="rb-page-cta"{!! $rbB['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' !!}>{{ $rbB['label'] }}</a>
                </div>
                @break
            @case ('promo')
                <section class="rb-section" data-testid="rb-page-promo">
                    <div class="rb-cp-promo">
                        @if ($rbB['image'])
                            <figure class="rb-cp-figure is-{{ $rbB['image']['fit'] }}" data-fit="{{ $rbB['image']['fit'] }}">
                                <img src="{{ $rbB['image']['url'] }}" alt="" loading="lazy" decoding="async"
                                     @if ($rbB['image']['w']) width="{{ $rbB['image']['w'] }}" height="{{ $rbB['image']['h'] }}" @endif>
                            </figure>
                        @endif
                        <div style="display:flex;flex-direction:column;gap:14px;align-items:flex-start">
                            @if ($rbB['title'] !== '')
                                <h2 class="rb-h2">{{ $rbB['title'] }}</h2>
                            @endif
                            @if ($rbB['body'] !== '')
                                <p class="rb-cp-text">{{ $rbB['body'] }}</p>
                            @endif
                            @if ($rbB['button'])
                                <a class="rb-btn" href="{{ $rbB['button']['href'] }}" data-testid="rb-page-promo-cta"{!! $rbB['button']['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' !!}>{{ $rbB['button']['label'] }}</a>
                            @endif
                        </div>
                    </div>
                </section>
                @break
        @endswitch
    @endforeach
</article>
@endsection

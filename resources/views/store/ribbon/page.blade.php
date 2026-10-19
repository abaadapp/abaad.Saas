@extends('store.ribbon.layout')
@section('title', $customPage['title'].' — '.$seo['brand'])
@section('content')
{{--
    صفحةٌ كتبها صاحبُ المتجر — `Store\StoreCustomPages`.

    عنوانٌ ونبذةٌ وصورةٌ رئيسيّة، ثمّ أقسامٌ من صورةٍ ونصٍّ بترتيبه، ثمّ زرٌّ
    اختياريٌّ إلى وجهةٍ في متجره. والنصوصُ مُهرَّبةٌ وأسطرُها أسطر
    (`pre-line`) — لا وسمَ يُرسم. والصورُ كاملةٌ أو تملأ إطارها كما اختار
    لكلٍّ منها؛ و«كاملة» إطارُها نسبتُها هي، وطولُها محدودٌ بالشاشة فلا تبتلع
    صورةٌ طويلةٌ الصفحةَ كلَّها.
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
    @media (min-width: 900px) { .rb-cp-sec.is-flip > .rb-cp-figure { order: 2; } }
</style>
<article class="rb-screen" data-testid="rb-custom-page" data-slug="{{ $customPage['slug'] }}">
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
    @if ($customPage['image'])
        <div class="rb-wrap" style="padding-top:28px">
            <figure class="rb-cp-figure is-{{ $customPage['image']['fit'] }}" data-testid="rb-page-image" data-fit="{{ $customPage['image']['fit'] }}">
                <img src="{{ $customPage['image']['url'] }}" alt="" decoding="async" fetchpriority="high"
                     @if ($customPage['image']['w']) width="{{ $customPage['image']['w'] }}" height="{{ $customPage['image']['h'] }}" @endif>
            </figure>
        </div>
    @endif
    @foreach ($customPage['sections'] as $rbI => $rbSec)
        <section class="rb-section" data-testid="rb-page-section">
            <div class="rb-cp-sec @if (! $rbSec['image']) is-text-only @endif @if ($rbI % 2 === 1) is-flip @endif">
                @if ($rbSec['image'])
                    <figure class="rb-cp-figure is-{{ $rbSec['image']['fit'] }}" data-testid="rb-page-section-image" data-fit="{{ $rbSec['image']['fit'] }}">
                        <img src="{{ $rbSec['image']['url'] }}" alt="" loading="lazy" decoding="async"
                             @if ($rbSec['image']['w']) width="{{ $rbSec['image']['w'] }}" height="{{ $rbSec['image']['h'] }}" @endif>
                    </figure>
                @endif
                @if ($rbSec['title'] !== '' || $rbSec['body'] !== '')
                    <div style="display:flex;flex-direction:column;gap:12px">
                        @if ($rbSec['title'] !== '')
                            <h2 class="rb-h2">{{ $rbSec['title'] }}</h2>
                        @endif
                        @if ($rbSec['body'] !== '')
                            <p class="rb-cp-text">{{ $rbSec['body'] }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </section>
    @endforeach
    @if ($customPage['cta'])
        <div class="rb-wrap" style="padding-top:clamp(36px,5vw,56px);display:flex;justify-content:center">
            <a class="rb-btn" href="{{ $customPage['cta']['href'] }}" data-testid="rb-page-cta">{{ $customPage['cta']['label'] }}</a>
        </div>
    @endif
</article>
@endsection

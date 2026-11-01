{{--
    صفحةٌ قانونيّة — سياسةُ الخصوصيّة أو تعليماتُ حذف البيانات أو الشروط.

    بترويسة المتجر وتذييله وألوانه وخطّه، كصفحة «من نحن» شكلًا: عنوانٌ على
    الخلفيّة الهادئة، ثمّ النصُّ في عمودٍ مقروء. والنصُّ ما نشره صاحبُ المتجر
    (`Store\StoreLegal`) — مُهرَّبًا، وروابطُه وحدها قابلةٌ للضغط.
--}}
@extends('store.ribbon.layout')
@section('title', $legalTitle.' — '.$business->name)
@section('content')
<section class="rb-screen" data-testid="rb-legal">
    <div style="background:var(--rb-soft)">
        <div class="rb-wrap" style="padding:clamp(32px,4.5vw,64px) 24px;display:flex;flex-direction:column;gap:14px">
            <h1 class="rb-h1" style="margin:0" data-testid="rb-legal-title">{{ $legalTitle }}</h1>
            @if ($legalUpdated)
                <div style="font-size:13px;color:var(--rb-muted, #6b6b5a)" data-testid="rb-legal-updated">{{ $lang === 'en' ? 'Last updated' : 'آخر تحديث' }}: <bdi dir="ltr">{{ $legalUpdated }}</bdi></div>
            @endif
        </div>
    </div>

    <div class="rb-wrap" style="padding:clamp(32px,4.5vw,64px) 24px">
        <article style="max-width:760px;font-size:16px;line-height:1.9;overflow-wrap:anywhere" data-testid="rb-legal-body">
            @foreach ($legalBlocks as $block)
                @if ($block['type'] === 'h')
                    <h2 style="font-size:20px;font-weight:500;margin:28px 0 10px">{{ $block['text'] }}</h2>
                @elseif ($block['type'] === 'ul')
                    <ul style="margin:0 0 14px;padding-inline-start:22px">
                        @foreach ($block['items'] as $item)
                            <li style="margin:4px 0">{{ \App\Support\Store\StoreLegal::inline($item) }}</li>
                        @endforeach
                    </ul>
                @else
                    <p style="margin:0 0 14px">{{ \App\Support\Store\StoreLegal::inline($block['text']) }}</p>
                @endif
            @endforeach
        </article>
    </div>
</section>
@endsection

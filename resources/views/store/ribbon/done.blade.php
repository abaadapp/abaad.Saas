@extends('store.ribbon.layout')
@section('title', $t['thanks'].' — '.$seo['brand'])
@section('content')
<section class="rb-screen" style="max-width:560px;margin:40px auto;padding:0 24px;text-align:center" data-testid="rb-done">
    <div style="width:64px;height:64px;border-radius:50%;background:var(--rb-olive);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:28px">✓</div>
    <h1 class="rb-h1" style="margin-top:18px">{{ $t['thanks'] }}</h1>
    <p style="margin:8px 0 0;font-size:15px">{{ $t['orderNo'] }}: <strong dir="ltr" data-testid="rb-order-number">{{ $order['number'] }}</strong></p>
    <div class="rb-box" style="margin:28px 0;text-align:start;font-size:14px;line-height:1.9;padding:20px">
        @foreach ($order['lines'] as $l)
            <div style="display:flex;justify-content:space-between;gap:12px"><span>{{ $l['name'] }} × {{ $l['qty'] }}</span><span>{{ $l['line'] }}</span></div>
        @endforeach
        <div style="border-top:1px solid var(--rb-line);margin-top:10px;padding-top:10px">
            <div><span>{{ $t['s2'] }}:</span> {{ $order['fulfil'] }}</div>
            <div><span>{{ $t['date'] }}:</span> {{ $order['date'] }}</div>
            <div><span>{{ $t['pay'] }}:</span> {{ $order['pay'] }}</div>
        </div>
        <div style="border-top:1px solid var(--rb-line);margin-top:10px;padding-top:10px;display:flex;justify-content:space-between"><span>{{ $t['total'] }}</span><strong>{{ $order['total'] }}</strong></div>
        {{--
            وثالثةٌ هنا — وهي التي تُقرأ ساعةَ الخلاف.

            الزبونُ يفتح الصندوق فيرى باقةً تختلف قليلًا، فيعود إلى آخر ما
            قاله له المحلّ. وهذه الصفحةُ هي آخرُ ما قاله — لا صفحةُ منتجٍ
            مرّ عليها ثمّ نسيها.
        --}}
        @if ($imageNote !== '')
            <p class="rb-note" data-testid="rb-image-note">{{ $imageNote }}</p>
        @endif
    </div>
    @if ($order['transfer'] && $bank !== '')
        <div class="rb-box" style="text-align:start;font-size:14px;line-height:1.9;margin-bottom:28px" data-testid="rb-bank">
            <strong>{{ $t['bankDetails'] }}</strong>
            <div style="white-space:pre-line;margin-top:6px">{{ $bank }}</div>
        </div>
    @endif
    <a class="rb-btn-ghost" href="{{ $base }}/">{{ $t['continueShopping'] }}</a>
</section>
@endsection

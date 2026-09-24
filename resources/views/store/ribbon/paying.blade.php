@extends('store.ribbon.layout')
@section('title', $t['payingTitle'].' — '.$seo['brand'])
@section('content')
{{--
    عاد الزائرُ من بوّابة الدفع ولم يصل إشعارُها بعد.

    والإشعارُ يسبق العودةَ عادةً، وقد يتأخّر ثوانيَ. فلا يُقال له «فشل»:
    صفحةٌ تقول ذلك على مالٍ خرج من حسابه أسوأُ من انتظارٍ بكلمةٍ صادقة.

    والصفحةُ تُحدّث نفسَها — فلا يُطلب منه أن يضغط شيئًا وهو قلق.
--}}
<section class="rb-screen" style="max-width:520px;margin:40px auto;padding:0 24px;text-align:center" data-testid="rb-paying">
    <div style="width:64px;height:64px;border-radius:50%;background:var(--rb-soft);display:inline-flex;align-items:center;justify-content:center;font-size:26px">⏳</div>
    <h1 class="rb-h1" style="margin-top:18px">{{ $t['payingTitle'] }}</h1>
    <p style="margin:10px 0 0;font-size:15px;line-height:1.9">{{ $paid ? $t['payingPaid'] : $t['payingWait'] }}</p>
    <p class="rb-note" style="margin-top:18px">{{ $t['payingNote'] }}</p>
    <div style="margin-top:26px"><a class="rb-btn-ghost" href="{{ $base }}/">{{ $t['continueShopping'] }}</a></div>
</section>
<script>
    // ثمانِ ثوانٍ: تكفي لإشعارٍ متأخّر ولا تُثقل الخادم بتحديثٍ كلَّ لحظة
    setTimeout(function () { window.location.reload(); }, 8000);
</script>
@endsection

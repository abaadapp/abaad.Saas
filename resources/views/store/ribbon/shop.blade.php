@extends('store.ribbon.layout')
@section('title', $seo['title'])
@section('content')
<section class="rb-screen rb-wrap" style="padding:40px 24px" data-testid="rb-shop">
    {{--
        والترشيحُ في الترويسة لا هنا — صفُّ «خيارات المتجر» (`layout`).

        كان هنا صفٌّ ثانٍ: «الكل» وكلُّ فئة. وبقاؤه مع صفّ الترويسة يعني
        ترشيحين يقولان شيئين، فرُفع. والعنوانُ يقول ما يُعرض: ما بُحث عنه،
        أو «الأكثر مبيعًا»، أو اسمُ الفئة — فمن وصل من بطاقةِ فئةٍ في الرئيسية
        يعرف أين هو ولو لم تكن فئتُه في صفّ الترويسة.
    --}}
    <div style="margin-bottom:28px">
        {{-- والمجموعةُ باسمها ووصفها بلغة الصفحة — وبلا وصفٍ لها يبقى سطرُ المتجر --}}
        <h1 class="rb-h1" data-testid="rb-shop-title">{{ $q !== '' ? $q : ($best ? $t['bestSellers'] : ($collection['title'] ?? ($catName ?? $t['shopTitle']))) }}</h1>
        @if (($collection['description'] ?? '') !== '')
            <p style="margin:6px 0 0;font-size:15px;line-height:1.7;white-space:pre-line" data-testid="rb-collection-description">{{ $collection['description'] }}</p>
        @else
            <p style="margin:6px 0 0;font-size:15px">{{ $t['shopSub'] }}</p>
        @endif
    </div>
    @if (count($products) === 0)
        {{--
            ورفٌّ فارغ غيرُ بحثٍ لم يُصب: «لا منتجات هنا بعد» تقول إنّ المحلّ
            خالٍ، وهو لا يُقال لمن في رفّه بضاعةٌ لم يطابقها ما كُتب.

            و«الأكثر مبيعًا» بلا بيعٍ يُقال كما هو — لا يُملأ بأحدث الأصناف
            ثمّ يُسمّى «الأكثر مبيعًا».
        --}}
        <p style="padding:60px 0;text-align:center" data-testid="rb-empty">{{ ! $hasAny ? $t['noProducts'] : ($q !== '' ? $t['noMatch'] : ($best ? $t['noBest'] : (($collection ?? null) !== null ? $t['noInCollection'] : $t['noInCategory']))) }}</p>
    @else
        <div class="rb-grid">
            @foreach ($products as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    @endif
</section>
@endsection

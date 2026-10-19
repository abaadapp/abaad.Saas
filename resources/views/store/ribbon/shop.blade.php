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
    @if (($collection['hidden'] ?? false))
        {{-- والمخفيّةُ تُعايَن وحدها — ويُقال لصاحبها إنّ زبونه لا يراها --}}
        <p style="margin:0 0 16px;font-size:13px;color:#6b655c" data-testid="rb-collection-hidden">{{ $t['collectionHidden'] }}</p>
    @endif
    @if (($collection['image'] ?? null) !== null)
        {{--
            وصورةُ المجموعة رأسُ رفّها — «كاملة» بنسبتها وطولُها محدود فلا تدفع
            البضاعةَ تحت الشاشة، أو تملأ إطارًا عريضًا. والمقاسُ يُحجز قبل وصولها.
        --}}
        <figure data-testid="rb-collection-image" data-fit="{{ $collection['fit'] }}"
                style="margin:0 0 24px;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft);display:flex;align-items:center;justify-content:center;{{ $collection['fit'] === 'cover' ? 'aspect-ratio:21/8' : '' }}">
            <img src="{{ $collection['image'] }}" alt="" decoding="async"
                 @if ($collection['size']) width="{{ $collection['size']['w'] }}" height="{{ $collection['size']['h'] }}" @endif
                 style="display:block;{{ $collection['fit'] === 'cover' ? 'width:100%;height:100%;object-fit:cover' : 'width:auto;height:auto;max-width:100%;max-height:min(42vh,340px);object-fit:contain' }}">
        </figure>
    @endif
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

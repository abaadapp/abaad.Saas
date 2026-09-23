@extends('store.ribbon.layout')
@section('title', $product['name'].' — '.$business->name)
@section('content')
<section class="rb-screen rb-wrap" style="padding:40px 24px" data-testid="rb-product-page">
    <a href="{{ $base }}/shop" style="font-size:13px;display:inline-flex;align-items:center;min-height:44px">{{ $t['back'] }}</a>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:48px;margin-top:20px;align-items:start">
        <div style="aspect-ratio:1/1;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)">
            @if ($product['image'])
                <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" style="width:100%;height:100%;object-fit:cover;display:block">
            @else
                <div class="rb-stripes" style="--tint: {{ $product['tint'] }}"></div>
            @endif
        </div>
        <div style="display:flex;flex-direction:column;gap:20px">
            <div>
                @if ($product['category'])<div style="font-size:12px;letter-spacing:.15em">{{ $product['category'] }}</div>@endif
                <h1 style="margin:6px 0 0;font-size:30px;font-weight:500">{{ $product['name'] }}</h1>
                <div style="font-size:22px;margin-top:8px;font-weight:600" data-rb-price>{{ $product['price_text'] }}</div>
                {{--
                    ═══ ولمَ هنا لا تحت الصورة ═══

                    كان تحتها — والادّعاءُ عنها، فبدا موضعَه. وعلى الجوّال
                    كان صحيحًا: الصورةُ أوّلًا ثمّ السطرُ ثمّ الزرّ.

                    ثمّ فُتحت الصفحةُ على شاشةٍ عريضة: عمودان، الصورةُ في
                    أحدهما بستّمئة بكسل، والاسمُ والثمنُ والزرُّ في الآخر
                    عند ثلاثمئة. فصار السطرُ عند ٨٣٠ والزرُّ عند ٣٦٣ — أي
                    **تحت الزرّ بأربعمئة بكسل**، ويُشترى المنتج بلا أن يُقرأ.

                    وهو العطبُ الذي كُتب هذا السطرُ كلُّه لأجله، في صورةٍ
                    أخرى. ولم يكشفه اختبارٌ: الترتيبُ في المصدر كان صحيحًا
                    — عمودُ الصورة يسبق — وكشفته لقطةُ شاشة.

                    فمكانُه عمودُ القرار: آخرُ ما يُقرأ قبل الضغطة، في
                    العرضين معًا.
                --}}
                @if ($imageNote !== '')
                    <p class="rb-note" data-testid="rb-image-note">{{ $imageNote }}</p>
                @endif
            </div>
            @if ($product['description'] !== '')
                <p style="margin:0;font-size:15px;line-height:1.7;text-wrap:pretty">{{ $product['description'] }}</p>
            @endif
            @if (count($product['sizes']))
                <div>
                    <div style="font-size:13px;margin-bottom:8px">{{ $t['size'] }}</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap" data-rb-sizes>
                        @foreach ($product['sizes'] as $i => $s)
                            <button type="button" class="rb-pill {{ $i === 0 ? 'on' : '' }}" data-variant="{{ $s['id'] }}" data-price="{{ $s['price_text'] }}" style="min-width:80px">{{ $s['name'] }} · {{ $s['price_text'] }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($product['available'])
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <div class="rb-qty">
                        <button type="button" data-rb-dec aria-label="−">−</button>
                        <span data-rb-qty>1</span>
                        <button type="button" data-rb-inc aria-label="+">+</button>
                    </div>
                    <button type="button" class="rb-btn" style="flex:1;min-width:200px;height:48px" data-rb-add data-testid="rb-add">{{ $t['add'] }}</button>
                </div>
            @else
                <div style="border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px;font-size:14px" data-testid="rb-soldout">{{ $t['soldOut'] }}</div>
            @endif
            @if ($deliveryNote !== '')
                <div style="font-size:13px;line-height:1.8">{{ $deliveryNote }}</div>
            @endif
        </div>
    </div>
</section>
@endsection
@section('scripts')
<script>
(function () {
    var id = {{ (int) $product['id'] }}, variant = null, qty = 1;
    var sizes = document.querySelector('[data-rb-sizes]');
    if (sizes) {
        var first = sizes.querySelector('button'); variant = first ? +first.dataset.variant : null;
        sizes.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            sizes.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on'); variant = +b.dataset.variant;
            document.querySelector('[data-rb-price]').textContent = b.dataset.price;
        });
    }
    var q = document.querySelector('[data-rb-qty]');
    var dec = document.querySelector('[data-rb-dec]'), inc = document.querySelector('[data-rb-inc]'), add = document.querySelector('[data-rb-add]');
    if (dec) dec.addEventListener('click', function () { qty = Math.max(1, qty - 1); q.textContent = qty; });
    if (inc) inc.addEventListener('click', function () { qty = Math.min({{ \App\Support\Store\WebCheckout::MAX_QTY }}, qty + 1); q.textContent = qty; });
    if (add) add.addEventListener('click', function () {
        RB.add(id, variant, qty);
        add.textContent = @json($t['added']); RB.toast(@json($t['added']));
        setTimeout(function () { add.textContent = @json($t['add']); }, 1500);
    });
})();
</script>
@endsection

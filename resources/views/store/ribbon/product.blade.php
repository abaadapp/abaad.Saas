@extends('store.ribbon.layout')
@section('title', $product['name'].' — '.$seo['brand'])
@section('content')
@php($offers = $product['available'] && count($upsells ?? []) > 0)
@php($gallery = $product['gallery'] ?? [])
<section class="rb-screen rb-wrap" style="padding:40px 24px" data-testid="rb-product-page">
    <a href="{{ $base }}/shop" style="font-size:13px;display:inline-flex;align-items:center;min-height:44px">{{ $t['back'] }}</a>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:48px;margin-top:20px;align-items:start">
        {{--
            ═══ معرضُ الصنف — هنا وحده ═══

            الرئيسيّةُ كبيرةً ثمّ الإضافيّةُ مصغّراتٍ بترتيبها، والضغطُ على
            مصغّرةٍ يُبدّل الكبيرة. والرفُّ والرئيسيّةُ يعرضان صورةً واحدة.
            وصنفٌ بصورةٍ واحدة لا شريطَ مصغّراتٍ له.
        --}}
        <div data-testid="rb-gallery">
            <div style="aspect-ratio:1/1;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)">
                @if (count($gallery))
                    <img src="{{ $gallery[0] }}" alt="{{ $product['name'] }}" style="width:100%;height:100%;object-fit:cover;display:block" data-rb-main data-testid="rb-main-image">
                @else
                    <div class="rb-stripes" style="--tint: {{ $product['tint'] }}"></div>
                @endif
            </div>
            @if (count($gallery) > 1)
                <div class="rb-thumbs" role="group" aria-label="{{ $t['photos'] }}" data-rb-thumbs data-testid="rb-thumbs">
                    @foreach ($gallery as $i => $src)
                        <button type="button" class="rb-thumb{{ $i === 0 ? ' is-on' : '' }}" data-src="{{ $src }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}" aria-label="{{ strtr($t['showPhoto'], [':n' => $i + 1, ':total' => count($gallery)]) }}" data-testid="rb-thumb">
                            <img src="{{ $src }}" alt="" loading="lazy">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
        <div style="display:flex;flex-direction:column;gap:20px">
            <div>
                @if ($product['category'])<div class="rb-track" style="font-size:12px;--rb-track:.15em">{{ $product['category'] }}</div>@endif
                <h1 style="margin:6px 0 0;font-size:30px;font-weight:500">{{ $product['name'] }}</h1>
                <div style="font-size:22px;margin-top:8px;font-weight:600" data-rb-price>{{ $product['price_text'] }}</div>
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
            @if ($offers)
                {{--
                    ═══ «أضف مع طلبك» — لمن في قائمة المالك وحده (`RibbonUpsells`) ═══

                    الكمّيّةُ ثمّ الإضافاتُ ثمّ الزرّ: يختار الزبونُ كلَّ شيءٍ ثمّ
                    يضغط مرّةً واحدة. ومن ليس في القائمة يبقى على الصفّ القديم أدناه.
                --}}
                <div class="rb-qty" style="align-self:flex-start">
                    <button type="button" data-rb-dec aria-label="−">−</button>
                    <span data-rb-qty>1</span>
                    <button type="button" data-rb-inc aria-label="+">+</button>
                </div>
                <div data-rb-upsells data-testid="rb-upsells">
                    <h2 style="margin:0 0 8px;font-size:15px;font-weight:500">{{ $t['upsellTitle'] }}</h2>
                    <div class="rb-ups">
                        @foreach ($upsells as $u)
                            <div class="rb-up" data-rb-up="{{ $u['id'] }}" @if (count($u['sizes'])) data-needs-variant @endif @if ($u['gift_card'] ?? false) data-gift-card @endif data-testid="rb-upsell">
                                <button type="button" class="rb-up-pick" data-rb-up-pick aria-pressed="false">
                                    <span class="rb-up-img">
                                        @if ($u['image'])
                                            <img src="{{ $u['image'] }}" alt="" loading="lazy">
                                        @else
                                            <span class="rb-stripes" style="display:block;--tint: {{ $u['tint'] }}"></span>
                                        @endif
                                    </span>
                                    <span style="flex:1;min-width:0">
                                        <span class="rb-up-name">{{ $u['name'] }}</span>
                                        <span class="rb-up-price">@if ($u['from']){{ $t['from'] }} @endif{{ $u['price_text'] }}</span>
                                    </span>
                                    <span class="rb-up-tick" aria-hidden="true"></span>
                                </button>
                                @if (count($u['sizes']))
                                    <div class="rb-up-sizes" data-rb-up-sizes hidden>
                                        @foreach ($u['sizes'] as $s)
                                            <button type="button" class="rb-pill" data-rb-up-size="{{ $s['id'] }}" aria-pressed="false">{{ $s['name'] }} · {{ $s['price_text'] }}</button>
                                        @endforeach
                                    </div>
                                    <p class="rb-error" style="margin:0 12px 12px" data-rb-up-need hidden>{{ $t['upsellChoose'] }}</p>
                                @endif
                                @if ($u['gift_card'] ?? false)
                                    {{--
                                        ═══ كرتُ الهدية إضافةً — خانةُ نصّه حين يُختار ═══

                                        الخانةُ نفسُها التي على صفحة الكرت (`rb-card-note`): النصُّ
                                        اختياريٌّ، ويدخل مع بند الكرت وحده — وكرتٌ بلا رسالةٍ يُضاف
                                        كما هو (`GiftCardProduct::settle`). وتُطوى وتُمحى إن تُرك الكرت.
                                    --}}
                                    <div class="rb-up-note" data-rb-up-note-box hidden data-testid="rb-upsell-card-note-box">
                                        <label for="rb-up-note-{{ $u['id'] }}" style="display:block;font-size:13px;margin-bottom:8px">{{ $t['giftCardMessage'] }}</label>
                                        <textarea id="rb-up-note-{{ $u['id'] }}" class="rb-input" rows="3" maxlength="{{ $product['card_max'] }}" data-rb-up-note data-testid="rb-upsell-card-note"></textarea>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <button type="button" class="rb-btn" style="width:100%;height:48px" data-rb-add data-testid="rb-add">{{ $t['add'] }}</button>
            @elseif ($product['available'])
                @if ($product['gift_card'])
                    {{--
                        ═══ نصُّ كرت الهدية — خانةٌ واحدة، اختياريّة ═══

                        لا رفعَ ملفٍّ ولا محاذاةَ ولا اختيار. والنصُّ يُحفظ مع
                        البند في السلّة؛ وكرتٌ بلا رسالةٍ يُشترى ويُحاسَب كما هو
                        (`GiftCardProduct::settle`).
                    --}}
                    <div data-testid="rb-card-note-box">
                        <label for="rb-card-note" style="display:block;font-size:13px;margin-bottom:8px">{{ $t['giftCardMessage'] }}</label>
                        <textarea id="rb-card-note" class="rb-input" rows="4" maxlength="{{ $product['card_max'] }}" data-rb-card-note data-testid="rb-card-note"></textarea>
                    </div>
                @endif
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
            {{--
                ═══ تنبيهُ الصورة تحت زرّ الإضافة مباشرةً ═══

                في عمود القرار لا تحت الصورة: على الشاشة العريضة يقع عمودُ
                الصورة بجانب الزرّ، فسطرٌ هناك يهبط بعيدًا عنه ولا يُقرأ.
                وهنا يلاصق الزرَّ في العرضين — بإضافاتٍ أو بلا — ثمّ تليه
                ملاحظةُ التوصيل. وكان تحت الثمن، ونُقل بقرار المالك.
            --}}
            @if ($imageNote !== '')
                <p class="rb-note" data-testid="rb-image-note">{{ $imageNote }}</p>
            @endif
            @if (($showDeliveryNote ?? true) && $deliveryNote !== '')
                <div style="font-size:13px;line-height:1.8" data-testid="rb-delivery-note">{{ $deliveryNote }}</div>
            @endif
        </div>
    </div>
</section>
@endsection
@section('scripts')
@if (count($gallery) > 1)
<style>
    .rb-thumbs { display: flex; gap: 10px; margin-top: 12px; overflow-x: auto; padding: 2px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .rb-thumbs::-webkit-scrollbar { display: none; }
    .rb-thumb { flex: none; width: 72px; height: 72px; padding: 0; border: 1px solid var(--rb-border); border-radius: var(--rb-r-sm); overflow: hidden; background: var(--rb-soft); cursor: pointer; opacity: .75; }
    .rb-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rb-thumb.is-on { border-color: var(--rb-olive); box-shadow: inset 0 0 0 1px var(--rb-olive); opacity: 1; }
    .rb-thumb:focus-visible { outline: 2px solid var(--rb-olive); outline-offset: 2px; }
    @media (max-width: 1023px) { .rb-thumb { width: 60px; height: 60px; } }
</style>
<script>{!! file_get_contents(resource_path('js/store/ribbon-gallery.js')) !!}</script>
<script>RBGallery.mount(document.querySelector('[data-rb-thumbs]'), document.querySelector('[data-rb-main]'));</script>
@endif
@if ($product['gift_card'])
<script>{!! file_get_contents(resource_path('js/store/ribbon-card-note.js')) !!}</script>
@endif
@if ($offers)
<style>
    .rb-ups { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
    .rb-up { border: 1px solid var(--rb-border); border-radius: var(--rb-r); background: #fff; }
    .rb-up.on { border-color: var(--rb-olive); box-shadow: inset 0 0 0 1px var(--rb-olive); }
    .rb-up-pick { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 72px; padding: 8px 12px; border: 0; background: transparent; color: #000; cursor: pointer; text-align: start; font: inherit; }
    .rb-up-img { width: 56px; height: 56px; flex: none; border-radius: var(--rb-r-sm); overflow: hidden; background: var(--rb-soft); }
    .rb-up-img img, .rb-up-img .rb-stripes { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rb-up-name { display: block; font-size: 14px; line-height: 1.4; overflow-wrap: anywhere; }
    .rb-up-price { display: block; font-size: 13px; font-weight: 600; margin-top: 2px; }
    .rb-up-tick { width: 22px; height: 22px; flex: none; border: 1.5px solid var(--rb-border); border-radius: 50%; }
    .rb-up.on .rb-up-tick { border-color: var(--rb-olive); background: var(--rb-olive) radial-gradient(circle, #fff 0 3px, transparent 4px); }
    .rb-up-sizes { display: flex; gap: 8px; flex-wrap: wrap; padding: 0 12px 12px; }
    .rb-up-sizes .rb-pill { min-height: 44px; }
    /* و`display` أعلاه يغلب `hidden` لولا هذا — فتظهر المقاساتُ قبل الاختيار */
    .rb-up-sizes[hidden], .rb-up [data-rb-up-need][hidden], .rb-up-note[hidden] { display: none; }
    .rb-up-note { padding: 0 12px 12px; }
</style>
<script>{!! file_get_contents(resource_path('js/store/ribbon-upsells.js')) !!}</script>
@endif
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
@if ($offers)
    // الصنفُ بمقاسه وكمّيّته، ومعه ما اختير من الإضافات — في ضغطةٍ واحدة
    if (add) RBUpsells.mount(document.querySelector('[data-rb-upsells]'), {
        button: add,
        main: function () { return { id: id, variant_id: variant, qty: qty }; },
        add: RB.add,
        done: function () {
            add.textContent = @json($t['added']); RB.toast(@json($t['added']));
            setTimeout(function () { add.textContent = @json($t['add']); }, 1500);
        },
        /*
            والتنبيهُ عند البطاقة لا في الشريط العائم: الشريطُ سطرٌ قصير،
            وجملةٌ بطولها تنقصّ فيه على الجوّال. فتُقال حيث يُختار المقاس،
            وتُساق الصفحةُ إليها.
        */
        missing: function (cards) { cards[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); },
    });
@elseif ($product['gift_card'])
    // والنصُّ اختياريّ يُحفظ مع بنده — وكرتٌ بلا رسالةٍ يدخل السلّة كما هو
    var note = RBCardNote.mount(document.querySelector('[data-rb-card-note]'));
    if (add) add.addEventListener('click', function () {
        var text = note.take();
        if (text) RB.add(id, variant, qty, text); else RB.add(id, variant, qty);
        add.textContent = @json($t['added']); RB.toast(@json($t['added']));
        setTimeout(function () { add.textContent = @json($t['add']); }, 1500);
    });
@else
    if (add) add.addEventListener('click', function () {
        RB.add(id, variant, qty);
        add.textContent = @json($t['added']); RB.toast(@json($t['added']));
        setTimeout(function () { add.textContent = @json($t['add']); }, 1500);
    });
@endif
})();
</script>
@endsection

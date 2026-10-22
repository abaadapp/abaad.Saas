{{--
    ═══ «إضافات اختيارية» — إضافاتُ الصنف نفسِه التي فُتحت للموقع ═══

    ما يعرضه `RibbonController::addons` بالقاعدة التي يأذن بها الخادم عند
    التسعير (`ProductAddons::for` ببابِ الموقع). ولكلّ إضافةٍ عدّادُها يبدأ
    بصفر، وكمّيّتُها للبند كلِّه لا لكلّ قطعة — ويُقال ذلك تحت العنوان.

    والأسعارُ على البطاقات (`data-amount`) للعرض: الإتمامُ يسعّر من القاعدة.
    وما نفد رفُّه يُعرض بلا عدّاد. والعدّاداتُ في `ribbon-addons.js`.
--}}
@if (count($addons ?? []))
    <div data-rb-addons data-testid="rb-addons">
        <h2 style="margin:0 0 4px;font-size:15px;font-weight:500">{{ $t['addonsTitle'] }}</h2>
        <p style="margin:0 0 10px;font-size:12px;color:#555" data-testid="rb-addons-hint">{{ $t['addonsHint'] }}</p>
        <div class="rb-addons">
            @foreach ($addons as $a)
                <div class="rb-addon" data-rb-addon="{{ $a['id'] }}" data-qty="0" data-amount="{{ $a['price'] }}" @unless ($a['available']) data-soldout @endunless data-testid="rb-addon">
                    <span class="rb-addon-name">{{ $a['name'] }}</span>
                    <span class="rb-addon-price">+ {{ $a['price_text'] }}</span>
                    @if ($a['available'])
                        <div class="rb-qty" role="group" aria-label="{{ strtr($t['upQty'], [':name' => $a['name']]) }}">
                            <button type="button" data-rb-addon-dec aria-label="{{ strtr($t['upDec'], [':name' => $a['name']]) }}" data-testid="rb-addon-dec">−</button>
                            <span data-rb-addon-count aria-live="polite" data-testid="rb-addon-count">0</span>
                            <button type="button" data-rb-addon-inc aria-label="{{ strtr($t['upInc'], [':name' => $a['name']]) }}" data-testid="rb-addon-inc">+</button>
                        </div>
                    @else
                        <span class="rb-addon-out" data-testid="rb-addon-soldout">{{ $t['addonSoldOut'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>
        {{-- إجماليُّ البند — الصنفُ في كمّيّته وما اختير معه، ويُطوى حتّى تُختار إضافة --}}
        <p class="rb-up-total" data-rb-line-total hidden data-testid="rb-line-total" style="margin-top:10px"><span>{{ $t['lineTotal'] }}</span> <strong data-rb-line-total-text></strong></p>
    </div>
    <style>
        .rb-addons { display: grid; gap: 8px; }
        .rb-addon { display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; border: 1px solid var(--rb-line); border-radius: var(--rb-r); background: #fff; padding: 8px 12px; }
        .rb-addon.is-on { border-color: var(--rb-ink, #111); }
        .rb-addon[data-soldout] { opacity: .55; }
        .rb-addon-name { font-size: 14px; min-width: 0; overflow-wrap: anywhere; }
        .rb-addon-price { font-size: 13px; white-space: nowrap; }
        .rb-addon .rb-qty button { width: 44px; height: 44px; }
        .rb-addon .rb-qty button[disabled] { opacity: .35; cursor: not-allowed; }
        .rb-addon-out { font-size: 12px; }
        [data-rb-line-total] { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; font-size: 15px; border-top: 1px solid var(--rb-line); padding-top: 12px; }
        [data-rb-line-total] strong { font-size: 18px; font-weight: 600; }
        [data-rb-line-total][hidden] { display: none; }
    </style>
    {{-- وكتابةُ المبلغ كما يكتبه الخادم (`RBUpsells.money`) — تُضمَّن هنا لمن لا «أضف مع طلبك» له --}}
    @if ($withMoney ?? false)
        <script>{!! file_get_contents(resource_path('js/store/ribbon-upsells.js')) !!}</script>
    @endif
    <script>{!! file_get_contents(resource_path('js/store/ribbon-addons.js')) !!}</script>
@endif

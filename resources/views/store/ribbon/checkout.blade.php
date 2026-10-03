@extends('store.ribbon.layout')
@section('title', $t['checkoutTitle'].' — '.$seo['brand'])
@section('content')
<section class="rb-screen rb-wrap" style="padding:40px 24px" data-testid="rb-checkout-page">
    <h1 class="rb-h1" style="margin-bottom:28px">{{ $t['checkoutTitle'] }}</h1>
    @if (! $accepts)
        <div class="rb-box" data-testid="rb-closed">{{ $t['closed'] }}</div>
    @else
    {{-- الاسمان والعنوانُ بالإنجليزيّة لمن في قائمتها — والخادمُ يحرسها (`EnglishCheckout`) --}}
    @php
        $en = ($englishOnly ?? false) ? 'dir="ltr" lang="en"' : '';
    @endphp
    <form data-rb-form novalidate style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:48px;align-items:start">
        <div style="display:flex;flex-direction:column;gap:36px;min-width:0">
            @php
                /*
                    ما انتقاه صاحبُ المحلّ — والشاشةُ لا تحسبه، تقرؤه.
                    `shows` يرسم الحقل، و`req` يضع النجمة. والخادمُ يشترط
                    الشيءَ نفسَه من `CheckoutFields` — موضعٌ واحد لا اثنان.
                */
                $shows = fn ($f) => ($fields[$f] ?? 'optional') !== 'off';
                $req = fn ($f) => ($fields[$f] ?? 'optional') === 'required';
                $star = fn ($f) => $req($f) ? ' *' : '';
                /*
                    ونجمةٌ تُرى ولا تُقال ليست علامة.

                    قارئُ الشاشة يقرأ الاسمَ ويُسقط الرمزَ، فيسمع الأعمى
                    «المنطقة» ويسمع المبصرُ «المنطقة مطلوبة». و`aria-required`
                    تقولها له كما تقولها النجمةُ لعينه.
                */
                $need = fn ($f) => $req($f) ? 'aria-required="true"' : '';
            @endphp
            @php $step = fn ($n, $title) => '<h2 style="margin:0 0 14px;font-size:17px;font-weight:500;display:flex;align-items:center;gap:10px"><span style="width:24px;height:24px;border-radius:50%;background:var(--rb-olive);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px">'.$n.'</span>'.e($title).'</h2>'; @endphp
            <div>
                {!! $step(1, $t['s1']) !!}
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    {{--
                        والنجمةُ على المقفلين كذلك.

                        هما أشدُّ الحقول إلزامًا — الزبونُ يُعرف بهاتفه، والطلبُ
                        بلا اسمٍ لا يُنادى — ولا ينتقيهما أحد. وكانت النجمةُ
                        تُوضع على ما يملك صاحبُ المحلّ أمرَه وحدَه، فخرج
                        المقفلان بلا علامة: يرى الزبونُ نجمةً على «المنطقة»
                        ولا يراها على «رقم الهاتف»، فيظنّ الثاني اختياريًّا
                        ويتركه — ثمّ يُردّ طلبُه بعد أن ملأ النموذج كلَّه.
                    --}}
                    <div><input class="rb-input" name="name" {!! $en !!} placeholder="{{ $t['fName'] }} *" aria-label="{{ $t['fName'] }}" aria-required="true"><div class="rb-error" data-err="name"></div></div>
                    <div><input class="rb-input" name="phone" dir="ltr" placeholder="{{ $t['fPhone'] }} *" aria-label="{{ $t['fPhone'] }}" aria-required="true"><div class="rb-error" data-err="phone"></div></div>
                </div>
            </div>
            <div>
                {!! $step(2, $t['s2']) !!}
                {{-- ولا تُعرض طريقةٌ أغلقها صاحبُ المحلّ — ومن بقيت وحدَها لا تُسأل --}}
                @if (count($fulfilments) > 1)
                    <div style="display:flex;gap:8px;margin-bottom:14px" data-rb-fulfil>
                        <button type="button" class="rb-pill on" data-v="delivery" style="flex:1;height:46px">{{ $t['delivery'] }}</button>
                        <button type="button" class="rb-pill" data-v="pickup" style="flex:1;height:46px">{{ $t['pickup'] }}</button>
                    </div>
                @endif
                <div data-rb-delivery style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    @if ($shows('area'))
                        <div>
                            @if (count($delivery['areas']))
                                <select class="rb-input" name="area" aria-label="{{ $t['fArea'] }}" {!! $need('area') !!}>
                                    <option value="">{{ $t['fArea'] }}{{ $star('area') }}</option>
                                    @foreach ($delivery['areas'] as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
                                </select>
                            @else
                                <input class="rb-input" name="area" placeholder="{{ $t['fArea'] }}{{ $star('area') }}" aria-label="{{ $t['fArea'] }}" {!! $need('area') !!}>
                            @endif
                            <div class="rb-error" data-err="area"></div>
                        </div>
                    @endif
                    @if ($shows('address'))
                        <div><input class="rb-input" name="address" {!! $en !!} placeholder="{{ $t['fAddress'] }}{{ $star('address') }}" aria-label="{{ $t['fAddress'] }}" {!! $need('address') !!}><div class="rb-error" data-err="address"></div></div>
                    @endif
                </div>
                <div data-rb-pickup style="display:none;border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px;font-size:14px;line-height:1.7">{{ $t['pickupAddr'] }}@if ($identity['address'] !== '') — {{ $identity['address'] }}@endif @if ($hours !== '') · {{ $hours }}@endif</div>
                @if ($shows('date') || ($shows('slot') && count($delivery['slots'])))
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:12px">
                        @if ($shows('date'))
                            <div>
                                <label for="rb-date" style="font-size:13px">{{ $t['date'] }}{{ $star('date') }}</label>
                                <input id="rb-date" class="rb-input" type="date" name="date" min="{{ $minDate }}" max="{{ $maxDate }}" aria-label="{{ $t['date'] }}" {!! $need('date') !!} style="margin-top:6px">
                                <div class="rb-error" data-err="date"></div>
                            </div>
                        @endif
                        @if ($shows('slot') && count($delivery['slots']))
                            <div>
                                <label for="rb-slot" style="font-size:13px">{{ $t['slot'] }}{{ $star('slot') }}</label>
                                <select id="rb-slot" class="rb-input" name="slot" aria-label="{{ $t['slot'] }}" {!! $need('slot') !!} style="margin-top:6px">
                                    @if (! $req('slot'))<option value="">—</option>@endif
                                    @foreach ($delivery['slots'] as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                                </select>
                                <div class="rb-error" data-err="slot"></div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
            <div>
                {!! $step(3, $t['s3']) !!}

                {{--
                    ═══ وميزةُ الإهداء — لمن رفعها من متجره (`GiftOrders`) ═══

                    «هذا الطلب هدية» يفتح المستلِمَ والمناسبةَ وإخفاءَ الاسم وطريقةَ
                    تحديد موقع المستلِم. وخانتا المستلِم تنتقلان إليها — إلّا إن
                    اشترطهما صاحبُ المحلّ لكلّ طلب، فتبقيان في مكانهما ظاهرتين.
                --}}
                @php
                    $giftOn = (bool) ($giftOrder['on'] ?? false);
                    $recipInGift = $giftOn && ! $req('recipient');
                @endphp

                {{-- المستلِمُ غيرُ المشتري — مطويٌّ حتى يُطلب، فلا يُثقل من يشتري لنفسه --}}
                @if ($shows('recipient') && ! $recipInGift)
                    {{-- ومطلوبًا يُفتح بلا خانةٍ تُضغط: شرطٌ خلف طيّةٍ لا يُرى --}}
                    @unless ($req('recipient'))
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;margin-bottom:12px">
                            <input type="checkbox" data-rb-forother style="width:18px;height:18px;accent-color:var(--rb-olive)">
                            <span>{{ $t['forOther'] }}</span>
                        </label>
                    @endunless
                    <div data-rb-recipient style="display:{{ $req('recipient') ? 'grid' : 'none' }};grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px">
                        <div><input class="rb-input" name="recipient_name" {!! $en !!} placeholder="{{ $t['fRecipient'] }}{{ $star('recipient') }}" aria-label="{{ $t['fRecipient'] }}" {!! $need('recipient') !!}><div class="rb-error" data-err="recipient_name"></div></div>
                        <div><input class="rb-input" name="recipient_phone" dir="ltr" placeholder="{{ $t['fRecipientPhone'] }}{{ $star('recipient') }}" aria-label="{{ $t['fRecipientPhone'] }}" {!! $need('recipient') !!}><div class="rb-error" data-err="recipient_phone"></div></div>
                    </div>
                @endif

                @if ($giftOn)
                    {{-- و`display` في `style` يغلب `hidden` لولا هذا — فيظهر المطويّ --}}
                    <style>[data-rb-giftbox][hidden], [data-rb-giftloc][hidden], [data-rb-locnote][hidden], [data-rb-occasion-other][hidden] { display: none !important; }</style>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;min-height:44px;margin-bottom:8px" data-testid="rb-gift-toggle">
                        <input type="checkbox" data-rb-gift style="width:18px;height:18px;accent-color:var(--rb-olive)">
                        <span>{{ $t['giftOrder'] }}</span>
                    </label>
                    <div data-rb-giftbox hidden data-testid="rb-gift-box" style="border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px;margin-bottom:16px;display:flex;flex-direction:column;gap:14px">
                        @if ($recipInGift)
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                                <div><input class="rb-input" name="recipient_name" {!! $en !!} placeholder="{{ $t['fRecipient'] }} *" aria-label="{{ $t['fRecipient'] }}" aria-required="true"><div class="rb-error" data-err="recipient_name"></div></div>
                                <div><input class="rb-input" name="recipient_phone" dir="ltr" placeholder="{{ $t['fRecipientPhone'] }} *" aria-label="{{ $t['fRecipientPhone'] }}" aria-required="true"><div class="rb-error" data-err="recipient_phone"></div></div>
                            </div>
                        @endif
                        <div>
                            <label for="rb-occasion" style="font-size:13px">{{ $t['occasionOptional'] }}</label>
                            <select id="rb-occasion" class="rb-input" name="occasion" data-rb-occasion style="margin-top:6px">
                                <option value="">—</option>
                                @foreach ($giftOrder['occasions'] as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                            <div data-rb-occasion-other hidden style="margin-top:8px">
                                <input class="rb-input" name="occasion_text" maxlength="{{ $giftOrder['occasion_max'] }}" placeholder="{{ $t['enterOccasion'] }}" aria-label="{{ $t['enterOccasion'] }}">
                            </div>
                            <div class="rb-error" data-err="occasion"></div><div class="rb-error" data-err="occasion_text"></div>
                        </div>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;min-height:44px">
                            <input type="checkbox" name="hide_sender" data-rb-hide-sender style="width:18px;height:18px;accent-color:var(--rb-olive)">
                            <span>{{ $t['hideSender'] }}</span>
                        </label>
                        {{-- وموقعُ المستلِم للتوصيل وحده: يكتبه الآن، أو نتواصل معه --}}
                        <fieldset data-rb-giftloc style="border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:6px">
                            <legend style="font-size:13px;margin-bottom:6px;padding:0">{{ $t['recipientLocation'] }}</legend>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;min-height:40px">
                                <input type="radio" name="recipient_location" value="provided" checked style="width:18px;height:18px;accent-color:var(--rb-olive)">
                                <span>{{ $t['locProvided'] }}</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;min-height:40px">
                                <input type="radio" name="recipient_location" value="contact_recipient" style="width:18px;height:18px;accent-color:var(--rb-olive)">
                                <span>{{ $t['locContact'] }}</span>
                            </label>
                            <p data-rb-locnote hidden style="margin:0;font-size:12px;line-height:1.7">{{ $t['locContactNote'] }}</p>
                            <div class="rb-error" data-err="recipient_location"></div>
                        </fieldset>
                    </div>
                @endif

                @if ($giftCard['on'])
                    {{-- والكرتُ صنفٌ يُباع: ثمنُه مكتوبٌ حيث يُختار لا في الفاتورة وحدها --}}
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px" data-testid="rb-giftcard-toggle">
                        <input type="checkbox" data-rb-giftcard style="width:18px;height:18px;accent-color:var(--rb-olive)">
                        <span>{{ $t['addCard'] }}</span>
                        <span style="margin-inline-start:auto;font-weight:500;color:var(--rb-olive)" data-rb-cardprice>{{ $giftCard['price_text'] }}</span>
                    </label>
                    <div class="rb-error" data-err="gift_card"></div>

                    <div data-rb-cardbox style="display:none;margin-top:14px;border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px">
                        {{--
                            طريقةٌ واحدة لا اثنتان.

                            كان الصندوقُ يعرض خانةَ كتابةٍ **ورفعَ ملفٍّ معًا**، فيكتب
                            الزبونُ رسالةً ويرفع تصميمًا ولا يعرف أيُّهما يُطبع — ولا
                            يعرفه من يطبعه. فصار اختيارًا: يكتب أو يرفع.

                            والخادمُ يحكم كذلك (`WebCheckout`) — الشاشةُ تُرشد،
                            والحمولةُ تُكتب بيد من شاء.
                        --}}
                        <div style="font-size:13px">{{ $t['cardWay'] }}</div>
                        <div style="display:flex;gap:8px;margin-top:8px" data-rb-cardway>
                            <button type="button" class="rb-pill on" data-v="text" data-testid="rb-card-way-text" style="flex:1;height:40px;font-size:13px">{{ $t['cardWayText'] }}</button>
                            <button type="button" class="rb-pill" data-v="file" data-testid="rb-card-way-file" style="flex:1;height:40px;font-size:13px">{{ $t['cardWayFile'] }}</button>
                        </div>
                        <div class="rb-error" data-err="card"></div>

                        <div data-rb-cardtext style="margin-top:14px">
                        <textarea class="rb-input" name="card" rows="4" maxlength="500" placeholder="{{ $t['fCard'] }}" aria-label="{{ $t['fCard'] }}"></textarea>
                        <div style="font-size:12px;margin-top:6px">{{ $t['cardHint'] }}</div>

                        <div style="margin-top:14px;font-size:13px">{{ $t['cardAlign'] }}</div>
                        <div style="display:flex;gap:8px;margin-top:8px" data-rb-align>
                            @foreach (['right' => 'alignRight', 'center' => 'alignCenter', 'left' => 'alignLeft'] as $v => $k)
                                <button type="button" class="rb-pill{{ $v === 'right' ? ' on' : '' }}" data-v="{{ $v }}" style="flex:1;height:40px;font-size:13px">{{ $t[$k] }}</button>
                            @endforeach
                        </div>

                        {{-- ويُرى النصُّ مرتَّبًا قبل أن يُدفع ثمنُه — لا بعد أن يُطبع --}}
                        <div style="margin-top:14px;font-size:12px">{{ $t['cardPreview'] }}</div>
                        <div data-rb-cardpreview style="margin-top:8px;min-height:92px;white-space:pre-wrap;word-break:break-word;border:1px dashed var(--rb-line);border-radius:var(--rb-r);background:var(--rb-soft);padding:14px;font-size:14px;line-height:1.9;text-align:right"></div>
                        </div>

                        <div data-rb-cardfilebox style="display:none;margin-top:14px">
                        <div style="font-size:13px">{{ $t['cardFile'] }}</div>
                        <input type="file" data-rb-cardfile accept="{{ $giftCard['accept'] }}" aria-label="{{ $t['cardFile'] }}" style="margin-top:8px;font-size:13px;max-width:100%">
                        <div style="font-size:12px;margin-top:6px">{{ $t['cardFileHint'] }}</div>
                        <div data-rb-cardfilemsg style="font-size:12px;margin-top:6px"></div>
                        <div class="rb-error" data-err="card_file"></div>
                        </div>
                    </div>
                @elseif ($giftCard['message_only'])
                    {{--
                        ═══ رسالةٌ بلا ثمن — لا كرتٌ يُباع (`GiftCard::messageOnly`) ═══

                        خانةٌ بلا ثمنٍ بجانبها ولا «مجّانيّ»، ونصٌّ وحده بلا رفع
                        ملفّ. وتبديلُها لا يُعيد التسعير: الإجماليُّ لا يتبدّل بها.
                        والنصُّ يذهب في `card` وحده — انظر `js/store/ribbon-card-message.js`.
                    --}}
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;min-height:44px" data-testid="rb-cardmsg-toggle">
                        <input type="checkbox" data-rb-cardmsg style="width:18px;height:18px;accent-color:var(--rb-olive)">
                        <span>{{ $t['addCardMessage'] }}</span>
                    </label>
                    <div data-rb-cardmsgbox hidden data-testid="rb-cardmsg-box" style="margin-top:10px;border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px">
                        <div style="font-size:13px;margin-bottom:8px">{{ $t['cardMessageHint'] }}</div>
                        <textarea class="rb-input" name="card" rows="4" maxlength="500" placeholder="{{ $t['fCard'] }}" aria-label="{{ $t['fCard'] }}"></textarea>
                        <div style="font-size:12px;margin-top:6px">{{ $t['cardHint'] }}</div>
                        <div class="rb-error" data-err="card"></div>
                        <div style="margin-top:14px;font-size:12px">{{ $t['cardPreview'] }}</div>
                        <div data-rb-cardmsgpreview style="margin-top:8px;min-height:72px;white-space:pre-wrap;word-break:break-word;border:1px dashed var(--rb-line);border-radius:var(--rb-r);background:var(--rb-soft);padding:14px;font-size:14px;line-height:1.9;text-align:start"></div>
                    </div>
                @elseif ($giftCard['as_product'])
                    {{--
                        ═══ ولا شيءَ من الكرت هنا — كرتُه صنفٌ من الرفّ ═══

                        يُشترى من صفحته ونصُّه معه (`GiftCardProduct`): لا رسالةٌ
                        ولا كرتٌ مدفوعٌ ولا رفعُ ملفّ في الإتمام.
                    --}}
                @else
                    <textarea class="rb-input" name="card" rows="3" maxlength="500" placeholder="{{ $t['fCard'] }}" aria-label="{{ $t['fCard'] }}"></textarea>
                    <div style="font-size:12px;margin-top:6px">{{ $t['cardHint'] }}</div>
                @endif
            </div>
            <div>
                {!! $step(4, $t['s4']) !!}
                <div style="display:flex;flex-direction:column;gap:8px" data-rb-pay>
                    {{--
                        كلُّ وسيلةٍ باسمها — لا «تحويلٌ وما سواه نقد».
                        كانت البطاقةُ تُكتب «الدفع عند الاستلام» فيختارها الزبونُ
                        ظانًّا أنّه يدفع عند الباب، ثمّ تفتح له صفحةُ البنك. ووسيلةٌ
                        لا اسمَ لها هنا تُكتب بمفتاحها — لا باسمِ وسيلةٍ أخرى.
                    --}}
                    @php($payLabel = ['cod' => 'payCod', 'transfer' => 'payBank', 'card' => 'payCard'])
                    @foreach ($payments as $i => $p)
                        <button type="button" class="rb-payopt {{ $i === 0 ? 'on' : '' }}" data-v="{{ $p }}" style="display:flex;align-items:center;gap:12px;height:52px;border:1px solid var(--rb-border);border-radius:var(--rb-r);background:#fff;padding:0 16px;font-size:14px;cursor:pointer;text-align:start">
                            <span style="width:18px;height:18px;border-radius:50%;border:1.5px solid var(--rb-olive);display:inline-flex;align-items:center;justify-content:center;flex:none"><span class="dot" style="width:10px;height:10px;border-radius:50%"></span></span>
                            <span style="flex:1">{{ isset($payLabel[$p]) ? $t[$payLabel[$p]] : $p }}</span>
                            <span style="font-size:12px">{{ isset($payLabel[$p]) ? $t[$payLabel[$p].'Note'] : '' }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="rb-error" data-err="pay"></div>
                <div data-rb-banknote style="display:none;border:1px solid var(--rb-line);border-radius:var(--rb-r);background:#fff;padding:14px;font-size:14px;line-height:1.8;margin-top:12px">{{ $t['bankNote'] }}</div>
            </div>
        </div>
        <aside class="rb-box">
            <h2 style="margin:0 0 16px;font-size:17px;font-weight:500">{{ $t['summary'] }}</h2>
            <div data-rb-summary style="display:flex;flex-direction:column;gap:12px;border-bottom:1px solid var(--rb-line);padding-bottom:16px;font-size:14px"></div>
            @if ($shows('promo'))
                <div style="display:flex;gap:8px;margin:16px 0">
                    <input class="rb-input" name="promo" placeholder="{{ $t['promo'] }}" aria-label="{{ $t['promo'] }}" style="flex:1">
                    <button type="button" class="rb-btn-ghost" data-rb-apply style="height:46px;padding:0 16px">{{ $t['apply'] }}</button>
                </div>
            @endif
            <div class="rb-error" data-rb-promo-msg></div>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:14px;margin-top:8px">
                <div style="display:flex;justify-content:space-between"><span>{{ $t['subtotal'] }}</span><span data-rb-subtotal></span></div>
                <div style="display:none;justify-content:space-between" data-rb-discount-row><span>{{ $t['discount'] }}</span><span data-rb-discount></span></div>
                <div style="display:none;justify-content:space-between" data-rb-tax-row><span>{{ $t['tax'] }}</span><span data-rb-tax></span></div>
                <div style="display:flex;justify-content:space-between"><span>{{ $t['shipping'] }}</span><span data-rb-ship></span></div>
                <div style="display:flex;justify-content:space-between;border-top:1px solid var(--rb-line);padding-top:12px;font-size:16px"><span>{{ $t['total'] }}</span><strong data-rb-total></strong></div>
            </div>
            {{--
                وفوق الزرّ لا تحته — هذه لحظةُ الدفع.

                وهو السطرُ الذي يمنع النزاع فعلًا: لا درعًا قانونيًّا، بل
                آخرَ ما قرأه قبل أن يدفع. وصفحةُ المنتج قد تكون قبل ثلاثة
                أيّام، ولا أحدَ يتذكّر ما قرأه فيها.
            --}}
            @if ($imageNote !== '')
                <p class="rb-note" data-testid="rb-image-note">{{ $imageNote }}</p>
            @endif
            <div class="rb-error" data-rb-form-error style="margin-top:12px"></div>

            {{--
                ═══ وموافقةٌ صريحةٌ على سعرٍ تبدّل ═══

                الكودُ قد ينتهي بين لحظةِ التسعير ولحظةِ التأكيد. فلا يمضي
                الطلبُ بسعرٍ لم يره الزبون، ولا تكفي إعادةُ إرسالٍ صامتة:
                يُقال له السببُ والإجماليُّ الجديد، ويُقرّ بضغطةٍ واحدة.

                ومن لم يوافق فلا طلبَ — «تراجَع» تُغلق الصندوق ويبقى في صفحته.
            --}}
            <div data-rb-agree-box style="display:none;margin-top:14px;border:1px solid var(--rb-line);border-radius:var(--rb-r);padding:14px;background:var(--rb-sand,#faf8f5)">
                <p style="margin:0 0 6px;font-weight:500">{{ $t['priceChanged'] }}</p>
                <p data-rb-agree-why style="margin:0 0 12px;font-size:14px;color:var(--rb-ink-soft,#6b6b6b)"></p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="rb-btn" data-rb-agree style="flex:1;min-width:160px">{{ $t['agreeNew'] }}</button>
                    <button type="button" class="rb-btn-ghost" data-rb-agree-no style="flex:1;min-width:120px">{{ $t['keepBrowsing'] }}</button>
                </div>
            </div>

            <button type="submit" class="rb-btn" style="width:100%;margin-top:16px" data-testid="rb-place"><span data-rb-place-label>{{ $t['place'] }}</span></button>
        </aside>
    </form>
    @endif
</section>
@endsection
@section('scripts')
@if ($accepts)
@if ($giftCard['message_only'])
<script>{!! file_get_contents(resource_path('js/store/ribbon-card-message.js')) !!}</script>
@endif
@if ($giftOrder['on'] ?? false)
<script>{!! file_get_contents(resource_path('js/store/ribbon-gift-order.js')) !!}</script>
@endif
<script>
(function () {
    // والطريقةُ الابتدائيّة من المفتوح لا من ظنٍّ في الشاشة
    var T = @json($t), fulfil = @json($fulfilments[0] ?? 'delivery'), pay = @json($payments[0] ?? null), promo = '';
    /*
     * الإجماليُّ الذي عُرض للزبون آخرَ مرّة — يُرسَل مع الطلب.
     *
     * لا يُحسب منه شيء: التسعيرُ في الخادم وحده. وإنّما يقول «هذا ما رآه
     * ووافق عليه»، فإن سقط الخصمُ بين التسعيرة والإتمام عُلم أنّه لم يوافق
     * على الجديد، فيُردّ ويُقال له — لا يمضي بسعرٍ لم يره.
     */
    var shownTotal = null;
    var form = document.querySelector('[data-rb-form]');
    // ورمزُ الحماية يُقرأ هنا كذلك: `RB.post` يحمله، ورفعُ الملفّ يخرج عنه
    var CSRF = document.querySelector('meta[name=csrf-token]').content;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function $(sel) { return form.querySelector(sel); }
    function paintPay() {
        form.querySelectorAll('.rb-payopt').forEach(function (b) {
            var on = b.dataset.v === pay; b.classList.toggle('on', on);
            b.style.borderColor = on ? 'var(--rb-olive)' : 'var(--rb-border)'; b.style.background = on ? 'var(--rb-soft)' : '#fff';
            b.querySelector('.dot').style.background = on ? 'var(--rb-olive)' : 'transparent';
        });
        $('[data-rb-banknote]').style.display = pay === 'transfer' ? 'block' : 'none';
    }
    function refresh() {
        if (!RB.read().length) { location.href = RB.base + '/cart'; return; }
        RB.quote({ fulfil: fulfil, promo: promo, gift_card: gift }).then(function (q) {
            var msg = $('[data-rb-promo-msg]'); msg.textContent = '';
            if (!q.ok) { $('[data-rb-form-error]').textContent = Object.values(q.errors || {}).flat().join(' · '); return; }
            $('[data-rb-summary]').innerHTML = q.lines.map(function (l) {
                return '<div style="display:flex;justify-content:space-between;gap:12px"><span>' + esc(l.name) + (l.variant ? ' · ' + esc(l.variant) : '') + ' × ' + l.qty + '</span><span>' + esc(l.line_text) + '</span></div>';
            }).join('');
            $('[data-rb-subtotal]').textContent = q.subtotal_text;
            $('[data-rb-discount-row]').style.display = q.discount > 0 ? 'flex' : 'none'; $('[data-rb-discount]').textContent = '− ' + q.discount_text;
            $('[data-rb-tax-row]').style.display = q.tax > 0 ? 'flex' : 'none'; $('[data-rb-tax]').textContent = q.tax_text;
            $('[data-rb-ship]').textContent = q.delivery > 0 ? q.delivery_text : T.free;
            $('[data-rb-total]').textContent = q.total_text;
            shownTotal = q.total;
            $('[data-rb-place-label]').textContent = T.place + ' · ' + q.total_text;
            if (q.promo_error) { msg.textContent = q.promo_error; } else if (q.coupon) { msg.style.color = 'var(--rb-olive)'; msg.textContent = '✓ ' + q.coupon; }
        });
    }
    /*
     * ═══ والكرتُ يُسعَّر في الخادم ═══
     *
     * ثمنُه يُقرأ من إعداد المحلّ، فلا يُجمع هنا على المجموع. وتبديلُ
     * الخانة يُعيد التسعير كما يُعيده تبديلُ التوصيل — فما يُرى في الزرّ
     * هو ما يُكتب في الفاتورة، لا ما حسبه المتصفّح.
     */
    var gift = false, align = 'right', cardFile = null, cardFileName = null, cardWay = 'text';

    var forOther = form.querySelector('[data-rb-forother]');
    if (forOther) {
        forOther.addEventListener('change', function () {
            form.querySelector('[data-rb-recipient]').style.display = forOther.checked ? 'grid' : 'none';
            if (!forOther.checked) {
                form.querySelectorAll('[name=recipient_name],[name=recipient_phone]').forEach(function (i) { i.value = ''; });
            }
        });
    }

    /*
     * والرسالةُ المجّانيّة لا تمسّ `gift` ولا تُعيد التسعير — انظر
     * `js/store/ribbon-card-message.js`. وحيث لا خانةَ لها يبقى `null`.
     */
    var msgToggle = form.querySelector('[data-rb-cardmsg]');
    var cardMsg = msgToggle ? RBCardMessage.mount(msgToggle, form.querySelector('[data-rb-cardmsgbox]'), form.querySelector('[name=card]'), form.querySelector('[data-rb-cardmsgpreview]')) : null;

    var giftBox = form.querySelector('[data-rb-cardbox]');
    var giftToggle = form.querySelector('[data-rb-giftcard]');
    var preview = form.querySelector('[data-rb-cardpreview]');
    var cardText = form.querySelector('[name=card]');

    function paintPreview() {
        if (!preview) return;
        preview.style.textAlign = align;
        preview.textContent = cardText ? cardText.value : '';
    }

    if (giftToggle) {
        giftToggle.addEventListener('change', function () {
            gift = giftToggle.checked;
            giftBox.style.display = gift ? 'block' : 'none';
            refresh();
        });
        /*
            الطريقةُ اختيارٌ واحد — وتبديلُها يمحو ما كُتب بالأخرى.

            ولا يُترك نصٌّ خفيٌّ خلف زرّ: من بدّل إلى الملفّ ثمّ أرسل، لا
            يُرسل معه رسالةً كتبها ونسيها — فيطبعها المحلُّ ولا يدري أنّه
            تراجع عنها.
        */
        var wayBox = form.querySelector('[data-rb-cardway]');
        var textBox = form.querySelector('[data-rb-cardtext]');
        var fileBox = form.querySelector('[data-rb-cardfilebox]');

        if (wayBox) {
            wayBox.addEventListener('click', function (e) {
                var b = e.target.closest('button'); if (!b) return;
                cardWay = b.dataset.v;
                form.querySelectorAll('[data-rb-cardway] button').forEach(function (x) { x.classList.toggle('on', x === b); });
                textBox.style.display = cardWay === 'text' ? 'block' : 'none';
                fileBox.style.display = cardWay === 'file' ? 'block' : 'none';
                form.querySelector('[data-err="card"]').textContent = '';

                if (cardWay === 'file') {
                    if (cardText) { cardText.value = ''; paintPreview(); }
                } else {
                    cardFile = null; cardFileName = null;
                    var fi = form.querySelector('[data-rb-cardfile]');
                    if (fi) fi.value = '';
                    var fm = form.querySelector('[data-rb-cardfilemsg]');
                    if (fm) fm.textContent = '';
                }
            });
        }

        form.querySelector('[data-rb-align]').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            align = b.dataset.v;
            form.querySelectorAll('[data-rb-align] button').forEach(function (x) { x.classList.toggle('on', x === b); });
            paintPreview();
        });
        if (cardText) cardText.addEventListener('input', paintPreview);

        /*
         * والملفُّ يُرفع وحدَه قبل الطلب.
         *
         * فالطلبُ يُرسَل JSON ويُعاد تسعيرُه مرارًا، ورفعُ الملفّ معه في
         * كلّ مرّةٍ يرفعه مرارًا. ويعود عنه رمزٌ يُرسَل مع الطلب — انظر
         * `Store\GiftCard::hold`.
         */
        var fileInput = form.querySelector('[data-rb-cardfile]');
        var fileMsg = form.querySelector('[data-rb-cardfilemsg]');
        fileInput.addEventListener('change', function () {
            var f = fileInput.files && fileInput.files[0];
            cardFile = null; cardFileName = null;
            form.querySelector('[data-err="card_file"]').textContent = '';
            if (!f) { fileMsg.textContent = ''; return; }

            fileMsg.textContent = T.cardFileWait;
            var body = new FormData(); body.append('file', f);
            fetch(RB.base + '/gift-card', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: body })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) {
                        fileMsg.textContent = '';
                        fileInput.value = '';
                        form.querySelector('[data-err="card_file"]').textContent =
                            Object.values(j.errors || {}).flat().join(' ') || T.cardFileErr;
                        return;
                    }
                    cardFile = j.token; cardFileName = j.name; fileMsg.textContent = '✓ ' + j.name;
                })
                .catch(function () { fileMsg.textContent = ''; fileInput.value = ''; form.querySelector('[data-err="card_file"]').textContent = T.cardFileErr; });
        });
    }

    /*
     * وأزرارُ الاستلام قد لا تكون: من فتح طريقةً واحدةً لا يُسأل عنها،
     * فتُقرأ من الخادم ولا تُعرض. وقارئٌ يفترض وجودَها يسقط الصفحةَ كلَّها.
     */
    var fulfilPills = form.querySelector('[data-rb-fulfil]');
    /*
        والهديّةُ — انظر `js/store/ribbon-gift-order.js`. وحيث لا ميزةَ يبقى
        `null` ولا يُرسَل منها شيء.
    */
    var giftOrderToggle = form.querySelector('[data-rb-gift]');
    var giftOrder = giftOrderToggle ? RBGiftOrder.mount(form, giftOrderToggle) : null;
    function paintFulfil() {
        var d = form.querySelector('[data-rb-delivery]');
        var p = form.querySelector('[data-rb-pickup]');
        // وهديّةٌ يتواصل المتجرُ مع مستلِمها لا تُسأل عن عنوانٍ لا يعرفه المشتري
        var askAddress = fulfil === 'delivery' && !(giftOrder && giftOrder.contacts(fulfil));
        if (d) d.style.display = askAddress ? 'grid' : 'none';
        if (p) p.style.display = fulfil === 'pickup' ? 'block' : 'none';
        if (giftOrder) giftOrder.fulfil(fulfil);
    }
    if (giftOrder) giftOrder.onChange(paintFulfil);
    if (fulfilPills) {
        fulfilPills.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            fulfil = b.dataset.v;
            form.querySelectorAll('[data-rb-fulfil] button').forEach(function (x) { x.classList.toggle('on', x === b); });
            paintFulfil();
            refresh();
        });
    }
    paintFulfil();
    $('[data-rb-pay]').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; pay = b.dataset.v; paintPay(); });
    var applyBtn = form.querySelector('[data-rb-apply]');
    if (applyBtn) applyBtn.addEventListener('click', function () { promo = $('[name=promo]').value.trim(); refresh(); });
    /*
     * والموافقةُ تُرسل الطلبَ بالإجماليّ الذي صار إليه — وهو ما في `shownTotal`
     * بعد إعادةِ التسعير. و«تراجَع» تُغلق الصندوق ولا تُنشئ شيئًا.
     */
    var agreeBtn = $('[data-rb-agree]'), agreeNo = $('[data-rb-agree-no]');
    if (agreeBtn) agreeBtn.addEventListener('click', function () {
        $('[data-rb-agree-box]').style.display = 'none';
        form.dispatchEvent(new Event('submit', { cancelable: true }));
    });
    if (agreeNo) agreeNo.addEventListener('click', function () { $('[data-rb-agree-box]').style.display = 'none'; });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        form.querySelectorAll('[data-err]').forEach(function (d) { d.textContent = ''; });
        form.querySelectorAll('.rb-input').forEach(function (i) { i.classList.remove('err'); });
        $('[data-rb-form-error]').textContent = '';
        var btn = $('[data-testid=rb-place]'); btn.disabled = true;
        var payload = {
            items: RB.read(), fulfil: fulfil, pay: pay, promo: promo,
            name: $('[name=name]').value, phone: $('[name=phone]').value,
            area: $('[name=area]') ? $('[name=area]').value : '',
            address: $('[name=address]') ? $('[name=address]').value : '',
            date: $('[name=date]') ? $('[name=date]').value : '',
            slot: $('[name=slot]') ? $('[name=slot]').value : '',
            card: cardMsg ? cardMsg.card() : ((cardWay === 'text' && $('[name=card]')) ? $('[name=card]').value : ''),
            recipient_name: $('[name=recipient_name]') ? $('[name=recipient_name]').value : '',
            recipient_phone: $('[name=recipient_phone]') ? $('[name=recipient_phone]').value : '',
            gift_card: gift, card_align: align,
            // ما رآه ووافق عليه — انظر `shownTotal` أعلاه
            agreed_total: shownTotal,
            card_file: cardWay === 'file' ? cardFile : null,
            card_file_name: cardWay === 'file' ? cardFileName : null,
        };
        // وحقولُ الهديّة إن كانت هديّة — وإلّا `is_gift: false` وحدها
        if (giftOrder) Object.assign(payload, giftOrder.payload(fulfil));
        RB.post('/checkout', payload).then(function (r) {
            btn.disabled = false;
            /*
             * وخطأُ الكود يُقرأ تحت حقله، ويُفتح صندوقُ الموافقة بالسبب
             * والإجماليّ الجديد. ولا يُرسَل الطلبُ من نفسه: الإقرارُ ضغطةٌ.
             */
            if (r && r.errors && r.errors.promo) {
                var why = [].concat(r.errors.promo).join(' · ');
                var pm = $('[data-rb-promo-msg]');
                if (pm) { pm.style.color = 'var(--rb-clay, #b91c1c)'; pm.textContent = why; }
                var box = $('[data-rb-agree-box]');
                if (box) { $('[data-rb-agree-why]').textContent = why; box.style.display = 'block'; }
                // وتُعاد التسعيرةُ ليقرأ في الملخّص ما سيدفعه فعلًا
                refresh();
            }
            if (r.ok) { RB.clear(); location.href = r.redirect; return; }
            var errs = r.errors || {}; var any = false;
            Object.keys(errs).forEach(function (k) {
                var d = form.querySelector('[data-err="' + k + '"]'); var i = form.querySelector('[name="' + k + '"]');
                if (d) { d.textContent = errs[k].join(' '); any = true; } if (i) i.classList.add('err');
            });
            $('[data-rb-form-error]').textContent = any ? T.errReq : Object.values(errs).flat().join(' · ');
        }).catch(function () { btn.disabled = false; $('[data-rb-form-error]').textContent = T.errReq; });
    });
    paintPay(); refresh();
})();
</script>
@endif
@endsection

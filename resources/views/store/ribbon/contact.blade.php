{{--
    «تواصل معنا» — ما كتبه في بيانات نشاطه ومتجره، لا حقولٌ تُملأ مرّتين.

    والهاتفُ يُضغط فيتّصل، والواتسابُ يفتح المحادثة، والبريدُ يفتح البريد:
    الزائر على هاتفه لا ينسخ رقمًا ثمّ يلصقه في تطبيق الاتّصال.

    ولا خريطةَ مُضمَّنة — انظر `RibbonController::contact`.
--}}
@extends('store.ribbon.layout')
@section('title', $seo['title'])
@section('content')
<section class="rb-screen" data-testid="rb-contact">
    <div style="background:var(--rb-soft)">
        <div class="rb-wrap" style="padding:clamp(32px,4.5vw,64px) 24px;display:flex;flex-direction:column;gap:12px">
            {{-- ولا سطرَ فوق العنوان يقول ما يقوله: «تواصل معنا» مرّتين ليس تأكيدًا --}}
            <h1 class="rb-h1" style="margin:0">{{ $t['contactTitle'] }}</h1>
            <p style="margin:0;font-size:15px;line-height:1.7;max-width:520px">{{ $t['contactSub'] }}</p>
        </div>
    </div>

    <div class="rb-wrap" style="padding:clamp(32px,4.5vw,64px) 24px;display:flex;flex-direction:column;gap:32px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:20px">
            @if (isset($lines['phone']))
                <div class="rb-cbox" data-testid="rb-c-phone">
                    <span class="rb-clabel">{{ $t['cPhone'] }}</span>
                    <a dir="ltr" href="tel:{{ preg_replace('/[^\d+]/', '', $lines['phone']) }}">{{ $lines['phone'] }}</a>
                </div>
            @endif
            @if (isset($lines['whatsapp']))
                <div class="rb-cbox" data-testid="rb-c-whatsapp">
                    <span class="rb-clabel">{{ $t['cWhatsapp'] }}</span>
                    <a dir="ltr" href="https://wa.me/{{ preg_replace('/\D/', '', $lines['whatsapp']) }}" target="_blank" rel="noopener">{{ $lines['whatsapp'] }}</a>
                </div>
            @endif
            @if (isset($lines['email']))
                <div class="rb-cbox" data-testid="rb-c-email">
                    <span class="rb-clabel">{{ $t['cEmail'] }}</span>
                    <a dir="ltr" href="mailto:{{ $lines['email'] }}">{{ $lines['email'] }}</a>
                </div>
            @endif
            @if (isset($lines['address']))
                <div class="rb-cbox" data-testid="rb-c-address">
                    <span class="rb-clabel">{{ $t['cAddress'] }}</span>
                    <span>{{ $lines['address'] }}</span>
                    @if ($mapUrl)
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener" data-testid="rb-c-map">{{ $t['openMap'] }}</a>
                    @endif
                </div>
            @endif
            @if (isset($lines['hours']))
                <div class="rb-cbox" data-testid="rb-c-hours">
                    <span class="rb-clabel">{{ $t['cHours'] }}</span>
                    <span style="white-space:pre-line">{{ $lines['hours'] }}</span>
                </div>
            @endif
        </div>

        {{-- وزرّان كبيران لمن فتحها على هاتفه — لا يُرسم منهما إلّا ما له رقم --}}
        @if (isset($lines['phone']) || isset($lines['whatsapp']))
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                @if (isset($lines['phone']))
                    <a class="rb-btn" href="tel:{{ preg_replace('/[^\d+]/', '', $lines['phone']) }}">{{ $t['callNow'] }}</a>
                @endif
                @if (isset($lines['whatsapp']))
                    <a class="rb-btn-ghost" href="https://wa.me/{{ preg_replace('/\D/', '', $lines['whatsapp']) }}" target="_blank" rel="noopener">{{ $t['waNow'] }}</a>
                @endif
            </div>
        @endif
    </div>
</section>
@endsection

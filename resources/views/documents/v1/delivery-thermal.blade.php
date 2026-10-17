{{--
    سندُ التسليم شريطًا حراريًّا — ٥٨ أو ٨٠ مم، بطول محتواه.

    يُقرأ كما يقرؤه حاملُه: اسمُ المتجر واسمُ الورقة، ثمّ أيُّ طلبٍ ومتى،
    ثمّ **إلى من وأين** — وهو ما يبحث عنه السائقُ أوّلًا — ثمّ ما يحمل،
    ثمّ الهديّةُ إن كانت، ثمّ ما يوقّعه المستلِم.

    والرموزُ رموزُ الإيصال (`partials/thermal-tokens`)، والطولُ يقيسه المحرّك
    ويقسمه إن طال (`MpdfDriver::strip`): لا رأسَ يتكرّر في وسط الشريط — ولذلك
    لا يُكتب العنوانُ ولا رأسُ القسم في `<thead>`.

    ولا مالَ هنا بحال، ولا سطرَ بتسميةٍ وبياض: ما لم يُكتب يغيب بتسميته.
    انظر `DocumentRenderer::deliveryStrip` و`DeliveryPaper`.
--}}
@php
    use App\Support\FlowerOrder;
    use App\Support\Paper;

    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k) => trim((string) ($tpl[$k] ?? ''));
    $width = $width ?? 80;

    $branch = $show('show_branch') ? trim((string) $order->branch) : '';
    $phone = trim((string) ($shop['phone'] ?? ''));
    $fulfil = match ((string) $order->fulfillment_type) {
        FlowerOrder::DELIVERY => __('توصيل'),
        FlowerOrder::PICKUP => __('استلام من المحل'),
        default => '',
    };
    $reached = array_filter($recipient);
    $pieces = $order->items->sum('quantity');
    // نصٌّ يحفظ أسطرَ كاتبه ويلتفّ عند الفراغ وحده — انظر `Paper::keepWords`
    $lines = fn (?string $s) => Paper::keepWords($s);
@endphp
@include('documents.v1.partials.thermal-tokens')

<div class="paper">

{{-- ١ · المتجر واسمُ الورقة --}}
<div class="c">
    @if ($logo)
        <img src="{{ $logo }}" style="max-height:30pt; margin-bottom:2pt;" alt="">
    @endif
    <div class="shop">{{ $shop['name'] ?? __('نظام Abad POS') }}</div>
    @if ($line('header') !== '')
        <div class="muted tiny">{{ $line('header') }}</div>
    @endif
    @if ($branch !== '')
        <div class="muted tiny">{{ $branch }}</div>
    @endif
    @if ($phone !== '')
        <div class="muted tiny" dir="ltr">{{ $phone }}</div>
    @endif
</div>

<div class="rule"></div>

<div class="c title">{{ __('سند تسليم') }}</div>

{{-- ٢ · أيُّ طلبٍ، ومتى يُسلَّم، وكيف --}}
<table class="kv">
    <tr><td class="k">{{ __('رقم الطلب') }}</td><td class="l">{{ $order->number }}</td></tr>
    @if ($show('show_datetime') && $order->ordered_at)
        <tr><td class="k">{{ __('تاريخ الطلب') }}</td><td class="l">{{ $order->ordered_at->format('Y-m-d H:i') }}</td></tr>
    @endif
    @if ($order->scheduled_for)
        <tr><td class="k">{{ __('موعد التسليم') }}</td><td class="l b">{{ $order->scheduled_for->format('Y-m-d H:i') }}</td></tr>
    @endif
    @if ($fulfil !== '')
        <tr><td class="k">{{ __('نوع التسليم') }}</td><td class="v">{{ $fulfil }}</td></tr>
    @endif
</table>

{{-- ٣ · المستلِم — والطويلُ تحت تسميته بعرض الشريط كلِّه، يلتفّ ولا يُقصّ --}}
@if ($reached !== [])
    <div class="rule"></div>
    <div class="sec">{{ __('بيانات المستلم') }}</div>
    <table class="kv">
        @if ($recipient['name'])
            <tr><td class="k">{{ __('الاسم') }}</td><td class="v" dir="{{ Paper::dirOf($recipient['name']) }}">{{ $recipient['name'] }}</td></tr>
        @endif
        @if ($recipient['phone'])
            <tr><td class="k">{{ __('الهاتف') }}</td><td class="l">{{ $recipient['phone'] }}</td></tr>
        @endif
    </table>
    @if ($recipient['address'])
        <div class="cap">{{ __('العنوان') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($recipient['address']) }}">{!! $lines($recipient['address']) !!}</div>
    @endif
    @if ($recipient['notes'])
        <div class="cap">{{ __('ملاحظات التوصيل') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($recipient['notes']) }}">{!! $lines($recipient['notes']) !!}</div>
    @endif
@endif

{{--
    ٤ · الأصناف — عمودان لا أربعة.

    جدولُ الإيصال بإطفاء السعر يترك عمودين خاويين. وهنا الاسمُ وتحته ما
    يميّزه (ملاحظتُه وخياراتُه الظاهرة)، والكميّةُ في عمودٍ ضيّق تقرؤه العين
    بلا أن تقطع الشريط. ورأسُه صفٌّ عاديّ: `<thead>` يتكرّر في كلّ قسمٍ
    من الشريط الطويل.
--}}
<div class="rule"></div>
<div class="sec">{{ __('الأصناف') }}</div>
<table class="items">
    <tbody>
        @foreach ($items as $it)
            <tr>
                {{-- والاسمُ نصٌّ في الخليّة نفسِها، كلماتٍ لا تنكسر: وإلّا صغّر mpdf الجدولَ كلَّه بدل أن يلفّ السطر (`Paper::keepWords`) --}}
                <td class="bidi" dir="{{ Paper::dirOf($it['name']) }}">
                    {!! $lines($it['name']) !!}
                    @if ($it['note'])
                        <div class="bidi muted tiny" dir="{{ Paper::dirOf($it['note']) }}">— {!! $lines($it['note']) !!}</div>
                    @endif
                    @foreach ($it['options'] as $opt)
                        <div class="bidi muted tiny" dir="{{ Paper::dirOf($opt['label'].' '.$opt['value']) }}">— {!! $lines($opt['label'].': '.$opt['value']) !!}</div>
                    @endforeach
                </td>
                <td class="c b" style="width:{{ $width <= 60 ? 16 : 12 }}%; vertical-align:top" dir="ltr">× {{ $it['qty'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@if ($show('show_items_count'))
    <div class="muted tiny" style="margin-top:2pt">{{ __('عدد القطع') }}: <span dir="ltr">{{ \App\Support\DocumentPaper::qty($pieces) }}</span></div>
@endif

{{-- ٥ · الهديّة — ولا رأسَ لها إن لم يبقَ ما يُطبع، ولا «من:» إن أُخفي المُهدي --}}
@if ($gift)
    <div class="rule"></div>
    <div class="sec">{{ __('هدية') }}</div>
    <table class="kv">
        @if ($gift['occasion'])
            <tr><td class="k">{{ __('المناسبة') }}</td><td class="v">{{ $gift['occasion'] }}</td></tr>
        @endif
        @if ($gift['sender'])
            <tr><td class="k">{{ __('من') }}</td><td class="v" dir="{{ Paper::dirOf($gift['sender']) }}">{{ $gift['sender'] }}</td></tr>
        @endif
    </table>
    @if ($gift['message'])
        <div class="cap">{{ __('رسالة البطاقة') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($gift['message']) }}">{!! $lines($gift['message']) !!}</div>
    @endif
@endif

{{-- ٦ · الاستلام — ثلاثة أسطرٍ قصيرة، لا مساحةٌ بيضاء تأكل الورق --}}
@if ($show('show_signature'))
    <div class="rule"></div>
    @php($labelWidth = $width <= 60 ? 44 : 34)
    <table class="sign">
        <tr><td class="k" style="width:{{ $labelWidth }}%">{{ __('اسم المستلم') }}</td><td class="line">&nbsp;</td></tr>
        <tr><td class="k" style="width:{{ $labelWidth }}%">{{ __('التوقيع') }}</td><td class="line">&nbsp;</td></tr>
        <tr><td class="k" style="width:{{ $labelWidth }}%">{{ __('تاريخ/وقت الاستلام') }}</td><td class="line">&nbsp;</td></tr>
    </table>
@endif

{{--
    ٧ · تذييلُ التاجر لهذه الورقة — من قالبها، وفراغُه يُطبع فراغًا.
    والافتراضيُّ الذي لم يُلمس يُترجَم بلغة الورقة؛ وما كتبه التاجرُ يُطبع كما كتبه.
--}}
@php($footer = $line('footer'))
@php($untouched = $footer === trim(\App\Support\DocumentTemplates::DEFAULT_FOOTER))
@if ($footer !== '')
    <div class="rule"></div>
    <div class="c muted tiny">
        @foreach (preg_split('/\r\n|\r|\n/', $footer) as $l)
            @php($l = $untouched ? __($l) : $l)
            @php($clean = \App\Support\ReceiptTemplate::printableHtml($l))
            @if ($clean !== ''){!! $clean !!}@if (! $loop->last)<br>@endif @endif
        @endforeach
    </div>
@endif

</div>

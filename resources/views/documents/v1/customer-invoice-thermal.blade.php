{{--
    «فاتورة عميل — نسخة داخلية» شريطًا حراريًّا — ٥٨ أو ٨٠ مم، بطول محتواه.

    ورقةُ الطاولة لا ورقةُ العميل. تخرج على صندوق الفرع حين تُسدَّد الفاتورة
    (`PrintJobs`)، ويُقرأ منها: أيُّ فاتورةٍ ولمن، ومتى تُسلَّم وكيف وإلى
    من، ثمّ ما يُجهَّز بكميّاته وأثمانه، ثمّ المالُ وحالُ سداده، ثمّ
    الهديّةُ وملاحظاتُ التجهيز.

    ولا تُرقَّم ولا تُقيَّد: رقمُها رقمُ الفاتورة الأصليّة، والفاتورةُ A4 هي
    الورقةُ الماليّة كما كانت. وتقول ذلك في ذيلها: «ليست فاتورة ضريبية».

    ولا فاتورةَ في يد القالب: `$doc` حمولةٌ بناها الخادم
    (`CustomerInvoicePreparations::internalPaper`). ولا `<thead>`: الشريطُ
    الطويل يُقسَم (`MpdfDriver::strip`) ورأسٌ يتكرّر في وسطه يُربك من يقرأ.
--}}
@php
    use App\Support\Paper;

    $width = $width ?? 80;
    $branch = (string) ($doc['branch'] ?? '');
    $phone = trim((string) ($shop['phone'] ?? ''));
    $recipient = $doc['recipient'];
    $reached = array_filter($recipient);
    $gift = $doc['gift'];
    $payment = $doc['payment'];
    $narrow = $width <= 60;
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
    @if ($branch !== '')
        <div class="muted tiny">{{ $branch }}</div>
    @endif
    @if ($phone !== '')
        <div class="muted tiny" dir="ltr">{{ $phone }}</div>
    @endif
</div>

<div class="rule"></div>

<div class="c title">{{ __('فاتورة عميل — نسخة داخلية') }}</div>

{{-- ٢ · أيُّ فاتورة، ولمن، ومتى وكيف تُسلَّم --}}
<table class="kv">
    <tr><td class="k">{{ __('رقم الفاتورة') }}</td><td class="l">{{ $doc['number'] }}</td></tr>
    @if ($doc['date'])
        <tr><td class="k">{{ __('تاريخ الفاتورة') }}</td><td class="l">{{ $doc['date'] }}</td></tr>
    @endif
    @if ($doc['customer']['name'])
        <tr><td class="k">{{ __('العميل') }}</td><td class="v" dir="{{ Paper::dirOf($doc['customer']['name']) }}">{{ $doc['customer']['name'] }}</td></tr>
    @endif
    @if ($doc['customer']['phone'])
        <tr><td class="k">{{ __('هاتف العميل') }}</td><td class="l">{{ $doc['customer']['phone'] }}</td></tr>
    @endif
    @if ($doc['fulfilment'])
        <tr><td class="k">{{ __('نوع التسليم') }}</td><td class="v">{{ $doc['fulfilment'] }}</td></tr>
    @endif
    @if ($doc['scheduled_for'])
        <tr><td class="k">{{ __('الموعد') }}</td><td class="l b">{{ $doc['scheduled_for'] }}</td></tr>
    @endif
</table>

{{-- ٣ · المستلِم — والطويلُ تحت تسميته بعرض الشريط، يلتفّ ولا يُقصّ --}}
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
        <div class="cap">{{ __('تعليمات التوصيل') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($recipient['notes']) }}">{!! $lines($recipient['notes']) !!}</div>
    @endif
@endif

{{--
    ٤ · الأصناف — الاسمُ وتحته الخصم، ثمّ السعرُ والكميّةُ والإجماليّ.
    والرأسُ صفٌّ عاديٌّ في الجسد: `<thead>` يتكرّر في كلّ قسمٍ من الشريط الطويل.
--}}
<div class="rule"></div>
<table class="items">
    <tbody>
        <tr>
            <td class="b">{{ __('الصنف') }}</td>
            <td class="c b" style="width:{{ $narrow ? 24 : 22 }}%">{{ __('السعر') }}</td>
            <td class="c b" style="width:{{ $narrow ? 14 : 12 }}%">{{ __('كمية') }}</td>
            <td class="l b" style="width:{{ $narrow ? 26 : 24 }}%">{{ __('الإجمالي') }}</td>
        </tr>
        @foreach ($doc['items'] as $it)
            {{-- والإضافةُ تحت صنفها صفٌّ أصغر بلا خطٍّ بينهما — كأسطر الإضافة في الإيصال (`thermal-tokens`) --}}
            @php($sub = ($it['child'] ?? false) ? 'addon' : '')
            <tr @if ($sub) data-line="addon" @endif>
                {{-- والاسمُ كلماتٌ لا تنكسر: وإلّا صغّر mpdf الجدولَ كلَّه بدل أن يلفّ السطر (`Paper::keepWords`) --}}
                {{-- والخليّةُ باتّجاه الورقة، والاسمُ وحده باتّجاهه: mpdf يُلبس ما تحت الخليّة اتّجاهَها --}}
                <td class="bidi name {{ $sub }}">
                    {{-- وبندُ الإضافة يُعلَّم بها — سطرٌ بثمنه كما فُوتر، لا يُدمج في غيره --}}
                    <div class="bidi" dir="{{ Paper::dirOf($it['name']) }}">@if ($it['addon'] ?? false)<span data-addon>+ </span>@endif{!! $lines($it['name']) !!}</div>
                    @if (($it['addon'] ?? false) && ! ($it['child'] ?? false))
                        {{-- واسمٌ لا فعل: «إضافة» وحدَها تُترجَم «Add» --}}
                        <div class="muted tiny">{{ __('بند إضافة') }}</div>
                    @endif
                    {{-- التسميةُ باتّجاه الورقة، والقيمةُ معزولةٌ باتّجاهها — والداخليُّ معلَّم --}}
                    @foreach ($it['details'] ?? [] as $d)
                        <div class="muted tiny" data-detail dir="{{ Paper::rtl() ? 'rtl' : 'ltr' }}">— @if ($d['internal'])({{ __('داخلي') }}) @endif{{ $d['label'] }}: <span dir="{{ Paper::dirOf($d['text']) }}">{{ $d['text'] }}</span></div>
                    @endforeach
                    @foreach ($it['included'] ?? [] as $inc)
                        <div class="muted tiny" data-detail="included">+ <span dir="{{ Paper::dirOf($inc['name']) }}">{{ $inc['name'] }}</span> × <span dir="ltr">{{ $inc['qty'] }}</span> — {{ __('ضمن السعر') }}</div>
                    @endforeach
                    @if ($it['discount'])
                        <div class="muted tiny">{{ __('خصم') }}: <span dir="ltr">{{ $it['discount'] }}</span></div>
                    @endif
                </td>
                <td class="c {{ $sub }}" style="vertical-align:top"><span dir="ltr">{{ $it['price'] }}</span></td>
                <td class="c b {{ $sub }}" style="vertical-align:top" dir="ltr">{{ $it['qty'] }}</td>
                <td class="l {{ $sub }}" style="vertical-align:top"><span dir="ltr">{{ $it['total'] }}</span></td>
            </tr>
        @endforeach
    </tbody>
</table>
<div class="muted tiny" style="margin-top:2pt">{{ __('عدد القطع') }}: <span dir="ltr">{{ $doc['pieces'] }}</span></div>

{{-- ٥ · المال — كما في الفاتورة الأصليّة، وحالُ سدادها كما يقرؤه الخادم الآن --}}
<div class="rule"></div>
<table class="tot">
    <tr><td class="k">{{ __('المجموع الفرعي') }}</td><td class="l"><span dir="ltr">{{ $doc['totals']['subtotal'] }}</span></td></tr>
    @if ($doc['totals']['discount'])
        <tr><td class="k">{{ __('الخصم') }}</td><td class="l"><span dir="ltr">{{ $doc['totals']['discount'] }}</span></td></tr>
    @endif
    @if ($doc['totals']['tax'])
        <tr><td class="k">{{ __('الضريبة') }}</td><td class="l"><span dir="ltr">{{ $doc['totals']['tax'] }}</span></td></tr>
    @endif
    <tr class="grand"><td>{{ __('الإجمالي') }}</td><td class="l"><span dir="ltr">{{ $doc['totals']['total'] }}</span></td></tr>
    <tr><td class="k">{{ __('حالة السداد') }}</td><td class="l b">{{ $payment['state'] }}</td></tr>
    <tr><td class="k">{{ __('المدفوع') }}</td><td class="l"><span dir="ltr">{{ $payment['paid'] }}</span></td></tr>
    @if ($payment['outstanding'])
        <tr><td class="k">{{ __('المتبقي') }}</td><td class="l"><span dir="ltr">{{ $payment['outstanding'] }}</span></td></tr>
    @endif
    @if ($payment['methods'] !== [])
        <tr><td class="k">{{ __('وسيلة الدفع') }}</td><td class="l">{{ implode('، ', $payment['methods']) }}</td></tr>
    @endif
</table>

{{-- ٦ · الهديّة — والمُهدي يُرى هنا ولو أخفاه عن المستلِم: من يجهّز يكتب البطاقة --}}
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
    @if ($gift['hidden'])
        {{-- كلماتٍ لا تنكسر: على ٥٨ مم كان mpdf يكسر «التسليم» داخلها ويُخرجها عن عرض الشريط --}}
        <div class="b tiny bidi">{!! $lines(__('الاسم مخفيّ عن المستلم — لا يُكتب على البطاقة ولا على سند التسليم')) !!}</div>
    @endif
    @if ($gift['message'])
        <div class="cap">{{ __('رسالة البطاقة') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($gift['message']) }}">{!! $lines($gift['message']) !!}</div>
    @endif
@endif

{{-- ٧ · ما يُقال لمن يجهّز — لا يخرج إلى العميل ولا إلى المستلِم --}}
@if ($doc['prep_notes'] || $doc['invoice_notes'] || $doc['notes'])
    <div class="rule"></div>
    @if ($doc['prep_notes'])
        <div class="cap">{{ __('تعليمات التجهيز') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($doc['prep_notes']) }}">{!! $lines($doc['prep_notes']) !!}</div>
    @endif
    @if ($doc['invoice_notes'])
        <div class="cap">{{ __('ملاحظات داخلية') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($doc['invoice_notes']) }}">{!! $lines($doc['invoice_notes']) !!}</div>
    @endif
    @if ($doc['notes'])
        <div class="cap">{{ __('ملاحظات الفاتورة') }}</div>
        <div class="bidi block" dir="{{ Paper::dirOf($doc['notes']) }}">{!! $lines($doc['notes']) !!}</div>
    @endif
@endif

<div class="rule"></div>
<div class="c muted tiny">{{ __('نسخة داخلية للتجهيز — ليست فاتورة ضريبية ولا إيصال دفع') }}</div>

</div>

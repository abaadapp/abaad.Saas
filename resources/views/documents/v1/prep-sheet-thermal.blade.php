{{--
    «ورقة تجهيز داخلية» — شريطٌ حراريٌّ ٥٨ أو ٨٠ مم، بطول محتواه.

    تُقرأ كما يقرؤها من يقف عند الطاولة: أيُّ طلبٍ ومتى، ثمّ **ما يُصنع**
    — كلُّ صنفٍ بمربّعه، وتحته إضافاتُه بمربّعاتها، وموادُّه وخياراتُه
    وملاحظتُه — ثمّ ما يُراجَع قبل «جاهز»، ثمّ الملاحظاتُ والهديّة.

    ولا مالَ هنا بحال: `$sheet` حمولةٌ بُنيت في الخادم (`PrepSheet`) بلا سعرٍ
    ولا إجماليٍّ ولا ضريبةٍ ولا دفع. فشرطٌ يُنسى هنا لا يبلغ رقمًا لم يُعطَه.

    والمربّعُ المؤشَّر يقول من أشّره ومتى — كما على الشاشة. ومهمّةُ فاتورة
    العميل بلا علاماتٍ محفوظة، فمربّعاتُها فارغةٌ تُؤشَّر بقلم.

    والنصُّ الحرّ — الملاحظاتُ ورسالةُ البطاقة — يُطبع كما كتبه صاحبُه بأسطره،
    باتّجاهه هو لا اتّجاه الورقة (`Paper::dirOf`). ولا `<thead>`: يتكرّر في كلّ
    قسمٍ من الشريط الطويل (`MpdfDriver::strip`).
--}}
@php
    use App\Support\Paper;

    $width = $width ?? 80;
    $narrow = $width <= 60;
    // نصٌّ يحفظ أسطرَ كاتبه — والخطُّ يلفّه بين كلماته (`keep_spaces`)
    $text = fn (?string $s) => nl2br(e(trim((string) $s)), false);
    $dir = fn (?string $s) => Paper::dirOf((string) $s);
    $qtyw = $narrow ? 15 : 12;
    /*
     * والمربّعُ صورةٌ مضمَّنة لا صندوقٌ من CSS: mpdf لا يرسم حدودَ `div` فارغٍ
     * في خليّة، وحرفُ «☐» ليس في خطّ الورقة. والمؤشَّرُ مملوءٌ أسود — والطابعةُ
     * الحراريّة تطبع الأسودَ ولا تطبع الرماديّ.
     */
    $boxSvg = fn (bool $on) => 'data:image/svg+xml;base64,'.base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 10 10">'
        .'<rect x="0.6" y="0.6" width="8.8" height="8.8" fill="'.($on ? '#000' : '#fff').'" stroke="#000" stroke-width="1.2"/></svg>'
    );
    $box = fn (?array $mark) => '<img src="'.$boxSvg((bool) $mark).'" width="'.($narrow ? 8 : 9).'" height="'.($narrow ? 8 : 9).'" alt="">';
    // ومن أشّر ومتى — كلٌّ معزولٌ باتّجاهه: الاسمُ كما كُتب، والتاريخُ من اليسار دائمًا
    $who = fn (?array $m) => $m ? __('أُشِّر').': <span dir="'.Paper::dirOf((string) ($m['by'] ?? '')).'">'.e((string) ($m['by'] ?? '')).'</span> · <span dir="ltr">'.e((string) ($m['at'] ?? '')).'</span>' : null;
    $paperDir = Paper::rtl() ? 'rtl' : 'ltr';
    $boxCol = $narrow ? 9 : 7;
    $recipient = array_filter($sheet['recipient']);
    $customer = array_filter($sheet['customer']);
@endphp
@include('documents.v1.partials.thermal-tokens')
<style>
    .prep td { padding: 2pt 0; vertical-align: top; border-bottom: 0.4pt dotted #999; }
    .prep td.sub { border-bottom: none; padding: 0.6pt 0; }
    .prep td.last { border-bottom: 0.4pt dotted #999; padding-bottom: 2pt; }
    .prep .item { font-size: {{ round(7.8 * ($narrow ? 0.86 : 1), 2) }}pt; font-weight: bold; }
    .prep .who { font-size: {{ round(6 * ($narrow ? 0.86 : 1), 2) }}pt; color: #444; }
    .internal { font-weight: bold; }
</style>

<div class="paper">

{{-- ١ · المتجر واسمُ الورقة --}}
<div class="c">
    @if ($logo)
        <img src="{{ $logo }}" style="max-height:26pt; margin-bottom:2pt;" alt="">
    @endif
    <div class="shop">{{ $shop['name'] ?? __('نظام Abad POS') }}</div>
    @if ($sheet['branch'])
        <div class="muted tiny">{{ $sheet['branch'] }}</div>
    @endif
</div>

<div class="rule"></div>
<div class="c title">{{ __('ورقة تجهيز داخلية') }}</div>

{{-- ٢ · أيُّ طلبٍ، ومتى، وكيف --}}
<table class="kv">
    <tr><td class="k">{{ $sheet['number_label'] }}</td><td class="l b">{{ $sheet['number'] }}</td></tr>
    @if ($sheet['source'])
        <tr><td class="k">{{ __('المصدر') }}</td><td class="v">{{ $sheet['source'] }}</td></tr>
    @endif
    @if ($sheet['scheduled_for'])
        <tr><td class="k">{{ __('الموعد') }}</td><td class="l b">{{ $sheet['scheduled_for'] }}</td></tr>
    @endif
    @if ($sheet['fulfilment'])
        <tr><td class="k">{{ __('نوع التسليم') }}</td><td class="v">{{ $sheet['fulfilment'] }}</td></tr>
    @endif
    <tr><td class="k">{{ __('تاريخ الطباعة') }}</td><td class="l">{{ $sheet['printed_at'] }}</td></tr>
</table>

{{-- ٣ · لمن ومن — ما يلزم للتجهيز وحده --}}
@if ($customer !== [] || $recipient !== [])
    <div class="rule"></div>
    <table class="kv">
        @if (! empty($customer['name']))
            <tr><td class="k">{{ __('العميل') }}</td><td class="v" dir="{{ $dir($customer['name']) }}">{{ $customer['name'] }}</td></tr>
        @endif
        @if (! empty($customer['phone']))
            <tr><td class="k">{{ __('هاتف العميل') }}</td><td class="l">{{ $customer['phone'] }}</td></tr>
        @endif
        @if (! empty($recipient['name']))
            <tr><td class="k">{{ __('المستلم') }}</td><td class="v" dir="{{ $dir($recipient['name']) }}">{{ $recipient['name'] }}</td></tr>
        @endif
        @if (! empty($recipient['phone']))
            <tr><td class="k">{{ __('هاتف المستلم') }}</td><td class="l">{{ $recipient['phone'] }}</td></tr>
        @endif
    </table>
    @if (! empty($recipient['address']))
        <div class="cap">{{ __('العنوان') }}</div>
        <div class="bidi block" dir="{{ $dir($recipient['address']) }}">{!! $text($recipient['address']) !!}</div>
    @endif
@endif

{{--
    ٤ · ما يُصنع — كلُّ صنفٍ بمربّعه، وإضافاتُه بمربّعاتها تحته.

    ثلاثةُ أعمدة: المربّعُ، والاسمُ وما تحته، والكميّةُ معزولةً عن اتّجاه
    السطر («× ٢» لا تنقلب). والإضافةُ «+» في أوّل اسمها باتّجاه اسمها هو.
--}}
<div class="rule"></div>
<div class="sec">{{ __('الأصناف') }}</div>
<table class="prep" style="width:100%; border-collapse:collapse">
    <tbody>
        @foreach ($sheet['items'] as $it)
            @php($tail = $it['addons'] === [] && $it['components'] === [])
            <tr data-key="{{ $it['key'] }}">
                <td class="{{ $tail ? '' : 'sub' }}" style="width:{{ $boxCol }}%">{!! $box($it['checked']) !!}</td>
                {{-- والخليّةُ باتّجاه الورقة، والاسمُ وحده باتّجاهه: mpdf يُلبس ما تحت الخليّة اتّجاهَها --}}
                <td class="{{ $tail ? '' : 'sub' }}">
                    <div class="bidi item" dir="{{ $dir($it['name']) }}">{{ $it['name'] }}</div>
                    {{-- وما تحت الاسم باتّجاه الورقة: التسميةُ بلغتها، والقيمةُ معزولةٌ باتّجاهها هي --}}
                    @if ($it['variant'])
                        <div class="tiny" dir="{{ $paperDir }}">{{ __('المقاس') }}: <span dir="{{ $dir($it['variant']) }}">{{ $it['variant'] }}</span></div>
                    @endif
                    @foreach ($it['options'] as $opt)
                        <div class="tiny @if ($opt['internal']) internal @endif" dir="{{ $paperDir }}">— @if ($opt['internal'])({{ __('داخلي') }}) @endif<span dir="{{ $dir($opt['label'].' '.$opt['value']) }}">{{ $opt['label'] }}: {{ $opt['value'] }}</span></div>
                    @endforeach
                    @if ($it['note'])
                        <div class="tiny" dir="{{ $paperDir }}">{{ $it['note_label'] }}: <span dir="{{ $dir($it['note']) }}">{!! $text($it['note']) !!}</span></div>
                    @endif
                    @if ($who($it['checked']))
                        <div class="who" dir="{{ $paperDir }}">{!! $who($it['checked']) !!}</div>
                    @endif
                </td>
                <td class="c b {{ $tail ? '' : 'sub' }}" style="width:{{ $qtyw }}%" dir="ltr">× {{ $it['qty'] }}</td>
            </tr>
            @foreach ($it['addons'] as $addon)
                @php($last = $loop->last && $it['components'] === [])
                <tr data-addon>
                    <td class="{{ $last ? 'last' : 'sub' }}" style="text-align:{{ Paper::rtl() ? 'left' : 'right' }}">{!! $box($addon['checked']) !!}</td>
                    <td class="tiny {{ $last ? 'last' : 'sub' }}"><div class="bidi" dir="{{ $dir($addon['name']) }}">+ {{ $addon['name'] }}</div>
                        @if ($who($addon['checked']))
                            <div class="who" dir="{{ $paperDir }}">{!! $who($addon['checked']) !!}</div>
                        @endif
                    </td>
                    <td class="c {{ $last ? 'last' : 'sub' }}" dir="ltr">× {{ $addon['qty'] }}</td>
                </tr>
            @endforeach
            @foreach ($it['components'] as $c)
                <tr data-component>
                    <td class="{{ $loop->last ? 'last' : 'sub' }}"></td>
                    <td class="tiny muted {{ $loop->last ? 'last' : 'sub' }}"><div class="bidi" dir="{{ $dir($c['name']) }}">· {{ $c['name'] }}</div></td>
                    <td class="c tiny {{ $loop->last ? 'last' : 'sub' }}" dir="ltr">× {{ $c['qty'] }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>

{{-- ٥ · ما يُراجَع قبل «جاهز» --}}
<div class="sec" style="margin-top:4pt">{{ __('قائمة التحقق') }}</div>
<table class="prep" style="width:100%; border-collapse:collapse">
    <tbody>
        @foreach ($sheet['tasks'] as $task)
            <tr data-key="{{ $task['key'] }}">
                <td style="width:{{ $boxCol }}%">{!! $box($task['checked']) !!}</td>
                <td>{{ $task['label'] }}
                    @if ($who($task['checked']))
                        <div class="who" dir="{{ $paperDir }}">{!! $who($task['checked']) !!}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@unless ($sheet['saved_checks'])
    <div class="muted tiny">{{ __('أشّر المربّعات بالقلم — لا علامات محفوظة لمهمّة الفاتورة.') }}</div>
@endunless

{{-- ٦ · الملاحظاتُ بأسمائها — كما كتبها أصحابُها --}}
@foreach ($sheet['notes'] as $note)
    @if ($loop->first)<div class="rule"></div>@endif
    <div class="cap">{{ $note['label'] }}</div>
    <div class="bidi block" dir="{{ $dir($note['text']) }}">{!! $text($note['text']) !!}</div>
@endforeach

{{-- ٧ · الهديّة — واسمُ المرسل ولو أُخفي، معلَّمًا بأنّه لا يُكتب للمستلِم --}}
@if ($sheet['gift'])
    @php($gift = $sheet['gift'])
    <div class="rule"></div>
    <div class="sec">{{ __('هدية') }}</div>
    <table class="kv">
        @if ($gift['occasion'])
            <tr><td class="k">{{ __('المناسبة') }}</td><td class="v">{{ $gift['occasion'] }}</td></tr>
        @endif
        @if ($gift['sender'])
            <tr><td class="k">{{ __('من') }}</td><td class="v" dir="{{ $dir($gift['sender']) }}">{{ $gift['sender'] }}</td></tr>
        @endif
    </table>
    @if ($gift['sender'] && $gift['hidden'])
        <div class="tiny b">{{ __('الاسم مخفيّ عن المستلم — لا يُكتب على البطاقة ولا على سند التسليم') }}</div>
    @endif
    @if ($gift['message'])
        <div class="cap">{{ __('رسالة البطاقة') }}</div>
        <div class="bidi block" dir="{{ $dir($gift['message']) }}">{!! $text($gift['message']) !!}</div>
    @endif
@endif

<div class="rule"></div>
<div class="c tiny b">{{ __('للاستخدام الداخلي فقط — ليست فاتورة ولا سند تسليم.') }}</div>

</div>

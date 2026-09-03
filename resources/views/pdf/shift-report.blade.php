{{--
    ورقةُ إقفال الوردية — تخرج من طابعة الصندوق لا من طابعة المكتب.

    فهي شريطٌ بأنماط الشريط: `pdf.partials.strip-style` يضبط قياسَها بعرض
    الورق كما يضبط الإيصال، فلا تخرج ورقةُ الوردية بخطٍّ يخصّها وحدها.

    وهي اليوم بلا مسارٍ يستدعيها — لا متحكّم ولا زرّ. تُركت مبنيّةً على
    النظام الواحد كي لا تُوصَل غدًا بشكلٍ من عهدٍ مضى.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $at = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d H:i') : '—';
    $width = $width ?? 80;
    $scale = $scale ?? 1.0;
@endphp
@include('pdf.partials.strip-style')
<style>
    /* ما يخصّ هذه الورقة وحدها: الفرقُ لونٌ، والتوقيعُ سطرٌ يُكتب عليه */
    .diff-ok { color: #047857; }
    .diff-bad { color: #b91c1c; font-weight: bold; }
    .signs { margin-top: 18pt; }
    .signs td { padding-top: 20pt; border-bottom: 0.6pt solid #000; }
</style>

<div class="c">
    <div class="shop">{{ $business->name ?? __('نظام Abad POS') }}</div>
    <div class="muted">{{ __('تقرير إقفال الوردية') }}</div>
    <div class="muted">{{ $branchName }}@if ($deviceName) · {{ $deviceName }}@endif</div>
</div>

<div class="rule"></div>

<table class="kv">
    <tr><td class="k">{{ __('رقم الوردية') }}</td><td class="l" dir="ltr">#{{ $shift->id }}</td></tr>
    <tr><td class="k">{{ __('فُتحت') }}</td><td class="l" dir="ltr">{{ $at($shift->opened_at) }}</td></tr>
    <tr><td class="k">{{ __('أُقفلت') }}</td><td class="l" dir="ltr">{{ $at($shift->closed_at) }}</td></tr>
    <tr><td class="k">{{ __('الكاشير') }}</td><td class="l">{{ $openedBy }}</td></tr>
    @if ($closedBy && $closedBy !== $openedBy)
        <tr><td class="k">{{ __('أقفلها') }}</td><td class="l">{{ $closedBy }}</td></tr>
    @endif
</table>

<div class="rule"></div>
<div><strong>{{ __('المبيعات') }}</strong></div>

<table class="kv">
    <tr><td class="k">{{ __('عدد الفواتير') }}</td><td class="l" dir="ltr">{{ $totals['count'] }}</td></tr>
    @foreach ($totals['byMethod'] as $method => $sum)
        <tr><td class="k">{{ __($method) }}</td><td class="l" dir="ltr">{{ $money($sum) }}</td></tr>
    @endforeach
    <tr><td class="k"><strong>{{ __('إجمالي المبيعات') }}</strong></td><td class="l" dir="ltr"><strong>{{ $money($totals['sales']) }}</strong></td></tr>
</table>

<div class="rule"></div>
<div><strong>{{ __('الدرج') }}</strong></div>

{{--
    ترتيب الأسطر هو المعادلة نفسها: افتتاحي + نقدي + إيداع − سحب = المتوقّع.
    من يوقّع الورقة يجب أن يقرأ من أين جاء الرقم، لا أن يُسلَّم رقمًا يصدّقه.
--}}
<table class="kv">
    <tr><td class="k">{{ __('الرصيد الافتتاحي') }}</td><td class="l" dir="ltr">{{ $money($shift->opening_balance) }}</td></tr>
    <tr><td class="k">{{ __('مبيعات نقدية') }}</td><td class="l" dir="ltr">+ {{ $money($totals['cash']) }}</td></tr>
    <tr><td class="k">{{ __('إيداع في الدرج') }}</td><td class="l" dir="ltr">+ {{ $money($moves['in']) }}</td></tr>
    <tr><td class="k">{{ __('سحب من الدرج') }}</td><td class="l" dir="ltr">− {{ $money($moves['out']) }}</td></tr>
    <tr><td class="k b">{{ __('النقد المتوقّع') }}</td><td class="l b" dir="ltr">{{ $money($shift->expected_balance) }}</td></tr>
    {{--
        الوردية التي أُقفلت بلا عدّ لا تُطبع لها خانتان بصفرين.
        ورقةٌ تقول «الفرق 0.000» تُقرأ «طابق الدرج»، وتُوقَّع على ذلك — وهي
        عن درجٍ لم يفتحه أحد. فيُقال ما جرى بلفظه: لم يُعدّ.
    --}}
    @if ($shift->actual_balance === null)
        <tr>
            <td class="k"><strong>{{ __('النقد المعدود') }}</strong></td>
            <td class="l diff-bad" dir="rtl">{{ __('لم يُعدّ') }}</td>
        </tr>
        <tr>
            <td class="k"><strong>{{ __('الفرق') }}</strong></td>
            <td class="l diff-bad" dir="rtl">{{ __('مجهول') }}</td>
        </tr>
    @else
        <tr><td class="k">{{ __('النقد المعدود') }}</td><td class="l" dir="ltr">{{ $money($shift->actual_balance) }}</td></tr>
        <tr>
            <td class="k"><strong>{{ __('الفرق') }}</strong></td>
            <td class="l {{ abs((float) $shift->difference) < 0.001 ? 'diff-ok' : 'diff-bad' }}" dir="ltr">
                {{ (float) $shift->difference > 0 ? '+' : '' }}{{ $money($shift->difference) }}
            </td>
        </tr>
    @endif
</table>

@if (count($movements))
    <div class="rule"></div>
    <div><strong>{{ __('حركات الدرج') }}</strong></div>
    <table class="kv">
        @foreach ($movements as $m)
            <tr>
                <td class="k">
                    {{ $m['type'] === 'out' ? __('سحب') : __('إيداع') }} — {{ $m['reason'] }}
                </td>
                <td class="l" dir="ltr">{{ $money($m['amount']) }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($shift->note)
    <div class="rule"></div>
    <div class="muted">{{ __('ملاحظة') }}: {{ $shift->note }}</div>
@endif

{{--
    التوقيعان هما سبب الورقة.
    الإقفال في الشاشة رقمٌ في القاعدة يُعدَّل ولا يُسأل عنه أحد؛ وورقةٌ يوقّعها
    من سلّم ومن استلم تجعل الفرق مسؤوليةً لها اسم — وهذا ما يجعل الوردية أداة
    محاسبة لا سجلَّ وقت.
--}}
<table class="signs">
    <tr>
        <td style="width:46%">{{ __('توقيع الكاشير') }}</td>
        <td style="width:8%; border:0"></td>
        <td style="width:46%">{{ __('توقيع المستلم') }}</td>
    </tr>
</table>

<div class="c muted" style="margin-top:10pt">
    {{ __('طُبع في') }} {{ now()->format('Y-m-d H:i') }} — Abad POS
</div>

@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $at = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d H:i') : '—';
@endphp
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; font-size: 11px; color: #111; }
    .center { text-align: center; }
    .muted { color: #666; }
    h1 { font-size: 15px; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .rows td { padding: 3px 0; border-bottom: 1px dashed #ccc; }
    .rows .label { color: #444; }
    .rows .num { text-align: left; }
    .dash { border-top: 1px dashed #999; margin: 7px 0; }
    .grand { font-size: 13px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; }
    .diff-ok { color: #047857; }
    .diff-bad { color: #b91c1c; font-weight: bold; }
    .sign { margin-top: 22px; }
    .sign td { padding-top: 26px; border-bottom: 1px solid #000; }
</style>

<div class="center">
    <h1>{{ $business->name ?? __('نظام Abad POS') }}</h1>
    <div class="muted">{{ __('تقرير إقفال الوردية') }}</div>
    <div class="muted">{{ $branchName }}@if ($deviceName) · {{ $deviceName }}@endif</div>
</div>

<div class="dash"></div>

<table class="rows">
    <tr><td class="label">{{ __('رقم الوردية') }}</td><td class="num" dir="ltr">#{{ $shift->id }}</td></tr>
    <tr><td class="label">{{ __('فُتحت') }}</td><td class="num" dir="ltr">{{ $at($shift->opened_at) }}</td></tr>
    <tr><td class="label">{{ __('أُقفلت') }}</td><td class="num" dir="ltr">{{ $at($shift->closed_at) }}</td></tr>
    <tr><td class="label">{{ __('الكاشير') }}</td><td class="num">{{ $openedBy }}</td></tr>
    @if ($closedBy && $closedBy !== $openedBy)
        <tr><td class="label">{{ __('أقفلها') }}</td><td class="num">{{ $closedBy }}</td></tr>
    @endif
</table>

<div class="dash"></div>
<div><strong>{{ __('المبيعات') }}</strong></div>

<table class="rows">
    <tr><td class="label">{{ __('عدد الفواتير') }}</td><td class="num" dir="ltr">{{ $totals['count'] }}</td></tr>
    @foreach ($totals['byMethod'] as $method => $sum)
        <tr><td class="label">{{ __($method) }}</td><td class="num" dir="ltr">{{ $money($sum) }}</td></tr>
    @endforeach
    <tr><td class="label"><strong>{{ __('إجمالي المبيعات') }}</strong></td><td class="num" dir="ltr"><strong>{{ $money($totals['sales']) }}</strong></td></tr>
</table>

<div class="dash"></div>
<div><strong>{{ __('الدرج') }}</strong></div>

{{--
    ترتيب الأسطر هو المعادلة نفسها: افتتاحي + نقدي + إيداع − سحب = المتوقّع.
    من يوقّع الورقة يجب أن يقرأ من أين جاء الرقم، لا أن يُسلَّم رقمًا يصدّقه.
--}}
<table class="rows">
    <tr><td class="label">{{ __('الرصيد الافتتاحي') }}</td><td class="num" dir="ltr">{{ $money($shift->opening_balance) }}</td></tr>
    <tr><td class="label">{{ __('مبيعات نقدية') }}</td><td class="num" dir="ltr">+ {{ $money($totals['cash']) }}</td></tr>
    <tr><td class="label">{{ __('إيداع في الدرج') }}</td><td class="num" dir="ltr">+ {{ $money($moves['in']) }}</td></tr>
    <tr><td class="label">{{ __('سحب من الدرج') }}</td><td class="num" dir="ltr">− {{ $money($moves['out']) }}</td></tr>
    <tr><td class="label grand">{{ __('النقد المتوقّع') }}</td><td class="num grand" dir="ltr">{{ $money($shift->expected_balance) }}</td></tr>
    <tr><td class="label">{{ __('النقد المعدود') }}</td><td class="num" dir="ltr">{{ $money($shift->actual_balance) }}</td></tr>
    <tr>
        <td class="label"><strong>{{ __('الفرق') }}</strong></td>
        <td class="num {{ abs((float) $shift->difference) < 0.001 ? 'diff-ok' : 'diff-bad' }}" dir="ltr">
            {{ (float) $shift->difference > 0 ? '+' : '' }}{{ $money($shift->difference) }}
        </td>
    </tr>
</table>

@if (count($movements))
    <div class="dash"></div>
    <div><strong>{{ __('حركات الدرج') }}</strong></div>
    <table class="rows">
        @foreach ($movements as $m)
            <tr>
                <td class="label">
                    {{ $m['type'] === 'out' ? __('سحب') : __('إيداع') }} — {{ $m['reason'] }}
                </td>
                <td class="num" dir="ltr">{{ $money($m['amount']) }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($shift->note)
    <div class="dash"></div>
    <div class="muted">{{ __('ملاحظة') }}: {{ $shift->note }}</div>
@endif

{{--
    التوقيعان هما سبب الورقة.
    الإقفال في الشاشة رقمٌ في القاعدة يُعدَّل ولا يُسأل عنه أحد؛ وورقةٌ يوقّعها
    من سلّم ومن استلم تجعل الفرق مسؤوليةً لها اسم — وهذا ما يجعل الوردية أداة
    محاسبة لا سجلَّ وقت.
--}}
<table class="sign">
    <tr>
        <td style="width:46%">{{ __('توقيع الكاشير') }}</td>
        <td style="width:8%; border:0"></td>
        <td style="width:46%">{{ __('توقيع المستلم') }}</td>
    </tr>
</table>

<div class="center muted" style="margin-top:14px; font-size:9px;">
    {{ __('طُبع في') }} {{ now()->format('Y-m-d H:i') }} — Abad POS
</div>

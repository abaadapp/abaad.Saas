@php
    /*
     * دالةٌ مجهولة لا معرّفة باسمٍ عام: `function bar()` في قالبٍ تُعرَّف على
     * مستوى PHP كلّه، فرسمُ القالب مرّتين في عمليةٍ واحدة يُسقطها بـ«Cannot
     * redeclare». ولا يظهر على php-fpm — كل طلبٍ عملية — ويظهر في عامل
     * الطابور وفي أمرٍ يولّد تقريرين وفي مجموعة الاختبارات.
     */
    $bar = fn ($pct) => max(1, min(100, (int) round($pct)));
    $maxSales = max(1, max($salesSeries['data'] ?: [1]));
@endphp
<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #7c3aed; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #7c3aed; }
    .muted { color: #6b7280; font-size: 10px; }
    h2 { font-size: 13px; color: #4c1d95; margin: 18px 0 8px; border-right: 4px solid #7c3aed; padding-right: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f5f3ff; color: #4c1d95; text-align: right; padding: 7px; font-size: 10px; border-bottom: 1px solid #ede9fe; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .cards td { width: 25%; padding: 4px; border: none; }
    .card { background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; padding: 10px; }
    .card .lbl { color: #6b7280; font-size: 9px; }
    .card .val { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }
    .barwrap { background: #f3f4f6; border-radius: 6px; height: 14px; width: 100%; }
    .bar { background: #7c3aed; height: 14px; border-radius: 6px; }
    .barsec { background: #db2777; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('تقرير المبيعات الشهري') }}</div>
            <div class="muted">{{ $branch }}</div>
            <div class="muted">{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<h2>{{ __('الملخّص العام') }}</h2>
<table class="cards">
    @foreach (array_chunk($stats, 4) as $chunk)
        <tr>
            @foreach ($chunk as $s)
                <td><div class="card"><div class="lbl">{{ __($s['label']) }}</div><div class="val">{{ $s['value'] }}</div></div></td>
            @endforeach
        </tr>
    @endforeach
</table>

<h2>{{ __('حركة المبيعات (آخر 6 أشهر)') }}</h2>
<table>
    @foreach ($salesSeries['labels'] as $i => $label)
        @php $val = $salesSeries['data'][$i] ?? 0; @endphp
        <tr>
            <td style="width:16%;">{{ __($label) }}</td>
            <td style="width:60%;"><div class="barwrap"><div class="bar" style="width: {{ $bar($val / $maxSales * 100) }}%;"></div></div></td>
            <td style="width:24%; text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($val) }}</td>
        </tr>
    @endforeach
</table>

<h2>{{ __('توزيع وسائل الدفع') }}</h2>
<table>
    <tr><th>{{ __('الوسيلة') }}</th><th>{{ __('عدد العمليات') }}</th><th>{{ __('الإجمالي') }}</th><th style="width:30%;">{{ __('النسبة') }}</th></tr>
    @foreach ($payments as $p)
        <tr>
            <td>{{ __($p['name']) }}</td>
            <td>{{ $p['count'] }}</td>
            <td>{{ \App\Support\Demo::moneyBase($p['total']) }}</td>
            <td><div class="barwrap"><div class="bar barsec" style="width: {{ $bar($p['percent']) }}%;"></div></div>
                <span class="muted">{{ $p['percent'] }}%</span></td>
        </tr>
    @endforeach
</table>

<h2>{{ __('أفضل المنتجات مبيعًا') }}</h2>
<table>
    <tr><th>{{ __('المنتج') }}</th><th>{{ __('الكمية المباعة') }}</th><th>{{ __('الإيراد') }}</th></tr>
    @forelse ($topProducts as $p)
        <tr><td>{{ $p['name'] }}</td><td>{{ __(':n وحدة', ['n' => $p['qty']]) }}</td><td>{{ \App\Support\Demo::moneyBase($p['total']) }}</td></tr>
    @empty
        <tr><td colspan="3" style="text-align:center; color:#9ca3af;">{{ __('لا توجد بيانات مبيعات بعد') }}</td></tr>
    @endforelse
</table>

<div class="foot">{{ __('تم إنشاء هذا التقرير آليًا عبر نظام Abad POS') }} — {{ $generatedAt }}</div>

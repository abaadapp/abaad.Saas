@php
    /*
     * دالةٌ مجهولة لا معرّفة باسمٍ عام.
     *
     * `function pbar()` في قالبٍ تُعرَّف على مستوى PHP كلّه، فتُعرَّف مرّتين إن
     * رُسم القالب مرّتين في العملية نفسها — «Cannot redeclare» تُسقط العملية
     * لا الصفحة. ولا يقع على php-fpm لأن كل طلبٍ عملية، لكنه يقع في عاملٍ
     * دائم للطابور، وفي أي أمرٍ يولّد تقريرين، وفي مجموعة الاختبارات.
     */
    $pbar = fn ($pct) => max(1, min(100, (int) round($pct)));
    $maxRev = max(1, max($revenueSeries['data'] ?: [1]));
    $maxGrow = max(1, max($growthSeries['data'] ?: [1]));
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
            <div class="brand">Abad POS</div>
            <div class="muted">{{ __('منصة نقاط البيع متعددة المتاجر') }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('تقرير أداء المنصة') }}</div>
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

<h2>{{ __('الإيرادات (آخر 6 أشهر)') }}</h2>
<table>
    @foreach ($revenueSeries['labels'] as $i => $label)
        @php $val = $revenueSeries['data'][$i] ?? 0; @endphp
        <tr>
            <td style="width:16%;">{{ __($label) }}</td>
            <td style="width:60%;"><div class="barwrap"><div class="bar" style="width: {{ $pbar($val / $maxRev * 100) }}%;"></div></div></td>
            <td style="width:24%; text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($val) }}</td>
        </tr>
    @endforeach
</table>

<h2>{{ __('نمو الشركات (تسجيلات جديدة)') }}</h2>
<table>
    @foreach ($growthSeries['labels'] as $i => $label)
        @php $val = $growthSeries['data'][$i] ?? 0; @endphp
        <tr>
            <td style="width:16%;">{{ __($label) }}</td>
            <td style="width:60%;"><div class="barwrap"><div class="bar barsec" style="width: {{ $pbar($val / $maxGrow * 100) }}%;"></div></div></td>
            <td style="width:24%; text-align:left; font-weight:bold;">{{ __(':n شركة', ['n' => $val]) }}</td>
        </tr>
    @endforeach
</table>

<h2>{{ __('توزيع الشركات على الباقات') }}</h2>
<table>
    <tr><th>{{ __('الباقة') }}</th><th>{{ __('عدد الشركات') }}</th></tr>
    @foreach ($planDistribution['labels'] as $i => $label)
        <tr><td>{{ $label }}</td><td>{{ $planDistribution['series'][$i] ?? 0 }}</td></tr>
    @endforeach
</table>

<h2>{{ __('أعلى الشركات مبيعًا') }}</h2>
<table>
    <tr><th>{{ __('الشركة') }}</th><th>{{ __('المدينة') }}</th><th>{{ __('الباقة') }}</th><th>{{ __('المبيعات') }}</th></tr>
    @foreach ($topBusinesses as $b)
        <tr><td>{{ $b['name'] }}</td><td>{{ $b['city'] }}</td><td>{{ $b['plan'] }}</td><td>{{ \App\Support\Demo::moneyBase($b['sales']) }}</td></tr>
    @endforeach
</table>

<div class="foot">{{ __('تم إنشاء هذا التقرير آليًا عبر نظام Abad POS') }} — {{ $generatedAt }}</div>

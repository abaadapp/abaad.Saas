@extends('pdf.layout')

@php
    /* ورقةُ منصّة لا ورقةُ متجر: الترويسة تحمل اسمَها هي — انظر pdf.layout */
    $business = ['name' => 'Abad POS'];
@endphp

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

@section('title', __('تقرير أداء المنصة'))

@section('meta')
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('الملخّص العام') }}</h2>
    <table class="cards">
        @foreach (array_chunk($stats, 4) as $chunk)
            <tr>
                @foreach ($chunk as $s)
                    <td><div class="lbl">{{ __($s['label']) }}</div><div class="val">{{ $s['value'] }}</div></td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>{{ __('الإيرادات (آخر 6 أشهر)') }}</h2>
    <table class="grid">
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
    <table class="grid">
        @foreach ($growthSeries['labels'] as $i => $label)
            @php $val = $growthSeries['data'][$i] ?? 0; @endphp
            <tr>
                <td style="width:16%;">{{ __($label) }}</td>
                <td style="width:60%;"><div class="barwrap"><div class="bar bar-2" style="width: {{ $pbar($val / $maxGrow * 100) }}%;"></div></div></td>
                <td style="width:24%; text-align:left; font-weight:bold;">{{ __(':n شركة', ['n' => $val]) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('توزيع الشركات على الباقات') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الباقة') }}</th><th>{{ __('عدد الشركات') }}</th></tr>
        @foreach ($planDistribution['labels'] as $i => $label)
            <tr><td>{{ $label }}</td><td>{{ $planDistribution['series'][$i] ?? 0 }}</td></tr>
        @endforeach
    </table>

    <h2>{{ __('أعلى الشركات مبيعًا') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الشركة') }}</th><th>{{ __('المدينة') }}</th><th>{{ __('الباقة') }}</th><th>{{ __('المبيعات') }}</th></tr>
        @foreach ($topBusinesses as $b)
            <tr><td>{{ $b['name'] }}</td><td>{{ $b['city'] }}</td><td>{{ $b['plan'] }}</td><td>{{ \App\Support\Demo::moneyBase($b['sales']) }}</td></tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا عبر نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

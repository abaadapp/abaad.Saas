@extends('pdf.layout')

@php
    /*
     * دالةٌ مجهولة لا معرّفة باسمٍ عام: `function bar()` في قالبٍ تُعرَّف على
     * مستوى PHP كلّه، فرسمُ القالب مرّتين في عمليةٍ واحدة يُسقطها بـ«Cannot
     * redeclare». ولا يظهر على php-fpm — كل طلبٍ عملية — ويظهر في عامل
     * الطابور وفي أمرٍ يولّد تقريرين وفي مجموعة الاختبارات.
     */
    $bar = fn ($pct) => max(1, min(100, (int) round($pct)));
    $maxSales = max(1, max(array_filter($salesSeries['data'], fn ($v) => $v !== null) ?: [1]));
@endphp

@section('title', __('تقرير المبيعات'))

@section('meta')
    <div>{{ $branch }}</div>
    {{-- الفترة في الترويسة: ورقةٌ تُطبع وتُرسل، ولا مبدّل فوقها يقول عمّاذا تتحدّث --}}
    <div>{{ __('الفترة:') }} {{ $rangeLabel ?? '—' }}</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('الملخّص العام') }}</h2>
    <table class="cards">
        @foreach (array_chunk($stats, 4) as $chunk)
            <tr>
                @foreach ($chunk as $s)
                    {{-- الرقم يُنسَّق هنا: الملخّص يعود خامًا ليُجمع في خليّة Excel --}}
                    <td><div class="lbl">{{ $s['label'] }}</div><div class="val">{{ $s['money'] ? \App\Support\Demo::moneyBase($s['value']) : $s['value'] }}</div></td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>{{ __('حركة المبيعات') }} — {{ $rangeLabel ?? '' }}</h2>
    <table class="grid">
        @foreach (($salesSeries['full'] ?? $salesSeries['labels']) as $i => $label)
            {{-- ما لم يأتِ بعدُ لا يُطبع: سطرٌ بصفرٍ عن يوم غدٍ رقمٌ لا واقعة --}}
            @continue(($salesSeries['data'][$i] ?? null) === null)
            @php $val = $salesSeries['data'][$i] ?? 0; @endphp
            <tr>
                <td style="width:20%;">{{ __($label) }}</td>
                <td style="width:48%;"><div class="barwrap"><div class="bar" style="width: {{ $bar($val / $maxSales * 100) }}%;"></div></div></td>
                {{-- عدد الطلبات إلى جانب المبلغ: مئةٌ من طلبٍ واحد غير مئةٍ من أربعين --}}
                <td style="width:12%; text-align:center;" class="muted">{{ $salesSeries['counts'][$i] ?? 0 }}</td>
                <td style="width:20%; text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($val) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('توزيع وسائل الدفع') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الوسيلة') }}</th><th>{{ __('عدد العمليات') }}</th><th>{{ __('الإجمالي') }}</th><th style="width:30%;">{{ __('النسبة') }}</th></tr>
        @foreach ($payments as $p)
            <tr>
                <td>{{ __($p['name']) }}</td>
                <td>{{ $p['count'] }}</td>
                <td>{{ \App\Support\Demo::moneyBase($p['total']) }}</td>
                <td><div class="barwrap"><div class="bar bar-2" style="width: {{ $bar($p['percent']) }}%;"></div></div>
                    <span class="muted">{{ $p['percent'] }}%</span></td>
            </tr>
        @endforeach
    </table>

    {{-- الجدول نفسه الذي على الشاشة: مرتَّبٌ بالإيراد، وبقسم كل منتج ونسبته --}}
    <h2>{{ __('الأكثر مبيعًا') }}</h2>
    <table class="grid">
        <tr><th>{{ __('المنتج') }}</th><th>{{ __('القسم') }}</th><th>{{ __('المُباع') }}</th><th>{{ __('الإيراد') }}</th><th>{{ __('النسبة') }}</th></tr>
        @forelse ($topProducts as $p)
            <tr><td>{{ $p['name'] }}</td><td>{{ $p['cat'] }}</td><td>{{ __(':n وحدة', ['n' => $p['sold']]) }}</td><td>{{ \App\Support\Demo::moneyBase($p['revenue']) }}</td><td>{{ $p['pct'] }}</td></tr>
        @empty
            <tr><td colspan="5" class="empty">{{ __('لا توجد بيانات مبيعات بعد') }}</td></tr>
        @endforelse
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا عبر نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

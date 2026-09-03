@extends('pdf.layout')

@section('title', __('تقرير التحليلات المتقدمة'))

@section('meta')
    <div>{{ __('الفترة:') }} {{ $rangeLabel ?? '—' }}</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('مقارنة الأداء بالفترة السابقة') }}</h2>
    <table class="cards"><tr>
        @foreach ($comparison as $m)
            <td>
                <div class="lbl">{{ __($m['label']) }}</div>
                <div class="val">{{ $m['cur'] }}</div>
                <div class="muted">{{ __('السابق:') }} {{ $m['prev'] }} ({{ $m['delta'] >= 0 ? '+' : '' }}{{ $m['delta'] }}%)</div>
            </td>
        @endforeach
    </tr></table>

    <h2>{{ __('أفضل المنتجات مبيعًا') }}</h2>
    <table class="grid">
        <tr><th>{{ __('المنتج') }}</th><th>{{ __('الكمية المباعة') }}</th><th>{{ __('الإيراد') }}</th></tr>
        @forelse ($topProducts as $p)
            <tr><td>{{ $p['name'] }}</td><td>{{ __(':n وحدة', ['n' => $p['qty']]) }}</td><td>{{ \App\Support\Demo::moneyBase($p['total']) }}</td></tr>
        @empty
            <tr><td colspan="3" class="empty">{{ __('لا توجد بيانات.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('أفضل العملاء إنفاقًا') }}</h2>
    <table class="grid">
        <tr><th>{{ __('العميل') }}</th><th>{{ __('عدد الطلبات') }}</th><th>{{ __('إجمالي الإنفاق') }}</th></tr>
        @forelse ($topCustomers as $c)
            <tr><td>{{ $c['name'] }}</td><td>{{ $c['orders'] }}</td><td>{{ \App\Support\Demo::moneyBase($c['total']) }}</td></tr>
        @empty
            <tr><td colspan="3" class="empty">{{ __('لا توجد بيانات.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('المبيعات حسب القسم') }}</h2>
    <table class="grid">
        <tr><th>{{ __('القسم') }}</th><th>{{ __('المبيعات') }}</th></tr>
        @foreach ($categorySales['labels'] as $i => $label)
            <tr><td>{{ $label }}</td><td>{{ \App\Support\Demo::moneyBase($categorySales['series'][$i] ?? 0) }}</td></tr>
        @endforeach
    </table>

    <h2>{{ __('المبيعات حسب أيام الأسبوع') }}</h2>
    <table class="grid">
        <tr><th>{{ __('اليوم') }}</th><th>{{ __('المبيعات') }}</th></tr>
        @foreach ($byWeekday['labels'] as $i => $label)
            <tr><td>{{ __($label) }}</td><td>{{ \App\Support\Demo::moneyBase($byWeekday['data'][$i] ?? 0) }}</td></tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تقرير آلي عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بالريال العماني') }}</div>
@endsection

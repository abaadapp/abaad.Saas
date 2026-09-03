@extends('pdf.layout')

@section('title', __('التقرير المالي'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('الفترة') }}: {{ $rangeLabel }}</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('المؤشرات المالية') }}</h2>
    <table class="cards">
        <tr>
            @foreach ($stats as $i => $s)
                <td>
                    
                        <div class="lbl">{{ __($s['label']) }}</div>
                        <div class="val">{{ $s['value'] }}</div>
                    </td>
                @if (($i + 1) % 4 === 0 && ! $loop->last)</tr><tr>@endif
            @endforeach
        </tr>
    </table>

    <h2>{{ __('وسائل الدفع') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الوسيلة') }}</th><th>{{ __('الإجمالي') }}</th><th>{{ __('عدد العمليات') }}</th></tr>
        @foreach ($payments as $m)
            <tr>
                <td>{{ __($m['name']) }}</td>
                <td>{{ number_format($m['total'], 3) }} {{ __('ر.ع') }}</td>
                <td>{{ $m['count'] }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('المعاملات المالية') }} ({{ count($transactions) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('المرجع') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th>
            <th>{{ __('الوسيلة') }}</th><th>{{ __('النوع') }}</th><th>{{ __('المبلغ') }}</th>
        </tr>
        @foreach ($transactions as $t)
            <tr>
                <td>{{ $t['id'] }}</td>
                <td>{{ $t['date'] }}</td>
                <td>{{ $t['description'] }}</td>
                <td>{{ __($t['method']) }}</td>
                <td class="{{ $t['type'] === 'دخل' ? 'income' : 'expense' }}">{{ __($t['type']) }}</td>
                <td class="{{ $t['type'] === 'دخل' ? 'income' : 'expense' }}">
                    {{ $t['type'] === 'دخل' ? '+' : '−' }}{{ number_format(abs($t['amount']), 3) }} {{ __('ر.ع') }}
                </td>
            </tr>
        @endforeach
    </table>

    @php
        $totalIn = collect($transactions)->where('type', 'دخل')->sum(fn ($t) => abs($t['amount']));
        $totalOut = collect($transactions)->where('type', '!=', 'دخل')->sum(fn ($t) => abs($t['amount']));
    @endphp
    <table style="margin-top:10px;">
        <tr>
            <th>{{ __('إجمالي الدخل') }}</th>
            <th>{{ __('إجمالي المصروفات') }}</th>
            <th>{{ __('الصافي') }}</th>
        </tr>
        <tr>
            <td class="income">{{ number_format($totalIn, 3) }} {{ __('ر.ع') }}</td>
            <td class="expense">{{ number_format($totalOut, 3) }} {{ __('ر.ع') }}</td>
            <td style="font-weight:bold;">{{ number_format($totalIn - $totalOut, 3) }} {{ __('ر.ع') }}</td>
        </tr>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

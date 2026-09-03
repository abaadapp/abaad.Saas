@extends('pdf.layout')

@section('title', __('تقرير المصروفات'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    <table class="cards">
        <tr>
            <td>
                
                    <div class="lbl">{{ __('عدد المصروفات') }}</div>
                    <div class="val">{{ count($expenses) }}</div>
                </td>
            <td>
                
                    <div class="lbl">{{ __('إجمالي المصروفات') }}</div>
                    <div class="val">{{ number_format($total, 3) }} {{ __('ر.ع') }}</div>
                </td>
        </tr>
    </table>

    <h2>{{ __('المصروفات') }} ({{ count($expenses) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('التاريخ') }}</th>
            <th>{{ __('النوع') }}</th>
            <th>{{ __('الوصف') }}</th>
            <th>{{ __('المبلغ') }}</th>
            <th>{{ __('الطريقة') }}</th>
            <th>{{ __('الموظف') }}</th>
        </tr>
        @foreach ($expenses as $e)
            <tr>
                <td>{{ $e['date'] }}</td>
                <td>{{ __($e['type']) }}</td>
                <td>{{ $e['description'] }}</td>
                <td>{{ number_format((float) $e['amount'], 3) }} {{ __('ر.ع') }}</td>
                <td>{{ __($e['method']) }}</td>
                <td>{{ $e['employee'] }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

@extends('pdf.layout')

@section('title', __('تقرير الطلبات'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    <table class="cards">
        <tr>
            <td>
                
                    <div class="lbl">{{ __('عدد الطلبات') }}</div>
                    <div class="val">{{ count($orders) }}</div>
                </td>
            <td>
                
                    <div class="lbl">{{ __('إجمالي القيمة') }}</div>
                    <div class="val">{{ number_format($total, 3) }} {{ __('ر.ع') }}</div>
                </td>
        </tr>
    </table>

    <h2>{{ __('الطلبات') }} ({{ count($orders) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('رقم الطلب') }}</th>
            <th>{{ __('العميل') }}</th>
            <th>{{ __('الموظف') }}</th>
            <th>{{ __('الفرع') }}</th>
            <th>{{ __('الأصناف') }}</th>
            <th>{{ __('الإجمالي') }}</th>
            <th>{{ __('الدفع') }}</th>
            <th>{{ __('الحالة') }}</th>
            <th>{{ __('التاريخ') }}</th>
        </tr>
        @foreach ($orders as $o)
            <tr>
                <td>{{ $o['id'] }}</td>
                <td>{{ $o['customer'] }}</td>
                <td>{{ $o['employee'] }}</td>
                <td>{{ $o['branch'] }}</td>
                <td>{{ $o['items_count'] }}</td>
                <td>{{ number_format((float) $o['total'], 3) }} {{ __('ر.ع') }}</td>
                <td>{{ __($o['payment']) }}</td>
                <td>{{ __($o['status']) }}</td>
                <td>{{ $o['date'] }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

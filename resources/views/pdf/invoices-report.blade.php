@extends('pdf.layout')

@php
    /* ورقةُ منصّة لا ورقةُ متجر: الترويسة تحمل اسمَها هي — انظر pdf.layout */
    $business = ['name' => 'Abad POS'];
@endphp

@section('title', __('فواتير الاشتراكات'))

@section('meta')
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    <table class="cards">
        <tr>
            <td>
            
                    <div class="lbl">{{ __('عدد الفواتير') }}</div>
                    <div class="val">{{ count($invoices) }}</div>
                </td>
            <td>
            
                    <div class="lbl">{{ __('إجمالي القيمة') }}</div>
                    <div class="val">{{ number_format($total, 3) }} {{ __('ر.ع') }}</div>
                </td>
        </tr>
    </table>

    <h2>{{ __('فواتير الاشتراكات') }} ({{ count($invoices) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('رقم الفاتورة') }}</th>
            <th>{{ __('الشركة') }}</th>
            <th>{{ __('الباقة') }}</th>
            <th>{{ __('المبلغ') }}</th>
            <th>{{ __('التاريخ') }}</th>
            <th>{{ __('الحالة') }}</th>
        </tr>
        @foreach ($invoices as $i)
            <tr>
                <td>{{ $i['number'] }}</td>
                <td>{{ $i['business'] }}</td>
                <td>{{ __($i['plan']) }}</td>
                <td>{{ number_format((float) $i['amount'], 3) }} {{ __('ر.ع') }}</td>
                <td>{{ $i['date'] }}</td>
                <td>{{ __($i['status']) }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

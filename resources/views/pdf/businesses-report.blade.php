@extends('pdf.layout')

@php
    /* ورقةُ منصّة لا ورقةُ متجر: الترويسة تحمل اسمَها هي — انظر pdf.layout */
    $business = ['name' => 'Abad POS'];
@endphp

@section('title', __('تقرير الشركات'))

@section('meta')
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('الشركات') }} ({{ count($businesses) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('الشركة') }}</th>
            <th>{{ __('النوع') }}</th>
            <th>{{ __('المالك') }}</th>
            <th>{{ __('المدينة') }}</th>
            <th>{{ __('الباقة') }}</th>
            <th>{{ __('الحالة') }}</th>
            <th>{{ __('الفروع') }}</th>
            <th>{{ __('التسجيل') }}</th>
        </tr>
        @foreach ($businesses as $b)
            <tr>
                <td>{{ $b['name'] }}</td>
                <td>{{ __($b['type']) }}</td>
                <td>{{ $b['owner'] }}</td>
                <td>{{ $b['city'] }}</td>
                <td>{{ __($b['plan']) }}</td>
                <td>{{ __($b['status']) }}</td>
                <td>{{ $b['branches'] }}</td>
                <td>{{ $b['registered'] }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

@extends('pdf.layout')

@section('title', __('تقرير المنتجات'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    <table class="cards">
        <tr>
            <td>
                
                    <div class="lbl">{{ __('عدد المنتجات') }}</div>
                    <div class="val">{{ count($products) }}</div>
                </td>
            <td>
                
                    <div class="lbl">{{ __('قيمة المخزون') }}</div>
                    <div class="val">{{ number_format(array_sum(array_map(fn ($p) => (float) $p['cost'] * (int) $p['qty'], $products)), 3) }} {{ __('ر.ع') }}</div>
                </td>
        </tr>
    </table>

    <h2>{{ __('المنتجات') }} ({{ count($products) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('الاسم') }}</th>
            <th>{{ __('القسم') }}</th>
            <th>SKU</th>
            <th>{{ __('السعر') }}</th>
            <th>{{ __('الكمية') }}</th>
            <th>{{ __('حالة المخزون') }}</th>
            <th>{{ __('الحالة') }}</th>
        </tr>
        @foreach ($products as $p)
            <tr>
                <td>{{ $p['name'] }}</td>
                <td>{{ $p['cat'] }}</td>
                <td>{{ $p['sku'] }}</td>
                <td>{{ number_format((float) $p['price'], 3) }} {{ __('ر.ع') }}</td>
                <td>{{ $p['qty'] }}</td>
                <td>{{ __($p['stock_status']) }}</td>
                <td>{{ $p['active'] ? __('مفعّل') : __('معطّل') }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

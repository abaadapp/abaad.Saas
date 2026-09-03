@extends('pdf.layout')

@section('title', __('جرد المخزون'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    @php
        $low = collect($inventory)->where('status', 'منخفض')->count();
        $out = collect($inventory)->where('status', 'نفد المخزون')->count();
        $stockValue = collect($inventory)->sum('value');
    @endphp

    <table class="cards">
        <tr>
            <td>
                
                    <div class="lbl">{{ __('عدد الأصناف') }}</div>
                    <div class="val">{{ count($inventory) }}</div>
                </td>
            <td>
                
                    <div class="lbl">{{ __('منخفض') }}</div>
                    <div class="val">{{ $low }}</div>
                </td>
            <td>
                
                    <div class="lbl">{{ __('نفد المخزون') }}</div>
                    <div class="val">{{ $out }}</div>
                </td>
        </tr>
    </table>

    <table class="cards" style="margin-top:6px;">
        <tr>
            <td style="width:100%;">
                
                    <div class="lbl">{{ __('قيمة المخزون') }}</div>
                    <div class="val">{{ number_format($stockValue, 3) }} {{ __('ر.ع') }}</div>
                </td>
        </tr>
    </table>

    <h2>{{ __('الأصناف') }} ({{ count($inventory) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('المنتج') }}</th>
            <th>SKU</th>
            <th>{{ __('الكمية الحالية') }}</th>
            <th>{{ __('الحد الأدنى') }}</th>
            <th>{{ __('القيمة') }}</th>
            <th>{{ __('حالة المخزون') }}</th>
            <th>{{ __('آخر تحديث') }}</th>
        </tr>
        @foreach ($inventory as $i)
            <tr class="{{ (int) $i['qty'] <= 0 ? 'out' : ((int) $i['qty'] <= (int) $i['min'] ? 'low' : '') }}">
                <td>{{ $i['name'] }}</td>
                <td>{{ $i['sku'] }}</td>
                <td>{{ $i['qty'] }}</td>
                <td>{{ $i['min'] }}</td>
                <td>{{ number_format((float) $i['value'], 3) }} {{ __('ر.ع') }}</td>
                <td>{{ __($i['status']) }}</td>
                <td>{{ $i['updated'] }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

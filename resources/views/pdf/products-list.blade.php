@extends('pdf.layout')

@section('title', __('قائمة المنتجات'))

@section('meta')
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
    <div>{{ __('العدد: :n منتجًا', ['n' => $products->count()]) }}</div>
@endsection

@section('body')
    <table class="grid">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th>{{ __('الاسم') }}</th>
                <th>{{ __('القسم') }}</th>
                <th>SKU</th>
                <th>{{ __('الباركود') }}</th>
                <th style="text-align:left;">{{ __('السعر') }}</th>
                <th style="text-align:left;">{{ __('الكمية') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->category?->name ?? '—' }}</td>
                    <td dir="ltr">{{ $p->sku ?: '—' }}</td>
                    <td dir="ltr">{{ $p->barcode ?: '—' }}</td>
                    <td style="text-align:left;">{{ number_format((float) $p->price, 3) }}</td>
                    <td style="text-align:left;">{{ (int) $p->quantity }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">{{ __('لا توجد منتجات.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('قائمة منتجات آلية عبر نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

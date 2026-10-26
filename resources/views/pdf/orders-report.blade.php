@extends('pdf.layout')

@section('title', __('تقرير الطلبات'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    {{-- والتحصيلُ عبر رابط دفع صفٌّ بين الطلبات حين تعرضه الشاشة ($links) — ومجموعُه وحده، ليس من المبيعات --}}
    <table class="cards">
        <tr>
            <td>
                
                    <div class="lbl">{{ __('عدد الطلبات') }}</div>
                    <div class="val">{{ $ordersCount }}</div>
                </td>
            <td>
                
                    <div class="lbl">{{ $links ? __('إجمالي المبيعات') : __('إجمالي القيمة') }}</div>
                    <div class="val">{{ \App\Support\Demo::moneyBase($total) }}</div>
                </td>
            @if ($links)
                <td>
                    <div class="lbl">{{ __('مجموع التحصيلات عبر روابط الدفع (ليس من المبيعات)') }}</div>
                    <div class="val">{{ \App\Support\Demo::moneyBase($links['amount']) }} ({{ $links['count'] }})</div>
                </td>
            @endif
        </tr>
    </table>

    <h2>{{ $links ? __('الطلبات وتحصيلات روابط الدفع') : __('الطلبات') }} ({{ count($rows) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ $links ? __('رقم الطلب أو السند') : __('رقم الطلب') }}</th>
            <th>{{ __('العميل') }}</th>
            @if ($links)
                <th>{{ __('المصدر') }}</th>
            @endif
            <th>{{ __('الموظف') }}</th>
            <th>{{ __('الفرع') }}</th>
            <th>{{ __('المنتجات') }}</th>
            <th>{{ __('الإجمالي') }}</th>
            @if ($links)
                <th>{{ __('المحصّل عبر رابط دفع') }}</th>
            @endif
            <th>{{ __('الدفع') }}</th>
            <th>{{ __('الحالة') }}</th>
            <th>{{ __('التاريخ') }}</th>
            <th>{{ __('التسليم / الاستلام') }}</th>
        </tr>
        @foreach ($rows as $o)
            @if (($o['kind'] ?? 'order') === 'link')
                <tr>
                    <td>{{ $o['id'] }}<br>Paymob · {{ $o['reference'] }}</td>
                    <td>{{ $o['customer'] }}</td>
                    <td>{{ __('رابط دفع') }} — {{ $o['invoice']['number'] ?? __($o['type']) }}</td>
                    {{-- ومنشئُ الرابط موظّفُ التحصيل، والفرعُ والمنتجاتُ والموعدُ من فاتورته (`PaidLinksList::row`) --}}
                    <td>{{ $o['creator'] ?? '—' }}</td>
                    <td>{{ \App\Support\Lists\AllSalesList::branchCell($o) }}</td>
                    <td>{{ $o['products']['label'] ?? __('لا توجد منتجات مرتبطة') }}</td>
                    <td>—</td>
                    <td>{{ \App\Support\Demo::moneyBase($o['amount']) }}</td>
                    <td>Paymob</td>
                    <td>{{ $o['review'] ? __('مدفوع — يحتاج مراجعة') : __($o['status']) }}</td>
                    <td>{{ $o['date'] }}</td>
                    <td>{{ \App\Support\Lists\AllSalesList::handoverCell($o) }}</td>
                </tr>
            @else
                <tr>
                    <td>{{ $o['id'] }}</td>
                    <td>{{ $o['customer'] }}</td>
                    @if ($links)
                        <td>{{ __($o['channel_label']) }}</td>
                    @endif
                    {{-- ومن أكّده تحت من أنشأه — لا بدلَه (`SaleCells::confirmation`) --}}
                    <td>{{ $o['employee'] }}@if (! empty($o['confirmation']))<br>{{ $o['confirmation']['label'] }}@endif</td>
                    <td>{{ $o['branch'] }}</td>
                    <td>{{ $o['products']['label'] ?? $o['items_count'] }}@if (! empty($o['products']['addons_label']))<br>{{ $o['products']['addons_label'] }}@endif</td>
                    <td>{{ \App\Support\Demo::moneyBase($o['total']) }}</td>
                    @if ($links)
                        <td>—</td>
                    @endif
                    <td>{{ __($o['payment']) }}</td>
                    <td>{{ __($o['status']) }}</td>
                    <td>{{ $o['date'] }}</td>
                    <td>{{ $o['handover']['label'] ?? '—' }}@if (! empty($o['late']))<br>{{ __('متأخّر') }}@endif</td>
                </tr>
            @endif
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

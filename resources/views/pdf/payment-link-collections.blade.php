@extends('pdf.layout')

@section('title', __('تحصيلات روابط الدفع'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    {{-- تحصيلٌ لا بيع: البيعُ في فاتورته، وهذا ما دُفع عنها --}}
    <table class="cards">
        <tr>
            <td>
                <div class="lbl">{{ __('عدد التحصيلات') }}</div>
                <div class="val">{{ $totals['count'] }}</div>
            </td>
            <td>
                <div class="lbl">{{ __('المحصّل') }}</div>
                <div class="val">{{ \App\Support\Demo::moneyBase($totals['amount']) }}</div>
            </td>
            <td>
                <div class="lbl">{{ __('منه على فواتير') }}</div>
                <div class="val">{{ \App\Support\Demo::moneyBase($totals['on_invoices']['amount']) }}</div>
            </td>
            <td>
                <div class="lbl">{{ __('منه دون فاتورة') }}</div>
                <div class="val">{{ \App\Support\Demo::moneyBase($totals['standalone']['amount']) }}</div>
            </td>
        </tr>
    </table>

    <h2>{{ __('تحصيلات روابط الدفع') }} ({{ count($rows) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('رقم السند') }}</th>
            <th>{{ __('مرجع Paymob') }}</th>
            <th>{{ __('العميل') }}</th>
            <th>{{ __('النوع') }}</th>
            <th>{{ __('الفاتورة') }}</th>
            <th>{{ __('المبلغ') }}</th>
            <th>{{ __('تاريخ الدفع') }}</th>
            <th>{{ __('الحالة') }}</th>
        </tr>
        @foreach ($rows as $r)
            <tr>
                <td>{{ $r['id'] }}</td>
                <td>{{ $r['reference'] }}</td>
                <td>{{ $r['customer'] }}</td>
                <td>{{ __($r['type']) }}</td>
                <td>{{ $r['invoice']['number'] ?? '—' }}</td>
                <td>{{ \App\Support\Demo::moneyBase($r['amount']) }}</td>
                <td>{{ $r['date'] }}</td>
                <td>{{ $r['review'] ? __('مدفوع — يحتاج مراجعة') : __($r['status']) }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

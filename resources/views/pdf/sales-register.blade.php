@extends('pdf.layout')

@section('title', __('جميع عمليات البيع'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
@endsection

@section('body')
    <table class="cards">
        <tr>
            <td>
                <div class="lbl">{{ __('عدد العمليات') }}</div>
                <div class="val">{{ $totals['count'] }}</div>
            </td>
            <td>
                <div class="lbl">{{ __('قيمة المبيعات') }}</div>
                <div class="val">{{ \App\Support\Demo::moneyBase($totals['sales']) }}</div>
            </td>
            <td>
                <div class="lbl">{{ __('المحصّل') }}</div>
                <div class="val">{{ \App\Support\Demo::moneyBase($totals['collected']) }}</div>
            </td>
            <td>
                <div class="lbl">{{ __('المتبقي') }}</div>
                <div class="val">{{ \App\Support\Demo::moneyBase($totals['outstanding']) }}</div>
            </td>
        </tr>
    </table>

    <h2>{{ __('جميع عمليات البيع') }} ({{ count($rows) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('رقم العملية') }}</th>
            <th>{{ __('النوع') }}</th>
            <th>{{ __('العميل') }}</th>
            <th>{{ __('الموظف') }}</th>
            <th>{{ __('الفرع') }}</th>
            <th>{{ __('التاريخ') }}</th>
            <th>{{ __('المبلغ') }}</th>
            <th>{{ __('السداد') }}</th>
            <th>{{ __('التجهيز') }}</th>
            <th>{{ __('الحالة') }}</th>
        </tr>
        @foreach ($rows as $r)
            <tr>
                <td>{{ $r['number'] }}@if ($r['cover']) <br>{{ $r['cover']['number'] }}@endif</td>
                <td>{{ __($r['type']) }}</td>
                <td>{{ $r['customer'] }}</td>
                <td>
                    @if ($r['seller_kind'] === 'website')
                        {{ __('الموقع الإلكتروني') }}
                    @elseif ($r['seller_kind'] === 'unknown')
                        {{ __('غير محدد') }}
                    @else
                        {{ $r['seller'] }}
                    @endif
                </td>
                <td>{{ $r['branch'] ?? '—' }}</td>
                <td>{{ $r['date'] }}</td>
                <td>{{ \App\Support\Demo::moneyBase($r['amount']) }}</td>
                <td>{{ __((string) $r['pay_state']) }}</td>
                <td>{{ $r['prep'] !== null ? __($r['prep']) : '—' }}</td>
                <td>{{ __((string) $r['status']) }}</td>
            </tr>
        @endforeach
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

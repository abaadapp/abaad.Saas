@extends('pdf.layout')

@section('title', __('كشف حساب عميل'))

@section('meta')
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <table class="grid">
        <tr>
            <td style="width:50%;"><strong>{{ __('العميل:') }}</strong> {{ $customer->name }}</td>
            <td style="width:50%;"><strong>{{ __('الهاتف:') }}</strong> {{ $customer->phone ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('البريد:') }}</strong> {{ $customer->email ?: '—' }}</td>
            <td><strong>{{ __('نقاط الولاء:') }}</strong> {{ $customer->points }}</td>
        </tr>
    </table>

    <h2>{{ __('سجل المشتريات') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('المصدر') }}</th><th>{{ __('حالة الدفع') }}</th><th>{{ __('القيمة') }}</th></tr>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['number'] ?? '—' }}@if ($r['cover'] && $r['kind'] === 'order') <span style="color:#6b7280;">({{ $r['cover']['number'] }})</span>@endif</td>
                <td dir="ltr">{{ $r['date'] }}</td>
                <td>{{ __($r['type']) }}</td>
                <td><span class="pill">{{ __($r['pay_state']) }}</span></td>
                <td style="text-align:left; font-weight:bold;">@if ($r['cancelled'])<span style="text-decoration:line-through; color:#9ca3af;">{{ \App\Support\Demo::moneyBase($r['amount']) }}</span>@else{{ \App\Support\Demo::moneyBase($r['amount']) }}@if (($r['credited'] ?? 0) > 0)<div style="font-weight:normal; color:#b45309;">{{ __('إشعار دائن') }} −{{ \App\Support\Demo::moneyBase($r['credited']) }}</div>@endif @endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">{{ __('لا مشتريات لهذا العميل بعد.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('الملخّص') }}</h2>
    <table class="grid">
        <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">{{ __('إجمالي المشتريات') }}</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($totalSpent) }}</td></tr>
        <tr><td style="color:#6b7280;">{{ __('المحصَّل منها') }}</td><td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($collected) }}</td></tr>
        <tr><td style="color:#6b7280;">{{ __('المتبقّي عليها') }}</td><td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($outstanding) }}</td></tr>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('كشف حساب آلي عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بعملة') }} <span dir="ltr">{{ \App\Support\Demo::baseCurrency()['code'] }}</span></div>
@endsection

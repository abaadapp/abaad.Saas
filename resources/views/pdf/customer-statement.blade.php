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

    <h2>{{ __('سجل الطلبات') }}</h2>
    <table class="grid">
        <tr><th>{{ __('رقم الطلب') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('وسيلة الدفع') }}</th><th>{{ __('الإجمالي') }}</th></tr>
        @forelse ($orders as $o)
            <tr>
                <td>{{ $o->number }}</td>
                <td>{{ optional($o->ordered_at)->format('Y-m-d') }}</td>
                <td><span class="pill">{{ __($o->status) }}</span></td>
                <td>{{ __($o->payment_method) }}</td>
                <td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($o->total) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">{{ __('لا توجد طلبات لهذا العميل.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('الملخّص') }}</h2>
    <table class="grid">
        <tr><td style="color:#6b7280;">{{ __('إجمالي المشتريات') }}</td><td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($totalSpent) }}</td></tr>
        <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">{{ __('صافي الإنفاق') }}</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($net) }}</td></tr>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('كشف حساب آلي عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بالريال العماني') }}</div>
@endsection

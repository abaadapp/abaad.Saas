<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #7c3aed; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #7c3aed; }
    .muted { color: #6b7280; font-size: 10px; }
    h2 { font-size: 13px; color: #4c1d95; margin: 16px 0 8px; border-right: 4px solid #7c3aed; padding-right: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f5f3ff; color: #4c1d95; text-align: right; padding: 7px; font-size: 10px; border-bottom: 1px solid #ede9fe; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .info td { border: none; padding: 3px 0; }
    .totals td { padding: 8px; font-size: 12px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
    .pill { background: #f5f3ff; border-radius: 6px; padding: 2px 6px; font-size: 9px; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('كشف عمولة موظف') }}</div>
            <div class="muted">{{ __('الفترة:') }} {{ $month }}</div>
            <div class="muted">{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<table class="info">
    <tr>
        <td style="width:50%;"><strong>{{ __('الموظف:') }}</strong> {{ $employee->name }}</td>
        <td style="width:50%;"><strong>{{ __('الدور:') }}</strong> {{ __($employee->roleLabel()) }}</td>
    </tr>
    <tr>
        <td><strong>{{ __('الفرع:') }}</strong> {{ $employee->branch ?: '—' }}</td>
        <td><strong>{{ __('الهاتف:') }}</strong> {{ $employee->phone ?: '—' }}</td>
    </tr>
</table>

<h2>{{ __('طلبات الشهر') }} ({{ $orders_count }})</h2>
<table>
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
        <tr><td colspan="5" style="text-align:center; color:#9ca3af;">{{ __('لا توجد طلبات لهذا الموظف خلال الشهر.') }}</td></tr>
    @endforelse
</table>

<h2>{{ __('احتساب العمولة') }}</h2>
<table class="totals">
    <tr><td style="color:#6b7280;">{{ __('إجمالي المبيعات المحقّقة') }}</td><td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($achieved) }}</td></tr>
    <tr><td style="color:#6b7280;">{{ __('الهدف الشهري') }}</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($target) }} ({{ $pct }}%)</td></tr>
    <tr><td style="color:#6b7280;">{{ __('نسبة العمولة') }}</td><td style="text-align:left;">{{ $rate }}%</td></tr>
    <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">{{ __('العمولة المستحقّة') }}</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($commission) }}</td></tr>
</table>

<div class="foot">{{ __('كشف عمولة آلي عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بالريال العماني') }}</div>

@php $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع'); @endphp
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; font-size: 11px; color: #111; }
    .center { text-align: center; }
    .muted { color: #666; }
    h1 { font-size: 15px; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .items th, .items td { padding: 3px 0; font-size: 10px; }
    .items th { border-bottom: 1px solid #000; text-align: right; }
    .items td { border-bottom: 1px dashed #ccc; }
    .totals td { padding: 2px 0; }
    .totals .label { color: #444; }
    .totals .grand { font-size: 14px; font-weight: bold; border-top: 1px solid #000; padding-top: 4px; }
    .dash { border-top: 1px dashed #999; margin: 6px 0; }
</style>

<div class="center">
    <h1>🌹 زهرة مسقط</h1>
    <div class="muted">{{ __('نظام Abad POS') }} — {{ __('الفرع الرئيسي') }}</div>
    <div class="muted">مسقط، سلطنة عُمان · +968 24123456</div>
</div>

<div class="dash"></div>

<table>
    <tr><td class="muted">{{ __('رقم الفاتورة') }}</td><td style="text-align:left">{{ $order->number }}</td></tr>
    <tr><td class="muted">{{ __('الموظف') }}</td><td style="text-align:left">{{ $order->employee_name ?? '—' }}</td></tr>
    <tr><td class="muted">{{ __('العميل') }}</td><td style="text-align:left">{{ $order->customer_name ?? __('عميل نقدي') }}</td></tr>
    <tr><td class="muted">{{ __('التاريخ') }}</td><td style="text-align:left">{{ optional($order->ordered_at)->format('Y-m-d H:i') }}</td></tr>
</table>

<div class="dash"></div>

<table class="items">
    <thead>
        <tr><th>{{ __('الصنف') }}</th><th class="center">{{ __('الكمية') }}</th><th style="text-align:left">{{ __('الإجمالي') }}</th></tr>
    </thead>
    <tbody>
        @foreach ($order->items as $it)
            <tr>
                <td>{{ $it->name }}</td>
                <td class="center">{{ $it->quantity }}</td>
                <td style="text-align:left">{{ $money($it->total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="dash"></div>

<table class="totals">
    <tr><td class="label">{{ __('المجموع الفرعي') }}</td><td style="text-align:left">{{ $money($order->subtotal) }}</td></tr>
    <tr><td class="label">{{ __('الخصم') }}</td><td style="text-align:left">{{ $money($order->discount) }}</td></tr>
    <tr><td class="label">{{ __('الضريبة') }}</td><td style="text-align:left">{{ $money($order->tax) }}</td></tr>
    <tr><td class="label">{{ __('رسوم التوصيل') }}</td><td style="text-align:left">{{ $money($order->delivery_fee) }}</td></tr>
    <tr><td class="grand label">{{ __('الإجمالي') }}</td><td class="grand" style="text-align:left">{{ $money($order->total) }}</td></tr>
    <tr><td class="label">{{ __('وسيلة الدفع') }}</td><td style="text-align:left">{{ __($order->payment_method) }}</td></tr>
</table>

<div class="dash"></div>

<div class="center muted">
    {{ __('شكرًا لزيارتكم') }} 🌹<br>
    {{ __('نتشرف بخدمتكم دائمًا') }}
</div>

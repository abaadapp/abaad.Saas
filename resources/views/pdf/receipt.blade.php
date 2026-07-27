@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $business = $order->business;
    $itemsCount = $order->items->sum('quantity');
@endphp
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
    <h1>{{ $business->name ?? __('نظام Abad POS') }}</h1>
    <div class="muted">{{ __('نظام Abad POS') }} — {{ $order->branch ?? __('الفرع الرئيسي') }}</div>
    <div class="muted">{{ $business->city ?? '' }}@if($business && $business->phone) · {{ $business->phone }}@endif</div>
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
        <tr>
            <th>{{ __('الصنف') }}</th>
            <th class="center">{{ __('السعر') }}</th>
            <th class="center">{{ __('الكمية') }}</th>
            <th style="text-align:left">{{ __('الإجمالي') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $it)
            <tr>
                <td>
                    {{ $it->name }}
                    @if ($it->note)
                        <div class="muted" style="font-size:9px">— {{ $it->note }}</div>
                    @endif
                </td>
                <td class="center">{{ $money($it->price) }}</td>
                <td class="center">{{ $it->quantity }}</td>
                <td style="text-align:left">{{ $money($it->total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="muted" style="margin-top:4px; font-size:9px">{{ __('عدد الأصناف') }}: {{ $itemsCount }}</div>

<div class="dash"></div>

<table class="totals">
    <tr><td class="label">{{ __('المجموع الفرعي') }}</td><td style="text-align:left">{{ $money($order->subtotal) }}</td></tr>
    <tr><td class="label">{{ __('الخصم') }}</td><td style="text-align:left">{{ $money($order->discount) }}</td></tr>
    <tr><td class="label">{{ __('الضريبة') }}</td><td style="text-align:left">{{ $money($order->tax) }}</td></tr>
    <tr><td class="label">{{ __('رسوم التوصيل') }}</td><td style="text-align:left">{{ $money($order->delivery_fee) }}</td></tr>
    <tr><td class="grand label">{{ __('الإجمالي') }}</td><td class="grand" style="text-align:left">{{ $money($order->total) }}</td></tr>
    <tr><td class="label">{{ __('وسيلة الدفع') }}</td><td style="text-align:left">{{ $order->payment_method === 'بطاقة' ? __('فيزا') : __($order->payment_method) }}</td></tr>
    @if (($order->points_earned ?? 0) > 0)
        <tr><td class="label">{{ __('نقاط ولاء مكتسبة') }}</td><td style="text-align:left">{{ $order->points_earned }}</td></tr>
    @endif
</table>

<div class="dash"></div>

@if (!empty($qr))
    <div class="center" style="margin: 6px 0;">
        <barcode code="{{ $qr }}" type="QR" class="barcode" size="0.9" error="M" />
        <div class="muted" style="font-size:8px; margin-top:2px;">{{ __('رمز الفوترة الإلكترونية') }}</div>
    </div>
    <div class="dash"></div>
@endif

<div class="center muted">
    {{ __('شكرًا لزيارتكم') }} 🌹<br>
    {{ __('نتشرف بخدمتكم دائمًا') }}
</div>

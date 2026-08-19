<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #7c3aed; padding-bottom: 10px; margin-bottom: 14px; }
    .brand { font-size: 20px; font-weight: bold; color: #7c3aed; }
    .muted { color: #6b7280; font-size: 10px; }
    .title { font-size: 16px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f5f3ff; color: #4c1d95; text-align: right; padding: 7px; font-size: 10px; border-bottom: 1px solid #ede9fe; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .info td { border: none; padding: 3px 0; }
    .totals td { padding: 6px 8px; font-size: 11px; }
    .foot { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
            @if (!empty($vat['number']))<div class="muted">{{ __('الرقم الضريبي (TRN):') }} {{ $vat['number'] }}</div>@endif
        </td>
        <td style="border:none; text-align:left;">
            <div class="title">{{ __('فاتورة ضريبية') }}</div>
            <div class="muted">{{ __('رقم:') }} {{ $order->number }}</div>
            <div class="muted">{{ __('التاريخ:') }} {{ optional($order->ordered_at)->format('Y-m-d H:i') ?? $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<table class="info">
    <tr>
        <td style="width:50%;"><strong>{{ __('العميل:') }}</strong> {{ \App\Support\Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي') }}@if (!empty($customerTax))<br><span class="muted">{{ __('الرقم الضريبي (TRN):') }} {{ $customerTax }}</span>@endif</td>
        <td style="width:50%;"><strong>{{ __('وسيلة الدفع:') }}</strong> {{ __($order->payment_method) }}</td>
    </tr>
    <tr>
        <td><strong>{{ __('الفرع:') }}</strong> {{ $order->branch }}</td>
        <td><strong>{{ __('الموظف:') }}</strong> {{ $order->employee_name ?: '—' }}</td>
    </tr>
</table>

<table>
    <tr><th>{{ __('الصنف') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('السعر') }}</th><th>{{ __('الإجمالي') }}</th></tr>
    @foreach ($order->items as $it)
        <tr>
            <td>{{ $it->name }}</td>
            <td>{{ $it->quantity }}</td>
            <td>{{ \App\Support\Demo::moneyBase($it->price) }}</td>
            <td style="text-align:left;">{{ \App\Support\Demo::moneyBase($it->total) }}</td>
        </tr>
    @endforeach
</table>

@php
    $subtotal = (float) $order->subtotal;
    $discount = (float) $order->discount;
    $vatAmount = (float) $order->tax;
    $delivery = (float) $order->delivery_fee;
@endphp
<table class="totals" style="margin-top:12px; width:55%; float:left;">
    <tr><td style="color:#6b7280;">{{ __('المجموع الفرعي (قبل الضريبة)') }}</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($subtotal) }}</td></tr>
    @if ($discount > 0)<tr><td style="color:#6b7280;">{{ __('الخصم') }}</td><td style="text-align:left; color:#dc2626;">- {{ \App\Support\Demo::moneyBase($discount) }}</td></tr>@endif
    @if ($delivery > 0)<tr><td style="color:#6b7280;">{{ __('رسوم التوصيل') }}</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($delivery) }}</td></tr>@endif
    <tr><td style="color:#6b7280;">{{ __('ضريبة القيمة المضافة') }} ({{ rtrim(rtrim(number_format($vat['rate'],2,'.',''),'0'),'.') }}%)</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($vatAmount) }}</td></tr>
    <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">{{ __('الإجمالي المستحقّ') }}</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($order->total) }}</td></tr>
</table>
@if (!empty($qr))
    <div style="float:right; width:42%; text-align:center; margin-top:12px;">
        <barcode code="{{ $qr }}" type="QR" class="barcode" size="1.0" error="M" />
        <div class="muted" style="margin-top:4px;">{{ __('رمز الفوترة الإلكترونية — امسحه للتحقق من الفاتورة') }}</div>
    </div>
@endif
<div style="clear:both;"></div>

<div class="foot">{{ __('فاتورة ضريبية صادرة آليًا عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بالريال العماني') }}</div>

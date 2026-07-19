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
            @if (!empty($vat['number']))<div class="muted">الرقم الضريبي (TRN): {{ $vat['number'] }}</div>@endif
        </td>
        <td style="border:none; text-align:left;">
            <div class="title">فاتورة ضريبية</div>
            <div class="muted">رقم: {{ $order->number }}</div>
            <div class="muted">التاريخ: {{ optional($order->ordered_at)->format('Y-m-d H:i') ?? $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<table class="info">
    <tr>
        <td style="width:50%;"><strong>العميل:</strong> {{ $order->customer_name ?: 'عميل نقدي' }}</td>
        <td style="width:50%;"><strong>وسيلة الدفع:</strong> {{ $order->payment_method }}</td>
    </tr>
    <tr>
        <td><strong>الفرع:</strong> {{ $order->branch }}</td>
        <td><strong>الموظف:</strong> {{ $order->employee_name ?: '—' }}</td>
    </tr>
</table>

<table>
    <tr><th>الصنف</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr>
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
    <tr><td style="color:#6b7280;">المجموع الفرعي (قبل الضريبة)</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($subtotal) }}</td></tr>
    @if ($discount > 0)<tr><td style="color:#6b7280;">الخصم</td><td style="text-align:left; color:#dc2626;">- {{ \App\Support\Demo::moneyBase($discount) }}</td></tr>@endif
    @if ($delivery > 0)<tr><td style="color:#6b7280;">رسوم التوصيل</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($delivery) }}</td></tr>@endif
    <tr><td style="color:#6b7280;">ضريبة القيمة المضافة ({{ rtrim(rtrim(number_format($vat['rate'],2,'.',''),'0'),'.') }}%)</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($vatAmount) }}</td></tr>
    <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">الإجمالي المستحقّ</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($order->total) }}</td></tr>
</table>
<div style="clear:both;"></div>

<div class="foot">فاتورة ضريبية صادرة آليًا عبر نظام Abad POS — {{ $generatedAt }} — القيم بالريال العماني</div>

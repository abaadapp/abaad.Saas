@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $business = $order->business;
    $itemsCount = $order->items->sum('quantity');

    /**
     * قالب الإيصال من إعدادات «قوالب». كان الإيصال ثابتًا لا يقبل تعديلًا:
     * التاجر الذي يريد إخفاء اسم الموظف أو إضافة سطر ترحيب لا سبيل له.
     *
     * والافتراضات هي شكل الإيصال السابق حرفيًّا — تاجرٌ لم يفتح «قوالب» قطّ
     * يجب أن يطبع اليوم ما كان يطبعه أمس.
     */
    $tpl = $tpl ?? [];
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k, string $default = '') => trim((string) ($tpl[$k] ?? $default));
    $scale = ['صغير' => 0.85, 'كبير' => 1.15][$tpl['tpl_font'] ?? ''] ?? 1.0;
    $px = fn (float $base) => round($base * $scale, 1) . 'px';
@endphp
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; font-size: {{ $px(11) }}; color: #111; }
    .center { text-align: center; }
    .muted { color: #666; }
    h1 { font-size: {{ $px(15) }}; margin: 0; }
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
    @if ($show('tpl_show_logo', false) && ($business->logo ?? null))
        <img src="{{ $business->logo }}" style="max-height:{{ $px(46) }}; margin-bottom:4px;" alt="">
    @endif
    <h1>{{ $business->name ?? __('نظام Abad POS') }}</h1>
    @if ($line('tpl_header') !== '')
        <div class="muted">{{ $line('tpl_header') }}</div>
    @endif
    @if ($show('tpl_show_branch'))
        <div class="muted">{{ __('نظام Abad POS') }} — {{ $order->branch ?? __('الفرع الرئيسي') }}</div>
    @endif
    <div class="muted">{{ $business->city ?? '' }}@if($business && $business->phone) · {{ $business->phone }}@endif</div>
    @if ($show('tpl_show_vat_no', false) && $line('vat_number') !== '')
        <div class="muted">{{ __('الرقم الضريبي') }}: {{ $line('vat_number') }}</div>
    @endif
</div>

<div class="dash"></div>

<table>
    <tr><td class="muted">{{ __('رقم الفاتورة') }}</td><td style="text-align:left">{{ $order->number }}</td></tr>
    @if ($show('tpl_show_employee'))
        <tr><td class="muted">{{ __('الموظف') }}</td><td style="text-align:left">{{ $order->employee_name ?? '—' }}</td></tr>
    @endif
    @if ($show('tpl_show_customer'))
        <tr><td class="muted">{{ __('العميل') }}</td><td style="text-align:left">{{ \App\Support\Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي') }}</td></tr>
    @endif
    @if ($show('tpl_show_datetime'))
        <tr><td class="muted">{{ __('التاريخ') }}</td><td style="text-align:left">{{ optional($order->ordered_at)->format('Y-m-d H:i') }}</td></tr>
    @endif
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

@if ($show('tpl_show_items_count'))
    <div class="muted" style="margin-top:4px; font-size:{{ $px(9) }}">{{ __('عدد الأصناف') }}: {{ $itemsCount }}</div>
@endif

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

@if (!empty($qr) && $show('tpl_show_qr'))
    <div class="center" style="margin: 6px 0;">
        <barcode code="{{ $qr }}" type="QR" class="barcode" size="0.9" error="M" />
        <div class="muted" style="font-size:8px; margin-top:2px;">{{ __('رمز الفوترة الإلكترونية') }}</div>
    </div>
    <div class="dash"></div>
@endif

{{-- التذييل نصّ التاجر: أسطره تُحترم كما كتبها، ويُنقّى ممّا لا يطبعه الخطّ --}}
<div class="center muted">
    @foreach (preg_split('/\r\n|\r|\n/', $line('tpl_footer', __('شكرًا لزيارتكم') . "\n" . __('نتشرف بخدمتكم دائمًا'))) as $l)
        @php($clean = \App\Support\ReceiptTemplate::printable($l))
        @if ($clean !== ''){{ $clean }}@if (! $loop->last)<br>@endif @endif
    @endforeach
</div>

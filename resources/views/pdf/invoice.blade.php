@php $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع'); @endphp
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; color: #1f2937; font-size: 13px; }
    .header { border-bottom: 3px solid #7c3aed; padding-bottom: 14px; margin-bottom: 20px; }
    .brand { font-size: 24px; font-weight: bold; color: #7c3aed; }
    .muted { color: #6b7280; font-size: 12px; }
    .title { font-size: 20px; font-weight: bold; margin: 18px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    .info td { padding: 4px 0; }
    .info .k { color: #6b7280; width: 40%; }
    .items { margin-top: 18px; }
    .items th { background: #f5f3ff; color: #6d28d9; padding: 10px; text-align: right; font-size: 12px; }
    .items td { padding: 10px; border-bottom: 1px solid #eee; }
    .total-box { margin-top: 18px; background: #f9fafb; border-radius: 8px; padding: 14px; }
    .total-box .grand { font-size: 20px; font-weight: bold; color: #7c3aed; }
    .badge { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: bold; }
    .paid { background: #ecfdf5; color: #047857; }
    .unpaid { background: #fef2f2; color: #b91c1c; }
    .footer { margin-top: 28px; text-align: center; color: #9ca3af; font-size: 11px; border-top: 1px solid #eee; padding-top: 12px; }
</style>

<div class="header">
    <table>
        <tr>
            <td><div class="brand">🌸 Abad POS</div><div class="muted">{{ __('منصة إدارة نقاط البيع') }}</div></td>
            <td style="text-align:left">
                <div class="title">{{ __('فاتورة اشتراك') }}</div>
                <div class="muted">{{ __('رقم:') }} {{ $invoice->number }}</div>
                <div class="muted">{{ __('التاريخ:') }} {{ optional($invoice->issued_at)->format('Y-m-d') }}</div>
            </td>
        </tr>
    </table>
</div>

<table class="info">
    <tr><td class="k">{{ __('الشركة') }}</td><td>{{ $invoice->business?->name ?? '—' }}</td></tr>
    <tr><td class="k">{{ __('المالك') }}</td><td>{{ $invoice->business?->owner_name ?? '—' }}</td></tr>
    <tr><td class="k">{{ __('البريد الإلكتروني') }}</td><td>{{ $invoice->business?->email ?? '—' }}</td></tr>
    <tr><td class="k">{{ __('حالة الدفع') }}</td><td>
        <span class="badge {{ $invoice->status === 'مدفوعة' ? 'paid' : 'unpaid' }}">{{ __($invoice->status) }}</span>
    </td></tr>
</table>

<table class="items">
    <thead>
        <tr><th>{{ __('الوصف') }}</th><th>{{ __('الباقة') }}</th><th style="text-align:left">{{ __('المبلغ') }}</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ __('اشتراك في منصة Abad POS') }}</td>
            <td>{{ $invoice->plan?->name ?? '—' }}</td>
            <td style="text-align:left">{{ $money($invoice->amount) }}</td>
        </tr>
    </tbody>
</table>

<div class="total-box">
    <table>
        <tr>
            <td class="muted">{{ __('الإجمالي المستحق') }}</td>
            <td class="grand" style="text-align:left">{{ $money($invoice->amount) }}</td>
        </tr>
    </table>
</div>

<div class="footer">
    {{ __('شكرًا لاشتراككم في Abad POS · هذه فاتورة إلكترونية ولا تحتاج إلى توقيع') }}
</div>

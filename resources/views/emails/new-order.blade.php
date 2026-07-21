<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#7c3aed; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">🌹 {{ __('طلب جديد في متجرك') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">Abad POS</p>
        </div>
        <div style="padding:24px;">
            <p style="font-size:15px;">{{ __('تم تسجيل طلب جديد رقم') }} <strong>{{ $order->number }}</strong>.</p>
            <table style="width:100%; border-collapse:collapse; font-size:14px; margin-top:12px;">
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('العميل') }}</td><td style="padding:6px 0; text-align:left; font-weight:bold;">{{ $order->customer_name ?? __('عميل نقدي') }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('الموظف') }}</td><td style="padding:6px 0; text-align:left;">{{ $order->employee_name ?? '—' }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('وسيلة الدفع') }}</td><td style="padding:6px 0; text-align:left;">{{ __($order->payment_method) }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('الإجمالي') }}</td><td style="padding:6px 0; text-align:left; font-weight:bold; color:#7c3aed;">{{ number_format((float) $order->total, 3) }} {{ __('ر.ع') }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('الوقت') }}</td><td style="padding:6px 0; text-align:left;">{{ optional($order->ordered_at)->format('Y-m-d H:i') }}</td></tr>
            </table>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('رسالة آلية من نظام Abad POS') }}
        </div>
    </div>
</body>
</html>

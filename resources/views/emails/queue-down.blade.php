<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#dc2626; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">{{ __('توقّف عامل الطابور') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $host }} — Abaad</p>
        </div>
        <div style="padding:24px; font-size:14px; line-height:1.9;">
            <p style="margin:0 0 12px;">{{ __('ما دام متوقّفًا فكلُّ مهمّة تُصفّ في الطابور تبقى بلا تنفيذ — ومنها الحذف النهائي للشركات.') }}</p>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('الوحدة') }}</td><td style="padding:6px 0;" dir="ltr">{{ $unit }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('الوقت') }}</td><td style="padding:6px 0;">{{ $at }}</td></tr>
            </table>
            <p style="margin:16px 0 6px; color:#6b7280;">{{ __('للفحص على الخادم:') }}</p>
            <pre dir="ltr" style="background:#f9fafb; border:1px solid #f3f4f6; border-radius:8px; padding:12px; font-size:12px; overflow:auto; margin:0;">systemctl status {{ $unit }}
journalctl -u {{ $unit }} -n 50 --no-pager
php artisan purge:check</pre>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('رسالة آلية من نظام أبعاد') }}
        </div>
    </div>
</body>
</html>

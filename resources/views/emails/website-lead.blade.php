<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#111827; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">{{ __('طلب تواصل جديد من موقع أبعاد') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $inquiry['business_name'] }}</p>
        </div>
        <div style="padding:24px; font-size:14px; line-height:1.9;">
            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                <tr><td style="padding:6px 0; color:#6b7280; width:120px;">{{ __('الاسم') }}</td><td style="padding:6px 0;">{{ $inquiry['name'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('اسم النشاط') }}</td><td style="padding:6px 0;">{{ $inquiry['business_name'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('الهاتف') }}</td><td style="padding:6px 0;" dir="ltr">{{ $inquiry['phone'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('البريد') }}</td><td style="padding:6px 0;" dir="ltr">{{ $inquiry['email'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('نوع النشاط') }}</td><td style="padding:6px 0;">{{ $inquiry['activity'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('لغة الصفحة') }}</td><td style="padding:6px 0;">{{ $inquiry['locale'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('تاريخ الاستلام') }}</td><td style="padding:6px 0;" dir="ltr">{{ $inquiry['received_at'] }}</td></tr>
                <tr><td style="padding:6px 0; color:#6b7280;">{{ __('المصدر') }}</td><td style="padding:6px 0;">{{ $inquiry['source'] }}</td></tr>
            </table>
            <p style="margin:16px 0 6px; color:#6b7280;">{{ __('الرسالة') }}</p>
            <div style="background:#f9fafb; border:1px solid #f3f4f6; border-radius:8px; padding:12px; white-space:pre-wrap;">{{ $inquiry['message'] }}</div>
            <p style="margin:20px 0 0;">
                <a href="{{ $inquiry['lead_url'] }}" style="display:inline-block; background:#111827; color:#fff; text-decoration:none; border-radius:10px; padding:10px 18px; font-weight:bold;">{{ __('فتح ملف العميل في CRM') }}</a>
            </p>
            <p style="margin:12px 0 0; font-size:12px; color:#6b7280;">{{ __('اضغط «رد» ليصل ردّك إلى بريد صاحب الطلب مباشرة.') }}</p>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('رسالة آلية من نظام أبعاد — الطلب محفوظ في CRM المنصة') }}
        </div>
    </div>
</body>
</html>

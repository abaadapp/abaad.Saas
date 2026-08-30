<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#111; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">{{ __('تنبيه أمني') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.8;">{{ __('أبعاد') }}</p>
        </div>
        <div style="padding:24px;">
            <p style="font-size:15px; margin:0 0 16px;">{{ __('مرحبًا،') }}</p>
            <p style="font-size:14px; line-height:1.8; margin:0 0 16px;">
                {{ __('تم تغيير بريد الاستعادة لحسابك في أبعاد.') }}
            </p>
            {{-- ولا يُذكر العنوان الجديد: هذا الصندوق قد يكون هو المخترَق --}}
            <p style="font-size:14px; line-height:1.8; margin:0; color:#b91c1c;">
                {{ __('إذا لم تقم بهذا الإجراء، تواصل مع إدارة أبعاد فورًا.') }}
            </p>
        </div>
        <div style="background:#fafafa; padding:16px 24px; border-top:1px solid #eee;">
            <p style="margin:0; font-size:12px; color:#9ca3af;">{{ __('فريق أبعاد') }}</p>
        </div>
    </div>
</body>
</html>

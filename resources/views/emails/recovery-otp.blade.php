{{--
    رمز التحقّق — ستّة أرقامٍ ولا شيء غيرها.

    لا اسم متجر، ولا معرّف حساب، ولا رابط: الرسالة تُقرأ على شاشةٍ في مكانٍ
    عامّ، وما لا يلزم لا يُكتب. والأرقام كبيرةٌ متباعدة لأنّها تُنسخ باليد.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#111; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">
                {{ $isReset ? __('رمز إعادة تعيين كلمة المرور') : __('رمز التحقق') }}
            </h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.8;">{{ __('أبعاد') }}</p>
        </div>

        <div style="padding:24px;">
            <p style="font-size:15px; margin:0 0 16px;">{{ __('مرحبًا،') }}</p>

            <p style="font-size:14px; line-height:1.8; margin:0 0 20px;">
                {{ $isReset
                    ? __('تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بحسابك في أبعاد.')
                    : __('تلقينا طلبًا للتحقق من بريد الاستعادة الخاص بحسابك في أبعاد.') }}
            </p>

            <p style="text-align:center; margin:0 0 20px;">
                <span dir="ltr" style="display:inline-block; font-family:monospace; font-size:32px; font-weight:bold; letter-spacing:10px; background:#f3f4f6; padding:16px 24px; border-radius:12px; color:#111;">{{ $code }}</span>
            </p>

            <p style="font-size:13px; color:#6b7280; line-height:1.8; margin:0 0 8px;">
                {{ __('الرمز صالح لمدة :minutes دقائق.', ['minutes' => $minutes]) }}
            </p>
            <p style="font-size:13px; color:#6b7280; line-height:1.8; margin:0;">
                {{ __('إذا لم تطلب هذا الإجراء، يمكنك تجاهل الرسالة.') }}
            </p>
        </div>

        <div style="background:#fafafa; padding:16px 24px; border-top:1px solid #eee;">
            <p style="margin:0; font-size:12px; color:#9ca3af;">{{ __('فريق أبعاد') }}</p>
        </div>
    </div>
</body>
</html>

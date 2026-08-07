<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#111; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">🔑 {{ __('إعادة تعيين كلمة المرور') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.8;">Abad POS</p>
        </div>
        <div style="padding:24px;">
            <p style="font-size:15px; margin:0 0 16px;">{{ __('مرحبًا :name،', ['name' => $name]) }}</p>
            <p style="font-size:14px; line-height:1.8; margin:0 0 20px;">
                {{ __('وصلنا طلب لإعادة تعيين كلمة مرور حساب الدخول:') }}
                <br>
                <strong dir="ltr" style="display:inline-block; margin-top:6px; font-family:monospace; background:#f3f4f6; padding:4px 8px; border-radius:6px;">{{ $loginEmail }}</strong>
            </p>

            <p style="text-align:center; margin:0 0 20px;">
                <a href="{{ $url }}" style="display:inline-block; background:#111; color:#fff; text-decoration:none; padding:12px 28px; border-radius:10px; font-size:15px; font-weight:bold;">
                    {{ __('تعيين كلمة مرور جديدة') }}
                </a>
            </p>

            <p style="font-size:13px; color:#6b7280; line-height:1.8; margin:0;">
                {{ __('الرابط صالح :minutes دقيقة، ولمرة واحدة.', ['minutes' => $minutes]) }}
                <br>
                {{ __('إن لم تطلب هذا، تجاهل الرسالة — لن يتغيّر شيء في حسابك.') }}
            </p>

            {{-- الرابط نصًّا: عملاء بريدٍ كثيرون يجرّدون الأزرار، ومن لا يعمل عنده الزر يجب أن يجد ما ينسخه --}}
            <p style="font-size:11px; color:#9ca3af; word-break:break-all; margin:20px 0 0; padding-top:16px; border-top:1px solid #f3f4f6;" dir="ltr">
                {{ $url }}
            </p>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('رسالة آلية من نظام Abad POS') }}
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:600px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#7c3aed; color:#fff; padding:22px 24px;">
            <h1 style="margin:0; font-size:19px;">📊 {{ __('تقرير الأداء الشهري') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $businessName }} — {{ $period }}</p>
        </div>
        <div style="padding:24px;">
            <table style="width:100%; border-collapse:collapse;">
                @foreach ($stats as $s)
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:12px 0; color:#6b7280; font-size:14px;">{{ __($s['label']) }}</td>
                        <td style="padding:12px 0; text-align:left; font-weight:bold; font-size:15px; color:#111827;">{{ $s['value'] }}</td>
                    </tr>
                @endforeach
            </table>
            <p style="margin-top:20px; font-size:13px; color:#6b7280;">{{ __('للاطلاع على التفاصيل الكاملة والرسوم البيانية، سجّل الدخول إلى لوحة التحكم.') }}</p>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('تقرير آلي من نظام Abad POS') }} — {{ __('القيم بالريال العماني') }}
        </div>
    </div>
</body>
</html>

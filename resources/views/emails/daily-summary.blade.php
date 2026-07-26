<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
@php $money = fn ($v) => number_format((float) $v, 3, '.', ',') . ' ' . __('ر.ع'); @endphp
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#111827; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">📊 {{ __('ملخّص اليوم') }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $businessName }} — {{ $dateLabel }}</p>
        </div>
        <div style="padding:24px;">
            {{-- المبيعات (البطاقة الأبرز) --}}
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px; text-align:center; margin-bottom:16px;">
                <div style="font-size:13px; color:#15803d;">{{ __('مبيعات اليوم') }}</div>
                <div style="font-size:26px; font-weight:bold; color:#166534; margin-top:4px;">{{ $money($summary['sales']) }}</div>
                @php $t = $summary['trend']; $tc = $t['up'] ? '#16a34a' : '#dc2626'; @endphp
                <div style="font-size:12px; color:{{ $tc }}; margin-top:4px;">
                    {{ $t['up'] ? '▲' : '▼' }} {{ $t['trend'] }} {{ __('مقارنة بالأمس') }}
                </div>
            </div>

            {{-- شبكة الأرقام --}}
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate; border-spacing:8px;">
                <tr>
                    <td width="50%" style="background:#f9fafb; border-radius:10px; padding:12px;">
                        <div style="font-size:12px; color:#6b7280;">{{ __('عدد الطلبات') }}</div>
                        <div style="font-size:18px; font-weight:bold;">{{ $summary['orders'] }}</div>
                    </td>
                    <td width="50%" style="background:#f9fafb; border-radius:10px; padding:12px;">
                        <div style="font-size:12px; color:#6b7280;">{{ __('متوسط قيمة الطلب') }}</div>
                        <div style="font-size:18px; font-weight:bold;">{{ $money($summary['avg']) }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f9fafb; border-radius:10px; padding:12px;">
                        <div style="font-size:12px; color:#6b7280;">{{ __('عملاء جدد') }}</div>
                        <div style="font-size:18px; font-weight:bold;">{{ $summary['new_customers'] }}</div>
                    </td>
                    <td style="background:#f9fafb; border-radius:10px; padding:12px;">
                        <div style="font-size:12px; color:#6b7280;">{{ __('المصروفات') }}</div>
                        <div style="font-size:18px; font-weight:bold; color:#b91c1c;">{{ $money($summary['expenses']) }}</div>
                    </td>
                </tr>
                <tr>
                    <td style="background:#eff6ff; border-radius:10px; padding:12px;">
                        <div style="font-size:12px; color:#1d4ed8;">{{ __('صافي الأرباح') }}</div>
                        <div style="font-size:18px; font-weight:bold; color:#1e40af;">{{ $money($summary['net']) }}</div>
                    </td>
                    <td style="background:#f9fafb; border-radius:10px; padding:12px;">
                        <div style="font-size:12px; color:#6b7280;">{{ __('الأكثر مبيعًا') }}</div>
                        <div style="font-size:14px; font-weight:bold;">
                            {{ $summary['top_product'] ?? '—' }}
                            @if ($summary['top_product'])<span style="color:#6b7280; font-weight:normal;">({{ $summary['top_qty'] }})</span>@endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('ملخّص آلي يُرسَل نهاية كل يوم من نظام Abad POS') }}
        </div>
    </div>
</body>
</html>

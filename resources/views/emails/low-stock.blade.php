<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#f59e0b; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">⚠️ تنبيه انخفاض المخزون</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $businessName }} — Abad POS</p>
        </div>
        <div style="padding:24px;">
            <p style="font-size:15px;">المنتجات التالية وصلت أو اقتربت من حد التنبيه:</p>
            <table style="width:100%; border-collapse:collapse; font-size:14px; margin-top:12px;">
                <tr style="border-bottom:2px solid #f3f4f6; color:#6b7280;">
                    <th style="text-align:right; padding:8px 0;">المنتج</th>
                    <th style="text-align:center; padding:8px 0;">المتبقي</th>
                    <th style="text-align:center; padding:8px 0;">حد التنبيه</th>
                </tr>
                @foreach ($products as $p)
                    <tr style="border-bottom:1px solid #f9fafb;">
                        <td style="padding:8px 0;">{{ $p->name }}</td>
                        <td style="text-align:center; padding:8px 0; font-weight:bold; color:{{ $p->quantity <= 0 ? '#dc2626' : '#f59e0b' }};">{{ $p->quantity }}</td>
                        <td style="text-align:center; padding:8px 0; color:#9ca3af;">{{ $p->alert_qty }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            رسالة آلية من نظام Abad POS
        </div>
    </div>
</body>
</html>

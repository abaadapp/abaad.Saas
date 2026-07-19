<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#7c3aed; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">🔔 تنبيهات ذكية</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $businessName }} — Abad POS</p>
        </div>
        <div style="padding:24px;">
            <p style="font-size:15px;">رصد النظام {{ count($alerts) }} حدثًا يستحق انتباهك:</p>
            @foreach ($alerts as $a)
                @php $c = ['danger'=>'#dc2626','warning'=>'#f59e0b','info'=>'#3b82f6'][$a['color']] ?? '#6b7280'; @endphp
                <div style="border-right:4px solid {{ $c }}; background:#f9fafb; border-radius:8px; padding:12px 14px; margin-top:10px;">
                    <div style="font-weight:bold; color:{{ $c }}; font-size:13px;">{{ $a['type'] }}</div>
                    <div style="font-size:14px; margin-top:4px;">{{ $a['text'] }}</div>
                </div>
            @endforeach
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            رسالة آلية من نظام Abad POS
        </div>
    </div>
</body>
</html>

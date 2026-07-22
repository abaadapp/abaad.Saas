<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #111111; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111111; }
    .muted { color: #6b7280; font-size: 10px; }
    h2 { font-size: 13px; color: #111111; margin: 18px 0 8px; border-right: 4px solid #111111; padding-right: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #111111; color: #ffffff; text-align: right; padding: 7px; font-size: 10px; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .cards td { width: 50%; padding: 4px; border: none; }
    .card { background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; padding: 10px; }
    .card .lbl { color: #6b7280; font-size: 9px; }
    .card .val { font-size: 16px; font-weight: bold; color: #111827; margin-top: 3px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('تقرير المصروفات') }}</div>
            <div class="muted">{{ $branch }}</div>
            <div class="muted">{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<table class="cards">
    <tr>
        <td>
            <div class="card">
                <div class="lbl">{{ __('عدد المصروفات') }}</div>
                <div class="val">{{ count($expenses) }}</div>
            </div>
        </td>
        <td>
            <div class="card">
                <div class="lbl">{{ __('إجمالي المصروفات') }}</div>
                <div class="val">{{ number_format($total, 3) }} {{ __('ر.ع') }}</div>
            </div>
        </td>
    </tr>
</table>

<h2>{{ __('المصروفات') }} ({{ count($expenses) }})</h2>
<table>
    <tr>
        <th>{{ __('التاريخ') }}</th>
        <th>{{ __('النوع') }}</th>
        <th>{{ __('الوصف') }}</th>
        <th>{{ __('المبلغ') }}</th>
        <th>{{ __('الطريقة') }}</th>
        <th>{{ __('الموظف') }}</th>
    </tr>
    @foreach ($expenses as $e)
        <tr>
            <td>{{ $e['date'] }}</td>
            <td>{{ __($e['type']) }}</td>
            <td>{{ $e['description'] }}</td>
            <td>{{ number_format((float) $e['amount'], 3) }} {{ __('ر.ع') }}</td>
            <td>{{ __($e['method']) }}</td>
            <td>{{ $e['employee'] }}</td>
        </tr>
    @endforeach
</table>

<div class="foot">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>

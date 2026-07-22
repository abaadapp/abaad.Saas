<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #111111; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111111; }
    .muted { color: #6b7280; font-size: 10px; }
    h2 { font-size: 13px; color: #111111; margin: 18px 0 8px; border-right: 4px solid #111111; padding-right: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #111111; color: #ffffff; text-align: right; padding: 7px; font-size: 9px; }
    td { padding: 6px; border-bottom: 1px solid #f3f4f6; font-size: 9px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">Abad POS</div>
            <div class="muted">{{ __('لوحة المنصة') }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('تقرير الشركات') }}</div>
            <div class="muted">{{ __('تاريخ الإصدار') }}: {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<h2>{{ __('الشركات') }} ({{ count($businesses) }})</h2>
<table>
    <tr>
        <th>{{ __('الشركة') }}</th>
        <th>{{ __('النوع') }}</th>
        <th>{{ __('المالك') }}</th>
        <th>{{ __('المدينة') }}</th>
        <th>{{ __('الباقة') }}</th>
        <th>{{ __('الحالة') }}</th>
        <th>{{ __('الفروع') }}</th>
        <th>{{ __('التسجيل') }}</th>
    </tr>
    @foreach ($businesses as $b)
        <tr>
            <td>{{ $b['name'] }}</td>
            <td>{{ __($b['type']) }}</td>
            <td>{{ $b['owner'] }}</td>
            <td>{{ $b['city'] }}</td>
            <td>{{ __($b['plan']) }}</td>
            <td>{{ __($b['status']) }}</td>
            <td>{{ $b['branches'] }}</td>
            <td>{{ $b['registered'] }}</td>
        </tr>
    @endforeach
</table>

<div class="foot">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>

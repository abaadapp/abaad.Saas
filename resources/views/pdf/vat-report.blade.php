<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #7c3aed; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #7c3aed; }
    .muted { color: #6b7280; font-size: 10px; }
    h2 { font-size: 13px; color: #4c1d95; margin: 16px 0 8px; border-right: 4px solid #7c3aed; padding-right: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f5f3ff; color: #4c1d95; text-align: right; padding: 7px; font-size: 10px; border-bottom: 1px solid #ede9fe; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .totals td { padding: 8px; font-size: 12px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
            @if (!empty($vat['number']))<div class="muted">الرقم الضريبي (TRN): {{ $vat['number'] }}</div>@endif
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">إقرار ضريبة القيمة المضافة</div>
            <div class="muted">{{ $report['label'] }} ({{ $report['from'] }} — {{ $report['to'] }})</div>
            <div class="muted">تاريخ الإصدار: {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<h2>التفصيل الشهري</h2>
<table>
    <tr><th>الشهر</th><th>المبيعات الخاضعة</th><th>ضريبة المخرجات</th></tr>
    @foreach ($report['months'] as $m)
        <tr>
            <td>{{ $m['label'] }}</td>
            <td>{{ \App\Support\Demo::moneyBase($m['taxable']) }}</td>
            <td style="text-align:left;">{{ \App\Support\Demo::moneyBase($m['vat']) }}</td>
        </tr>
    @endforeach
</table>

<h2>ملخّص الإقرار (نسبة {{ rtrim(rtrim(number_format($report['rate'],2,'.',''),'0'),'.') }}%)</h2>
<table class="totals">
    <tr><td style="color:#6b7280;">إجمالي المبيعات الخاضعة للضريبة</td><td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($report['taxable_sales']) }}</td></tr>
    <tr><td style="color:#059669;">ضريبة المخرجات (على المبيعات)</td><td style="text-align:left; font-weight:bold; color:#059669;">{{ \App\Support\Demo::moneyBase($report['output_vat']) }}</td></tr>
    <tr><td style="color:#6b7280;">مشتريات مستلمة (أساس المدخلات)</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($report['input_base']) }}</td></tr>
    <tr><td style="color:#d97706;">ضريبة المدخلات (على المشتريات)</td><td style="text-align:left; font-weight:bold; color:#d97706;">- {{ \App\Support\Demo::moneyBase($report['input_vat']) }}</td></tr>
    <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">صافي الضريبة المستحقّة للسداد</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($report['net_vat']) }}</td></tr>
</table>

<div class="foot">إقرار ضريبي آلي عبر نظام Abad POS — {{ $generatedAt }} — القيم بالريال العماني — للاسترشاد فقط</div>

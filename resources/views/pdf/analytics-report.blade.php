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
    .cards td { width: 33%; padding: 4px; border: none; }
    .card { background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; padding: 10px; }
    .card .lbl { color: #6b7280; font-size: 9px; }
    .card .val { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('تقرير التحليلات المتقدمة') }}</div>
            <div class="muted">{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<h2>{{ __('مقارنة الأداء (هذا الشهر مقابل السابق)') }}</h2>
<table class="cards"><tr>
    @foreach ($comparison as $m)
        <td><div class="card">
            <div class="lbl">{{ __($m['label']) }}</div>
            <div class="val">{{ $m['cur'] }}</div>
            <div class="muted">{{ __('السابق:') }} {{ $m['prev'] }} ({{ $m['delta'] >= 0 ? '+' : '' }}{{ $m['delta'] }}%)</div>
        </div></td>
    @endforeach
</tr></table>

<h2>{{ __('أفضل المنتجات مبيعًا') }}</h2>
<table>
    <tr><th>{{ __('المنتج') }}</th><th>{{ __('الكمية المباعة') }}</th><th>{{ __('الإيراد') }}</th></tr>
    @forelse ($topProducts as $p)
        <tr><td>{{ $p['name'] }}</td><td>{{ __(':n وحدة', ['n' => $p['qty']]) }}</td><td>{{ \App\Support\Demo::moneyBase($p['total']) }}</td></tr>
    @empty
        <tr><td colspan="3" style="text-align:center; color:#9ca3af;">{{ __('لا توجد بيانات.') }}</td></tr>
    @endforelse
</table>

<h2>{{ __('أفضل العملاء إنفاقًا') }}</h2>
<table>
    <tr><th>{{ __('العميل') }}</th><th>{{ __('عدد الطلبات') }}</th><th>{{ __('إجمالي الإنفاق') }}</th></tr>
    @forelse ($topCustomers as $c)
        <tr><td>{{ $c['name'] }}</td><td>{{ $c['orders'] }}</td><td>{{ \App\Support\Demo::moneyBase($c['total']) }}</td></tr>
    @empty
        <tr><td colspan="3" style="text-align:center; color:#9ca3af;">{{ __('لا توجد بيانات.') }}</td></tr>
    @endforelse
</table>

<h2>{{ __('المبيعات حسب التصنيف') }}</h2>
<table>
    <tr><th>{{ __('التصنيف') }}</th><th>{{ __('المبيعات') }}</th></tr>
    @foreach ($categorySales['labels'] as $i => $label)
        <tr><td>{{ $label }}</td><td>{{ \App\Support\Demo::moneyBase($categorySales['series'][$i] ?? 0) }}</td></tr>
    @endforeach
</table>

<h2>{{ __('المبيعات حسب أيام الأسبوع') }}</h2>
<table>
    <tr><th>{{ __('اليوم') }}</th><th>{{ __('المبيعات') }}</th></tr>
    @foreach ($byWeekday['labels'] as $i => $label)
        <tr><td>{{ __($label) }}</td><td>{{ \App\Support\Demo::moneyBase($byWeekday['data'][$i] ?? 0) }}</td></tr>
    @endforeach
</table>

<div class="foot">{{ __('تقرير آلي عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بالريال العماني') }}</div>

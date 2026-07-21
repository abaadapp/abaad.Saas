<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111; }
    .muted { color: #6b7280; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f3f4f6; color: #111; text-align: right; padding: 7px; font-size: 10px; border-bottom: 1px solid #e5e7eb; }
    td { padding: 6px 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('قائمة العملاء') }}</div>
            <div class="muted">{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
            <div class="muted">{{ __('العدد: :n عميل', ['n' => $customers->count()]) }}</div>
        </td>
    </tr></table>
</div>

<table>
    <thead>
        <tr>
            <th style="width:4%;">#</th>
            <th>{{ __('الاسم') }}</th>
            <th>{{ __('الهاتف') }}</th>
            <th>{{ __('البريد') }}</th>
            <th>{{ __('الفرع') }}</th>
            <th style="text-align:left;">{{ __('النقاط') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($customers as $i => $c)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $c->name }}</td>
                <td dir="ltr">{{ $c->phone ?: '—' }}</td>
                <td dir="ltr">{{ $c->email ?: '—' }}</td>
                <td>{{ $c->branch?->name ?? '—' }}</td>
                <td style="text-align:left;">{{ $c->points }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center; color:#9ca3af;">{{ __('لا يوجد عملاء.') }}</td></tr>
        @endforelse
    </tbody>
</table>

<div class="foot">{{ __('قائمة عملاء آلية عبر نظام Abad POS') }} — {{ $generatedAt }}</div>

@extends('pdf.layout')

@section('title', __('إقرار ضريبة القيمة المضافة'))

@section('meta')
    <div>{{ __($report['label']) }} ({{ $report['from'] }} — {{ $report['to'] }})</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('التفصيل الشهري') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الشهر') }}</th><th>{{ __('المبيعات الخاضعة') }}</th><th>{{ __('ضريبة المخرجات') }}</th></tr>
        @foreach ($report['months'] as $m)
            <tr>
                <td>{{ __($m['label']) }}</td>
                <td>{{ \App\Support\Demo::moneyBase($m['taxable']) }}</td>
                <td style="text-align:left;">{{ \App\Support\Demo::moneyBase($m['vat']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('ملخّص الإقرار') }} ({{ __('نسبة') }} {{ rtrim(rtrim(number_format($report['rate'],2,'.',''),'0'),'.') }}%)</h2>
    <table class="grid">
        <tr><td style="color:#6b7280;">{{ __('إجمالي المبيعات الخاضعة للضريبة') }}</td><td style="text-align:left; font-weight:bold;">{{ \App\Support\Demo::moneyBase($report['taxable_sales']) }}</td></tr>
        <tr><td style="color:#059669;">{{ __('ضريبة المخرجات (على المبيعات)') }}</td><td style="text-align:left; font-weight:bold; color:#059669;">{{ \App\Support\Demo::moneyBase($report['output_vat']) }}</td></tr>
        <tr><td style="color:#6b7280;">{{ __('مشتريات مستلمة (أساس المدخلات)') }}</td><td style="text-align:left;">{{ \App\Support\Demo::moneyBase($report['input_base']) }}</td></tr>
        <tr><td style="color:#d97706;">{{ __('ضريبة المدخلات (على المشتريات)') }}</td><td style="text-align:left; font-weight:bold; color:#d97706;">- {{ \App\Support\Demo::moneyBase($report['input_vat']) }}</td></tr>
        <tr style="border-top:2px solid #7c3aed;"><td style="font-weight:bold;">{{ __('صافي الضريبة المستحقّة للسداد') }}</td><td style="text-align:left; font-weight:bold; color:#7c3aed; font-size:14px;">{{ \App\Support\Demo::moneyBase($report['net_vat']) }}</td></tr>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('إقرار ضريبي آلي عبر نظام Abad POS') }} — {{ $generatedAt }} — {{ __('القيم بالريال العماني') }} — {{ __('للاسترشاد فقط') }}</div>
@endsection

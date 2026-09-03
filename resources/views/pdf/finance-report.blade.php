<style>
    * { font-family: 'dejavusans', sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid #111111; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; color: #111111; }
    .muted { color: #6b7280; font-size: 10px; }
    h2 { font-size: 13px; color: #111111; margin: 18px 0 8px; border-right: 4px solid #111111; padding-right: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f2f2f0; color: #111111; text-align: right; padding: 7px; font-size: 10px; border-bottom: 1px solid #e5e5e5; }
    td { padding: 7px; border-bottom: 1px solid #f3f4f6; font-size: 10px; }
    .cards td { width: 25%; padding: 4px; border: none; }
    .card { background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; padding: 10px; }
    .card .lbl { color: #6b7280; font-size: 9px; }
    .card .val { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }
    .income { color: #16a34a; }
    .expense { color: #dc2626; }
    .foot { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>

<div class="head">
    <table style="border:none;"><tr>
        <td style="border:none; width:60%;">
            <div class="brand">{{ $business['name'] ?? 'Abad POS' }}</div>
            <div class="muted">{{ $business['type'] ?? '' }} — {{ $business['city'] ?? '' }}</div>
        </td>
        <td style="border:none; text-align:left;">
            <div style="font-size:15px; font-weight:bold;">{{ __('التقرير المالي') }}</div>
            <div class="muted">{{ $branch }}</div>
            <div class="muted">{{ __('الفترة') }}: {{ $rangeLabel }}</div>
            <div class="muted">{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
        </td>
    </tr></table>
</div>

<h2>{{ __('المؤشرات المالية') }}</h2>
<table class="cards">
    <tr>
        @foreach ($stats as $i => $s)
            <td>
                <div class="card">
                    <div class="lbl">{{ __($s['label']) }}</div>
                    <div class="val">{{ $s['value'] }}</div>
                </div>
            </td>
            @if (($i + 1) % 4 === 0 && ! $loop->last)</tr><tr>@endif
        @endforeach
    </tr>
</table>

<h2>{{ __('وسائل الدفع') }}</h2>
<table>
    <tr><th>{{ __('الوسيلة') }}</th><th>{{ __('الإجمالي') }}</th><th>{{ __('عدد العمليات') }}</th></tr>
    @foreach ($payments as $m)
        <tr>
            <td>{{ __($m['name']) }}</td>
            <td>{{ number_format($m['total'], 3) }} {{ __('ر.ع') }}</td>
            <td>{{ $m['count'] }}</td>
        </tr>
    @endforeach
</table>

<h2>{{ __('المعاملات المالية') }} ({{ count($transactions) }})</h2>
<table>
    <tr>
        <th>{{ __('المرجع') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th>
        <th>{{ __('الوسيلة') }}</th><th>{{ __('النوع') }}</th><th>{{ __('المبلغ') }}</th>
    </tr>
    @foreach ($transactions as $t)
        <tr>
            <td>{{ $t['id'] }}</td>
            <td>{{ $t['date'] }}</td>
            <td>{{ $t['description'] }}</td>
            <td>{{ __($t['method']) }}</td>
            {{-- النوع كما حدث لا كاتّجاهٍ وحده: «تحويل» و«سحب المالك» و«دخل آخر» --}}
            <td class="{{ $t['type'] === 'دخل' ? 'income' : ($t['type'] === 'مصروف' ? 'expense' : '') }}">{{ $t['kind_label'] ?? __($t['type']) }}</td>
            <td class="{{ $t['type'] === 'دخل' ? 'income' : ($t['type'] === 'مصروف' ? 'expense' : '') }}">
                {{ $t['type'] === 'دخل' ? '+' : ($t['type'] === 'مصروف' ? '−' : '') }}{{ number_format(abs($t['amount']), 3) }} {{ __('ر.ع') }}
            </td>
        </tr>
    @endforeach
</table>

@php
    /*
     * التحويل بين الصندوق والبنك ليس دخلًا ولا مصروفًا.
     *
     * كان المجموع الثاني يجمع «كلّ ما ليس دخلًا»، فيقع فيه التحويل: مالٌ
     * انتقل من جيبٍ إلى جيب يُقرأ خروجًا، ويُنقص «الصافي» بمبلغٍ لم يخرج.
     */
    $totalIn = collect($transactions)->where('type', 'دخل')->sum(fn ($t) => abs($t['amount']));
    $totalOut = collect($transactions)->where('type', 'مصروف')->sum(fn ($t) => abs($t['amount']));
@endphp
<table style="margin-top:10px;">
    <tr>
        <th>{{ __('إجمالي الدخل') }}</th>
        <th>{{ __('إجمالي المصروفات') }}</th>
        <th>{{ __('الصافي') }}</th>
    </tr>
    <tr>
        <td class="income">{{ number_format($totalIn, 3) }} {{ __('ر.ع') }}</td>
        <td class="expense">{{ number_format($totalOut, 3) }} {{ __('ر.ع') }}</td>
        <td style="font-weight:bold;">{{ number_format($totalIn - $totalOut, 3) }} {{ __('ر.ع') }}</td>
    </tr>
</table>

<div class="foot">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>

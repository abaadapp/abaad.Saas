@extends('pdf.layout')

@section('title', __('التقرير المالي'))

@section('meta')
    <div>{{ $branch }}</div>
    <div>{{ __('الفترة') }}: {{ $rangeLabel }}</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ $generatedAt }}</div>
@endsection

@section('body')
    <h2>{{ __('المؤشرات المالية') }}</h2>
    <table class="cards">
        <tr>
            @foreach ($stats as $i => $s)
                <td>
                    
                        <div class="lbl">{{ __($s['label']) }}</div>
                        <div class="val">{{ $s['value'] }}</div>
                    </td>
                @if (($i + 1) % 4 === 0 && ! $loop->last)</tr><tr>@endif
            @endforeach
        </tr>
    </table>

    <h2>{{ __('وسائل الدفع') }}</h2>
    <table class="grid">
        <tr><th>{{ __('الوسيلة') }}</th><th>{{ __('الإجمالي') }}</th><th>{{ __('عدد العمليات') }}</th></tr>
        @foreach ($payments as $m)
            <tr>
                <td>{{ __($m['name']) }}</td>
                <td>{{ \App\Support\Demo::moneyBase($m['total']) }}</td>
                <td>{{ $m['count'] }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('المعاملات المالية') }} ({{ count($transactions) }})</h2>
    <table class="grid">
        <tr>
            <th>{{ __('المرجع') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th>
            <th>{{ __('الوسيلة') }}</th><th>{{ __('النوع') }}</th><th>{{ __('المبلغ') }}</th>
            <th>{{ __('الحالة') }}</th>
        </tr>
        @foreach ($transactions as $t)
            <tr>
                <td>{{ $t['id'] }}</td>
                <td>{{ $t['date'] }}</td>
                <td>{{ $t['description'] }}</td>
                <td>{{ __($t['method']) }}</td>
                <td class="{{ $t['type'] === 'دخل' ? 'income' : 'expense' }}">{{ __($t['type']) }}</td>
                <td class="{{ $t['cancelled'] ? '' : ($t['type'] === 'دخل' ? 'income' : 'expense') }}"
                    @if ($t['cancelled']) style="text-decoration:line-through;color:#9ca3af;" @endif>
                    {{ $t['cancelled'] ? '' : ($t['type'] === 'دخل' ? '+' : '−') }}{{ \App\Support\Demo::moneyBase(abs($t['amount'])) }}
                </td>
                {{-- الملغاة تُوسم ولا تُحذف: خرجت من المجموع وبقيت في السجلّ --}}
                <td>{{ $t['cancelled'] ? __('ملغاة') : '—' }}</td>
            </tr>
        @endforeach
    </table>

    @php
        /*
         * والمجموعُ يستثني الملغاة — كما تستثنيها المؤشّرات في أعلى الورقة.
         *
         * كان يجمع كلَّ صفٍّ في الجدول، فتخرج ورقةٌ واحدة برقمين: مؤشّرٌ
         * فوق يقول «الدخل ١٠٠» ومجموعٌ تحت يقول «١٠٠٠». ورقةٌ تناقض نفسها
         * تُطبع وتُرسَل إلى المحاسب.
         */
        $live = collect($transactions)->where('cancelled', false);
        $totalIn = $live->where('type', 'دخل')->sum(fn ($t) => abs($t['amount']));
        $totalOut = $live->where('type', '!=', 'دخل')->sum(fn ($t) => abs($t['amount']));
    @endphp
    <table style="margin-top:10px;">
        <tr>
            <th>{{ __('إجمالي الدخل') }}</th>
            <th>{{ __('إجمالي المصروفات') }}</th>
            <th>{{ __('الصافي') }}</th>
        </tr>
        <tr>
            <td class="income">{{ \App\Support\Demo::moneyBase($totalIn) }}</td>
            <td class="expense">{{ \App\Support\Demo::moneyBase($totalOut) }}</td>
            <td style="font-weight:bold;">{{ \App\Support\Demo::moneyBase($totalIn - $totalOut) }}</td>
        </tr>
    </table>
@endsection

@section('foot')
    <div class="c">{{ __('تم إنشاء هذا التقرير آليًا من نظام Abad POS') }} — {{ $generatedAt }}</div>
@endsection

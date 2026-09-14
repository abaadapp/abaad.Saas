{{--
    إشعارٌ دائن — ورقةُ ما رُدّ أو خُصم من فاتورةٍ صدرت.

    ═══ ولا يُعيد كتابة الفاتورة ═══

    فاتورةٌ بمئةٍ سُدِّد منها ثلاثون ورُدّ بعشرين: الباقي خمسون. ولو خُفّض
    إجماليُّ الفاتورة إلى ثمانين لقالت الورقةُ التي في يد الزبون غيرَ ما
    يقوله النظام — انظر `CustomerInvoices::creditNote`.

    فالورقةُ هنا تسمّي **الفاتورة الأصليّة** في سطور التعريف: من يقرأ الإشعار
    يقرؤه مقابل ورقةٍ أخرى بيده، ورقمٌ لا يقابله رقمٌ لا يُخصم من شيء.

    ═══ ولا جدولَ بنودٍ عليه ═══

    `customer_credit_notes` صفٌّ بمبلغٍ وضريبته وسببٍ: لا بنودَ فيه. وجدولٌ
    يُخترع له يقول للعميل إنّ صنفًا بعينه رُدّ — ولا شيءَ في النظام يقول ذلك.

    و`amount` شاملٌ للضريبة، و`tax_amount` نصيبُها منه: فالصافي فرقُهما،
    ويُحسب في `DocumentPaper::forCreditNote` لا هنا.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    $vatNumber = $show('show_vat_no') ? ($vatNumber ?? '') : '';
    /* والمتجرُ هو مُصدِرُ الإشعار — انظر §٨ في المواصفة */
    $issuerCap = 'المُصدِر';
@endphp

@section('type', 'إشعار دائن')
@section('number', $doc['number'])

@section('parties')
    @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
@endsection

@php
    $metaCells = array_merge([['label' => 'رقم الإشعار', 'value' => $doc['number']]], $doc['meta'] ?? []);
@endphp

@section('figure')
    {{-- ورقمُ الإشعار الأهمّ: كم يُنقَص من ذمّة العميل — انظر §٢٥ في المواصفة --}}
    @include('documents.v1.partials.figure', [
        'label' => 'إجمالي الإشعار',
        'value' => collect($doc['totals'])->firstWhere('grand', true)['value'] ?? '',
        'status' => $doc['status'] ?? '',
    ])
@endsection

@section('body')
    @include('documents.v1.partials.totals', ['totals' => $doc['totals']])

    @include('documents.v1.partials.close', [
        'panels' => [
            ['cap' => 'عن هذه الورقة', 'text' => __('يُنقص هذا المبلغُ ما على الجهة من الفاتورة المذكورة أعلاه.')],
            $show('show_notes') ? ['cap' => 'سبب الإشعار', 'text' => $doc['notes']] : [],
        ],
    ])
@endsection

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
            <div>{{ $l }}</div>
        @endforeach
    @endsection
@endif

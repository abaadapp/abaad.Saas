{{--
    فاتورةُ المورّد — سندُ ما على المتجر لمورّده.

    ═══ وما يميّزها عن أمر الشراء ═══

    أمرُ الشراء يقول «جهّز لي هذا»، وهذه تقول «هذا ما عليّ لك». فالسؤالُ
    الأوّل فيها مبلغٌ وتاريخُ استحقاق، لا أصنافٌ وكميّات.

    ═══ ولا جدولَ أصنافٍ عليها — وهو قرارُ صدقٍ لا نقص ═══

    `supplier_invoices` صفٌّ بمبلغٍ ومرجعٍ وتاريخين: لا بنودَ فيه. والبضاعةُ
    في أمر الشراء وسندات الاستلام، ولكلٍّ ورقتُه.

    وجرُّ بنود أمر الشراء إلى هنا يُخرج ورقةً تكذب: المطابقةُ قد تقول إنّ
    إجماليَّ السند يخالف إجماليَّ الأمر — وذاك سببُ وجود `SupplierInvoices::match`
    أصلًا — فتُطبع أصنافٌ مجموعُها غيرُ المبلغ المكتوب أسفلها.

    فما عليها ما في الصفّ: الطرفان، والمرجع، والتاريخان، وأمرُ الشراء الذي
    تقابله، والمبالغ وما سُدِّد منها.

    ولا رمزَ عليها: تحمل أسعارَ شراء المتجر، ورابطٌ عامٌّ لا يحرسه إلّا كونُه
    غيرَ مخمَّن يضع هامشَ الربح خلف قصاصةٍ تُصوَّر بهاتف — كأمر الشراء.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    $vatNumber = $show('show_vat_no') ? ($vatNumber ?? '') : '';
    /* والمتجرُ هنا مشترٍ لا بائع: ورقةٌ تقول «البائع» فوق اسمنا تقلب الطرفين */
    $issuerCap = 'المشتري';
@endphp

@section('type', 'فاتورة المورّد')
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_supplier'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = array_merge(
        [['label' => 'رقم فاتورة المورّد', 'value' => $doc['number']]],
        $show('show_datetime') ? ($doc['meta'] ?? []) : array_values(array_filter(
            $doc['meta'] ?? [],
            fn ($c) => ! in_array($c['label'] ?? '', ['تاريخ الإصدار', 'تاريخ الاستحقاق'], true),
        )),
    );
@endphp

@section('figure')
    {{-- وما يُبحث عنه في فاتورة مورّد: كم بقي عليها، فإن سُدِّدت فإجماليُّها --}}
    @php($due = collect($doc['totals'])->firstWhere('due', true))
    @php($grand = collect($doc['totals'])->firstWhere('grand', true))
    @include('documents.v1.partials.figure', [
        'label' => $due ? 'الرصيد المستحق' : ($grand['label'] ?? 'الإجمالي'),
        'value' => ($due ?: $grand)['value'] ?? '',
        'status' => $doc['status'] ?? '',
    ])
@endsection

@section('body')
    @include('documents.v1.partials.totals', ['totals' => $doc['totals']])

    @include('documents.v1.partials.close', [
        'panels' => [
            ['cap' => 'عن هذه الورقة', 'text' => __('سجلُّ المتجر لهذه الفاتورة، لا أصلَها عند المورّد — وبضاعتُها في أمر الشراء وسندات الاستلام.')],
            $show('show_notes') ? ['cap' => 'ملاحظات', 'text' => $doc['notes']] : [],
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

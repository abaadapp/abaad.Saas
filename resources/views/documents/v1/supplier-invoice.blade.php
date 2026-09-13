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
    $numberLabel = __('رقم فاتورة المورّد');
    /* والمتجرُ هنا مشترٍ لا بائع: ورقةٌ تقول «البائع» فوق اسمنا تقلب الطرفين */
    $sellerCap = __('المشتري');
@endphp

@section('type', __('فاتورة مورّد'))
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_supplier'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = $show('show_datetime') ? ($doc['meta'] ?? []) : array_values(array_filter(
        $doc['meta'] ?? [],
        fn ($c) => ! in_array($c['label'] ?? '', [__('تاريخ الإصدار'), __('تاريخ الاستحقاق')], true),
    ));
@endphp

@section('body')
    @include('documents.v1.partials.totals', [
        'totals' => $doc['totals'],
        'aside' => __('سجلُّ المتجر لهذه الفاتورة، لا أصلَها عند المورّد — وبضاعتُها في أمر الشراء وسندات الاستلام.'),
    ])

    @if ($show('show_notes') && trim((string) $doc['notes']) !== '')
        <div class="panel sm">
            <div class="eyebrow">{{ __('ملاحظات') }}</div>
            {{ $doc['notes'] }}
        </div>
    @endif
@endsection

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
            <div>{{ $l }}</div>
        @endforeach
    @endsection
@endif

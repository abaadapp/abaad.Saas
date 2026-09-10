{{--
    أمرُ شراء — يُرسَل إلى المورّد ليجهّز الشحنة.

    ═══ وما يميّزه عن الفاتورة ═══

    الفاتورةُ تقول «هذا ما بِعتُك، ادفع». وأمرُ الشراء يقول «هذا ما أطلب،
    جهّزه» — فالسؤالُ الأوّل فيه ليس المبلغَ بل **متى**. فتاريخُ الاستلام
    المتوقّع يقع في سطور التعريف لا في هامش، والمورّدُ يقرؤه قبل أن يصل
    إلى الجدول.

    والطرفانِ اثنان هنا لا واحد: من يطلب ومن يُطلَب منه. وأمرٌ يحمل اسمَ
    المورّد وحده لا يقول لمن تُشحن البضاعة إن كان للتاجر أكثرُ من فرع.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    $vatNumber = $show('show_vat_no') ? ($vatNumber ?? '') : '';
    $numberLabel = __('رقم الأمر');
@endphp

@section('type', __('أمر شراء'))
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_supplier'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = array_merge(
        $show('show_datetime') && filled($doc['date']) ? [['label' => __('تاريخ الأمر'), 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch', false) && filled($doc['branch']) ? [['label' => __('يُشحن إلى'), 'value' => $doc['branch']]] : [],
    );
@endphp

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => $showPrices,
        'itemsLabel' => __('الصنف المطلوب'),
    ])

    @if ($show('show_items_count'))
        <div class="sm muted" style="margin-bottom:8pt">
            {{ __('عدد الأصناف') }}: <span class="ltr">{{ count($doc['items']) }}</span>
        </div>
    @endif

    @if ($showPrices)
        @include('documents.v1.partials.totals', ['totals' => $doc['totals']])
    @endif

    @if ($show('show_notes') && trim((string) $doc['notes']) !== '')
        <div class="panel sm">
            <div class="eyebrow">{{ __('ملاحظات وشروط') }}</div>
            {{ $doc['notes'] }}
        </div>
    @endif

    {{--
        ولا رمزَ على هذه الورقة — وهو قرارُ مالكٍ لا سهو.

        أمرُ الشراء يمضي إلى المورّد وفيه **تكلفةُ البضاعة**. ورابطٌ عامٌّ
        لا يحرسه إلّا كونُه غير مخمَّن يضع هامشَ ربح التاجر خلف قصاصةِ ورقٍ
        تُصوَّر بهاتف. فالرمزُ لأوراق الزبون وحدها — انظر
        `App\Support\PublicDocument`، وهو لا يُبنى لهذا النوع أصلًا.
    --}}

    @if ($show('show_signature', false))
        <table class="sign sm">
            <tr>
                <td><div class="rule">{{ __('اعتماد الطلب') }}</div></td>
                <td><div class="rule">{{ __('إقرار المورّد') }}</div></td>
            </tr>
        </table>
    @endif
@endsection

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
            <div>{{ $l }}</div>
        @endforeach
    @endsection
@endif

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
    /*
        وكتلتُنا عنوانُها «المشتري» لا «البائع».

        الورقةُ تمضي إلى المورّد، والبائعُ فيها **هو** لا نحن. و«البائع»
        فوق عنوان المتجر ورقمِه الضريبيّ يقلب طرفَي الصفقة على من يقرأ —
        وهو خطأٌ يُرى في أوّل نظرة إلى ورقةٍ تُرسَل خارج المتجر.

        وهي القاعدةُ نفسُها في فاتورة المورّد: انظر `supplier-invoice`.
    */
    $sellerCap = __('المشتري');
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

    {{--
        ولا رمزَ على هذه الورقة — وهو قرارُ مالكٍ لا سهو.

        أمرُ الشراء يمضي إلى المورّد وفيه **تكلفةُ البضاعة**. ورابطٌ عامٌّ
        لا يحرسه إلّا كونُه غير مخمَّن يضع هامشَ ربح التاجر خلف قصاصةِ ورقٍ
        تُصوَّر بهاتف. فالرمزُ لأوراق الزبون وحدها — انظر
        `App\Support\PublicDocument`، وهو لا يُبنى لهذا النوع أصلًا.
    --}}
    @include('documents.v1.partials.totals', [
        'totals' => $showPrices ? $doc['totals'] : [],
        'aside' => $show('show_items_count')
            ? __('عدد الأصناف').': '.count($doc['items'])
            : null,
        'panels' => [
            $show('show_notes') ? ['cap' => __('ملاحظات وشروط'), 'text' => $doc['notes']] : [],
        ],
    ])

    @if ($show('show_signature', false))
        <table class="sign sm">
            <tr>
                <td class="space" colspan="3"></td>
            </tr>
            <tr>
                <td class="box">{{ __('اعتماد الطلب') }}</td>
                <td class="gap"></td>
                <td class="box">{{ __('إقرار المورّد') }}</td>
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

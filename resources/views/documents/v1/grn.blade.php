{{--
    سندُ استلام بضاعة — يُوقَّع عند باب المخزن حين تصل الشحنة.

    ═══ وعمودان للكميّة لا واحد ═══

    السؤالُ الذي يُجيب عليه هذا السند: **هل وصل ما طُلب؟** وعمودٌ واحدٌ
    يقول «المستلمة: ٨» لا يقول شيئًا بلا «المطلوبة: ١٠» بجانبه. والفرقُ
    بينهما هو ما يُحتجّ به على المورّد، وهو ما يُبنى عليه النقصُ في
    المخزون — فورقةٌ تحمل رقمًا واحدًا تجعل من يراجعها يفتح أمر الشراء
    ليقارن.

    والعمودُ الثاني يظهر إن كان للسند أمرُ شراءٍ مرجعيّ وحده: استلامٌ بلا
    أمرٍ سابق ليس له «مطلوبة» تُقارن.

    والأسعارُ مطفأةٌ افتراضًا: من يستلم عند الباب يعدّ الصناديق ولا يحتاج
    تكلفتَها — وقد لا يجوز أن يراها.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    $vatNumber = $show('show_vat_no', false) ? ($vatNumber ?? '') : '';
    /*
        والمتجرُ هنا **جهةُ الاستلام** لا «المستلِم».

        «المستلِم» حقلٌ قائمٌ في وسط الورقة اسمُه موظّفُ الاستلام. فلو
        حملت كتلتُنا العنوانَ نفسَه لظهرت التسميةُ مرّتين على ورقةٍ واحدة
        بمعنيين — جهةً مرّةً وشخصًا مرّة. والمواصفة تسمّيها «Receiving
        Location».
    */
    $issuerCap = 'جهة الاستلام';
    /* والعمودُ يظهر إن حمل صنفٌ واحدٌ كميّةً مطلوبة — لا إن كان النوع grn */
    $hasOrdered = collect($doc['items'])->contains(fn ($i) => filled($i['ordered'] ?? null));
@endphp

@section('type', 'سند استلام بضاعة')
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_supplier'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = array_merge(
        [['label' => 'رقم السند', 'value' => $doc['number']]],
        $show('show_datetime') && filled($doc['date']) ? [['label' => 'تاريخ الاستلام', 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch') && filled($doc['branch']) ? [['label' => 'المخزن / الفرع', 'value' => $doc['branch']]] : [],
        $show('show_employee') && filled($doc['employee']) ? [['label' => 'المستلِم', 'value' => $doc['employee']]] : [],
    );
@endphp

@section('figure')
    {{--
        وسندُ الاستلام ورقةُ بضاعةٍ لا ورقةُ مال.

        المواصفة صريحة: «ليس ضروريًا أن تعرض financial totals إذا كان
        domain document لا يحتاجها. لا تخترع بيانات مالية فقط لمطابقة
        Invoice». فإن أظهر التاجرُ الأسعارَ عليه فله رقمُه، وإلّا فالورقةُ
        تقول ما استُلم وكم — لا كم يساوي.
    --}}
    @if ($showPrices)
        @include('documents.v1.partials.figure', [
            'label' => 'الإجمالي',
            'value' => collect($doc['totals'])->firstWhere('grand', true)['value'] ?? '',
            'status' => $doc['status'] ?? '',
        ])
    @endif
@endsection

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => $showPrices,
        'showOrdered' => $hasOrdered,
        'itemsLabel' => 'الصنف',
    ])

    {{-- ولا رمزَ: يمضي إلى المورّد وفيه تكلفةُ البضاعة — كأمر الشراء --}}
    @include('documents.v1.partials.totals', ['totals' => $showPrices ? $doc['totals'] : []])

    @include('documents.v1.partials.close', [
        'panels' => [
            $show('show_notes') ? ['cap' => 'ملاحظات الاستلام', 'text' => $doc['notes']] : [],
        ],
    ])

    @if ($show('show_signature'))
        <table class="sign sm">
            <tr>
                <td class="space" colspan="3"></td>
            </tr>
            <tr>
                <td class="box">{{ __('توقيع المورّد / الناقل') }}</td>
                <td class="gap"></td>
                <td class="box">{{ __('توقيع المستلِم') }}</td>
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

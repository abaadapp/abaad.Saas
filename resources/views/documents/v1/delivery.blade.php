{{--
    سندُ تسليم — يمشي مع الشحنة ويُوقَّع عند الباب.

    ═══ وهو ليس فاتورة ═══

    يحمله سائقٌ إلى بيت المستلِم. فما فيه: **ما الذي يُسلَّم، وكم عددُه،
    ومن استلمه**. أمّا الأسعار فتُطفأ افتراضًا (`show_prices` = false في
    السجلّ) — وورقةٌ تُظهر للسائق ولمن في البيت تكلفةَ ما يحمل ليست ورقةَ
    تسليم.

    والتوقيعُ خانتان لا واحدة: ورقةٌ يوقّعها المستلِم وحده لا تُثبت من
    سلّم، وورقةٌ يوقّعها المسلِّم وحده لا تُثبت أنّها وصلت.

    والرمزُ يبقى: هذه ورقةُ زبونٍ، والمستلِمُ يمسحه ليرى ما وصله كاملًا.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    $vatNumber = $show('show_vat_no', false) ? ($vatNumber ?? '') : '';
    /* والمتجرُ هو المُرسِل على سند التسليم — انظر §٨ في المواصفة */
    $issuerCap = 'المُرسِل';
@endphp

@section('type', 'سند تسليم')
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_customer'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = array_merge(
        [['label' => 'رقم السند', 'value' => $doc['number']]],
        $show('show_datetime') && filled($doc['date']) ? [['label' => 'تاريخ التسليم', 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch') && filled($doc['branch']) ? [['label' => 'الفرع', 'value' => $doc['branch']]] : [],
        $show('show_employee') && filled($doc['employee']) ? [['label' => 'المسؤول', 'value' => $doc['employee']]] : [],
    );
@endphp

@section('figure')
    {{--
        وسندُ التسليم بلا رقمٍ كبيرٍ حين لا أسعارَ عليه.

        ورقةٌ يحملها سائقٌ لا مبلغَ فيها أصلًا — والمواصفة: «لا تخترع
        بيانات مالية فقط لمطابقة Invoice». فإن كانت الأسعارُ عليه ظاهرةً
        فقيمتُه ما يُسلَّم، وإلّا فلا شيء.
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
        'itemsLabel' => 'الصنف المُسلَّم',
    ])

    @include('documents.v1.partials.totals', ['totals' => $showPrices ? $doc['totals'] : []])

    @include('documents.v1.partials.close', [
        'panels' => [
            $show('show_notes') ? ['cap' => 'ملاحظات التسليم', 'text' => $doc['notes']] : [],
        ],
        'paperUrl' => $paperUrl ?? '',
    ])

    @if ($show('show_signature'))
        <table class="sign sm">
            <tr>
                <td class="space" colspan="3"></td>
            </tr>
            <tr>
                <td class="box">{{ __('توقيع المسلِّم') }}</td>
                <td class="gap"></td>
                <td class="box">{{ __('توقيع المستلِم — الاسم والتاريخ') }}</td>
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

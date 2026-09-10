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
    $numberLabel = __('رقم السند');
@endphp

@section('type', __('سند تسليم'))
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_customer'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = array_merge(
        $show('show_datetime') && filled($doc['date']) ? [['label' => __('تاريخ التسليم'), 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch') && filled($doc['branch']) ? [['label' => __('الفرع'), 'value' => $doc['branch']]] : [],
        $show('show_employee') && filled($doc['employee']) ? [['label' => __('المسؤول'), 'value' => $doc['employee']]] : [],
    );
@endphp

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => $showPrices,
        'itemsLabel' => __('الصنف المُسلَّم'),
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
            <div class="eyebrow">{{ __('ملاحظات التسليم') }}</div>
            {{ $doc['notes'] }}
        </div>
    @endif

    @if ($show('show_signature'))
        <table class="sign sm">
            <tr>
                <td><div class="rule">{{ __('توقيع المسلِّم') }}</div></td>
                <td><div class="rule">{{ __('توقيع المستلِم — الاسم والتاريخ') }}</div></td>
            </tr>
        </table>
    @endif

    @include('documents.v1.partials.qr', [
        'eInvoice' => '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => '',
        'size' => 1.0,
    ])
@endsection

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
            <div>{{ $l }}</div>
        @endforeach
    @endsection
@endif

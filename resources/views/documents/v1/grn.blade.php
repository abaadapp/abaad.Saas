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
    $numberLabel = __('رقم السند');
    /* والعمودُ يظهر إن حمل صنفٌ واحدٌ كميّةً مطلوبة — لا إن كان النوع grn */
    $hasOrdered = collect($doc['items'])->contains(fn ($i) => filled($i['ordered'] ?? null));
@endphp

@section('type', __('سند استلام بضاعة'))
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_supplier'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    $metaCells = array_merge(
        $show('show_datetime') && filled($doc['date']) ? [['label' => __('تاريخ الاستلام'), 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch') && filled($doc['branch']) ? [['label' => __('المخزن / الفرع'), 'value' => $doc['branch']]] : [],
        $show('show_employee') && filled($doc['employee']) ? [['label' => __('المستلِم'), 'value' => $doc['employee']]] : [],
    );
@endphp

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => $showPrices,
        'showOrdered' => $hasOrdered,
        'itemsLabel' => __('الصنف'),
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
            <div class="eyebrow">{{ __('ملاحظات الاستلام') }}</div>
            {{ $doc['notes'] }}
        </div>
    @endif

    {{-- ولا رمزَ: يمضي إلى المورّد وفيه تكلفةُ البضاعة — كأمر الشراء --}}

    @if ($show('show_signature'))
        <table class="sign sm">
            <tr>
                <td><div class="rule">{{ __('توقيع المورّد / الناقل') }}</div></td>
                <td><div class="rule">{{ __('توقيع المستلِم') }}</div></td>
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

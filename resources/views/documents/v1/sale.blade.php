{{--
    فاتورةُ البيع — أكملُ أوراق النظام.

    وتُسمّى «فاتورة ضريبية» بشرطٍ واحد: أن يكون للمتجر رقمٌ ضريبيّ. وبلا
    رقمٍ هي «فاتورة» — وورقةٌ تسمّي نفسَها ضريبيّةً بلا رقمِ تسجيلٍ تُردّ
    على صاحبها من أوّل جهةٍ تراجعها، ويظنّ هو نفسَه ممتثلًا حتى تُردّ.

    والسؤالان لا يُخلطان: `$vat` هل للمتجر رقمٌ أصلًا — وبه تُسمّى الورقة؛
    و`$showVatNumber` هل يريد التاجر طباعتَه في الترويسة. وخلطُهما كان
    يجعل إطفاءَ المقبض يُسقط اسمَ الورقة معه.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $vat = trim((string) ($vatNumber ?? ''));
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    /* والرقمُ في الترويسة بمقبضه — انظر رأس الملفّ */
    $vatNumber = $show('show_vat_no', false) ? $vat : '';
    $numberLabel = __('رقم الفاتورة');
@endphp

@section('type', $vat !== '' ? __('فاتورة ضريبية') : __('فاتورة'))
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_customer'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@section('meta')
    @if ($show('show_datetime') && filled($doc['date']))
        <tr>
            <td class="k">{{ __('التاريخ') }}</td>
            <td><span class="ltr">{{ $doc['date'] }}</span></td>
        </tr>
    @endif
    @foreach ($doc['meta'] ?? [] as $row)
        <tr>
            <td class="k">{{ $row['label'] }}</td>
            <td>{{ $row['value'] }}</td>
        </tr>
    @endforeach
    @if ($show('show_branch') && filled($doc['branch']))
        <tr><td class="k">{{ __('الفرع') }}</td><td>{{ $doc['branch'] }}</td></tr>
    @endif
    @if ($show('show_employee') && filled($doc['employee']))
        <tr><td class="k">{{ __('الموظف') }}</td><td>{{ $doc['employee'] }}</td></tr>
    @endif
@endsection

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => true,
        'itemsLabel' => __('الصنف'),
    ])

    @if ($show('show_items_count'))
        <div class="sm muted" style="margin-bottom:8pt">
            {{ __('عدد الأصناف') }}: <span class="ltr">{{ count($doc['items']) }}</span>
        </div>
    @endif

    @include('documents.v1.partials.totals', ['totals' => $doc['totals']])

    @if ($show('show_notes', false) && trim((string) $doc['notes']) !== '')
        <div class="panel sm">
            <div class="eyebrow">{{ __('ملاحظات') }}</div>
            {{ $doc['notes'] }}
        </div>
    @endif

    @include('documents.v1.partials.qr', [
        'eInvoice' => $show('show_qr') ? ($qr ?? '') : '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => $googleReview ?? '',
        'size' => 1.0,
    ])
@endsection

@if (trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        <div class="c">
            @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
                @php($clean = \App\Support\ReceiptTemplate::printableHtml($l))
                @if ($clean !== ''){!! $clean !!}@if (! $loop->last)<br>@endif @endif
            @endforeach
        </div>
    @endsection
@endif

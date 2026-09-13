{{--
    سندُ قبض — إقرارُ المتجر بأنّه استلم مالًا من عميل.

    ═══ وبنودُه فواتيرُ لا أصناف ═══

    من دفع بمئةٍ تُسدَّد بها ثلاثُ فواتير يريد أن يعرف أيُّها سُدِّدت وبكم.
    فجدولُ البنود هنا **توزيعُ المبلغ** — `customer_payment_allocations` —
    لا قائمةَ بضاعة: البضاعةُ على الفواتير نفسِها.

    وما لم يُوزَّع يُقال صراحةً في المجاميع: رصيدٌ عند المتجر لباقي فواتير
    صاحبه، لا مبلغٌ اختفى بين السطور.

    ═══ وخانةُ التوقيع فيه افتراضًا ═══

    هي ورقةٌ تُسلَّم لمن دفع، وسندُ قبضٍ بلا توقيعٍ لا يُثبت أنّ أحدًا استلم
    شيئًا — وهو الغرضُ الوحيد من وجوده.
--}}
@extends('documents.v1.layout')

@php
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $headerNote = trim((string) ($tpl['header'] ?? ''));
    $vatNumber = $show('show_vat_no') ? ($vatNumber ?? '') : '';
    $numberLabel = __('رقم السند');
    $sellerCap = __('المستلِم');
@endphp

@section('type', __('سند قبض'))
@section('number', $doc['number'])

@section('parties')
    @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
@endsection

@php
    $metaCells = $show('show_datetime') ? ($doc['meta'] ?? []) : array_values(array_filter(
        $doc['meta'] ?? [],
        fn ($c) => ($c['label'] ?? '') !== __('تاريخ القبض'),
    ));
@endphp

@section('body')
    {{--
        وجدولُ التوزيع عمودان لا خمسة.

        جزئيّةُ الأصناف المشتركة تحمل «الكمية» و«سعر الوحدة» — وهما سؤالان
        عن بضاعة. والصفُّ هنا **فاتورةٌ سُدِّد منها مبلغ**: «الكمية ١» و«سعر
        الوحدة ٢٥٠» حشوٌ يُقرأ خطأً، لا خبرٌ ناقص.

        والأصنافُ نفسُها على الفواتير المذكورة، ولكلٍّ ورقتُها.
    --}}
    @if (count($doc['items']) > 0)
        <table class="items">
            <thead>
                <tr>
                    <th style="width:6%" class="num">#</th>
                    <th>{{ __('سُدِّد من') }}</th>
                    <th style="width:30%" class="amt">{{ __('المبلغ') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($doc['items'] as $i => $item)
                    {{-- ولا زِبرةَ: شعرةٌ فاصلةٌ تكفي — انظر `partials/items` --}}
                    <tr>
                        <td class="num faint">{{ $i + 1 }}</td>
                        <td class="bidi item" dir="{{ \App\Support\Paper::dirOf($item['name']) }}">
                            {{ $item['name'] }}
                            @if (filled($item['note'] ?? null))
                                <div class="sm muted bidi">{{ $item['note'] }}</div>
                            @endif
                        </td>
                        {{-- والمبلغُ معزولٌ عن اتّجاه السطر: انظر `partials/items` --}}
                        <td class="amt line"><span dir="ltr">{{ $item['total'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('documents.v1.partials.totals', [
        'totals' => $doc['totals'],
        'aside' => count($doc['items']) === 0
            ? __('لم يُخصَّص هذا المبلغُ لفاتورةٍ بعد — وهو رصيدٌ للجهة عند المتجر.')
            : null,
        'panels' => [
            $show('show_notes') ? ['cap' => __('ملاحظات'), 'text' => $doc['notes']] : [],
        ],
    ])

    @if ($show('show_signature'))
        <table class="sign sm">
            <tr>
                <td class="space" colspan="3"></td>
            </tr>
            <tr>
                <td class="box">{{ __('توقيع الدافع') }}</td>
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

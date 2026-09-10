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
    $numberLabel = __('رقم الفاتورة');

    /*
     * ═══ ووجهُ الورقة الثالث: الفاتورة الضريبية ═══
     *
     * `sale` ثلاثُ أوراقٍ يحكمها قالبٌ واحد: الإيصالُ الحراريّ، وفاتورةُ
     * A4، والفاتورةُ الضريبية. والثالثةُ تفترق عن الثانية في قاعدةٍ واحدة
     * لا في شكل:
     *
     * **الرقمُ الضريبيّ فيها بلا مقبض.** ورقةٌ عنوانها «فاتورة ضريبية» بلا
     * رقم بائعها ليست فاتورةً ضريبية — تُردّ من أوّل جهةٍ تراجعها، ويظنّ
     * صاحبُها نفسَه ممتثلًا حتى تُردّ. فلا يُخفى بـ«إظهار الرقم الضريبي»
     * كما يُخفى في العادية: هو سببُ وجود الورقة.
     *
     * وقالبان لهما ترويسةٌ وتذييلٌ وجدولٌ واحد كانا يفترقان عند أوّل
     * تعديل — يُصلَح سطرٌ في إحداهما ويبقى معطوبًا في الأخرى، ولطلبٍ واحد
     * تخرج ورقتان لا يجمعهما شكل.
     */
    $taxInvoice = (bool) ($taxInvoice ?? false);
    $vatNumber = ($taxInvoice || $show('show_vat_no', false)) ? $vat : '';
@endphp

@section('type', $taxInvoice || $vat !== '' ? __('فاتورة ضريبية') : __('فاتورة'))
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_customer'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    /* شريطُ التعريف — أعمدةٌ لا قائمة. انظر `partials/meta` */
    $metaCells = array_merge(
        $show('show_datetime') && filled($doc['date']) ? [['label' => __('التاريخ'), 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch') && filled($doc['branch']) ? [['label' => __('الفرع'), 'value' => $doc['branch']]] : [],
        $show('show_employee') && filled($doc['employee']) ? [['label' => __('الموظف'), 'value' => $doc['employee']]] : [],
    );
@endphp

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => true,
        'itemsLabel' => __('الصنف'),
    ])

    {{--
        وعددُ الأصناف يقع بجانب الإجماليّات لا فوقها.

        وكان سطرًا يطفو وحده بين الجدول والمجاميع، في شريطٍ خالٍ بعرض
        الورقة كلِّها. والإجماليّاتُ تشغل طرفًا واحدًا وتترك الطرفَ المقابل
        فارغًا — فهو مكانُه: يملأ الفراغَ ويُقرأ مع ما يخصّه.
    --}}
    @include('documents.v1.partials.totals', [
        'totals' => $doc['totals'],
        'aside' => $show('show_items_count')
            ? __('عدد الأصناف').': '.count($doc['items'])
            : null,
    ])

    @if ($show('show_notes', false) && trim((string) $doc['notes']) !== '')
        <div class="panel sm">
            <div class="eyebrow">{{ __('ملاحظات') }}</div>
            <div class="bidi" dir="{{ \App\Support\Paper::dirOf($doc['notes']) }}">{{ $doc['notes'] }}</div>
        </div>
    @endif

    @include('documents.v1.partials.qr', [
        'eInvoice' => $show('show_qr') ? ($qr ?? '') : '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => $googleReview ?? '',
        'size' => 1.0,
    ])
@endsection

@if ($taxInvoice || trim((string) ($tpl['footer'] ?? '')) !== '')
    @section('foot')
        {{--
            وسطرٌ يقول إنّها صدرت آليًّا ومتى — تطلبه الجهاتُ التي تراجع،
            ويميّز النسخةَ الأصلية من صورةٍ أُعيدت طباعتُها بعد شهر.
        --}}
        @if ($taxInvoice)
            <div class="c xs faint" style="margin-bottom:3pt">
                {{ __('فاتورة ضريبية صادرة آليًا عبر نظام أبعاد') }}
                — <span class="ltr">{{ $generatedAt ?? now()->format('Y-m-d H:i') }}</span>
                {{--
                    والعملةُ تُسمّى من عملة المتجر لا مثبَّتةً.

                    كان السطرُ يقول «القيم بالريال العماني» على ورقة كلّ
                    تاجر، ومنهم من يبيع بالدرهم — فتقول الورقةُ الضريبيّة
                    عملةً غير التي في جدولها. انظر `Support\Money`.
                --}}
                — {{ __('القيم بعملة') }} <span class="ltr">{{ \App\Support\Money::of($business['id'] ?? ($business->id ?? 0))['code'] }}</span>
            </div>
        @endif
        <div class="c">
            @foreach (preg_split('/\r\n|\r|\n/', $tpl['footer']) as $l)
                @php($clean = \App\Support\ReceiptTemplate::printableHtml($l))
                @if ($clean !== ''){!! $clean !!}@if (! $loop->last)<br>@endif @endif
            @endforeach
        </div>
    @endsection
@endif

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
    /* والمتجرُ هو البائع على ورقة البيع — انظر §٨ في المواصفة */
    $issuerCap = 'البائع';

    $taxInvoice = (bool) ($taxInvoice ?? false);
    $vatNumber = ($taxInvoice || $show('show_vat_no', false)) ? $vat : '';
@endphp

{{-- والعنوانُ **مفتاحٌ** لا ترجمة: القالبُ يطبعه في لغتين — انظر `Paper::pair` --}}
@section('type', $taxInvoice || $vat !== '' ? 'فاتورة ضريبية' : 'فاتورة')
@section('number', $doc['number'])

@section('parties')
    @if ($show('show_customer'))
        @include('documents.v1.partials.parties', ['parties' => $doc['parties']])
    @endif
@endsection

@php
    /* شريطُ التعريف — أعمدةٌ لا قائمة. انظر `partials/meta` */
    $metaCells = array_merge(
        [['label' => 'رقم الفاتورة', 'value' => $doc['number']]],
        $show('show_datetime') && filled($doc['date']) ? [['label' => 'التاريخ', 'value' => $doc['date']]] : [],
        $doc['meta'] ?? [],
        $show('show_branch') && filled($doc['branch']) ? [['label' => 'الفرع', 'value' => $doc['branch']]] : [],
        $show('show_employee') && filled($doc['employee']) ? [['label' => 'الموظف', 'value' => $doc['employee']]] : [],
    );

    /*
        ═══ والرقمُ الأهمّ: ما بقي، لا ما بيع ═══

        فاتورةٌ سُدِّدت كاملةً رقمُها الأهمّ إجماليُّها، وفاتورةٌ عليها باقٍ
        رقمُها الأهمّ **الباقي** — هو ما يُبحث عنه ويُدفَع ويُطالَب به.
        و`due` علامةٌ يضعها `DocumentPaper` على سطر الباقي حين يكون.
    */
    $due = collect($doc['totals'])->firstWhere('due', true);
    $grand = collect($doc['totals'])->firstWhere('grand', true);
    $figure = $due ?: $grand;
@endphp

@section('figure')
    {{--
        والتسميةُ من موقع الرقم لا من سطره.

        سطرُ المجاميع اسمُه «الباقي» — وهو صحيحٌ في سلّمٍ يسبقه إجماليٌّ
        ومسدَّد. لكنّه وحدَه في صدر الورقة بلا ما قبله يُقرأ باقيَ ماذا.
        فيُسمّى بما هو: «الرصيد المستحق / Total due» — وهي تسميةُ المرجع
        نفسِها، وتسميةُ فاتورة العميل في هذا النظام.
    --}}
    @include('documents.v1.partials.figure', [
        'label' => $due ? 'الرصيد المستحق' : ($grand['label'] ?? 'الإجمالي'),
        'value' => $figure['value'] ?? '',
        'status' => $doc['status'] ?? '',
    ])
@endsection

@section('body')
    @include('documents.v1.partials.items', [
        'items' => $doc['items'],
        'showPrices' => true,
        'itemsLabel' => 'البيان',
    ])

    @include('documents.v1.partials.totals', ['totals' => $doc['totals']])

    @include('documents.v1.partials.close', [
        'panels' => [
            $show('show_notes', false) ? ['cap' => 'ملاحظات', 'text' => $doc['notes']] : [],
        ],
        'eInvoice' => $show('show_qr') ? ($qr ?? '') : '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => $googleReview ?? '',
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

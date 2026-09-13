{{--
    فاتورةُ العميل — الورقةُ التي تُرسَل إلى شركةٍ أو وزارة.

    ═══ وهي ليست فاتورةَ البيع بشكلٍ آخر ═══

    فاتورةُ البيع إيصالُ بيعةٍ تمّت عند الصندوق. وهذه **مطالبةٌ بمبلغ**:
    تُرسَل، ويُبنى عليها جدولُ صرفٍ في جهةٍ أخرى، وتُلاحَق حتى تُسدَّد.

    فما يميّزها ثلاثة، ولكلٍّ سببُه:

    • **الاستحقاقُ والشروط** — «صافي ٣٠» ليست زينة: هي ما يحتجّ به من
      يطالب، وما يبني عليه المحاسبُ في الجهة موعدَ الصرف. وورقةٌ تحمل
      تاريخَ استحقاقٍ بلا شرطٍ يفسّره تُقرأ تاريخًا اختاره كاتبُها.

    • **حقولُ الجهة** — رقمُ أمر الشراء ومركزُ التكلفة والقسم. بلا رقم أمر
      الشراء تُردّ الفاتورةُ من قسم الحسابات بلا أن تُقرأ أصلًا.

    • **تعليماتُ السداد** — من لا يعرف إلى أين يحوّل يؤجّل، وهذا وحده
      يؤخّر التحصيل أسابيع.

    ═══ ولا عنوانَ مبنًى فيها ═══

    شرطُ صاحب النظام. والعنوانُ يُمنع من مصدره لا بمقبضٍ هنا: هذه الورقةُ
    وحدها تُرسَم بـ`InvoiceBranding::paper` التي **لا مفتاحَ `address`
    فيها أصلًا**. ومقبضٌ يُخفي حقلًا موجودًا يُنسى فيُشعَل، وحقلٌ لا
    يُرسَل لا يُطبع أبدًا. ويحرسه `TheInvoiceCarriesTheShopsIdentityTest`.
--}}
@extends('documents.v1.layout')

@php
    /*
     * والصيغةُ من `Support\Money` لا من هنا.
     *
     * `$amount` رقمٌ بلا رمز — لخلايا الجدول، إذ يتكرّر الرمزُ فيها عشرين
     * مرّةً بلا فائدة. و`$money` رقمٌ برمزه — للإجمالي وللباقي وحدهما،
     * وهما ما تقع عليه العين.
     */
    $amount = fn ($v) => \App\Support\Money::amount((float) $v, $currency);
    $money = fn ($v) => \App\Support\Money::format((float) $v, $currency);
    $vat = trim((string) ($vatNumber ?? ''));
    $numberLabel = __('رقم الفاتورة');

    /*
        وحقولُ الجهة تُرشَّح قبل أن تُرسم.

        صفٌّ يُكتب بحقلين يُخرج خانةً فارغةً حين يُملأ أحدُهما، وصفًّا خاويًا
        حين لا يُملأ أيٌّ منهما. وورقةٌ فيها خاناتٌ خاوية تُقرأ نموذجًا لم
        يُكمَل. والشُّرَطُ «—» مكان الفارغ أسوأ: تقول للجهة إنّ للورقة قسمًا
        لم يُذكر — ولا قسمَ لها أصلًا.
    */
    $orgFields = array_values(array_filter([
        ['label' => __('رقم أمر الشراء (PO)'), 'value' => $invoice->po_number],
        ['label' => __('رقم العقد'), 'value' => $invoice->contract_number],
        ['label' => __('رقم المرجع'), 'value' => $invoice->external_reference],
        ['label' => __('القسم / الإدارة'), 'value' => $invoice->department],
        ['label' => __('مركز التكلفة'), 'value' => $invoice->cost_center],
        ['label' => __('موجه إلى / عناية'), 'value' => $invoice->attention_to],
    ], fn ($f) => filled($f['value'])));

    $terms = $invoice->payment_terms_days === null ? null
        : ((int) $invoice->payment_terms_days === 0
            ? __('مستحق فورًا')
            : __('صافي :n يومًا', ['n' => (int) $invoice->payment_terms_days]));

    $totals = [['label' => __('المجموع الفرعي'), 'value' => $amount($invoice->subtotal)]];

    if ((float) $invoice->discount_total > 0) {
        $totals[] = ['label' => __('الخصم'), 'value' => '− '.$amount($invoice->discount_total)];
    }

    if ((float) $invoice->tax_total > 0) {
        $totals[] = ['label' => __('ضريبة القيمة المضافة'), 'value' => $amount($invoice->tax_total)];
    }

    $totals[] = ['label' => __('الإجمالي'), 'value' => $money($invoice->total), 'grand' => true];
    $totals[] = ['label' => __('المسدَّد'), 'value' => $amount($paid)];
    $totals[] = ['label' => __('الباقي'), 'value' => $money($outstanding), 'due' => true];

    /*
        وهذه الورقةُ تُرسَم من الصفّ لا من `DocumentPaper` كأخواتها.

        فيُبنى لها `$doc` بما تقرؤه الجزئيّاتُ المشتركة: الخَتمُ يقرأ
        `status` والتذييلُ يقرأ `number`. وبدونه تخرج فاتورةٌ ملغاةٌ بلا
        شيءٍ عليها يقول ذلك — والإلغاءُ أهمُّ ما يُقرأ على مطالبةٍ بمبلغ.

        و«صادرة» لا تُختم: هي الحالُ الطبيعيّ لكلّ فاتورةٍ في يد جهةٍ
        تُطالَب، وختمُها على كلّ ورقةٍ ضجيجٌ لا خبر.
    */
    $doc = [
        'number' => $invoice->number ?: __('مسودة'),
        'status' => $invoice->status === \App\Models\CustomerInvoice::ISSUED ? '' : (string) $invoice->status,
    ];
@endphp

@section('type', $invoice->tax_total > 0 && $vat !== '' ? __('فاتورة ضريبية') : __('فاتورة'))
{{-- ورقمٌ لم يُقطع بعدُ يُقال «مسودّة» — لا سطرٌ ينتهي عند فراغ --}}
@section('number', $invoice->number ?: __('مسودة'))

@section('parties')
    @include('documents.v1.partials.parties', ['parties' => [[
        'cap' => __('فاتورة إلى'),
        'lines' => array_values(array_filter([
            $invoice->customer_name ?: null,
            filled($invoice->customer_tax_number) ? __('الرقم الضريبي').': '.$invoice->customer_tax_number : null,
            filled($invoice->customer_cr) ? __('السجل التجاري').': '.$invoice->customer_cr : null,
        ])),
    ]]])
@endsection

@php
    $metaCells = array_merge(
        [['label' => __('تاريخ الإصدار'), 'value' => optional($invoice->issued_at)->format('Y-m-d')]],
        $invoice->due_at ? [['label' => __('تاريخ الاستحقاق'), 'value' => $invoice->due_at->format('Y-m-d')]] : [],
        $terms !== null ? [['label' => __('شروط الدفع'), 'value' => $terms]] : [],
        $orgFields,
    );
@endphp

@section('body')
    <table class="items">
        <thead>
            <tr>
                <th style="width:6%" class="num">#</th>
                <th>{{ __('البيان') }}</th>
                <th style="width:11%" class="num">{{ __('الكمية') }}</th>
                <th style="width:17%" class="amt">{{ __('سعر الوحدة') }}</th>
                <th style="width:15%" class="amt">{{ __('الضريبة') }}</th>
                <th style="width:19%" class="amt">{{ __('الإجمالي') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->items as $i => $item)
                {{-- ولا زِبرةَ: شعرةٌ فاصلةٌ تكفي — انظر `partials/items` --}}
                <tr>
                    <td class="num faint">{{ $i + 1 }}</td>
                    {{-- والبيانُ يحمل اتّجاهَه: بندٌ إنجليزيٌّ في فاتورةٍ عربيّة لا تنقلب نقطتُه --}}
                    <td class="bidi item" dir="{{ \App\Support\Paper::dirOf($item->description) }}">{{ $item->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    {{-- والمبالغُ معزولةٌ عن اتّجاه السطر: انظر `partials/items` --}}
                    <td class="amt muted"><span dir="ltr">{{ $amount($item->unit_price) }}</span></td>
                    <td class="amt muted"><span dir="ltr">{{ $amount($item->tax_amount) }}</span></td>
                    <td class="amt line"><span dir="ltr">{{ $amount($item->line_total) }}</span></td>
                </tr>
            @empty
                <tr><td class="empty" colspan="6">{{ __('لا بنود على هذه الفاتورة') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    {{--
        وملاحظةُ العميل تُطبع إن أرادها صاحبُ المحلّ — من «قوالب الأوراق».
        ولا تُخلط بالملاحظات الداخليّة: تلك عمودٌ آخر لا يبلغ ورقةَ العميل
        بحال، ولا مفتاحَ يُظهرها.

        وتعليماتُ السداد كتلةٌ ثانيةٌ في العمود نفسِه: هي ما يُقرأ مقابل
        المبلغ المطلوب — «ادفع هذا، إلى هنا» — فمكانُها بإزائه لا أسفلَه.
    --}}
    @include('documents.v1.partials.totals', [
        'totals' => $totals,
        'panels' => [
            ($invoice->notes && ($showNotes ?? true)) ? ['cap' => __('ملاحظات'), 'text' => $invoice->notes] : [],
            $bank ? [
                'cap' => __('تعليمات السداد'),
                'rows' => [
                    $bank->bank_name ? ['label' => __('البنك'), 'value' => $bank->bank_name] : [],
                    $bank->account_name ? ['label' => __('اسم الحساب'), 'value' => $bank->account_name] : [],
                    $bank->iban ? ['label' => __('الآيبان'), 'value' => $bank->iban, 'ltr' => true] : [],
                ],
            ] : [],
        ],
        'paperUrl' => $paperUrl ?? '',
        /* وهذه وحدها تفتح صفحةَ تحقّقٍ لا نسخةً كاملة — انظر `public/verify` */
        'verify' => true,
    ])
@endsection

@if (trim((string) ($footerNote ?? '')) !== '')
    @section('foot')
        {{ $footerNote }}
    @endsection
@endif

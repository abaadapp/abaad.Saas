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
    $money = fn ($v) => number_format((float) $v, 3);
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

    $totals = [['label' => __('المجموع الفرعي'), 'value' => $money($invoice->subtotal)]];

    if ((float) $invoice->discount_total > 0) {
        $totals[] = ['label' => __('الخصم'), 'value' => '− '.$money($invoice->discount_total)];
    }

    if ((float) $invoice->tax_total > 0) {
        $totals[] = ['label' => __('ضريبة القيمة المضافة'), 'value' => $money($invoice->tax_total)];
    }

    $totals[] = ['label' => __('الإجمالي'), 'value' => $money($invoice->total).' '.__('ر.ع'), 'grand' => true];
    $totals[] = ['label' => __('المسدَّد'), 'value' => $money($paid)];
    $totals[] = ['label' => __('الباقي'), 'value' => $money($outstanding).' '.__('ر.ع'), 'due' => true];
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

@section('meta')
    <tr>
        <td class="k">{{ __('تاريخ الإصدار') }}</td>
        <td><span class="ltr">{{ optional($invoice->issued_at)->format('Y-m-d') }}</span></td>
    </tr>
    @if ($invoice->due_at)
        <tr>
            <td class="k">{{ __('تاريخ الاستحقاق') }}</td>
            <td class="b"><span class="ltr">{{ $invoice->due_at->format('Y-m-d') }}</span></td>
        </tr>
    @endif
    @if ($terms !== null)
        <tr><td class="k">{{ __('شروط الدفع') }}</td><td>{{ $terms }}</td></tr>
    @endif
    @foreach ($orgFields as $field)
        <tr><td class="k">{{ $field['label'] }}</td><td>{{ $field['value'] }}</td></tr>
    @endforeach
@endsection

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
                <tr>
                    <td class="num faint">{{ $i + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td class="amt muted">{{ $money($item->unit_price) }}</td>
                    <td class="amt muted">{{ $money($item->tax_amount) }}</td>
                    <td class="amt b">{{ $money($item->line_total) }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="6">{{ __('لا بنود على هذه الفاتورة') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('documents.v1.partials.totals', ['totals' => $totals])

    {{--
        وملاحظةُ العميل تُطبع إن أرادها صاحبُ المحلّ — من «قوالب الأوراق».
        ولا تُخلط بالملاحظات الداخليّة: تلك عمودٌ آخر لا يبلغ ورقةَ العميل
        بحال، ولا مفتاحَ يُظهرها.
    --}}
    @if ($invoice->notes && ($showNotes ?? true))
        <div class="panel sm">
            <div class="eyebrow">{{ __('ملاحظات') }}</div>
            {{ $invoice->notes }}
        </div>
    @endif

    @if ($bank)
        <div class="panel sm">
            <div class="eyebrow">{{ __('تعليمات السداد') }}</div>
            <table style="width:100%">
                @if ($bank->bank_name)
                    <tr><td class="k faint" style="width:34%; border:none; padding:1pt 0">{{ __('البنك') }}</td>
                        <td style="border:none; padding:1pt 0">{{ $bank->bank_name }}</td></tr>
                @endif
                @if ($bank->account_name)
                    <tr><td class="k faint" style="border:none; padding:1pt 0">{{ __('اسم الحساب') }}</td>
                        <td style="border:none; padding:1pt 0">{{ $bank->account_name }}</td></tr>
                @endif
                @if ($bank->iban)
                    <tr><td class="k faint" style="border:none; padding:1pt 0">{{ __('الآيبان') }}</td>
                        <td style="border:none; padding:1pt 0"><span class="ltr b">{{ $bank->iban }}</span></td></tr>
                @endif
            </table>
        </div>
    @endif

    @include('documents.v1.partials.qr', [
        'eInvoice' => '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => '',
        'size' => 1.0,
    ])
@endsection

@if (trim((string) ($footerNote ?? '')) !== '')
    @section('foot')
        {{ $footerNote }}
    @endsection
@endif

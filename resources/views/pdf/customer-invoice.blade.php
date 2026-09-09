@extends('pdf.layout')

@section('title', $invoice->tax_total > 0 && $vatNumber !== '' ? __('فاتورة ضريبية') : __('فاتورة'))

@section('meta')
    {{-- ورقمٌ لم يُقطع بعدُ يُقال «مسودّة» — لا سطرٌ ينتهي عند فراغ --}}
    <div>{{ __('رقم الفاتورة:') }} {{ $invoice->number ?: __('مسودة') }}</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ optional($invoice->issued_at)->format('Y-m-d') }}</div>
    @if ($invoice->due_at)
        <div>{{ __('تاريخ الاستحقاق:') }} {{ $invoice->due_at->format('Y-m-d') }}</div>
    @endif
@endsection

@section('body')
    {{--
        بياناتُ العميل من لقطة الفاتورة لا من صفّه اليوم: تغييرُ اسمٍ بعد
        سنةٍ لا يعيد كتابة ورقةٍ صدرت.

        ═══ ولا عنوانَ مبنًى في هذه الورقة ═══

        كان يُطبع «العنوان: …» للعميل، ويُطبع عنوانُ المتجر في الترويسة —
        وقد رُفعا بطلب صاحب النظام. والترويسةُ تُخفيه بـ`hideAddress`
        (انظر pdf/layout)، وهنا يُرفع سطرُ العميل.

        وما بقي يُبنى من قائمةٍ تُرشَّح لا من صفوفٍ مكتوبةٍ باليد: كانت
        «—» تُطبع مكان الرقم الضريبيّ والسجلّ التجاريّ لعميلٍ فردٍ لا سجلَّ
        له — فتُقرأ الورقةُ نموذجًا نقصت خاناتُه، وهو الصنفُ نفسُه الذي
        عولج في حقول الجهة أسفلُ.
    --}}
    @php
        $partyFields = array_values(array_filter([
            ['label' => __('الرقم الضريبي'), 'value' => $invoice->customer_tax_number],
            ['label' => __('السجل التجاري'), 'value' => $invoice->customer_cr],
        ], fn ($f) => filled($f['value'])));
    @endphp

    <table class="grid">
        <tr>
            <td style="width:50%;"><strong>{{ __('العميل:') }}</strong> {{ $invoice->customer_name ?: '—' }}</td>
            @if ($partyFields !== [])
                <td style="width:50%;"><strong>{{ $partyFields[0]['label'] }}:</strong> {{ $partyFields[0]['value'] }}</td>
            @else
                <td style="width:50%;"></td>
            @endif
        </tr>
        @if (count($partyFields) > 1)
            <tr>
                <td><strong>{{ $partyFields[1]['label'] }}:</strong> {{ $partyFields[1]['value'] }}</td>
                <td></td>
            </tr>
        @endif
    </table>

    {{--
        بياناتُ الجهة — ما مُلئ منها وحدَه.

        وتُبنى من قائمةٍ ثمّ تُقسَّم اثنين اثنين، لا بصفوفٍ مكتوبةٍ باليد:
        صفٌّ يُكتب بحقلين يُخرج خانةً فارغةً حين يُملأ أحدُهما، ويُخرج صفًّا
        كاملًا فارغًا حين لا يُملأ أيٌّ منهما. وورقةٌ فيها خاناتٌ خاوية تُقرأ
        نموذجًا لم يُكمَل.

        والشُّرَطُ «—» كانت تُطبع مكان الفارغ في «عناية» و«القسم»: تقول للجهة
        إنّ للورقة قسمًا لم يُذكر — ولا قسمَ لها أصلًا.
    --}}
    @php
        $orgFields = array_values(array_filter([
            ['label' => __('رقم أمر الشراء (PO)'), 'value' => $invoice->po_number],
            ['label' => __('رقم العقد'), 'value' => $invoice->contract_number],
            ['label' => __('رقم المرجع'), 'value' => $invoice->external_reference],
            ['label' => __('القسم / الإدارة'), 'value' => $invoice->department],
            ['label' => __('مركز التكلفة'), 'value' => $invoice->cost_center],
            ['label' => __('موجه إلى / عناية'), 'value' => $invoice->attention_to],
        ], fn ($f) => filled($f['value'])));
    @endphp

    @if ($orgFields !== [])
        <table class="grid">
            @foreach (array_chunk($orgFields, 2) as $pair)
                <tr>
                    @foreach ($pair as $field)
                        <td style="width:50%;"><strong>{{ $field['label'] }}:</strong> {{ $field['value'] }}</td>
                    @endforeach
                    {{-- والفردُ الأخير يُكمَّل بخانةٍ صامتة كي لا يمتدّ عمودُه --}}
                    @if (count($pair) === 1)
                        <td style="width:50%;"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('البيان') }}</th>
                <th>{{ __('الكمية') }}</th>
                <th>{{ __('السعر') }}</th>
                <th>{{ __('الضريبة') }}</th>
                <th>{{ __('الإجمالي') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td>{{ number_format((float) $item->unit_price, 3) }}</td>
                    <td>{{ number_format((float) $item->tax_amount, 3) }}</td>
                    <td>{{ number_format((float) $item->line_total, 3) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="grid">
        <tr><td>{{ __('المجموع الفرعي') }}</td><td>{{ number_format((float) $invoice->subtotal, 3) }}</td></tr>
        @if ((float) $invoice->discount_total > 0)
            <tr><td>{{ __('الخصم') }}</td><td>{{ number_format((float) $invoice->discount_total, 3) }}</td></tr>
        @endif
        @if ((float) $invoice->tax_total > 0)
            <tr><td>{{ __('الضريبة') }}</td><td>{{ number_format((float) $invoice->tax_total, 3) }}</td></tr>
        @endif
        <tr><td><strong>{{ __('الإجمالي') }}</strong></td><td><strong>{{ number_format((float) $invoice->total, 3) }}</strong></td></tr>
        <tr><td>{{ __('المسدَّد') }}</td><td>{{ number_format($paid, 3) }}</td></tr>
        <tr><td><strong>{{ __('الباقي') }}</strong></td><td><strong>{{ number_format($outstanding, 3) }}</strong></td></tr>
    </table>

    @if ($invoice->notes)
        <p class="small">{{ $invoice->notes }}</p>
    @endif

    {{-- تعليماتُ السداد: من لا يعرف إلى أين يحوّل يؤجّل — وهذا وحده يؤخّر
         التحصيل أسابيع. وتُطبع إن ضبطها التاجر فقط --}}
    @if ($bank)
        <p class="small">
            {{ __('للسداد:') }} {{ $bank->bank_name ?: '' }}
            @if ($bank->account_name) — {{ $bank->account_name }} @endif
            @if ($bank->iban) — {{ __('الآيبان:') }} <span dir="ltr">{{ $bank->iban }}</span> @endif
        </p>
    @endif
@endsection

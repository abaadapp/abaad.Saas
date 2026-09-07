@extends('pdf.layout')

@section('title', $invoice->tax_total > 0 && $vatNumber !== '' ? __('فاتورة ضريبية') : __('فاتورة'))

@section('meta')
    <div>{{ __('رقم الفاتورة:') }} {{ $invoice->number }}</div>
    <div>{{ __('تاريخ الإصدار:') }} {{ optional($invoice->issued_at)->format('Y-m-d') }}</div>
    @if ($invoice->due_at)
        <div>{{ __('تاريخ الاستحقاق:') }} {{ $invoice->due_at->format('Y-m-d') }}</div>
    @endif
@endsection

@section('body')
    {{-- بياناتُ العميل من لقطة الفاتورة لا من صفّه اليوم: تغييرُ اسمٍ بعد
         سنةٍ لا يعيد كتابة ورقةٍ صدرت --}}
    <table class="grid">
        <tr>
            <td style="width:50%;"><strong>{{ __('العميل:') }}</strong> {{ $invoice->customer_name ?: '—' }}</td>
            <td style="width:50%;"><strong>{{ __('الرقم الضريبي:') }}</strong> {{ $invoice->customer_tax_number ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('السجل التجاري:') }}</strong> {{ $invoice->customer_cr ?: '—' }}</td>
            <td><strong>{{ __('العنوان:') }}</strong> {{ $invoice->customer_address ?: '—' }}</td>
        </tr>
        @if ($invoice->attention_to || $invoice->department)
            <tr>
                <td><strong>{{ __('عناية:') }}</strong> {{ $invoice->attention_to ?: '—' }}</td>
                <td><strong>{{ __('القسم:') }}</strong> {{ $invoice->department ?: '—' }}</td>
            </tr>
        @endif
    </table>

    {{-- ولا تُطبع الحقولُ الفارغة: ورقةٌ نصفُها شُرَطٌ تُقرأ نموذجًا لم يُملأ --}}
    @if ($invoice->po_number || $invoice->contract_number || $invoice->external_reference || $invoice->cost_center)
        <table class="grid">
            <tr>
                @if ($invoice->po_number)
                    <td><strong>{{ __('أمر الشراء:') }}</strong> {{ $invoice->po_number }}</td>
                @endif
                @if ($invoice->contract_number)
                    <td><strong>{{ __('رقم العقد:') }}</strong> {{ $invoice->contract_number }}</td>
                @endif
            </tr>
            <tr>
                @if ($invoice->external_reference)
                    <td><strong>{{ __('المرجع:') }}</strong> {{ $invoice->external_reference }}</td>
                @endif
                @if ($invoice->cost_center)
                    <td><strong>{{ __('مركز التكلفة:') }}</strong> {{ $invoice->cost_center }}</td>
                @endif
            </tr>
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

{{--
    فاتورةُ البيع على A4 — ورقةٌ تُرسَل إلى منشأةٍ تطلب فاتورةً ضريبية.

    وليست شريطَ الإيصال مُمدَّدًا: كانت تُرسم بقالبه نفسه فتخرج بمحتوًى
    منكمشٍ في أعلى الصفحة وثلثيها بياض.

    وترويستُها وتذييلُها من `pdf.layout` — مثلَ كلّ ورقةٍ في النظام.
--}}
@extends('pdf.layout')

@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $business = $order->business;

    $tpl = $tpl ?? [];
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k, string $default = '') => trim((string) ($tpl[$k] ?? $default));

    // مقاسُ الخطّ من «قوالب الأوراق»: معاملٌ يضرب الورقة كلَّها لا الجسدَ وحده
    $scale = \App\Support\DocumentRenderer::scale($line('tpl_font', 'عادي'));
    $headerNote = $line('tpl_header');

    /*
     * الرقمُ الضريبيّ سؤالان لا واحد.
     *
     * `$vat` هل للمتجر رقمٌ أصلًا — وبه وحده تُسمّى الورقة «فاتورة ضريبية».
     * و`$vatNumber` هل يريد التاجر طباعته في الترويسة. وخلطُهما كان يجعل
     * إطفاءَ المقبض يُسقط اسم الورقة معه.
     */
    $vat = $line('vat_number');
    $vatNumber = $show('tpl_show_vat_no', false) ? $vat : '';
@endphp

@section('title', $vat !== '' ? __('فاتورة ضريبية') : __('فاتورة'))

@section('meta')
    <div>{{ __('رقم الفاتورة') }}: <strong dir="ltr">{{ $order->number }}</strong></div>
    @if ($show('tpl_show_datetime'))
    <div><span class="k">{{ __('التاريخ') }}:</span> <span dir="ltr">{{ optional($order->ordered_at)->format('Y-m-d H:i') }}</span></div>
    @endif
    @if ($show('tpl_show_branch'))
    <div>{{ $order->branch ?? __('الفرع الرئيسي') }}</div>
    @endif
@endsection

@section('body')
    <table class="grid" style="margin-bottom:12pt">
        <tr>
            @if ($show('tpl_show_customer'))
                <td style="width:50%; border-bottom:none; vertical-align:top">
                    <div class="faint small">{{ __('العميل') }}</div>
                    <div>{{ \App\Support\Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي') }}</div>
                    {{--
                        الرقم الضريبي للمشتري: بدونه لا تخصم منشأةٌ مسجَّلة
                        ضريبةَ ما اشترته، فتعود تطلب ورقةً ثانية لطلبٍ واحد.
                    --}}
                    @if (! empty($customerTax))
                        <div class="muted small" style="margin-top:3pt">{{ __('الرقم الضريبي (TRN)') }}: <span dir="ltr">{{ $customerTax }}</span></div>
                    @endif
                </td>
            @endif
            <td style="border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('وسيلة الدفع') }}</div>
                <div>{{ __($order->payment_method ?? 'نقدي') }}</div>
                @if ($show('tpl_show_employee'))
                    <div class="muted small" style="margin-top:3pt">{{ __('الموظف') }}: {{ $order->employee_name ?? '—' }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th style="width:6%" class="num">#</th>
                <th>{{ __('الصنف') }}</th>
                <th style="width:12%" class="num">{{ __('الكمية') }}</th>
                <th style="width:19%" class="amt">{{ __('السعر') }}</th>
                <th style="width:19%" class="amt">{{ __('الإجمالي') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
                <tr>
                    <td class="num faint">{{ $i + 1 }}</td>
                    <td>
                        {{ $item->name }}
                        @if ($item->note)<div class="muted small">{{ $item->note }}</div>@endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="amt">{{ $money($item->price) }}</td>
                    <td class="amt">{{ $money($item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($show('tpl_show_items_count'))
        <div class="muted small" style="margin-bottom:6pt">
            {{ __('عدد الأصناف') }}: <span dir="ltr">{{ $order->items->count() }}</span>
        </div>
    @endif

    {{-- الإجماليات إلى اليسار: العين تتبع عمود المبالغ حيث انتهى الجدول --}}
    <table style="width:100%"><tr>
        <td style="width:56%; border:none"></td>
        <td style="width:44%; border:none">
            <table class="grid" style="margin:0">
                <tr><td>{{ __('المجموع الفرعي') }}</td><td class="amt">{{ $money($order->subtotal) }}</td></tr>
                @if ((float) $order->discount > 0)
                    <tr><td>{{ __('الخصم') }}</td><td class="amt">− {{ $money($order->discount) }}</td></tr>
                @endif
                {{--
                    النسبة مطبوعةٌ مع القيمة: فاتورةٌ تقول «الضريبة ١.٢٥٠» ولا
                    تقول على أي نسبة حُسبت لا تُراجَع. وتُقرأ من الفعل لا من
                    الإعلان — نسبةٌ معلنة تخالف المحتسبة فاتورةٌ تقول ما لا تفعل.
                --}}
                @php
                    $vatBase = (float) $order->subtotal - (float) $order->discount;
                    $vatRate = $vatBase > 0 ? round((float) $order->tax / $vatBase * 100, 2) : 0;
                @endphp
                <tr>
                    <td>{{ __('الضريبة') }}@if ($vatRate > 0) <span class="faint small">({{ rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') }}%)</span>@endif</td>
                    <td class="amt">{{ $money($order->tax) }}</td>
                </tr>
                @if ((float) $order->delivery_fee > 0)
                    <tr><td>{{ __('رسوم التوصيل') }}</td><td class="amt">{{ $money($order->delivery_fee) }}</td></tr>
                @endif
                <tfoot>
                    <tr><td>{{ __('الإجمالي') }}</td><td class="amt">{{ $money($order->total) }}</td></tr>
                </tfoot>
            </table>
        </td>
    </tr></table>

    @include('pdf.partials.qr', [
        'eInvoice' => $show('tpl_show_qr') ? ($qr ?? '') : '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => $googleReview ?? '',
        'size' => 1.0,
    ])
@endsection

@section('foot')
    @foreach (preg_split('/\r\n|\r|\n/', $line('tpl_footer', __('شكرًا لزيارتكم') . "\n" . __('نتشرف بخدمتكم دائمًا'))) as $l)
        @php($clean = \App\Support\ReceiptTemplate::printableHtml($l))
        @if ($clean !== ''){!! $clean !!}@if (! $loop->last)<br>@endif @endif
    @endforeach
@endsection

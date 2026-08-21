@php
    /**
     * فاتورة A4 — ورقةٌ تُرسَل إلى شركة، لا شريطُ صندوقٍ مُمدَّد.
     *
     * كانت A4 تُرسم بقالب الإيصال الحراري نفسه: محتوًى ينكمش في أعلى الصفحة
     * ويترك ثلثيها بياضًا، وخطٌّ بحجم ورقٍ عرضه ثمانية سنتيمترات في وسط ورقة
     * كاملة. وهي الورقة التي يطلبها من يريد فاتورةً ضريبية.
     *
     * والحقول هي حقول الإيصال نفسها ومن مصدرٍ واحد — القالب في «قوالب» يحكم
     * الاثنين — فلا تفترق ورقتان لطلبٍ واحد.
     */
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $business = $order->business;
    $tpl = $tpl ?? [];
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k, string $default = '') => trim((string) ($tpl[$k] ?? $default));
    /* حجم الخطّ كما في الورقتين الأخريين — «قوالب الفواتير» يحكم الثلاث */
    $base = match ($tpl['tpl_font'] ?? 'عادي') { 'صغير' => 11, 'كبير' => 14, default => 12 };
@endphp
<style>
    * { font-family: sans-serif; }
    body { direction: rtl; text-align: right; font-size: {{ $base }}px; color: #111; }
    .muted { color: #666; }
    .small { font-size: 10px; }
    h1 { font-size: 20px; margin: 0 0 2px; }
    .doc-title { font-size: 15px; font-weight: bold; }

    /* الترويسة: هويّة البائع يمينًا وبيانات الورقة يسارًا */
    .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
    .head td { vertical-align: top; }

    /* بطاقتا البائع والمشتري: الفاتورة الضريبية تُعرّف طرفيها */
    .parties { width: 100%; margin-bottom: 14px; }
    .parties td { width: 50%; vertical-align: top; padding: 8px 10px; border: 1px solid #ddd; }
    .parties .cap { font-size: 10px; color: #888; margin-bottom: 3px; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.items th { background: #f4f4f2; border: 1px solid #ddd; padding: 7px 8px; font-size: 11px; text-align: right; }
    table.items td { border: 1px solid #eee; padding: 7px 8px; }
    table.items td.num, table.items th.num { text-align: center; }
    table.items td.amt, table.items th.amt { text-align: left; }

    .totals { width: 100%; border-collapse: collapse; }
    .totals td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; }
    /* nowrap: «10.500 ر.ع» كانت تنكسر سطرين في عمودٍ ضيّق فتُقرأ رقمين */
    .totals .amt { text-align: left; white-space: nowrap; }
    .totals .grand td { border-top: 2px solid #111; border-bottom: none; font-size: 15px; font-weight: bold; padding-top: 8px; }

    .foot { margin-top: 26px; border-top: 1px solid #ddd; padding-top: 10px; }
</style>

<table class="head">
    <tr>
        <td style="width:62%">
            @if ($show('tpl_show_logo', false) && ($business->logo ?? null))
                <img src="{{ $business->logo }}" style="max-height:52px; margin-bottom:4px;" alt="">
            @endif
            <h1>{{ $business->name ?? __('نظام Abad POS') }}</h1>
            {{-- «سطر تحت اسم المتجر» كان يصل الإيصال والفاتورة الضريبية دون هذه --}}
            @if ($line('tpl_header') !== '')
                <div class="muted small">{{ $line('tpl_header') }}</div>
            @endif
            <div class="muted small">
                {{ $business->address ?? '' }}@if ($business && $business->city) — {{ $business->city }}@endif
            </div>
            @if ($business && $business->phone)
                <div class="muted small">{{ __('هاتف') }}: <span dir="ltr">{{ $business->phone }}</span></div>
            @endif
            @if ($show('tpl_show_vat_no', false) && $line('vat_number') !== '')
                <div class="muted small">{{ __('الرقم الضريبي') }}: <span dir="ltr">{{ $line('vat_number') }}</span></div>
            @endif
        </td>
        <td style="text-align:left">
            {{-- «فاتورة ضريبية» لا تُقال إلا برقمٍ ضريبي: تسميةٌ بلا رقم ادّعاء --}}
            <div class="doc-title">{{ $line('vat_number') !== '' ? __('فاتورة ضريبية') : __('فاتورة') }}</div>
            <div class="small" style="margin-top:6px">
                <div>{{ __('رقم الفاتورة') }}: <strong dir="ltr">{{ $order->number }}</strong></div>
                @if ($show('tpl_show_datetime'))
                    <div class="muted">{{ __('التاريخ') }}: <span dir="ltr">{{ optional($order->ordered_at)->format('Y-m-d H:i') }}</span></div>
                @endif
                @if ($show('tpl_show_branch'))
                    <div class="muted">{{ $order->branch ?? __('الفرع الرئيسي') }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        @if ($show('tpl_show_customer'))
        <td>
            <div class="cap">{{ __('العميل') }}</div>
            <div>{{ \App\Support\Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي') }}</div>
            {{--
                الرقم الضريبي للمشتري هنا أيضًا لا في الورقة الأخرى وحدها:
                بدونه لا تخصم منشأةٌ مسجَّلة ضريبة ما اشترته، فتعود تطلب
                ورقةً ثانية لطلبٍ واحد.
            --}}
            @if (!empty($customerTax))
                <div class="muted small" style="margin-top:4px">{{ __('الرقم الضريبي (TRN)') }}: <span dir="ltr">{{ $customerTax }}</span></div>
            @endif
        </td>
        @endif
        <td @if (! $show('tpl_show_customer')) colspan="2" @endif>
            <div class="cap">{{ __('وسيلة الدفع') }}</div>
            <div>{{ __($order->payment_method ?? 'نقدي') }}</div>
            @if ($show('tpl_show_employee'))
                <div class="muted small" style="margin-top:4px">{{ __('الموظف') }}: {{ $order->employee_name ?? '—' }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width:6%" class="num">#</th>
            <th>{{ __('الصنف') }}</th>
            <th style="width:14%" class="num">{{ __('الكمية') }}</th>
            <th style="width:18%" class="amt">{{ __('السعر') }}</th>
            <th style="width:18%" class="amt">{{ __('الإجمالي') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $i => $item)
            <tr>
                <td class="num muted">{{ $i + 1 }}</td>
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
    <div class="muted small" style="margin-bottom:8px">
        {{ __('عدد الأصناف') }}: {{ $order->items->count() }}
    </div>
@endif

{{-- الإجماليات إلى اليسار: العين تتبع عمود المبالغ حيث انتهى الجدول --}}
<table style="width:100%"><tr><td style="width:58%"></td><td style="width:42%">
    <table class="totals">
        <tr><td>{{ __('المجموع الفرعي') }}</td><td class="amt">{{ $money($order->subtotal) }}</td></tr>
        @if ((float) $order->discount > 0)
            <tr><td>{{ __('الخصم') }}</td><td class="amt">− {{ $money($order->discount) }}</td></tr>
        @endif
        {{--
            النسبة مطبوعةٌ مع القيمة: فاتورةٌ تقول «الضريبة ١.٢٥٠» ولا تقول
            على أي نسبة حُسبت لا تُراجَع. وتُقرأ من الفعل لا من الإعلان —
            نسبةٌ معلنة تخالف المحتسبة فاتورةٌ تقول ما لا تفعل.
        --}}
        @php
            $vatBase = (float) $order->subtotal - (float) $order->discount;
            $vatRate = $vatBase > 0 ? round((float) $order->tax / $vatBase * 100, 2) : 0;
        @endphp
        <tr><td>{{ __('الضريبة') }}@if ($vatRate > 0) <span class="muted small">({{ rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') }}%)</span>@endif</td><td class="amt">{{ $money($order->tax) }}</td></tr>
        @if ((float) $order->delivery_fee > 0)
            <tr><td>{{ __('رسوم التوصيل') }}</td><td class="amt">{{ $money($order->delivery_fee) }}</td></tr>
        @endif
        <tr class="grand"><td>{{ __('الإجمالي') }}</td><td class="amt">{{ $money($order->total) }}</td></tr>
    </table>
</td></tr></table>

@if ($show('tpl_show_qr') && ! empty($qr))
    {{-- وسم mPDF لا <img>: القيمة نصّ TLV لا صورة، ووضعُها في src يخرج مربّعًا مكسورًا --}}
    <div style="margin-top:18px">
        <barcode code="{{ $qr }}" type="QR" size="1.1" error="M" />
        <div class="muted small">{{ __('رمز الفوترة الإلكترونية') }}</div>
    </div>
@endif

<div class="foot muted small">
    @foreach (preg_split('/\r\n|\r|\n/', $line('tpl_footer', __('شكرًا لزيارتكم') . "\n" . __('نتشرف بخدمتكم دائمًا'))) as $l)
        @php($clean = \App\Support\ReceiptTemplate::printableHtml($l))
        @if ($clean !== ''){!! $clean !!}@if (! $loop->last)<br>@endif @endif
    @endforeach
</div>

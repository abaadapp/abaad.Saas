{{--
    الإيصالُ الحراريّ — شريطٌ بعرض الطابعة وبطول محتواه.

    وطولُه ليس هنا: يقيسه المحرّك بالرسم نفسه ثمّ يرسم على ورقةٍ بقدره —
    انظر `MpdfDriver::strip`. وكان مثبَّتًا على ٢٠٠ مم، فإيصالٌ بأربعين
    صنفًا يُقسَم صفحتين على طابعةٍ لا تعرف الصفحات: يخرج نصفُه، ثمّ يقفز
    الورق، ثمّ يخرج نصفُه الثاني بلا ترويسةٍ ولا مجموع.

    والترتيبُ يتبع ما يفعله من يمسك الورقة: يقرأ اسمَ المحلّ، ثمّ يبحث عن
    **الإجمالي**، ثمّ يراجع الأصناف إن شكّ. فالمجموعُ لا يُدفن تحت الرموز.

    والرموزُ فوق التذييل لا تحته: التذييل آخرُ ما يُقرأ، ورمزٌ بعده يقع في
    طرف الورقة الذي تقصّه الطابعة.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 3) . ' ' . __('ر.ع');
    $business = $order->business;
    $itemsCount = $order->items->sum('quantity');

    $tpl = $tpl ?? [];
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k, string $default = '') => trim((string) ($tpl[$k] ?? $default));

    $width = $width ?? 80;
    $vat = $line('vat_number');
@endphp
@include('documents.v1.partials.thermal-tokens')

<div class="c">
    @if ($show('tpl_show_logo', false) && ($business->logo ?? null))
        <img src="{{ $business->logo }}" style="max-height:30pt; margin-bottom:2pt;" alt="">
    @endif
    <div class="shop">{{ $business->name ?? __('نظام Abad POS') }}</div>
    @if ($line('tpl_header') !== '')
        <div class="muted tiny">{{ $line('tpl_header') }}</div>
    @endif
    @if ($show('tpl_show_branch'))
        <div class="muted tiny">{{ $order->branch ?? __('الفرع الرئيسي') }}</div>
    @endif
    @if ($business && ($business->city || $business->phone))
        <div class="muted tiny">
            {{ $business->city }}@if ($business->city && $business->phone) · @endif<span dir="ltr">{{ $business->phone }}</span>
        </div>
    @endif
    @if ($show('tpl_show_vat_no', false) && $vat !== '')
        <div class="muted tiny">{{ __('الرقم الضريبي') }}: <span dir="ltr">{{ $vat }}</span></div>
    @endif
    @if (! empty($customerTax))
        <div class="muted tiny">{{ __('الرقم الضريبي للعميل') }}: <span dir="ltr">{{ $customerTax }}</span></div>
    @endif
</div>

<div class="rule"></div>

{{-- واسمُ الورقة يُقال صراحةً: من يمسك إيصالين لا يعرف أيُّهما الضريبيّ --}}
<div class="c doctype">{{ $vat !== '' ? __('فاتورة ضريبية') : __('فاتورة') }}</div>

<table class="kv">
    <tr><td class="k">{{ __('رقم الفاتورة') }}</td><td class="l">{{ $order->number }}</td></tr>
    @if ($show('tpl_show_datetime'))
        <tr><td class="k">{{ __('التاريخ') }}</td><td class="l">{{ optional($order->ordered_at)->format('Y-m-d H:i') }}</td></tr>
    @endif
    @if ($show('tpl_show_employee'))
        <tr><td class="k">{{ __('الموظف') }}</td><td class="l">{{ $order->employee_name ?? '—' }}</td></tr>
    @endif
    @if ($show('tpl_show_customer'))
        <tr><td class="k">{{ __('العميل') }}</td><td class="l">{{ \App\Support\Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي') }}</td></tr>
    @endif
</table>

<div class="rule"></div>

<table class="items">
    <thead>
        <tr>
            {{--
                ومسمّياتُ الشريط قصيرةٌ عمدًا.

                عرضُه المطبوع ٧٤ مم، و«Quantity» عليه تلتفّ سطرين وتصطدم
                بعمود «Total». والعربيّةُ تنجو بقِصَرها فلا يظهر العطب لمن
                ضبط متجرَه بالعربية — يظهر على إيصال زبونه.
            --}}
            <th>{{ __('الصنف') }}</th>
            <th style="width:24%; text-align:center">{{ __('السعر') }}</th>
            <th style="width:14%; text-align:center">{{ __('كمية') }}</th>
            <th style="width:26%">{{ __('الإجمالي') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $it)
            <tr>
                <td>
                    {{ $it->name }}
                    @if ($it->note)
                        <div class="muted tiny">— {{ $it->note }}</div>
                    @endif
                </td>
                <td class="c"><span dir="ltr">{{ $money($it->price) }}</span></td>
                <td class="c" dir="ltr">{{ $it->quantity }}</td>
                <td class="l"><span dir="ltr">{{ $money($it->total) }}</span></td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($show('tpl_show_items_count'))
    <div class="muted tiny" style="margin-top:2pt">{{ __('عدد الأصناف') }}: <span dir="ltr">{{ $itemsCount }}</span></div>
@endif

<div class="rule"></div>

<table class="tot">
    <tr><td class="k">{{ __('المجموع الفرعي') }}</td><td class="l"><span dir="ltr">{{ $money($order->subtotal) }}</span></td></tr>
    @if ((float) $order->discount > 0)
        <tr><td class="k">{{ __('الخصم') }}</td><td class="l"><span dir="ltr">{{ $money($order->discount) }}</span></td></tr>
    @endif
    @if ((float) $order->tax > 0)
        <tr><td class="k">{{ __('الضريبة') }}</td><td class="l"><span dir="ltr">{{ $money($order->tax) }}</span></td></tr>
    @endif
    @if ((float) $order->delivery_fee > 0)
        <tr><td class="k">{{ __('رسوم التوصيل') }}</td><td class="l"><span dir="ltr">{{ $money($order->delivery_fee) }}</span></td></tr>
    @endif
    <tr class="grand"><td>{{ __('الإجمالي') }}</td><td class="l"><span dir="ltr">{{ $money($order->total) }}</span></td></tr>
    <tr><td class="k">{{ __('وسيلة الدفع') }}</td><td class="l">{{ $order->payment_method === 'بطاقة' ? __('فيزا') : __($order->payment_method) }}</td></tr>
    @if (($order->points_earned ?? 0) > 0)
        <tr><td class="k">{{ __('نقاط ولاء مكتسبة') }}</td><td class="l" dir="ltr">{{ $order->points_earned }}</td></tr>
    @endif
</table>

<div class="rule"></div>

@include('documents.v1.partials.qr', [
    'eInvoice' => $show('tpl_show_qr') ? ($qr ?? '') : '',
    'paperUrl' => $paperUrl ?? '',
    'googleReview' => $googleReview ?? '',
    'compact' => true,
    'size' => $width <= 60 ? 0.8 : 0.95,
])

<div class="rule"></div>

{{-- التذييل نصّ التاجر: أسطره تُحترم كما كتبها، ويُنقّى ممّا لا يطبعه الخطّ --}}
<div class="c muted tiny">
    @foreach (preg_split('/\r\n|\r|\n/', $line('tpl_footer', __('شكرًا لزيارتكم') . "\n" . __('نتشرف بخدمتكم دائمًا'))) as $l)
        @php($clean = \App\Support\ReceiptTemplate::printableHtml($l))
        @if ($clean !== ''){!! $clean !!}@if (! $loop->last)<br>@endif @endif
    @endforeach
</div>

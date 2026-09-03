{{--
    الفاتورة الضريبية تتبع قالب المتجر — لا شكلًا ثابتًا لا يملكه أحد.

    كانت ورقةً بنفسجيّة مرسومة في الكود: لا شعار، ولا ترويسة، ولا تذييل،
    ولا يُخفى منها حقلٌ ولا يُظهر. فيضبط التاجر قالب فاتورته في الإعدادات
    ثم يفتح الورقة الأخرى لطلبه نفسه فيجدها بهيئةٍ لا تشبه متجره.

    فصار المصدر واحدًا: «قوالب الأوراق» يحكم الورقتين، و`pdf.layout` يرسم
    ترويستَهما. وما يخصّ الضريبة وحدها — الرقمان الضريبيان وتفصيل الوعاء —
    يبقى هنا ولا يُخفى بمفتاح: هو سببُ وجود الورقة، وإخفاؤه يجعلها تدّعي
    ما ليست به.
--}}
@extends('pdf.layout')

@php
    $tpl = $tpl ?? [];
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k, string $default = '') => trim((string) ($tpl[$k] ?? $default));

    $scale = \App\Support\DocumentRenderer::scale($line('tpl_font', 'عادي'));
    $money = fn ($v) => \App\Support\Demo::moneyBase($v);

    /*
     * الشعارُ بمقبضه، والرقمُ الضريبيّ بلا مقبض.
     *
     * ورقةٌ عنوانها «فاتورة ضريبية» بلا رقم بائعها ليست فاتورةً ضريبية،
     * فلا يُخفى بمفتاح «إظهار الرقم الضريبي» كما يُخفى في الفاتورة العادية.
     */
    $business = $show('tpl_show_logo', false) ? $business : array_merge($business ?? [], ['logo' => null]);
    $vatNumber = (string) ($vat['number'] ?? '');
    $headerNote = $line('tpl_header');
@endphp

@section('title', __('فاتورة ضريبية'))

@section('meta')
    <div>{{ __('رقم:') }} <strong dir="ltr">{{ $order->number }}</strong></div>
    @if ($show('tpl_show_datetime'))
    <div><span class="k">{{ __('التاريخ:') }}</span> <span dir="ltr">{{ optional($order->ordered_at)->format('Y-m-d H:i') ?? $generatedAt }}</span></div>
    @endif
    @if ($line('tpl_header') !== '')
    <div>{{ $line('tpl_header') }}</div>
    @endif
@endsection

@section('body')
    <table class="grid" style="margin-bottom:12pt">
        <tr>
            <td style="width:50%; border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('العميل') }}</div>
                <div>{{ \App\Support\Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي') }}</div>
                @if (! empty($customerTax))
                    <div class="muted small" style="margin-top:3pt">{{ __('الرقم الضريبي (TRN):') }} <span dir="ltr">{{ $customerTax }}</span></div>
                @endif
            </td>
            <td style="border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('وسيلة الدفع') }}</div>
                <div>{{ __($order->payment_method) }}</div>
                @if ($show('tpl_show_branch') && $order->branch)
                    <div class="muted small" style="margin-top:3pt">{{ __('الفرع:') }} {{ $order->branch }}</div>
                @endif
                @if ($show('tpl_show_employee'))
                    <div class="muted small">{{ __('الموظف:') }} {{ $order->employee_name ?: '—' }}</div>
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
            @foreach ($order->items as $i => $it)
                <tr>
                    <td class="num faint">{{ $i + 1 }}</td>
                    <td>{{ $it->name }}</td>
                    <td class="num">{{ $it->quantity }}</td>
                    <td class="amt">{{ $money($it->price) }}</td>
                    <td class="amt">{{ $money($it->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($show('tpl_show_items_count'))
        <div class="muted small" style="margin-bottom:6pt">
            {{ __('عدد الأصناف:') }} <span dir="ltr">{{ $order->items->count() }}</span>
        </div>
    @endif

    <table style="width:100%"><tr>
        <td style="width:56%; border:none"></td>
        <td style="width:44%; border:none">
            <table class="grid" style="margin:0">
                <tr><td>{{ __('المجموع الفرعي (قبل الضريبة)') }}</td><td class="amt">{{ $money($order->subtotal) }}</td></tr>
                @if ((float) $order->discount > 0)
                    <tr><td>{{ __('الخصم') }}</td><td class="amt">− {{ $money($order->discount) }}</td></tr>
                @endif
                @if ((float) $order->delivery_fee > 0)
                    <tr><td>{{ __('رسوم التوصيل') }}</td><td class="amt">{{ $money($order->delivery_fee) }}</td></tr>
                @endif
                <tr>
                    <td>{{ __('ضريبة القيمة المضافة') }}
                        <span class="faint small">({{ rtrim(rtrim(number_format((float) ($vat['rate'] ?? 0), 2, '.', ''), '0'), '.') }}%)</span>
                    </td>
                    <td class="amt">{{ $money($order->tax) }}</td>
                </tr>
                <tfoot>
                    <tr><td>{{ __('الإجمالي المستحقّ') }}</td><td class="amt">{{ $money($order->total) }}</td></tr>
                </tfoot>
            </table>
        </td>
    </tr></table>

    @include('pdf.partials.qr', [
        'eInvoice' => $show('tpl_show_qr') ? ($qr ?? '') : '',
        'paperUrl' => $paperUrl ?? '',
        'googleReview' => '',
        'size' => 1.0,
    ])
@endsection

@section('foot')
    <div class="c">
        @foreach (preg_split('/\r\n|\r|\n/', $line('tpl_footer')) as $l)
            @if (trim($l) !== '')<div>{{ $l }}</div>@endif
        @endforeach
        <div>{{ __('فاتورة ضريبية صادرة آليًا عبر نظام Abad POS') }} — <span dir="ltr">{{ $generatedAt }}</span> — {{ __('القيم بالريال العماني') }}</div>
    </div>
@endsection

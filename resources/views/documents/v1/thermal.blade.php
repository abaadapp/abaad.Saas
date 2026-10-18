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
    // والصيغةُ من `Support\Money` لا من هنا: قرارُ «كيف يُكتب المبلغ» واحدٌ في النظام
    $money = fn ($v) => \App\Support\Money::format((float) $v, $currency);
    // ملاحظةُ المنتج وملاحظاتُ الطلب بأسمائهما — لمتجرٍ فُتحت له الميزة
    $notesOn = \App\Support\NotesAndEdits::on((int) $order->business_id);
    /*
     * وهويّةُ البائع من لقطة الورقة إن كانت مختومة — لا من المتجر اليوم.
     *
     * والشعارُ وحده يبقى حيًّا: صورةٌ لا واقعةٌ ماليّة، ونسخُها في كلّ
     * صفٍّ يضخّم القاعدةَ بلا أن يحفظ شيئًا يُراجَع.
     * انظر `Document\Snapshot`.
     *
     * ويصل مُضمَّنًا (`$logo` من `InvoiceBranding::logo`) لا من صفّ المتجر:
     * `$business->logo` رابطٌ نسبيّ للشاشة لا يقرؤه mpdf — انظر `saleStrip`.
     */
    $business = $order->business;
    $stamped = (object) (array_filter($seller ?? []) + [
        'name' => $business->name ?? null,
        'city' => $business->city ?? null,
        'phone' => $business->phone ?? null,
    ]);
    $stamped->logo = $logo ?? null;
    $itemsCount = $order->items->sum('quantity');

    $tpl = $tpl ?? [];
    $show = fn (string $k, bool $default = true) => (bool) ($tpl[$k] ?? $default);
    $line = fn (string $k, string $default = '') => trim((string) ($tpl[$k] ?? $default));

    $width = $width ?? 80;
    $vat = $line('vat_number');
    /*
     * والهاتفُ كان يُطبع دائمًا، فغيابُ مقبضه يُقرأ «ظاهرًا» — إيصالٌ رُسم
     * قبل المقبض يبقى كما كان. والمدينةُ ليست تواصلًا: تبقى معه أو بدونه.
     */
    $phone = $show('tpl_show_phone') ? ($stamped->phone ?? null) : null;
@endphp
@include('documents.v1.partials.thermal-tokens')

{{-- صندوقُ الشريط — للشاشة وحدها، انظر `layout` و`Document\PaperSize` --}}
<div class="paper">

<div class="c">
    {{-- والافتراضُ «يُعرض» كما في `partials/identity`: إخفاءُ ما لم يطلب أحدٌ إخفاءه أسوأ --}}
    @if ($show('tpl_show_logo') && ($stamped->logo ?? null))
        <img src="{{ $stamped->logo }}" style="max-height:30pt; margin-bottom:2pt;" alt="">
    @endif
    <div class="shop">{{ $stamped->name ?? __('نظام Abad POS') }}</div>
    @if ($line('tpl_header') !== '')
        <div class="muted tiny">{{ $line('tpl_header') }}</div>
    @endif
    @if ($show('tpl_show_branch'))
        <div class="muted tiny">{{ $order->branch ?? __('الفرع الرئيسي') }}</div>
    @endif
    @if ($stamped->city || $phone)
        <div class="muted tiny">
            {{ $stamped->city }}@if ($stamped->city && $phone) · @endif<span dir="ltr">{{ $phone }}</span>
        </div>
    @endif
    {{-- وعنوانُ المتجر الإلكترونيّ — من نطاقه المنشور لا من نصٍّ يُكتب (`DocumentRenderer::website`) --}}
    @if (($website ?? '') !== '')
        <div class="muted tiny" dir="ltr">{{ $website }}</div>
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
        <tr><td class="k">{{ __('العميل') }}</td><td class="l">{{ $order->customer_name ?: __('عميل نقدي') }}</td></tr>
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
            {{--
                وإضافاتُ الصنف تحته — من لقطة البيع، ومالُها مرّةً واحدة.

                المجبيّةُ فوق ثمنه سطورٌ بثمنها وكميّتها ومجموعها، فتجمع أسطرُ
                الصنف إلى ما دُفع فيه (`lineTotal`) لا إلى ثمنه وحده. والتي داخل
                ثمنه تُسمّى بلا مال. والقاعدةُ قاعدةُ فاتورة العميل نفسُها —
                انظر `ItemAddons`.
            --}}
            {{--
                والاسمُ الطويل يلتفّ بين كلماته بلا وسمٍ عليه.

                كان mpdf يقطع الكلمةَ العربيّة حيث امتلأ السطر («تغلي» ثمّ «ف»)
                ويُصغّر الجدولَ كلَّه إن لم يتّسع اسمٌ لعموده — لأنّ خطَّ الورقة
                كان يُبدّل كلَّ مسافةٍ برمزٍ لا يراه المحرّكُ مسافة. أُصلح الخطّ
                نفسُه (`keep_spaces` في `scripts/build-document-font.py`)، فلا
                `Paper::keepWords` هنا: يُضاعف حجمَ الورقة حتى يتجاوز إيصالُ الألف
                حدَّ PCRE في mpdf.
            --}}
            @php($extras = \App\Support\ItemAddons::of($it))
            @php($joined = in_array($extras['mode'], ['priced', 'bundled'], true) ? 'joined' : '')
            <tr>
                <td class="name {{ $joined }}">
                    {{ $it->name }}
                    {{-- والمقاسُ من لقطة البيع لا من الكتالوج: مقاسٌ أُعيدت تسميتُه لا يغيّر إيصالًا مضى --}}
                    @if (filled($it->variant_name))
                        <div class="muted tiny">{{ __('المقاس') }}: <span dir="{{ \App\Support\Paper::dirOf($it->variant_name) }}">{{ $it->variant_name }}</span></div>
                    @endif
                    {{-- ورسالةُ كرت الهدية لا تُطبع على الإيصال — كما على الفاتورة (`paperNote`) --}}
                    @php($itemNote = \App\Support\Store\GiftCardProduct::paperNote($it, $order))
                    @if ($itemNote)
                        {{-- وملاحظةُ المنتج باسمها حيث فُتحت الميزة (`NotesAndEdits`) --}}
                        @if ($notesOn)
                            <div class="muted tiny">{{ __('ملاحظة المنتج') }}: <span dir="{{ \App\Support\Paper::dirOf($itemNote) }}">{{ $itemNote }}</span></div>
                        @else
                            <div class="muted tiny">— {{ $itemNote }}</div>
                        @endif
                    @endif
                    {{--
                        وخياراتُ الطلب المخصَّص على الشريط كما هي على الورقة.

                        الشريطُ هو ما يمسكه الزبونُ فعلًا — الورقةُ تُطبع عند
                        الطلب، والشريطُ يخرج من الصندوق مع كلّ بيعة. فبندٌ
                        مخصَّصٌ بلا خياراته عليه إيصالٌ لا يقول ما اشتُري.

                        والقارئُ واحدٌ للورقتين (`paperLines`): الداخليُّ لا
                        يُطبع هنا ولا هناك، ولا تفترق ورقتان في معنى واحد.
                    --}}
                    {{--
                        و`$opt` لا `$line`: القالبُ يُعرّف أعلاه مغلِّفًا اسمُه
                        `$line` يقرأ إعدادات الشريط، ويستدعيه التذييل. وحلقةٌ
                        تسمّي متغيّرها باسمه تدهسه بمصفوفة، فينهار التذييل على
                        كلّ إيصالٍ فيه بندٌ مخصَّص — والسطرُ الذي انهار ليس
                        السطرَ الذي كُتب.
                    --}}
                    @php($customLines = $it->isCustom() ? \App\Support\CustomArrangement::paperLines($it->custom_details) : [])
                    @foreach ($customLines as $opt)
                        {{--
                            والاتّجاهُ من السطر كلِّه ابتداءً بتسميته — كما في
                            ورقة A4. وبلا `dir` يرث السطرُ اتّجاهَ الشريط
                            فتُحاذى الخياراتُ إلى غير جهة اسمِ البند، وتُدفع
                            الشرطةُ إلى آخره.
                        --}}
                        <div class="muted tiny" dir="{{ \App\Support\Paper::dirOf($opt['label'].' '.$opt['value']) }}">— {{ $opt['label'] }}: {{ $opt['value'] }}</div>
                    @endforeach
                    {{-- وما داخل الثمن يُسمّى ولا يُكتب مالًا --}}
                    @if ($extras['mode'] === 'included')
                        @foreach ($extras['rows'] as $addon)
                            <div class="tiny" data-addon="included">+ <span dir="{{ \App\Support\Paper::dirOf($addon['name']) }}">{{ $addon['name'] }}</span> × <span dir="ltr">{{ $addon['qty'] }}</span> <span class="muted">— {{ __('ضمن السعر') }}</span></div>
                        @endforeach
                    @endif
                </td>
                <td class="c {{ $joined }}"><span dir="ltr">{{ $money($it->price) }}</span></td>
                <td class="c {{ $joined }}" dir="ltr">{{ $it->quantity }}</td>
                <td class="l {{ $joined }}"><span dir="ltr">{{ $money($it->total) }}</span></td>
            </tr>
            @if ($extras['mode'] === 'priced')
                @foreach ($extras['rows'] as $addon)
                    @php($edge = $loop->last ? 'addon addon-last' : 'addon')
                    <tr data-addon="priced">
                        <td class="name {{ $edge }}">+ <span dir="{{ \App\Support\Paper::dirOf($addon['name']) }}">{{ $addon['name'] }}</span></td>
                        <td class="c {{ $edge }}"><span dir="ltr">{{ $money($addon['unit']) }}</span></td>
                        <td class="c {{ $edge }}" dir="ltr">{{ $addon['qty'] }}</td>
                        <td class="l {{ $edge }}"><span dir="ltr">{{ $money($addon['total']) }}</span></td>
                    </tr>
                @endforeach
            @elseif ($extras['mode'] === 'bundled')
                {{-- ولقطاتٌ لا تطابق ما جُبي: سطرٌ واحدٌ بما جُبي فعلًا، يسمّي ما فيه --}}
                <tr data-addon="bundled">
                    <td class="name addon addon-last">+ {{ __('إضافات') }}: <span dir="{{ \App\Support\Paper::dirOf($extras['names']) }}">{{ $extras['names'] }}</span></td>
                    <td class="addon addon-last"></td>
                    <td class="addon addon-last"></td>
                    <td class="l addon addon-last"><span dir="ltr">{{ $money($extras['total']) }}</span></td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

{{-- وملاحظاتُ الطلب كما كتبها العميل — قسمٌ باسمه؛ والداخليّةُ لا تبلغ الإيصال --}}
@if ($notesOn && filled($order->notes))
    <div class="tiny" style="margin-top:3pt" data-order-notes>
        <span class="muted">{{ __('ملاحظات الطلب') }}:</span>
        <div dir="{{ \App\Support\Paper::dirOf($order->notes) }}" style="white-space:pre-wrap">{{ $order->notes }}</div>
    </div>
@endif

@if ($show('tpl_show_items_count'))
    {{--
        و«عدد القطع» لا «عدد الأصناف» — والرقمُ لم يتغيّر.

        ═══ التناقضُ الذي وُلد منه هذا السطر ═══

        الشريطُ كان يقول «عدد الأصناف: ٦» والورقةُ A4 للطلب نفسِه تقول
        «عدد الأصناف: ٣»: هذا يجمع الكميّات وتلك تعدّ السطور. عبارةٌ واحدة
        ورقمان — فمن يقابل الإيصالَ بالفاتورة يظنّ أنّ أحدهما خطأ.

        والمقياسان كلاهما نافع: الكاشيرُ يعدّ القطعَ وهو يكيّس، والمحاسبُ
        يعدّ السطور. فبقي لكلّ ورقةٍ رقمُها، وتغيّرت العبارةُ لتقول أيَّهما
        — لا الرقمُ ليوافق عبارةً.
    --}}
    <div class="muted tiny" style="margin-top:2pt">{{ __('عدد القطع') }}: <span dir="ltr">{{ $itemsCount }}</span></div>
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
    {{-- وطلبُ الموقع «عند الاستلام» لا يُطبع «نقدي» قبل أن يُقبض — `PaymentMethods::onReceipt` --}}
    <tr><td class="k">{{ __('وسيلة الدفع') }}</td><td class="l">{{ \App\Support\PaymentMethods::onReceipt($order) }}</td></tr>
    {{--
        وحالُ الدفع بجانب وسيلته — لمن أشعله (`show_payment_status`).

        إيصالُ الموقع يُعطى لمن دفع ببطاقته، وهو ورقتُه أنّ مالَه قُبض. ومطفأٌ
        افتراضًا: لم يكن على الإيصال قطّ، فلا يُضاف إلى إيصال متجرٍ لم يطلبه.
    --}}
    @if ($show('tpl_show_payment_status', false) && filled($order->payment_status))
        <tr><td class="k">{{ __('حالة الدفع') }}</td><td class="l">{{ __($order->payment_status) }}</td></tr>
    @endif
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

</div>

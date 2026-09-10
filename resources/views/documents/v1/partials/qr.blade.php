{{--
    رمزُ التحقّق أسفل الورقة — صغيرٌ ولا يسيطر.

    ═══ وثلاثةٌ قد تجتمع، ولكلٍّ سطرٌ يقول ما هو ═══

    رمزُ الفوترة الإلكترونية (TLV بالمعيار الخليجي — لا يُقرأ رابطًا)، ورمزُ
    الورقة أونلاين، ورمزُ تقييم Google. ورمزٌ بلا سطرٍ تحته يجعل الزبون
    يمسح ثلاثةً ليعرف أيُّها فاتورته.

    وتُرسم في صفٍّ على A4 وعمودٍ على الشريط: شريطُ ٥٨ لا يسع رمزين
    متجاورين بحجمٍ يُمسح.

    والكتلةُ محجوزةٌ كاملةً (`page-break-inside: avoid` في `.qr-block`) فلا
    ينفصل الرمزُ عن سطره، ولا يركب على الجدول فوقه.

    المتغيّرات: `$eInvoice` و`$paperUrl` و`$googleReview` — وأيُّها فارغٌ لا
    يُرسم. و`$compact` يجعلها عمودًا، و`$size` حجم الوحدة.
--}}
@php
    $codes = array_values(array_filter([
        ($eInvoice ?? '') !== '' ? ['code' => $eInvoice, 'cap' => __('رمز الفوترة الإلكترونية')] : null,
        ($paperUrl ?? '') !== '' ? ['code' => $paperUrl, 'cap' => __('امسح للتحقق من المستند')] : null,
        ($googleReview ?? '') !== '' ? ['code' => $googleReview, 'cap' => __('امسح الرمز لتقييمنا على Google')] : null,
    ]));
    $size = $size ?? 0.9;
@endphp

@if (count($codes) > 0)
    @if ($compact ?? false)
        <div class="qr-block">
            @foreach ($codes as $q)
                <div class="c" style="margin-bottom:3pt;">
                    <barcode code="{{ $q['code'] }}" type="QR" size="{{ $size }}" error="M" />
                    <div class="qr-cap">{{ $q['cap'] }}</div>
                </div>
            @endforeach
        </div>
    @else
        <table class="qr-block" style="width:100%;">
            <tr>
                @foreach ($codes as $q)
                    <td style="width:{{ (int) (100 / count($codes)) }}%; text-align:center; border:none; padding:0 4pt;">
                        <barcode code="{{ $q['code'] }}" type="QR" size="{{ $size }}" error="M" />
                        <div class="qr-cap">{{ $q['cap'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
@endif

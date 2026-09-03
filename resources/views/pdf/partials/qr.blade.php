{{--
    رموزُ أسفل الورقة — ولكلٍّ منها سببٌ يُكتب تحته.

    ثلاثةٌ قد تجتمع: رمزُ الفوترة الإلكترونية (TLV بالمعيار الخليجي، ولا
    يُقرأ رابطًا)، ورمزُ الورقة أونلاين، ورمزُ تقييم Google. ورمزٌ بلا سطرٍ
    تحته يقول ما هو يجعل الزبون يمسح ثلاثةً ليعرف أيُّها فاتورته.

    وتُرسم في صفٍّ واحد على A4 وعمودٍ على الشريط: شريطُ ٥٨ لا يسع رمزين
    متجاورين بحجمٍ يُمسح.

    المتغيّرات: $eInvoice و$paperUrl و$googleReview — وأيُّها فارغ لا يُرسم.
    و$compact يجعلها عمودًا، و$size حجم الوحدة.
--}}
@php
    $codes = array_values(array_filter([
        ($eInvoice ?? '') !== '' ? ['code' => $eInvoice, 'cap' => __('رمز الفوترة الإلكترونية')] : null,
        ($paperUrl ?? '') !== '' ? ['code' => $paperUrl, 'cap' => __('امسح لعرض الفاتورة')] : null,
        ($googleReview ?? '') !== '' ? ['code' => $googleReview, 'cap' => __('امسح الرمز لتقييمنا على Google')] : null,
    ]));
    $size = $size ?? 0.9;
@endphp

@if (count($codes) > 0)
    @if ($compact ?? false)
        @foreach ($codes as $q)
            <div class="qr c">
                <barcode code="{{ $q['code'] }}" type="QR" size="{{ $size }}" error="M" />
                <div class="cap">{{ $q['cap'] }}</div>
            </div>
        @endforeach
    @else
        <table style="width:100%; margin-top:10pt;">
            <tr>
                @foreach ($codes as $q)
                    <td style="width:{{ (int) (100 / count($codes)) }}%; text-align:center; border:none; padding:0 4pt;">
                        <barcode code="{{ $q['code'] }}" type="QR" size="{{ $size }}" error="M" />
                        <div class="faint tiny" style="margin-top:2pt;">{{ $q['cap'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
@endif

{{--
    خاتمةُ الورقة — ما يُقال بعد المال: ملاحظاتٌ وشروطٌ ورمزُ تحقّق.

    ═══ وهي جزئيّةٌ واحدةٌ لتسع أوراق ═══

    لأنّ الترتيبَ نفسَه يجب أن يقع في كلٍّ منها. ولو تُركت كلُّ ورقةٍ
    تبني خاتمتَها بيدها لافترقت التسعُ عند أوّل تعديل — ورقةٌ رمزُها فوق
    ملاحظاتها وأخرى تحتها.

    ═══ والرمزُ هادئٌ لا يسيطر — ومكانُه أسفلُ الورقة لا أسفلُ المجاميع ═══

    المواصفة: «لا تجعله العنصر البصري المسيطر»، و«يجب أن يبدو جزءًا من
    نهاية المستند، وليس جزءًا من جدول الحسابات».

    وكان يقع في ذيل هذا الجدول مباشرةً بعد الملاحظات. ففاتورةٌ بصنفٍ واحد
    تنتهي عند ثلثَي الورقة ويقع رمزُها هناك، ثمّ يبقى تحته شبرٌ من البياض
    إلى التذييل — فيُقرأ الرمزُ ملحقًا بالمجاميع لا خاتمةً للورقة.

    فيُدفع إلى `closezone` في `layout`: منطقةٌ تُثبَّت أسفلَ الصفحة الأخيرة
    فوق تذييل المحرّك. و`@push` لأنّ القالبَ يُرسم قبلها — انظر `layout`.

    وحمولتُه وتوليدُه لا يُمسّان — انظر `App\Support\PublicDocument` و`EInvoice`.

    ولا تُترك مساحةٌ خاويةٌ حين لا شيءَ يُقال: الجدولُ لا يُرسم أصلًا.

    المتغيّرات: `$panels` قائمةُ `['cap' => مفتاحٌ عربيّ, 'text'?, 'rows'?]` ·
    `$eInvoice` · `$paperUrl` · `$googleReview`.
--}}
@php
    $panels = array_values(array_filter(
        $panels ?? [],
        fn ($p) => filled($p['text'] ?? null) || count(array_filter($p['rows'] ?? [])) > 0,
    ));

    $hasCode = ($eInvoice ?? '') !== '' || ($paperUrl ?? '') !== '' || ($googleReview ?? '') !== '';
@endphp

@if ($hasCode)
    @push('closezone')
        @include('documents.v1.partials.qr', [
            'compact' => true,
            'align' => \App\Support\Paper::rtl() ? 'right' : 'left',
            'size' => $qrSize ?? 0.6,
        ])
    @endpush
@endif

@if (count($panels) > 0)
    <table class="stack" style="margin-top:{{ round(12, 2) }}pt">
        @foreach ($panels as $panel)
            @php([$first, $second] = \App\Support\Paper::pair($panel['cap']))
            <tr><td class="cap lbl">{{ $first }}</td></tr>
            @if ($second !== '')
                <tr><td class="cap lbl2" dir="{{ \App\Support\Paper::dirOf($second, 'ltr') }}">{{ $second }}</td></tr>
            @endif
            <tr><td>
                @if (filled($panel['text'] ?? null))
                    <div class="bidi" dir="{{ \App\Support\Paper::dirOf($panel['text']) }}">{{ $panel['text'] }}</div>
                @endif
                @if (count(array_filter($panel['rows'] ?? [])) > 0)
                    <table style="width:100%; border-collapse:collapse">
                        @foreach (array_filter($panel['rows']) as $row)
                            <tr>
                                <td class="lbl2" style="width:30%; border:none; padding:1.5pt 0">{{ $row['label'] }}</td>
                                <td style="border:none; padding:1.5pt 0">
                                    @if ($row['ltr'] ?? false)
                                        <span class="ltr b">{{ $row['value'] }}</span>
                                    @else
                                        {{ $row['value'] }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </td></tr>
        @endforeach
    </table>
@endif

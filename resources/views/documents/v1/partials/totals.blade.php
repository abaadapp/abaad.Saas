{{--
    المجاميعُ — أسطرٌ تتبع الجدول بأعمدته، لا لوحةٌ بجانبه.

    ═══ ولمَ ذهبت اللوحة ═══

    كانت بطاقةً مؤطَّرةً ثمّ لوحةً بأرضيّةٍ فاتحة، والإجماليُّ فيها كتلةً
    داكنةً ثمّ سطرًا بخطّ. وفي المرجع المعتمد ليست شيئًا من ذلك: سطران
    يقعان تحت الجدول مباشرة، بأعمدةٍ تحاذي أعمدتَه — المبلغُ تحت
    «المجموع»، والتسميةُ تحت «السعر». فتُقرأ امتدادًا للجدول.

    ولا خطَّ فوق الإجمالي ولا أرضيّةَ تحته: الخطُّ الأخيرُ من الجدول يفصل
    المجاميعَ عمّا قبلها، ووزنُ الحبر يقول أيُّ السطور أهمّ. وهو ما تعنيه
    المواصفة: «Typography + spacing + alignment».

    ═══ والأعمدةُ مكتوبةٌ لتُحاذي جدولَ الأصناف ═══

    جدولان مستقلّان لا يتّفقان على عرضٍ إلّا إن صُرّح لهما به. و٢٤٪ للمبلغ
    و١٨٪ للسعر هي نفسُها المكتوبة في `partials/items` — فلو تغيّرت هناك
    تُغيَّر هنا، ولا ثالثَ يحملها.

    المتغيّر: `$totals` قائمةُ `['label' => مفتاحٌ عربيّ, 'value', 'grand'?, 'due'?, 'hint'?]`.
--}}
@php
    $rows = array_values(array_filter($totals ?? [], fn ($r) => filled($r['value'] ?? null)));
@endphp

@if (count($rows) > 0)
    <table class="totals">
        @foreach ($rows as $row)
            @php([$first, $second] = \App\Support\Paper::pair($row['label']))
            <tr class="{{ ($row['grand'] ?? false) ? 'grand' : '' }}">
                <td style="width:58%"></td>
                <td style="width:18%" class="lblcell">
                    <span class="lbl">{{ $first }}</span>@if (filled($row['hint'] ?? null))&nbsp;<span class="lbl2 ltr">{{ $row['hint'] }}</span>@endif
                    @if ($second !== '')
                        <br><span class="lbl2">{{ $second }}</span>
                    @endif
                </td>
                {{-- والمبلغُ معزول: انظر `partials/items` --}}
                <td style="width:24%" class="amt"><span dir="ltr">{{ $row['value'] }}</span></td>
            </tr>
        @endforeach
    </table>
@endif

{{--
    خاتمةُ الورقة — الملاحظاتُ والرمزُ في جهة، والمجاميعُ في الجهة المقابلة.

    ═══ ولمَ صارت الجزئيّةُ تحمل الثلاثة ═══

    كانت تحمل المجاميعَ وحدها، وتترك أربعةً وخمسين بالمئة من عرض الورقة
    خاليةً إلى جانبها. وكانت الملاحظاتُ شريطًا بعرض الورقة **أسفلها**،
    والرمزُ شريطًا ثالثًا أسفلَ ذاك. فتنتهي كلُّ ورقةٍ في النظام بثلاثة
    شرائطَ متراكمةٍ ونصفِ صفحةٍ بياضًا في وسطها.

    والمرجعُ المعتمد يضعها في عمودين: «Notes في الجهة المقابلة للإجماليات»
    و«QR أسفل notes». فيمتلئ البياضُ بما يخصّه، وتُقرأ خاتمةُ الورقة دفعةً
    واحدة: ما يُقال عن الصفقة في جهة، وما يُدفع فيها في الجهة الأخرى.

    ═══ وهي جزئيّةٌ واحدة لتسع أوراق ═══

    لأنّ الترتيبَ نفسَه يجب أن يقع في كلٍّ منها. ولو تُركت كلُّ ورقةٍ تبني
    عمودَيها بيدها لافترقت التسعُ عند أوّل تعديل — ورقةٌ ملاحظاتُها يمينًا
    وأخرى يسارًا، وثالثةٌ رمزُها فوق ملاحظاتها.

    ═══ والمجاميعُ قد تغيب والعمودُ يبقى ═══

    سندُ تسليمٍ بلا أسعار لا مجاميعَ له، وملاحظاتُ التسليم عليه هي كلُّ ما
    يُقرأ. فالجدولُ يُرسم ما دام فيه شيءٌ يُقرأ — لا ما دامت فيه أرقام.

    المتغيّرات:
      `$totals`  قائمةُ `['label','value','grand'?,'due'?,'hint'?]`
      `$aside`   سطرٌ باهتٌ فوق الملاحظات (عددُ الأصناف مثلًا)
      `$panels`  كتلُ الجهة المقابلة: `['cap', 'text'?, 'rows'?]`
      `$eInvoice` · `$paperUrl` · `$googleReview` — الرموز، وأيُّها فارغٌ لا يُرسم
--}}
@php
    $rows = array_values(array_filter($totals ?? [], fn ($r) => filled($r['value'] ?? null)));

    $panels = array_values(array_filter(
        $panels ?? [],
        fn ($p) => filled($p['text'] ?? null) || count(array_filter($p['rows'] ?? [])) > 0,
    ));

    $hasCode = ($eInvoice ?? '') !== '' || ($paperUrl ?? '') !== '' || ($googleReview ?? '') !== '';
    $hasSide = filled($aside ?? null) || count($panels) > 0;
@endphp

@if (count($rows) > 0 || $hasSide)
    <table class="totals-wrap">
        <tr>
            {{--
                والعمودُ يأخذ الورقةَ كلَّها حين لا مجاميعَ بجانبه.

                خمسةٌ وخمسون بالمئة لملاحظاتٍ وحدها يترك نصفَ الورقة
                بياضًا لا شيءَ يملؤه — وهو البياضُ نفسُه الذي وُلدت هذه
                الجزئيّةُ لتملأه.
            --}}
            <td style="width:{{ count($rows) > 0 ? 55 : 100 }}%" class="aside">
              {{-- وصفٌّ لكلّ كتلة: هوامشُ الـ`div` تسقط داخل الخلايا — انظر `partials/tokens` --}}
              <table class="asidestack">
                @if (filled($aside ?? null))
                    <tr><td class="sm muted">{{ $aside }}</td></tr>
                @endif

                {{--
                    وعنوانُ الكتلة صفٌّ وجسدُها صفٌّ — لا كتلتان في خليّة.

                    هامشُ الـ`div` داخل الخليّة يسقط عند mpdf، فيلتصق
                    «ملاحظات» بنصّه حتى تمسّ تشكيلةُ السطر حروفَ العنوان.
                    وحشوُ الخليّة يُحترم كاملًا — انظر `table.asidestack`.
                --}}
                @foreach ($panels as $panel)
                    <tr><td class="cap eyebrow">{{ $panel['cap'] }}</td></tr>
                    <tr><td class="sm">
                        @if (filled($panel['text'] ?? null))
                            <div class="bidi" dir="{{ \App\Support\Paper::dirOf($panel['text']) }}">{{ $panel['text'] }}</div>
                        @endif
                        @if (count(array_filter($panel['rows'] ?? [])) > 0)
                            <table style="width:100%; border-collapse:collapse">
                                @foreach (array_filter($panel['rows']) as $row)
                                    <tr>
                                        <td class="faint" style="width:34%; border:none; padding:1.5pt 0">{{ $row['label'] }}</td>
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

                {{--
                    والرمزُ أسفلَ الملاحظات، في عمودها لا بعرض الورقة.

                    وهو خارج هذه الخليّة عمدًا حين تغيب المجاميع؟ لا —
                    داخلها دائمًا: `partials/qr` يبني جدولًا بعرض ١٠٠٪،
                    وجدولٌ كذلك داخل خليّةٍ بعرض ٥٥٪ يُربك حسابَ العرض
                    الأدنى في mpdf فينسحق العمودان. انظر `layout`.

                    فتُرسم الرموزُ كتلًا متراكبةً هنا — وهي واحدةٌ أو
                    اثنتان على أكثر الأوراق.
                --}}
                @if ($hasCode)
                    <tr><td>
                        @include('documents.v1.partials.qr', [
                            'compact' => true,
                            'align' => \App\Support\Paper::rtl() ? 'right' : 'left',
                            'size' => $qrSize ?? 0.9,
                        ])
                    </td></tr>
                @endif
              </table>
            </td>

            @if (count($rows) > 0)
                <td class="gap" style="width:4%"></td>
                {{--
                    واللوحةُ أرضيّتُها على الخليّة لا على كتلةٍ داخلها.

                    قِيس في الـPDF: mpdf يُقلّص كتلةَ `div` إلى عرض نصّها
                    داخل خليّة جدول — ولو صُرّح لها `width: 100%`. انظر
                    `partials/parties` و`design-system/DESIGN_SYSTEM.md` §10.
                --}}
                <td class="totalcard" style="width:41%">
                    <table class="totals">
                        @foreach ($rows as $row)
                            {{--
                                والخطُّ الفاصلُ صفٌّ بخليّةٍ واحدة، لا حدٌّ على خليّتَي الصفّ.

                                قِيس في الـPDF: خليّتان متجاورتان بحدٍّ علويٍّ واحدٍ يرسمهما
                                mpdf مفصولتين بشعرةٍ بيضاء عند ملتقاهما، فينقطع خطُّ الإجمالي
                                في وسطه. وخليّةٌ واحدةٌ بـ`colspan` تُرسم مرّةً فلا ملتقى.

                                وهو الدرسُ نفسُه الذي كان يُكتب على `tr` حين كانت للإجمالي
                                أرضيّة — انظر `design-system/DESIGN_SYSTEM.md` §10.

                                ولا خطَّ فوق أوّل الصفوف: حدٌّ في أعلى اللوحة يفصلها عن
                                فراغٍ لا عن شيء.
                            --}}
                            @if (! $loop->first && (($row['grand'] ?? false) || ($row['due'] ?? false)))
                                <tr class="sep"><td colspan="2" class="{{ ($row['grand'] ?? false) ? 'strong' : '' }}"></td></tr>
                            @endif
                            <tr class="{{ ($row['grand'] ?? false) ? 'grand' : (($row['due'] ?? false) ? 'due' : '') }}">
                                <td class="{{ ($row['grand'] ?? false) || ($row['due'] ?? false) ? '' : 'k' }}">
                                    {{ $row['label'] }}
                                    @if (filled($row['hint'] ?? null))
                                        <span class="xs faint">{{ $row['hint'] }}</span>
                                    @endif
                                </td>
                                {{-- والمبلغُ معزول: انظر `partials/items` --}}
                                <td class="amt"><span dir="ltr">{{ $row['value'] }}</span></td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            @endif
        </tr>
    </table>
@endif

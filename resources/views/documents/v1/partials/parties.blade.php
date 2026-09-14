{{--
    الطرفُ الآخر — حقلٌ مسمّى تحت عنوان المستند.

    ═══ ولمَ صار حقلًا بعد أن كان بطاقةً ثمّ عمودًا ═══

    كان بطاقتين بأرضيّةٍ وحافّة، ثمّ عمودين وفاصلًا. وفي المرجع المعتمد
    ليس واحدًا منهما: هو **حقلٌ كبقيّة الحقول** — «المورد / Supplier /
    Fastcomet» — يقع تحت عنوان المستند مباشرة.

    وهو الصواب: هويّةُ المُصدِر لها مكانُها في الطرف المقابل مع الشعار،
    فلا حاجةَ إلى عمودين متقابلين تحت الترويسة. والطرفُ الآخر خبرٌ واحدٌ
    من أخبار المستند — كرقمه وتاريخه — فيُعرض كما تُعرض.

    ═══ والتسميةُ من نوع المستند لا ثابتة ═══

    فاتورةُ بيعٍ طرفُها «العميل»، وأمرُ شراءٍ «المورّد»، وسندُ قبضٍ
    «الدافع»، وسندُ تسليمٍ «المستلِم». ولا تُسمّى كلُّها «العميل» لأنّ
    القالبَ واحد — المواصفة: «لا تجعل كل الأوراق Invoice».

    وتُرشَّح عند بنائها: طرفٌ بلا سطرٍ واحد لا يُطبع عنوانُه، وخانةٌ
    خاويةٌ تحت «المورّد» تُقرأ حقلًا نُسي.

    المتغيّر: `$parties` قائمةُ `['cap' => مفتاحٌ عربيّ, 'lines' => [...]]`.
--}}
@php
    $shown = array_values(array_filter(
        $parties ?? [],
        fn ($p) => count(array_filter($p['lines'] ?? [])) > 0,
    ));
@endphp

@foreach ($shown as $party)
    @php([$first, $second] = \App\Support\Paper::pair($party['cap']))
    <table class="fields">
        <tr><td class="lbl" dir="{{ \App\Support\Paper::dirOf($first, 'ltr') }}">{{ $first }}</td></tr>
        @if ($second !== '')
            <tr><td class="lbl2" dir="{{ \App\Support\Paper::dirOf($second, 'ltr') }}">{{ $second }}</td></tr>
        @endif
        @foreach (array_values(array_filter($party['lines'])) as $n => $line)
            {{-- واسمُ الطرف أوّلُ سطوره وأثقلُها، وما بعده تفصيلُ اتّصال --}}
            <tr><td class="{{ $n === 0 ? 'val b' : 'val lbl2' }}{{ $loop->last ? ' f-end' : '' }}"
                    dir="{{ \App\Support\Paper::dirOf($line, 'ltr') }}">{{ $line }}</td></tr>
        @endforeach
    </table>
@endforeach

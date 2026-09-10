{{--
    بطاقاتُ الأطراف — «من» و«إلى» جنبًا إلى جنب.

    والعنوانُ الصغير فوق كلٍّ منها (`eyebrow`) هو ما يفصلهما، لا إطارٌ حول
    كلٍّ: إطاران متجاوران يجعلان الورقة نموذجًا يُملأ، والفراغُ يجعلها
    مستندًا يُقرأ.

    وتُرشَّح عند بنائها: طرفٌ بلا سطرٍ واحد لا يُطبع عنوانُه — وخانةٌ خاوية
    تحت «المورّد» تُقرأ حقلًا نُسي.

    المتغيّر: `$parties` قائمةُ `['cap' => ..., 'lines' => [...]]`.
--}}
@php
    $shown = array_values(array_filter($parties ?? [], fn ($p) => count(array_filter($p['lines'] ?? [])) > 0));
@endphp

@if (count($shown) > 0)
    <table class="parties">
        <tr>
            @foreach ($shown as $party)
                <td style="width:{{ (int) (100 / count($shown)) }}%">
                    <div class="eyebrow">{{ $party['cap'] }}</div>
                    @foreach (array_values(array_filter($party['lines'])) as $i => $l)
                        <div class="{{ $i === 0 ? 'b' : 'sm muted' }}">{{ $l }}</div>
                    @endforeach
                </td>
            @endforeach
        </tr>
    </table>
@endif

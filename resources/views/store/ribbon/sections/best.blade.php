{{--
    الأكثرُ مبيعًا — أو «مختاراتنا» إن اختار صاحبُ المحلّ الأصنافَ بيده.

    والعنوانُ يتبع مصدرَه: «الأكثر مبيعاً» دعوى عن البيع يقرؤها الزبون
    ويبني عليها ثقتَه. فصفٌّ رتّبه صاحبُ المحلّ ثمّ سُمّي بها كذبٌ عليه —
    وهو يظنّ أنّه رتّب واجهةً، لا أنّه وقّع على دعوى.
--}}
    @if (count($best))
    <div class="rb-section" data-testid="rb-sec-best">
        <div class="rb-section-head">
            <h2 class="rb-h2">{{ $bestPicked ? $t['pickedTitle'] : $t['bestTitle'] }}</h2>
            <a class="rb-link" href="{{ $base }}/shop">{{ $t['viewAll'] }}</a>
        </div>
        <div class="rb-grid" data-testid="rb-best">
            @foreach ($best as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    </div>
    @endif

{{--
    «اختيارات RIBBON» — أصنافٌ اختارها صاحبُ المحلّ بيده وبترتيبه.

    لا قسمَ أصنافٍ وراءها: معرّفاتُها لها (`Store\RibbonPicks`)، والصنفُ يبقى
    في قسمه. وشكلُها شكلُ «وصل حديثًا» نفسُه — فلا تخرج عن هويّة الصفحة.

    والعنوانُ بلغة الصفحة: ما كتبه لها، وإلّا اسمُ القسم في القالب. ولا
    يُرسم القسمُ بلا صنفٍ معروض.
--}}
    @if (count($picks))
    <div class="rb-section" data-testid="rb-sec-picks">
        <div class="rb-section-head">
            <h2 class="rb-h2">{{ $picksTitle }}</h2>
            <a class="rb-link" href="{{ $base }}/shop">{{ $t['viewAll'] }}</a>
        </div>
        <div class="rb-grid" data-testid="rb-picks">
            @foreach ($picks as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    </div>
    @endif

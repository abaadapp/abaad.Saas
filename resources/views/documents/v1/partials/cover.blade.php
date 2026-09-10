{{--
    غلافُ الورقة — صورةُ التاجر، أو شريطٌ بلونه، أو لا شيء.

    ═══ والحالاتُ ثلاثٌ لا اثنتان ═══

    • رفع غلافًا → صورةٌ بعرض الورقة وحوافَّ مُدوَّرة.
    • لم يرفع، وله لون → شريطٌ رفيعٌ بلونه. يقول «ورقةُ فلان» بلا أن يأكل
      ثلثَ الصفحة لأجل لونٍ واحد.
    • لم يرفع، وعلى اللون الافتراضيّ (حبرٌ لا لون) → **لا شيء**. وهذا هو
      الصواب: شريطٌ أسودُ فوق كلّ ورقةٍ لمتجرٍ لم يختر شيئًا ليس هويّةً،
      وإنما حبرٌ يُطبع بلا سبب. والورقةُ بلا غلافٍ يجب أن تكون أبسطَ لا
      أسوأ — وهو شرطٌ صريحٌ في المواصفة.

    و`object-fit` لا يعرفها mpdf: الصورةُ تُوضع خلفيّةً بـ`background-size:
    cover` — وهي مدعومةٌ فيه — فتملأ الإطار بلا أن تنسحب أبعادُها.
--}}
@php
    $cover = $coverImage ?? null;
    $hasCover = is_string($cover) && $cover !== '';
    /*
        و«له لون» يعني أنّه اختار غيرَ الافتراضيّ.

        والمقارنةُ بالافتراضيّ لا بالفراغ: `Theme::normalize` تردّ لونًا
        دائمًا، فلا يكون فارغًا أبدًا. ومن ترك الحبرَ كما هو لم يختر.
    */
    $chose = ($t['primary'] ?? '') !== \App\Support\Document\Theme::DEFAULTS['primary'];
@endphp

@if ($hasCover)
    <div class="cover" style="background-image: url('{{ $cover }}'); background-size: cover; background-position: center;">
        {{-- والخليّةُ تحمل ارتفاعَها: mpdf لا يمنح الخلفيّةَ ارتفاعًا من العدم --}}
        <div style="height:{{ round(\App\Support\Document\Theme::GEOMETRY['cover_height'], 2) }}pt;"></div>
    </div>
@elseif ($chose)
    <div class="cover-plain"></div>
@endif

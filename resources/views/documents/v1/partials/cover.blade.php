{{--
    غلافُ الورقة — صورةُ التاجر، أو شريطٌ بلونه، أو لا شيء.

    ═══ والحالاتُ ثلاثٌ لا اثنتان ═══

    • رفع غلافًا → صورةٌ بعرض الورقة وحوافَّ مُدوَّرة.
    • لم يرفع → **لا شيء**. والخيطُ أعلى الورقة يحمل لونَه، وهو كلُّ ما
      يحتمله المرجعُ المعتمد من لونٍ خارج الشعار — انظر `.brandrule` في
      `partials/tokens`. وشريطٌ ثانٍ بلونه فوقه يجعل اللونَ مساحةً بعد أن
      أردناه لمسة.

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
@endif

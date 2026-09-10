{{--
    موقعُ التاجر — الصفحة التي يفتحها زبونه.

    والرأسُ يُرسم هنا في الخادم، والجسدُ في المتصفّح بطبقة الرسم المشتركة
    (`resources/js/site.tsx`). وهذا التقسيم مقصود:

    ١) رابطٌ يُشارَك في واتساب أو إنستغرام لا يُنفّذ JavaScript قبل أن يرسم
       بطاقتَه. فما لا يكن في `<head>` لا يظهر في البطاقة — ويُشارَك رابطُ
       متجرٍ بلا اسمٍ ولا صورة.

    ٢) والجسدُ لا يُنسخ إلى Blade: طبقة الرسم سبعةَ عشرَ نوعَ قسمٍ في نحو
       ألفَي سطر، ونسخةٌ ثانية بلغةٍ أخرى تفترق عند أوّل إصلاح — فيرى التاجر
       في معاينته غير ما يرى زبونُه. وهو ما وُضع له `RendererParityTest`.

    ═══ وما يبقى ناقصًا، ويُقال ═══

    الجسدُ يُرسم في المتصفّح، فمحرّكُ البحث يقرأ الرأسَ و`<noscript>` ولا
    يقرأ الصفحة مرسومة. وتمامُها أن تُرسم في الخادم — ويلزمها Node، وهو
    مثبَّتٌ على الخادم فعلًا (يبني الأصول عند كلّ نشر). انظر خطّة الترقية
    في `Storefront/docs/DEPLOY.md`.
--}}
@php
    $dir = $doc['dir'] ?? 'rtl';
    $locale = $doc['locale'] ?? 'ar';
    $desc = \Illuminate\Support\Str::limit($head['description'] ?: $head['title'], 155);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>{{ $head['title'] }}</title>
    <meta name="description" content="{{ $desc }}">

    {{-- الأصل هو النطاق الفرعيّ: لا يقرأ محرّكُ البحث نسختين لصفحةٍ واحدة --}}
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endif

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $head['title'] }}">
    <meta property="og:description" content="{{ $desc }}">
    @if ($head['image'])
        <meta property="og:image" content="{{ $head['image'] }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif

    {{-- ولونُ المتصفّح لونُ المتجر: الشريطُ فوق الصفحة في الهاتف يتبعه --}}
    @if (! empty($doc['theme']['primary']))
        <meta name="theme-color" content="{{ $doc['theme']['primary'] }}">
    @endif

    {{-- والاتّصالُ بغوغل يُسخَّن مبكّرًا: الخطُّ يُطلب من `site.tsx` بعد
         القراءة، فتوفيرُ المصافحة هنا يقصّر انتظارَه --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    @vite(['resources/js/site.tsx'])
</head>
<body>
    {{--
        نصُّ الموقع **داخل الحاوية** لا في `<noscript>`.

        و`<noscript>` لا يظهر إلا لمن أطفأ JavaScript — أمّا من كان عنده
        يعمل ثمّ انهار التركيب (شبكةٌ قطعت الحزمة، متصفّحٌ قديم) فيبقى أمام
        صندوقٍ أبيض ولا شيء يقول له ما جرى.

        وما هنا يُقرأ في الحالات الثلاث: يراه من لا JavaScript عنده، ويقرؤه
        من يزحف قبل أن يُنفَّذ، ويبقى لمن انهار عنده. فإن ركّب الرسمُ نفسه
        محاه `createRoot` — فهو يفرّغ الحاوية عند أوّل رسم.

        والنصّ مستخرَجٌ من الكتالوج لا من أسماءٍ مكتوبة — انظر
        `Published::outline`.
    --}}
    <div id="site">
        <main style="max-width:42rem;margin:0 auto;padding:2rem 1.25rem;font:16px/1.7 system-ui,sans-serif">
            <h1>{{ $head['title'] }}</h1>
            @if ($desc !== '')
                <p>{{ $desc }}</p>
            @endif
            {{-- وما قيل في العنوان أو الوصف لا يُعاد: أوّلُ سطرٍ في الموقع
                 هو عنوانُ صدارته غالبًا، وهو نفسه ما بُني منه الوصف --}}
            @foreach ($outline as $line)
                @if ($line !== $head['title'] && $line !== $head['description'])
                    <p>{{ $line }}</p>
                @endif
            @endforeach
        </main>
    </div>

    {{--
        اللقطةُ تُمرَّر في وسمٍ لا في متغيّرٍ عامّ.

        `application/json` لا يُنفَّذه المتصفّح، فمحتواه نصٌّ مهما كان —
        بخلاف `<script>window.doc = {!! … !!}` حيث حرفٌ في بيانات التاجر
        يُغلق الوسم ويصير ما بعده شيفرةً تُنفَّذ.

        و`JSON_HEX_TAG` تُغلق البابَ الباقي: النوعُ وحده لا يمنع أنّ تاجرًا
        كتب `</script>` في نبذة متجره يُنهي الوسمَ من داخله. فتُرمَّز `<`
        و`>` سداسيًّا، ويقرؤها `JSON.parse` كما كُتبت.
    --}}
    <script id="site-doc" type="application/json">@json($doc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)</script>
</body>
</html>

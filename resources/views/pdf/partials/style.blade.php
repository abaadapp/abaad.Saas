{{--
    نظامُ تصميم الورق — أنماطُ كلّ ورقةٍ في النظام، في ملفٍّ واحد.

    كان لكلّ قالبٍ من اثنين وعشرين كتلةُ `<style>` خاصّة به: هذا يكتب
    `color:#111` وذاك `#1f2937`، وهذا يجعل الترويسة سوداء وذاك بنفسجية،
    وهذا يسمّي الخطّ `sans-serif` وذاك `dejavusans` — **وهو خطٌّ بلا حرفٍ
    عربيّ واحد**. فتخرج من النظام الواحد أوراقٌ لا يجمعها شكل، والتاجر
    يرسلها كلَّها باسمه.

    والقياساتُ بالنقطة (pt) لا بالبكسل: الورقة مقاسُها بالمليمتر، والبكسل
    في mpdf يُحوَّل بمعامل شاشةٍ لا معنى له على ورق. و١٠pt نصٌّ يُقرأ
    مطبوعًا، و٧pt أصغرُ ما يُقرأ.

    و`$scale` معاملُ التاجر من «قوالب الأوراق»: من اختار «كبير» يكبر عنده
    نصُّ الورقة كلِّه — لا سطرُ الجسد وحده فيصير الجدول أصغر من حوله.
--}}
@php
    $pt = fn (float $size) => round($size * ($scale ?? 1.0), 2) . 'pt';
@endphp
<style>
    * { font-family: xbriyaz, sans-serif; }

    body {
        direction: rtl;
        text-align: right;
        color: #111;
        font-size: {{ $pt(10) }};
        line-height: 1.45;
    }

    .muted { color: #6b7280; }
    .faint { color: #9ca3af; }
    .small { font-size: {{ $pt(8.5) }}; }
    .tiny  { font-size: {{ $pt(7.5) }}; }
    .b     { font-weight: bold; }
    .c     { text-align: center; }
    /* المبالغ إلى اليسار دائمًا، ولا تنكسر: مبلغٌ ومعه رمزُ العملة كان
       يُقسَم سطرين في عمودٍ ضيّق فيُقرأ رقمين. ولا مثالَ هنا برمز العملة
       نفسه: الحارس يقرأ الورقة نصًّا خامًا، فيجد في تعليقٍ ما أطفأه
       التاجر — انظر DocumentTemplatesTest */
    .amt   { text-align: left; white-space: nowrap; direction: ltr; }
    .num   { text-align: center; direction: ltr; }

    /* ————— الترويسة ————— */
    .p-head { width: 100%; border-bottom: 1.6pt solid #111; padding-bottom: 7pt; margin-bottom: 12pt; }
    .p-head td { vertical-align: top; border: none; padding: 0; }
    .p-brand { font-size: {{ $pt(15) }}; font-weight: bold; color: #111; margin-bottom: 1pt; }
    .p-title { font-size: {{ $pt(12) }}; font-weight: bold; }
    .p-meta  { margin-top: 4pt; font-size: {{ $pt(8.5) }}; color: #6b7280; }
    .p-meta .k { color: #9ca3af; }

    /* ————— الجداول ————— */
    table.grid { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; }
    table.grid th {
        background: #f5f5f4; color: #111; text-align: right;
        padding: 5pt 6pt; font-size: {{ $pt(8.5) }}; font-weight: bold;
        border-bottom: 0.8pt solid #d4d4d4;
    }
    table.grid td { padding: 5pt 6pt; font-size: {{ $pt(9) }}; border-bottom: 0.4pt solid #f0f0ef; }
    /* الصفُّ لا ينكسر بين صفحتين: سطرٌ نصفُه هنا ونصفُه هناك يُقرأ مرّتين */
    table.grid tr { page-break-inside: avoid; }
    table.grid thead { display: table-header-group; }
    table.grid tfoot td { border-top: 1.2pt solid #111; border-bottom: none; font-weight: bold; padding-top: 6pt; }
    table.grid td.empty { text-align: center; color: #9ca3af; padding: 14pt 6pt; }

    /* ————— عنوانٌ داخل الورقة ————— */
    h2 { font-size: {{ $pt(11) }}; margin: 14pt 0 5pt; padding-right: 6pt; border-right: 2.4pt solid #111; }

    /* ————— بطاقات المؤشّرات ————— */
    table.cards { width: 100%; border-collapse: separate; border-spacing: 4pt; margin: 2pt -4pt 8pt; }
    table.cards td { border: 0.4pt solid #e5e7eb; background: #fafafa; padding: 6pt 7pt; vertical-align: top; }
    table.cards .lbl { color: #6b7280; font-size: {{ $pt(8) }}; }
    table.cards .val { font-size: {{ $pt(12) }}; font-weight: bold; color: #111; margin-top: 2pt; }

    /* ————— شريطٌ نسبيّ في التقارير ————— */
    .barwrap { background: #f0f0ef; height: 8pt; width: 100%; }
    .bar { background: #111; height: 8pt; }
    .bar-2 { background: #9ca3af; }

    /* ————— الدخلُ والمصروف ————— */
    /* اللونُ الوحيد في الورق كلِّه، وله سببه: عمودٌ فيه الداخلُ والخارج
       معًا يُقرأ بالخطأ حين يتشابهان — والرقم السالب لا يُكتب في دفتر. */
    .income  { color: #15803d; }
    .expense { color: #b91c1c; }

    /* شارةٌ صغيرة: حالةُ طلبٍ أو صفٍّ داخل خليّة */
    .pill {
        display: inline-block; padding: 1pt 5pt;
        background: #f5f5f4; border: 0.4pt solid #e5e7eb; color: #374151;
    }

    /* ————— كتلٌ متفرّقة ————— */
    .note { border: 0.4pt dashed #d4d4d4; padding: 6pt 8pt; margin-top: 10pt; }
    .sign { width: 100%; margin-top: 26pt; }
    .sign td { width: 50%; padding-top: 20pt; border: none; }
    .sign .rule { border-top: 0.6pt solid #9ca3af; padding-top: 3pt; }
    .p-foot { margin-top: 18pt; border-top: 0.4pt solid #e5e7eb; padding-top: 6pt; }
</style>

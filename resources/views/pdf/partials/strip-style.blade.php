{{--
    أنماطُ الشريط الحراريّ — ورقٌ بعرض ٥٨ أو ٨٠ مليمترًا، لا صفحة.

    والقياسُ يتبع عرض الورق: شريطُ ٥٨ عرضُه المطبوع ٥٤ مم، أي ثلثا شريط
    ٨٠. فالخطُّ نفسُه عليه يجعل أربعةَ أعمدةٍ تتزاحم حتى ينكسر اسمُ الصنف
    ثلاثة أسطر، ويخرج إيصالٌ طولُه ضِعفُ ما يلزم. والمعامل يقع هنا لا في
    الكود: القالب هو من يعرف كم عمودًا يرسم.

    ويضربه معامل التاجر من «قوالب الأوراق» — فمن اختار «كبير» يكبر عنده
    على أيّ عرضٍ كان.
--}}
@php
    $fit = ($width ?? 80) <= 60 ? 0.86 : (($width ?? 80) >= 100 ? 1.08 : 1.0);
    $pt = fn (float $base) => round($base * $fit * ($scale ?? 1.0), 2) . 'pt';
@endphp
<style>
    * { font-family: xbriyaz, sans-serif; }

    body {
        direction: rtl; text-align: right; color: #000;
        font-size: {{ $pt(8) }}; line-height: 1.35;
    }

    .c { text-align: center; }
    .l { text-align: left; direction: ltr; }
    .muted { color: #444; }
    .tiny { font-size: {{ $pt(6.6) }}; }

    .shop { font-size: {{ $pt(12) }}; font-weight: bold; margin: 0 0 1pt; }

    /* الفاصلُ خطٌّ متقطّع: الطابعة الحرارية ترسم الخطّ المصمت شريطًا
       أسود يشرب الحبر ويبهت بسرعة */
    .rule { border-top: 0.5pt dashed #555; margin: 4pt 0; }

    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 0; }

    .kv td { padding: 0.6pt 0; font-size: {{ $pt(7.4) }}; }
    .kv .k { color: #444; }

    .items th {
        text-align: right; font-size: {{ $pt(7) }};
        border-bottom: 0.6pt solid #000; padding: 2pt 0;
    }
    .items td { font-size: {{ $pt(7.4) }}; padding: 2pt 0; border-bottom: 0.4pt dotted #999; }
    /* الصنفُ لا يُقصّ: طابعةٌ بلا صفحات، فالسطر يطول ولا ينكسر بينها */
    .items tr { page-break-inside: avoid; }

    .tot td { padding: 1pt 0; font-size: {{ $pt(7.6) }}; }
    .tot .k { color: #333; }
    .tot .grand td { font-size: {{ $pt(11) }}; font-weight: bold; border-top: 0.7pt solid #000; padding-top: 3pt; }

    .qr { margin: 4pt 0 2pt; }
    .qr .cap { font-size: {{ $pt(6.4) }}; color: #444; margin-top: 1pt; }
</style>

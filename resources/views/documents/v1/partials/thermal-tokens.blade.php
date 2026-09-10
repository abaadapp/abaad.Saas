{{--
    رموزُ الشريط الحراريّ — ورقٌ بعرض ٥٨ أو ٨٠ مليمترًا، لا صفحة.

    ═══ ولمَ طبقةٌ ثانية لا القالبُ الأوّل مُصغَّرًا ═══

    الطابعةُ الحرارية ليست طابعةً صغيرة: هي جهازٌ آخر.

    • **لا تعرف الصفحات** — الورقُ شريطٌ يخرج ويُقصّ. فلا ترقيمَ ولا
      ترويسةٌ تتكرّر ولا `page-break`.
    • **لا تطبع رماديًّا** — تُسخّن الورقَ أو لا تسخّنه. فرأسُ جدولٍ بخلفيّة
      `#f7f7f7` يخرج أبيضَ تمامًا، وخلفيّةٌ بلون التاجر تخرج **مربّعًا
      أسود** يشرب الحرارة ويبهت خلال أسابيع.
    • **لا تطبع الحوافّ المُدوَّرة** بأيّ معنى مفيد على ٥٤ مم مطبوعة.
    • **والخطُّ المتقطّع أفضلُ من المصمت**: الخطُّ المصمت شريطٌ أسودُ يشرب
      الحبر.

    فلونُ التاجر هنا يقع في **موضعٍ واحد**: خطُّ الإجمالي. وذاك خطٌّ رفيع
    يخرج على الطابعة الملوّنة لونًا وعلى الحرارية سوادًا — وكلاهما صحيح.

    والقياسُ يتبع عرض الورق: شريطُ ٥٨ عرضُه المطبوع ٥٤ مم، أي ثلثا شريط
    ٨٠. فالخطُّ نفسُه عليه يجعل أربعةَ أعمدةٍ تتزاحم حتى ينكسر اسمُ الصنف
    ثلاثة أسطر، ويخرج إيصالٌ طولُه ضِعفُ ما يلزم.
--}}
@php
    $t = $tokens;
    $width = $width ?? 80;
    $fit = $width <= 60 ? 0.86 : ($width >= 100 ? 1.08 : 1.0);
    $pt = fn (float $base) => round($base * $fit * ((float) ($t['scale'] ?? 1.0)), 2) . 'pt';
@endphp
<style>
    * { font-family: xbriyaz, 'IBM Plex Sans Arabic', sans-serif; }

    body {
        direction: rtl; text-align: right; color: #000;
        font-size: {{ $pt(8) }}; line-height: 1.35;
    }

    .c { text-align: center; }
    .l { text-align: left; direction: ltr; }
    .b { font-weight: bold; }
    .muted { color: #444; }
    .tiny { font-size: {{ $pt(6.6) }}; }

    .shop { font-size: {{ $pt(12.5) }}; font-weight: bold; margin: 0 0 1pt; }
    .doctype {
        font-size: {{ $pt(7.2) }}; letter-spacing: 0.08em;
        color: #333; margin-bottom: 2pt;
    }

    /* الفاصلُ متقطّع — انظر رأس الملفّ */
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
    /*
        والإجماليُّ يحمل لونَ التاجر في خطٍّ رفيعٍ فوقه — وحدَه في الإيصال.
        الطابعةُ الحرارية تُخرجه سوادًا، والملوّنةُ تُخرجه لونًا، وكلاهما
        يفصل الإجماليَّ عمّا فوقه وهو المقصود.
    */
    .tot .grand td {
        font-size: {{ $pt(11.5) }}; font-weight: bold;
        border-top: 0.9pt solid {{ $t['primary_ink'] }};
        padding-top: 3pt;
    }

    .qr-block { margin: 4pt 0 2pt; }
    .qr-cap { font-size: {{ $pt(6.4) }}; color: #444; margin-top: 1pt; line-height: 1.3; }
</style>

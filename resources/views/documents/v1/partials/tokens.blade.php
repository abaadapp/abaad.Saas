{{--
    رموزُ التصميم — الطبقةُ التي تجعل أوراقَ النظام ورقةً واحدة.

    ═══ ولمَ تُكتب القيمةُ مرّتين ═══

    الورقةُ تُقرأ في محرّكين: mpdf ليخرج الـPDF، والمتصفّح لتُرى في المعاينة
    وتُرسَل رابطًا. والمتصفّحُ يفهم `var(--document-primary)`، وmpdf **لا
    يفهمها** — يتجاهل الإعلان صامتًا فتخرج الورقة بلا لون، ولا خطأ يقول لماذا.

    فكلُّ قاعدةٍ تُكتب مرّتين: `var()` أوّلًا للمتصفّح، ثمّ القيمةُ الحرفيّة
    بعدها. والأخيرةُ تغلب في المحرّكين معًا — فالشكلُ واحدٌ فيهما لا شكلان،
    وهو شرطُ «HTML هو المصدر البصريّ والـPDF نسخةٌ منه».

    ═══ والقياسُ بالنقطة لا بالبكسل ═══

    الورقةُ مقاسُها بالمليمتر، والبكسل في mpdf يُحوَّل بمعامل شاشةٍ لا معنى
    له على ورق. و`$s()` تضرب كلَّ مقاسٍ بمعامل الخطّ الذي اختاره التاجر —
    فتكبر الورقةُ معًا، لا سطرُ الجسد وحده فيصير الجدولُ أصغر ممّا حوله.
--}}
@php
    use App\Support\Document\PaperSize;
    use App\Support\Document\Theme;

    $t = $tokens;
    /* مقاسُ الورقة — من السجلّ نفسِه الذي يُبنى به المحرّك */
    $size = PaperSize::of($paper ?? PaperSize::A4);
    $scale = (float) ($t['scale'] ?? 1.0);

    /** مقاسٌ بالنقطة، مضروبًا بمعامل التاجر */
    $s = fn (float $size) => round($size * $scale, 2) . 'pt';

    /** مقاسٌ لا يتبع الخطّ — الهوامشُ والحوافّ لا تكبر بكبر النصّ */
    $fx = fn (float $size) => round($size, 2) . 'pt';

    /*
        واتّجاهُ الورقة من مصدرٍ واحد.

        وmpdf لا يعرف `margin-inline-start` ولا `text-align: start`، فتُحسب
        الجهةُ هنا مرّةً وتُكتب فيزيائيّةً. وهو ما يجعل القالبَ واحدًا
        للغتين بلا نسخةٍ ثانية — الحسابُ في PHP لا الشرطُ في كلّ قاعدة.
    */
    $rtl = \App\Support\Paper::rtl();
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
@endphp
<style>
    :root {
{!! Theme::css($t) !!}
    }

{!! PaperSize::css($size) !!}

    /*
        و`border-box` على كلّ شيء — لا على الصندوق وحده.

        بلا ذلك يُضاف الحشوُ إلى العرض المصرَّح به: عمودٌ عرضُه ٢٠٪ وحشوُه
        ٧pt يخرج أعرضَ من خُمس الورقة، فتتجاوز الأعمدةُ مجتمعةً حدَّها
        ويُقلّص المحرّكُ الجدولَ ليُلائمه — وهو تصغيرٌ لا يطلبه أحد.
    */
@include('documents.v1.partials.webfont')

    {{--
        وخطُّ الورقة واحدٌ في المحرّكين — وطريقُه إلى كلٍّ منهما غيرُ الآخر.

        هذا السطرُ للمتصفّح: `'IBM Plex Sans Arabic'` هو اسمُ الأسرة في
        `@font-face` أعلاه. وmpdf **لا يقرأ محدّدَ `*` أصلًا** — قِسناه:
        بُدّل الاسمُ هنا إلى خطٍّ آخر فخرج الـPDF على حاله. خطُّه يصله من
        `default_font` في `MpdfDriver::base`.

        و`ibmplexsansarabic` بلا فراغ أوّلًا احتياطًا: اسمُ الأسرة في سجلّ
        mpdf لا يحتمل فراغًا، فلو قرأ المحدّدَ يومًا وجد ما يعرفه. والمتصفّحُ
        يتخطّاه — ليس خطًّا عنده — إلى الاسم بعده.

        والملفّان اللذان يقرؤهما المحرّك مبنيّان من مقاطع المتصفّح نفسِها —
        `scripts/build-document-font.py` — فالحروفُ ذاتُها بالمقاسات ذاتها
        هنا وهناك.
    --}}
    * { font-family: ibmplexsansarabic, 'IBM Plex Sans Arabic', sans-serif; box-sizing: border-box; }

    body {
        /* ولا هامشَ افتراضيًّا من المتصفّح: ٨ بكسل تُزيح الورقة عن حدّها */
        margin: 0;
        padding: 0;
        direction: {{ $rtl ? 'rtl' : 'ltr' }};
        text-align: {{ $start }};
        color: var(--document-text); color: {{ $t['text'] }};
        background: var(--document-background); background: {{ $t['background'] }};
        font-size: {{ $s(Theme::GEOMETRY['text_base']) }};
        line-height: 1.55;
    }

    /* ————————————————— النصّ ————————————————— */

    .muted { color: var(--document-muted); color: {{ $t['muted'] }}; }
    .faint { color: var(--document-faint); color: {{ $t['faint'] }}; }
    .brandink { color: var(--document-primary-ink); color: {{ $t['primary_ink'] }}; }

    .xs { font-size: {{ $s(Theme::GEOMETRY['text_xs']) }}; }
    .sm { font-size: {{ $s(Theme::GEOMETRY['text_sm']) }}; }
    .md { font-size: {{ $s(Theme::GEOMETRY['text_md']) }}; }
    .lg { font-size: {{ $s(Theme::GEOMETRY['text_lg']) }}; }
    .b  { font-weight: bold; }
    .c  { text-align: center; }

    /*
        العنوانُ الصغير فوق كلّ كتلة — حروفٌ متباعدة ومقاسٌ صغير.

        وهو ما يحمل التسلسلَ البصريّ بدل الخطوط الثقيلة: القارئ يعرف أين
        هو من الورقة بالمقاس والفراغ، لا بإطارٍ حول كلّ شيء.
    */
    .eyebrow {
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        color: var(--document-faint); color: {{ $t['faint'] }};
        letter-spacing: 0.06em;
        margin-bottom: {{ $fx(2.5) }};
    }

    /*
        المبالغُ إلى الطرف المقابل دائمًا، ولا تنكسر.

        مبلغٌ ومعه رمزُ العملة كان يُقسَم سطرين في عمودٍ ضيّق فيُقرأ رقمين.
        و`direction: ltr` لأنّ الرقم يُقرأ من اليسار في اللغتين معًا.
    */
    .amt { text-align: {{ $end }}; white-space: nowrap; direction: ltr; }
    .num { text-align: center; direction: ltr; }
    /*
        وكلُّ ما يُقرأ من اليسار داخل سطرٍ عربيّ يُعزَل.

        رقمُ فاتورةٍ أو آيبانٌ أو بريدٌ داخل نصٍّ عربيّ يتبع اتّجاهَ السطر لا
        اتّجاهَ نفسِه، فينقلب طرفاه. و`unicode-bidi: isolate` تفصله عمّا حوله
        فيُقرأ كما كُتب — انظر ReceiptTemplate::printableHtml للسطور الحرّة.
    */
    .ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }
    {{--
        ونصُّ البشر: اتّجاهُه من لغته، ومحاذاتُه من الورقة.

        و`dir` يحملهما معًا — فسمةُ `rtl` على اسم صنفٍ عربيٍّ داخل ورقةٍ
        إنجليزيّة تصحّح ترتيبَ حروفه **وتزيحه إلى يمين خليّته**، فيخرج
        عمودُ الأصناف مبعثرًا: عربيُّه يمينًا وإنجليزيُّه يسارًا في جدولٍ
        واحد.

        فتُفصل المحاذاة: تتبع الورقةَ دائمًا، ويبقى لـ`dir` ترتيبُ الحروف
        وحدَه — وهو ما أردناه منه.
    --}}
    .bidi { text-align: {{ $start }}; }

    /* ————————————————— الغلاف والترويسة ————————————————— */

    .cover {
        width: 100%;
        height: {{ $fx(Theme::GEOMETRY['cover_height']) }};
        border-radius: var(--document-radius-lg); border-radius: {{ $fx($t['radius_lg']) }};
        overflow: hidden;
        margin-bottom: {{ $fx(14) }};
    }

    /*
        وبلا صورةٍ لا تخرج الورقة فارغة.

        شريطٌ بلون التاجر أنحفُ من الصورة بكثير: يقول «هذه ورقةُ فلان» ولا
        يأكل ثلثَ الصفحة لأجل لونٍ واحد. ومتجرٌ لم يرفع غلافًا لا يجب أن
        تكون ورقتُه أسوأ — يجب أن تكون **أبسط**.
    */
    .cover-plain {
        width: 100%;
        height: {{ $fx(10) }};
        border-radius: var(--document-radius); border-radius: {{ $fx($t['radius']) }};
        background: var(--document-primary); background: {{ $t['primary'] }};
        margin-bottom: {{ $fx(14) }};
    }

    .head { width: 100%; margin-bottom: {{ $fx(Theme::GEOMETRY['section_gap'] + 4) }}; }
    .head td { vertical-align: top; border: none; padding: 0; }

    .doctype {
        font-size: {{ $s(Theme::GEOMETRY['text_xl']) }};
        font-weight: bold;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
        line-height: 1.2;
    }

    .docnum {
        font-size: {{ $s(Theme::GEOMETRY['text_md']) }};
        direction: ltr; unicode-bidi: isolate;
        margin-top: {{ $fx(1) }};
    }

    .shop { font-size: {{ $s(Theme::GEOMETRY['text_md']) }}; font-weight: bold; }

    /* ————————————————— بطاقاتُ الأطراف ————————————————— */

    /*
        الأطرافُ في جدولٍ بلا حدود — لا في `flex`.

        mpdf لا يعرف `flexbox`، وجدولٌ بخليّتين يفعل ما يُراد هنا تمامًا:
        عمودان متساويان يعلوان معًا. والحدودُ منزوعة، فالفصلُ بالفراغ.
    */
    .parties { width: 100%; margin-bottom: {{ $fx(Theme::GEOMETRY['section_gap']) }}; }
    .parties td {
        vertical-align: top; border: none;
        padding: {{ $fx(0) }} {{ $fx(10) }} {{ $fx(0) }} 0;
    }

    /* سطورُ التعريف: مفتاحٌ خافتٌ وقيمةٌ بعده */
    {{--
        شريطُ التعريف — أعمدةٌ متجاورة، عنوانٌ صغيرٌ فوق قيمته.

        وكان جدولًا بعمودين وعنوانُه بعرض ٣٤٪، فيبقى نصفُ كلّ سطرٍ خاليًا:
        منطقةٌ ميّتةٌ بعرض راحةِ اليد بين التاريخ وجدول الأصناف.

        و`table-layout: fixed` لأنّ العرض معلَنٌ في الخليّة: بدونه يوسّع
        المحرّكُ العمودَ لأطول قيمةٍ فيه، فيعيد الشريطُ ترتيبَ نفسه كلّما
        طال اسمُ موظّف.
    --}}
    table.metastrip {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin-bottom: {{ $fx(Theme::GEOMETRY['section_gap']) }};
    }
    table.metastrip td {
        border: none;
        padding: {{ $fx(0) }} {{ $fx(9) }} {{ $fx(0) }} 0;
        vertical-align: top;
    }
    table.metastrip tr + tr td { padding-top: {{ $fx(7) }}; }

    /* ————————————————— جدول الأصناف ————————————————— */

    /*
        لا حدودَ حول الخلايا — سطرٌ رفيعٌ تحت كلٍّ منها فقط.

        الجدولُ المُشبَّك يجعل العينَ تتوقّف عند كلّ خليّة. والفواتيرُ التي
        تُحتذى تكتفي بخطٍّ رفيعٍ يفصل الصفوف: القراءةُ تمشي أفقيًّا فلا
        تحتاج جدرانًا رأسيّة.
    */
    table.items { width: 100%; border-collapse: collapse; margin-bottom: {{ $fx(12) }}; }

    table.items th.num { text-align: center; }
    table.items th.amt { text-align: {{ $end }}; }
    table.items th {
        text-align: {{ $start }};
        padding: {{ $fx(6) }} {{ $fx(7) }};
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        font-weight: bold;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
        background: var(--document-primary-wash); background: {{ $t['primary_wash'] }};
        border-bottom: {{ $fx(0.8) }} solid {{ $t['primary_edge'] }};
        letter-spacing: 0.04em;
    }

    /* والحافّةُ مُدوَّرة عند طرفَي الرأس — لمسةٌ واحدة تكفي */
    table.items th:first-child {
        border-top-{{ $start }}-radius: {{ $fx($t['radius']) }};
        border-bottom-{{ $start }}-radius: 0;
    }
    table.items th:last-child {
        border-top-{{ $end }}-radius: {{ $fx($t['radius']) }};
        border-bottom-{{ $end }}-radius: 0;
    }

    table.items td {
        padding: {{ $fx(7) }};
        font-size: {{ $s(Theme::GEOMETRY['text_base']) }};
        border-bottom: {{ $fx(0.4) }} solid {{ $t['rule'] }};
        vertical-align: top;
        {{--
            ورمزُ صنفٍ لا فراغَ فيه يُكسَر بدل أن يخرج.

            `SKU-ABAAD-2026-XL-0099` أو آيبانٌ بأربعة وعشرين حرفًا كلمةٌ
            واحدةٌ عند المحرّك: لا موضعَ كسرٍ فيها، فتمتدّ خارج عمودها
            وتُقصّ عند حافّة الورقة.
        --}}
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    /*
        الصفُّ لا ينكسر بين صفحتين، والرأسُ يتكرّر على كلّ صفحة.

        سطرٌ نصفُه هنا ونصفُه هناك يُقرأ مرّتين، وجدولٌ يمتدّ ثلاثَ صفحاتٍ
        بلا رؤوسِ أعمدةٍ في الثانية والثالثة يُقرأ بالتخمين: أيُّ عمودٍ
        الكميّةُ وأيُّها السعر.
    */
    table.items tr { page-break-inside: avoid; }
    table.items thead { display: table-header-group; }
    table.items tfoot { display: table-footer-group; }

    table.items td.empty {
        text-align: center;
        color: var(--document-faint); color: {{ $t['faint'] }};
        padding: {{ $fx(18) }} {{ $fx(7) }};
    }

    /* ————————————————— الإجماليّات ————————————————— */

    /*
        كتلةُ الإجماليّات لا تنكسر بين صفحتين.

        «الإجمالي» في صفحةٍ ومفرداتُه في أخرى يجعل من يراجع الورقة يعود
        صفحةً ليعرف ممّ تكوّن المبلغ. وهي كتلةٌ قصيرةٌ دائمًا، فحجزُها
        كاملةً لا يُضيّع صفحة.
    */
    .totals-wrap { width: 100%; page-break-inside: avoid; }
    .totals-wrap > tbody > tr > td { border: none; padding: 0; vertical-align: top; }

    table.totals { width: 100%; border-collapse: collapse; }
    table.totals td {
        padding: {{ $fx(3.5) }} {{ $fx(7) }};
        font-size: {{ $s(Theme::GEOMETRY['text_base']) }};
        border: none;
    }
    table.totals td.k { color: var(--document-muted); color: {{ $t['muted'] }}; }
    {{--
        وتلميحُ النسبة «(٥٪)» يبقى في سطر عنوانه.

        عمودُ العناوين ضيّق، و«ضريبة القيمة المضافة (٥٪)» يتجاوزه فينكسر
        التلميحُ سطرًا ثانيًا تحت العنوان — فيطول الصفُّ ويختلّ إيقاعُ
        الكتلة. والتلميحُ ثلاثةُ محارف: يُمنع كسرُه ويُترك للعنوان أن ينكسر.
    --}}
    table.totals td .xs { white-space: nowrap; }
    {{--
        والجانبيُّ يحاذي أعلى الكتلة صراحةً.

        `.totals-wrap > tbody > tr > td` تضبطه، لكنّ mpdf لا يُعوَّل عليه
        في محدّد الابن المباشر عبر `tbody` ضمنيّ — فتسقط القاعدةُ صامتةً
        ويحاذي الوسطَ، فيهبط السطرُ إلى منتصف المجاميع بلا سبب ظاهر.
    --}}
    .totals-wrap td.aside { padding-top: {{ $fx(3.5) }}; vertical-align: top; }

    /*
        و«الإجمالي» أقوى عنصرٍ ماليٍّ في الورقة.

        مقاسٌ أكبر، ولونُ التاجر، وخلفيّةٌ فاتحةٌ منه، وحافّةٌ مُدوَّرة. وهو
        الرقمُ الذي يبحث عنه القارئ أوّلًا — وورقةٌ يتساوى فيها المجموعُ
        الفرعيُّ والإجماليُّ تجعله يقرأ الأربعةَ ليعرف أيُّها المطلوب.
    */
    table.totals tr.grand td {
        font-size: {{ $s(Theme::GEOMETRY['text_lg']) }};
        font-weight: bold;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
        background: var(--document-primary-wash); background: {{ $t['primary_wash'] }};
        padding-top: {{ $fx(7) }};
        padding-bottom: {{ $fx(7) }};
    }
    table.totals tr.grand td:first-child {
        border-top-{{ $start }}-radius: {{ $fx($t['radius']) }};
        border-bottom-{{ $start }}-radius: {{ $fx($t['radius']) }};
    }
    table.totals tr.grand td:last-child {
        border-top-{{ $end }}-radius: {{ $fx($t['radius']) }};
        border-bottom-{{ $end }}-radius: {{ $fx($t['radius']) }};
    }

    /* والباقي بعد السداد: ثاني ما يُبحث عنه في ورقةٍ لم تُسدَّد كاملةً */
    table.totals tr.due td {
        font-weight: bold;
        border-top: {{ $fx(0.4) }} solid {{ $t['border'] }};
        padding-top: {{ $fx(5) }};
    }

    /* ————————————————— كتلٌ متفرّقة ————————————————— */

    {{--
        الملاحظاتُ والشروط — عنوانٌ وخطٌّ جانبيّ، لا بطاقةٌ بإطارٍ وزوايا.

        إطارٌ مستديرٌ حول كلّ كتلةٍ يجعل الورقةَ شاشةَ لوحةٍ طُبعت. والخطُّ
        الرفيع في أوّل السطر يفصل الكتلةَ عمّا قبلها بالقدر نفسه ولا يرسم
        صندوقًا حولها.
    --}}
    .panel {
        border-{{ $start }}: {{ $fx(1.6) }} solid {{ $t['primary_edge'] }};
        padding-{{ $start }}: {{ $fx(9) }};
        margin-top: {{ $fx(14) }};
        page-break-inside: avoid;
    }

    .sign { width: 100%; margin-top: {{ $fx(26) }}; page-break-inside: avoid; }
    .sign td { width: 50%; padding-top: {{ $fx(22) }}; border: none; }
    .sign .rule {
        border-top: {{ $fx(0.6) }} solid {{ $t['border'] }};
        padding-top: {{ $fx(3) }};
        color: var(--document-faint); color: {{ $t['faint'] }};
    }

    /* والرمزُ لا يركب على الجدول: كتلةٌ محجوزةٌ كاملةً */
    .qr-block { margin-top: {{ $fx(14) }}; page-break-inside: avoid; }
    .qr-cap {
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        color: var(--document-faint); color: {{ $t['faint'] }};
        margin-top: {{ $fx(2) }};
        line-height: 1.35;
    }

    .foot {
        margin-top: {{ $fx(16) }};
        padding-top: {{ $fx(8) }};
        border-top: {{ $fx(0.4) }} solid {{ $t['border'] }};
        color: var(--document-muted); color: {{ $t['muted'] }};
        font-size: {{ $s(Theme::GEOMETRY['text_sm']) }};
        page-break-inside: avoid;
    }
</style>

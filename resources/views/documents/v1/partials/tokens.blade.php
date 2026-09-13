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

    ═══ وما تغيّر في هذه النسخة، ولماذا ═══

    كانت الورقةُ رماديّةً كلَّها: عنوانٌ أسود، وجدولٌ برأسٍ رماديٍّ باهت،
    وصندوقٌ رماديٌّ للإجمالي، ولا أثرَ للتاجر في شيء منها. تُطبع فتُقرأ
    «مستندًا خرج من قاعدة بيانات» لا ورقةَ شركة.

    فصار للورقة **هيكلٌ بصريّ**: مسطرةٌ بلون التاجر تفتحها، وترويسةٌ تحمل
    نوعَ المستند ورقمَه في رقعةٍ ملوّنة وحالتَه في خَتم، وبطاقتان للطرفين،
    وشريطُ تعريفٍ محدود، وجدولٌ برأسٍ مصمت، وإجماليٌّ في كتلةٍ بلون التاجر،
    وتذييلٌ يحمل الهويّةَ والرقمَ ورقمَ الصفحة.

    والألوانُ كلُّها مشتقّةٌ من لونٍ واحدٍ يختاره التاجر، ومحسوبةُ التباين
    في `Theme` — فورقةُ من لم يختر شيئًا تخرج بحبرٍ داكنٍ موقّر، لا باهتة.
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
        font-weight: bold;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
        letter-spacing: 0.07em;
        margin-bottom: {{ $fx(3.5) }};
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

    /*
        ومسطرةُ الترويسة — الخطُّ الذي تفتتح به الورقة.

        ═══ ولمَ تُطبع لمن لم يختر لونًا ═══

        الغلافُ زينةٌ: شريطٌ عريضٌ بلونٍ اختاره التاجر، ومن لم يختر لا
        يُطبع له. وهذه غيرُه — بنيةٌ لا زينة: هي ما يفصل رأسَ الورقة عن
        حافّة الصفحة، كما يفعل خطُّ الترويسة في كلّ ورقٍ رسميّ. وارتفاعُها
        ٢٫٥ نقطة: حبرٌ لا يُذكر مقابل ورقةٍ لها بداية.

        ومن لم يختر لونًا فلونُه الحبرُ الداكن الافتراضيّ — فتخرج مسطرةً
        موقّرة لا باهتة.
    */
    .brandrule {
        width: 100%;
        height: {{ $fx(2.5) }};
        background: var(--document-primary); background: {{ $t['primary'] }};
        margin-bottom: {{ $fx(13) }};
        font-size: 0;
        line-height: 0;
    }

    .head { width: 100%; table-layout: fixed; }
    .head td { vertical-align: top; border: none; padding: 0; }

    /*
        ورأسُ الورقة يُغلق بخطٍّ بلون التاجر لا بفراغ.

        الفراغُ وحده يترك الترويسةَ عائمةً فوق الأطراف بلا حدٍّ يفصلهما،
        فتُقرأ الورقةُ كتلةً واحدةً من أعلاها إلى جدولها. والخطُّ الرفيع
        يقول «انتهت الهويّة، بدأ المستند».
    */
    .headrule {
        border-bottom: {{ $fx(0.9) }} solid {{ $t['primary_edge'] }};
        margin: {{ $fx(11) }} 0 {{ $fx(Theme::GEOMETRY['section_gap']) }} 0;
        font-size: 0;
        line-height: 0;
    }

    .doctype {
        font-size: {{ $s(Theme::GEOMETRY['text_xl']) }};
        font-weight: bold;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
        line-height: 1.15;
        letter-spacing: -0.01em;
    }

    /*
        ورقمُ المستند في رقعةٍ مصمتة — لا سطرًا رماديًّا تحت العنوان.

        هو ما يُذكر في المكالمة ويُبحث عنه في البريد ويُكتب على الحوالة.
        ورقعةٌ بلون التاجر تجعله أوّلَ ما تقع عليه العين بعد نوع المستند،
        وتفصله عن التواريخ التي حوله فلا يُخلط برقمٍ آخر على الورقة.
    */
    {{--
        لوحةُ المستند — نوعُه ورقمُه وحالتُه في كتلةٍ واحدة.

        ═══ ولمَ لا تنكمش إلى محتواها ═══

        جُرّب الرقمُ رقعةً مصمتةً تنكمش، مرّةً بـ`display: inline-block`
        ومرّةً بجدولٍ داخليّ. فالأولى خرجت متراكبةً على عنوانها: mpdf يرسم
        الصندوقَ بحشوه ولا يوسّع صندوقَ السطر له. والثانيةُ سحقت أعمدةَ
        الترويسة كلَّها: جدولٌ بعرض ١٠٠٪ داخل خليّةٍ بعرض ٥٠٪ يُربك حسابَ
        العرض الأدنى عنده.

        وكلا العطبين لا يظهر في المتصفّح — يظهر في الـPDF وحده. وهو ما
        تعنيه المواصفةُ بـ«لا تفترض أن نجاح HTML يعني نجاح PDF».

        فاللوحةُ كتلةٌ بعرض عمودها: لا انكماشَ ولا تداخل، والرقمُ والخَتمُ
        يملآنها فيُقرآن شريطين تحت العنوان.
    --}}
    {{--
        والعرضُ مُصرَّحٌ به في كلّ كتلةٍ داخل خليّة.

        قِيس في الـPDF: mpdf يمدّ كتلةَ `div` عبر الورقة حين تقع في مجرى
        الجسد، ويُقلّصها إلى محتواها حين تقع **داخل خليّة جدول**. فبطاقةُ
        الطرف تخرج ملتصقةً باسم المورّد لا تملأ نصفَ الورقة، ولوحةُ المستند
        تخرج بعرض كلمتين. والمتصفّحُ يمدّ الاثنتين — فالعطبُ في الـPDF وحده.
    --}}
    .head td.idpanel {
        background: var(--document-primary-tint); background: {{ $t['primary_tint'] }};
        border-radius: {{ $fx($t['radius']) }};
        padding: {{ $fx(10) }} {{ $fx(11) }};
    }
    /* وخليّةٌ فاصلةٌ بلا محتوى: الفراغُ بين الكتلتين بلا `border-spacing` */
    .head td.gap { padding: 0; }

    .docnum {
        font-size: {{ $s(Theme::GEOMETRY['text_lg']) }};
        font-weight: bold;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
        direction: ltr;
        line-height: 1.4;
        margin-bottom: {{ $fx(6) }};
    }
    .docnum-cap {
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        color: var(--document-faint); color: {{ $t['faint'] }};
        letter-spacing: 0.06em;
        margin-top: {{ $fx(7) }};
    }

    /*
        والحالةُ خَتمٌ لا لون.

        الورقةُ تُطبع أبيضَ وأسودَ في أكثر المكاتب، فحالةٌ يحملها اللونُ
        وحده تُمحى عند أوّل طابعة. فالخَتمُ إطارٌ وكلمةٌ ومقاسٌ صغير: يُقرأ
        على الشاشة وعلى الورق الرماديّ سواء. انظر `partials/stamp`.
    */
    /*
        والخَتمُ وحدَه يُترك منكمشًا — والانكماشُ هنا هو المطلوب.

        رقعةٌ بحجم كلمتها تُقرأ خَتمًا، وشريطٌ بعرض اللوحة يُقرأ عنوانَ
        قسم. فما كان عطبًا في البطاقة صوابٌ فيه.
    */
    .stamp {
        margin-top: {{ $fx(5) }};
        padding: {{ $fx(3) }} {{ $fx(6) }};
        border: {{ $fx(0.9) }} solid {{ $t['primary_edge'] }};
        border-radius: {{ $fx(3) }};
        background: var(--document-background); background: {{ $t['background'] }};
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        font-weight: bold;
        letter-spacing: 0.08em;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
    }

    .shop {
        font-size: {{ $s(Theme::GEOMETRY['text_lg']) }};
        font-weight: bold;
        line-height: 1.25;
    }

    /* ————————————————— بطاقاتُ الأطراف ————————————————— */

    /*
        الأطرافُ في جدولٍ بلا حدود — لا في `flex`.

        mpdf لا يعرف `flexbox`، وجدولٌ بخليّتين يفعل ما يُراد هنا تمامًا:
        عمودان متساويان يعلوان معًا. والفصلُ بينهما فراغٌ لا خطّ.
    */
    table.parties { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: {{ $fx(Theme::GEOMETRY['section_gap']) }}; }
    table.parties td { vertical-align: top; border: none; padding: 0; }
    /* والفاصلُ خليّةٌ خاوية لا `border-spacing`: ذاك يُزيح الطرفين عن حدّ الورقة */
    table.parties td.gap { width: {{ $fx(14) }}; }

    /*
        وكلُّ طرفٍ في بطاقةٍ لها أرضيّةٌ وحافّةٌ من جهة البداية.

        ═══ ولمَ صارت بطاقةً بعد أن كانت سطورًا ═══

        سطورٌ رماديّةٌ متجاورةٌ بلا أرضيّة تُقرأ فقرةً واحدةً طويلة: عينُ
        القارئ لا تعرف أين ينتهي المورّد ويبدأ البائع إلّا بعدّ الأسطر.
        والأرضيّةُ الفاتحةُ ترسم الحدَّ بلا إطارٍ حول كلّ شيء — فتبقى
        الورقةُ مستندًا يُقرأ لا نموذجًا يُملأ.

        والحافّةُ من جهة البداية وحدها: خطٌّ واحدٌ بلون التاجر يربط
        البطاقتين بالمسطرة التي تعلو الورقة.
    */
    table.parties td.party {
        background: var(--document-primary-tint); background: {{ $t['primary_tint'] }};
        {{--
            والحافّةُ بحبر التاجر لا بحافّته الفاتحة.

            `primary_edge` عشرون بالمئة من اللون، وأرضيّةُ البطاقة اثنا
            عشر — فخطٌّ بينهما لا تفرّقه العين، ويختفي تمامًا في الطباعة
            الرماديّة. و`primary_ink` محسوبُ التباين على الورق الأبيض.
        --}}
        border-{{ $start }}: {{ $fx(2.5) }} solid {{ $t['primary_ink'] }};
        border-radius: {{ $fx($t['radius']) }};
        padding: {{ $fx(8) }} {{ $fx(10) }};
    }
    .party-name {
        font-size: {{ $s(Theme::GEOMETRY['text_md']) }};
        font-weight: bold;
        line-height: 1.35;
    }

    {{--
        شريطُ التعريف — أعمدةٌ متجاورة، عنوانٌ صغيرٌ فوق قيمته.

        وكان جدولًا بعمودين وعنوانُه بعرض ٣٤٪، فيبقى نصفُ كلّ سطرٍ خاليًا:
        منطقةٌ ميّتةٌ بعرض راحةِ اليد بين التاريخ وجدول الأصناف.

        و`table-layout: fixed` لأنّ العرض معلَنٌ في الخليّة: بدونه يوسّع
        المحرّكُ العمودَ لأطول قيمةٍ فيه، فيعيد الشريطُ ترتيبَ نفسه كلّما
        طال اسمُ موظّف.

        وصار له أرضيّةٌ وحدٌّ بين خلاياه: أربعةُ تواريخَ متجاورةٍ في فراغٍ
        أبيض تُقرأ سطرًا واحدًا مقطوعًا، والخطُّ الرفيع بينها يقول إنّها
        أربعةُ حقول.
    --}}
    table.metastrip {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        background: var(--document-surface); background: {{ $t['surface'] }};
        border-radius: {{ $fx($t['radius']) }};
        margin-bottom: {{ $fx(Theme::GEOMETRY['section_gap']) }};
    }
    table.metastrip td {
        border: none;
        border-{{ $start }}: {{ $fx(0.5) }} solid {{ $t['border'] }};
        padding: {{ $fx(7) }} {{ $fx(10) }};
        vertical-align: top;
    }
    /* ولا خطَّ قبل الأوّل: الحدُّ يفصل بين اثنين لا يفتتح الشريط */
    table.metastrip td.first { border-{{ $start }}: none; }
    table.metastrip tr + tr td { border-top: {{ $fx(0.5) }} solid {{ $t['border'] }}; }
    .metalabel {
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        color: var(--document-faint); color: {{ $t['faint'] }};
        letter-spacing: 0.05em;
        margin-bottom: {{ $fx(1.5) }};
    }

    /* ————————————————— جدول الأصناف ————————————————— */

    /*
        رأسٌ مصمتٌ بلون التاجر، وصفوفٌ يفصلها خطٌّ رفيعٌ وتناوبُ أرضيّة.

        ═══ ولمَ مصمتٌ بعد أن كان باهتًا ═══

        الرأسُ الباهت — أرضيّةٌ رماديّةٌ فاتحةٌ ونصٌّ داكن — لا يفصل نفسَه
        عن الصفوف: يُقرأ صفًّا أوّلَ فيه كلماتٌ بدل أرقام. والمصمتُ يفصل
        الجدولَ عمّا فوقه فصلًا قاطعًا، ويعطي الورقةَ مركزَها البصريَّ حيث
        ينبغي: عند البضاعة.

        وتناوبُ الأرضيّة يُحسب في القالب لا بـ`nth-child`: mpdf لا يُعوّل
        عليه فيها، فيخرج الجدولُ في المتصفّح مخطّطًا وفي الـPDF مسطّحًا —
        وهو بالضبط اختلافُ الشكل بين المحرّكين الذي نمنعه.

        ولا حدودَ رأسيّةً بين الأعمدة: القراءةُ تمشي أفقيًّا فلا تحتاج
        جدرانًا. انظر شرطَ المواصفة: «لا تستخدم borders حول كل cell».
    */
    table.items { width: 100%; border-collapse: collapse; margin-bottom: {{ $fx(12) }}; }
    table.items th.num { text-align: center; }
    table.items th.amt { text-align: {{ $end }}; }
    table.items th {
        text-align: {{ $start }};
        padding: {{ $fx(7) }} {{ $fx(8) }};
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        font-weight: bold;
        color: var(--document-on-primary); color: {{ $t['on_primary'] }};
        background: var(--document-primary); background: {{ $t['primary'] }};
        letter-spacing: 0.05em;
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
        padding: {{ $fx(8) }};
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
    /* وتناوبُ الأرضيّة: أفتحُ من أن يُرى وحده، وكافٍ ليمشي البصرُ على السطر */
    table.items tr.alt td { background: {{ $t['surface'] }}; }
    /* واسمُ الصنف أقوى من رقمه: هو ما يُبحث عنه في السطر */
    table.items td.item { font-weight: bold; }
    /* والإجماليُّ آخرُ العمود وأثقلُه — إليه تنتهي قراءةُ السطر */
    table.items td.line { font-weight: bold; }

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

    /*
        والمجاميعُ في بطاقةٍ مؤطّرة — لا أرقامًا عائمةً في بياض الورقة.

        الإطارُ يجمع المفردات والإجماليَّ في شيءٍ واحدٍ يُقرأ دفعةً، ويمنع
        أن يُقرأ «الضريبة» سطرًا من الملاحظات المجاورة.
    */
    .totals-wrap td.totalcard {
        border: {{ $fx(0.7) }} solid {{ $t['border'] }};
        border-radius: {{ $fx($t['radius']) }};
        padding: {{ $fx(5) }};
    }
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

        كتلةٌ مصمتةٌ بلون التاجر ونصٌّ معكوسٌ عليها ومقاسٌ أكبر. وهو الرقمُ
        الذي يبحث عنه القارئ أوّلًا — وورقةٌ يتساوى فيها المجموعُ الفرعيُّ
        والإجماليُّ تجعله يقرأ الأربعةَ ليعرف أيُّها المطلوب.

        وكانت أرضيّتُه فاتحةً من لون التاجر: فرقٌ لا يكاد يُرى عن الصفوف
        فوقه، ويختفي تمامًا في طباعةٍ رماديّة.
    */
    {{--
        وأرضيّةُ الصفّ تحت أرضيّةِ خليّتيه — لأنّ بينهما شقًّا.

        قِيس في الـPDF: خليّتان متجاورتان بأرضيّةٍ واحدةٍ وحافّةٍ مُدوَّرة
        يرسمهما mpdf مفصولتين بخيطٍ أبيضَ رفيع، فينشقّ صندوقُ الإجمالي
        نصفين. وأرضيّةٌ على الصفّ تملأ ما بينهما ولا تُرى تحتهما.
    --}}
    table.totals tr.grand {
        background: var(--document-primary); background: {{ $t['primary'] }};
    }
    table.totals tr.grand td {
        font-size: {{ $s(Theme::GEOMETRY['text_lg']) }};
        font-weight: bold;
        color: var(--document-on-primary); color: {{ $t['on_primary'] }};
        background: var(--document-primary); background: {{ $t['primary'] }};
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
        الملاحظاتُ والشروط — كتلةٌ لها أرضيّةٌ وحافّةٌ من جهة البداية.

        وهي أرضيّةُ بطاقة الأطراف نفسُها: ما ليس جدولًا ولا مجاميعَ على هذه
        الورقة يُعرَض بالشكل نفسه — فتُقرأ الورقةُ بلغةٍ واحدة.
    --}}
    .panel {
        background: var(--document-surface); background: {{ $t['surface'] }};
        border-{{ $start }}: {{ $fx(2.5) }} solid {{ $t['primary_ink'] }};
        border-radius: {{ $fx($t['radius']) }};
        padding: {{ $fx(8) }} {{ $fx(10) }};
        margin-top: {{ $fx(14) }};
        page-break-inside: avoid;
    }

    /*
        وخانةُ التوقيع خانةٌ فعلًا — سطرٌ منقوطٌ يُكتب عليه.

        خطٌّ متّصلٌ رفيعٌ تحت الكلمة يُقرأ فاصلًا بين قسمين لا موضعَ توقيع.
        والمنقوطُ لا يُقرأ إلّا موضعَ كتابة — وهو ما تفعله كلُّ استمارةٍ
        تُوقَّع.
    */
    /*
        وخانةُ التوقيع صندوقٌ فعلًا — لا كلمةً معلّقةً في بياض.

        والإطارُ على الخليّة لا على كتلةٍ داخلها: mpdf يُقلّص كتلَ الـ`div`
        إلى نصّها داخل الخلايا، فيخرج الصندوقُ بعرض الكلمة لا بعرض ما
        يُوقَّع عليه. انظر `partials/parties`.
    */
    table.sign { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: {{ $fx(24) }}; page-break-inside: avoid; }
    table.sign td.gap { width: {{ $fx(14) }}; }
    table.sign td.box {
        border: {{ $fx(0.7) }} dashed {{ $t['border'] }};
        border-radius: {{ $fx($t['radius']) }};
        padding: {{ $fx(7) }} {{ $fx(9) }} {{ $fx(22) }} {{ $fx(9) }};
        vertical-align: top;
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

    /*
        وتذييلُ الهويّة — آخرُ ما على الورقة، وما يجعل الصفحةَ الضائعة تُعرف.

        صفحةٌ ثانيةٌ من فاتورةٍ تسقط من ملفّ: بلا اسم متجرٍ ورقمِ مستندٍ
        عليها لا يعرف أحدٌ إلى أين تعود. فيحمل الشريطُ الثلاثة: من، وأيّ
        مستند، وأيّ صفحة.
    */
    table.docfoot {
        width: 100%;
        border-collapse: collapse;
        margin-top: {{ $fx(18) }};
        border-top: {{ $fx(0.9) }} solid {{ $t['primary_edge'] }};
        page-break-inside: avoid;
    }
    table.docfoot td {
        border: none;
        padding: {{ $fx(6) }} 0 0 0;
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        color: var(--document-faint); color: {{ $t['faint'] }};
        vertical-align: top;
    }
</style>

{{--
    رموزُ التصميم — الطبقةُ التي تجعل أوراقَ النظام ورقةً واحدة.

    ═══ ولمَ تُكتب القيمةُ مرّتين ═══

    الورقةُ تُقرأ في محرّكين: mpdf ليخرج الـPDF، والمتصفّح لتُرى في المعاينة
    وتُرسَل رابطًا. والمتصفّحُ يفهم `var(--document-primary)`، وmpdf **لا
    يفهمها** — يتجاهل الإعلان صامتًا فتخرج الورقة بلا لون، ولا خطأ يقول لماذا.

    فكلُّ قاعدةٍ تُكتب مرّتين: `var()` أوّلًا للمتصفّح، ثمّ القيمةُ الحرفيّة
    بعدها. والأخيرةُ تغلب في المحرّكين معًا — فالشكلُ واحدٌ فيهما لا شكلان.

    ═══ والقياسُ بالنقطة لا بالبكسل ═══

    الورقةُ مقاسُها بالمليمتر، والبكسل في mpdf يُحوَّل بمعامل شاشةٍ لا معنى
    له على ورق. و`$s()` تضرب كلَّ مقاسٍ بمعامل الخطّ الذي اختاره التاجر —
    فتكبر الورقةُ معًا، لا سطرُ الجسد وحده فيصير الجدولُ أصغر ممّا حوله.

    ═══ وهذه النسخةُ مقيسةٌ من ورقةٍ قائمة، لا مُصمَّمةٌ من رأي ═══

    المرجعُ المعتمد ملفُّ PDF لفاتورةٍ عُمانيّةٍ ثنائيّةِ اللغة. ولم يُقلَّد
    شكلُه بالعين: فُكَّ مجرى محتواه وقُرئت منه المقاسات والألوان والخطوط
    الفاصلة والمسافات، ثمّ كُتبت هنا أرقامًا:

      • حبرُ النصّ `#354058` — كحليٌّ داكن لا أسود.
      • حبرُ السطر الثاني `#8898b3` — لتسمية اللغة الأخرى.
      • الخطُّ الفاصل `#cbd5e1` بسُمك `0.74pt`، **واحدٌ في كلّ موضع**.
      • عنوانُ المستند `28.8pt`، والجسدُ `12pt`، والعملةُ `14pt`،
        والتذييلُ `10pt` — وهي مقاساتُ `Tf` في المرجع بلا معامل.
      • خطوةُ السطر داخل الحقل `14.6pt`، وبين حقلٍ وآخر `21.6pt`.
      • ارتفاعُ صفّ الجدول `21.5pt`.

    ولا أرضيّةَ في المرجع كلِّه — ولا واحدة. لا لرأس جدول، ولا لبطاقة طرف،
    ولا للإجمالي. الورقةُ بيضاء، وما يبني التسلسلَ فيها ثلاثةٌ: **المقاس**
    و**الفراغ** و**وزنُ الحبر**. وهذا هو التصميم: لا شيءَ يُضاف، إنّما
    يُرتَّب ما لا بدّ منه.

    ولونُ التاجر لا يقع إلّا في شعاره وفي خيطٍ واحدٍ أعلى الورقة — انظر
    `.brandrule`. والبياضُ هو اللون المسيطر.
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

    /** خطُّ الورقة — سُمكٌ واحدٌ ولونٌ واحدٌ في كلّ موضع */
    $hair = $fx(Theme::HAIRLINE) . ' solid ' . $t['rule'];

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
        line-height: 1.5;
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
        ═══ سطرا التسمية: العربيّةُ فوق والإنجليزيّةُ تحتها ═══

        هذا هو العنصرُ الأصغرُ في الورقة كلِّها، ومنه تُبنى الترويسةُ
        وحقولُ المستند ورؤوسُ الجدول والمجاميع. ثلاثةُ أسطر:

            .lbl   التسميةُ بلغة الورقة — داكنةٌ ثقيلة
            .lbl2  التسميةُ باللغة الأخرى — أفتحُ وأخفّ
            .val   القيمة — داكنةٌ خفيفة

        والوزنُ هو ما يفصلها: لو تساوى السطران لقُرئت الورقةُ ضِعفَ ما
        فيها. انظر `Paper::pair` — هي التي تأتي بالسطرين من جدول الترجمة
        نفسِه الذي تقرؤه الشاشة.
    */
    .lbl  { font-weight: bold; color: var(--document-text); color: {{ $t['text'] }}; }
    .lbl2 { color: var(--document-second); color: {{ $t['second'] }}; }
    .val  { color: var(--document-text); color: {{ $t['text'] }}; }

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
        فيُقرأ كما كُتب.
    */
    .ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }

    {{--
        ونصُّ البشر: اتّجاهُه من لغته، ومحاذاتُه من الورقة.

        و`dir` يحملهما معًا — فسمةُ `rtl` على اسم صنفٍ عربيٍّ داخل ورقةٍ
        إنجليزيّة تصحّح ترتيبَ حروفه **وتزيحه إلى يمين خليّته**، فيخرج
        عمودُ الأصناف مبعثرًا. فتُفصل المحاذاة: تتبع الورقةَ دائمًا، ويبقى
        لـ`dir` ترتيبُ الحروف وحدَه — وهو ما أردناه منه.
    --}}
    .bidi { text-align: {{ $start }}; }

    /* ————————————————— أعلى الورقة ————————————————— */

    .cover {
        width: 100%;
        height: {{ $fx(Theme::GEOMETRY['cover_height']) }};
        overflow: hidden;
        margin-bottom: {{ $fx(16) }};
    }

    /*
        وخيطٌ واحدٌ بلون التاجر أعلى الورقة — وهو كلُّ ما يناله اللون.

        ═══ ولمَ خيطٌ لا شريط ═══

        المرجعُ المعتمد لا لونَ فيه إلّا في الشعار: الورقةُ بيضاء والحبرُ
        كحليٌّ والخطوطُ رماديّة. وهو الصواب — «White يجب أن يبقى اللون
        المسيطر». لكنّ تاجرًا لم يرفع شعارًا تخرج ورقتُه بلا أثرٍ منه
        البتّة، وهويّةُ التاجر إعدادٌ قائمٌ في النظام لا يُلغى.

        فخيطٌ بسُمك نقطةٍ واحدة أعلى الورقة: يقول «هذه ورقةُ فلان» بحبرٍ
        لا يكاد يُذكر، ولا ينازع شيئًا. وهو «subtle line» في المواصفة —
        ولا يقع لونُ التاجر في غيره على هذه الورقة.
    */
    .brandrule {
        width: 100%;
        height: {{ $fx(1) }};
        background: var(--document-primary); background: {{ $t['primary'] }};
        /* والفراغُ تحته هو فراغُ الأقسام نفسُه — رقمٌ واحدٌ يحكم إيقاعَ الورقة */
        margin-bottom: {{ $fx(Theme::GEOMETRY['section_gap']) }};
        font-size: 0;
        line-height: 0;
    }

    /*
        ═══ ترويسةٌ بثلاثة أعمدة ═══

        من جهة البداية: عنوانُ المستند ثمّ الطرفُ الآخر — وهو أوّلُ ما
        تقع عليه العين. وفي الوسط: حقولُ المستند. وفي الطرف المقابل:
        الشعارُ وهويّةُ المُصدِر.

        وهو ترتيبُ المرجع حرفًا بحرف، وله سببٌ يُقرأ: من يفتح الورقة يسأل
        «ما هي؟» ثمّ «لمن؟» ثمّ «بأيّ رقمٍ وتاريخ؟» — وأخيرًا «ممّن؟».
        والهويّةُ في الطرف المقابل مع الشعار حيث تعوّدت العينُ أن تجدها.
    */
    table.head { width: 100%; table-layout: fixed; border-collapse: collapse; }
    table.head td { vertical-align: top; border: none; padding: 0; }
    table.head td.gap { padding: 0; font-size: 0; line-height: 0; }

    /*
        وعنوانُ المستند سطران بمقاسٍ واحد ووزنين.

        `فاتورة ضريبية` داكنةٌ ثقيلة، و`Tax Invoice` تحتها بالمقاس نفسِه
        وبحبرٍ أفتحَ ووزنٍ عاديّ. فيُقرأ الأوّلُ عنوانًا والثاني ترجمةً —
        بلا أن يصغر أحدُهما فيبدو حاشية.
    */
    .doctitle {
        font-size: {{ $s(Theme::GEOMETRY['text_display']) }};
        font-weight: bold;
        line-height: 1.35;
        color: var(--document-text); color: {{ $t['text'] }};
    }
    .doctitle2 {
        font-size: {{ $s(Theme::GEOMETRY['text_display']) }};
        font-weight: normal;
        line-height: 1.35;
        color: var(--document-second); color: {{ $t['second'] }};
    }

    /*
        ═══ واسمُ المتجر أكبرُ من جسد الورقة ═══

        في المرجع تُثبّت هويّةَ المُصدِر صورتُه: شعارٌ بارتفاعٍ يقارب ثلاثةَ
        أضعاف سطرِ النصّ، والاسمُ تحته. وتاجرٌ لم يرفع شعارًا — وهم أكثرُ
        مستعملي النظام — تبقى هويّتُه سطرًا بمقاس بقيّة السطور، فتُقرأ
        الورقةُ بلا صاحب.

        فيأخذ الاسمُ درجةً واحدةً فوق الجسد. لا صندوقَ حوله ولا أرضيّةَ
        ولا لون — مقاسُه وحدَه يقول إنّه اسمُ من أصدر الورقة.
    */
    .shop {
        font-size: {{ $s(Theme::GEOMETRY['text_currency']) }};
        font-weight: bold;
        line-height: 1.3;
    }

    /*
        ═══ رصُّ الحقول: صفٌّ لكلّ سطر ═══

        قِيس في الـPDF: **mpdf يُسقط هوامشَ كتل الـ`div` وحشوَها داخل خليّة
        جدول**، فتتراكم أسطرُ الحقل بلا فراغٍ بينها ويركب بعضُها على بعض.
        وحشوُ خليّة الجدول يُحترم كاملًا. فما لا يُضبط بكتلةٍ يُضبط بصفّ.

        والخطوتان مقيستان من المرجع: ١٣٫٤ نقطة بين سطرَي الحقل الواحد،
        و٢٠٫٧ بين حقلٍ وآخر. والفرقُ بينهما هو ما يجعل الحقولَ تُقرأ
        مجموعات.
    */
    table.fields { width: 100%; border-collapse: collapse; }
    table.fields td {
        border: none;
        padding: 0;
        line-height: {{ round(Theme::GEOMETRY['field_line'] / Theme::GEOMETRY['text_base'], 3) }};
        text-align: {{ $start }};
    }
    /* والفراغُ بين الحقول حشوٌ أسفلَ القيمة — لا هامشٌ يسقط */
    table.fields td.f-end { padding-bottom: {{ $fx(Theme::GEOMETRY['field_gap'] - Theme::GEOMETRY['field_line']) }}; }
    table.fields tr:last-child td { padding-bottom: 0; }
    {{--
        وعمودُ الطرف المقابل يحاذي حافّةَ الورقة لا وسطَها.

        في المرجع تقع هويّةُ المُصدِر والرقمُ الكبير على الحافّة المقابلة
        لجهة البداية، محاذيَين لها. فيتوازن رأسُ الورقة: عنوانٌ في طرف
        وهويّةٌ في الطرف الآخر، وبينهما حقولُ المستند.
    --}}
    table.fieldsend td { text-align: {{ $end }}; }

    /*
        ═══ وحقولُ المستند عمودان حين تكثر ═══

        `table-layout: fixed` شرطٌ لا زينة: جدولٌ بعرض ١٠٠٪ داخل خليّةٍ
        ضيّقةٍ يُفسد حسابَ العرض الأدنى عند mpdf فيخرج العمودان متداخلين —
        وهي القاعدةُ نفسُها التي تحكم `table.head`.
    */
    table.metasplit { width: 100%; table-layout: fixed; border-collapse: collapse; }
    table.metasplit td { border: none; padding: 0; vertical-align: top; }
    table.metasplit td.gap { width: {{ $fx(14) }}; padding: 0; font-size: 0; line-height: 0; }

    /* وفراغٌ تحت عنوان المستند يفصله عن الطرف الذي يليه */
    table.fields td.f-title { padding-bottom: {{ $fx(18) }}; }

    /*
        ═══ الرقمُ الأهمّ على الورقة ═══

        في المرجع: «الرصيد المستحق / Total due» ثمّ الرقمُ بمقاس العنوان
        نفسِه. لا صندوقَ حوله ولا أرضيّةَ ولا لون — مقاسُه وحدَه يجعله
        أوّلَ ما تقع عليه العين بعد عنوان المستند.

        وهو ما يُبحث عنه أوّلًا في كلّ ورقةٍ ماليّة: كم؟ فيُعطى ما يستحقّ
        من الوزن، ويُترك حوله بياضٌ يُظهره. والعملةُ بجانبه بمقاسٍ أصغر:
        الرقمُ هو الخبر، والعملةُ وحدةُ قياسه.
    */
    /* وفراغُه حولَه هامشٌ: كتلةٌ في مجرى الورقة، والهوامشُ هنا تُحترم */
    table.figwrap { margin-top: {{ $fx(13) }}; margin-bottom: {{ $fx(11) }}; page-break-inside: avoid; }
    .fig {
        font-size: {{ $s(Theme::GEOMETRY['text_display']) }};
        font-weight: bold;
        line-height: 1.25;
        direction: ltr;
        color: var(--document-text); color: {{ $t['text'] }};
    }
    .fig-cur {
        font-size: {{ $s(Theme::GEOMETRY['text_currency']) }};
        font-weight: normal;
        color: var(--document-second); color: {{ $t['second'] }};
    }

    /*
        وحالُ المستند سطرٌ هادئ، لا خَتمٌ مؤطَّر.

        المواصفة: «والحالة تظهر بشكل منفصل وبسيط». وإطارٌ حول كلمةٍ في
        ورقةٍ نُزعت عنها الأطرُ كلُّها يعود صندوقًا خامسًا. فتُكتب الحالُ
        سطرًا تحت الرقم بحبر التاجر — وهو «small status detail» الذي
        تسمح به المواصفةُ للون.

        ولا تُطبع حالٌ ليست في النموذج: خانةٌ خاويةٌ تُقرأ حقلًا نُسي.
    */
    .stamp {
        font-size: {{ $s(Theme::GEOMETRY['text_sm']) }};
        font-weight: bold;
        letter-spacing: 0.06em;
        color: var(--document-primary-ink); color: {{ $t['primary_ink'] }};
    }

    /* ————————————————— جدول الأصناف ————————————————— */

    /*
        ═══ جدولٌ بخطّين اثنين: تحت الرأس، وتحت كلّ صفّ ═══

        لا أرضيّةَ للرأس ولا لونَ ولا حدودَ رأسيّةً ولا تناوبَ صفوف. وهذا
        هو المرجعُ حرفيًّا، وهو ما تعنيه المواصفةُ بـ«ممنوع spreadsheet
        appearance»: الجدولُ ليس شبكةً تُملأ، إنّما أسطرٌ تُقرأ.

        ورأسُه سطران — تسميةٌ بلغة الورقة وأخرى تحتها — بالمقاس نفسِه الذي
        تُكتب به الصفوف. يفصله عنها الوزنُ والخطُّ أسفله، لا صبغٌ ولا مقاس.

        وارتفاعُ الصفّ ٢١٫٥ نقطة مقيسًا: يكفي ليتنفّس السطرُ ولا يجعل
        عشرةَ أصنافٍ تمتدّ صفحتين.
    */
    table.items { width: 100%; border-collapse: collapse; margin-bottom: {{ $fx(8) }}; }
    table.items th {
        text-align: {{ $start }};
        vertical-align: bottom;
        padding: 0 {{ $fx(6) }} {{ $fx(5) }};
        font-size: {{ $s(Theme::GEOMETRY['text_base']) }};
        font-weight: normal;
        border-bottom: {{ $hair }};
    }
    table.items th.num { text-align: center; }
    table.items th.amt { text-align: {{ $end }}; }
    table.items td {
        padding: {{ $fx(4) }} {{ $fx(6) }} {{ $fx(4) }};
        font-size: {{ $s(Theme::GEOMETRY['text_base']) }};
        border-bottom: {{ $hair }};
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
    /* واسمُ الصنف أقوى من سطر وصفه — وهو ما يُبحث عنه في السطر */
    table.items td.item { font-weight: bold; }

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
        color: var(--document-second); color: {{ $t['second'] }};
        padding: {{ $fx(18) }} {{ $fx(6) }};
    }

    /* ————————————————— المجاميع ————————————————— */

    /*
        ═══ المجاميعُ أسطرٌ تتبع الجدول، لا لوحةٌ بجانبه ═══

        في المرجع تقع تحت الجدول مباشرة، بأعمدةٍ تحاذي أعمدتَه: المبلغُ
        تحت «المجموع»، والتسميةُ تحت «السعر»، والعملةُ في الطرف. فتُقرأ
        امتدادًا للجدول لا كتلةً ثانية.

        ولا إطارَ ولا أرضيّةَ ولا خطَّ فوق الإجمالي: الخطُّ الأخيرُ من
        الجدول يفصله عمّا قبله، والمقاسُ والوزنُ يقولان أيُّها الأهمّ.
    */
    table.totals { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
    table.totals td {
        border: none;
        padding: {{ $fx(3) }} {{ $fx(6) }};
        font-size: {{ $s(Theme::GEOMETRY['text_base']) }};
        vertical-align: top;
        text-align: {{ $start }};
    }
    table.totals td.amt { text-align: {{ $end }}; }
    {{--
        وسطرا التسمية في المجاميع متّسعان.

        «الإجمالي» ثمّ «Total» تحتها في خليّةٍ واحدة بفاصل `<br>`: ياءُ
        العربيّة تنزل على لام الإنجليزيّة عند الارتفاع الافتراضيّ. ومقاسُ
        السطر هنا هو خطوةُ الحقل نفسُها في بقيّة الورقة.
    --}}
    table.totals td.lblcell { line-height: {{ round(Theme::GEOMETRY['field_line'] / Theme::GEOMETRY['text_base'], 3) }}; }
    /* والإجماليُّ أثقلُ سطرٍ فيها — بالوزن وحده */
    table.totals tr.grand td { font-weight: bold; }
    table.totals tr.grand td.lblcell .lbl2 { font-weight: normal; }

    /* ————————————————— كتلٌ متفرّقة ————————————————— */

    /*
        الملاحظاتُ والشروط — عنوانٌ صغيرٌ ونصٌّ تحته، بلا صندوق.

        وتقع في الجهة المقابلة للمجاميع حيث كان نصفُ السطر خاليًا، والرمزُ
        أسفلها. انظر `partials/close`: هي التي تبني العمودين لتسع أوراق.
    */
    table.stack { width: 100%; border-collapse: collapse; }
    table.stack td { border: none; padding: 0 0 {{ $fx(7) }} 0; vertical-align: top; text-align: {{ $start }}; }
    table.stack tr:last-child td { padding-bottom: 0; }
    table.stack td.cap { padding-bottom: {{ $fx(2) }}; }

    /*
        وخانةُ التوقيع خطٌّ يُوقَّع فوقه — لا صندوقٌ منقوطٌ حوله.

        الفراغُ المحجوز ثمّ الخطُّ ثمّ اسمُ ما يُوقَّع تحته: وهو ما تستعمله
        كلُّ استمارةٍ تُوقَّع، ويُقرأ موضعًا بلا إطار.
    */
    table.sign { width: 100%; border-collapse: collapse; margin-top: {{ $fx(22) }}; page-break-inside: avoid; }
    table.sign td.gap { width: {{ $fx(24) }}; padding: 0; }
    table.sign td.space { border: none; padding: 0; height: {{ $fx(26) }}; font-size: 0; line-height: 0; }
    table.sign td.box {
        border-top: {{ $hair }};
        padding: {{ $fx(5) }} 0 0 0;
        vertical-align: top;
    }

    /* والرمزُ لا يركب على الجدول: كتلةٌ محجوزةٌ كاملةً */
    .qr-block { page-break-inside: avoid; margin-top: {{ $fx(4) }}; }
    .qr-block .c { text-align: center; }
    .qr-cap {
        font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
        color: var(--document-second); color: {{ $t['second'] }};
        margin-top: {{ $fx(2) }};
        line-height: 1.35;
    }

    .foot {
        margin-top: {{ $fx(8) }};
        padding-top: {{ $fx(4) }};
        border-top: {{ $hair }};
        color: var(--document-second); color: {{ $t['second'] }};
        font-size: {{ $s(Theme::GEOMETRY['text_sm']) }};
        page-break-inside: avoid;
    }

    /*
        ═══ تذييلُ الورقة: هادئٌ ولا ينافس — وللشاشة وحدها ═══

        في المرجع سطرٌ واحدٌ صغيرٌ في أسفل الصفحة: اسمُ المُصدِر في طرف،
        و«Page 1 of 1 - Server» في الآخر. لا خطَّ فوقه ولا وزن.

        وفي الـPDF يرسم هذا السطرَ **كاملًا** `MpdfDriver::pageNumbers` في
        تذييل كلّ صفحة: الاسمُ في طرفٍ والصفحةُ ورقمُ المستند في الآخر.
        فكتلةٌ في مجرى الورقة تحمل الاسمَ كانت تطبعه مرّتين — واحدةً
        فوق الأخرى بفارق سنتيمتر.

        وأسوأُ من التكرار أثرُه على الصفحات: كتلةٌ في آخر المجرى لا يتّسع
        لها ما بقي من الصفحة **تفتح صفحةً جديدة** — فاتورةٌ بثلاثين صنفًا
        كانت تخرج ثلاثَ صفحات، ثالثتُها بيضاءُ إلّا من اسم المتجر.

        والرابطُ العامُّ والمعاينةُ لا محرّكَ فيهما ولا تذييلَ صفحة، فتبقى
        الكتلةُ لهما وحدهما — بالطريق نفسِه الذي يُرسَم به صندوقُ الورقة:
        mpdf يقرأ `print` ويتجاهل `screen` كاملًا. انظر `PaperSize`.
    */
    /*
        والإخفاءُ على كتلةٍ لا على جدول — قِيس في mpdf.

        `display: none` على `<table>` يتجاهله المحرّكُ صامتًا فيُطبع
        الجدولُ كما هو، ويحترمه على `<div>`. فالكتلةُ غلافٌ يُخفى،
        والجدولُ في جوفها كما هو.
    */
    div.docfoot { display: none; }

    @media screen {
        div.docfoot { display: block; }

        table.docfoot {
            width: 100%;
            border-collapse: collapse;
            margin-top: {{ $fx(4) }};
        }
        table.docfoot td {
            border: none;
            padding: 0;
            font-size: {{ $s(Theme::GEOMETRY['text_xs']) }};
            color: var(--document-second); color: {{ $t['second'] }};
            text-align: center;
            line-height: 1.4;
        }
    }
</style>

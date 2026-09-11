{{--
    خطُّ الورقة في المتصفّح — لا خطُّ الجهاز الذي يفتحها.

    ═══ العطبُ الذي وُلد منه هذا الملفّ ═══

    القالبُ يقول `font-family: xbriyaz, 'IBM Plex Sans Arabic', sans-serif`.
    وmpdf يجد `xbriyaz` مضمّنًا في المكتبة فيُثبّته في الـPDF — ورقةٌ واحدة
    على كلّ جهاز. والمتصفّحُ لا يجده — ليس خطَّ وِب — ولا يجد الثاني أيضًا:
    لا سطرَ في النظام كلِّه يُحمّله. فيسقط إلى `sans-serif`:

      • على ماك   →  SF Arabic
      • على ويندوز →  Segoe UI أو Tahoma
      • على لينكس  →  ما وجده

    فالمعاينةُ لا تطابق المطبوع، **ولا تطابق نفسَها** بين تاجرٍ وتاجر. ومن
    يضبط عرضَ عمودٍ على جهازه يضبطه على خطٍّ لا يراه غيرُه ولا تطبعه الطابعة.

    ═══ والخطُّ المختار موجودٌ أصلًا ═══

    ملفّاتُ «IBM Plex Sans Arabic» مقطّعةً في `public/fonts` — تُشحن اليوم
    لمواقع التجّار. فلا ملفَّ جديد، ولا تحويلَ صيغة، ولا 1.1 ميغابايت من
    `xbriyaz` تُحمَّل على زائر.

    ═══ وثمنُه ═══

    أربعةُ مقاطعَ بالكثير — عربيٌّ ولاتينيٌّ في وزنين — مجموعُها ٩٣ كيلوبايت،
    والمتصفّحُ لا يجلب منها إلّا ما يلزم حروفَ الورقة (`unicode-range`).
    وهي هنا وحدها: صفحاتُ اللوحة لا تطلبها، فلا تُشحن إلّا حين تُفتح معاينةٌ
    أو رابطُ ورقة. وهي ملفّاتٌ ساكنة تُخزَّن في المتصفّح إلى الأبد.

    ═══ وداخل `@media screen` عمدًا ═══

    mpdf يقرأ وسيطَ `mpdf` وحده (`CSSselectMedia`)، فلا يرى هذه الكتلة أصلًا:
    لا يحاول جلبَ ملفٍّ، ولا يبدّل خطَّ الـPDF. انظر `MpdfDriver::base`.

    ═══ وما لا يُدّعى ═══

    هذا يجعل المعاينة **واحدةً على كلّ جهاز** ومقارِبةً للمطبوع — لا مطابقةً
    له حرفًا بحرف: `xbriyaz` و«IBM Plex» خطّان مختلفان، ومقاساتُ حروفهما
    تختلف، فينكسر السطرُ الطويلُ أحيانًا عند كلمةٍ غير التي ينكسر عندها في
    الـPDF. والتطابقُ التامّ يحتاج الخطَّ نفسَه في المحرّكين — وثمنُه تحويلُ
    `xbriyaz` إلى woff2 وشحنُه، أو إعطاءُ mpdf ملفَّ «IBM Plex» كاملًا.
    وكلاهما أصلٌ جديد يُضاف، والفرقُ الباقي مقيسٌ في
    `ThePreviewAndThePrintShareAFontTest`.
--}}
    @media screen {
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/fonts/ibmpsa-5.woff2') format('woff2');
            unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC;
        }
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/fonts/ibmpsa-8.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('/fonts/ibmpsa-17.woff2') format('woff2');
            unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC;
        }
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('/fonts/ibmpsa-20.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
    }

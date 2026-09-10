<?php

namespace App\Support\Document;

/**
 * مقاساتُ الورق — مصدرٌ واحد يقرؤه القالبُ والمحرّكُ والشاشة.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * كانت هندسةُ الورقة تعيش في **مُنشئ mpdf وحده**: `format` و`margin_*`.
 * والقالبُ لا يعرف عنها شيئًا — لا `@page` فيه، ولا عرضَ في `body`، ولا
 * هامش. فالورقةُ الواحدة تخرج بشكلين:
 *
 *  • في الـPDF: عمودُ نصٍّ عرضُه ١٨٤ مم داخل صفحة ٢١٠، بهامش ١٣ مم.
 *  • في المتصفّح — وهو ما يراه التاجر في المعاينة، وما يفتحه الزبون من
 *    الرابط، وما يُطبع بـCtrl+P — **بلا مقاسٍ ولا هامش**: يمتدّ المحتوى
 *    على عرض ما يُوضع فيه، بهامشٍ افتراضيٍّ من المتصفّح مقدارُه ٨ بكسل،
 *    ثمّ يُقلّصه المتصفّح ليُلائم ورقتَه عند الطبع.
 *
 * فالمعاينةُ تكذب على الورقة، والورقةُ تكذب على الطابعة. ولا سطرَ في
 * المستودع يقول أيُّهما الصواب.
 *
 * ═══ والحلُّ أن يكون المقاسُ مكتوبًا مرّةً ويُقرأ في ثلاثة مواضع ═══
 *
 *  ١. `@page` — يقرؤه mpdf ويقرؤه المتصفّحُ عند الطبع.
 *  ٢. صندوقُ `.paper` تحت `@media screen` — للمعاينة والرابط.
 *  ٣. مُنشئ mpdf — من الأرقام نفسِها.
 *
 * ولا تصغيرَ في أيّ منها: لا `scale` ولا `zoom` ولا «ملاءمةٌ للصفحة».
 * التخطيطُ مبنيٌّ على المقاس الحقيقيّ، فيُطبع بمقياس ١٠٠٪.
 *
 * ═══ ولمَ `@media screen` تحديدًا ═══
 *
 * mpdf يقرأ وسيطَ `print` افتراضًا ويتجاهل `screen` كاملًا. فما يقع في
 * تلك الكتلة يخصّ المتصفّحَ وحده — صندوقُ الورقة وحشوُها — بينما يبقى
 * الـPDF على هوامش المحرّك. ولو وُضع الحشوُ خارجها لتضاعف في الـPDF:
 * هامشُ المحرّك ثمّ حشوُ الصندوق فوقه.
 */
final class PaperSize
{
    public const A4 = 'A4';

    public const A5 = 'A5';

    public const T80 = '80mm';

    public const T58 = '58mm';

    /**
     * ورقةٌ ذاتُ صفحات (`sheet`) أو شريطٌ لا صفحاتِ له (`strip`).
     *
     * والشريطُ بلا ارتفاع: الطابعةُ الحرارية ورقُها بكرةٌ تُقصّ، فيُقاس
     * المحتوى ثمّ تُرسم ورقةٌ بطوله — انظر `MpdfDriver::strip`.
     *
     * والهوامشُ بالمليمتر لأنّ الورقة تُقاس به. والبكسلُ في mpdf يُحوَّل
     * بمعامل شاشةٍ لا معنى له على ورق.
     *
     * @var array<string, array{kind: string, width: float, height: float|null, top: float, side: float, bottom: float, footer: float}>
     */
    public const PRESETS = [
        self::A4 => [
            'kind' => 'sheet', 'width' => 210.0, 'height' => 297.0,
            /*
             * والسفليُّ أوسعُ من العلويّ: mpdf يرسم تذييلَ الصفحة **داخل**
             * الهامش السفليّ، فهامشٌ بقدر النصّ يجعل رقمَ الصفحة يركب على
             * آخر سطر.
             */
            'top' => 14.0, 'side' => 13.0, 'bottom' => 20.0, 'footer' => 6.0,
        ],
        self::A5 => [
            'kind' => 'sheet', 'width' => 148.0, 'height' => 210.0,
            /* وهوامشُها أضيق: ١٣ مم على ورقةٍ عرضُها ١٤٨ تأكل سُبعَ عرضها */
            'top' => 10.0, 'side' => 10.0, 'bottom' => 15.0, 'footer' => 5.0,
        ],
        self::T80 => [
            'kind' => 'strip', 'width' => 80.0, 'height' => null,
            'top' => 3.0, 'side' => 3.0, 'bottom' => 2.0, 'footer' => 0.0,
        ],
        self::T58 => [
            /*
             * والهامشُ يتبع عرضَ الورق: ٣ مم على شريط ٥٨ تأكل عُشرَ عرضه
             * المطبوع، فينكمش عمودُ الأصناف حتى ينكسر اسمُ الصنف ثلاثة أسطر.
             */
            'kind' => 'strip', 'width' => 58.0, 'height' => null,
            'top' => 3.0, 'side' => 2.0, 'bottom' => 2.0, 'footer' => 0.0,
        ],
    ];

    /** المقاسُ الافتراضيّ لما لا يُعرف — ورقةٌ عاديّة لا شريط */
    public const DEFAULT = self::A4;

    /**
     * وصفُ مقاسٍ باسمه — والمجهولُ يعود إلى A4 ولا يُسقط الطباعة.
     *
     * @return array{kind: string, width: float, height: float|null, top: float, side: float, bottom: float, footer: float}
     */
    public static function of(?string $key): array
    {
        return self::PRESETS[$key ?? ''] ?? self::PRESETS[self::DEFAULT];
    }

    /** أهذا شريطُ طابعةٍ حراريّة؟ */
    public static function isStrip(?string $key): bool
    {
        return self::of($key)['kind'] === 'strip';
    }

    /** أهذا مقاسٌ نعرفه؟ */
    public static function known(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::PRESETS);
    }

    /** أسماءُ المقاسات — لقائمة الاختيار ولمصادقة الحفظ */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * أنماطُ الصفحة — `@page` للطبع، وصندوقُ الورقة للشاشة.
     *
     * وتُكتب من الأرقام نفسِها التي يُبنى بها المحرّك، فلا يفترق ما يُرى
     * عمّا يُطبع.
     *
     * @param  array<string, mixed>  $p  وصفُ المقاس من `of()`
     */
    public static function css(array $p): string
    {
        $n = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.').'mm';

        $pad = $n($p['top']).' '.$n($p['side']).' '.$n($p['bottom']);

        /*
         * وحجمُ الصفحة يُعلَن للورقة ذات الصفحات وحدها.
         *
         * الشريطُ ارتفاعُه من محتواه — يُقاس بالرسم ثمّ تُبنى ورقةٌ بطوله.
         * ورقمٌ مكتوبٌ هنا يخالف ما يقيسه المحرّك، فتخرج ورقةٌ فيها بياضٌ
         * أسفلَ الإيصال أو يُقصّ ذيلُه.
         */
        $lines = [];
        $lines[] = '    /*';
        $lines[] = '        مقاسُ الصفحة للمتصفّح عند الطبع — وmpdf يتجاهله.';
        $lines[] = '';
        $lines[] = '        وهو داخل `@media print` عمدًا: مقاسُ mpdf يصله من';
        $lines[] = '        `format` وهوامشُه من `margin_*`، و`@page` عنده يُبطل';
        $lines[] = '        تذييلَ الصفحة فيختفي ترقيمُها. انظر `MpdfDriver::base`.';
        $lines[] = '    */';
        $lines[] = '    @media print {';
        $lines[] = '        @page {';

        if ($p['height'] !== null) {
            $lines[] = '            size: '.$n($p['width']).' '.$n($p['height']).';';
        }

        $lines[] = '            margin: '.$pad.';';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        html, body { margin: 0; padding: 0; }';
        $lines[] = '';
        $lines[] = '        /* والحشوُ في `@page` لا في الصندوق: لئلّا يُحسب مرّتين */';
        $lines[] = '        .paper { width: auto; min-height: 0; padding: 0; }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /*';
        $lines[] = '        وصندوقُ الورقة للشاشة وحدها — mpdf يقرأ `print` ويتجاهل هذه الكتلة.';
        $lines[] = '';
        $lines[] = '        وبها تُرى الورقةُ في المعاينة وفي الرابط بمقاسها وهوامشِها كما';
        $lines[] = '        ستُطبع تمامًا. ولو وُضعت خارج `@media screen` لتضاعف الحشو في';
        $lines[] = '        الـPDF: هامشُ المحرّك، ثمّ حشوُ الصندوق فوقه.';
        $lines[] = '    */';
        $lines[] = '    @media screen {';
        $lines[] = '        html, body { margin: 0; padding: 0; width: auto; }';
        $lines[] = '';
        $lines[] = '        .paper {';
        $lines[] = '            width: '.$n($p['width']).';';

        if ($p['height'] !== null) {
            $lines[] = '            min-height: '.$n($p['height']).';';
        }

        $lines[] = '            padding: '.$pad.';';
        $lines[] = '            margin: 0 auto;';
        $lines[] = '            background: #fff;';
        $lines[] = '        }';
        $lines[] = '    }';

        return implode("\n", $lines);
    }
}

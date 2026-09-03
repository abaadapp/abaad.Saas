<?php

namespace App\Support;

use Illuminate\Http\Response;
use Mpdf\Mpdf;

/**
 * محرّكُ الورق — موضعٌ واحد يبني mpdf لكلّ ورقةٍ في النظام.
 *
 * كانت ستّةُ مواضع تكتب `new Mpdf([...])` بيدها: متحكّمُ الورق، ومصدّرُ
 * المنتجات، ومصدّرُ العملاء، ومصدّرُ المورّدين، ومنزّلُ التقارير، وراسمُ
 * المستندات. ولكلٍّ هوامشُه: هذا يكتب ١٢ وذاك ١٤ وثالثٌ ١٥، وواحدٌ منها
 * وحده يضبط الخطّ. فتخرج من النظام الواحد أوراقٌ لا يجمعها شكل — والتاجر
 * يرسلها كلَّها باسمه هو.
 *
 * والخطّ يُسمّى هنا صراحةً: `xbriyaz` خطٌّ عربيّ يأتي مع mpdf بأربع وزنات.
 * وكانت القوالب تكتب `font-family: 'dejavusans'` — وهو خطٌّ **بلا حرفٍ
 * عربيّ واحد**، فيسقط الرسمُ إلى بديلٍ يختاره المحرّك بنفسه. يعمل، لكن
 * لا أحد يعرف أيّ خطٍّ خرج على الورقة، ولا يبقى واحدًا بين نسختين من
 * المكتبة.
 */
class Pdf
{
    /** هوامش A4 بالمليمتر — واحدةٌ لكل ورقةٍ في النظام */
    private const A4_MARGIN = 13;

    private const A4_TOP = 14;

    /** ما يُترك أسفل الشريط الحراريّ قبل القصّ */
    private const STRIP_MARGIN = 4;

    /**
     * ارتفاعُ ورقة القياس — طويلٌ لا نهائيّ.
     *
     * مواصفةُ PDF تقف عند ٢٠٠ بوصة (٥٠٨٠ مم)، والمترانِ هنا أطولُ من أيّ
     * إيصالٍ في الوجود: مئةُ صنفٍ لا تبلغ نصفَه. وإن بلغه شيءٌ يومًا فالقياس
     * يجمع الصفحات ولا يبتر.
     */
    private const PROBE = 2000;

    /** أقصرُ إيصالٍ يُطبع — أقلُّ منه يخرج قصاصةً لا تُمسك */
    private const MIN_STRIP = 60;

    /**
     * ورقةُ A4 — كلُّ ما يُطبع على ورقٍ عاديّ.
     *
     * والعرضيّ للجداول التي لا تسعها الصفحة قائمةً: تقريرٌ بعشرة أعمدة على
     * ورقةٍ قائمة يخرج بأعمدةٍ ملتصقة تُقرأ بالتخمين.
     */
    public static function a4(string $html, string $name, bool $landscape = false): Response
    {
        $mpdf = new Mpdf(self::base() + [
            'format' => $landscape ? 'A4-L' : 'A4',
            'margin_left' => self::A4_MARGIN,
            'margin_right' => self::A4_MARGIN,
            'margin_top' => self::A4_TOP,
            /*
             * والسفليُّ يتّسع للتذييل: mpdf يرسم تذييل الصفحة **داخل** الهامش
             * السفليّ، فهامشٌ بقدر النصّ يجعل رقم الصفحة يركب على آخر سطر.
             */
            'margin_bottom' => self::A4_TOP + 6,
            'margin_footer' => 6,
        ]);

        self::pageNumbers($mpdf);
        $mpdf->WriteHTML($html);

        return self::respond($mpdf, $name);
    }

    /**
     * شريطُ الطابعة الحراريّة — بعرض ورقها وبطول محتواه.
     *
     * وكان الطولُ مثبَّتًا على ٢٠٠ مم: أي ورقةٍ بارتفاع عشرين سنتيمترًا.
     * فإيصالٌ بأربعين صنفًا يُقسَم **صفحتين** على طابعةٍ لا تعرف الصفحات —
     * يخرج نصفُه، ثمّ يقفز الورق، ثمّ يخرج نصفُه الثاني بلا ترويسةٍ ولا
     * مجموع. ويأخذ الزبون ورقتين إحداهما بلا رأسٍ والأخرى بلا ذيل.
     *
     * فيُقاس المحتوى أوّلًا على ورقةٍ لا تنتهي، ثمّ يُرسم على ورقةٍ بطوله
     * تمامًا. والرسمُ مرّتان — والإيصال أصغرُ ما يُرسم في النظام، وثمنُ
     * المرّة الثانية أهونُ من إيصالٍ مقصوص.
     */
    public static function strip(string $html, string $name, int $widthMm): Response
    {
        $height = self::stripHeight($html, $widthMm);

        $mpdf = self::stripEngine($widthMm, $height);
        $mpdf->WriteHTML($html);

        return self::respond($mpdf, $name);
    }

    /**
     * طولُ ما سيُرسم بالمليمتر — بالرسم نفسه لا بتقديرٍ من عدد الأسطر.
     *
     * وهي عامّةٌ ليُقاس عليها: «هل يطول الشريط بطول فاتورته؟» سؤالٌ لا
     * يُجاب عليه بفحص ملفّ PDF — وهو أهمّ ما في هذا الملفّ.
     */
    public static function stripHeight(string $html, int $widthMm): float
    {
        $probe = self::stripEngine($widthMm, self::PROBE);
        $probe->WriteHTML($html);

        // صفحاتٌ كاملة قبله + موضعُ القلم في الأخيرة
        $used = (max(1, (int) $probe->page) - 1) * self::PROBE + (float) $probe->y;

        return max(self::MIN_STRIP, round($used + self::STRIP_MARGIN, 1));
    }

    private static function stripEngine(int $widthMm, float $heightMm): Mpdf
    {
        /*
         * والهامش يتبع عرض الورق.
         *
         * ٤ مم على شريط ٥٨ تأكل ثُمن عرضه المطبوع، فينكمش عمود الأصناف حتى
         * ينكسر اسمُ الصنف ثلاثة أسطر. والطابعة الحرارية لا تطبع حتى الحافة
         * على كل حال، فالهامش المنطقيّ يبقى صغيرًا.
         */
        $margin = $widthMm <= 60 ? 2 : 3;

        return new Mpdf(self::base() + [
            'format' => [$widthMm, $heightMm],
            'margin_left' => $margin,
            'margin_right' => $margin,
            'margin_top' => 3,
            'margin_bottom' => 2,
        ]);
    }

    /**
     * ترقيمُ الصفحات — على كلّ ورقة A4 بلا أن يكتبه قالب.
     *
     * تقريرُ مخزونٍ من ستّ صفحات كان يخرج بلا رقمٍ على واحدة: تسقط ورقةٌ من
     * الحزمة فلا يعرف قارئها أنّها سقطت. وكتابتُه في اثنين وعشرين قالبًا
     * تعني نسيانَه في واحدٍ منها على الأقلّ.
     */
    private static function pageNumbers(Mpdf $mpdf): void
    {
        $mpdf->SetHTMLFooter(
            '<div style="font-family:xbriyaz; font-size:8pt; color:#9ca3af; text-align:center; '
            .'border-top:0.4pt solid #e5e7eb; padding-top:2mm;">'
            .'<span dir="ltr">{PAGENO} / {nbpg}</span>'
            .'</div>'
        );
    }

    /**
     * ما يشترك فيه كلُّ ورقة.
     *
     * `default_font` صراحةً: بلا اسمٍ يختار المحرّك بنفسه، وخيارُه يتبدّل مع
     * نسخة المكتبة — فورقةٌ تُطبع اليوم بخطٍّ وبعد ترقيةٍ بخطٍّ آخر، ولا
     * سطرَ في المستودع يقول لماذا.
     *
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return [
            'mode' => 'utf-8',
            'directionality' => 'rtl',
            'default_font' => 'xbriyaz',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            /*
             * وصورةٌ لا تُقرأ لا تُسقط الورقة.
             *
             * شعارُ متجرٍ بمسارٍ منقطع كان يرفع استثناءً من mpdf، فيُردّ
             * التاجر بصفحة خطأٍ بدل فاتورته — والشعار زينةٌ فيها.
             */
            'showImageErrors' => false,
        ];
    }

    private static function respond(Mpdf $mpdf, string $name): Response
    {
        $file = $name.'.pdf';

        return response($mpdf->Output($file, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$file.'"',
        ]);
    }
}

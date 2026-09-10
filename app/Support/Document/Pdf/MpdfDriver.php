<?php

namespace App\Support\Document\Pdf;

use Illuminate\Http\Response;
use Mpdf\Mpdf;

/**
 * محرّكُ الورق — mpdf، موضعًا واحدًا يبنيه لكلّ ورقةٍ في النظام.
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
class MpdfDriver implements Driver
{
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
     * ارتفاعُ الترويسة المتكرّرة — يُحجَز في الهامش العلويّ.
     *
     * mpdf يرسم ترويسةَ الصفحة **داخل** الهامش العلويّ، فهامشٌ بقدر النصّ
     * يجعلها تركب على أوّل سطرٍ في الجسد.
     */
    private const HEADER_SPACE = 18;

    public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
    {
        $repeats = $runningHeader !== null && trim($runningHeader) !== '';

        /*
         * والمقاسُ يُمرَّر أرقامًا لا اسمًا مثل `'A4'`.
         *
         * الاسمُ يعرفه mpdf وحده، والقالبُ يحتاج العرضَ والارتفاعَ ليكتب
         * `@page` وصندوقَ الورقة. ورقمان في موضعين يفترقان عند أوّل مقاسٍ
         * يُضاف — فيُبنى المحرّك على ١٤٨ مم ويُرسم القالبُ على ٢١٠.
         *
         * والعرضيّةُ تقلب البُعدين: تقريرٌ بعشرة أعمدة على ورقةٍ قائمة يخرج
         * بأعمدةٍ ملتصقة تُقرأ بالتخمين.
         */
        $format = $landscape
            ? [$preset['height'], $preset['width']]
            : [$preset['width'], $preset['height']];

        $mpdf = new Mpdf(self::base() + [
            'format' => $format,
            'margin_left' => $preset['side'],
            'margin_right' => $preset['side'],
            /*
             * والهامشُ العلويّ يتّسع للترويسة المتكرّرة إن وُجدت.
             *
             * فاتورةٌ بخمسين صنفًا تمتدّ صفحتين وثلاثًا، وصفحةٌ ثانيةٌ بلا
             * اسم المتجر ولا رقم الفاتورة ورقةٌ لا يُعرف إلى أيّ حزمةٍ
             * تعود إن سقطت منها. والترويسةُ الكاملةُ لا تتكرّر — سطرٌ
             * واحدٌ يعرّف: من، وأيُّ مستند، وبأيّ رقم.
             */
            'margin_top' => $repeats ? self::HEADER_SPACE + 6 : $preset['top'],
            'margin_header' => $repeats ? 8 : 0,
            /*
             * والسفليُّ يتّسع للتذييل: mpdf يرسم تذييل الصفحة **داخل** الهامش
             * السفليّ، فهامشٌ بقدر النصّ يجعل رقم الصفحة يركب على آخر سطر.
             */
            'margin_bottom' => $preset['bottom'],
            'margin_footer' => $preset['footer'],
        ]);

        if ($repeats) {
            $mpdf->SetHTMLHeader($runningHeader);
        }

        if ($preset['footer'] > 0) {
            self::pageNumbers($mpdf, $context);
        }
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
    public function strip(string $html, string $name, int $widthMm): Response
    {
        $height = $this->stripHeight($html, $widthMm);

        $mpdf = self::stripEngine($widthMm, $height);
        $mpdf->WriteHTML($html);

        return self::respond($mpdf, $name);
    }

    public function stripHeight(string $html, int $widthMm): float
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
    private static function pageNumbers(Mpdf $mpdf, ?string $context = null): void
    {
        /*
         * ═══ وسياقُ المستند في التذييل لا في ترويسةٍ متكرّرة ═══
         *
         * فاتورةٌ بخمسين صنفًا تمتدّ ثلاثَ صفحات، وصفحةٌ ثانيةٌ لا تحمل إلّا
         * «٢ / ٣» ورقةٌ لا يُعرف إلى أيّ حزمةٍ تعود إن سقطت — وهو ما يقع في
         * مكاتب المحاسبة حين تُفكّ الحزمةُ وتُصوَّر.
         *
         * والترويسةُ المتكرّرة كانت الحلَّ الأوّل، وطريقُها في mpdf يمرّ
         * بـ`@page` — وهو ما أطفأ `SetHTMLFooter` مرّةً فاختفى ترقيمُ
         * الصفحات من كلّ ورقةٍ في النظام بلا أن يسقط اختبار. والتذييلُ بابٌ
         * قائمٌ يعمل على كلّ صفحة بلا أن يُمسّ شيءٌ من ذلك.
         *
         * ولا يُطبع فارغًا: تقريرُ مخزونٍ لا سياقَ له، فيبقى على رقمه وحده.
         */
        $left = $context !== null && trim($context) !== ''
            ? '<span>'.htmlspecialchars(trim($context), ENT_QUOTES, 'UTF-8').'</span>'
            : '';

        $mpdf->SetHTMLFooter(
            '<table style="width:100%; border-collapse:collapse; font-family:xbriyaz; font-size:8pt; '
            .'color:#9ca3af; border-top:0.4pt solid #e5e7eb;"><tr>'
            .'<td style="border:none; padding:2mm 0 0; width:35%;">'.$left.'</td>'
            .'<td style="border:none; padding:2mm 0 0; width:30%; text-align:center;">'
            .'<span dir="ltr">{PAGENO} / {nbpg}</span></td>'
            .'<td style="border:none; padding:2mm 0 0; width:35%;"></td>'
            .'</tr></table>'
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
            /*
             * ═══ ووسيطُ الأنماط `mpdf` لا `print` ═══
             *
             * الافتراضيُّ أنّ mpdf يقرأ `@media print`. والقالبُ يضع فيه
             * `@page` ليعرف **المتصفّحُ** مقاسَ الورقة عند الطبع — وهو ما
             * لا يحتاجه mpdf: مقاسُه يصله من `format` وهوامشُه من
             * `margin_*`.
             *
             * وحين يقرؤه mpdf يقع تعارض: `@page` يُبطل `SetHTMLFooter`،
             * فيختفي ترقيمُ الصفحات من كلّ ورقةٍ في النظام — وقد وقع فعلًا،
             * ولم يظهر إلّا بقياس آخر سطرٍ فيه حبر (١٨٣ مم بدل ٢٩٠).
             *
             * فيُقرأ وسيطٌ لا يكتبه أحد. وبه يتجاهل mpdf `print` و`screen`
             * معًا، ويبقى على القواعد غير المقيَّدة بوسيط — وهي جسدُ
             * الأنماط كلُّه. فيصير لكلّ محرّكٍ ما يفهمه بلا أن يتنازعا.
             */
            'CSSselectMedia' => 'mpdf',
            /*
             * ═══ ولا يُجلب من الشبكة شيء ═══
             *
             * mpdf يسمح افتراضًا بـ`http` و`https` في مصادر الصور، فأيُّ
             * رابطٍ يبلغ حقلَ شعارٍ أو صورةَ غلاف يجعل **الخادمَ** يطلبه.
             * ورابطٌ إلى ‎169.254.169.254 أو إلى شبكةٍ داخلية يجعل الورقة
             * بابًا يُطرق به ما لا يُطرق من الخارج (SSRF).
             *
             * ورفعُ الشعار اليومَ يمرّ بتحقّقٍ يقبل الصورَ وحدها، فالبابُ
             * غيرُ مفتوحٍ من الواجهة. لكنّ الحقلَ يُملأ من استيرادٍ أو من
             * نسخةٍ مستعادة أو من سطرٍ يُضاف غدًا — والقفلُ هنا يسبق ذلك
             * كلَّه بسطر.
             *
             * والأصولُ الشرعيّة لا تمرّ من هنا أصلًا: `InvoiceBranding::logo`
             * و`Branding::cover` تضمّنان الملفَّ `data:` من قرص المتجر.
             */
            'whitelistStreamWrappers' => [],
        ];
    }

    /**
     * الردُّ بالملفّ — واسمُه منقّى قبل أن يدخل الترويسة.
     *
     * ═══ ولمَ يُنقّى ═══
     *
     * الاسمُ يُبنى من رقم الورقة، ورقمُ الورقة يبدأ ببادئةٍ **يكتبها
     * التاجر** في الإعدادات (`inv_prefix`). وتنقيتُها هناك تُزيل ما يكسر
     * شرطَ `LIKE` — `%` و`_` و`\` — ولا تُزيل علامةَ التنصيص.
     *
     * فبادئةٌ فيها `"` تُغلق اقتباسَ `filename` في الترويسة ويصير ما بعدها
     * وسائطَ أخرى. والسطرُ الجديد أخطر، وإن كان الإطارُ يردّه اليوم.
     *
     * فيُقصَر الاسمُ على ما يصلح اسمَ ملفّ: حروفٌ وأرقامٌ وشرطتان ونقطة.
     * وما سواه يصير شرطة، ولا يُترك فارغًا.
     */
    private static function respond(Mpdf $mpdf, string $name): Response
    {
        $file = self::filename($name).'.pdf';

        return response($mpdf->Output($file, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$file.'"',
        ]);
    }

    /** اسمُ ملفٍّ آمنٌ من نصٍّ قد يحمل ما لا يصلح في ترويسة */
    private static function filename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        $clean = trim((string) $clean, '-.');

        return $clean === '' ? 'document' : mb_substr($clean, 0, 80);
    }
}

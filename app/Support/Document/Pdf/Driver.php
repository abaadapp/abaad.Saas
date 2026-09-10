<?php

namespace App\Support\Document\Pdf;

use Illuminate\Http\Response;

/**
 * محرّكُ تحويل HTML إلى PDF — واجهةٌ لا تنفيذ.
 *
 * ═══ ولمَ واجهةٌ لمحرّكٍ واحد ═══
 *
 * اليومَ mpdf. وهو الصحيح لهذا الخادم: يعمل داخل PHP بلا عمليّةٍ ثانية،
 * ولا يحتاج متصفّحًا كاملًا في ذاكرةٍ لا تتّسع له.
 *
 * لكنّ لـmpdf سقفًا معروفًا: لا يعرف `flexbox` ولا `grid` ولا
 * `margin-inline-start` ولا `var()`. فالتخطيطُ بالجداول والقيمُ محسوبةٌ في
 * PHP. ويومَ يكبر الخادم — أو تُخرَج الطباعةُ إلى خدمةٍ مستقلّة — يصير
 * Chromium خيارًا يفتح ذلك السقف.
 *
 * وبلا هذه الواجهة يكون الانتقالُ بحثًا عن `new Mpdf` في المستودع كلّه.
 * وبها: صنفٌ ثانٍ يُنفّذها وسطرٌ في الإعدادات — والقوالبُ لا تُمسّ.
 *
 * والواجهةُ تصف **ما تحتاجه الورقة** لا ما يقبله mpdf: صفحةٌ بمقاسٍ
 * وهوامش، أو شريطٌ بعرضٍ وبطولِ محتواه. ولو وُصفت بلغة mpdf لصارت واجهةً
 * لا يُنفّذها إلّا mpdf.
 */
interface Driver
{
    /**
     * ورقةٌ ذاتُ صفحات — A4 أو A5.
     *
     * والمقاسُ يصل وصفًا لا اسمًا: عرضٌ وارتفاعٌ وهوامشُ بالمليمتر، من
     * `Document\PaperSize`. فالقالبُ والمحرّكُ يقرآن الأرقامَ نفسَها، ولا
     * تخرج ورقةٌ مبنيّةٌ على مقاسٍ وتُطبع على آخر.
     *
     * @param  string  $html  الرسمُ كاملًا — بأنماطه ضمنه
     * @param  string  $name  اسمُ الملفّ بلا لاحقة
     * @param  array<string, mixed>  $preset  وصفُ المقاس من `PaperSize::of`
     * @param  bool  $landscape  عرضيّةً — لجدولٍ لا تسعه الصفحة قائمة
     * @param  string|null  $runningHeader  ترويسةٌ تتكرّر على كلّ صفحة، أو null
     */
    public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null): Response;

    /**
     * شريطُ طابعةٍ حراريّة — بعرض ورقها وبطول محتواه.
     *
     * والطولُ يُقاس بالرسم لا بتقديرٍ من عدد الأسطر: طابعةٌ لا تعرف الصفحات،
     * فورقةٌ أقصرُ من محتواها تُقسَم قسمين لا يحمل أحدُهما ترويسةً ولا الآخر
     * مجموعًا.
     */
    public function strip(string $html, string $name, int $widthMm): Response;

    /** طولُ ما سيُرسم بالمليمتر — ليُقاس عليه في الاختبار */
    public function stripHeight(string $html, int $widthMm): float;
}

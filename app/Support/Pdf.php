<?php

namespace App\Support;

use App\Support\Document\PaperSize;
use App\Support\Document\Pdf\Driver;
use App\Support\Document\Pdf\MpdfDriver;
use Illuminate\Http\Response;

/**
 * بابُ الطباعة — واحدٌ لكلّ ورقةٍ في النظام.
 *
 * ═══ ولمَ بقي الاسمُ هنا والتنفيذُ انتقل ═══
 *
 * اثنان وعشرون متحكّمًا ينادون `Pdf::a4(...)`. ونقلُ النداءات إلى اسمٍ
 * جديد تغييرٌ في اثنين وعشرين ملفًّا لا يُضيف للتاجر شيئًا — ويكسر ما
 * يعمل إن سُهي عن واحد.
 *
 * فبقي البابُ حيث هو، وانتقل ما خلفَه إلى `Document\Pdf\MpdfDriver` خلف
 * واجهةٍ (`Document\Pdf\Driver`). ويومَ يُستبدل المحرّك — Chromium حين
 * يتّسع الخادم، أو خدمةُ طباعةٍ مستقلّة — يتغيّر سطرُ `driver()` وحده.
 *
 * والسقفُ الذي يُفتح يومَها معروف: mpdf لا يعرف `flexbox` ولا `grid` ولا
 * `margin-inline-start` ولا `var()`. فقوالبُ اليوم تُبنى بالجداول وبقيمٍ
 * محسوبةٍ في PHP — وهي تعمل في المحرّكين معًا، فلا يُفقَد شيءٌ بالانتظار.
 */
class Pdf
{
    private static ?Driver $driver = null;

    /**
     * المحرّكُ الحاليّ — mpdf ما لم يُستبدل.
     *
     * ولا يُبنى في مُنشئٍ ولا يُسجَّل في حاويةٍ: هذا الصنفُ يُنادى ساكنًا من
     * اثنين وعشرين موضعًا، وتحويلُه إلى خدمةٍ تُحقن تغييرٌ في كلٍّ منها.
     */
    public static function driver(): Driver
    {
        return self::$driver ??= new MpdfDriver;
    }

    /**
     * استبدالُ المحرّك — للاختبار ولليوم الذي يُستبدل فيه فعلًا.
     *
     * ويردّ السابقَ ليُعاد: اختبارٌ يبدّل المحرّك ولا يردّه يُسرّب بديلَه
     * إلى ما بعده، فتسقط اختباراتٌ لا علاقة لها به.
     */
    public static function swap(?Driver $driver): ?Driver
    {
        $was = self::$driver;
        self::$driver = $driver;

        return $was;
    }

    /**
     * ورقةُ A4 — كلُّ ما يُطبع على ورقٍ عاديّ.
     *
     * والعرضيّ للجداول التي لا تسعها الصفحة قائمةً: تقريرٌ بعشرة أعمدة على
     * ورقةٍ قائمة يخرج بأعمدةٍ ملتصقة تُقرأ بالتخمين.
     *
     * و`$runningHeader` سطرٌ يتكرّر على كلّ صفحة — تستعمله المستنداتُ
     * الطويلة وحدها. والتقاريرُ لا ترسله فلا يتغيّر عندها شيء.
     *
     * وتبقى باسمها: اثنان وعشرون تقريرًا ينادونها، ونقلُهم إلى `sheet`
     * تغييرٌ في اثنين وعشرين ملفًّا لا يُضيف للتاجر شيئًا.
     */
    public static function a4(string $html, string $name, bool $landscape = false, ?string $runningHeader = null): Response
    {
        return self::sheet($html, $name, PaperSize::A4, $landscape, $runningHeader);
    }

    /**
     * ورقةٌ بمقاسٍ يُختار — A4 أو A5.
     *
     * والمقاسُ يُحلّ هنا مرّةً إلى أرقامه، ثمّ تُبنى منه الورقةُ والقالبُ
     * معًا. فلا يقع أن يُبنى المحرّك على ١٤٨ مم ويُرسم القالبُ على ٢١٠.
     */
    public static function sheet(string $html, string $name, ?string $paper = null, bool $landscape = false, ?string $runningHeader = null): Response
    {
        return self::driver()->sheet($html, $name, PaperSize::of($paper), $landscape, $runningHeader);
    }

    /** شريطُ الطابعة الحراريّة — بعرض ورقها وبطول محتواه */
    public static function strip(string $html, string $name, int $widthMm): Response
    {
        return self::driver()->strip($html, $name, $widthMm);
    }

    /**
     * طولُ ما سيُرسم بالمليمتر — بالرسم نفسه لا بتقديرٍ من عدد الأسطر.
     *
     * وهي عامّةٌ ليُقاس عليها: «هل يطول الشريط بطول فاتورته؟» سؤالٌ لا
     * يُجاب عليه بفحص ملفّ PDF.
     */
    public static function stripHeight(string $html, int $widthMm): float
    {
        return self::driver()->stripHeight($html, $widthMm);
    }
}

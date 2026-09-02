<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * قراءةُ ملفٍّ يرفعه التاجر — من بابٍ واحد لكلّ الاستيرادات.
 *
 * وسببُ وجودها ترميزُ الملفّ لا شكلُه: «حفظ باسم ‹CSV›» في إكسل على ويندوز
 * عربيّ يكتب الملفّ بترميز **Windows-1256** لا UTF-8. فتصل الترويسة
 * «الاسم,السعر,الكمية» بايتاتٍ لا تُقرأ عربيّةً، فلا يتعرّف الكاشفُ على
 * عمودٍ واحد.
 *
 * وأثرُه أسوأ من رسالة خطأ: الاستيراد **ينجح** — يُسنَد «السعر» إلى «التصنيف»
 * و«الكمية» إلى رمز الصنف، ويصير صفُّ العناوين منتجًا. فيدخل المتجرَ صنفٌ
 * اسمه طلاسم بسعر صفر، وتضيع أسعارُ الملفّ كلِّه ولا يقول شيءٌ إنّ شيئًا وقع.
 *
 * وهي أكثرُ طريقةٍ يُصدَّر بها جردُ محلٍّ في عُمان: يفتح صاحبُه إكسل ويحفظ.
 */
class Sheet
{
    /**
     * ترميزُ ملفّ CSV كما هو لا كما يُفترض.
     *
     * وUTF-8 هي الافتراض حين تصحّ: ملفٌّ لاتينيٌّ كلُّه صالحٌ فيها، وما لا
     * يصحّ فيها فهو من صفحة ويندوز العربية — وهي الوحيدة العمليّة هنا.
     * وتخمينٌ أوسع (ISO-8859-6 وغيرها) يُخطئ أكثر ممّا يُصيب على نصٍّ عربيّ.
     */
    public static function encoding(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '' || mb_check_encoding($bytes, 'UTF-8')) {
            return 'UTF-8';
        }

        return 'CP1256';
    }

    /** قارئٌ مضبوطُ الترميز — و`setInputEncoding` لا معنى لها إلّا في CSV */
    public static function reader(string $path): IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if ($reader instanceof Csv) {
            $reader->setInputEncoding(self::encoding($path));
        }

        return $reader;
    }

    public static function spreadsheet(string $path): Spreadsheet
    {
        return self::reader($path)->load($path);
    }

    /**
     * صفوفُ الورقة الأولى — بالقيم كما كُتبت.
     *
     * والمعاملات كما كانت في المتحكّمات الأربعة: بلا تنسيقٍ ولا فهرسةٍ
     * بأسماء الأعمدة، فالرقمُ يصل رقمًا والفهرسُ من صفر.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function rows(string $path): array
    {
        return self::spreadsheet($path)->getActiveSheet()->toArray(null, true, false, false);
    }
}

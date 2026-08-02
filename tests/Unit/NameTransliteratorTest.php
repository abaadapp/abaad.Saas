<?php

namespace Tests\Unit;

use App\Support\Customers;
use App\Support\NameTransliterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * العقد: «الكل أو لا شيء».
 *
 * الاسم الذي تُفهم كل كلماته يُترجم كاملًا، وأي اسم فيه كلمة واحدة خارج
 * القاموس يبقى كما أُدخل. الخليط («أحمد Shamsi») ممنوع — كان يظهر قبلًا
 * وهو أسوأ للقارئ من الإنجليزي كاملًا.
 */
class NameTransliteratorTest extends TestCase
{
    public static function fullyTranslated(): array
    {
        return [
            'اسم بسيط' => ['Mohammed Salem', 'محمد سالم'],
            'لقب عُماني ببادئة منفصلة' => ['Ahmed Al Shamsi', 'أحمد الشامسي'],
            'لقب عُماني ببادئة ملتصقة' => ['Abdulrahim Alharthi', 'عبدالرحيم الحارثي'],
            'ثلاثي' => ['Sara Ahmed Khalid', 'سارة أحمد خالد'],
            'اسم غربي' => ['John Smith', 'جون سميث'],
            'اسم مفرد' => ['Fatima', 'فاطمة'],
        ];
    }

    #[DataProvider('fullyTranslated')]
    public function test_it_translates_names_it_fully_understands(string $input, string $expected): void
    {
        $this->assertSame($expected, NameTransliterator::toArabic($input));
    }

    public static function notTranslated(): array
    {
        return [
            'كلمة أخيرة مجهولة' => ['Said Al Qwertyz'],
            'الاسم كله مجهول' => ['Zhang Wei'],
            'كلمة أولى مجهولة' => ['Xyzq Salem'],
            'بادئة معلّقة بلا اسم بعدها' => ['Ahmed Al'],
        ];
    }

    #[DataProvider('notTranslated')]
    public function test_it_returns_null_rather_than_a_half_arabic_name(string $input): void
    {
        $this->assertNull(
            NameTransliterator::toArabic($input),
            'الترجمة الجزئية تُنتج اسمًا مختلطًا — يجب رفضها.',
        );
    }

    public function test_arabic_input_is_not_treated_as_latin(): void
    {
        $this->assertFalse(NameTransliterator::isLatin('فاطمة الحارثية'));
        $this->assertTrue(NameTransliterator::isLatin('Fatima Al Harthi'));
    }

    public function test_localize_name_keeps_the_original_in_name_en(): void
    {
        $data = Customers::localizeName(['name' => 'Ahmed Al Shamsi']);

        $this->assertSame('أحمد الشامسي', $data['name']);
        $this->assertSame('Ahmed Al Shamsi', $data['name_en']);
    }

    public function test_localize_name_keeps_an_untranslatable_name_as_entered(): void
    {
        $data = Customers::localizeName(['name' => 'Zhang Wei']);

        $this->assertSame('Zhang Wei', $data['name']);
        $this->assertSame('Zhang Wei', $data['name_en']);
    }

    public function test_localize_name_leaves_arabic_input_alone(): void
    {
        $data = Customers::localizeName(['name' => 'محمد سالم']);

        $this->assertSame('محمد سالم', $data['name']);
        $this->assertNull($data['name_en']);
    }
}

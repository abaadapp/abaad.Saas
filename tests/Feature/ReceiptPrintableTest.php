<?php

namespace Tests\Feature;

use App\Support\ReceiptTemplate;
use Tests\TestCase;

/**
 * ما لا يطبعه خطّ الإيصال لا يُرسَل إليه.
 *
 * خطّ الـPDF عربيّ ولاتينيّ ولا يحمل الرموز التعبيرية، فكل إيموجي يخرج
 * مربّعًا فارغًا على ورق الزبون. والتذييل الافتراضي نفسه كان يحمل وردةً —
 * أي أن كل إيصالٍ طُبع بمربّعٍ فيه، ولم يظهر في اختبارٍ قطّ لأن الاختبارات
 * تقيس بايتات الملفّ لا الحروف المرسومة. ولم يُكتشف إلا بالنظر إلى ورقة.
 */
class ReceiptPrintableTest extends TestCase
{
    public function test_it_removes_what_the_font_cannot_draw(): void
    {
        $this->assertSame('شكرًا لزيارتكم', ReceiptTemplate::printable('شكرًا لزيارتكم 🌹'));
        $this->assertSame('تم', ReceiptTemplate::printable('✅ تم'));
        $this->assertSame('اتصل بنا', ReceiptTemplate::printable('☎ اتصل بنا'));
    }

    public function test_it_keeps_every_letter_the_merchant_wrote(): void
    {
        /*
         * الحذف يجب أن يكون ضيّقًا: تذييلُ التاجر كلامُه هو، وحذفُ حرفٍ منه
         * أسوأ من مربّعٍ — المربّع يُرى ويُسأل عنه، والحرف الناقص يمرّ.
         */
        $text = 'متجر الورود — هاتف: +968 91234567 · العنوان: مسقط، الخوير';

        $this->assertSame($text, ReceiptTemplate::printable($text));
    }

    public function test_a_line_that_was_only_an_emoji_becomes_empty_not_a_blank_gap(): void
    {
        // سطرٌ فارغ في وسط التذييل يُقرأ عطبًا في الطابعة
        $this->assertSame('', ReceiptTemplate::printable('🌹🌸💐'));
    }

    public function test_it_does_not_leave_a_double_space_behind(): void
    {
        $this->assertSame('شكرًا لك', ReceiptTemplate::printable('شكرًا 🌹 لك'));
    }

    public function test_arabic_diacritics_and_tatweel_survive(): void
    {
        // علاماتٌ في نطاقاتٍ قريبة من المحذوف — الحدّ يجب أن يقف دونها
        $this->assertSame('شُكرًا جزيلاً', ReceiptTemplate::printable('شُكرًا جزيلاً'));
        $this->assertSame('مرحبـــا', ReceiptTemplate::printable('مرحبـــا'));
    }
}

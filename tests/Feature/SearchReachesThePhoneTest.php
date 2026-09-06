<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صندوقُ البحث يغيب عن الهاتف بلا بديل.
 *
 * كان المكوّن كلُّه `hidden … sm:block`: يختفي تحت ٦٤٠ بكسل ولا يخلفه شيء
 * — لا أيقونةَ ولا مدخل. والهاتف هو ما يقف عليه الكاشير وما يحمله صاحب
 * المحلّ وهو خارج متجره، فأكثرُ من يحتاج «ابحث عن رقم فاتورة» كان محرومًا
 * منه. وبابٌ يغيب عن أضيق شاشةٍ يُستعمل عليها ليس بابًا.
 *
 * فصارت أيقونةٌ تُضغط فيتمدّد الصندوق فوق الترويسة، وزرٌّ يعيدها.
 */
class SearchReachesThePhoneTest extends TestCase
{
    private function code(): string
    {
        return file_get_contents(resource_path('js/Components/UnifiedSearch.tsx'));
    }

    /** الأيقونة موجودةٌ وتخصّ الشاشة الضيّقة وحدها */
    public function test_a_phone_gets_a_search_button(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('sm:hidden', $code, 'لا أيقونةَ بحثٍ للهاتف');
        $this->assertStringContainsString('setOnPhone', $code);
    }

    /** ولا يبقى الصندوق ممتدًّا بلا مخرج */
    public function test_the_expanded_box_can_be_shut(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('const shut = () =>', $code, 'لا مخرجَ من صندوق الهاتف');
        $this->assertStringContainsString('إغلاق البحث', $code);
    }

    /** ولا يُخفى المكوّن كلُّه على الهاتف بلا بديل */
    public function test_the_whole_box_is_not_hidden_on_a_phone(): void
    {
        $code = $this->code();

        // الشكلُ القديم: أوّلُ ما يُرسم صندوقٌ مخفيٌّ ولا شيء غيره
        $this->assertDoesNotMatchRegularExpression(
            '/return \(\s*<div ref=\{boxRef\} className="relative hidden/',
            $code,
            'المكوّن يبدأ بصندوقٍ مخفيٍّ على الهاتف بلا بديل',
        );
    }

    /** والترويسة تستضيفه: الشريط الممتدّ يحتاج مرجعًا يتموضع عليه */
    public function test_the_header_can_host_the_expanded_bar(): void
    {
        $topbar = file_get_contents(resource_path('js/Components/Topbar.tsx'));

        $this->assertMatchesRegularExpression(
            '/<header className="(sticky|relative)[^"]*"/',
            $topbar,
            'الترويسة غير متموضعة، فالشريط الممتدّ يخرج عنها',
        );
    }
}

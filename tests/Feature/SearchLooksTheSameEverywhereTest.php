<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صندوقُ البحث يغيب عن الهاتف.
 *
 * كان المكوّن كلُّه `hidden … sm:block`: يختفي تحت ٦٤٠ بكسل ولا يخلفه شيء
 * — لا أيقونةَ ولا مدخل. والهاتفُ هو ما يقف عليه الكاشير وما يحمله صاحبُ
 * المحلّ وهو خارج متجره، فأكثرُ من يحتاج «ابحث عن رقم فاتورة» كان محرومًا
 * منه، بينما يراه من يجلس أمام شاشةٍ كبيرة ولا يحتاجه.
 *
 * والحقلُ واحدٌ على العرضين — لا أيقونةً على الهاتف وحقلًا على الحاسوب:
 * شكلان لبابٍ واحد يجعلان من تعلّمه على جهازٍ يبحث عنه على الآخر.
 */
class SearchLooksTheSameEverywhereTest extends TestCase
{
    private function code(): string
    {
        return file_get_contents(resource_path('js/Components/UnifiedSearch.tsx'));
    }

    /** الحقل ظاهرٌ على كلّ عرض — لا يُخفى تحت نقطة انكسار */
    public function test_the_box_is_never_hidden_by_width(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<div ref=\{boxRef\} className="[^"]*\bhidden\b/',
            $this->code(),
            'صندوق البحث يُخفى على الشاشات الضيّقة',
        );
    }

    /** ولا ينكمش دافعًا أزرارَ الترويسة خارج الشاشة */
    public function test_the_box_may_shrink_inside_the_header(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div ref=\{boxRef\} className="[^"]*\bmin-w-0\b/',
            $this->code(),
            'بلا min-w-0 يرفض الحقل الانكماش فيدفع الأزرار خارج الشاشة',
        );

        $this->assertStringContainsString(
            'ms-auto flex shrink-0 items-center',
            file_get_contents(resource_path('js/Components/Topbar.tsx')),
            'صفُّ الأزرار ينضغط بدل أن ينكمش الحقل',
        );
    }

    /**
     * ولا يُسكب دليلُ الصفحات قبل أن يُكتب حرف.
     *
     * كانت نقرةُ الصندوق تفتح قائمةً بكلّ صفحةٍ في النظام — عشرين سطرًا
     * يتكرّر في كلٍّ منها «أقسام النظام» — فيقرؤها من فتحه ليبحث عن رقم
     * فاتورة. وقائمةٌ لا تُقرأ تُغلق قبل أن يُكتب فيها شيء.
     */
    public function test_nothing_is_listed_before_a_letter_is_typed(): void
    {
        $this->assertStringNotContainsString(
            'if (!term) return pages;',
            $this->code(),
            'الصندوق يعرض دليل الصفحات كلَّه قبل الكتابة',
        );

        $this->assertStringContainsString('if (!term) return [];', $this->code());
    }

    /** والقائمة تُقرأ على الهاتف: بعرض الشاشة لا بعرض الحقل الضيّق */
    public function test_the_results_are_readable_on_a_phone(): void
    {
        $this->assertStringContainsString('inset-x-4', $this->code(), 'قائمةُ النتائج بعرض الحقل الضيّق');
        $this->assertStringContainsString('sm:w-full', $this->code());
    }
}

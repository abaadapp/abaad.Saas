<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الآيباد يُلمَس ولا يُنقر — وإن قال عن نفسه غيرَ ذلك.
 *
 * سفاري عليه يفتح المواقع بوضع «عرض كموقع حاسوب» افتراضًا منذ iPadOS 13،
 * فيردّ على `(pointer: coarse)` بـ`fine`. وكان كلُّ ما كُتب للإصبع معلَّقًا
 * على ذلك الاستعلام: أزرارُ نقطة البيع، وعدّاداتُ السلّة، وارتفاعُ الحقول
 * — فكانت تُرسم بمقاسِ الفأرة على الجهاز الذي كُتبت له، ويمدّ الكاشير
 * إبهامَه إلى زرٍّ بأربعين بكسلًا.
 *
 * فصار المحوّل `touch:` يقبل بابين: الاستعلامَ لمن يصدق فيه، وسمةً على
 * `<html>` يكتبها `lib/touch` بعد أن تقرأ `maxTouchPoints`.
 *
 * وهذا الحارس يقيس ما لا يقيسه متصفّحُ الاختبار: أنّ البابين ما زالا
 * مفتوحين، وأنّ اسمَ السمة واحدٌ في الملفّين، وأنّ الاسمَ الصامتَ القديم لا
 * يعود. والسلوكُ نفسُه — متى تُكتب السمة ومتى لا تُكتب — مقيسٌ في
 * `tests/js/the-thumb-gets-a-bigger-target.test.ts`.
 */
class TheTabletIsTouchedNotClickedTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(resource_path($path));
    }

    /** تعريفُ المحوّل وحدَه — لا `app.css` كلُّه */
    private function variant(): string
    {
        $css = $this->read('css/app.css');
        $at = strpos($css, '@custom-variant touch {');

        $this->assertNotFalse($at, 'المحوّل `touch:` غير معرَّف — فكلُّ ما كُتب للإصبع لا يُولَّد أصلًا');

        return substr($css, $at, strpos($css, "\n}\n", $at) - $at);
    }

    /** ملفّات الواجهة كلُّها */
    private function screens(): array
    {
        $out = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public function test_no_screen_uses_the_variant_the_tablet_silences(): void
    {
        $guilty = [];

        foreach ($this->screens() as $path) {
            if (str_contains((string) file_get_contents($path), 'pointer-coarse:')) {
                $guilty[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $guilty,
            'محوّلٌ يصمت على الآيباد: `pointer-coarse:` استعلامٌ وحده، واستعمِل `touch:`',
        );
    }

    public function test_the_variant_still_opens_for_the_media_query(): void
    {
        /*
         * البابُ الأوّل يعمل قبل أوّل سطرِ JS.
         *
         * ولو حُذف واكتُفي بالسمة لَرُسم الهاتفُ بمقاسِ الفأرة حتى تُقلع
         * الحزمة — ومَن يفتح نقطةَ البيع على شبكةٍ بطيئة يرى ذلك.
         */
        $this->assertStringContainsString(
            '@media (pointer: coarse)',
            $this->variant(),
            'البابُ الأوّل أُغلق: الهاتفُ ينتظر JS ليأخذ مقاسَه',
        );
    }

    public function test_the_variant_opens_for_the_attribute_the_boot_writes(): void
    {
        preg_match("/setAttribute\('([a-z-]+)', ''\)/", $this->read('js/lib/touch.ts'), $m);

        $this->assertNotEmpty($m, 'لا سمةَ تُكتب في `lib/touch` — فالبابُ الثاني لا يُطرق');

        $this->assertStringContainsString(
            "[{$m[1]}] &",
            $this->variant(),
            "المحوّل لا يقرأ ما يكتبه الإقلاع: يُكتب `{$m[1]}` ويُقرأ سواه",
        );
    }

    public function test_the_boot_marks_the_device_before_it_draws(): void
    {
        $boot = $this->read('js/app.tsx');

        $mark = strpos($boot, 'markTouchDevice()');
        $draw = strpos($boot, 'createRoot(');

        $this->assertNotFalse($mark, 'الإقلاعُ لا يسأل عن الجهاز — فالسمةُ لا تُكتب أبدًا');
        $this->assertLessThan(
            $draw,
            $mark,
            'السمةُ تُكتب بعد الرسم: تُرسم الأزرارُ بمقاسِ سطح المكتب ثمّ تقفز تحت الإصبع',
        );
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفٌّ مرنٌ يقصّ ما يفيض منه — وابنٌ فيه بلا حدٍّ أدنى يدفع أخاه خارج الشاشة.
 *
 * ═══ العطب ═══
 *
 * شاشةُ الصندوق صفٌّ أفقيّ: المنتجاتُ تتمدّد، والسلّةُ بعرضٍ ثابتٍ بجانبها،
 * والغلافُ `overflow-hidden`.
 *
 * وأدنى عرضٍ لعنصرٍ مرنٍ هو `auto` — أي عرضُ محتواه لا صفر. فصفُّ البحث
 * والباركود داخل قسم المنتجات لا ينضغط تحت مقاسه، ويبقى القسمُ ٩٧٢ بكسلًا
 * مهما ضاقت الشاشة. فتُدفع السلّةُ إلى ما بعد الحافّة، ولا تُمرَّر لأنّ
 * الغلافَ يقصّ: **تختفي**.
 *
 * وعلى ١٠٢٤ — مقاسُ اللوحيّ الذي يقف عليه الصندوق — كانت السلّة تبدأ عند
 * ‎−٣١١ وتنتهي عند ٢٠: يرى الكاشير عشرين بكسلًا منها. لا مجموع، ولا بند،
 * ولا زرَّ دفع. وقِيست بالمتصفّح: ١٤٤٠ و١٢٨٠ و١٠٢٤ كلُّها مصابة، و١٩٢٠
 * سليمة — ولذلك لم تُرَ على شاشة من كتبها.
 *
 * ═══ ولماذا فحصُ مصدرٍ لا فحصُ متصفّح ═══
 *
 * `jsdom` لا يحسب تخطيطًا: كلُّ عرضٍ فيه صفر، فاختبارُ واجهةٍ لا يرى هذا
 * أبدًا. والمتصفّحُ يراه ولا يعمل في CI هنا.
 *
 * فيُسأل المصدرُ عن الشرط الذي يمنع العطب: كلُّ ابنٍ يتمدّد داخل صفٍّ أفقيّ
 * يقصّ يحمل `min-w-0`. وهي قاعدةٌ تُقرأ ولا تُحفظ — «قائمةٌ تُكتب باليد
 * تنسى التاليَ دائمًا»، فتُقرأ الشاشاتُ كلُّها لا شاشةُ الصندوق وحدها.
 */
class ACartThatIsPushedOffTheScreenIsNotACartTest extends TestCase
{
    public function test_no_stretching_child_of_a_clipping_row_forgets_its_floor(): void
    {
        $offenders = [];

        foreach ($this->screens() as $file => $source) {
            foreach ($this->classAttributes($source) as [$offset, $child]) {
                // ابنٌ يتمدّد، وقد أُعطي حدَّه الأدنى صراحةً
                if (! preg_match('/\bflex-1\b/', $child) || str_contains($child, 'min-w-0')) {
                    continue;
                }

                if ($this->clippingRowBefore($source, $offset)) {
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $offenders[] = $file.':'.$line.' → '.trim($child);
                }
            }
        }

        $this->assertSame([], $offenders, "ابنٌ يتمدّد في صفٍّ أفقيٍّ يقصّ بلا `min-w-0` — يدفع أخاه خارج الشاشة:\n".implode("\n", $offenders));
    }

    /**
     * كلُّ `className` في الملفّ بموضعها — لا سطرًا سطرًا.
     *
     * والقراءةُ بالأسطر كانت تُفلت المخالفةَ المكتوبةَ في سطرٍ واحد:
     * `<div class="flex flex-row overflow-hidden"><div class="flex-1" />` —
     * الغلافُ والابنُ معًا، فلا يجد الحارسُ غلافًا «فوق» السطر. وقد نجت
     * هذه الطفرةُ فعلًا قبل أن يُعاد كتابتُه.
     *
     * @return array<int, array{int, string}>
     */
    private function classAttributes(string $source): array
    {
        preg_match_all('/className="([^"]*)"/', $source, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn ($one) => [$one[1], $one[0]], $m[1]);
    }

    /**
     * أقربُ غلافٍ قبل هذا الموضع: أهو صفٌّ أفقيٌّ يقصّ؟
     *
     * ويُقطع البحثُ عند أوّل صفٍّ أفقيّ — فغلافٌ أبعد منه لا يحكم هذا الابن.
     */
    private function clippingRowBefore(string $source, int $offset): bool
    {
        $before = array_filter($this->classAttributes($source), fn ($one) => $one[0] < $offset);

        foreach (array_reverse($before) as [, $parent]) {
            if (! str_contains($parent, 'flex')) {
                continue;
            }

            if (preg_match('/(^|[\s:])flex-row\b/', $parent) !== 1) {
                continue;
            }

            return str_contains($parent, 'overflow-hidden');
        }

        return false;
    }

    /** @return array<string, string> */
    private function screens(): array
    {
        $out = [];
        $root = resource_path('js');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'tsx') {
                $out[str_replace(base_path().'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        ksort($out);

        return $out;
    }

    /** ولا يمرّ الحارسُ فارغًا: لو لم يُقرأ ملفٌّ لَقال «لا مخالف» وهو لم ينظر */
    public function test_the_guard_actually_reads_the_screens(): void
    {
        $screens = $this->screens();

        $this->assertGreaterThan(100, count($screens), 'الحارس لم يقرأ الشاشات');
        $this->assertArrayHasKey('resources/js/Pages/Pos/Index.tsx', $screens);
        $this->assertStringContainsString('min-w-0', $screens['resources/js/Pages/Pos/Index.tsx']);
    }
}

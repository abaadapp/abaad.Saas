<?php

namespace Tests\Feature;

use App\Support\Website\Sections;
use Tests\TestCase;

/**
 * المعاينة والموقع يُرسمان بالشيفرة نفسها.
 *
 * `resources/js/Pages/Admin/Website/preview/renderer/` ليست نسخةً «مأخوذة
 * عن» عارض المتاجر — هي هو، تُنسخ إليه بأمرٍ واحد من مستودع `Storefront`
 * (`node scripts/sync-renderer.mjs`). والبصمة المسجَّلة بجانبها هي ما يمنع
 * الافتراق: من عدّل ملفًّا هنا بيده ولم يُعدّل الأصل يسقط هذا الاختبار.
 *
 * ولماذا كلّ هذا؟ لأنّ الافتراق يظهر في أسوأ موضعٍ ممكن: التاجر يرى في
 * معاينته موقعًا، ثمّ ينشر، فيرى زبونُه موقعًا آخر. وهو لا يشكّ في المعاينة
 * — يشكّ في نفسه.
 */
class RendererParityTest extends TestCase
{
    private function dir(): string
    {
        return resource_path('js/Pages/Admin/Website/preview/renderer');
    }

    /** البصمة نفسها التي يحسبها `scripts/renderer-hash.mjs` */
    private function fingerprint(string $dir): string
    {
        $files = array_values(array_filter(
            scandir($dir) ?: [],
            fn ($f) => (bool) preg_match('/\.(tsx?|css)$/', $f),
        ));
        sort($files);

        $context = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($context, $file);
            hash_update($context, "\0");
            hash_update($context, (string) file_get_contents($dir.'/'.$file));
            hash_update($context, "\0");
        }

        return substr(hash_final($context), 0, 16);
    }

    public function test_the_vendored_renderer_matches_its_recorded_fingerprint(): void
    {
        $dir = $this->dir();

        $this->assertFileExists($dir.'/RENDERER_HASH', 'طبقة الرسم غير منسوخة — شغّل sync-renderer في مستودع العارض');

        $recorded = trim((string) file_get_contents($dir.'/RENDERER_HASH'));

        $this->assertSame(
            $recorded,
            $this->fingerprint($dir),
            'طبقة الرسم عُدّلت هنا بلا مزامنة: عدّلها في مستودع Storefront ثمّ شغّل `node scripts/sync-renderer.mjs`',
        );
    }

    public function test_every_section_type_in_the_catalogue_has_a_renderer(): void
    {
        $blocks = (string) file_get_contents($this->dir().'/blocks.tsx');

        // سجلُّ الرسم: `REGISTRY` — يُقرأ منه، فلا قائمةٌ ثانية تُكتب هنا
        $registry = substr($blocks, (int) strpos($blocks, 'export const REGISTRY'));
        $registry = substr($registry, 0, (int) strpos($registry, '};'));

        preg_match_all('/^\s{4}(\w+):/m', $registry, $matches);
        $drawn = $matches[1];

        $this->assertNotEmpty($drawn, 'لم يُقرأ سجلُّ الأقسام من blocks.tsx');

        foreach (Sections::CATALOGUE as $type => $spec) {
            // الترويسة والتذييل لهما مكوّناهما لا مدخلٌ في السجلّ
            if ($spec['slot'] ?? false) {
                $this->assertFileExists(
                    $this->dir().'/'.($type === Sections::HEADER ? 'Header.tsx' : 'Footer.tsx'),
                );

                continue;
            }

            $this->assertContains($type, $drawn, "القسم «{$type}» في مكتبة أبعاد بلا رسمٍ في العارض");
        }
    }

    public function test_the_renderer_carries_no_dependency_beyond_react(): void
    {
        /*
         * وهذا شرط بقائها مشتركة.
         *
         * أيّ استيرادٍ من `@/…` أو من حزمةٍ يجعلها تعمل في أحد المستودعين
         * دون الآخر — فتُنسخ فتنكسر، فتُصلَح بيدها هناك، فتفترق.
         */
        foreach (glob($this->dir().'/*.{ts,tsx}', GLOB_BRACE) ?: [] as $file) {
            preg_match_all("/from '([^']+)'/", (string) file_get_contents($file), $m);

            foreach ($m[1] as $import) {
                $allowed = str_starts_with($import, '.') || $import === 'react';

                $this->assertTrue($allowed, basename($file).' تستورد «'.$import.'» — وطبقة الرسم بلا تبعيّات');
            }
        }
    }
}

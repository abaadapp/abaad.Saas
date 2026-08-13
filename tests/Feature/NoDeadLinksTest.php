<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * كل رابطٍ في الواجهة يقود إلى مسارٍ موجود.
 *
 * `route('admin.x')` على مسارٍ محذوف لا يُخطئ عند البناء: يسقط في المتصفح
 * لحظةَ يُعرض الزرّ، فتُصبح الصفحةُ كلّها بيضاء عند من فتحها — لا الزرّ وحده.
 * والعطب لا يظهر لمن كتب الحذف لأنه لا يفتح كل شاشة.
 *
 * ولهذا يُفحص من المصدر لا بالتصفّح: يُقرأ كل `route('…')` وكل `routeName`
 * في الواجهة والخادم، ويُقارَن بجدول المسارات المسجَّل فعلًا.
 */
class NoDeadLinksTest extends TestCase
{
    /** ما يُفحص: الواجهة والقوالب والخادم */
    private const ROOTS = ['resources/js', 'resources/views', 'app'];

    public function test_no_link_points_at_a_route_that_no_longer_exists(): void
    {
        $known = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter()->flip();
        $dead = [];

        foreach ($this->sourceFiles() as $file) {
            $code = file_get_contents($file);
            $relative = str_replace(base_path().'/', '', $file);

            /*
             * الاسم الحرفيّ وحده.
             *
             * `route($name)` بمتغيّر لا يُفحص هنا — ولا يُدَّعى أنه يُفحص:
             * اختبارٌ يزعم تغطيةً لا يملكها أسوأ من لا اختبار.
             */
            preg_match_all("/route\(\s*'([a-z][a-zA-Z0-9_.-]*\.[a-zA-Z0-9_.-]+)'/", $code, $calls);
            preg_match_all('/routeName=\{?[\'"]([a-zA-Z0-9_.-]+)[\'"]/', $code, $props);

            foreach (array_merge($calls[1], $props[1]) as $name) {
                if (! $known->has($name)) {
                    $dead[] = "{$relative} → {$name}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($dead)), 'روابط تقود إلى مسارات محذوفة');
    }

    /** @return iterable<string> */
    private function sourceFiles(): iterable
    {
        foreach (self::ROOTS as $root) {
            $path = base_path($root);
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['tsx', 'ts', 'php'], true)) {
                    yield $file->getPathname();
                }
            }
        }
    }
}

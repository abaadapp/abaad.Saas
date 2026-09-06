<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * رابطٌ يقصد قسمًا لا وجود له في الإعدادات.
 *
 * شاشتا لوحة الموقع والسيو تعرضان «اضبط النطاق» و«إعدادات النطاق» ويقصدان
 * `?section=domain`. ولا قسمَ بهذا الاسم: أقسام الإعدادات تُبنى من
 * `SettingsNav`، وليس فيها `domain` — كان في فرعٍ لم يُدمج.
 *
 * والقيمة الغريبة لا تُخطئ أحدًا. `tabFromUrl` تردّ `null` عمدًا كي لا يُعرض
 * قسمٌ فارغ، فيهبط التاجر في لوحة الإعدادات لا في النطاق: يضغط زرًّا يقول
 * «اضبط النطاق» فيصل إلى شاشةٍ لا نطاقَ فيها، ولا رسالةَ تقول له لماذا.
 * ومقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض.
 *
 * والحارسُ يقرأ الاسمين من موضعهما: مفاتيحُ الأقسام من `SettingsNav`،
 * والروابطُ من كلّ ملفّ في `Pages` — فلا تُكتب قائمةٌ باليد تنسى التاليَ.
 */
class EverySettingsLinkNamesARealSectionTest extends TestCase
{
    /** كلُّ ملفّات الواجهة تحت `Pages` */
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

    /** مفاتيح أقسام الإعدادات كما تبنيها القائمة نفسها */
    private function sectionKeys(): array
    {
        $nav = file_get_contents(resource_path('js/Pages/Admin/Settings/partials/SettingsNav.tsx'));

        preg_match_all("/\bkey: '([a-z0-9-]+)'/", $nav, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_the_settings_nav_declares_its_sections(): void
    {
        $keys = $this->sectionKeys();

        $this->assertNotEmpty($keys, 'لم تُقرأ أقسام الإعدادات');
        $this->assertContains('website', $keys);
    }

    public function test_no_screen_points_at_a_section_that_does_not_exist(): void
    {
        $keys = $this->sectionKeys();
        $dead = [];

        foreach ($this->screens() as $path) {
            $code = file_get_contents($path);

            // الروابط المكتوبة حرفيًّا وحدها — والمحسوبة من متغيّر تُترك
            preg_match_all(
                "/admin\.settings\.index'\s*,\s*\{\s*section:\s*'([a-z0-9-]+)'/",
                $code,
                $m
            );

            foreach ($m[1] as $section) {
                if (! in_array($section, $keys, true)) {
                    $dead[] = basename($path).' → '.$section;
                }
            }
        }

        $this->assertSame([], $dead, 'روابطُ تقصد أقسامًا لا وجود لها: '.implode('، ', $dead));
    }
}

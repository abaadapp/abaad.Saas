<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * مسار صفحات Inertia مضبوطٌ بحروفه.
 *
 * افتراضي الحزمة js/pages ومجلّدنا js/Pages. وmacOS لا يفرّق بين الحرفين
 * فيمرّ كلُّ شيء على جهاز المطوّر، ولينكس يفرّق فينهار على الخادم — عطبٌ
 * لا يظهر إلا بعد الدفع، وهو أسوأ أنواعه.
 *
 * ولا يكفي is_dir هنا: هو نفسه لا يفرّق على macOS، فيمرّ الاختبار على
 * مسارٍ خاطئ ويقول «سليم». فيُقرأ المجلّد الأب ويُطابق الاسم حرفًا بحرف.
 */
class InertiaPagePathTest extends TestCase
{
    public function test_the_configured_pages_path_matches_the_folder_letter_for_letter(): void
    {
        $paths = config('inertia.pages.paths');

        $this->assertNotEmpty($paths, 'مسار صفحات Inertia غير مضبوط.');

        foreach ($paths as $path) {
            $parent = dirname($path);
            $name = basename($path);

            $this->assertContains(
                $name,
                scandir($parent) ?: [],
                "المجلّد [{$name}] غير موجود بهذا الرسم داخل [{$parent}] — يمرّ على macOS وينكسر على لينكس.",
            );
        }
    }

    public function test_a_known_page_resolves_through_the_finder(): void
    {
        // ما يستعمله assertInertia()->component() فعلًا — لا افتراضًا عنه
        $found = app('inertia.view-finder')->find('Auth/Login');

        $this->assertStringEndsWith('resources/js/Pages/Auth/Login.tsx', $found);
    }
}

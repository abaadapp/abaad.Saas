<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * صفحةٌ داخلية بلا طريقٍ يعود منها.
 *
 * «إضافة موظف» و«تعديل الموظف» كانتا تُفتحان ولا تُغلقان: ينتهي المدير من
 * الحقول فلا يجد إلّا زرّ المتصفّح — وهو على آيباد في المحلّ نصفُ مخفيّ،
 * وفي الشاشة المثبّتة على الحائط غيرُ موجود. فيضغط «حفظ» ليخرج، أو يترك
 * الصفحة معلّقة.
 *
 * والوجهة مكتوبةٌ باسمها لا `history.back()`: العودةُ إلى «ما كان قبلُ»
 * تصدق حين يأتي الزائر من القائمة، وتكذب حين يأتي من رابطٍ محفوظ أو من
 * إعادة توجيهٍ بعد حفظ — فتُخرجه من النظام أو تُعيده إلى صفحة إرسال.
 */
class EveryDeepScreenHasItsWayBackTest extends TestCase
{
    /** الصفحة الداخلية → المسار الذي تعود إليه */
    private const DEEP = [
        'Admin/Employees/Create.tsx' => 'admin.employees.index',
        'Admin/Employees/Edit.tsx' => 'admin.employees.show',
        'Admin/Employees/Show.tsx' => 'admin.employees.index',
    ];

    public function test_every_deep_employee_screen_carries_a_back_link(): void
    {
        foreach (self::DEEP as $page => $destination) {
            $code = file_get_contents(resource_path('js/Pages/'.$page));

            $this->assertStringContainsString('BackLink', $code, $page.' بلا طريقٍ يعود منه');
            $this->assertStringContainsString("routeName=\"{$destination}\"", $code, $page.' يعود إلى غير بابه');
        }
    }

    /** والوجهة مسارٌ قائم — نصيحةٌ تُحيل إلى مسارٍ محذوف أسوأ من لا شيء */
    public function test_the_destinations_are_real_routes(): void
    {
        foreach (array_unique(array_values(self::DEEP)) as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    /**
     * وشاشتا الرواتب أختان لا واحدةٌ تحت الأخرى.
     *
     * «مسيرة الرواتب» و«صرف الرواتب» و«الموظفون» ثلاثُ تبويباتٍ لقسمٍ واحد،
     * وكلٌّ منها تعرض الأخريين. فسهمُ رجوعٍ فيها يشير إلى صفحةٍ معروضةٍ فوقه
     * — ومقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض.
     */
    public function test_the_payroll_screens_show_their_siblings_instead(): void
    {
        foreach (['Admin/Payroll/Index.tsx', 'Admin/Payroll/Payments.tsx', 'Admin/Employees/Index.tsx'] as $page) {
            $code = file_get_contents(resource_path('js/Pages/'.$page));

            $this->assertStringContainsString('EMPLOYEE_TABS', $code, $page);
            $this->assertStringNotContainsString('BackLink', $code, $page.' سهمُ رجوعٍ فوق تبويباتٍ تعرض الوجهة');
        }
    }

    /**
     * والسهم من مصدرٍ واحد في اللوحتين.
     *
     * ويُقاس برابط تنقّلٍ نصُّه «رجوع» لا بالكلمة وحدها: «رجوع» في نافذةٍ
     * تعني «ارجع خطوةً في هذه النافذة» لا «غادر الصفحة»، وصندوق البيع يرسم
     * أزراره كبيرةً للمس فلا يُقاس بمقاس اللوحة.
     */
    public function test_no_navigation_back_button_is_drawn_by_hand(): void
    {
        $hand = [];

        foreach ($this->pages() as $file) {
            if (! str_contains($file, '/Pages/Admin/') && ! str_contains($file, '/Pages/Platform/')) {
                continue;
            }

            if (preg_match('/<SmartLink[^>]*>(?:(?!<\/SmartLink>).)*رجوع/su', file_get_contents($file))) {
                $hand[] = basename($file);
            }
        }

        $this->assertSame([], $hand, 'زرّ رجوعٍ مرسوم بيده — استعمل BackLink');
    }

    /** @return array<int, string> */
    private function pages(): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'tsx') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}

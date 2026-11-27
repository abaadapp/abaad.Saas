<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Tests\TestCase;

/**
 * مجموعاتُ «الصلاحيات» في شاشة الموظّف تُقاس على `Permissions` نفسِها.
 *
 * المجموعاتُ عرضٌ في الواجهة (`permissionGroups.ts`) لا يقرؤه الحارس. فإن
 * ذكرت مفتاحًا لا وجود له كانت تعد بصلاحيةٍ لا تُمنح، وإن ذكرته مرّتين
 * عُرض مربّعان لشيءٍ واحد، وإن نسيت مفتاحًا وقع في «أخرى» بعيدًا عن أهله.
 */
class TheStaffPermissionScreenShowsEveryKeyOnceTest extends TestCase
{
    /** @return list<string> */
    private function listed(): array
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/permissionGroups.ts'));
        $start = strpos($source, 'export const PERMISSION_GROUPS');
        $end = strpos($source, 'export const OTHER_GROUP');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        preg_match_all('/keys:\s*\[([^\]]*)\]/', substr($source, $start, $end - $start), $blocks);
        $keys = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([^']+)'/", $block, $m);
            $keys = [...$keys, ...$m[1]];
        }

        return $keys;
    }

    /** @return list<string> */
    private function known(): array
    {
        return [...Permissions::SECTIONS, ...Permissions::actions()];
    }

    public function test_no_group_names_a_permission_that_does_not_exist(): void
    {
        $this->assertSame([], array_values(array_diff($this->listed(), $this->known())), 'مجموعةٌ تعد بصلاحيةٍ لا وجود لها');
    }

    public function test_no_permission_is_listed_twice(): void
    {
        $listed = $this->listed();

        $this->assertSame(array_values(array_unique($listed)), $listed, 'مفتاحٌ في مجموعتين يُرسم مربّعين');
    }

    public function test_every_permission_today_has_its_group(): void
    {
        $this->assertSame([], array_values(array_diff($this->known(), $this->listed())), 'صلاحيةٌ بلا مجموعة تقع في «أخرى»');
        $this->assertCount(count(Permissions::SECTIONS) + count(Permissions::ACTIONS), $this->listed());
    }

    /** والحسّاسُ في الشاشة هو `ACTIONS` — والواجهةُ تقرؤه من الحمولة لا من شكل المفتاح */
    public function test_the_sensitive_mark_comes_from_the_actions_the_server_sends(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/permissionGroups.ts'));

        $this->assertStringContainsString('if (key in actions) return { key, label: actions[key], hint: hints[key], sensitive: true };', $source);
        $this->assertSame([], array_values(array_filter(Permissions::SECTIONS, fn ($s) => Permissions::isAction($s))));
    }

    /** والمجموعاتُ لا تُرسَل: ما يُحفظ قائمةُ المفاتيح وحدها */
    public function test_the_board_sends_keys_not_groups(): void
    {
        $form = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/EmployeeForm.tsx'));
        $board = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/PermissionBoard.tsx'));

        $this->assertStringNotContainsString('form.', $board, 'اللوحةُ لا تكتب في النموذج بنفسها');
        $this->assertStringContainsString("'permissions',", $form);
        $this->assertStringNotContainsString('PERMISSION_GROUPS', $form);
    }
}

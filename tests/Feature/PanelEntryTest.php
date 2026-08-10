<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * زرّ «لوحة النشاط» في نقطة البيع يقود إلى بابٍ يُفتح.
 *
 * كان يظهر لمن يدخل اللوحة ثم يقوده إلى `admin.dashboard` دائمًا. فموظّفٌ
 * مُنح المخزون وحده يراه — لأنه يدخل اللوحة فعلًا — ويصطدم بـ403 على قسمٍ لم
 * يُمنحه. بابٌ يُعرض ولا يُفتح: الموظّف يظنّ العطب في النظام فيعيد المحاولة،
 * والتاجر يظنّ أن الصلاحيات التي ضبطها لم تُحفظ.
 */
class PanelEntryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
    }

    /** @param  string[]  $permissions */
    private function staff(array $permissions, string $role = 'cashier'): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'موظف',
            'email' => 'staff'.uniqid().'@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    public function test_it_points_at_a_section_the_employee_actually_owns(): void
    {
        $user = $this->staff(['pos', 'inventory']);

        $this->assertSame(route('admin.inventory.index'), Permissions::panelEntry($user));
    }

    public function test_the_destination_really_opens(): void
    {
        /*
         * أهمّ ما في الملفّ: لا يكفي أن تُحسب وجهةٌ ما، يجب أن يمرّ صاحبها من
         * حارسها. وهذا ما لم يكن يُفحص — فبقي الزرّ يقود إلى 403 شهورًا.
         */
        $user = $this->staff(['pos', 'inventory']);

        $this->actingAs($user)->get(Permissions::panelEntry($user))->assertOk();
    }

    public function test_an_employee_with_only_the_till_sees_no_button(): void
    {
        $user = $this->staff(['pos']);

        $this->assertNull(Permissions::panelEntry($user));
    }

    public function test_the_dashboard_wins_when_it_is_granted(): void
    {
        // الترتيب يتبع SECTIONS، ولوحة التحكم أوّلها: من ملكها يبدأ منها
        $user = $this->staff(['pos', 'dashboard', 'inventory'], 'manager');

        $this->assertSame(route('admin.dashboard'), Permissions::panelEntry($user));
    }

    public function test_the_till_screen_carries_the_destination_not_a_guess(): void
    {
        $user = $this->staff(['pos', 'inventory']);

        /*
         * تُقرأ من صفحةٍ يفتحها هذا الموظّف فعلًا: نقطة البيع تُحوّل إلى تفعيل
         * الجهاز ما لم يُفعَّل. والخاصية مشتركة على كل صفحة، فالشاشة التي
         * يقرؤها الاختبار لا تغيّر الجواب.
         */
        $props = $this->actingAs($user)
            ->get(route('admin.inventory.index'))->viewData('page')['props'];

        $this->assertSame(route('admin.inventory.index'), $props['auth']['panelUrl']);
    }

    public function test_a_till_only_employee_gets_no_destination_in_the_props(): void
    {
        $user = $this->staff(['pos']);

        // لا لوحة له، فتُقرأ من شاشة اختيار الكاشير التي يقف عليها
        $props = $this->actingAs($user)
            ->get(route('pos.cashier'))->viewData('page')['props'];

        $this->assertNull($props['auth']['panelUrl']);
    }
}

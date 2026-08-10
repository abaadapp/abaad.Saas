<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * تعديل الموظف — والعطل الذي كان يمنعه.
 *
 * صفحة التعديل كانت ترسل `pin` كما هو من القاعدة، وهو مخزَّن **مشفّرًا**:
 * فيصل هاش من 60 حرفًا إلى المتصفح ويُملأ به الحقل، فيسقط التحقق
 * (digits:4) عند كل حفظ. النتيجة أن تعديل أي موظف له رمز دخول كان
 * مستحيلًا — ومعه تسريب بصمة الرمز إلى صفحة HTML.
 */
class EmployeeEditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'المالك', 'email' => 'owner@test.local',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function employee(?string $pin = '4739'): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'أحمد', 'email' => 'emp@test.local',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'job_title' => 'كاشير', 'branch' => 'الفرع الرئيسي',
            'pin' => $pin ? Hash::make($pin) : null,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'أحمد',
            'email' => 'emp@test.local',
            'phone' => '+968 92223333',
            'job_title' => 'كاشير',
            'branch' => 'الفرع الرئيسي',
        ], $overrides);
    }

    public function test_the_edit_page_never_sends_the_pin_hash_to_the_browser(): void
    {
        $employee = $this->employee('4739');

        $props = $this->actingAs($this->owner)
            ->get(route('admin.employees.edit', $employee->id))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('', $props['employee']['pin'], 'الرمز يجب ألّا يُرسل أبدًا');
        $this->assertTrue($props['employee']['has_pin'], 'يكفي إخبار الواجهة بوجوده');
        $this->assertStringNotContainsString('$2y$', json_encode($props), 'لا بصمة مشفّرة في حمولة الصفحة');
    }

    public function test_an_employee_with_a_pin_can_be_saved_without_retyping_it(): void
    {
        $employee = $this->employee('4739');
        $before = $employee->pin;

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $employee->id), $this->payload(['pin' => '']))
            ->assertRedirect(route('admin.employees.show', $employee->id))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('+968 92223333', $employee->phone);
        $this->assertSame($before, $employee->pin, 'ترك الحقل فارغًا يُبقي الرمز لا يمحوه');
    }

    public function test_a_new_pin_replaces_the_old_one(): void
    {
        $employee = $this->employee('4739');

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $employee->id), $this->payload(['pin' => '6284']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('6284', $employee->refresh()->pin));
    }

    public function test_a_pin_that_is_not_four_digits_is_refused(): void
    {
        $employee = $this->employee('4739');

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $employee->id), $this->payload(['pin' => '99']))
            ->assertSessionHasErrors('pin');
    }

    public function test_an_owner_cannot_edit_another_businesss_employee(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id,
            'name' => 'غريب', 'email' => 'stranger@test.local',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.employees.edit', $stranger->id))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $stranger->id), $this->payload())
            ->assertNotFound();

        $this->assertSame('غريب', $stranger->refresh()->name);
    }
}

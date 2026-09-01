<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function employee(): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'أحمد', 'email' => 'emp@test.local',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'job_title' => 'كاشير', 'branch' => 'الفرع الرئيسي',
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

    /**
     * لا بصمةَ سرٍّ في حمولة الصفحة.
     *
     * كان الرمز المشفَّر يُرسل إلى المتصفح ويُملأ به الحقل. رُفع الرمز كلّه،
     * ويبقى الحرس: كلمة المرور مبصومة كذلك، ولا تخرج.
     */
    public function test_the_edit_page_sends_no_hash_to_the_browser(): void
    {
        $employee = $this->employee();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.employees.edit', $employee->id))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('pin', $props['employee'], 'الرمز رُفع فلا يُرسل');
        $this->assertStringNotContainsString('$2y$', json_encode($props), 'لا بصمة مشفّرة في حمولة الصفحة');
    }

    /** والحفظ بلا لمس كلمة المرور يُبقيها كما هي */
    public function test_saving_without_touching_the_password_keeps_it(): void
    {
        $employee = $this->employee();
        $before = $employee->password;

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $employee->id), $this->payload())
            ->assertRedirect(route('admin.employees.show', $employee->id))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('+968 92223333', $employee->phone);
        $this->assertSame($before, $employee->password);
    }

    /**
     * ومحو البريد مرفوض: هو الباب الوحيد بعد رفع الرمز.
     *
     * كان الحساب يقوم على بابين، فمحوُ أحدهما لا يقفل شيئًا. واليوم حسابٌ
     * بلا بريدٍ حسابٌ لا سبيل إليه — يُحفظ بنجاح ثمّ يقف صاحبه أمام الشاشة.
     */
    public function test_clearing_the_email_is_refused(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $employee->id), $this->payload(['email' => '']))
            ->assertSessionHasErrors('email');

        $this->assertSame('emp@test.local', $employee->fresh()->email);
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

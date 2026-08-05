<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صلاحيات يدوية لكل موظف على حدة.
 *
 * كانت الصلاحية تُشتقّ من الدور وحده، فإعطاء كاشيرٍ بعينه صلاحية المخزون
 * يستلزم ترقيته دورًا كاملًا. وشاشة «صلاحيات الموظفين» في الإعدادات كانت
 * تحفظ مفاتيح perm_* لا يقرؤها أي كود: مربّعات تُبدَّل ولا تغيّر شيئًا.
 */
class ManualPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);
    }

    private function save(array $extra)
    {
        return $this->actingAs($this->owner)->put(route('admin.employees.update', $this->cashier->id), array_merge([
            'name' => 'أحمد', 'email' => 'c@abaad.om', 'job_title' => 'كاشير',
        ], $extra));
    }

    /* -------------------------- الافتراضي -------------------------- */

    public function test_without_manual_permissions_the_role_still_decides(): void
    {
        $this->assertNull($this->cashier->permissions);
        $this->assertFalse($this->cashier->allows('inventory'));
        $this->assertTrue($this->cashier->allows('pos'));
    }

    /** حفظٌ من شاشة أخرى لا يمحو التخصيص، ولا يفرضه على من لم يُخصَّص له */
    public function test_saving_without_the_flag_leaves_permissions_untouched(): void
    {
        $this->cashier->update(['permissions' => ['inventory']]);

        $this->save(['phone' => '9111'])->assertRedirect();

        $this->assertSame(['inventory'], $this->cashier->fresh()->permissions);
    }

    /* ------------------------- المنح والمنع ------------------------- */

    public function test_the_owner_can_grant_a_single_extra_section(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => ['inventory', 'products']])->assertRedirect();

        $cashier = $this->cashier->fresh();
        $this->assertTrue($cashier->allows('inventory'), 'لم تصل الصلاحية الممنوحة');
        $this->assertTrue($cashier->allows('products'));
        $this->assertFalse($cashier->allows('finance'), 'وصلت صلاحية لم تُمنح');
        $this->assertSame('cashier', $cashier->role, 'تغيّر الدور، والمقصود منح صلاحية لا ترقية');
    }

    /** الحارس على المسار يقرأ نفس الصلاحية، فالمنح يفتح الصفحة فعلًا */
    public function test_the_granted_section_actually_opens(): void
    {
        $this->actingAs($this->cashier)->get(route('admin.inventory.index'))->assertForbidden();

        $this->save(['manual_permissions' => 1, 'permissions' => ['inventory']]);

        $this->actingAs($this->cashier->fresh())->get(route('admin.inventory.index'))->assertOk();
    }

    /**
     * لا يُحفظ موظف بلا صلاحية واحدة.
     *
     * حسابٌ بلا صلاحية يُحفظ بنجاح ثمّ لا يجد صاحبه شيئًا يفتحه — عطلٌ لا
     * يظهر إلا عند أوّل دخول، وصاحب النشاط يظنّ أنه أنهى الإضافة.
     */
    public function test_an_employee_cannot_be_saved_without_a_single_permission(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => []])
            ->assertSessionHasErrors('permissions');

        $this->actingAs($this->owner)->post(route('admin.employees.store'), [
            'name' => 'سالم', 'email' => 's@abaad.om', 'job_title' => 'كاشير',
            'manual_permissions' => 1, 'permissions' => [],
        ])->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('users', ['email' => 's@abaad.om']);
    }

    /**
     * قائمة يدوية فارغة تعني «لا شيء إضافي» لا «اتبع الدور» — والفرق بينهما
     * هو ما يجعل المنع ممكنًا أصلًا.
     */
    /**
     * لا قسم مفتوحًا بلا منح — ولا حتى لوحة التحكم ونقطة البيع والفروع.
     *
     * كانت الثلاثة تُفتح لكل من دخل مهما كانت صلاحياته، فيرفع صاحب النشاط
     * علامتها ولا يتغيّر شيء: منعٌ ظاهرٌ في الشاشة لا وجود له في الواقع.
     */
    public function test_nothing_is_open_without_being_granted(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => ['inventory']])->assertRedirect();

        $cashier = $this->cashier->fresh();
        foreach (['dashboard', 'pos', 'branch'] as $section) {
            $this->assertFalse($cashier->allows($section), "بقي «{$section}» مفتوحًا بلا منح");
        }
        $this->assertTrue($cashier->allows('inventory'));
    }

    /** والثلاثة تُمنح كغيرها حين يريدها صاحب النشاط */
    public function test_the_three_can_be_granted_like_any_other_section(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => ['dashboard', 'pos', 'branch']])->assertRedirect();

        $cashier = $this->cashier->fresh();
        $this->assertTrue($cashier->allows('dashboard'));
        $this->assertTrue($cashier->allows('pos'));
        $this->assertTrue($cashier->allows('branch'));
        $this->assertFalse($cashier->allows('inventory'));
    }

    /** ونقطة البيع وحدها لا تفتح باب اللوحة — وإلا دخلها كل كاشير */
    public function test_pos_alone_does_not_open_the_panel(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => ['pos']])->assertRedirect();

        $this->actingAs($this->cashier->fresh())->get(route('admin.dashboard'))->assertForbidden();
    }

    /* ------------------------ عند الإضافة ------------------------ */

    /**
     * الصلاحية تُحدَّد منذ لحظة الإضافة لا بعد حفظٍ ثانٍ. وكانت الشاشة تعرضها
     * عند التعديل وحده، فيُضاف الموظف بصلاحيات وظيفته ثم يُفتح ملفه لتصحيحها.
     */
    public function test_a_new_employee_can_be_created_with_manual_permissions(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.store'), [
            'name' => 'سالم', 'email' => 's@abaad.om', 'job_title' => 'كاشير',
            'manual_permissions' => 1, 'permissions' => ['inventory', 'suppliers'],
        ])->assertRedirect();

        $new = User::where('email', 's@abaad.om')->firstOrFail();

        $this->assertSame(['inventory', 'suppliers'], $new->permissions);
        $this->assertTrue($new->allows('inventory'));
        $this->assertFalse($new->allows('finance'));
        $this->assertSame('cashier', $new->role, 'الوظيفة تقترح ولا تُلزم — والدور لم يتغيّر');
    }

    public function test_a_new_employee_without_the_flag_follows_the_job_title(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.store'), [
            'name' => 'مريم', 'email' => 'm@abaad.om', 'job_title' => 'كاشير',
        ])->assertRedirect();

        $this->assertNull(User::where('email', 'm@abaad.om')->firstOrFail()->permissions);
    }

    /** والقسم الممنوح عند الإضافة يُفتح فعلًا، لا يُحفظ وحسب */
    public function test_the_new_employee_can_open_what_was_granted(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.store'), [
            'name' => 'سالم', 'email' => 's@abaad.om', 'job_title' => 'كاشير',
            'manual_permissions' => 1, 'permissions' => ['inventory'],
        ]);

        $this->actingAs(User::where('email', 's@abaad.om')->firstOrFail())
            ->get(route('admin.inventory.index'))->assertOk();
    }

    /* --------------------------- الحماية --------------------------- */

    public function test_you_cannot_edit_your_own_permissions(): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $this->owner->id), [
                'name' => 'المالك', 'email' => 'o@abaad.om', 'job_title' => 'كاشير',
                'manual_permissions' => 1, 'permissions' => ['dashboard'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertNull($this->owner->fresh()->permissions, 'أقفل المدير الباب على نفسه');
    }

    public function test_an_unknown_section_is_rejected(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => ['not_a_section']])
            ->assertSessionHasErrors('permissions.0');
    }
}

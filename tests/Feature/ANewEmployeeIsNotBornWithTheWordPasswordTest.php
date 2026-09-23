<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * موظّفٌ يُضاف بلا كلمة مرور لا يُولد بكلمة «password».
 *
 * ═══ العطب ═══
 *
 * حقلُ كلمة المرور في نموذج الإضافة اختياريّ، والخادمُ كان يملأ الفراغَ
 * بـ`'password'` حرفيًّا. فتاجرٌ يترك الحقلَ — يظنّ أنّ النظام يولّدها أو
 * يرسلها — يُخرج حسابًا اسمُ دخوله ظاهرٌ في كلّ شاشة وكلمتُه أشهرُ كلمةٍ
 * في العالم. ومن حاول `password` على أيّ اسمٍ دخل.
 *
 * ═══ وما يقع الآن ═══
 *
 * الفراغُ يعني «ولّدها لي»: تُولَّد كلمةٌ عشوائيّة وتُعرض مرّةً واحدة في
 * بطاقة الموظّف — البابُ نفسُه الذي تسلكه «إعادة تعيين كلمة المرور» — فلا
 * تُخترع كلمةٌ ثابتة، ولا يُردّ النموذجُ على من لم يُطلب منه شيء.
 *
 * ═══ ومعه: ما يمنحه الدورُ يُقاس بأفعاله لا بأقسامه وحدها ═══
 *
 * حدُّ الباقة يقيس «أخرج القائمةُ عمّا يمنحه الدور؟». وكان يقيس الأقسامَ
 * وحدها، والشاشةُ تُرسل ما تحمله الوظيفةُ كاملًا — أقسامًا **وأفعالًا**
 * (`titleGrants`). فمحاسبٌ يُضاف على باقةٍ لا تبيع التخصيص، بقائمةٍ هي
 * عينُ ما تمنحه وظيفتُه، كان يُردّ بأنّه «مخصَّص». والمقياسُ الصحيح هو
 * `roleGrants` — ما تقرؤه الشاشةُ نفسُها.
 */
class ANewEmployeeIsNotBornWithTheWordPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'محاسب', 'role' => 'accountant']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('owner-secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function hire(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.employees.store'), $overrides + [
            'name' => 'سالم', 'login_username' => 'salim', 'job_title' => 'كاشير',
            'manual_permissions' => false,
        ]);
    }

    public function test_a_blank_password_field_does_not_mean_the_word_password(): void
    {
        $this->hire()->assertSessionHasNoErrors();

        $salim = User::where('business_id', $this->business->id)->where('name', 'سالم')->firstOrFail();

        $this->assertFalse(Hash::check('password', $salim->password), 'وُلد الحسابُ بكلمة «password»');
    }

    public function test_the_generated_password_is_shown_once_and_opens_the_account(): void
    {
        $response = $this->hire();
        $salim = User::where('business_id', $this->business->id)->where('name', 'سالم')->firstOrFail();

        $response->assertRedirect(route('admin.employees.show', $salim->id))
            ->assertSessionHas('issued_password');

        $issued = session('issued_password');
        $this->assertIsString($issued);
        $this->assertGreaterThanOrEqual(8, strlen($issued));
        $this->assertTrue(Hash::check($issued, $salim->password), 'الكلمةُ المعروضة ليست كلمةَ الحساب');
    }

    public function test_a_typed_password_is_the_one_that_opens_the_account(): void
    {
        // ورقمٌ في الكلمة لأنّ الشرطَ صار يطلبه — انظر `AStaffPasswordIsNotAFourDigitPinTest`.
        // وما يقيسه هذا الاختبارُ لم يتبدّل: المكتوبةُ بيدٍ هي التي تفتح الحساب.
        $this->hire(['password' => 'typed-by-hand2'])->assertSessionMissing('issued_password');

        $salim = User::where('business_id', $this->business->id)->where('name', 'سالم')->firstOrFail();

        $this->assertTrue(Hash::check('typed-by-hand2', $salim->password));
    }

    /** ومحاسبٌ يُضاف بما تمنحه وظيفتُه — أقسامًا وأفعالًا — ليس تخصيصًا يُباع */
    public function test_hiring_by_the_full_role_grants_is_not_a_paid_customization(): void
    {
        $plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => []]);
        $this->business->update(['plan_id' => $plan->id]);

        $grants = Permissions::roleGrants('accountant');
        $this->assertNotEmpty(array_filter($grants, fn ($g) => Permissions::isAction($g)), 'المحاسبُ بلا أفعال — الاختبارُ لا يقيس شيئًا');

        $this->hire([
            'name' => 'المحاسبة', 'login_username' => 'acc', 'job_title' => 'محاسب',
            'manual_permissions' => true, 'permissions' => $grants,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['business_id' => $this->business->id, 'name' => 'المحاسبة']);
    }

    /** وحذفُ فعلٍ من قائمة الوظيفة تخصيصٌ — يحتاج الباقةَ التي تبيعه */
    public function test_dropping_an_action_from_the_role_is_a_customization(): void
    {
        $plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => []]);
        $this->business->update(['plan_id' => $plan->id]);

        $narrower = array_values(array_filter(Permissions::roleGrants('accountant'), fn ($g) => $g !== Permissions::PAYROLL_VIEW));

        $this->hire([
            'name' => 'المحاسبة', 'login_username' => 'acc', 'job_title' => 'محاسب',
            'manual_permissions' => true, 'permissions' => $narrower,
        ])->assertSessionHasErrors('permissions');
    }

    /** وشاشةُ التعديل تقول ما يفتحه الدورُ كاملًا — أفعالَه لا أقسامَه وحدها */
    public function test_the_edit_screen_lists_the_roles_actions_too(): void
    {
        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين', 'email' => 'amin@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'inventory', 'job_title' => 'أمين مخزن', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)->get(route('admin.employees.edit', $clerk->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employee.role_permissions', Permissions::roleGrants('inventory')));
    }
}

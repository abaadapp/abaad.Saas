<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرجوعُ عن التخصيص — بابٌ كان مسدودًا.
 *
 * ═══ العطب ═══
 *
 * `users.permissions` لها معنيان: `null` يعني «اتبع صلاحيات وظيفتك» فتتبدّل
 * مع المسمّى، ومصفوفةٌ تعني «قائمةٌ لك وحدك» لا تتبدّل. والمتحكّم يعالج
 * الأولى بعنايةٍ في ثلاثة مواضع.
 *
 * وبابُها كان مسدودًا من طرفين:
 *
 *   ١ — القاعدة `required_with:manual_permissions` تعمل على **حضور** الحقل
 *       لا على قيمته. والنموذج يرسله في كلّ حفظة، فاختيارُ «اتبع الوظيفة»
 *       (`0`) كان يُردّ بـ«حدّد صلاحيات الموظف» — ولم يُطلَب منه تحديد شيء.
 *
 *   ٢ — والنموذج يرسل `manual_permissions: true` أبدًا: لا مقبضَ في الشاشة
 *       أصلًا.
 *
 * فمن خُصّصت صلاحياتُه مرّةً بقي عليها أبدًا — ولو رُقّي، ولو بُدِّلت وظيفتُه.
 * وصاحبُ الباقة الأساسية — وهي لا تبيع التخصيص — لا يجد الحالَ التي اشتراها.
 */
class PermissionsMayReturnToTheJobTitleTest extends TestCase
{
    use RefreshDatabase;

    private Business $biz;

    private User $owner;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::updateOrCreate(['name' => 'باقة الاختبار'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 5, 'max_employees' => 20, 'max_products' => 1000,
        ]);

        $this->biz = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $plan->id,
        ]);
        Branch::create(['business_id' => $this->biz->id, 'name' => 'الرئيسي']);

        foreach ([['كاشير', 'cashier'], ['محاسب', 'accountant']] as [$n, $r]) {
            JobTitle::create(['business_id' => $this->biz->id, 'name' => $n, 'role' => $r]);
        }

        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->emp = User::create([
            'business_id' => $this->biz->id, 'name' => 'موظّف', 'email' => 'e@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['pos', 'orders'],
        ]);

        $this->actingAs($this->owner);
    }

    private function save(array $over = [])
    {
        return $this->put(route('admin.employees.update', $this->emp->id), array_merge([
            'name' => 'موظّف', 'job_title' => 'كاشير',
        ], $over));
    }

    /* ─────────── الرجوع يقع ─────────── */

    /** «اتبع صلاحيات الوظيفة» تُقبل ولا تُردّ */
    public function test_following_the_job_title_is_accepted(): void
    {
        $this->assertTrue($this->emp->hasManualPermissions(), 'المقدّمة خاطئة: لا تخصيص أصلًا');

        $this->save(['manual_permissions' => 0])->assertSessionHasNoErrors();

        $this->assertNull($this->emp->fresh()->permissions, 'بقيت القائمة المخصّصة');
        $this->assertFalse($this->emp->fresh()->hasManualPermissions());
    }

    /**
     * وبعدها تتبدّل صلاحياتُه مع وظيفته — وهو معنى الرجوع كلُّه.
     *
     * ولولا ذلك لكان الفرقُ بين الحالين شكلًا في القاعدة لا أثرًا للتاجر.
     */
    public function test_after_returning_his_permissions_follow_the_title(): void
    {
        $this->save(['manual_permissions' => 0]);

        $this->assertFalse($this->emp->fresh()->allows('finance'),
            'كاشيرٌ يفتح المالية');

        $this->save(['job_title' => 'محاسب', 'manual_permissions' => 0]);

        $this->assertSame('accountant', $this->emp->fresh()->role);
        $this->assertTrue($this->emp->fresh()->allows('finance'),
            'بُدِّلت وظيفتُه ولم تتبدّل صلاحياتُه — فالرجوعُ لم يقع');
    }

    /** والمخصَّصُ لا تتبدّل صلاحياتُه مع الوظيفة — وهو الفرقُ بين الحالين */
    public function test_a_customised_list_does_not_follow_the_title(): void
    {
        $this->save(['job_title' => 'محاسب', 'manual_permissions' => 1, 'permissions' => ['pos']]);

        $this->assertSame('accountant', $this->emp->fresh()->role);
        $this->assertFalse($this->emp->fresh()->allows('finance'),
            'قائمةٌ مخصّصةٌ تبدّلت بتبديل الوظيفة');
        $this->assertTrue($this->emp->fresh()->allows('pos'));
    }

    /** والعودةُ إلى التخصيص بعد الرجوع مفتوحة — البابان يعملان */
    public function test_customising_again_after_returning_works(): void
    {
        $this->save(['manual_permissions' => 0]);
        $this->assertNull($this->emp->fresh()->permissions);

        $this->save(['manual_permissions' => 1, 'permissions' => ['pos', 'inventory']]);

        $this->assertSame(['pos', 'inventory'], $this->emp->fresh()->permissions);
    }

    /* ─────────── وما كان محروسًا يبقى ─────────── */

    /** التخصيصُ بقائمةٍ فارغة يُردّ — لا حسابَ بلا صلاحية */
    public function test_customising_with_an_empty_list_is_still_refused(): void
    {
        $this->save(['manual_permissions' => 1, 'permissions' => []])
            ->assertSessionHasErrors('permissions');

        $this->assertSame(['pos', 'orders'], $this->emp->fresh()->permissions, 'مُحيت صلاحياتُه');
    }

    /** والتخصيصُ بلا قائمةٍ أصلًا يُردّ كذلك */
    public function test_customising_without_sending_a_list_is_refused(): void
    {
        $this->save(['manual_permissions' => 1])->assertSessionHasErrors('permissions');

        $this->assertSame(['pos', 'orders'], $this->emp->fresh()->permissions);
    }

    /**
     * و«اتبع الوظيفة» مع قائمةٍ فارغة لا تُردّ.
     *
     * القائمةُ لا تُقرأ في هذه الحال أصلًا — وردُّها كان يُغلق البابَ من
     * حيث لا يحرس شيئًا. وهذا ما كانت `min:1` تفعله.
     */
    public function test_following_the_title_with_an_empty_list_is_not_refused(): void
    {
        $this->save(['manual_permissions' => 0, 'permissions' => []])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->emp->fresh()->permissions);
    }

    /** ولا يُخصّص أحدٌ صلاحيات نفسه — ولا يرجع بها */
    public function test_no_one_touches_his_own_permissions(): void
    {
        $this->put(route('admin.employees.update', $this->owner->id), [
            'name' => 'المالك', 'job_title' => 'كاشير', 'manual_permissions' => 0,
        ])->assertSessionHasErrors('permissions');

        $this->assertNull($this->owner->fresh()->permissions);
    }

    /**
     * وحفظٌ من شاشةٍ لا ترسل العلم لا يمسّ الصلاحيات.
     *
     * التمييزُ بين «لم يُرسل» و«أُرسل صفرًا» جوهريّ: لولاه لَمحا كلُّ حفظٍ
     * من شاشةٍ أخرى القائمةَ المخصّصة بلا أن يطلب أحد.
     */
    public function test_a_save_without_the_flag_leaves_permissions_alone(): void
    {
        $this->save(['phone' => '+968 90000000'])->assertSessionHasNoErrors();

        $this->assertSame(['pos', 'orders'], $this->emp->fresh()->permissions,
            'حفظُ رقمِ هاتفٍ محا الصلاحيات المخصّصة');
    }

    /* ─────────── والإضافةُ كذلك ─────────── */

    /** موظّفٌ جديدٌ يُفتح على صلاحيات وظيفته */
    public function test_a_new_employee_may_follow_his_title(): void
    {
        $this->post(route('admin.employees.store'), [
            'name' => 'جديد', 'login_username' => 'newone', 'job_title' => 'محاسب',
            'manual_permissions' => 0,
        ])->assertSessionHasNoErrors();

        $hired = User::where('business_id', $this->biz->id)->where('name', 'جديد')->firstOrFail();

        $this->assertNull($hired->permissions, 'كُتبت له قائمةٌ لم تُطلب');
        $this->assertTrue($hired->allows('finance'), 'لا يفتح ما تفتحه وظيفتُه');
    }

    /* ─────────── والشاشةُ تقرأ ما يقرؤه الباب ─────────── */

    /**
     * `titleGrants` تُحسب من `roleGrants` نفسِها التي يقيس بها الحارس.
     *
     * ولو حُسبت في الشاشة لقالت للمدير «هذه الوظيفة تفتح كذا» ثمّ فتحت غيره
     * — وتقريرُ حالٍ كاذب أسوأ من غياب التقرير.
     */
    public function test_the_screen_is_told_what_each_title_opens(): void
    {
        $this->get(route('admin.employees.edit', $this->emp->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('titleGrants.كاشير', Permissions::roleGrants('cashier'))
                ->where('titleGrants.محاسب', Permissions::roleGrants('accountant')));
    }

    /** ولا تُسرَّب وظائفُ متجرٍ آخر إليها */
    public function test_a_neighbours_titles_are_not_listed(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $neighbour->id, 'name' => 'مدير الجار', 'role' => 'manager']);

        $this->get(route('admin.employees.edit', $this->emp->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('titleGrants.مدير الجار'));
    }

    /** وشاشةُ الإضافة تُرسَل إليها كذلك — الشاشتان تقرآن مصدرًا واحدًا */
    public function test_the_create_screen_gets_it_too(): void
    {
        $this->get(route('admin.employees.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('titleGrants.كاشير', Permissions::roleGrants('cashier')));
    }
}

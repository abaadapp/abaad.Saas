<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * والموظّفُ الجديد يُحفظ على وظيفته — لا على تخصيصٍ لم يطلبه أحد.
 *
 * ═══ العطب ═══
 *
 * شاشةُ الإضافة كانت تُفتح على «خصّص لهذا الموظّف» وقائمتُه فارغة
 * (`manual_permissions: true, permissions: []`). فمن ملأ الاسمَ والوظيفةَ
 * واسمَ الدخول وضغط «حفظ» — ولم يمرّ على قسم الصلاحيات أصلًا — وقع في واحدةٍ
 * من اثنتين:
 *
 *   • على باقةٍ لا تبيع «الصلاحيات المخصّصة»: يُردّ الحفظُ برسالةٍ تقول
 *     «رقِّ الباقة» عن قدرةٍ لم يخترها، والمربّعاتُ التي «تُصلحها» معطّلةٌ
 *     في الشاشة. فالموظّفُ لا يُحفظ أبدًا، ولا سبيل إلى فهم السبب.
 *
 *   • على باقةٍ تبيعها أو متجرٍ بلا باقة: يُحفظ بقائمةٍ **فارغة** — حسابٌ
 *     يدخل فلا يفتح شيئًا، ولا تتبدّل صلاحياتُه حين تتبدّل وظيفتُه.
 *
 * ═══ والحارسُ الذي كان يمنع الثانية كان ميّتًا ═══
 *
 * `required_if:manual_permissions,1` تقارن العلمَ بالنصّ `'1'` مقارنةً
 * صارمة. وInertia ترسل JSON، فيصل `true` منطقيًّا — فلا يتحقّق الشرط ولا
 * تُطلب القائمة. ولم يكشفه اختبارٌ لأنّ الاختبارات كلَّها كانت ترسل قائمةً
 * مملوءة.
 *
 * فهنا حارسان: الشاشةُ تُفتح على الوظيفة (في vitest)، والخادمُ يردّ القائمةَ
 * الفارغةَ برسالةٍ على حقلها — على كلّ باقة.
 */
class ANewEmployeeIsSavedOnHisJobTitleTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->branch] = $this->aShop('متجري', 'o@abaadapp.om');
    }

    /** @return array{0: Business, 1: User, 2: Branch} */
    private function aShop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        JobTitle::create(['business_id' => $shop->id, 'name' => 'كاشير', 'role' => 'cashier']);
        JobTitle::create(['business_id' => $shop->id, 'name' => 'محاسب', 'role' => 'accountant']);

        return [$shop, $owner, $branch];
    }

    /** يضع المتجر على باقةٍ بقدراتٍ وسقفٍ — `null` قدراتٍ تعني «لم تُضبط»، و`0` مقاعدَ تعني «بلا سقف» */
    private function onPlan(?array $capabilities, ?int $seats = null): Plan
    {
        $plan = Plan::create([
            'name' => 'باقة', 'monthly_price' => 9.9, 'yearly_price' => 99,
            'capabilities' => $capabilities, 'max_employees' => $seats ?? 0,
        ]);

        $this->shop->update(['plan_id' => $plan->id]);
        $this->shop->refresh();

        return $plan;
    }

    /**
     * ما ترسله شاشةُ الإضافة كما تُفتح.
     *
     * الأصلُ هنا هو الأصلُ في `EmployeeForm`: علمُ التخصيص مطفأ، والقائمةُ
     * فارغة — والوظيفةُ وحدها تمنح. وهو **جسمُ JSON** كما ترسله Inertia: العلمُ
     * منطقيٌّ لا نصّ، وهذا الفرقُ بعينه هو ما قتل القاعدةَ القديمة.
     */
    private function asTheScreenSendsIt(array $over = []): array
    {
        return array_merge([
            'name' => 'سالم',
            'job_title' => 'كاشير',
            'branch' => 'الرئيسي',
            'branches' => [],
            'phone' => '',
            'login_username' => 'salem',
            'password' => '',
            'status' => true,
            'monthly_target' => '',
            'basic_salary' => '',
            'allowances' => '',
            'manual_permissions' => false,
            'permissions' => [],
        ], $over);
    }

    private function save(array $over = [], ?User $actor = null)
    {
        /*
         * و`postJson` لا `post`: الجسمُ JSON هو موضعُ العطب — العلمُ يصل
         * منطقيًّا `true` لا نصًّا `'1'`، وهو ما كانت القاعدةُ القديمة لا
         * تقرؤه. و`post` في الاختبار تمرّر المنطقيَّ كما هو في مصفوفة PHP،
         * فيسلك الطريقَ نفسَه — ولذلك لم يكشفه اختبارٌ قديم.
         */
        return $this->actingAs($actor ?? $this->owner)
            ->postJson(route('admin.employees.store'), $this->asTheScreenSendsIt($over));
    }

    /* ══════════ ١ · الشاشةُ كما تُفتح تحفظ — على كلّ باقة ══════════ */

    /** بلا باقةٍ أصلًا: الأقدمُ من الباقات لا يُقفل عليه بابُ التوظيف */
    public function test_the_screen_as_it_opens_saves_in_a_shop_with_no_plan(): void
    {
        $this->save()->assertRedirect();

        $employee = User::where('business_id', $this->shop->id)->where('name', 'سالم')->first();

        $this->assertNotNull($employee, 'الشاشةُ كما تُفتح لم تحفظ موظّفًا');
        $this->assertNull($employee->permissions, 'حُفظ بقائمةٍ بدل أن يتبع وظيفته');
        $this->assertSame('cashier', $employee->role);
        $this->assertSame('سالم@'.'', ''.$employee->name.'@'.'');
    }

    /** وباقةٌ لا تبيع «الصلاحيات المخصّصة» — وهي موضعُ العطب */
    public function test_the_screen_as_it_opens_saves_on_a_plan_that_does_not_sell_custom_permissions(): void
    {
        $this->onPlan([]);

        $this->save()->assertRedirect();

        $employee = User::where('business_id', $this->shop->id)->where('name', 'سالم')->first();

        $this->assertNotNull($employee, 'باقةٌ لا تبيع التخصيص كانت تقفل إضافةَ الموظّفين كلَّها');
        $this->assertNull($employee->permissions);
    }

    /** وباقةٌ تبيعها: يتبع وظيفتَه كذلك — لا يُحفظ بقائمةٍ فارغة */
    public function test_the_screen_as_it_opens_saves_on_a_plan_that_sells_them(): void
    {
        $this->onPlan(['custom_permissions']);

        $this->save()->assertRedirect();

        $employee = User::where('business_id', $this->shop->id)->where('name', 'سالم')->first();

        $this->assertNotNull($employee);
        $this->assertNull($employee->permissions, 'حُفظ بقائمةٍ فارغة — حسابٌ يدخل ولا يفتح شيئًا');
    }

    /** ويظهر في قائمة الموظّفين بعد حفظه */
    public function test_and_he_appears_in_the_list(): void
    {
        $this->save()->assertRedirect();

        // والقائمةُ تُقرأ من خصائص Inertia لا من نصّ الصفحة: الصفحةُ قشرةٌ والبياناتُ JSON فيها
        $this->actingAs($this->owner)->get(route('admin.employees.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Employees/Index')
                ->where('employees', fn ($rows) => collect($rows)->contains(
                    fn ($row) => ($row['name'] ?? null) === 'سالم',
                )));
    }

    /** وفروعُه تُربط كما اختيرت */
    public function test_and_the_branch_he_was_given_is_attached(): void
    {
        $second = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);

        $this->save(['branches' => [$second->id]])->assertRedirect();

        $employee = User::where('business_id', $this->shop->id)->where('name', 'سالم')->firstOrFail();

        $this->assertSame([$second->id], $employee->branches()->pluck('branches.id')->all());
    }

    /* ══════════ ٢ · والقائمةُ الفارغةُ تُردّ برسالةٍ على حقلها ══════════ */

    /**
     * علمُ التخصيص يصل منطقيًّا من Inertia — والقاعدةُ يجب أن تقرأه.
     *
     * وهذا ما كان ميّتًا: `required_if:…,1` لا تقرأ `true`، فتمرّ القائمةُ
     * الفارغةُ ويُحفظ حسابٌ لا يفتح شيئًا.
     */
    public function test_an_empty_custom_list_is_refused_when_the_flag_is_a_real_boolean(): void
    {
        $this->onPlan(['custom_permissions']);

        $this->save(['manual_permissions' => true, 'permissions' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.permissions.0', 'حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.');

        $this->assertDatabaseMissing('users', ['business_id' => $this->shop->id, 'name' => 'سالم']);
    }

    /** وكذلك حين يصل نصًّا — البابان قراءةٌ واحدة */
    public function test_and_refused_when_the_flag_arrives_as_a_string(): void
    {
        $this->onPlan(['custom_permissions']);

        $this->save(['manual_permissions' => '1', 'permissions' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.permissions.0', 'حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.');
    }

    /** وعلى متجرٍ بلا باقةٍ كذلك — الفراغُ لا يُحفظ لأحد */
    public function test_and_refused_in_a_shop_with_no_plan_at_all(): void
    {
        $this->save(['manual_permissions' => true, 'permissions' => []])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['business_id' => $this->shop->id, 'name' => 'سالم']);
    }

    /** والتخصيصُ المملوءُ يُحفظ كما أُشّر — الحارسُ يردّ الفراغ لا التخصيص */
    public function test_but_a_filled_custom_list_is_saved_as_ticked(): void
    {
        $this->onPlan(['custom_permissions']);

        $this->save(['manual_permissions' => true, 'permissions' => ['pos', 'inventory']])
            ->assertRedirect();

        $employee = User::where('business_id', $this->shop->id)->where('name', 'سالم')->firstOrFail();

        $this->assertEqualsCanonicalizing(['pos', 'inventory'], $employee->permissions);
    }

    /* ══════════ ٣ · وكلُّ رفضٍ يُقال على حقله ══════════ */

    /** اسمُ دخولٍ محجوز: رسالةٌ على حقله، ولا صفٌّ ثانٍ */
    public function test_a_taken_login_name_is_refused_on_its_own_field(): void
    {
        $this->save()->assertRedirect();

        $this->save(['name' => 'ثانٍ'])
            ->assertStatus(302)
            ->assertSessionHasErrors('login_username');

        $this->assertSame(1, User::where('business_id', $this->shop->id)
            ->whereIn('name', ['سالم', 'ثانٍ'])->count(), 'حُفظ موظّفٌ ثانٍ باسم دخولٍ محجوز');
    }

    /** وما كتبه التاجرُ يعود معه — لا يُمحى النموذج */
    public function test_and_what_he_typed_comes_back_with_the_refusal(): void
    {
        $this->save()->assertRedirect();

        $this->save(['name' => 'ثانٍ'])->assertSessionHasInput('name', 'ثانٍ');
    }

    /** ووظيفةٌ ليست في هذا المتجر تُردّ على حقلها */
    public function test_a_job_title_this_shop_does_not_have_is_refused(): void
    {
        $this->save(['job_title' => 'أمين مخزن'])
            ->assertStatus(302)
            ->assertSessionHasErrors('job_title');

        $this->assertDatabaseMissing('users', ['business_id' => $this->shop->id, 'name' => 'سالم']);
    }

    /** ووظيفةُ متجرٍ آخر ليست وظيفتَه */
    public function test_and_a_job_title_that_belongs_to_another_shop_is_not_borrowed(): void
    {
        [$other] = $this->aShop('متجرٌ آخر', 'x@abaadapp.om');
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير فرع', 'role' => 'manager']);

        $this->save(['job_title' => 'مدير فرع'])->assertSessionHasErrors('job_title');

        $this->assertDatabaseMissing('users', ['business_id' => $this->shop->id, 'name' => 'سالم']);
    }

    /** وكلمةُ مرورٍ دون الشرط تُردّ — ولا تُضعَّف لأجل الحفظ */
    public function test_a_password_below_the_rule_is_refused(): void
    {
        $this->save(['password' => '1234'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->save(['password' => 'باسوورد'])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['business_id' => $this->shop->id, 'name' => 'سالم']);
    }

    /** وسقفُ الباقة يُقال في النموذج لا صفحةَ خطأ */
    public function test_the_seat_limit_is_said_in_the_form(): void
    {
        // المالكُ نفسُه يشغل المقعد الوحيد
        $this->onPlan([], seats: 1);

        // والرسالةُ تُعلَّق على حقلٍ في الشاشة لا تُصفع صفحةَ خطأ
        $this->save()->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseMissing('users', ['business_id' => $this->shop->id, 'name' => 'سالم']);
    }

    /* ══════════ ٤ · ولا يتسرّب شيءٌ بين المتاجر ══════════ */

    /** فرعُ متجرٍ آخر لا يُربط بموظّفٍ هنا */
    public function test_a_branch_of_another_shop_is_never_attached(): void
    {
        [$other, , $otherBranch] = $this->aShop('متجرٌ آخر', 'x@abaadapp.om');

        $this->save(['branches' => [$otherBranch->id]])->assertRedirect();

        $employee = User::where('business_id', $this->shop->id)->where('name', 'سالم')->firstOrFail();

        $this->assertSame([], $employee->branches()->pluck('branches.id')->all());
        $this->assertSame(0, DB::table('branch_user')
            ->where('user_id', $employee->id)->where('branch_id', $otherBranch->id)->count());
    }

    /** واسمٌ واحدٌ في متجرين: كلٌّ في متجره، ولا يرى أحدُهما الآخر */
    public function test_the_same_person_hired_in_two_shops_stays_in_his_own(): void
    {
        [$other, $otherOwner] = $this->aShop('متجرٌ آخر', 'x@abaadapp.om');

        $this->save()->assertRedirect();

        $this->save(['login_username' => 'salem2'], $otherOwner)->assertRedirect();

        $this->assertSame(1, User::where('business_id', $this->shop->id)->where('name', 'سالم')->count());
        $this->assertSame(1, User::where('business_id', $other->id)->where('name', 'سالم')->count());

        // ولا يقرأ جارُه صفَّه: القائمةُ في متجرٍ لا تحمل موظّفَ متجرٍ آخر
        $this->actingAs($otherOwner)->get(route('admin.employees.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employees', fn ($rows) => collect($rows)->every(
                    fn ($row) => ($row['email'] ?? '') !== 'salem@abaadapp.om',
                )));
    }

    /** وضغطتان على «حفظ» لا تصنعان موظّفين */
    public function test_pressing_save_twice_does_not_hire_twice(): void
    {
        $this->save()->assertRedirect();
        $this->save();

        $this->assertSame(1, User::where('business_id', $this->shop->id)->where('name', 'سالم')->count());
    }
}

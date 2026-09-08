<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يُستولى على حسابٍ يفتح أكثر ممّا يفتحه صاحبُ اليد.
 *
 * ═══ العطبُ الأوّل: قسمٌ واحد يساوي «كن أيَّ أحدٍ دون المالك» ═══
 *
 * حارسُ شاشة الموظفين كان يسأل سؤالًا واحدًا: «أهذا صاحبُ النشاط؟». وما
 * دونه مباح. فموظّفٌ مُنح قسم «الرواتب والموظفين» وحدَه — ليضيف موظّفًا أو
 * يصحّح مسمًّى — كان **يعيد تعيين كلمة مرور مدير الفرع**، وتُعرض له الكلمةُ
 * الجديدة في الشاشة لينسخها (`issued_password`). ثمّ يدخل بها فيصير له ما
 * للمدير: `'*'` — كلُّ قسم، وكلُّ فعل، وكلُّ ريال في الدفتر.
 *
 * ويقدر كذلك أن يُعطّل حسابه فيقفله عن صاحبه، وأن يعدّل بياناته.
 *
 * والقاعدةُ الآن قاعدةُ المنح نفسها: من لا يملك أن **يَمنح** الصلاحية لا
 * يملك أن **يأخذها** بكلمة مرور.
 *
 * ═══ والثاني: `PAYROLL_VIEW` كانت تحرس بابًا لدارٍ لها بابان ═══
 *
 * فُصلت عن القسم لأنّ «من مُنح القسمَ ليصحّح مسمًّى كان يقرأ راتبَ كلّ من في
 * المتجر». وحُرست شاشةُ المسيرة وحدَها — وبقي الرقمُ نفسُه معروضًا **ومكتوبًا**
 * في شاشة تعديل الموظّف.
 *
 * ═══ والثالث: بطاقةُ الموظّف كانت تكذب ═══
 *
 * تبويب «الصلاحيات» فيها كان عشرةَ أسطرٍ مكتوبةٍ في الخادم باليد، متطابقةٍ
 * لكلّ موظّف ولا تُقرأ من صفّه. فيفتح صاحبُ النشاط بطاقةَ من يملك «الرواتب
 * والموظفين» فيقرأ «إدارة الموظفين: ممنوع» ويطمئنّ.
 */
class NoOneSeizesAnAccountAboveHisOwnTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $manager;

    private User $accountant;

    /** كاتبٌ لا يملك إلّا قسم «الرواتب والموظفين» */
    private User $clerk;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير فرع', 'role' => 'manager']);

        $this->owner = $this->staff('المالك', 'o', 'admin', null);
        $this->manager = $this->staff('المدير', 'mgr1', 'manager', null, 'مدير فرع');
        $this->accountant = $this->staff('المحاسب', 'c', 'accountant', null);
        $this->clerk = $this->staff('كاتب الموظفين', 'k', 'sales', ['employees']);
        $this->cashier = $this->staff('الكاشير', 'cash1', 'cashier', null, 'كاشير');
    }

    private function staff(string $name, string $key, string $role, ?array $perms, string $title = 'كاشير'): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => $name, 'email' => $key.'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'job_title' => $title, 'permissions' => $perms,
            'basic_salary' => 400, 'allowances' => 50,
        ]);
    }

    // ═════════════ الاستيلاءُ بكلمة المرور ═════════════

    /**
     * والكاتبُ لا يأخذ حسابَ المدير بكلمة مرورٍ يولّدها.
     *
     * والتحقّقُ من `issued_password` لا من رمز الحال وحده: الكلمةُ تُعرض في
     * الشاشة لتُنسخ، فوجودُها في الجلسة هو الاستيلاء نفسه.
     */
    public function test_a_clerk_cannot_reset_the_managers_password(): void
    {
        $before = $this->manager->password;

        $this->actingAs($this->clerk)
            ->post(route('admin.employees.resetPassword', $this->manager->id))
            ->assertForbidden()
            ->assertSessionMissing('issued_password');

        $this->assertSame($before, $this->manager->fresh()->password);
    }

    /** ولا يُعطّل حسابَه فيقفله عن صاحبه */
    public function test_a_clerk_cannot_disable_the_manager(): void
    {
        $this->actingAs($this->clerk)
            ->post(route('admin.employees.toggle', $this->manager->id))
            ->assertForbidden();

        $this->assertSame('نشط', $this->manager->fresh()->status);
    }

    /** ولا يعدّل بياناته */
    public function test_a_clerk_cannot_edit_the_manager(): void
    {
        $this->actingAs($this->clerk)->put(route('admin.employees.update', $this->manager->id), [
            'name' => 'اسمٌ آخر', 'login_username' => 'mgr1', 'job_title' => 'مدير فرع',
        ])->assertForbidden();

        $this->assertSame('المدير', $this->manager->fresh()->name);
    }

    /**
     * والمحاسبُ كذلك: دورُه يمنحه القسم ولا يمنحه المدير.
     *
     * وهو الأهمّ عمليًّا: `MAP['accountant']` تحمل `employees`، فكلُّ محاسبٍ
     * في كلّ متجرٍ كان يبلغ حسابَ مديره بلا أن يُمنح شيئًا.
     */
    public function test_an_accountant_cannot_reset_the_managers_password(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.employees.resetPassword', $this->manager->id))
            ->assertForbidden()
            ->assertSessionMissing('issued_password');
    }

    // ═════════════ وما بقي يعمل ═════════════

    /** والمالكُ يمسّ الجميع — وهو ما كان الحارسُ القديم يحفظه */
    public function test_the_owner_still_reaches_everyone(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.resetPassword', $this->manager->id))
            ->assertRedirect()
            ->assertSessionHas('issued_password');
    }

    /** ومديرُ الفرع يمسّ من دونه: الكاشيرُ لا يفتح شيئًا ليس للمدير */
    public function test_a_manager_still_reaches_the_cashier(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.employees.resetPassword', $this->cashier->id))
            ->assertRedirect()
            ->assertSessionHas('issued_password');
    }

    /** ولا يبلغ المالكَ — وهو فوقه بفعلٍ واحدٍ على الأقلّ */
    public function test_a_manager_still_cannot_reach_the_owner(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.employees.resetPassword', $this->owner->id))
            ->assertForbidden();
    }

    /**
     * ونظيرٌ يمسّ نظيرَه: مديران يفتحان الشيء نفسه.
     *
     * ولا استيلاءَ فيه — من يملك كلَّ ما يملكه الآخر لا يكسب بأخذ حسابه.
     */
    public function test_a_manager_reaches_another_manager(): void
    {
        $second = $this->staff('مدير ثانٍ', 'm2', 'manager', null, 'مدير فرع');

        $this->actingAs($this->manager)
            ->post(route('admin.employees.resetPassword', $second->id))
            ->assertRedirect()
            ->assertSessionHas('issued_password');
    }

    // ═════════════ الرواتب: بابان لا باب ═════════════

    /** وشاشةُ التعديل لا تُرسل راتبًا لمن لا يقرأ الرواتب */
    public function test_the_edit_screen_hides_the_salary_from_who_may_not_read_it(): void
    {
        $this->actingAs($this->clerk)->get(route('admin.employees.edit', $this->cashier->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('employee.basic_salary', null)
                ->where('employee.allowances', null)
                ->where('may_read_payroll', false)
                ->etc());
    }

    /** ومن يقرؤها يراها */
    public function test_the_owner_still_sees_the_salary(): void
    {
        $this->actingAs($this->owner)->get(route('admin.employees.edit', $this->cashier->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('may_read_payroll', true)
                ->where('employee.basic_salary', fn ($v) => (float) $v === 400.0)
                ->etc());
    }

    /**
     * وحفظةٌ من لا يقرأ الرواتب تنجح ولا تمسّ الرقم المسجَّل.
     *
     * والحقلُ يُرفع من الحمولة لا يُكتب صفرًا: الشاشةُ لا تعرضه، فنموذجٌ
     * يُرسَل بلا راتبٍ كان سيمسح راتبًا قائمًا في كلّ حفظة.
     *
     * ═══ وأوّلُ صياغةٍ لهذا الاختبار كانت تمرّ لغير سببها ═══
     *
     * كتبتُه بالكاتب (`clerk`) — وهو يُردّ بـ٤٠٣ عند `refuseGrantingMoreThanIHave`
     * قبل أن يبلغ الراتب أصلًا. فرفعتُ الحارسَ عمدًا وبقي الاختبارُ أخضر:
     * الراتبُ لم يتغيّر لأنّ الحفظة لم تقع، لا لأنّ الحقل رُفع.
     * و`assertSessionHasNoErrors` تمرّ على ٤٠٣ فلا تكشفه.
     *
     * فالفاعلُ هنا **مشرفٌ يبلغ الكاشير فعلًا**: يفتح ما يفتحه ولا يقرأ
     * الرواتب. و`assertRedirect` تُثبت أنّ الحفظة وقعت.
     */
    public function test_a_save_by_who_may_not_read_payroll_leaves_the_salary_alone(): void
    {
        $supervisor = $this->staff('مشرف', 'sup1', 'sales', ['employees', 'dashboard', 'pos']);

        $this->actingAs($supervisor)->put(route('admin.employees.update', $this->cashier->id), [
            'name' => 'الكاشير المعدَّل', 'login_username' => 'cash1', 'job_title' => 'كاشير',
            'basic_salary' => 9000, 'allowances' => 9000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->cashier->fresh();

        // والحفظةُ وقعت فعلًا — وإلّا لم يكن الاختبارُ يقول شيئًا
        $this->assertSame('الكاشير المعدَّل', $fresh->name);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->basic_salary, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->allowances, 0.001);
    }

    /** ولا يُكتب راتبٌ في موظّفٍ جديدٍ بيد من لا يقرؤه */
    public function test_a_clerk_cannot_write_a_salary_on_a_new_employee(): void
    {
        $this->actingAs($this->clerk)->post(route('admin.employees.store'), [
            'name' => 'موظف جديد', 'login_username' => 'newone', 'job_title' => 'كاشير',
            'basic_salary' => 5000, 'allowances' => 500,
            'manual_permissions' => true, 'permissions' => ['employees'],
        ])->assertSessionHasNoErrors();

        $made = User::where('name', 'موظف جديد')->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $made->basic_salary, 0.001);
    }

    /** ومن يقرؤها يكتبها */
    public function test_the_owner_still_writes_a_salary(): void
    {
        $this->actingAs($this->owner)->put(route('admin.employees.update', $this->cashier->id), [
            'name' => 'الكاشير', 'login_username' => 'cash1', 'job_title' => 'كاشير',
            'basic_salary' => 700, 'allowances' => 60,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(700.0, (float) $this->cashier->fresh()->basic_salary, 0.001);
    }

    // ═════════════ بطاقةُ الموظّف تقول الحقّ ═════════════

    /** وما تعرضه البطاقةُ هو ما يفتحه صاحبُها */
    public function test_the_card_lists_what_this_employee_actually_opens(): void
    {
        $this->actingAs($this->owner)->get(route('admin.employees.show', $this->clerk->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('permissions', [Permissions::sectionLabels()['employees']])
                ->where('permissions_are_manual', true)
                ->etc());
    }

    /** واثنان يختلفان في الصلاحيات يختلفان في البطاقة */
    public function test_two_employees_do_not_share_one_list(): void
    {
        $clerkCard = $this->actingAs($this->owner)
            ->get(route('admin.employees.show', $this->clerk->id))
            ->viewData('page')['props']['permissions'];

        $cashierCard = $this->actingAs($this->owner)
            ->get(route('admin.employees.show', $this->cashier->id))
            ->viewData('page')['props']['permissions'];

        $this->assertNotEquals($clerkCard, $cashierCard);
        // والكاشيرُ يفتح «نقطة البيع» والكاتبُ لا
        $this->assertContains(Permissions::sectionLabels()['pos'], $cashierCard);
        $this->assertNotContains(Permissions::sectionLabels()['pos'], $clerkCard);
    }

    /** والأفعالُ تُعرض مع الأقسام: المالكُ يملكها كلَّها */
    public function test_the_card_names_actions_too(): void
    {
        $card = $this->actingAs($this->owner)
            ->get(route('admin.employees.show', $this->manager->id))
            ->viewData('page')['props']['permissions'];

        $this->assertContains(Permissions::actionLabels()[Permissions::PAYROLL_APPROVE], $card);
        // والتجاوزُ للمالك وحده — فليس في بطاقة المدير
        $this->assertNotContains(Permissions::actionLabels()[Permissions::INVOICE_OVERRIDE], $card);
    }

    /** وبطاقةٌ لمن لا يملك شيئًا لا تخترع له صلاحية */
    public function test_a_card_with_nothing_granted_shows_nothing(): void
    {
        $nobody = $this->staff('بلا صلاحية', 'n', 'cashier', []);

        $this->actingAs($this->owner)->get(route('admin.employees.show', $nobody->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('permissions', [])->etc());
    }
}

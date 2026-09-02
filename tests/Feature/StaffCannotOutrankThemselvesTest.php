<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الموظفون والرواتب: من يمنح ماذا، ومن يرفع نفسه، وكم مرّةً يخرج الراتب.
 *
 * قسم «الموظفون» بابُ رواتبَ وأرقام هواتف في ظاهره، وهو في الحقيقة بابُ
 * أدوارٍ وصلاحيات: من يفتحه يستطيع أن يصنع حسابًا أوسع من حسابه ويدخل به.
 */
class StaffCannotOutrankThemselvesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        foreach ([['محاسب', 'accountant'], ['مدير فرع', 'manager'], ['كاشير', 'cashier']] as [$name, $role]) {
            JobTitle::create(['business_id' => $this->business->id, 'name' => $name, 'role' => $role]);
        }
    }

    private function staff(string $role, string $title, array $over = []): User
    {
        return User::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'موظف '.$role,
            'email' => $role.'@abaad.om', 'password' => bcrypt('password'),
            'role' => $role, 'job_title' => $title, 'status' => 'نشط',
            'basic_salary' => 300, 'allowances' => 50,
        ], $over));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'موظف جديد', 'job_title' => 'كاشير',
            'login_username' => 'newstaff', 'password' => 'secret123',
        ], $over);
    }

    /* ------------------- لا يُعطي أحدٌ ما لا يملك ------------------- */

    /**
     * المحاسب يُنشئ موظّفًا بوظيفةٍ دورُها «مدير فرع» — وهو يملك كلّ الأقسام —
     * ويضع له كلمة مرورٍ يعرفها ثمّ يدخل بها.
     */
    public function test_an_accountant_cannot_create_a_manager(): void
    {
        $this->actingAs($this->staff('accountant', 'محاسب'));

        $this->post(route('admin.employees.store'), $this->payload(['job_title' => 'مدير فرع']))
            ->assertForbidden();

        $this->assertNull(User::where('email', 'newstaff@abaadapp.om')->first());
    }

    public function test_an_accountant_cannot_hand_out_a_section_they_lack(): void
    {
        $this->actingAs($this->staff('accountant', 'محاسب'));

        // «الإعدادات» ليست من أقسام المحاسب — فلا يمنحها
        $this->post(route('admin.employees.store'), $this->payload([
            'manual_permissions' => 1, 'permissions' => ['settings'],
        ]))->assertForbidden();
    }

    public function test_an_accountant_may_still_hand_out_what_they_hold(): void
    {
        $this->actingAs($this->staff('accountant', 'محاسب'));

        $this->post(route('admin.employees.store'), $this->payload([
            'manual_permissions' => 1, 'permissions' => ['orders', 'customers'],
        ]))->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'newstaff@abaadapp.om')->first());
    }

    public function test_the_owner_is_bound_by_nothing(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('admin.employees.store'), $this->payload(['job_title' => 'مدير فرع']))
            ->assertSessionHasNoErrors();

        $this->assertSame('manager', User::where('email', 'newstaff@abaadapp.om')->value('role'));
    }

    /* ------------------- ولا يرفع أحدٌ نفسه ------------------- */

    public function test_an_accountant_cannot_promote_themselves(): void
    {
        $me = $this->staff('accountant', 'محاسب');
        $this->actingAs($me);

        // ويُردّ من الحارس الأوّل: وظيفةُ مدير الفرع تحمل أقسامًا لا يملكها
        $this->put(route('admin.employees.update', $me->id), [
            'name' => $me->name, 'email' => $me->email, 'job_title' => 'مدير فرع',
        ])->assertForbidden();

        $this->assertSame('accountant', $me->fresh()->role);
    }

    /**
     * وظيفةٌ بدورٍ آخر لا تزيده صلاحيةً — فيمرّ من الحارس الأوّل ويقف عند
     * الثاني. ولولاه لبدّل الموظّف دورَه بنفسه.
     */
    public function test_a_sideways_self_promotion_is_refused_too(): void
    {
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مشرف', 'role' => 'sales']);
        $me = $this->staff('cashier', 'كاشير', ['permissions' => ['dashboard', 'employees', 'orders', 'customers', 'products', 'pos', 'preparation']]);
        $this->actingAs($me);

        $this->put(route('admin.employees.update', $me->id), [
            'name' => $me->name, 'email' => $me->email, 'job_title' => 'مشرف',
        ])->assertSessionHasErrors('job_title');

        $this->assertSame('cashier', $me->fresh()->role);
    }

    public function test_an_accountant_cannot_raise_their_own_salary(): void
    {
        $me = $this->staff('accountant', 'محاسب');
        $this->actingAs($me);

        $this->put(route('admin.employees.update', $me->id), [
            'name' => $me->name, 'email' => $me->email, 'job_title' => 'محاسب',
            'basic_salary' => 5000,
        ])->assertSessionHasErrors('job_title');

        $this->assertEqualsWithDelta(300, (float) $me->fresh()->basic_salary, 0.001);
    }

    public function test_they_may_still_correct_their_own_phone(): void
    {
        $me = $this->staff('accountant', 'محاسب');
        $this->actingAs($me);

        $this->put(route('admin.employees.update', $me->id), [
            'name' => $me->name, 'email' => $me->email, 'job_title' => 'محاسب',
            'phone' => '95555555',
        ])->assertSessionHasNoErrors();

        $this->assertSame('95555555', $me->fresh()->phone);
    }

    public function test_the_owner_may_raise_anyone(): void
    {
        $clerk = $this->staff('cashier', 'كاشير');
        $this->actingAs($this->owner);

        $this->put(route('admin.employees.update', $clerk->id), [
            'name' => $clerk->name, 'email' => $clerk->email, 'job_title' => 'مدير فرع',
            'basic_salary' => 900,
        ])->assertSessionHasNoErrors();

        $this->assertSame('manager', $clerk->fresh()->role);
    }

    /* ------------------- حساب صاحب النشاط ------------------- */

    public function test_the_owners_row_is_read_only_to_everyone_else(): void
    {
        $this->actingAs($this->staff('accountant', 'محاسب'));

        $this->put(route('admin.employees.update', $this->owner->id), [
            'name' => 'مسروق', 'login_username' => MerchantAccount::username($this->owner->email), 'job_title' => 'محاسب',
        ])->assertForbidden();

        $this->post(route('admin.employees.resetPassword', $this->owner->id))->assertForbidden();
        $this->post(route('admin.employees.toggle', $this->owner->id))->assertForbidden();

        $this->assertSame('المالك', $this->owner->fresh()->name);
    }

    /* ------------------- عزل المستأجر ------------------- */

    public function test_a_neighbours_employee_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = User::create([
            'business_id' => $other->id, 'name' => 'موظفهم', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);

        $this->get(route('admin.employees.edit', $theirs->id))->assertNotFound();
        $this->put(route('admin.employees.update', $theirs->id), [
            'name' => 'مسروق', 'job_title' => 'كاشير',
        ])->assertNotFound();
        $this->post(route('admin.employees.resetPassword', $theirs->id))->assertNotFound();
        $this->post(route('admin.employees.toggle', $theirs->id))->assertNotFound();

        $this->assertSame('موظفهم', $theirs->fresh()->name);
    }

    public function test_a_branch_of_another_shop_is_never_assigned(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        $mine = Branch::where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner);
        $this->post(route('admin.employees.store'), $this->payload([
            'branches' => [$theirBranch->id, $mine->id],
        ]))->assertSessionHasNoErrors();

        $employee = User::where('email', 'newstaff@abaadapp.om')->firstOrFail();
        $this->assertSame([$mine->id], $employee->branches()->pluck('branches.id')->all());
    }

    /* ------------------- الراتب يخرج مرّة ------------------- */

    private function draftRun(): PayrollRun
    {
        $this->actingAs($this->owner);
        $this->staff('cashier', 'كاشير', ['email' => 'c1@abaad.om', 'basic_salary' => 400, 'allowances' => 0]);
        Ledger::seedChart($this->business->id);

        $this->post(route('admin.payroll.store'), ['period' => now()->format('Y-m')])
            ->assertSessionHasNoErrors();

        return PayrollRun::latest('id')->firstOrFail();
    }

    private function balance(string $key): float
    {
        $account = Ledger::account($this->business->id, $key);
        $row = collect(Ledger::trialBalance($this->business->id)['accounts'])->firstWhere('id', $account->id);

        return (float) ($row['balance'] ?? 0);
    }

    public function test_approving_twice_accrues_the_salary_once(): void
    {
        $run = $this->draftRun();

        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();
        $accrued = $this->balance('salaries');
        $this->post(route('admin.payroll.approve', $run->id));

        $this->assertSame($accrued, $this->balance('salaries'), 'قُيّد مصروف الرواتب مرّتين');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_paying_the_same_lines_twice_pays_them_once(): void
    {
        $run = $this->draftRun();
        $this->post(route('admin.payroll.approve', $run->id));
        $ids = PayrollLine::where('payroll_run_id', $run->id)->pluck('id')->all();

        $pay = fn () => $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);

        $pay();
        $cash = $this->balance('cash');
        $pay();

        $this->assertSame($cash, $this->balance('cash'), 'خرج الراتب من الصندوق مرّتين');
        $this->assertSame(0.0, round($this->balance('salaries_payable'), 3));
    }

    public function test_a_paid_run_closes_itself(): void
    {
        $run = $this->draftRun();
        $this->post(route('admin.payroll.approve', $run->id));
        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => PayrollLine::where('payroll_run_id', $run->id)->pluck('id')->all(),
            'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);

        $this->assertSame('مصروفة', $run->fresh()->status);
    }

    /**
     * والقفل لا يُثبته اختبارٌ متسلسل — وأقولها كما هي.
     *
     * الطلب الثاني في الاختبار يقرأ الصفّ من جديد فيرى ما كتبه الأول ويُردّ
     * بالفحص وحده. والحال التي يحرسها القفل طلبان **يجريان معًا** على
     * عاملَي PHP مختلفين، وذاك لا يُصنَع في عمليةٍ واحدة. فيبقى الحارس
     * مقروءًا من مصدره كي لا يُرفع سهوًا: راتبٌ يُقيَّد مرّتين كلفةُ عمالةٍ
     * مضاعفة، وراتبٌ يُصرف مرّتين مالٌ خرج مرّتين.
     */
    public function test_the_payroll_writes_read_under_a_lock(): void
    {
        $approve = file_get_contents(base_path('app/Http/Controllers/Admin/Payroll/PayrollRunController.php'));
        $pay = file_get_contents(base_path('app/Http/Controllers/Admin/Payroll/PayrollPaymentController.php'));

        $this->assertStringContainsString('lockForUpdate()->find($run->id)', $approve);
        $this->assertStringContainsString('->lockForUpdate()->get()', $pay);
        $this->assertStringContainsString("where('paid', false)", $pay);
    }

    public function test_a_neighbours_payroll_cannot_be_approved_from_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = PayrollRun::create([
            'business_id' => $other->id, 'period' => now()->startOfMonth(),
            'number' => 'PR-THEIRS',
            'status' => 'مسودة', 'gross' => 100, 'deductions' => 0, 'net' => 100,
        ]);

        $this->actingAs($this->owner);
        $this->post(route('admin.payroll.approve', $theirs->id))->assertNotFound();

        $this->assertSame('مسودة', $theirs->fresh()->status);
    }
}

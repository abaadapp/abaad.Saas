<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرواتب: ما استُحقّ شيء، وما صُرف شيءٌ آخر.
 *
 * راتبُ شهرٍ اعتُمد ولم يُصرف التزامٌ قائم على المتجر. ودمجُ الاعتماد بالصرف
 * يُخفي هذا الالتزام حتى يخرج المال — فيُقرأ الشهر ربحًا وهو مدين برواتبه،
 * ويُنفق ما ليس له.
 *
 * والخطر المقابل أن يُقيَّد المصروف مرّتين: مرّةً عند الاعتماد ومرّةً عند
 * الصرف، فتظهر رواتب الشهر ضِعفها في قائمة الدخل.
 */
class PayrollTest extends TestCase
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
            'basic_salary' => 0, 'allowances' => 0,
        ]);

        $this->actingAs($this->owner);
        $this->get(route('admin.payroll.index'));
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function employee(string $name, float $basic, float $allowances = 0): User
    {
        return User::create([
            'business_id' => $this->bid(), 'name' => $name,
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'basic_salary' => $basic, 'allowances' => $allowances,
        ]);
    }

    private function openRun(?string $period = null)
    {
        return $this->post(route('admin.payroll.store'), ['period' => $period ?? now()->format('Y-m')]);
    }

    /* ------------------------------ الفتح ------------------------------ */

    public function test_opening_a_run_fills_it_from_the_employees_salaries(): void
    {
        $this->employee('سالم', 300, 50);
        $this->employee('ريم', 400);

        $this->openRun()->assertSessionHasNoErrors();

        $run = PayrollRun::first();

        $this->assertSame(2, $run->lines()->count());
        $this->assertSame(750.0, (float) $run->gross);
        $this->assertSame(750.0, (float) $run->net);
        // من لا راتب له لا سطر له — والمالك بلا راتب مسجَّل
        $this->assertSame(0, PayrollLine::where('employee_name', 'المالك')->count());
    }

    public function test_a_month_gets_one_run_not_two(): void
    {
        // مسيرتان لشهرٍ واحد تعنيان راتبًا يُقيَّد مرّتين
        $this->employee('سالم', 300);

        $this->openRun()->assertSessionHasNoErrors();
        $this->openRun()->assertSessionHasErrors('period');

        $this->assertSame(1, PayrollRun::count());
    }

    public function test_a_later_raise_does_not_rewrite_an_older_run(): void
    {
        /*
         * القيم تُنسخ إلى السطور ولا تُقرأ من الموظّف عند كل عرض: راتبٌ يُرفع
         * في مارس لا يجوز أن يُعيد كتابة مسيرة يناير — وإلا تغيّرت مسيرةٌ
         * صُرفت واختلفت عمّا قُيّد في الدفتر.
         */
        $employee = $this->employee('سالم', 300);
        $this->openRun();

        $employee->update(['basic_salary' => 900]);

        $this->assertSame(300.0, (float) PayrollLine::first()->basic);
        $this->assertSame(300.0, (float) PayrollRun::first()->net);
    }

    public function test_a_store_with_no_salaries_is_told_why(): void
    {
        $this->openRun()->assertSessionHasErrors('period');

        $this->assertSame(0, PayrollRun::count());
    }

    /* ----------------------------- الاعتماد ----------------------------- */

    public function test_approving_books_the_liability_not_the_payment(): void
    {
        $this->employee('سالم', 300);
        $this->openRun();
        $run = PayrollRun::first();

        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();

        $this->assertSame('معتمدة', $run->fresh()->status);
        $this->assertSame(300.0, Ledger::account($this->bid(), 'salaries')->balance());
        $this->assertSame(300.0, Ledger::account($this->bid(), 'salaries_payable')->balance());
        // لم يخرج مالٌ بعد
        $this->assertSame(0.0, Ledger::account($this->bid(), 'cash')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_deductions_lower_what_is_owed_not_the_cost_of_labour(): void
    {
        /*
         * ما استحقّه الموظّف هو إجماليه، وما يُدفع له بعد الخصم أقلّ. وطرحُ
         * الخصم من المصروف يُخفي كلفة العمالة الحقيقية.
         */
        $this->employee('سالم', 300);
        $this->openRun();
        $line = PayrollLine::first();

        $this->put(route('admin.payroll.lines.update', $line->id), [
            'basic' => 300, 'allowances' => 0, 'overtime' => 0, 'deductions' => 50,
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.payroll.approve', PayrollRun::first()->id));

        $this->assertSame(250.0, (float) PayrollRun::first()->net);
        $this->assertSame(300.0, Ledger::account($this->bid(), 'salaries')->balance(), 'ابتلع الخصمُ كلفةَ العمالة');
        $this->assertSame(250.0, Ledger::account($this->bid(), 'salaries_payable')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_an_approved_run_no_longer_accepts_edits(): void
    {
        // تعديلُ سطرٍ بعد اعتماده يجعل الدفتر يقول غير ما تقوله المسيرة
        $this->employee('سالم', 300);
        $this->openRun();
        $run = PayrollRun::first();
        $line = PayrollLine::first();

        $this->post(route('admin.payroll.approve', $run->id));

        $this->put(route('admin.payroll.lines.update', $line->id), [
            'basic' => 900, 'allowances' => 0, 'overtime' => 0, 'deductions' => 0,
        ]);

        $this->assertSame(300.0, (float) $line->fresh()->basic);
        $this->assertSame(300.0, (float) $run->fresh()->net);
    }

    public function test_approving_twice_does_not_double_the_expense(): void
    {
        $this->employee('سالم', 300);
        $this->openRun();
        $run = PayrollRun::first();

        $this->post(route('admin.payroll.approve', $run->id));
        $this->post(route('admin.payroll.approve', $run->id));

        $this->assertSame(300.0, Ledger::account($this->bid(), 'salaries')->balance());
        $this->assertSame(1, JournalEntry::where('business_id', $this->bid())->count());
    }

    public function test_a_run_that_entered_the_ledger_is_not_deleted(): void
    {
        $this->employee('سالم', 300);
        $this->openRun();
        $run = PayrollRun::first();
        $this->post(route('admin.payroll.approve', $run->id));

        $this->delete(route('admin.payroll.destroy', $run->id));

        $this->assertNotNull(PayrollRun::find($run->id), 'حُذفت مسيرةٌ لها قيدٌ في الدفتر');
    }

    /* ------------------------------ الصرف ------------------------------ */

    private function approvedRun(): PayrollRun
    {
        $this->employee('سالم', 300);
        $this->employee('ريم', 200);
        $this->openRun();
        $run = PayrollRun::first();
        $this->post(route('admin.payroll.approve', $run->id));

        return $run->fresh();
    }

    public function test_paying_lowers_the_liability_and_the_cash_only(): void
    {
        // ولا يقيّد مصروفًا ثانيًا: المصروف قُيّد يوم الاعتماد
        $run = $this->approvedRun();

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $run->lines->pluck('id')->all(),
            'paid_at' => now()->toDateString(),
            'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(500.0, Ledger::account($this->bid(), 'salaries')->balance(), 'قُيّد المصروف مرّتين');
        $this->assertSame(0.0, Ledger::account($this->bid(), 'salaries_payable')->balance());
        $this->assertSame(-500.0, Ledger::account($this->bid(), 'cash')->balance());
        $this->assertSame('مصروفة', $run->fresh()->status);
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_paying_one_employee_leaves_the_rest_owed(): void
    {
        /*
         * موظّفٌ يُصرف له اليوم وآخر في الأسبوع القادم واقعٌ لا استثناء.
         * ومسيرةٌ تُصرف كتلةً واحدة تُجبر التاجر على الانتظار أو على أن يكذب
         * في التاريخ.
         */
        $run = $this->approvedRun();
        $first = $run->lines->firstWhere('employee_name', 'سالم');

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => [$first->id],
            'paid_at' => now()->toDateString(),
            'from' => 'bank',
        ])->assertSessionHasNoErrors();

        $this->assertSame(200.0, Ledger::account($this->bid(), 'salaries_payable')->balance());
        $this->assertSame('معتمدة', $run->fresh()->status, 'أُقفلت مسيرةٌ ما زال فيها من ينتظر');
        $this->assertTrue($first->fresh()->paid);
    }

    public function test_paying_the_same_line_twice_does_not_take_the_money_twice(): void
    {
        $run = $this->approvedRun();
        $ids = $run->lines->pluck('id')->all();

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);
        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);

        $this->assertSame(-500.0, Ledger::account($this->bid(), 'cash')->balance(), 'خرج المال مرّتين');
        $this->assertSame(0.0, Ledger::account($this->bid(), 'salaries_payable')->balance());
    }

    public function test_a_draft_run_cannot_be_paid(): void
    {
        $this->employee('سالم', 300);
        $this->openRun();
        $run = PayrollRun::first();

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $run->lines->pluck('id')->all(),
            'paid_at' => now()->toDateString(),
            'from' => 'cash',
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0.0, Ledger::account($this->bid(), 'cash')->balance());
    }

    /**
     * والراتب يُكتب بلوحةٍ عربية كما يُكتب بغيرها.
     *
     * `basic_salary` و`allowances` كانتا خارج قائمة توحيد الأرقام، فراتبٌ
     * يُكتب «٥٠٠،٧٥» — والفاصلةُ ما تُخرجه لوحتُه حين يعني الفاصل العشريّ —
     * يُردّ بـ«يجب أن يكون رقمًا» على رقمٍ صحيح، ويبقى الراتب القديم كما هو
     * ولا تظهر المسيرةُ التالية بما قرّره المدير.
     */
    public function test_a_salary_typed_on_an_arabic_keyboard_reaches_the_employee(): void
    {
        $employee = $this->employee('سالم', 300);
        $employee->update(['email' => 'salem@abaad.om']);
        $title = JobTitle::create([
            'business_id' => $this->bid(), 'name' => 'كاشير', 'role' => 'cashier',
        ]);

        $this->actingAs($this->owner)->put(route('admin.employees.update', $employee->id), [
            'name' => $employee->name,
            'email' => 'salem@abaad.om',
            'role' => 'cashier',
            'job_title' => $title->name,
            'basic_salary' => '٥٠٠،٧٥',
            'allowances' => '٢٥٫٥',
        ])->assertSessionHasNoErrors();

        $fresh = $employee->fresh();

        $this->assertSame(500.75, (float) $fresh->basic_salary, 'الراتب رُدّ أو ضاعت فاصلتُه');
        $this->assertSame(25.5, (float) $fresh->allowances);
    }

    public function test_another_stores_run_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = PayrollRun::create([
            'business_id' => $other->id, 'number' => 'PR-00001',
            'period' => now()->startOfMonth()->toDateString(), 'status' => 'معتمدة',
        ]);

        $this->post(route('admin.payroll.approve', $theirs->id))->assertNotFound();
        $this->delete(route('admin.payroll.destroy', $theirs->id))->assertNotFound();
        $this->assertNotNull(PayrollRun::find($theirs->id));
    }
}

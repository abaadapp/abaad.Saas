<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * كشف الحساب البنكي يقرأ ما مرّ بالبنك.
 *
 * كان يقرأ كلّ معاملة أيًّا كانت وسيلتها، والنقد لا يمرّ بالبنك. فكان
 * «الرصيد الحالي» مجموعَ ما دخل المتجر وخرج منه لا رصيدَ الحساب، ولا يطابق
 * كشف البنك في ريالٍ واحد أبدًا.
 *
 * وأسوأ منه أثرًا: بيعةٌ نقدية بـ٤٧٫٢٥٠ كانت تُطابَق بإيداعٍ بنكيّ بالمبلغ
 * نفسه فيُكتب «مطابق» — فتقول الشاشة «الحساب سليم» وهي لم تقارن شيئًا. وهذه
 * الشاشة تُفتح لكشف الفرق لا لإخفائه.
 */
class BankReconciliationTest extends TestCase
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

        $this->actingAs($this->owner);
    }

    private function trx(string $type, float $amount, string $method, ?string $when = null): Transaction
    {
        return Transaction::create([
            'business_id' => $this->business->id,
            'reference' => Transaction::nextReference($this->business->id),
            'description' => 'قيد', 'method' => $method, 'type' => $type,
            'amount' => $amount, 'employee_name' => 'المالك',
            'occurred_at' => $when ?? now()->toDateTimeString(),
        ]);
    }

    private function line(float $amount, ?string $date = null, ?string $ref = null): BankStatementLine
    {
        return BankStatementLine::create([
            'business_id' => $this->business->id,
            'date' => $date ?? now()->toDateString(),
            'description' => 'حركة', 'reference' => $ref,
            'amount' => $amount,
        ]);
    }

    /* --------------------------- ما مرّ بالبنك --------------------------- */

    public function test_cash_never_enters_the_bank_statement(): void
    {
        $this->trx('دخل', 100, 'نقدي');
        $this->trx('دخل', 40, 'تحويل بنكي');
        $this->trx('دخل', 10, 'بطاقة');

        $statement = Demo::bankStatement();

        $this->assertSame(50.0, $statement['closing'], 'دخل النقدُ رصيدَ البنك');
        $this->assertCount(2, $statement['rows']);
    }

    public function test_a_cash_sale_is_not_matched_to_a_deposit(): void
    {
        /*
         * أخطر من عدم المطابقة: يقول «الحساب سليم» وهو لم يقارن شيئًا.
         */
        $this->trx('دخل', 47.25, 'نقدي');
        $this->line(47.25);

        $this->post(route('admin.bank.rematch'))->assertSessionHasNoErrors();

        $this->assertSame('غير مطابق', BankStatementLine::first()->match_status);
    }

    public function test_a_bank_transfer_is_still_matched(): void
    {
        // الحماية ليست تعطيلًا: ما مرّ بالبنك يُطابَق كما كان
        $this->trx('دخل', 47.25, 'تحويل بنكي');
        $this->line(47.25);

        $this->post(route('admin.bank.rematch'));

        $this->assertSame('مطابق', BankStatementLine::first()->match_status);
    }

    public function test_an_expense_matches_a_withdrawal_not_a_deposit(): void
    {
        // الاتجاه جزءٌ من المطابقة: صادرٌ لا يُطابَق بوارد
        $this->trx('مصروف', 80, 'تحويل بنكي');
        $this->line(80);   // إيداع بالمبلغ نفسه

        $this->post(route('admin.bank.rematch'));
        $this->assertSame('غير مطابق', BankStatementLine::first()->match_status);

        $this->line(-80);  // سحب
        $this->post(route('admin.bank.rematch'));

        $this->assertSame('مطابق', BankStatementLine::where('amount', '<', 0)->first()->match_status);
    }

    /* ----------------------- تاريخ الرصيد الافتتاحي ----------------------- */

    public function test_what_precedes_the_opening_balance_is_not_counted_twice(): void
    {
        /*
         * الرصيد الافتتاحي يتضمّن ما قبله، فجمعُه إليه يحسبه مرّتين. وكان
         * الحقل يُملأ ويُحفظ ويُعرض ولا يدخل أيّ حساب.
         */
        BankAccount::where('business_id', $this->business->id)->delete();
        BankAccount::create([
            'business_id' => $this->business->id,
            'opening_balance' => 1000,
            'opening_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->trx('دخل', 500, 'تحويل بنكي', now()->subMonth()->toDateTimeString());

        $this->assertSame(1000.0, Demo::bankStatement()['closing'], 'حُسب ما قبل التاريخ مرّتين');
    }

    public function test_what_follows_the_opening_balance_is_counted(): void
    {
        BankAccount::where('business_id', $this->business->id)->delete();
        BankAccount::create([
            'business_id' => $this->business->id,
            'opening_balance' => 1000,
            'opening_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->trx('دخل', 500, 'تحويل بنكي', now()->toDateTimeString());

        $this->assertSame(1500.0, Demo::bankStatement()['closing']);
    }

    public function test_an_empty_opening_balance_does_not_break_the_page(): void
    {
        $account = \App\Support\Bank::account($this->business->id);

        // كان مسحُ الرقم لتصحيحه يُسقط الشاشة بخطأ ٥٠٠
        $this->put(route('admin.finance.banks.update', $account->id), [
            'bank_name' => 'بنك مسقط', 'account_name' => 'متجري',
            'iban' => '', 'opening_balance' => '', 'opening_date' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0.0, (float) BankAccount::where('business_id', $this->business->id)->value('opening_balance'));
    }

    /* ------------------------------ الاستيراد ------------------------------ */

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'statement.csv', 'text/csv', null, true);
    }

    public function test_importing_a_second_month_keeps_the_first(): void
    {
        // كان كل استيرادٍ يحذف السابق: تستورد فبراير فيضيع يناير بلا تحذير
        $this->post(route('admin.bank.import'), [
            'statement' => $this->csv("التاريخ,البيان,المبلغ\n2026-01-05,إيداع,100\n"),
        ]);
        $this->post(route('admin.bank.import'), [
            'statement' => $this->csv("التاريخ,البيان,المبلغ\n2026-02-05,إيداع,200\n"),
        ]);

        $this->assertSame(2, BankStatementLine::count());
    }

    public function test_the_same_file_twice_does_not_double_the_lines(): void
    {
        $file = "التاريخ,البيان,المرجع,المبلغ\n2026-01-05,إيداع,REF1,100\n2026-01-06,سحب,REF2,-40\n";

        $this->post(route('admin.bank.import'), ['statement' => $this->csv($file)]);
        $this->post(route('admin.bank.import'), ['statement' => $this->csv($file)]);

        $this->assertSame(2, BankStatementLine::count(), 'تضاعف الكشف بإعادة استيراد الملفّ نفسه');
    }

    public function test_a_statement_with_debit_and_credit_columns_is_read(): void
    {
        /*
         * أغلب كشوف البنوك هنا بعمودين منفصلين لا بعمودٍ بإشارة — وكان
         * الملفّ يُرفض كلّه: «تعذّر التعرّف على الأعمدة».
         */
        $this->post(route('admin.bank.import'), [
            'statement' => $this->csv("التاريخ,البيان,مدين,دائن\n2026-01-05,إيداع,,100\n2026-01-06,سحب,40,\n"),
        ])->assertSessionHasNoErrors();

        $this->assertSame(100.0, (float) BankStatementLine::whereDate('date', '2026-01-05')->value('amount'));
        $this->assertSame(-40.0, (float) BankStatementLine::whereDate('date', '2026-01-06')->value('amount'), 'المدين صادر');
    }

    /* ------------------------------ الملخّص ------------------------------ */

    public function test_missing_from_the_bank_is_counted_inside_the_statement_period_only(): void
    {
        /*
         * كان يعدّ عمر المتجر كلّه: تستورد كشف شهرٍ فيقول إنّ معاملات الأشهر
         * السابقة ناقصةٌ من البنك — وهي في كشوفها هي.
         */
        $this->trx('دخل', 10, 'تحويل بنكي', now()->subMonths(3)->toDateTimeString());
        $this->trx('دخل', 20, 'تحويل بنكي', now()->subMonths(2)->toDateTimeString());
        $this->trx('دخل', 30, 'تحويل بنكي', now()->toDateTimeString());
        $this->trx('دخل', 55, 'تحويل بنكي', now()->toDateTimeString());

        $this->line(30);
        $this->post(route('admin.bank.rematch'));

        $summary = Demo::reconciliationSummary();

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['unmatched_system'], 'عُدّت معاملات خارج مدى الكشف');
    }

    public function test_another_business_statement_is_never_read(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        BankStatementLine::create([
            'business_id' => $other->id, 'date' => now()->toDateString(), 'amount' => 999,
        ]);

        $this->assertSame(0, Demo::reconciliationSummary()['lines']);
        $this->assertCount(0, Demo::bankLines());
    }
}

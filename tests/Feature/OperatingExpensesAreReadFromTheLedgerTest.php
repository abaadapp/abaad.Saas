<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseBranchAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Profitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * المصروفاتُ التشغيليّة من الدفتر — رقمٌ واحد في اللوحة والمالية والتقارير.
 *
 * كان «صافي الربح» يطرح جدولَ المصروفات المدفوعة وحده: الإيجارُ يُطرح،
 * والرواتبُ والإهلاكُ لا. فصار يطرح `Ledger::operatingExpenses` — كلَّ
 * حسابٍ نوعُه «مصروف» إلّا تكلفةَ البضاعة، وكلَّ حدثٍ مرّةً واحدة بقيده.
 */
class OperatingExpensesAreReadFromTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->muscat, $this->sohar] = $this->business('متجري', 'o@abaad.om');
        [$this->other] = $this->business('الجار', 'x@other.om');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $a = Branch::create(['business_id' => $shop->id, 'name' => 'مسقط']);
        $b = Branch::create(['business_id' => $shop->id, 'name' => 'صحار']);
        Ledger::seedChart($shop->id);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner, $a, $b];
    }

    private function entry(array $lines, string $date, string $source = 'يدوي', ?Branch $branch = null, ?Business $shop = null): JournalEntry
    {
        return Ledger::post(($shop ?? $this->shop)->id, 'قيد اختبار', $lines, Carbon::parse($date), $source, $branch?->id);
    }

    private function expense(float $amount, string $type, string $date, ?Branch $branch = null, string $status = Expense::PAID): Expense
    {
        $expense = Expense::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch?->id, 'type' => $type,
            'description' => $type, 'amount' => $amount, 'method' => 'نقدي', 'status' => $status,
            'spent_at' => $date,
        ]);

        // المدفوعُ يُرحَّل يومَ يُدفع — وغيرُ المدفوع لا قيدَ له اليوم
        if ($status === Expense::PAID) {
            Books::recordExpense($expense);
        }

        return $expense;
    }

    /** راتبٌ كما يكتبه `PayrollRunController::approve` — ثمّ صرفُه كما يكتبه `PayrollPaymentController::store` */
    private function payroll(float $amount, string $date, bool $paid = false): void
    {
        $this->entry([['account' => 'salaries', 'debit' => $amount], ['account' => 'salaries_payable', 'credit' => $amount]], $date, 'رواتب');

        if ($paid) {
            $this->entry([['account' => 'salaries_payable', 'debit' => $amount], ['account' => 'cash', 'credit' => $amount]], $date, 'صرف رواتب');
        }
    }

    private function sale(float $cost, string $date, float $total = 1000): Order
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => $total, 'cost' => $cost, 'quantity' => 50]);
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => Carbon::parse($date),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة',
            'price' => $total, 'quantity' => 1, 'cost' => $cost, 'total' => $total]);
        Books::recordSale($order);

        return $order;
    }

    private function opex(?string $from = '2026-09-01', ?string $to = '2026-10-01', ?Branch $branch = null): float
    {
        return Ledger::operatingExpenses($this->shop->id, $from ? Carbon::parse($from) : null, $to ? Carbon::parse($to) : null, $branch?->id);
    }

    /* ═══════════ ما يدخل وما لا يدخل ═══════════ */

    public function test_a_manual_expense_and_a_salary_make_one_operating_total(): void
    {
        $this->expense(100, 'إيجار', '2026-09-05');
        $this->payroll(200, '2026-09-30');

        $this->assertSame(300.0, $this->opex());
    }

    public function test_cogs_is_not_an_operating_expense_and_stands_on_its_own(): void
    {
        $this->sale(500, '2026-09-10');

        $this->assertSame(0.0, $this->opex(), 'دخلت تكلفةُ البضاعة في المصروفات التشغيليّة');

        $this->actingAs($this->owner);
        $summary = Demo::reportSummary('month');
        $this->assertSame(500.0, $summary['cogs']);
        $this->assertSame(0.0, (float) $summary['expenses']);
    }

    public function test_a_supplier_inventory_invoice_and_its_payment_are_not_operating_expenses(): void
    {
        // كما يكتبه `SupplierInvoices::approve` — مخزونٌ وضريبةُ مدخلات وذمّة
        $this->entry([
            ['account' => 'inventory', 'debit' => 100],
            ['account' => 'tax_input', 'debit' => 5],
            ['account' => 'payable', 'credit' => 105],
        ], '2026-09-03', 'سند مورّد');
        $this->assertSame(0.0, $this->opex());

        // وسدادُها لا يُنشئ مصروفًا
        $this->entry([['account' => 'payable', 'debit' => 105], ['account' => 'bank', 'credit' => 105]], '2026-09-10', 'سداد مورّد');
        $this->assertSame(0.0, $this->opex());
    }

    public function test_paying_an_approved_payroll_does_not_count_the_salary_twice(): void
    {
        $this->payroll(200, '2026-09-30', paid: true);

        $this->assertSame(200.0, $this->opex());
    }

    public function test_depreciation_and_direct_purchases_are_operating_expenses(): void
    {
        // كما يكتبه `FixedAssetController::depreciate`
        $this->entry([['account' => 'depreciation', 'debit' => 50], ['account' => 'accumulated_depreciation', 'credit' => 50]], '2026-09-30', 'إهلاك');
        // «مواد خام» يُرحَّل إلى المشتريات المباشرة (`Books::TYPE_ACCOUNTS`)
        $this->expense(30, 'مواد خام', '2026-09-07');

        $this->assertSame('direct_purchases', Books::expenseAccount('مواد خام', $this->shop->id));
        $this->assertSame(80.0, $this->opex());
    }

    public function test_a_new_expense_account_counts_without_being_listed(): void
    {
        $account = Account::create([
            'business_id' => $this->shop->id, 'code' => '5950', 'name' => 'اشتراكات برمجيّات',
            'type' => 'مصروف', 'normal_side' => 'debit', 'parent_id' => Account::where('business_id', $this->shop->id)->where('code', '5')->value('id'),
        ]);
        // حسابٌ أنشأه التاجر بلا مفتاحٍ نظاميّ — قيدٌ يدويٌّ مرحَّل عليه
        $entry = JournalEntry::create(['business_id' => $this->shop->id, 'number' => 'JV-SOFT', 'entry_date' => '2026-09-15',
            'description' => 'اشتراك', 'source' => 'يدوي', 'posted' => true, 'posted_at' => now()]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $account->id, 'debit' => 12, 'credit' => 0]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => Ledger::account($this->shop->id, 'cash')->id, 'debit' => 0, 'credit' => 12]);
        $this->assertNull($account->fresh()->system_key);

        $this->assertSame(12.0, $this->opex());
    }

    public function test_a_reversal_cancels_its_expense(): void
    {
        $rent = $this->expense(75, 'إيجار', '2026-09-05');
        $this->assertSame(75.0, $this->opex());

        Books::unpostExpense($rent);

        $this->assertSame(0.0, $this->opex(), 'القيدُ العكسيّ لم يُلغِ أثرَ المصروف');
    }

    public function test_an_unpaid_expense_is_not_counted_yet(): void
    {
        $this->expense(90, 'صيانة', '2026-09-05', status: Expense::UNPAID);

        $this->assertSame(0.0, $this->opex());
    }

    public function test_an_unposted_draft_is_not_counted(): void
    {
        $entry = JournalEntry::create(['business_id' => $this->shop->id, 'number' => 'JV-DRAFT', 'entry_date' => '2026-09-05',
            'description' => 'مسوّدة', 'source' => 'يدوي', 'posted' => false]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => Ledger::account($this->shop->id, 'rent')->id, 'debit' => 80, 'credit' => 0]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => Ledger::account($this->shop->id, 'cash')->id, 'debit' => 0, 'credit' => 80]);

        $this->assertSame(0.0, $this->opex());
    }

    public function test_another_business_is_never_counted(): void
    {
        $this->entry([['account' => 'rent', 'debit' => 999], ['account' => 'cash', 'credit' => 999]], '2026-09-05', shop: $this->other);
        $this->expense(10, 'إيجار', '2026-09-05');

        $this->assertSame(10.0, $this->opex());
    }

    /* ═══════════ الفترة والفرع ═══════════ */

    public function test_the_month_reads_only_its_own_entries(): void
    {
        $this->expense(40, 'إيجار', '2026-08-31');
        $this->expense(60, 'إيجار', '2026-09-01');
        $this->expense(70, 'إيجار', '2026-09-30');
        $this->expense(80, 'إيجار', '2026-10-01');

        $this->assertSame(130.0, $this->opex('2026-09-01', '2026-10-01'));
        $this->assertSame(40.0, $this->opex('2026-08-01', '2026-09-01'));
    }

    public function test_a_branch_reads_its_own_entries_from_the_ledger(): void
    {
        $this->expense(25, 'إيجار', '2026-09-05', $this->muscat);
        $this->expense(35, 'إيجار', '2026-09-05', $this->sohar);
        $this->payroll(200, '2026-09-30');

        $this->assertSame(25.0, $this->opex(branch: $this->muscat));
        $this->assertSame(260.0, $this->opex());
    }

    /**
     * وربحُ الفرع على ما كان: المباشرُ وحصّتُه من الموزَّع — لا قيودُ الدفتر.
     *
     * الدفترُ يحمل فرعًا واحدًا للقيد ولا حصصَ فيه، فقراءةُ الفرع منه تُسقط
     * حصصَ المصروف الموزَّع.
     */
    public function test_the_branch_profit_keeps_its_current_attribution(): void
    {
        $this->expense(25, 'إيجار', '2026-09-05', $this->muscat);
        // مصروفٌ موزَّع: قيدُه على النشاط، وحصّتُه في جدول التوزيع وحده
        $shared = $this->expense(100, 'كهرباء وماء', '2026-09-06');
        ExpenseBranchAllocation::create(['expense_id' => $shared->id, 'branch_id' => $this->muscat->id, 'amount' => 40]);
        ExpenseBranchAllocation::create(['expense_id' => $shared->id, 'branch_id' => $this->sohar->id, 'amount' => 60]);
        $this->payroll(200, '2026-09-30');

        $branch = Profitability::summary($this->shop->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'), $this->muscat->id);
        $whole = Profitability::summary($this->shop->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));

        $this->assertSame(65.0, $branch['expenses'], 'سقطت حصّةُ الفرع من الموزَّع');
        $this->assertSame(325.0, $whole['expenses']);
        $this->assertSame(25.0, $this->opex(branch: $this->muscat), 'الدفترُ لا حصصَ فيه');
    }

    /* ═══════════ رقمٌ واحد في كلّ مكان ═══════════ */

    public function test_the_dashboard_finance_and_reports_read_the_same_number(): void
    {
        $this->sale(400, '2026-09-10', 1000);
        $this->expense(100, 'إيجار', '2026-09-05');
        $this->payroll(200, '2026-09-15');
        $this->entry([['account' => 'depreciation', 'debit' => 50], ['account' => 'accumulated_depreciation', 'credit' => 50]], '2026-09-18', 'إهلاك');
        // وما خارج الشهر لا يدخل
        $this->expense(999, 'إيجار', '2026-08-20');

        $this->actingAs($this->owner);
        $expected = 350.0;

        $this->assertSame($expected, Ledger::operatingExpenses($this->shop->id, now()->startOfMonth()));

        // التقارير
        $report = Demo::reportSummary('month');
        $this->assertSame($expected, (float) $report['expenses']);
        $this->assertSame(round(1000 - 400 - $expected, 3), (float) $report['profit']);

        // صافي الربح
        $profit = Profitability::summary($this->shop->id, Demo::rangeStart('month'));
        $this->assertSame($expected, $profit['expenses']);
        $this->assertSame(600.0, $profit['gross_profit']);
        $this->assertSame(250.0, $profit['net_profit']);

        // وصفوفُ الزمن تساوي جملتَها
        $rows = Profitability::rows($this->shop->id, 'month');
        $this->assertSame($expected, round(array_sum(array_column($rows, 'expenses')), 3));

        // المالية
        $period = $this->get(route('admin.finance.summary', ['range' => 'month']))->assertOk()
            ->viewData('page')['props']['period'];
        $this->assertSame($expected, (float) $period['expenses']);
        $this->assertSame(250.0, (float) $period['profit']);

        // اللوحة
        $stats = collect(Demo::adminStats());
        $this->assertSame(Demo::money($expected), $stats->firstWhere('label', 'إجمالي المصروفات')['value']);
        $this->assertSame(Demo::money(250.0), $stats->firstWhere('label', 'صافي الأرباح')['value']);
    }

    /* ═══════════ تصديرُ المصروفات المسجّلة بشهرها ═══════════ */

    public function test_the_export_carries_every_row_of_its_month_and_nothing_else(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->expense(10, 'إيجار', sprintf('2026-09-%02d', $i));
        }
        $this->expense(7, 'صيانة', '2026-09-20', status: Expense::UNPAID);
        $this->expense(500, 'إيجار', '2026-10-02');
        $this->expense(300, 'إيجار', '2026-08-28');

        $res = $this->actingAs($this->owner)->get(route('admin.expenses.xlsx', ['month' => '2026-09']))->assertOk();
        $this->assertStringContainsString('expenses-2026-09.xlsx', (string) $res->headers->get('content-disposition'));

        $sheet = $this->sheet($res->streamedContent());
        $text = $this->text($sheet);

        $this->assertStringContainsString('متجري', $text);
        $this->assertStringContainsString('2026-09', $text);
        $this->assertStringNotContainsString('2026-10-02', $text);
        $this->assertStringNotContainsString('2026-08-28', $text);

        $dates = $this->dates($sheet);
        $this->assertCount(16, $dates, 'لم يُصدَّر الشهرُ كلُّه — صفحتُه وحدها');

        $this->assertSame(150.0, $this->total($sheet, 'إجمالي المصروفات المسجلة والمدفوعة'));
        $this->assertSame(7.0, $this->total($sheet, 'إجمالي غير المدفوع'));
        $this->assertSame(16.0, $this->total($sheet, 'عدد السجلات'));
    }

    public function test_all_months_exports_everything_under_its_own_name(): void
    {
        $this->expense(10, 'إيجار', '2026-08-28');
        $this->expense(20, 'إيجار', '2026-09-03');

        $res = $this->actingAs($this->owner)->get(route('admin.expenses.xlsx', ['month' => 'all']))->assertOk();
        $this->assertStringContainsString('expenses-all.xlsx', (string) $res->headers->get('content-disposition'));

        $sheet = $this->sheet($res->streamedContent());
        $this->assertCount(2, $this->dates($sheet));
        $this->assertSame(30.0, $this->total($sheet, 'إجمالي المصروفات المسجلة والمدفوعة'));
    }

    public function test_the_export_never_carries_another_business(): void
    {
        Expense::create(['business_id' => $this->other->id, 'type' => 'إيجار', 'description' => 'لهم', 'amount' => 444,
            'method' => 'نقدي', 'status' => Expense::PAID, 'spent_at' => '2026-09-04']);
        $this->expense(10, 'إيجار', '2026-09-04');

        $sheet = $this->sheet($this->actingAs($this->owner)->get(route('admin.expenses.xlsx', ['month' => '2026-09']))->streamedContent());

        $this->assertCount(1, $this->dates($sheet));
        $this->assertStringNotContainsString('444', $this->text($sheet));
    }

    /* ═══════════ أدواتُ الورقة ═══════════ */

    private function sheet(string $binary): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $binary);
        $book = IOFactory::load($path);
        @unlink($path);

        return $book->getActiveSheet();
    }

    private function text($sheet): string
    {
        return collect($sheet->toArray(null, false, false))->flatten()->filter()->implode(' | ');
    }

    /** @return list<string> */
    private function dates($sheet): array
    {
        return collect($sheet->toArray(null, false, false))
            ->map(fn ($row) => (string) ($row[0] ?? ''))
            ->filter(fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v))
            ->values()->all();
    }

    private function total($sheet, string $label): ?float
    {
        foreach ($sheet->toArray(null, false, false) as $row) {
            if (($row[2] ?? null) === $label) {
                return (float) $row[3];
            }
        }

        return null;
    }
}

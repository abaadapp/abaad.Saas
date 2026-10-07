<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الملخّصُ المالي يُري ما يتكوّن منه رقماه: «عليك الآن» ونتيجةُ المدة.
 *
 * ═══ ما كان ═══
 *
 * «عليك الآن» رقمٌ واحدٌ بلا تفصيل، وسنداتٌ وصلت ولم تُعتمد لا تُذكر في
 * الملخّص أصلًا. و«صافي الربح» يُطرح منه تكلفةُ البضاعة ولا تُرى: مبيعاتٌ
 * ومصروفاتٌ وربحٌ لا يساوي فرقَهما.
 *
 * ═══ ما يُحرس ═══
 *
 *   - `dues.invoices` = رصيدُ السندات المعتمَدة وحدها (`total − paid`)،
 *     والمسدَّدُ كاملًا والمعلَّقُ والمرفوضُ والملغى خارجه.
 *   - والمعلَّقُ وحده في `pending_invoices` — لا المرفوضُ ولا الملغى.
 *   - `dues.total` = الفواتير + المصروفات غير المدفوعة + الرواتب المستحقة.
 *   - `period.cogs` من `Demo::reportSummary` كما حسبها، و`gross_profit`
 *     = المبيعات − الضريبة − التكلفة، و`profit` هو رقمُ `reportSummary`
 *     نفسُه = مجمل الربح − المصروفات التشغيلية.
 *   - وسندُ شراء مخزونٍ معتمَدٌ لا يدخل المصروفات التشغيلية.
 */
class TheFinanceSummaryShowsWhatMakesItsNumbersTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد الورد']);

        $this->actingAs($this->owner);
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** @return array<string, mixed> */
    private function summary(string $range = 'month'): array
    {
        return $this->get(route('admin.finance.summary', ['range' => $range]))->assertOk()->viewData('page')['props'];
    }

    private function invoice(string $ref, float $total, string $approval, float $paid = 0, ?int $businessId = null): SupplierInvoice
    {
        $bid = $businessId ?? $this->business->id;
        $supplier = $businessId === null
            ? $this->supplier
            : Supplier::create(['business_id' => $bid, 'name' => 'مورّد الجار']);

        return SupplierInvoice::create([
            'business_id' => $bid, 'supplier_id' => $supplier->id, 'supplier_ref' => $ref,
            'issued_at' => now()->toDateString(),
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'paid' => $paid,
            'approval_status' => $approval,
        ]);
    }

    private function unpaidExpense(float $amount): void
    {
        Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-'.$amount, 'type' => 'إيجار',
            'description' => 'إيجار', 'amount' => $amount, 'method' => 'نقدي',
            'status' => Expense::UNPAID, 'spent_at' => now()->toDateString(),
        ]);
    }

    private function salaryDue(float $net): void
    {
        $run = PayrollRun::create([
            'business_id' => $this->business->id, 'number' => 'PR-1', 'period' => now()->startOfMonth()->toDateString(),
            'status' => 'معتمدة', 'gross' => $net, 'deductions' => 0, 'net' => $net,
        ]);

        PayrollLine::create([
            'payroll_run_id' => $run->id, 'user_id' => $this->owner->id,
            'employee_name' => 'المالك', 'basic' => $net, 'allowances' => 0,
            'overtime' => 0, 'deductions' => 0, 'net' => $net, 'paid' => false,
        ]);
    }

    /**
     * باع بألفٍ (ضريبتُها خمسون) ما كلّفه ستّمئة، وأنفق مئتين.
     *
     *   مجمل الربح = ١٠٠٠ − ٥٠ − ٦٠٠ = ٣٥٠
     *   صافي الربح = ٣٥٠ − ٢٠٠ = ١٥٠
     */
    private function soldAtAProfit(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'sku' => 'B-1',
            'price' => 1000, 'cost' => 600, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);

        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-P1', 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => 950, 'tax' => 50, 'total' => 1000,
            'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة',
            'quantity' => 1, 'price' => 950, 'cost' => 600, 'total' => 950,
        ]);

        Books::recordExpense(Expense::create([
            'business_id' => $this->business->id, 'type' => 'إيجار', 'description' => 'إيجار',
            'amount' => 200, 'method' => 'نقدي', 'status' => Expense::PAID,
            'spent_at' => now(), 'employee_name' => 'المالك',
        ]));
    }

    /* ═══════════════ ١ · سنداتُ الموردين في «عليك الآن» ═══════════════ */

    public function test_an_approved_unpaid_invoice_is_owed_in_full(): void
    {
        $this->invoice('A-1', 300, SupplierInvoices::APPROVED);

        $dues = $this->summary()['dues'];

        $this->assertSame(300.0, $dues['invoices']);
        $this->assertSame(300.0, $dues['total']);
    }

    public function test_a_partly_paid_invoice_owes_only_what_is_left(): void
    {
        $this->invoice('A-2', 300, SupplierInvoices::APPROVED, paid: 120);

        $this->assertSame(180.0, $this->summary()['dues']['invoices'], 'الرصيدُ المتبقّي total − paid لا الإجمالي');
    }

    public function test_a_fully_paid_invoice_is_not_owed(): void
    {
        $this->invoice('A-3', 300, SupplierInvoices::APPROVED, paid: 300);

        $props = $this->summary();

        $this->assertSame(0.0, $props['dues']['invoices']);
        $this->assertSame(0.0, $props['dues']['total']);
    }

    public function test_an_invoice_awaiting_approval_is_not_owed_but_is_named_beside_it(): void
    {
        $this->invoice('P-1', 400, SupplierInvoices::PENDING);
        $this->invoice('P-2', 220, SupplierInvoices::PENDING);

        $props = $this->summary();

        $this->assertSame(0.0, $props['dues']['invoices']);
        $this->assertSame(0.0, $props['dues']['total'], 'لم يصر دَينًا بعد');
        $this->assertSame(['count' => 2, 'total' => 620.0], $props['pending_invoices']);
    }

    public function test_rejected_and_cancelled_invoices_are_in_neither(): void
    {
        $this->invoice('R-1', 500, SupplierInvoices::REJECTED);
        $this->invoice('C-1', 700, SupplierInvoices::CANCELLED);

        $props = $this->summary();

        $this->assertSame(0.0, $props['dues']['total']);
        $this->assertSame(['count' => 0, 'total' => 0.0], $props['pending_invoices']);
    }

    public function test_a_neighbours_waiting_invoice_is_not_this_shops(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->invoice('N-1', 999, SupplierInvoices::PENDING, businessId: $other->id);

        $this->assertSame(['count' => 0, 'total' => 0.0], $this->summary()['pending_invoices']);
    }

    /* ═══════════════ ٢ · الإجماليُّ مجموعُ أجزائه ═══════════════ */

    public function test_the_total_owed_is_its_three_parts_and_nothing_else(): void
    {
        $this->invoice('A-1', 300, SupplierInvoices::APPROVED, paid: 50);   // ٢٥٠
        $this->invoice('P-1', 400, SupplierInvoices::PENDING);              // خارجه
        $this->invoice('R-1', 500, SupplierInvoices::REJECTED);             // خارجه
        $this->unpaidExpense(120);
        $this->salaryDue(80);

        $dues = $this->summary()['dues'];

        $this->assertSame(250.0, $dues['invoices']);
        $this->assertSame(120.0, $dues['expenses']);
        $this->assertSame(80.0, $dues['payroll']);
        $this->assertSame(450.0, $dues['total']);
        $this->assertSame(round($dues['invoices'] + $dues['expenses'] + $dues['payroll'], 3), $dues['total']);
    }

    public function test_the_summary_and_the_dues_screen_still_say_the_same_number(): void
    {
        $this->invoice('A-1', 300, SupplierInvoices::APPROVED, paid: 50);
        $this->invoice('P-1', 400, SupplierInvoices::PENDING);
        $this->unpaidExpense(120);

        $dues = $this->get(route('admin.finance.dues'))->assertOk()->viewData('page')['props']['totals'];

        $this->assertSame($dues, $this->summary()['dues'], 'المعلَّقُ معلومةٌ في الملخّص لا جزءٌ من مجموع المستحقّات');
    }

    /* ═══════════════ ٣ · نتيجةُ المدة خطوةً خطوة ═══════════════ */

    public function test_the_cost_of_goods_and_the_gross_profit_reach_the_summary(): void
    {
        $this->soldAtAProfit();

        $period = $this->summary()['period'];

        $this->assertSame(1000.0, $period['sales']);
        $this->assertSame(50.0, $period['tax']);
        $this->assertSame(600.0, $period['cogs']);
        $this->assertSame(350.0, $period['gross_profit']);
        $this->assertSame(200.0, $period['expenses']);
        $this->assertSame(150.0, $period['profit']);
    }

    public function test_gross_profit_is_sales_less_tax_less_cost_and_net_is_gross_less_expenses(): void
    {
        $this->soldAtAProfit();

        $p = $this->summary()['period'];

        $this->assertSame(round($p['sales'] - $p['tax'] - $p['cogs'], 3), $p['gross_profit']);
        $this->assertSame(round($p['gross_profit'] - $p['expenses'], 3), $p['profit']);
    }

    public function test_net_profit_is_the_report_summarys_own_number(): void
    {
        $this->soldAtAProfit();

        $report = Demo::reportSummary('month');
        $period = $this->summary()['period'];

        $this->assertSame(round((float) $report['profit'], 3), $period['profit'], 'لا يُعاد حسابُه بمعادلةٍ أخرى');
        $this->assertSame(round((float) $report['cogs'], 3), $period['cogs']);
    }

    public function test_an_approved_stock_invoice_is_not_an_operating_expense(): void
    {
        $this->soldAtAProfit();
        $before = $this->summary()['period'];

        $invoice = $this->invoice('S-1', 500, SupplierInvoices::PENDING);
        SupplierInvoices::approve($invoice, $this->owner);

        $after = $this->summary();

        $this->assertSame($before['expenses'], $after['period']['expenses'], 'كلفتُه تصل الربحَ عبر تكلفة البضاعة — لا مرّةً ثانية');
        $this->assertSame($before['cogs'], $after['period']['cogs']);
        $this->assertSame($before['profit'], $after['period']['profit']);
        $this->assertSame(500.0, $after['dues']['invoices'], 'وصار دَينًا بالاعتماد');
    }

    /* ═══════════════ ٤ · «عليك الآن»: متى ═══════════════ */

    private function expenseDue(float $amount, ?string $due): void
    {
        Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-D-'.uniqid(), 'type' => 'إيجار',
            'description' => 'إيجار', 'amount' => $amount, 'method' => 'نقدي',
            'status' => Expense::UNPAID, 'spent_at' => now()->toDateString(), 'due_date' => $due,
        ]);
    }

    private function invoiceDue(string $ref, float $total, string $approval, ?string $due, float $paid = 0): void
    {
        $this->invoice($ref, $total, $approval, $paid)->update(['due_at' => $due]);
    }

    /**
     * المتأخّرُ وما يستحقّ خلال سبعة أيام — مبلغًا، من المصروفات والسندات المعتمدة.
     *
     *   متأخّر:  مصروف ١٠٠ (أمس) + سند ٢٠٠ مدفوعٌ منه ٥٠ (قبل ثلاثة أيام) = ٢٥٠
     *   قريب:    مصروف ٤٠ (اليوم) + مصروف ٣٠ (بعد ٧) + سند ٧٠ (بعد يومين) = ١٤٠
     *   خارجهما: مصروف ٢٠ (بعد ٨)، ومصروف ١٥ بلا موعد، والرواتب (لا موعدَ لها)،
     *            وسنداتٌ معلّقةٌ ومرفوضةٌ وملغاة — ولو فات موعدُها
     */
    public function test_the_overdue_and_the_due_within_a_week_are_told_in_money(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $day = fn (int $d) => now()->addDays($d)->toDateString();

        $this->expenseDue(100, $day(-1));
        $this->expenseDue(40, $day(0));
        $this->expenseDue(30, $day(7));
        $this->expenseDue(20, $day(8));
        $this->expenseDue(15, null);
        $this->invoiceDue('A-1', 200, SupplierInvoices::APPROVED, $day(-3), paid: 50);
        $this->invoiceDue('A-2', 70, SupplierInvoices::APPROVED, $day(2));
        $this->invoiceDue('P-1', 999, SupplierInvoices::PENDING, $day(-1));
        $this->invoiceDue('R-1', 888, SupplierInvoices::REJECTED, $day(-1));
        $this->invoiceDue('C-1', 777, SupplierInvoices::CANCELLED, $day(1));
        $this->salaryDue(80);

        $dues = $this->summary()['dues'];

        $this->assertSame(250.0, $dues['overdue_amount']);
        $this->assertSame(2, $dues['overdue_count']);
        $this->assertSame($dues['overdue_count'], $dues['overdue'], 'العددُ باسمه القديم كما هو');
        $this->assertSame(140.0, $dues['due_soon_amount'], 'المتأخّرُ لا يُعدّ قريبًا، ولا ما بعد السابع');

        // والتعريفُ نفسُه لـ«عليك الآن»: الأقسامُ الثلاثة، والمعلّقُ خارجها
        $this->assertSame(205.0, $dues['expenses']);
        $this->assertSame(220.0, $dues['invoices']);
        $this->assertSame(80.0, $dues['payroll']);
        $this->assertSame(505.0, $dues['total']);
    }

    /* ═══════════════ ٥ · «لك الآن»: ممّ يتكوّن ═══════════════ */

    /**
     * فاتورةٌ صادرة بـ٣٠٠ فات موعدُها، وبيعةٌ آجلة بـ١٢٠ لم تُفوتَر، ودفعةٌ
     * مقدّمة بـ٥٠ لم تُخصَّص.
     *
     *   total    = ٣٠٠ + ١٢٠ = ٤٢٠   (قبل الرصيد الدائن — كما يحسبه Receivables)
     *   invoiced = ٤٢٠ − ١٢٠ = ٣٠٠
     *   net      = ٤٢٠ − ٥٠  = ٣٧٠
     */
    public function test_customer_invoices_uninvoiced_sales_and_credit_are_each_named(): void
    {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الورد', 'phone' => '96890000001']);

        CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id, 'number' => 'CI-1',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->subDays(40)->toDateString(),
            'due_at' => now()->subDays(10)->toDateString(), 'subtotal' => 300, 'total' => 300,
        ]);
        Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'customer_name' => 'شركة الورد', 'number' => 'INV-CR-1', 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'آجل', 'payment_status' => 'غير مدفوع',
            'subtotal' => 120, 'total' => 120, 'ordered_at' => now(),
        ]);
        CustomerPayment::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'number' => 'RC-1', 'amount' => 50, 'method' => 'نقدي', 'occurred_at' => now()->toDateString(),
        ]);

        $r = $this->summary()['receivables'];

        $this->assertSame(420.0, $r['total'], 'الإجماليُّ قبل الرصيد الدائن — لم يتغيّر حسابُه');
        $this->assertSame(120.0, $r['uninvoiced']);
        $this->assertSame(300.0, $r['invoiced'], 'فواتير العملاء وحدها');
        $this->assertSame(50.0, $r['credit']);
        $this->assertSame(370.0, $r['net'], 'الصافي بعد الرصيد الدائن');
        $this->assertSame(300.0, $r['overdue']);
        $this->assertSame(1, $r['invoices']);
        $this->assertSame(1, $r['customers']);
    }
}

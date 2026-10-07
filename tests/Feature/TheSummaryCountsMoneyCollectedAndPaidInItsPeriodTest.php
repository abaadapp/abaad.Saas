<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Cheques;
use App\Support\Ledger;
use App\Support\Receivables;
use App\Support\Settlements;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «التحصيل والسداد في المدة» — مالٌ قُبض من العملاء أو دُفع للموردين فعلًا.
 *
 * ═══ ما يُحرس ═══
 *
 *   - التحصيلُ مجموعُ التحصيلات المسجّلة بتاريخ قبضها — لا إجماليُّ الفاتورة
 *     ولا حالُها. والجزئيّ يُعدّ في فترته، والملغى لا يُعدّ، والشيكُ لا يُعدّ
 *     حتّى يُصرف ثمّ يُعدّ بتاريخ صرفه، والمرتجعُ لا يُعدّ أبدًا.
 *   - والسدادُ مجموعُ قيود «سداد مورّد» بتاريخها — لا إجماليُّ السند ولا عمودُ
 *     «المدفوع» وحده. والجزئيّ يُعدّ في فترته.
 *   - والفترةُ فترةُ الملخّص نفسِها: اليوم والأسبوع والشهر والسنة والكلّ.
 *   - ولا يتبدّل شيءٌ ممّا فوقهما: المبيعاتُ والضريبةُ والتكلفةُ ومجملُ الربح
 *     والمصروفاتُ وصافي الربح وحركةُ المال، ولا رصيدا الذمم والموردين.
 *   - ومتجرٌ لا يرى مالَ متجرٍ آخر.
 *
 * والمتجرُ عامّ — لا معرّفَ مكتوبٌ يُفرض عليه.
 */
class TheSummaryCountsMoneyCollectedAndPaidInItsPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $north;

    private Branch $south;

    private User $owner;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // خميسٌ في منتصف الشهر — والأسبوعُ يبدأ الأحد (`Demo::WEEK_START`)
        Carbon::setTestNow('2026-10-15 10:00:00');

        [$this->business, $this->owner] = $this->shop('ورود مسقط', 'o@abaad.om');
        $this->north = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->south = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الورد', 'phone' => '96890000001']);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد الورد']);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $email): array
    {
        $business = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($business->id);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$business, $owner];
    }

    /** @return array<string, mixed> */
    private function summary(string $range = 'month'): array
    {
        return $this->get(route('admin.finance.summary', ['range' => $range]))->assertOk()->viewData('page')['props'];
    }

    /** @return array{amount: float, count: int} */
    private function collected(string $range = 'month'): array
    {
        return $this->summary($range)['settlements']['collections'];
    }

    /** @return array{amount: float, count: int} */
    private function paidOut(string $range = 'month'): array
    {
        return $this->summary($range)['settlements']['supplier_payments'];
    }

    private function customerInvoice(float $total, string $issuedAt, ?Branch $branch = null): CustomerInvoice
    {
        static $n = 0;
        $n++;

        return CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'branch_id' => ($branch ?? $this->north)->id, 'number' => 'CI-'.$n,
            'status' => CustomerInvoice::ISSUED, 'issued_at' => $issuedAt, 'due_at' => $issuedAt,
            'subtotal' => $total, 'total' => $total,
        ]);
    }

    /** تحصيلٌ من بابه الحقيقيّ — كما يسجّله التاجر */
    private function collect(float $amount, string $on, ?CustomerInvoice $invoice = null, string $method = 'نقدي', array $extra = []): CustomerPayment
    {
        $this->post(route('admin.customerPayments.store'), $extra + [
            'customer_id' => $this->customer->id, 'amount' => $amount, 'method' => $method,
            'occurred_at' => $on, 'customer_invoice_id' => $invoice?->id,
        ])->assertSessionHasNoErrors();

        return CustomerPayment::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /** سندُ مورّدٍ معتمَدٌ من بابه — يُرحَّل قيدُ شرائه */
    private function supplierInvoice(string $ref, float $total, string $issuedAt): SupplierInvoice
    {
        $invoice = SupplierInvoice::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'supplier_ref' => $ref,
            'issued_at' => $issuedAt, 'subtotal' => $total, 'tax' => 0, 'total' => $total, 'paid' => 0,
            'approval_status' => SupplierInvoices::PENDING,
        ]);

        $this->post(route('admin.purchases.invoices.approve', $invoice->id), ['override_reason' => 'سندٌ بلا أمر شراء'])
            ->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    /** سدادٌ من بابه الحقيقيّ */
    private function pay(SupplierInvoice $invoice, float $amount, string $on, string $from = 'cash'): void
    {
        $this->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => $amount, 'paid_at' => $on, 'from' => $from,
        ])->assertSessionHasNoErrors();
    }

    /** باع بألفٍ (ضريبتُها خمسون) ما كلّفه ستّمئة، وأنفق مئتين — اليوم */
    private function soldAtAProfit(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'sku' => 'B-1',
            'price' => 1000, 'cost' => 600, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->north->id,
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

    /* ═══════════════ التحصيل ═══════════════ */

    /** ١ و٣٣: فاتورةٌ بلا تحصيل — صفرٌ وعددٌ صفر */
    public function test_an_unpaid_invoice_collects_nothing(): void
    {
        $this->customerInvoice(1000, '2026-10-01');

        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->collected());
        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->paidOut());
    }

    /**
     * ٢ و٣ و١٣: ألفٌ سُدّد منها ثلاثمئة في سبتمبر وسبعمئة في أكتوبر — كلٌّ في شهره.
     *
     * والأيّامُ تمضي كما تمضي: يُقرأ سبتمبر في سبتمبر قبل أن يقع تحصيلُ أكتوبر.
     * فالفترةُ من بدايتها إلى اليوم — قاعدةُ الملخّص كلِّه (`Demo::rangeStart`).
     */
    public function test_partial_collections_land_in_the_period_they_were_received(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $invoice = $this->customerInvoice(1000, '2026-09-05');
        $this->collect(300, '2026-09-10', $invoice);

        $this->assertSame(['amount' => 300.0, 'count' => 1], $this->collected('month'), 'عُدّت الفاتورةُ كلُّها لا ما قُبض');

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->collect(700, '2026-10-12', $invoice);

        $this->assertSame(['amount' => 700.0, 'count' => 1], $this->collected('month'), 'دخل تحصيلُ سبتمبر في أكتوبر');
        $this->assertSame(1000.0, $this->collected('all')['amount']);
        $this->assertSame(1000.0, $this->collected('year')['amount']);
    }

    /** ٤: فاتورةٌ سُدّدت كلُّها بدفعاتٍ — مجموعُ الدفعات لا إجماليُّ الفاتورة مرّتين */
    public function test_a_fully_paid_invoice_sums_its_actual_payments(): void
    {
        $invoice = $this->customerInvoice(900, '2026-10-01');
        $this->collect(200, '2026-10-02', $invoice);
        $this->collect(300, '2026-10-05', $invoice);
        $this->collect(400, '2026-10-14', $invoice);

        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame(['amount' => 900.0, 'count' => 3], $this->collected());
    }

    /** ٨ و١٠ و١٢: يُقرأ تاريخُ القبض لا تاريخُ الفاتورة */
    public function test_the_payment_date_decides_not_the_invoice_date(): void
    {
        // فاتورةُ الشهر الماضي قُبضت هذا الشهر — تُعدّ
        $old = $this->customerInvoice(500, '2026-09-01');
        $this->collect(500, '2026-10-03', $old);

        // وفاتورةُ هذا الشهر قُبض منها في الماضي (مقدّمًا) — لا تُعدّ هذا الشهر
        $new = $this->customerInvoice(250, '2026-10-10');
        $this->collect(250, '2026-09-28', $new);

        $this->assertSame(['amount' => 500.0, 'count' => 1], $this->collected('month'));
    }

    /** ومالٌ قُبض على الحساب بلا فاتورة يُعدّ — قُبض فعلًا وبقي رصيدًا للعميل */
    public function test_money_received_on_account_counts(): void
    {
        $payment = $this->collect(120, '2026-10-15');

        $this->assertSame(120.0, $payment->unallocated());
        $this->assertSame(['amount' => 120.0, 'count' => 1], $this->collected('today'));
    }

    /** ١٤: التحصيلُ الملغى لا يُعدّ */
    public function test_a_cancelled_collection_is_not_counted(): void
    {
        $invoice = $this->customerInvoice(400, '2026-10-01');
        $kept = $this->collect(150, '2026-10-02', $invoice);
        $cancelled = $this->collect(250, '2026-10-03', $invoice);

        $this->post(route('admin.customerPayments.cancel', $cancelled->id), ['reason' => 'سُجّل خطأً'])->assertSessionHasNoErrors();

        $this->assertNotNull($cancelled->fresh()->cancelled_at);
        $this->assertSame(['amount' => 150.0, 'count' => 1], $this->collected());
        $this->assertNull($kept->fresh()->cancelled_at);
    }

    /** ١٥: والشيكُ ليس مالًا حتّى يُصرف — ثمّ يُعدّ بتاريخ صرفه، والمرتجعُ لا يُعدّ */
    public function test_a_cheque_counts_when_it_clears_on_its_clearing_date(): void
    {
        $bank = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك مسقط',
            'account_name' => 'الحساب الرئيسي', 'opening_balance' => 0,
        ]);
        $invoice = $this->customerInvoice(1000, '2026-09-01');

        $cheque = $this->collect(600, '2026-09-25', $invoice, Cheques::METHOD, ['bank_account_id' => $bank->id, 'cheque_due_at' => '2026-10-10']);
        $bounced = $this->collect(400, '2026-10-01', $invoice, Cheques::METHOD, ['bank_account_id' => $bank->id]);

        // تحت التحصيل: لا مال بعد
        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->collected('all'));

        $this->post(route('admin.finance.cheques.clear', $cheque->id), ['cleared_on' => '2026-10-11', 'bank_account_id' => $bank->id])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.finance.cheques.bounce', $bounced->id), ['reason' => 'رصيدٌ غير كافٍ'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Cheques::CLEARED, $cheque->fresh()->cheque_status);
        $this->assertSame(Cheques::BOUNCED, $bounced->fresh()->cheque_status);

        // صُرف في أكتوبر وإن قُبض في سبتمبر — يُعدّ في أكتوبر، والمرتجعُ لا
        $this->assertSame(['amount' => 600.0, 'count' => 1], $this->collected('month'));
        $this->assertSame(['amount' => 600.0, 'count' => 1], $this->collected('all'));
        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->collected('today'));
    }

    /** والإشعارُ الدائن يُنقص الذمّةَ ولا يُعدّ تحصيلًا ولا يُنقصه */
    public function test_a_credit_note_is_not_a_collection(): void
    {
        $invoice = $this->customerInvoice(500, '2026-10-01');
        $this->collect(200, '2026-10-02', $invoice);

        $this->post(route('admin.customerInvoices.creditNote', $invoice->id), ['amount' => 100, 'reason' => 'خصمٌ بعد البيع']);

        $this->assertSame(['amount' => 200.0, 'count' => 1], $this->collected());
    }

    /* ═══════════════ السداد ═══════════════ */

    /** ٥: سندٌ معتمَدٌ لم يُسدَّد منه شيء — صفر */
    public function test_an_unpaid_supplier_invoice_pays_nothing(): void
    {
        $this->supplierInvoice('S-1', 1000, '2026-10-01');

        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->paidOut());
    }

    /** ٦ و٧ و١٣: ألفٌ سُدّد منها أربعمئة الشهرَ الماضي وستّمئة هذا الشهر — كلٌّ في شهره */
    public function test_partial_supplier_payments_land_in_their_own_period(): void
    {
        Carbon::setTestNow('2026-09-30 18:00:00');
        $invoice = $this->supplierInvoice('S-2', 1000, '2026-09-01');
        $this->pay($invoice, 400, '2026-09-15');

        $this->assertSame(['amount' => 400.0, 'count' => 1], $this->paidOut('month'), 'عُدّ السندُ كلُّه لا ما دُفع');

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->pay($invoice, 600, '2026-10-08', 'bank');

        $this->assertSame(1000.0, (float) $invoice->fresh()->paid);
        $this->assertSame(['amount' => 600.0, 'count' => 1], $this->paidOut('month'), 'عُدّ عمودُ «المدفوع» كلُّه لا ما دُفع في الشهر');
        $this->assertSame(['amount' => 1000.0, 'count' => 2], $this->paidOut('all'));
    }

    /** ٩ و١١: يُقرأ تاريخُ السداد لا تاريخُ السند */
    public function test_the_supplier_payment_date_decides_not_the_invoice_date(): void
    {
        $old = $this->supplierInvoice('S-3', 300, '2026-08-01');
        $this->pay($old, 300, '2026-10-01');

        $new = $this->supplierInvoice('S-4', 200, '2026-10-02');
        $this->pay($new, 200, '2026-09-30');

        $this->assertSame(['amount' => 300.0, 'count' => 1], $this->paidOut('month'));
        $this->assertSame(['amount' => 500.0, 'count' => 2], $this->paidOut('year'));
    }

    /** ١٤: قيدُ سدادٍ معكوسٌ لا يُعدّ، ولا قيدُ عكسه */
    public function test_a_reversed_supplier_payment_is_not_counted(): void
    {
        $invoice = $this->supplierInvoice('S-5', 500, '2026-10-01');
        $this->pay($invoice, 200, '2026-10-02');
        $this->pay($invoice, 100, '2026-10-03');

        $entry = JournalEntry::where('business_id', $this->business->id)
            ->where('source', Settlements::SUPPLIER_SOURCE)->orderBy('id')->firstOrFail();
        Ledger::reverse($entry, now(), $this->owner->id, 'اختبار');

        $this->assertSame(['amount' => 100.0, 'count' => 1], $this->paidOut());
    }

    /* ═══════════════ الفترات ═══════════════ */

    /** ٣٤ إلى ٣٨: اليوم والأسبوع والشهر والسنة والكلّ — كلٌّ يعدّ ما في فترته */
    public function test_every_range_counts_its_own_window(): void
    {
        $invoice = $this->customerInvoice(5000, '2025-01-01');
        $bill = $this->supplierInvoice('S-6', 5000, '2025-01-01');

        $days = [
            '2026-10-15' => 1,    // اليوم (الخميس)
            '2026-10-11' => 10,   // الأحد — أوّلُ الأسبوع
            '2026-10-10' => 100,  // السبت — قبل الأسبوع، في الشهر
            '2026-03-01' => 1000, // في السنة
            '2025-12-31' => 2000, // قبل السنة
        ];

        foreach ($days as $day => $amount) {
            $this->collect($amount, $day, $invoice);
            $this->pay($bill, $amount, $day);
        }

        $expect = ['today' => [1, 1], 'week' => [11, 2], 'month' => [111, 3], 'year' => [1111, 4], 'all' => [3111, 5]];

        foreach ($expect as $range => [$amount, $count]) {
            $this->assertSame(['amount' => (float) $amount, 'count' => $count], $this->collected($range), "تحصيلُ «{$range}»");
            $this->assertSame(['amount' => (float) $amount, 'count' => $count], $this->paidOut($range), "سدادُ «{$range}»");
        }
    }

    /* ═══════════════ لا يمسّ ما فوقه ═══════════════ */

    /** ١٩ إلى ٢٦ و٢٩: الربحُ وما يتكوّن منه وحركةُ المال — كما كانت قبل التحصيل والسداد */
    public function test_collecting_and_paying_change_no_profit_figure_or_money_movement(): void
    {
        $this->soldAtAProfit();
        $invoice = $this->customerInvoice(1000, '2026-10-01');
        $bill = $this->supplierInvoice('S-7', 800, '2026-10-01');

        $before = $this->summary()['period'];

        $this->collect(300, '2026-10-05', $invoice);
        $this->collect(700, '2026-10-06', $invoice);
        $this->pay($bill, 500, '2026-10-07');
        $this->pay($bill, 300, '2026-10-08', 'bank');

        $after = $this->summary();

        $this->assertSame($before, $after['period'], 'تبدّل رقمٌ من نتيجة المدة أو حركة المال بتحصيلٍ أو سداد');
        $this->assertSame(1000.0, $after['period']['sales']);
        $this->assertSame(50.0, $after['period']['tax']);
        $this->assertSame(600.0, $after['period']['cogs'], 'سدادُ المورّد غيّر تكلفةَ البضاعة');
        $this->assertSame(350.0, $after['period']['gross_profit']);
        $this->assertSame(200.0, $after['period']['expenses'], 'سدادُ المورّد صار مصروفًا تشغيليًّا');
        $this->assertSame(150.0, $after['period']['profit']);

        $this->assertSame(['amount' => 1000.0, 'count' => 2], $after['settlements']['collections']);
        $this->assertSame(['amount' => 800.0, 'count' => 2], $after['settlements']['supplier_payments']);

        // ولا مصروفَ ولا حركةَ ماليّة تولد من السداد
        $this->assertSame(1, Expense::where('business_id', $this->business->id)->count());
    }

    /** ٢٧ و٢٨: رصيدا الذمم والموردين كما يحسبهما بابُهما — لا يمسّهما العرض */
    public function test_receivable_and_payable_balances_stay_right(): void
    {
        $invoice = $this->customerInvoice(1000, '2026-10-01');
        $bill = $this->supplierInvoice('S-8', 800, '2026-10-01');

        $this->collect(300, '2026-10-05', $invoice);
        $this->pay($bill, 500, '2026-10-07');

        $this->summary();

        $this->assertSame(700.0, $invoice->fresh()->outstanding());
        $this->assertSame(700.0, (float) Receivables::totals($this->business->id)['total']);
        $this->assertSame(300.0, $bill->fresh()->outstanding());
        $this->assertSame(300.0, round(Ledger::account($this->business->id, 'payable')->balance(), 3));
        $this->assertSame(300.0, $this->summary()['dues']['invoices']);
    }

    /* ═══════════════ العزل ═══════════════ */

    /** ١٦: مالُ متجرٍ آخر لا يُعدّ — ولو كانت أرقامُه أقرب */
    public function test_another_shops_money_never_counts(): void
    {
        $invoice = $this->customerInvoice(100, '2026-10-01');
        $this->collect(100, '2026-10-02', $invoice);

        [$other, $otherOwner] = $this->shop('متجر الجار', 'n@abaad.om');
        $theirCustomer = Customer::create(['business_id' => $other->id, 'name' => 'عميل الجار', 'phone' => '96890000009']);
        $theirInvoice = CustomerInvoice::create([
            'business_id' => $other->id, 'customer_id' => $theirCustomer->id, 'number' => 'CI-X',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => '2026-10-01', 'subtotal' => 9000, 'total' => 9000,
        ]);
        $theirBill = SupplierInvoice::create([
            'business_id' => $other->id, 'supplier_id' => Supplier::create(['business_id' => $other->id, 'name' => 'مورّد الجار'])->id,
            'supplier_ref' => 'X-1', 'issued_at' => '2026-10-01', 'subtotal' => 9000, 'tax' => 0, 'total' => 9000, 'paid' => 0,
            'approval_status' => SupplierInvoices::PENDING,
        ]);

        $this->actingAs($otherOwner);
        $this->post(route('admin.customerPayments.store'), [
            'customer_id' => $theirCustomer->id, 'amount' => 9000, 'method' => 'نقدي', 'occurred_at' => '2026-10-02', 'customer_invoice_id' => $theirInvoice->id,
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.purchases.invoices.approve', $theirBill->id), ['override_reason' => 'سندٌ بلا أمر شراء'])->assertSessionHasNoErrors();
        $this->post(route('admin.purchases.invoices.pay', $theirBill->id), ['amount' => 9000, 'paid_at' => '2026-10-02', 'from' => 'cash'])->assertSessionHasNoErrors();

        $this->actingAs($this->owner);
        $this->assertSame(['amount' => 100.0, 'count' => 1], $this->collected());
        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->paidOut());

        // ومعرّفُ المتجر في الرابط لا يُبدّل شيئًا — المتجرُ من الجلسة
        $this->assertSame(100.0, $this->get(route('admin.finance.summary', ['range' => 'month', 'business_id' => $other->id]))
            ->viewData('page')['props']['settlements']['collections']['amount']);

        $this->actingAs($otherOwner);
        $this->assertSame(['amount' => 9000.0, 'count' => 1], $this->collected());
        $this->assertSame(['amount' => 9000.0, 'count' => 1], $this->paidOut());
    }

    /**
     * ١٧ و١٨: والملخّصُ للمتجر كلِّه — بلا مبدّل فرع، كسائر أرقامه.
     *
     * والتحصيلُ لا عمودَ فرعٍ له، وقيدُ السداد بلا فرع: فلا تُخترع نسبةٌ إلى
     * فرع. وتحصيلٌ على فاتورة فرعٍ وآخرُ على فاتورة فرعٍ آخر يُعدّان معًا.
     */
    public function test_the_summary_counts_every_branch_of_the_shop_as_its_other_figures_do(): void
    {
        $this->collect(100, '2026-10-02', $this->customerInvoice(100, '2026-10-01', $this->north));
        $this->collect(40, '2026-10-03', $this->customerInvoice(40, '2026-10-01', $this->south));

        $this->assertSame(['amount' => 140.0, 'count' => 2], $this->collected());

        // ورابطٌ يحمل فرعًا لا يُبدّل الملخّص — لا يقبل الفرعَ أصلًا
        $this->assertSame(140.0, $this->get(route('admin.finance.summary', ['range' => 'month', 'branch_id' => $this->south->id]))
            ->viewData('page')['props']['settlements']['collections']['amount']);
    }

    /** ومن لا يملك المالية لا يصله الملخّص ولا أرقامُه */
    public function test_a_user_without_finance_cannot_open_the_figures(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $response = $this->actingAs($cashier)->get(route('admin.finance.summary'));

        $this->assertNotSame(200, $response->getStatusCode(), 'كاشيرٌ فتح الملخّص المالي');
        $this->assertStringNotContainsString('settlements', (string) $response->getContent());
    }

    /** والقراءةُ لا تكتب شيئًا — لا قيدَ ولا حركةَ ولا تحصيل */
    public function test_reading_the_summary_writes_nothing(): void
    {
        $invoice = $this->customerInvoice(100, '2026-10-01');
        $this->collect(100, '2026-10-02', $invoice);

        $counts = fn () => [
            JournalEntry::count(), Transaction::count(),
            CustomerPayment::count(), Expense::count(),
        ];
        $before = $counts();

        foreach (['today', 'week', 'month', 'year', 'all'] as $range) {
            $this->summary($range);
        }

        $this->assertSame($before, $counts());
    }
}

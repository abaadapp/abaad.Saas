<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Bank;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كشفُ البنك يُقرأ من دفتر الأستاذ — فيطابقه بالبناء.
 *
 * كان يُقرأ من جدول الحركات (`transactions`)، فوجد تدقيقُ ماليّة RIBBON
 * (2026-10-24) الكشفَ ٢٬٤٢٤٫٠٨٠ والدفترَ ١٬٨٧١٫٤٤٨:
 *
 * - تحصيلُ فاتورة العميل بالتحويل يدخل البنكَ في الدفتر ولا يكتب صفَّ حركة؛
 * - التحويلُ من الصندوق إلى البنك يُقرأ سحبًا لأنّ نوعَه ليس «دخل»؛
 * - ومصروفٌ بـ«تحويل بنكي» يظهر خارجًا من البنك والدفترُ رحّله من الصندوق؛
 * - وما قبل الرصيد الافتتاحيّ يغيب بلا كلمة.
 *
 * وكلُّ بنكٍ يُقرأ من ورقته، ولا يرى متجرٌ شيئًا من دفتر جاره.
 */
class TheBankStatementIsTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        app()->setLocale('ar');
        Carbon::setTestNow('2026-10-20 10:00:00');

        [$this->business, $this->owner] = $this->shop('متجر الكشف', 'statement@abaad.om');

        $this->company = Customer::create([
            'language' => 'ar', 'business_id' => $this->business->id, 'name' => 'شركة العميل',
            'customer_type' => 'شركة', 'allow_credit_sales' => true, 'payment_terms_days' => 30,
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 100, 'cost' => 0,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->actingAs($this->owner);
        $this->openBank('بنك مسقط', 1000, '2026-10-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $email): array
    {
        $business = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($business->id);
        Setting::create(['business_id' => $business->id, 'key' => 'vat_enabled', 'value' => '0']);

        return [$business, User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ])];
    }

    private function openBank(string $name, float $opening, string $on): BankAccount
    {
        $this->post(route('admin.finance.banks.store'), [
            'bank_name' => $name, 'opening_balance' => $opening, 'opening_date' => $on,
        ])->assertSessionHasNoErrors();

        return BankAccount::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /** رصيدُ ورقة الحساب في الدفتر منذ تاريخ رصيده الافتتاحي، شاملًا الافتتاحي */
    private function ledgerSinceOpening(BankAccount $account): float
    {
        $leaf = Bank::leaf($this->business->id, $account->id);

        return round((float) $leaf->lines()
            ->whereHas('entry', fn ($q) => $q->where('business_id', $this->business->id)
                ->where('posted', true)->whereDate('entry_date', '>=', $account->opening_date))
            ->sum(DB::raw('debit - credit')), 3);
    }

    private function invoicePaidBy(string $method, ?int $bankAccountId = null): void
    {
        $this->post(route('admin.customerInvoices.store'), array_filter([
            'customer_id' => $this->company->id, 'issued_at' => now()->toDateString(), 'issue' => true,
            'payment_method' => $method, 'bank_account_id' => $bankAccountId,
            'items' => [['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 100]],
        ]))->assertSessionHasNoErrors();
    }

    public function test_an_invoice_receipt_by_transfer_reaches_the_statement(): void
    {
        $this->invoicePaidBy('تحويل', Bank::current($this->business->id)->id);

        $statement = Demo::bankStatement();

        $this->assertSame(1100.0, $statement['closing']);
        $this->assertSame([100.0], array_column($statement['rows'], 'credit'));
        $this->assertSame(1100.0, round(Bank::leaf($this->business->id)->balance(), 3), 'الكشفُ والدفترُ رقمٌ واحد');
    }

    public function test_a_transfer_from_the_drawer_is_a_deposit_and_back_is_a_withdrawal(): void
    {
        $this->post(route('admin.finance.store'), ['kind' => 'cash_to_bank', 'amount' => 50])->assertSessionHasNoErrors();
        $this->post(route('admin.finance.store'), ['kind' => 'bank_to_cash', 'amount' => 20])->assertSessionHasNoErrors();

        $statement = Demo::bankStatement();

        $this->assertSame([[0.0, 50.0], [20.0, 0.0]], array_map(fn ($r) => [$r['debit'], $r['credit']], $statement['rows']));
        $this->assertSame(1030.0, $statement['closing']);
    }

    public function test_a_credit_sale_by_transfer_is_not_money_in_the_bank(): void
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 100]],
            'payment_method' => 'تحويل بنكي', 'credit' => true, 'customer_id' => $this->company->id, 'paid_now' => 0,
        ])->assertOk();

        $statement = Demo::bankStatement();

        $this->assertSame([], $statement['rows'], 'دخل الكشفَ بيعٌ آجلٌ لم يُقبض');
        $this->assertSame(1000.0, $statement['closing']);
    }

    /** وما دُفع منه الآن بالتحويل يدخل البنك — لا الدرج */
    public function test_a_credit_sales_down_payment_by_transfer_enters_the_bank(): void
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 100]],
            'payment_method' => 'تحويل بنكي', 'credit' => true, 'customer_id' => $this->company->id, 'paid_now' => 30,
        ])->assertOk();

        $this->assertSame('تحويل', CustomerPayment::where('business_id', $this->business->id)->value('method'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'), 'رُحّل التحويلُ إلى الدرج');
        $this->assertSame(1030.0, Demo::bankStatement()['closing']);
    }

    public function test_an_expense_paid_by_bank_transfer_leaves_the_bank_not_the_drawer(): void
    {
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'method' => 'تحويل بنكي', 'spent_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(700.0, round(Bank::leaf($this->business->id)->balance(), 3));
        $this->assertSame(700.0, Demo::bankStatement()['closing']);
    }

    public function test_the_closing_is_the_ledger_since_the_opening_and_the_opening_is_not_counted_twice(): void
    {
        $account = Bank::current($this->business->id);

        $this->invoicePaidBy('تحويل', $account->id);
        $this->post(route('admin.finance.store'), ['kind' => 'cash_to_bank', 'amount' => 50]);
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 30, 'method' => 'بطاقة', 'spent_at' => now()->toDateString(),
        ]);

        $statement = Demo::bankStatement();

        $this->assertSame(1000.0, $statement['opening']);
        $this->assertSame(1120.0, $statement['closing']);
        $this->assertSame($this->ledgerSinceOpening($account), $statement['closing']);
        $this->assertNotContains('رصيد افتتاحي', array_column($statement['rows'], 'source'), 'عُدّ الرصيدُ الافتتاحيّ مرّتين');
    }

    public function test_what_precedes_the_opening_date_is_told_not_counted(): void
    {
        Ledger::post($this->business->id, 'إيداعٌ قديم', [
            ['account' => Bank::leaf($this->business->id), 'debit' => 70],
            ['account' => 'sales', 'credit' => 70],
        ], Carbon::parse('2026-09-15'));

        $statement = Demo::bankStatement();

        $this->assertSame(1000.0, $statement['closing']);
        $this->assertSame(['count' => 1, 'net' => 70.0], $statement['before_opening']);
        $this->assertSame(1070.0, $statement['ledger_balance']);
    }

    public function test_a_second_account_reads_only_its_own_leaf(): void
    {
        $second = $this->openBank('بنك ظفار', 0, '2026-10-01');
        $this->invoicePaidBy('تحويل', $second->id);

        $this->assertSame([], Demo::bankStatement()['rows'], 'ظهر في الرئيسيّ مالُ الحساب الثاني');
        $this->assertSame(100.0, Demo::bankStatement($second->id)['closing']);
    }

    /** ولا يرى متجرٌ شيئًا من دفتر جاره — ولا يُقرأ حسابُ جاره ولو سُمّي */
    public function test_another_shops_bank_never_appears(): void
    {
        [$other] = $this->shop('الجار', 'neighbour@abaad.om');
        $theirs = BankAccount::create(['business_id' => $other->id, 'bank_name' => 'بنكهم', 'opening_balance' => 0, 'is_primary' => true]);
        Ledger::post($other->id, 'إيداعُ الجار', [
            ['account' => 'bank', 'debit' => 500],
            ['account' => 'sales', 'credit' => 500],
        ], now());

        $this->assertSame([], Demo::bankStatement()['rows']);
        $this->assertSame(1000.0, Demo::bankStatement()['closing']);
        $this->assertSame([], Bank::statement($this->business->id, $theirs)['rows'], 'قُرئ دفترُ الجار باسم حسابه');
        $this->assertSame(1000.0, Demo::bankStatement($theirs->id)['closing'], 'فُتح كشفُ حسابٍ ليس لهذا المتجر');
    }

    /** والمطابقةُ تقرأ التحويلَ من الصندوق إيداعًا — يُطابَق بإيداعه لا بسحب */
    public function test_reconciliation_matches_a_transfer_from_the_drawer_to_a_deposit(): void
    {
        $this->post(route('admin.finance.store'), ['kind' => 'cash_to_bank', 'amount' => 50])->assertSessionHasNoErrors();
        $line = BankStatementLine::create([
            'business_id' => $this->business->id, 'date' => now()->toDateString(),
            'description' => 'إيداع', 'amount' => 50,
        ]);

        $this->post(route('admin.bank.rematch'))->assertSessionHasNoErrors();

        $this->assertSame(BankStatementLine::MATCHED, $line->fresh()->match_status);
    }

    /** والتقريرُ الماليّ لا يعدّ التحويلَ دخلًا ولا صرفًا */
    public function test_the_finance_report_counts_a_transfer_as_neither_income_nor_outgo(): void
    {
        $this->post(route('admin.finance.store'), ['kind' => 'cash_to_bank', 'amount' => 50])->assertSessionHasNoErrors();

        $props = $this->get(route('admin.reports.finance'))->assertOk()->viewData('page')['props'];

        $this->assertSame(['income' => 0.0, 'outgo' => 0.0, 'net' => 0.0], array_intersect_key($props['summary'], array_flip(['income', 'outgo', 'net'])));
        $this->assertContains('تحويل', array_column($props['rows'], 'type'), 'والتحويلُ يبقى في الجدول');
    }

    /** وكلُّ ذلك بلا قيدٍ مكرّر: قيدٌ واحد لكلّ مستند */
    public function test_each_document_writes_one_entry(): void
    {
        $this->invoicePaidBy('تحويل', Bank::current($this->business->id)->id);
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 30, 'method' => 'تحويل بنكي', 'spent_at' => now()->toDateString(),
        ]);

        $payment = CustomerPayment::where('business_id', $this->business->id)->firstOrFail();
        $expense = Expense::where('business_id', $this->business->id)->firstOrFail();

        $this->assertSame(1, JournalEntry::where('sourceable_type', CustomerPayment::class)->where('sourceable_id', $payment->id)->count());
        $this->assertSame(1, JournalEntry::where('sourceable_type', Expense::class)->where('sourceable_id', $expense->id)->count());
    }
}

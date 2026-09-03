<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا حركةَ مالٍ بلا قيدٍ يقابلها — ولا قيدَ مرّتين عن حدثٍ واحد.
 *
 * كانت شاشة المالية تكتب صفًّا في `transactions` ولا تكتب شيئًا في
 * `journal_entries`: مالٌ خرج من الدرج في شاشةٍ ولا أثر له في ميزان المراجعة،
 * فيقرأ التاجر دفترًا ويقرأ محاسبُه دفترًا آخر ولا يتّفقان في ريال.
 *
 * وكانت تسأل «دخل أم مصروف؟» وحدهما: فالتحويل من الصندوق إلى البنك يُسجَّل
 * إمّا دخلًا يُضخّم المبيعات وإمّا مصروفًا يأكل الربح — وهو لا هذا ولا ذاك.
 *
 * وهذا الملفّ يحرس البابين معًا: أنّ كلّ حركةٍ تصل إلى الدفترين، وأنّ ما لا
 * يجوز أن يُقيَّد لا يُقيَّد.
 */
class FinanceMovementsTest extends TestCase
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

        \App\Models\JobTitle::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier',
        ]);

        Ledger::seedChart($this->business->id);
        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function balance(string $key): float
    {
        return Ledger::account($this->bid(), $key)?->balance() ?? 0.0;
    }

    private function record(array $data)
    {
        return $this->post(route('admin.finance.store'), $data);
    }

    /* --------------------------- دخل آخر --------------------------- */

    public function test_other_income_in_cash_lands_in_both_books(): void
    {
        $this->record(['kind' => 'other_income', 'amount' => 30, 'side' => 'cash', 'description' => 'تعويض تأمين'])
            ->assertSessionHasNoErrors();

        $transaction = Transaction::where('business_id', $this->bid())->firstOrFail();

        $this->assertSame('other_income', $transaction->kind);
        $this->assertSame('نقدي', $transaction->method);
        $this->assertNotNull($transaction->journal_entry_id, 'حركةٌ بلا قيد');

        $this->assertSame(30.0, $this->balance('cash'));
        $this->assertSame(30.0, $this->balance('other_income'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_other_income_to_the_bank_debits_the_bank_not_the_drawer(): void
    {
        $this->record(['kind' => 'other_income', 'amount' => 30, 'side' => 'bank'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->balance('cash'));
        $this->assertSame(30.0, $this->balance('bank'));
        $this->assertSame(30.0, $this->balance('other_income'));

        // الوسيلة تتبع الجهة، وإلا غابت الحركة عن مطابقة كشف البنك
        $this->assertSame('تحويل بنكي', Transaction::where('business_id', $this->bid())->value('method'));
    }

    public function test_other_income_is_not_read_as_a_sale(): void
    {
        /*
         * كانت المبيعات تُقرأ بـ`type = 'دخل'` — وهي خانةٌ يقع فيها تعويضُ
         * التأمين وإيداعُ المالك، فيُقرآن مبيعاتٍ في كل تقرير.
         */
        $this->record(['kind' => 'other_income', 'amount' => 30, 'side' => 'cash']);

        $stats = collect(Demo::financeStats('all'))->pluck('value', 'label');

        $this->assertSame(Demo::money(0), $stats->first(), 'قُرئ الدخل الآخر مبيعاتٍ');
    }

    /* ---------------------- مصروف من شاشة المالية ---------------------- */

    public function test_an_expense_from_the_finance_screen_reaches_the_ledger(): void
    {
        $this->record([
            'kind' => 'expense', 'amount' => 45, 'side' => 'cash', 'description' => 'كهرباء',
        ])->assertSessionHasNoErrors();

        $this->assertSame(-45.0, $this->balance('cash'));
        $this->assertSame(45.0, $this->balance('other_expenses'));

        // ويظهر في شاشة المصروفات أيضًا — لا في المالية وحدها
        $expense = Expense::where('business_id', $this->bid())->firstOrFail();
        $this->assertSame(45.0, (float) $expense->amount);
        $this->assertSame(Expense::PAID, $expense->status);
        $this->assertNotNull($expense->transaction_id);
        $this->assertSame(45.0, Demo::reportSummary('all')['expenses']);
    }

    public function test_an_expense_from_the_expenses_screen_reaches_the_ledger_too(): void
    {
        /*
         * كانت `postToLedger` تُسمّى ترحيلًا ولا ترحّل: صفٌّ في `transactions`
         * وحده. فمصروفُ ثلاثمئة لا أثر له في ميزان المراجعة.
         */
        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'method' => 'تحويل بنكي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('other_expenses'));
        $this->assertSame(-300.0, $this->balance('bank'), 'خرج المال من الصندوق وقد دُفع تحويلًا');
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_deleting_an_expense_takes_its_entry_out_of_the_ledger(): void
    {
        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 300]);
        $expense = Expense::where('business_id', $this->bid())->firstOrFail();

        $this->delete(route('admin.expenses.destroy', $expense->id));

        $this->assertSame(0.0, $this->balance('other_expenses'), 'بقي قيدٌ يتيم لمصروفٍ حُذف');
        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());

        // ويعود بالدفترين معًا لا بأحدهما
        $this->post(route('admin.expenses.restore', $expense->id));

        $this->assertSame(300.0, $this->balance('other_expenses'));
        $this->assertSame(1, JournalEntry::where('business_id', $this->bid())->count());
    }

    /* -------------------- سحب المالك وإيداعه -------------------- */

    public function test_an_owner_withdrawal_is_not_an_expense(): void
    {
        /*
         * خلطُه بالمصروف يجعل متجرًا رابحًا يقرأ نفسه خاسرًا كلّما أخذ صاحبه
         * مصروفه من الدرج: حقّه في المتجر ينقص، والربح لا يمسّه ذلك.
         */
        $this->record(['kind' => 'owner_withdrawal', 'amount' => 100, 'side' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(100.0, $this->balance('drawings'));
        $this->assertSame(-100.0, $this->balance('cash'));
        $this->assertSame(0.0, $this->balance('other_expenses'), 'قُرئ سحبُ المالك مصروفًا');
        $this->assertSame(0.0, Demo::reportSummary('all')['expenses']);
    }

    public function test_an_owner_deposit_is_capital_not_income(): void
    {
        $this->record(['kind' => 'owner_deposit', 'amount' => 500, 'side' => 'bank'])
            ->assertSessionHasNoErrors();

        $this->assertSame(500.0, $this->balance('capital'));
        $this->assertSame(500.0, $this->balance('bank'));
        $this->assertSame(0.0, $this->balance('other_income'));
        $this->assertSame(0.0, Demo::reportSummary('all')['sales'], 'قُرئ إيداع المالك مبيعاتٍ');
    }

    /* ------------------ التحويل بين الصندوق والبنك ------------------ */

    public function test_cash_to_bank_moves_the_money_and_nothing_else(): void
    {
        $this->record(['kind' => 'cash_to_bank', 'amount' => 200])->assertSessionHasNoErrors();

        $this->assertSame(-200.0, $this->balance('cash'));
        $this->assertSame(200.0, $this->balance('bank'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);

        $this->assertSame('تحويل', Transaction::where('business_id', $this->bid())->value('type'));
    }

    public function test_bank_to_cash_moves_it_the_other_way(): void
    {
        $this->record(['kind' => 'bank_to_cash', 'amount' => 200])->assertSessionHasNoErrors();

        $this->assertSame(200.0, $this->balance('cash'));
        $this->assertSame(-200.0, $this->balance('bank'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_a_transfer_touches_neither_profit_nor_revenue_nor_expenses(): void
    {
        $this->record(['kind' => 'cash_to_bank', 'amount' => 200]);
        $this->record(['kind' => 'bank_to_cash', 'amount' => 50]);

        $summary = Demo::reportSummary('all');

        $this->assertSame(0.0, $summary['sales'], 'قُرئ التحويل مبيعاتٍ');
        $this->assertSame(0.0, $summary['expenses'], 'قُرئ التحويل مصروفًا');
        $this->assertSame(0.0, $summary['profit']);
        $this->assertSame(0.0, $this->balance('other_income'));
        $this->assertSame(0.0, $this->balance('other_expenses'));
    }

    /* --------------------------- الحرّاس --------------------------- */

    public function test_a_zero_movement_is_refused(): void
    {
        // صفٌّ في الدفتر لا يغيّر رصيدًا ولا يُصحَّح — وقيدٌ بصفر لا يعني شيئًا
        $this->record(['kind' => 'other_income', 'amount' => 0, 'side' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::where('business_id', $this->bid())->count());
        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
    }

    public function test_a_zero_expense_is_refused_from_the_expenses_screen_too(): void
    {
        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 0])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::where('business_id', $this->bid())->count());
    }

    public function test_resending_the_same_movement_does_not_write_it_twice(): void
    {
        /*
         * ضغطتان على «حفظ»، أو إعادةُ إرسالٍ بعد انقطاع: المال لا يخرج
         * مرّتين لأنّ الشبكة تلعثمت.
         */
        $payload = ['kind' => 'expense', 'amount' => 45, 'side' => 'cash', 'client_uuid' => 'once-only'];

        $this->record($payload)->assertSessionHasNoErrors();
        $this->record($payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('business_id', $this->bid())->count());
        $this->assertSame(1, JournalEntry::where('business_id', $this->bid())->count());
        $this->assertSame(1, Expense::where('business_id', $this->bid())->count());
        $this->assertSame(-45.0, $this->balance('cash'), 'خرج المال مرّتين');
    }

    public function test_a_pos_sale_cannot_be_entered_again_by_hand(): void
    {
        /*
         * البيع تكتبه نقطة البيع لحظةَ وقوعه. وتسجيلُه هنا يدويًّا يعني بيعةً
         * مرّتين في كل تقرير — والحارس في الخادم لا في القائمة وحدها: الطلب
         * قد يصل من غير الشاشة.
         */
        $this->record(['kind' => 'sale', 'amount' => 50, 'side' => 'cash'])
            ->assertSessionHasErrors('kind');

        $this->assertSame(0, Transaction::where('business_id', $this->bid())->count());
    }

    public function test_a_pos_sale_writes_exactly_one_movement(): void
    {
        $product = Product::create([
            'business_id' => $this->bid(), 'name' => 'صنف', 'price' => 10,
            'cost' => 4, 'quantity' => 20, 'active' => true,
        ]);
        $this->openShiftFor($this->bid());

        $payload = [
            'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
            'client_uuid' => 'sale-once',
        ];

        $this->postJson(route('pos.checkout'), $payload)->assertOk();
        // الرفع الثاني بعد عودة الاتصال يعيد الفاتورة الأصلية ولا يبيع ثانيةً
        $this->postJson(route('pos.checkout'), $payload)->assertOk();

        $sales = Transaction::where('business_id', $this->bid())->where('kind', Transaction::SALE)->get();

        $this->assertCount(1, $sales, 'سُجّلت البيعة مرّتين');
        $this->assertSame(
            (float) \App\Models\Order::where('business_id', $this->bid())->value('total'),
            (float) $sales->first()->amount,
        );
    }

    /* ------------------------ عزل المتاجر ------------------------ */

    public function test_a_movement_never_lands_in_another_stores_books(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);

        $this->record(['kind' => 'other_income', 'amount' => 30, 'side' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('business_id', $other->id)->count());
        $this->assertSame(0, JournalEntry::where('business_id', $other->id)->count());
        $this->assertSame(0.0, Ledger::account($other->id, 'cash')->balance());
        $this->assertSame(30.0, $this->balance('cash'));
    }

    public function test_another_stores_movements_are_not_listed_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Transaction::create([
            'business_id' => $other->id, 'reference' => 'TRX-000900', 'description' => 'سرّ الجار',
            'method' => 'نقدي', 'type' => 'دخل', 'kind' => 'other_income', 'amount' => 900,
            'occurred_at' => now(),
        ]);

        $props = $this->get(route('admin.finance.transactions'))->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['rows']);
        $this->assertSame(0.0, $props['summary']['in']);
    }

    /* ------------------------- الصلاحيات ------------------------- */

    private function staff(array $permissions): User
    {
        return User::create([
            'business_id' => $this->bid(), 'name' => 'موظف',
            'email' => 'e'.uniqid().'@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    public function test_holding_finance_does_not_open_the_advanced_accounting_tools(): void
    {
        /*
         * من مُنح «المالية» ليسجّل مصروفًا كان يُمنح معها شجرةَ حسابات المتجر
         * وزرَّ القيد اليدويّ — أدواتٌ يُفسد الدفترَ من يستعملها بلا علم، ولا
         * يُكتشف أثرها إلا في ميزان المراجعة بعد شهور.
         */
        $clerk = $this->staff(['finance', 'expenses']);

        $this->actingAs($clerk)->get(route('admin.finance.transactions'))->assertOk();
        $this->actingAs($clerk)->get(route('admin.finance.summary'))->assertOk();
        $this->actingAs($clerk)->get(route('admin.finance.dues'))->assertOk();

        $this->actingAs($clerk)->get(route('admin.finance.chart'))->assertForbidden();
        $this->actingAs($clerk)->get(route('admin.finance.journal'))->assertForbidden();
        $this->actingAs($clerk)->get(route('admin.finance.assets'))->assertForbidden();
    }

    public function test_a_clerk_cannot_write_a_manual_entry_or_touch_the_chart(): void
    {
        $clerk = $this->staff(['finance', 'expenses']);
        $cash = Ledger::account($this->bid(), 'cash');
        $income = Ledger::account($this->bid(), 'other_income');

        $this->actingAs($clerk)->post(route('admin.finance.journal.store'), [
            'entry_date' => now()->toDateString(),
            'description' => 'قيدٌ من موظّف',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 10],
                ['account_id' => $income->id, 'credit' => 10],
            ],
        ])->assertForbidden();

        $this->actingAs($clerk)->post(route('admin.finance.chart.store'), [
            'code' => '5950', 'name' => 'قرطاسية', 'type' => 'مصروف',
        ])->assertForbidden();

        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
    }

    public function test_the_accountant_holds_the_advanced_tools(): void
    {
        $accountant = $this->staff(['finance', 'accounting']);

        $this->actingAs($accountant)->get(route('admin.finance.chart'))->assertOk();
        $this->actingAs($accountant)->get(route('admin.finance.journal'))->assertOk();
        $this->actingAs($accountant)->get(route('admin.finance.assets'))->assertOk();
    }

    /**
     * ومن فقدها بالتشديد له بابٌ يستردّها منه.
     *
     * الموظّفون ذوو الصلاحيات المخصَّصة الذين مُنحوا «المالية» فقدوا الشجرةَ
     * والقيودَ والأصول حين صارت تحت مفتاحٍ أضيق. فمن كان محاسبًا فعلًا يُمنحها
     * صراحةً — والمنح من الشاشة نفسها التي سُلبت منها، لا من القاعدة بيدٍ.
     */
    public function test_advanced_accounting_can_be_granted_from_the_employee_screen(): void
    {
        $clerk = $this->staff(['finance', 'expenses']);

        $sections = $this->get(route('admin.employees.edit', $clerk->id))
            ->assertOk()->viewData('page')['props']['sections'];

        $this->assertArrayHasKey('accounting', $sections, 'لا مربّع يُمنح منه القسم');
        $this->assertSame(__('المحاسبة المتقدمة'), $sections['accounting']);

        $this->put(route('admin.employees.update', $clerk->id), [
            'name' => $clerk->name, 'email' => $clerk->email, 'job_title' => 'كاشير',
            'manual_permissions' => 1, 'permissions' => ['finance', 'expenses', 'accounting'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($clerk->fresh()->allows('accounting'));
        $this->actingAs($clerk->fresh())->get(route('admin.finance.journal'))->assertOk();
    }

    /** ودورُ المحاسب يأخذها بلا منحٍ يدويّ */
    public function test_the_accountant_role_holds_it_without_being_granted(): void
    {
        $accountant = User::create([
            'business_id' => $this->bid(), 'name' => 'محاسب', 'email' => 'acc@abaad.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);

        $this->assertTrue($accountant->allows('accounting'));
        $this->actingAs($accountant)->get(route('admin.finance.chart'))->assertOk();
    }

    public function test_a_clerk_without_finance_cannot_record_a_movement(): void
    {
        $this->actingAs($this->staff(['inventory']))
            ->post(route('admin.finance.store'), ['kind' => 'other_income', 'amount' => 5, 'side' => 'cash'])
            ->assertForbidden();

        $this->assertSame(0, Transaction::where('business_id', $this->bid())->count());
    }
}

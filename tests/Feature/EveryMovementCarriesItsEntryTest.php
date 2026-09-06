<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ الحركة المالية: بابٌ يكتب في الدفترين معًا أو لا يكتب.
 *
 * كان `POST /finance/transactions` مسارَ حفظٍ بلا شاشة تقصده، ويكتب صفًّا في
 * `transactions` ولا يكتب قيدًا في `journal_entries` — أي مالًا يتحرّك في
 * دفتر الصندوق ولا أثر له في دفتر الأستاذ. وميزانُ المراجعة يبقى متوازنًا
 * لأنّه لا يعرف أنّ شيئًا وقع.
 *
 * وكان يسأل «دخل أم مصروف؟» وهما لا يكفيان: التحويلُ بين الدرج والبنك ليس
 * أيًّا منهما، وسحبُ المالك ليس مصروفًا، و«دخل» تجمع البيعةَ وتعويضَ التأمين
 * في خانةٍ واحدة تُقرأ مبيعاتٍ في كلّ تقرير.
 *
 * فما يحرسه هذا الملفّ: أنّ كلّ حركةٍ تُسجَّل هنا تحمل قيدَها، وأنّ الوصفة
 * تضع كلّ نوعٍ في حسابه، وأنّ ما يكتبه النظامُ عن مستنده لا يُكتب هنا بيدٍ.
 */
class EveryMovementCarriesItsEntryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    /** @return array<string, mixed> */
    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    private function move(array $payload)
    {
        return $this->post(route('admin.finance.store'), $payload);
    }

    /** رصيد حسابٍ بمفتاحه النظاميّ */
    private function balance(string $key): float
    {
        return round((float) (Ledger::account($this->bid(), $key)?->balance() ?? 0), 3);
    }

    /* ------------------------- القيد يرافق الحركة ------------------------- */

    public function test_a_manual_expense_writes_both_books(): void
    {
        $this->move([
            'kind' => 'expense', 'amount' => 45, 'side' => 'cash', 'description' => 'كهرباء سبتمبر',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::where('business_id', $this->bid())->firstOrFail();

        $this->assertSame('expense', $transaction->kind);
        $this->assertSame('مصروف', $transaction->type);
        $this->assertNotNull($transaction->journal_entry_id, 'حركةٌ بلا قيد — وهو العطب نفسه');

        // والقيد متوازن ومعلَّقٌ بحركته، لا معلَّقًا في الهواء
        $entry = JournalEntry::findOrFail($transaction->journal_entry_id);
        $this->assertSame(Transaction::class, $entry->sourceable_type);
        $this->assertSame($transaction->id, (int) $entry->sourceable_id);
        $this->assertSame(45.0, round((float) $entry->lines()->sum('debit'), 3));
        $this->assertSame(45.0, round((float) $entry->lines()->sum('credit'), 3));
    }

    public function test_a_manual_expense_shows_on_the_expenses_screen_too(): void
    {
        /*
         * وإلا صار للمصروف بابان: ما يُسجَّل هنا لا يُرى هناك، فيقرأ التاجر
         * «مصروفات الشهر» ناقصةً ولا يقول له شيءٌ لماذا.
         */
        $this->move(['kind' => 'expense', 'amount' => 45, 'side' => 'cash', 'description' => 'كهرباء']);

        $expense = Expense::where('business_id', $this->bid())->firstOrFail();

        $this->assertSame(45.0, (float) $expense->amount);
        $this->assertTrue($expense->isPaid(), 'مصروفٌ خرج ماله ويُعدّ غير مدفوع');
        $this->assertNotNull($expense->reference, 'مصروفٌ بلا مرجع لا يُبحث عنه');
        $this->assertSame(Transaction::where('business_id', $this->bid())->value('id'), $expense->transaction_id);
    }

    /* --------------------------- الوصفة والحساب --------------------------- */

    public function test_an_owner_withdrawal_is_not_an_expense(): void
    {
        /*
         * سحبُ المالك يُنقص حقّه في المتجر ولا يُنقص ربحه. وخلطُه بالمصروف
         * يجعل متجرًا رابحًا يقرأ نفسه خاسرًا كلّما أخذ صاحبه مصروفه من الدرج.
         */
        $this->move(['kind' => 'owner_withdrawal', 'amount' => 100, 'side' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(100.0, $this->balance('drawings'));
        $this->assertSame(0.0, $this->balance('other_expenses'));
        $this->assertSame(-100.0, $this->balance('cash'));
    }

    public function test_a_transfer_is_neither_in_nor_out(): void
    {
        $this->move(['kind' => 'cash_to_bank', 'amount' => 200])->assertSessionHasNoErrors();

        $transaction = Transaction::where('business_id', $this->bid())->firstOrFail();

        $this->assertSame('تحويل', $transaction->type, 'التحويل يُجمع مع الدخل فيُضخّم المقبوض');
        $this->assertSame(200.0, $this->balance('bank'));
        $this->assertSame(-200.0, $this->balance('cash'));

        $summary = $this->props(route('admin.finance.transactions'))['summary'];

        $this->assertSame(0.0, $summary['in']);
        $this->assertSame(0.0, $summary['out']);
        $this->assertSame(200.0, $summary['transfers']);
    }

    public function test_an_expense_lands_in_the_account_its_type_was_linked_to(): void
    {
        /*
         * وإلا سقط كلُّ مصروفٍ في «مصروفات أخرى»: سطرٌ واحد يبتلع الإيجار
         * والكهرباء والصيانة، فلا تقول قائمةُ الدخل أين يذهب مال المتجر.
         */
        ExpenseType::create([
            'business_id' => $this->bid(), 'name' => 'إيجار المحل', 'account_key' => 'rent',
        ]);

        $this->move([
            'kind' => 'expense', 'amount' => 300, 'side' => 'bank', 'expense_type' => 'إيجار المحل',
        ])->assertSessionHasNoErrors();

        $this->assertSame(300.0, $this->balance('rent'));
        $this->assertSame(0.0, $this->balance('other_expenses'));
    }

    public function test_an_unlinked_expense_type_falls_to_other_and_still_posts(): void
    {
        // ونوعٌ لم يُربط لا يُسقط التسجيل: «أخرى» أهون من مصروفٍ لا يُقيَّد
        $this->move([
            'kind' => 'expense', 'amount' => 12, 'side' => 'cash', 'expense_type' => 'نوعٌ لا حساب له',
        ])->assertSessionHasNoErrors();

        $this->assertSame(12.0, $this->balance('other_expenses'));
        $this->assertNotNull(Transaction::where('business_id', $this->bid())->value('journal_entry_id'));
    }

    /* ------------------------------ ما يُردّ ------------------------------ */

    public function test_a_pos_sale_is_not_recorded_by_hand(): void
    {
        /*
         * البيع تكتبه نقطة البيع لحظته. وتسجيلُه هنا يعني بيعةً مرّتين في كلّ
         * تقرير — والحارس في الخادم لا في الشاشة: الطلب قد يصل من غيرها.
         */
        $this->move(['kind' => Transaction::SALE, 'amount' => 50, 'side' => 'cash'])
            ->assertSessionHasErrors('kind');

        $this->assertSame(0, Transaction::where('business_id', $this->bid())->count());
    }

    public function test_a_movement_that_asks_for_a_side_is_refused_without_one(): void
    {
        $this->move(['kind' => 'expense', 'amount' => 20])->assertSessionHasErrors('side');

        $this->assertSame(0, Transaction::where('business_id', $this->bid())->count());
    }

    public function test_a_zero_movement_is_refused(): void
    {
        // صفٌّ في الدفتر لا يغيّر رصيدًا ولا يُصحَّح
        $this->move(['kind' => 'other_income', 'amount' => 0, 'side' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, JournalEntry::count());
    }

    /* ---------------------------- ضغطتان لا تكرار ---------------------------- */

    public function test_the_same_submission_twice_moves_the_money_once(): void
    {
        $payload = [
            'kind' => 'owner_deposit', 'amount' => 500, 'side' => 'cash',
            'client_uuid' => 'a1b2c3d4-e5f6-4711-8899-aabbccddeeff',
        ];

        $this->move($payload)->assertSessionHasNoErrors();
        $this->move($payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('business_id', $this->bid())->count());
        $this->assertSame(500.0, $this->balance('cash'));
    }

    /* ------------------------------ الجدول ------------------------------ */

    public function test_a_cancelled_invoice_leaves_the_total_and_stays_in_the_table(): void
    {
        $order = Order::create([
            'business_id' => $this->bid(), 'number' => 'INV-1', 'status' => Order::CANCELLED,
            'total' => 30, 'subtotal' => 30, 'payment_method' => 'نقدي', 'is_held' => false,
        ]);

        Transaction::create([
            'business_id' => $this->bid(), 'reference' => 'TRX-000900', 'description' => 'بيعة',
            'method' => 'نقدي', 'type' => 'دخل', 'kind' => Transaction::SALE, 'amount' => 30,
            'order_id' => $order->id, 'occurred_at' => now(),
        ]);

        $props = $this->props(route('admin.finance.transactions'));

        $this->assertSame(0.0, $props['summary']['in'], 'مالٌ لم يُقبض يُجمع في المقبوض');
        $this->assertCount(1, $props['rows'], 'سجلٌّ وقع فمُحي — التاريخ المالي لا يُمحى بإلغاء');
        $this->assertTrue($props['rows'][0]['cancelled']);
    }

    public function test_a_neighbours_movements_are_not_listed(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Transaction::create([
            'business_id' => $other->id, 'reference' => 'TRX-000001', 'description' => 'مالُ الجار',
            'method' => 'نقدي', 'type' => 'دخل', 'kind' => 'other_income', 'amount' => 999,
            'occurred_at' => now(),
        ]);

        $props = $this->props(route('admin.finance.transactions'));

        $this->assertCount(0, $props['rows']);
        $this->assertSame(0.0, $props['summary']['in']);
    }

    public function test_the_screen_never_offers_a_sale_as_something_to_record(): void
    {
        // بابٌ معروضٌ لا يُفتح: نوعٌ في القائمة يردّه الخادم عند الحفظ
        $movements = $this->props(route('admin.finance.transactions'))['movements'];

        $this->assertNotEmpty($movements);
        $this->assertNotContains(Transaction::SALE, array_column($movements, 'value'));
    }

    /* ------------------------- الملخّص والمستحقّات ------------------------- */

    public function test_the_summary_reads_the_cash_it_posted(): void
    {
        $this->move(['kind' => 'owner_deposit', 'amount' => 700, 'side' => 'cash']);
        $this->move(['kind' => 'expense', 'amount' => 100, 'side' => 'cash']);

        $props = $this->props(route('admin.finance.summary'));

        $this->assertSame(600.0, $props['cash'], 'الرصيد يُقرأ من الدفتر لا من جمع الحركات');
        $this->assertSame(700.0, $props['period']['in']);
        $this->assertSame(100.0, $props['period']['out']);
    }

    public function test_both_screens_say_the_same_number_about_what_is_owed(): void
    {
        /*
         * «عليك ٤٢٠» في الملخّص و«عليك ٣٩٠» في المستحقّات يجعل التاجر لا
         * يصدّق أيًّا منهما — فالمجموع يُحسب في موضعٍ واحد.
         */
        Expense::create([
            'business_id' => $this->bid(), 'reference' => 'EXP-1', 'type' => 'إيجار',
            'description' => 'إيجار سبتمبر', 'amount' => 420, 'method' => 'نقدي',
            'status' => 'غير مدفوع', 'spent_at' => now()->toDateString(),
        ]);

        $summary = $this->props(route('admin.finance.summary'))['dues'];
        $dues = $this->props(route('admin.finance.dues'))['totals'];

        $this->assertSame(420.0, $summary['total']);
        $this->assertSame($summary, $dues);
    }

    public function test_a_neighbours_bill_is_not_owed_by_this_shop(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Expense::create([
            'business_id' => $other->id, 'reference' => 'EXP-9', 'type' => 'إيجار',
            'description' => 'إيجار الجار', 'amount' => 999, 'method' => 'نقدي',
            'status' => 'غير مدفوع', 'spent_at' => now()->toDateString(),
        ]);

        $props = $this->props(route('admin.finance.dues'));

        $this->assertSame(0.0, $props['totals']['total']);
        $this->assertCount(0, $props['expenses']);
    }
}

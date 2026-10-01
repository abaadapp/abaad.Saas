<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * قيدٌ مبسّط من نقطة البيع — ما حدث عند الدرج، والدفترُ يكتب قيده.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) الفعلُ لا القسم: الكاشيرُ يبيع ولا يُخرج من الدرج بلا إذن — لا زرَّ
 *    يراه، والبابُ يردّه ولو وصله الطلب من غير الزرّ. والمالكُ والمديرُ
 *    بدورهما، ومن يُمنحه بالاسم يُفتح له.
 * ٢) الوصفةُ وصفةُ المالية: مصروفٌ مدينٌ بحساب نوعه دائنٌ بالصندوق، ويظهر
 *    صفًّا في المصروفات بفرعه — فيُقرأ في صافي ربح الفرع.
 * ٣) الدرجُ جهتُه والآنُ تاريخُه — ما يُرسَل غيرَهما لا يُقرأ.
 * ٤) الفرعُ فرعُ الجهاز، والاسمُ اسمُ الواقف على الصندوق.
 * ٥) ضغطتان لا تُخرجان المال مرّتين، ونوعُ مصروف متجرٍ آخر لا يُقبل.
 */
class ADrawerEntryFromThePosWritesItsOwnLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $main;

    private Branch $seeb;

    private User $owner;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-17 12:00:00');

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        ExpenseType::create(['business_id' => $this->shop->id, 'name' => 'كهرباء', 'account_key' => 'utilities']);

        $this->owner = $this->user('admin', 'o@abaad.om', 'المالك');
        $this->manager = $this->user('manager', 'm@abaad.om', 'المدير');
        $this->cashier = $this->user('cashier', 'c@abaad.om', 'أحمد');

        // الجهازُ في السيب — لا في أوّل فرع: الفرعُ يُقرأ من الجهاز لا يُخمَّن
        $this->activatePosDevice($this->shop->id, $this->seeb->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $email, string $name, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => $name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function enter(array $data = [])
    {
        return $this->post(route('pos.movements.store'), $data + [
            'kind' => 'expense',
            'amount' => '12.500',
            'description' => 'فاتورة الكهرباء',
            'expense_type' => 'كهرباء',
            'client_uuid' => uniqid('m', true),
        ]);
    }

    /** @return array<string, float> رمزُ الحساب ← مدين − دائن */
    private function lines(JournalEntry $entry): array
    {
        $out = [];
        foreach ($entry->lines()->with('account')->get() as $line) {
            $out[$line->account->code] = round((float) $line->debit - (float) $line->credit, 3);
        }
        ksort($out);

        return $out;
    }

    private function code(string $key): string
    {
        return Ledger::account($this->shop->id, $key)->code;
    }

    /* ============================ من يرى الزرّ ============================ */

    public function test_the_owner_and_the_manager_see_the_button_and_the_cashier_does_not(): void
    {
        foreach ([$this->owner, $this->manager] as $who) {
            $this->actingAs($who)->get(route('pos.index'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('movement.expenseTypes', ['كهرباء'])
                    ->has('movement.kinds', count(Books::MOVEMENTS)));
        }

        $this->actingAs($this->cashier)->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('movement', null));
    }

    public function test_the_cashier_is_turned_away_at_the_door_too_and_nothing_is_written(): void
    {
        $this->actingAs($this->cashier);
        $this->enter()->assertForbidden();

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_cashier_granted_the_action_by_name_may_enter_it(): void
    {
        $granted = $this->user('cashier', 'g@abaad.om', 'سالم', ['dashboard', 'pos', Permissions::POS_MOVEMENT]);

        $this->actingAs($granted)->get(route('pos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->whereNot('movement', null));

        $this->enter()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Transaction::count());
    }

    public function test_the_action_is_offered_in_the_employee_permissions_screen(): void
    {
        $this->assertArrayHasKey(Permissions::POS_MOVEMENT, Permissions::actionLabels());
        $this->assertTrue(Permissions::allowsAction('admin', Permissions::POS_MOVEMENT));
        $this->assertTrue(Permissions::allowsAction('manager', Permissions::POS_MOVEMENT));
        $this->assertFalse(Permissions::allowsAction('cashier', Permissions::POS_MOVEMENT));
        $this->assertFalse(Permissions::allowsAction('accountant', Permissions::POS_MOVEMENT));
    }

    /* ============================ ما يُكتب ============================ */

    public function test_a_cash_expense_is_an_expense_row_a_movement_and_one_balanced_entry_on_the_drawer(): void
    {
        $this->actingAs($this->owner);
        $this->enter()->assertRedirect()->assertSessionHasNoErrors();

        $tx = Transaction::sole();
        $this->assertSame('expense', $tx->kind);
        $this->assertSame('مصروف', $tx->type);
        $this->assertSame('نقدي', $tx->method);
        $this->assertSame(12.5, (float) $tx->amount);
        $this->assertSame($this->seeb->id, (int) $tx->branch_id, 'فرعُ الجهاز لا أوّلُ فرع');

        $expense = Expense::sole();
        $this->assertSame('كهرباء', $expense->type);
        $this->assertSame(12.5, (float) $expense->amount);
        $this->assertSame($this->seeb->id, (int) $expense->branch_id, 'يُقرأ في صافي ربح السيب');
        $this->assertSame($tx->id, (int) $expense->transaction_id);

        $entry = JournalEntry::sole();
        $this->assertSame($this->seeb->id, (int) $entry->branch_id);
        $this->assertSame('2027-03-17', Carbon::parse($entry->entry_date)->toDateString());
        $this->assertSame([
            $this->code('cash') => -12.5,
            $this->code('utilities') => 12.5,
        ], $this->lines($entry));
    }

    public function test_the_drawer_and_today_are_not_read_from_the_request(): void
    {
        $this->actingAs($this->owner);
        $this->enter(['side' => 'bank', 'occurred_at' => '2020-01-01', 'branch_id' => $this->main->id])
            ->assertSessionHasNoErrors();

        $tx = Transaction::sole();
        $this->assertSame('نقدي', $tx->method);
        $this->assertSame('2027-03-17', Carbon::parse($tx->occurred_at)->toDateString());
        $this->assertSame($this->seeb->id, (int) $tx->branch_id);
        $this->assertArrayHasKey($this->code('cash'), $this->lines(JournalEntry::sole()));
        $this->assertArrayNotHasKey($this->code('bank'), $this->lines(JournalEntry::sole()));
    }

    public function test_each_kind_follows_the_finance_recipe_from_the_drawer(): void
    {
        $this->actingAs($this->owner);

        $expect = [
            'other_income' => ['cash' => 5.0, 'other_income' => -5.0],
            'owner_deposit' => ['cash' => 5.0, 'capital' => -5.0],
            'owner_withdrawal' => ['drawings' => 5.0, 'cash' => -5.0],
            'cash_to_bank' => ['bank' => 5.0, 'cash' => -5.0],
            'bank_to_cash' => ['cash' => 5.0, 'bank' => -5.0],
        ];

        foreach ($expect as $kind => $byKey) {
            $this->enter(['kind' => $kind, 'amount' => 5, 'expense_type' => null])->assertSessionHasNoErrors();

            $entry = JournalEntry::latest('id')->first();
            $want = [];
            foreach ($byKey as $key => $v) {
                $want[$this->code($key)] = $v;
            }
            ksort($want);
            $this->assertSame($want, $this->lines($entry), $kind);
        }

        $this->assertSame(0, Expense::count(), 'ليس شيءٌ منها مصروفًا');
    }

    public function test_the_entry_carries_the_name_of_who_stands_at_the_register(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.cashier.select'), ['employee_id' => $this->cashier->id]);

        $this->enter()->assertSessionHasNoErrors();

        $this->assertSame('أحمد', Transaction::sole()->employee_name);
        $this->assertSame('أحمد', Expense::sole()->employee_name);
        $this->assertSame($this->cashier->id, (int) JournalEntry::sole()->created_by);
    }

    /* ============================ ما يُردّ ============================ */

    public function test_two_presses_take_the_money_out_once(): void
    {
        $this->actingAs($this->owner);
        $this->enter(['client_uuid' => 'same'])->assertSessionHasNoErrors();
        $this->enter(['client_uuid' => 'same'])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_what_is_not_a_drawer_entry_is_refused(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        ExpenseType::create(['business_id' => $other->id, 'name' => 'نوعُ الجار']);

        $this->actingAs($this->owner);

        $this->enter(['kind' => 'sale'])->assertSessionHasErrors('kind');
        $this->enter(['amount' => 0])->assertSessionHasErrors('amount');
        $this->enter(['amount' => -3])->assertSessionHasErrors('amount');
        $this->enter(['expense_type' => 'نوعُ الجار'])->assertSessionHasErrors('expense_type');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_the_entry_stays_inside_its_shop(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->owner);
        $this->enter()->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('business_id', $other->id)->count());
        $this->assertSame($this->shop->id, (int) JournalEntry::sole()->business_id);
        $this->assertSame($this->shop->id, (int) Expense::sole()->business_id);
    }
}

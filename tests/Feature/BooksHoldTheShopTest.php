<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دفتر الأستاذ يحمل عملَ المحلّ — لا نصفَه.
 *
 * كان في النظام دفتران لا يلتقيان: `transactions` تكتب فيه نقطةُ البيع
 * وتقرأ منه التقارير، و`journal_entries` تكتب فيه سنداتُ الموردين والرواتب
 * والأصول. فالمبيعات لم تصل إلى الثاني أبدًا: شجرةٌ فيها «إيراد المبيعات»
 * صفر وقد باع صاحبُها، ومخزونٌ يزيد بالشراء ولا ينقص بالبيع.
 *
 * وميزانُ المراجعة كان يقول «متوازن» — وهو متوازنٌ فعلًا لأنّ كلّ قيدٍ كُتب
 * متوازن — فيطمئنّ التاجر إلى دفترٍ ليس فيه عملُه.
 */
class BooksHoldTheShopTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);

        Ledger::seedChart($this->business->id);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
        $this->openShiftFor($this->business->id, $this->branch->id);
    }

    private function sell(array $extra = [])
    {
        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 3]],
            'payment_method' => 'نقدي',
        ], $extra));
    }

    /** رصيدُ حسابٍ بمفتاحه النظاميّ من ميزان المراجعة */
    private function balance(string $key): float
    {
        $account = Ledger::account($this->business->id, $key);
        $row = collect(Ledger::trialBalance($this->business->id)['accounts'])
            ->firstWhere('id', $account->id);

        return (float) ($row['balance'] ?? 0);
    }

    /* ------------------- البيعة تصل إلى الدفتر ------------------- */

    public function test_a_sale_reaches_the_ledger_not_only_the_cashbook(): void
    {
        $this->sell()->assertOk();
        $order = Order::where('is_held', false)->firstOrFail();

        // دفتر الصندوق كما كان
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('type', 'دخل')->count());
        // ودفتر الأستاذ الذي لم يكن يصله شيء
        $this->assertGreaterThan(0, $this->balance('sales'));
    }

    public function test_the_entry_carries_revenue_tax_and_cash_apart(): void
    {
        $this->sell()->assertOk();
        $order = Order::where('is_held', false)->firstOrFail();

        $this->assertSame(round((float) $order->total, 3), round($this->balance('cash'), 3));
        $this->assertSame(
            round((float) $order->subtotal - (float) $order->discount + (float) $order->delivery_fee, 3),
            round($this->balance('sales'), 3),
        );
        $this->assertSame(round((float) $order->tax, 3), round($this->balance('tax_payable'), 3));
    }

    /** البضاعة تخرج بتكلفتها — وبلا هذا ينتفخ المخزون بكلّ ما بيع منه */
    public function test_the_cost_of_what_was_sold_leaves_the_inventory_account(): void
    {
        $this->sell()->assertOk();

        $this->assertSame(12.0, round($this->balance('cogs'), 3));      // 3 × 4
        $this->assertSame(-12.0, round($this->balance('inventory'), 3));
    }

    public function test_the_trial_balance_still_balances_after_a_sale(): void
    {
        $this->sell()->assertOk();

        $tb = Ledger::trialBalance($this->business->id);
        $this->assertTrue($tb['balanced'], 'اختلّ الميزان بعد قيد البيع');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
    }

    public function test_an_unpaid_sale_is_a_receivable_not_cash(): void
    {
        $this->sell()->assertOk();
        $order = Order::where('is_held', false)->firstOrFail();

        // فاتورةٌ صارت آجلة: ليست نقدًا في الدرج بل ذمّةً على العميل
        $order->update(['payment_status' => 'غير مدفوع']);
        Books::forgetSale($order);
        Books::recordSale($order->fresh());

        $this->assertSame(round((float) $order->total, 3), round($this->balance('receivable'), 3));
        $this->assertSame(0.0, round($this->balance('cash'), 3));
    }

    public function test_a_card_sale_goes_to_the_bank_not_the_drawer(): void
    {
        $this->sell(['payment_method' => 'بطاقة'])->assertOk();

        $this->assertGreaterThan(0, $this->balance('bank'));
        $this->assertSame(0.0, round($this->balance('cash'), 3));
    }

    /* ------------------- ولا يُكتب مرّتين ------------------- */

    public function test_posting_the_same_sale_twice_writes_it_once(): void
    {
        $this->sell()->assertOk();
        $order = Order::where('is_held', false)->firstOrFail();

        Books::recordSale($order);
        Books::recordSale($order);

        $this->assertSame(2, JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->count(), 'قيدٌ للإيراد وآخر للتكلفة — لا أكثر');
    }

    public function test_the_repair_command_posts_only_what_is_missing(): void
    {
        $this->sell()->assertOk();
        $order = Order::where('is_held', false)->firstOrFail();
        Books::forgetSale($order);
        $this->assertSame(0.0, $this->balance('sales'));

        $this->artisan('finance:post-missing-sales')->assertSuccessful();
        $posted = $this->balance('sales');

        $this->artisan('finance:post-missing-sales')->assertSuccessful();
        $this->assertSame($posted, $this->balance('sales'), 'كُتب القيد مرّتين');
    }

    /* ------------------- الإلغاء يمحو قيده ------------------- */

    public function test_cancelling_a_sale_takes_its_ledger_entries_with_it(): void
    {
        $this->sell()->assertOk();
        $order = Order::where('is_held', false)->firstOrFail();

        OrderCorrection::cancel($order);

        $this->assertSame(0, JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->count());
        $this->assertSame(0.0, round($this->balance('sales'), 3));
        $this->assertSame(0.0, round($this->balance('cogs'), 3));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /* ------------------- المصروف كذلك ------------------- */

    private function spend(array $over = []): Expense
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => 300, 'method' => 'نقدي',
        ], $over))->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    public function test_a_paid_expense_reaches_the_ledger(): void
    {
        $this->spend();

        $this->assertSame(300.0, round($this->balance('rent'), 3));
        $this->assertSame(-300.0, round($this->balance('cash'), 3));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_an_unfamiliar_expense_type_lands_in_other_expenses(): void
    {
        $this->spend(['type' => 'صيانة المكيّف']);

        $this->assertSame(300.0, round($this->balance('other_expenses'), 3));
    }

    /**
     * والرواتب ليست منها عمدًا: مسيرةُ الرواتب تُرحّل بنفسها، فربطُ نوعٍ
     * مكتوبٍ باليد بالحساب نفسه يجعل راتبًا واحدًا يُقيَّد مرّتين.
     */
    public function test_an_expense_typed_salaries_does_not_touch_the_payroll_account(): void
    {
        $this->spend(['type' => 'رواتب']);

        $this->assertSame(0.0, round($this->balance('salaries'), 3));
        $this->assertSame(300.0, round($this->balance('other_expenses'), 3));
    }

    public function test_an_unpaid_bill_is_not_money_out_yet(): void
    {
        $this->spend(['status' => 'غير مدفوع', 'due_date' => now()->addWeek()->toDateString()]);

        $this->assertSame(0.0, round($this->balance('rent'), 3));
        $this->assertSame(0.0, round($this->balance('cash'), 3));
    }

    public function test_deleting_an_expense_takes_its_entry_with_it(): void
    {
        $expense = $this->spend();

        $this->delete(route('admin.expenses.destroy', $expense->id));

        $this->assertSame(0.0, round($this->balance('rent'), 3));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_restoring_it_brings_the_entry_back_once(): void
    {
        $expense = $this->spend();
        $this->delete(route('admin.expenses.destroy', $expense->id));
        $this->post(route('admin.expenses.restore', $expense->id));

        $this->assertSame(300.0, round($this->balance('rent'), 3));
        $this->assertSame(1, JournalEntry::where('sourceable_type', Expense::class)
            ->where('sourceable_id', $expense->id)->count());
    }

    /* ------------------- الصندوق لا يتوقّف ------------------- */

    /**
     * شجرةُ حساباتٍ عدّلها التاجر تجعل الترحيل يرفض — ولو رُبط بها البيع
     * لتوقّف الصندوق والزبون واقف.
     */
    public function test_a_broken_chart_never_stops_a_sale(): void
    {
        $cash = Ledger::account($this->business->id, 'cash');
        // حسابٌ صار له فرعٌ تحته لم يعد يُرحَّل إليه
        \App\Models\Account::create([
            'business_id' => $this->business->id, 'parent_id' => $cash->id,
            'code' => '1101', 'name' => 'درج الكاشير', 'type' => 'أصل', 'normal_side' => 'debit',
        ]);

        $this->sell()->assertOk();

        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(0, JournalEntry::where('sourceable_type', Order::class)->count());
        // ويُقيَّد الإخفاق باسمه ليُستدرَك، لا يُبتلع
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'order']);
    }

    /* ------------------- حدّ المتجر ------------------- */

    public function test_the_entries_belong_to_the_shop_that_sold(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        Ledger::seedChart($other->id);

        $this->sell()->assertOk();

        $this->assertSame(0.0, round(Ledger::trialBalance($other->id)['total_debit'], 3));
        $this->assertGreaterThan(0, Ledger::trialBalance($this->business->id)['total_debit']);
    }
}

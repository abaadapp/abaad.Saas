<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البيعة تصل إلى الدفترين — والملغاة تخرج منهما بعكسٍ لا بمحو.
 *
 * كان البيع يكتب صفًّا في `transactions` ولا يكتب قيدًا في `journal_entries`:
 * الدفتر يعرف ما خرج من الصندوق (مصروفات ورواتب وسدادُ موردين) ولا يعرف ما
 * دخله، فرصيدُ الصندوق فيه سالبٌ دائمًا وميزانُ المراجعة صحيحُ التوازن كاذبُ
 * المعنى.
 *
 * وكانت الفاتورة الملغاة تبقى إيرادًا في شاشةٍ وتخرج منه في أخرى: بطاقتان عن
 * الفترة نفسها بجوابين — وأخطرُهما الإقرار الضريبي، ضريبةٌ تُقرّ على بيعةٍ لم
 * تقع.
 */
class SaleLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 50, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        Ledger::seedChart($this->business->id);
        $this->openShiftFor($this->business->id);
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

    /** بيعةٌ من نقطة البيع — كما تقع فعلًا، لا بكتابة صفٍّ بيدٍ */
    private function sell(string $method = 'نقدي', int $qty = 1, ?string $uuid = null): Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty]],
            'payment_method' => $method,
            'client_uuid' => $uuid ?? uniqid('s', true),
        ])->assertOk()->assertJsonPath('ok', true);

        return Order::where('business_id', $this->bid())->latest('id')->firstOrFail();
    }

    /**
     * بيعةٌ لها موعدٌ في المستقبل — وهي الوحيدة التي تُلغى.
     *
     * بيعُ المنضدة يُسجَّل «مكتمل»، و«مكتمل» نهايةٌ لا انتقال بعدها: فاتورة
     * الصندوق تُصحَّح بندًا بندًا ولا تُلغى. أمّا ما يُدفع اليوم ويُسلَّم غدًا
     * فيبقى حيًّا حتى يُسلَّم — أو يعتذر الزبون فيُلغى. والمال قُبض في
     * الحالين، فكلتاهما مُرحَّلة يوم البيع.
     */
    private function sellScheduled(): Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('sch', true),
            'scheduled_for' => now()->addDay()->toDateTimeString(),
        ])->assertOk()->assertJsonPath('ok', true);

        return Order::where('business_id', $this->bid())->latest('id')->firstOrFail();
    }

    /* ------------------------- البيعة في الدفترين ------------------------- */

    public function test_a_cash_sale_debits_the_drawer_and_credits_revenue_and_vat(): void
    {
        $order = $this->sell();

        // 100 + ضريبة 5% = 105 يدخل الدرج، منها 100 إيراد و5 التزامٌ ضريبيّ
        $this->assertSame(105.0, (float) $order->total);
        $this->assertSame(105.0, $this->balance('cash'));
        $this->assertSame(100.0, $this->balance('sales'));
        $this->assertSame(5.0, $this->balance('tax_payable'));
        $this->assertSame(0.0, $this->balance('bank'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_a_card_sale_debits_the_bank_not_the_drawer(): void
    {
        $this->sell('بطاقة');

        $this->assertSame(0.0, $this->balance('cash'), 'دخل الدرجَ مالٌ مرّ بالبنك');
        $this->assertSame(105.0, $this->balance('bank'));
    }

    public function test_the_cost_of_what_was_sold_leaves_the_inventory_account(): void
    {
        /*
         * المخزون المستمرّ: تكلفة ما بيع تنتقل من المخزون إلى المصروف لحظةَ
         * البيع. وبلا هذا يبقى المخزون في الميزانية بقيمة بضاعةٍ خرجت من
         * الرفّ، ويُقرأ ربحُ الشهر بلا تكلفةٍ تُقابله.
         */
        $this->sell(qty: 2);

        $this->assertSame(120.0, $this->balance('cogs'), 'لقطةُ التكلفة يوم البيع: 60 × 2');
        $this->assertSame(-120.0, $this->balance('inventory'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_the_cost_snapshot_is_used_not_todays_price(): void
    {
        // المورّد يرفع سعره بعد البيع، فتكلفةُ ما بيع أمس لا تتغيّر
        $order = $this->sell();
        $this->product->update(['cost' => 90]);

        Books::recordSale($order); // لا يُرحَّل مرّةً ثانية أصلًا
        $this->assertSame(60.0, $this->balance('cogs'));
    }

    public function test_the_two_books_say_the_same_thing_about_one_sale(): void
    {
        $order = $this->sell();

        $transaction = Transaction::where('order_id', $order->id)->firstOrFail();
        $entry = JournalEntry::forSource($order)->live()->firstOrFail();

        $this->assertNotNull($transaction->journal_entry_id, 'حركةُ البيع لا تعرف قيدها');
        $this->assertSame($entry->id, (int) $transaction->journal_entry_id);
        $this->assertSame((float) $order->total, (float) $transaction->amount);
        $this->assertSame((float) $order->total, $entry->totalDebit() - 60.0, 'مدينُ القيد غير إجمالي الفاتورة');
        $this->assertTrue($entry->posted);
    }

    /* ----------------------- الضريبة تتبع التسجيل ----------------------- */

    public function test_a_business_not_registered_for_vat_gets_no_vat_liability(): void
    {
        /*
         * الضريبة تتبع تسجيل النشاط لا نسبةَ الصنف: متجرٌ لم يبلغ حدّ التسجيل
         * لا التزام ضريبيّ عليه، ولو حملت أصنافُه نسبًا في بطاقاتها. وإنشاء
         * «ضريبة مستحقّة» له يعني إقرارًا بمالٍ ليس عليه أن يجبيه.
         */
        Setting::create(['business_id' => $this->bid(), 'key' => 'vat_enabled', 'value' => '0']);
        $this->product->update(['tax' => 5]);

        $order = $this->sell();

        $this->assertSame(0.0, (float) $order->tax);
        $this->assertSame(100.0, (float) $order->total);
        $this->assertSame(100.0, $this->balance('cash'));
        $this->assertSame(100.0, $this->balance('sales'), 'لم يقع المبلغ كلّه إيرادًا');
        $this->assertSame(0.0, $this->balance('tax_payable'), 'أُنشئ التزامٌ ضريبيّ لمتجرٍ غير مسجَّل');
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_a_registered_business_separates_the_vat_from_its_revenue(): void
    {
        $order = $this->sell();
        $entry = JournalEntry::forSource($order)->live()->firstOrFail();

        $accounts = $entry->lines->map(fn ($l) => $l->account->system_key)->all();

        $this->assertContains('tax_payable', $accounts);
        $this->assertSame(5.0, $this->balance('tax_payable'));
    }

    /* ------------------------ لا ترحيل مرّتين ------------------------ */

    public function test_a_sale_is_never_posted_twice(): void
    {
        $order = $this->sell(uuid: 'once');

        // إعادةُ الرفع بعد انقطاع، ثم نداءٌ مباشر — كلاهما لا يُضاعف الإيراد
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => 'once',
        ])->assertOk();

        $this->assertNull(Books::recordSale($order->fresh()), 'رُحّلت البيعة مرّتين');

        $this->assertSame(1, JournalEntry::forSource($order)->count());
        $this->assertSame(105.0, $this->balance('sales') + $this->balance('tax_payable'));
    }

    public function test_the_backfill_command_skips_what_is_already_posted(): void
    {
        $this->sell();

        $this->artisan('sales:post-ledger', ['--business' => $this->bid()])->assertSuccessful();

        $this->assertSame(100.0, $this->balance('sales'), 'ضاعف الاستدراكُ إيرادًا مُرحَّلًا');
    }

    public function test_the_backfill_command_posts_a_sale_that_never_reached_the_ledger(): void
    {
        // فاتورةٌ من قبل هذه النسخة: صفٌّ في الحركة ولا قيد له
        $order = $this->sell();
        JournalEntry::forSource($order)->delete();

        $this->artisan('sales:post-ledger', ['--business' => $this->bid()])->assertSuccessful();

        $this->assertSame(100.0, $this->balance('sales'));
        $this->assertSame(105.0, $this->balance('cash'));
    }

    /* --------------------------- التصحيح --------------------------- */

    public function test_correcting_an_invoice_reverses_then_reposts(): void
    {
        $order = $this->sell(qty: 2);
        $original = JournalEntry::forSource($order)->live()->firstOrFail();

        OrderCorrection::setQuantity($order, $order->items()->first(), 1, 'أرجع الزبون واحدة');

        $original->refresh();
        $this->assertNotNull($original->reversed_at, 'عُدّل القيد في مكانه بدل أن يُعكس');

        $reversal = JournalEntry::where('reverses_id', $original->id)->firstOrFail();
        $this->assertSame($order->id, (int) $reversal->sourceable_id, 'القيد العكسيّ بلا مستند');
        $this->assertSame($original->totalDebit(), $reversal->totalCredit(), 'العكس لا يطابق أصله');

        // والدفتر يقول ما تقوله الفاتورة بعد التصحيح: صنفٌ واحد بـ105
        $this->assertSame(105.0, $this->balance('cash'));
        $this->assertSame(100.0, $this->balance('sales'));
        $this->assertSame(5.0, $this->balance('tax_payable'));
        $this->assertSame(60.0, $this->balance('cogs'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);

        // ثلاثة قيود لفاتورةٍ واحدة، وواحدٌ منها حيّ
        $this->assertSame(3, JournalEntry::forSource($order)->count());
        $this->assertSame(1, JournalEntry::forSource($order)->live()->count());
    }

    public function test_correcting_the_payment_method_moves_the_money_to_the_right_account(): void
    {
        /*
         * ضغط الكاشير «نقدي» والزبون دفع بالبطاقة: كان الدرج يُصحَّح في
         * `transactions` ويبقى الدفتر يقول إنّ المال في الصندوق.
         */
        $order = $this->sell();
        $this->assertSame(105.0, $this->balance('cash'));

        OrderCorrection::setPaymentMethod($order->fresh(), 'بطاقة', 'دفع بالبطاقة لا نقدًا');

        $this->assertSame(0.0, $this->balance('cash'));
        $this->assertSame(105.0, $this->balance('bank'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    /* --------------------------- الإلغاء --------------------------- */

    public function test_cancelling_an_invoice_reverses_revenue_vat_and_cost(): void
    {
        $order = $this->sellScheduled();

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::CANCELLED])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->balance('cash'));
        $this->assertSame(0.0, $this->balance('sales'));
        $this->assertSame(0.0, $this->balance('tax_payable'), 'بقيت ضريبةٌ مستحقّة على بيعةٍ أُلغيت');
        $this->assertSame(0.0, $this->balance('cogs'));
        $this->assertSame(0.0, $this->balance('inventory'));
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_cancelling_keeps_the_history_and_does_not_delete_it(): void
    {
        $order = $this->sellScheduled();
        $entry = JournalEntry::forSource($order)->live()->firstOrFail();

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::CANCELLED]);

        $this->assertSame(2, JournalEntry::forSource($order)->count(), 'مُحي التاريخ المالي بدل أن يُعكس');
        $this->assertNotNull($entry->fresh()->reversed_at);
        $this->assertSame(0, JournalEntry::forSource($order)->live()->count());
        $this->assertNotNull(Transaction::where('order_id', $order->id)->first(), 'مُحيت حركة فاتورةٍ ملغاة');
    }

    public function test_a_cancelled_invoice_is_no_longer_revenue_in_any_screen(): void
    {
        /*
         * كان `reportSummary` يستثني الملغى و`financeStats` لا يستثنيه:
         * الشاشتان تقرآن الفترة نفسها وتقولان رقمين.
         */
        $kept = $this->sell();
        $cancelled = $this->sellScheduled();

        $this->post(route('admin.orders.status', $cancelled->number), ['status' => OrderStatus::CANCELLED]);

        $this->assertSame(105.0, Demo::reportSummary('all')['sales']);
        $this->assertSame(Demo::money(105), collect(Demo::financeStats('all'))->first()['value']);

        $props = $this->get(route('admin.finance.summary'))->assertOk()->viewData('page')['props'];
        $this->assertSame(105.0, $props['period']['in'], 'جُمعت فاتورةٌ ملغاة في الدخل');

        // وتبقى في الحركة موسومةً: السجلّ لا يُمحى
        $rows = $this->get(route('admin.finance.transactions'))->assertOk()->viewData('page')['props']['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame(1, collect($rows)->where('cancelled', true)->count());
        $this->assertSame($kept->number, collect($rows)->firstWhere('cancelled', false)['reference']);
    }

    public function test_cancelling_twice_does_not_reverse_twice(): void
    {
        $order = $this->sellScheduled();
        $entry = JournalEntry::forSource($order)->live()->firstOrFail();

        Books::unpostSale($order);
        $this->assertNull(Books::unpostSale($order->fresh()), 'عُكس القيد مرّتين');
        $this->assertNull(Ledger::reverse($entry->fresh()));

        $this->assertSame(2, JournalEntry::forSource($order)->count());
        $this->assertSame(0.0, $this->balance('sales'));
    }

    /* --------------------- الرصيد الحقيقيّ في الدفتر --------------------- */

    public function test_the_ledger_finally_knows_what_is_in_the_drawer(): void
    {
        /*
         * هذا هو المقصد كلّه.
         *
         * كان الدفتر يعرف ما خرج ولا يعرف ما دخل: مصروفاتٌ ورواتبُ وسدادُ
         * موردين تُنقص الصندوق، ولا بيعةَ واحدة تزيده. فرصيدُ الصندوق في
         * ميزان المراجعة سالبٌ دائمًا — متوازنٌ في الأرقام كاذبٌ في المعنى،
         * ولا يصلح لقائمة مركزٍ مالي ولا لجواب «كم عندي؟».
         */
        $this->sell();                    // ‎+105 نقدًا
        $this->sell('بطاقة');              // ‎+105 بنكًا

        $this->post(route('admin.expenses.store'), ['type' => 'إيجار', 'amount' => 45]);
        $this->post(route('admin.finance.store'), [
            'kind' => 'cash_to_bank', 'amount' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertSame(30.0, $this->balance('cash'), '105 − 45 − 30');
        $this->assertSame(135.0, $this->balance('bank'), '105 + 30');
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);

        // والشاشة تقرأ الرصيد من الدفتر لا من جمع الحركات بالعين
        $props = $this->get(route('admin.finance.summary'))->assertOk()->viewData('page')['props'];
        $this->assertSame(30.0, $props['cash']);
    }

    public function test_a_ledger_failure_never_costs_a_sale(): void
    {
        /*
         * الزبون واقفٌ عند الصندوق والفاتورة تُطبع: حسابٌ مفقودٌ في الشجرة
         * يجب ألّا يمنع البيعة. يُكتب التعثّر في السجلّ ويُستدرَك بأمر
         * `sales:post-ledger` — ولا يُردّ الزبون.
         */
        // «الصندوق» صار أبًا لغيره، والحساب ذو الأبناء لا يُرحَّل إليه
        \App\Models\Account::create([
            'business_id' => $this->bid(),
            'parent_id' => Ledger::account($this->bid(), 'cash')->id,
            'code' => '1110', 'name' => 'درج الكاشير', 'type' => 'أصل', 'normal_side' => 'debit',
        ]);

        $order = $this->sell();

        $this->assertSame(105.0, (float) $order->total, 'سقطت بيعةٌ لأنّ الدفتر تعثّر');
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertSame(49, (int) $this->product->fresh()->quantity, 'لم يُخصم المخزون');
        $this->assertNull(Books::liveEntryFor($order), 'رُحّل قيدٌ إلى حسابٍ لا يقبل الترحيل');

        // ويُستدرَك حين يُصلَح الحساب
        \App\Models\Account::where('business_id', $this->bid())->where('code', '1110')->delete();
        $this->artisan('sales:post-ledger', ['--business' => $this->bid()])->assertSuccessful();

        $this->assertSame(105.0, $this->balance('cash'));
        $this->assertSame(100.0, $this->balance('sales'));
    }

    /* ------------------------- عزل المتاجر ------------------------- */

    public function test_a_sale_never_touches_another_stores_ledger(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);

        $this->sell();

        $this->assertSame(0.0, Ledger::account($other->id, 'cash')->balance());
        $this->assertSame(0.0, Ledger::account($other->id, 'sales')->balance());
        $this->assertSame(0, JournalEntry::where('business_id', $other->id)->count());
    }

    public function test_the_backfill_command_stays_inside_the_business_it_is_given(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($other->id);

        $theirs = Order::create([
            'business_id' => $other->id, 'number' => 'X-1', 'customer_name' => 'زبون',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => 'مكتمل',
            'subtotal' => 50, 'discount' => 0, 'tax' => 0, 'total' => 50,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->artisan('sales:post-ledger', ['--business' => $this->bid()])->assertSuccessful();

        $this->assertSame(0, JournalEntry::forSource($theirs)->count());
        $this->assertSame(0.0, Ledger::account($other->id, 'sales')->balance());
    }
}

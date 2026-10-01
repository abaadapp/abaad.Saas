<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * البيعُ الآجل من نقطة البيع — ذمّةٌ بلا إيرادٍ مضاعف.
 *
 * ومالكُ ترحيل الإيراد واحد: `Books::recordSale`. والفاتورةُ المولودة من
 * الطلب مستندٌ فوق حدثٍ رُحّل، لا حدثٌ جديد.
 */
class ACreditSaleOwesWithoutDoublePostingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $company;

    private Product $product;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 100, 'cost' => 40, 'quantity' => 50, 'active' => true,
        ]);
        $this->company = Customer::create([
            'language' => 'ar',
            'business_id' => $this->business->id, 'name' => 'شركة ABC',
            'customer_type' => 'شركة', 'payment_terms_days' => 30,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function checkout(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 100]],
            'payment_method' => 'نقدي',
        ] + $extra);
    }

    // ————— البيعُ النقديُّ كما كان —————

    public function test_a_cash_sale_is_untouched(): void
    {
        $this->checkout()->assertOk();

        $order = Order::first();
        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
        // ولا ورقةَ ذمّةٍ لبيعةٍ نقديّة
        $this->assertSame(0, CustomerInvoice::count());
    }

    // ————— الآجلُ الكامل —————

    public function test_a_credit_sale_owes_and_takes_no_cash(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'sales'));
        $this->assertSame(100.0, Receivables::customerOutstanding($this->business->id, $this->company->id));
    }

    public function test_a_credit_sale_carries_its_paper(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        $invoice = CustomerInvoice::first();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        // والطلبُ يُقرأ من الجدول لا من عمود: ورقةٌ قد تغطّي شهرًا كاملًا
        $this->assertSame([(int) Order::first()->id], $invoice->orders->pluck('id')->all());
        $this->assertSame(100.0, (float) $invoice->total);
        // وتاريخُ الاستحقاق من شروط سداد العميل
        $this->assertSame(30, (int) $invoice->issued_at->diffInDays($invoice->due_at));
    }

    public function test_the_invoice_from_an_order_posts_no_second_revenue(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        // قيدُ بيعٍ واحد، وقيدُ تكلفةٍ واحد — ولا ثالثَ من الفاتورة
        $this->assertSame(1, JournalEntry::where('source', Books::SALE)->count());
        $this->assertSame(1, JournalEntry::where('source', Books::SALE_COST)->count());
        $this->assertSame(0, JournalEntry::where('source', 'فاتورة عميل')->count());
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'sales'));
    }

    public function test_cogs_is_posted_once_by_the_order_not_by_the_invoice(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        $this->assertSame(40.0, Ledger::balance($this->business->id, 'cogs'));
        $this->assertSame(-40.0, Ledger::balance($this->business->id, 'inventory'));
    }

    public function test_stock_is_deducted_once(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        // والفاتورةُ ليست مستندَ مخزون: الخصمُ من الطلب وحده
        $this->assertSame(49, (int) $this->product->fresh()->quantity);
    }

    // ————— الدفعُ الجزئيّ —————

    public function test_a_partial_sale_splits_cash_and_debt(): void
    {
        $this->checkout([
            'credit' => true, 'customer_id' => $this->company->id, 'paid_now' => 30,
        ])->assertOk();

        // ٣٠ نقدًا و٧٠ ذمّة — لا ١٠٠ دخلًا نقديًّا ثمّ ٧٠ ذمّةً فوقها
        $this->assertSame(30.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(70.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'sales'));
        $this->assertSame(70.0, CustomerInvoice::first()->outstanding());
        $this->assertSame('مدفوعة جزئيًا', CustomerInvoice::first()->paymentState());
    }

    public function test_a_partial_sale_keeps_the_trial_balance_true(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id, 'paid_now' => 30])->assertOk();

        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
        $this->assertTrue(Receivables::reconcile($this->business->id)['balanced']);
    }

    public function test_paying_now_more_than_the_total_is_capped(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id, 'paid_now' => 500])->assertOk();

        $this->assertSame(100.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
    }

    // ————— الشروط —————

    public function test_credit_requires_a_customer(): void
    {
        $this->checkout(['credit' => true])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->assertSame(0, Order::count());
    }

    /** وأيُّ عميلٍ مسجَّلٍ يُباع له آجلًا — بقيمه الافتراضيّة، بلا إذنٍ ولا حدّ */
    public function test_a_new_customer_with_defaults_buys_on_credit(): void
    {
        $walkin = Customer::create(['language' => 'ar', 'business_id' => $this->business->id, 'name' => 'زبون جديد']);

        $this->checkout(['credit' => true, 'customer_id' => $walkin->id])->assertOk();

        $this->assertSame(100.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(100.0, Receivables::customerOutstanding($this->business->id, $walkin->id));
    }

    /** والكاشيرُ يبيع آجلًا كما يبيع المالك — لا فعلَ يُطلب ولا سببَ يُكتب */
    public function test_a_cashier_sells_on_credit_without_any_permission(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id], $this->cashier)->assertOk();

        $this->assertSame(100.0, Ledger::balance($this->business->id, 'receivable'));
    }

    /** ولا سقفَ يُحسب على ما عليه — البيعةُ الثانية تمرّ كالأولى */
    public function test_no_ceiling_counts_what_is_already_owed(): void
    {
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        $this->assertSame(200.0, Receivables::customerOutstanding($this->business->id, $this->company->id));
        $this->assertSame(0, DB::table('activity_logs')->where('description', 'like', '%تجاوز%')->count());
    }

    // ————— التعدّد المستأجَر —————

    public function test_a_customer_from_another_shop_cannot_be_charged(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create([
            'language' => 'ar',
            'business_id' => $other->id, 'name' => 'عميلهم',
        ]);

        $this->checkout(['credit' => true, 'customer_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    /* ————— ولا مقبضَ في الإعدادات يطفئه —————
     *
     * كان في «طرق الدفع» مقبضٌ `pay_credit` يُطفئ الآجلَ للمتجر كلّه، فحُذف:
     * الشرطُ الوحيد عميلٌ مختار. ومتجرٌ أطفأه قبل الحذف بقي صفُّه في
     * `settings` — والصفُّ الباقي لا يُقرأ، فلا يمنع بيعةً ولا يُخفي الصندوق.
     */

    public function test_a_switch_left_off_before_the_removal_stops_nothing(): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'pay_credit'], ['value' => '0']);

        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();
        $this->assertSame(1, Order::count(), 'صفُّ المقبض القديم ما زال يردّ الآجل');
    }

    public function test_the_settings_screen_no_longer_writes_a_credit_switch(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['pay_credit' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertNull(Setting::where('business_id', $this->business->id)->where('key', 'pay_credit')->first(),
            'الإعداداتُ ما زالت تحفظ مقبضًا لا يُقرأ');

        $branch = \App\Models\Branch::firstOrCreate(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->activatePosDevice($this->business->id, $branch->id);
        $props = $this->actingAs($this->owner)->get(route('pos.index'))->viewData('page')['props']['settings'];
        $this->assertArrayNotHasKey('creditSale', $props, 'الصندوقُ ما زال يُسأل عن مقبضٍ محذوف');

        $this->assertFalse(method_exists(\App\Support\PaymentMethods::class, 'creditAllowedFor'));
    }
}

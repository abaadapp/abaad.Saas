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
            'business_id' => $this->business->id, 'name' => 'شركة ABC',
            'customer_type' => 'شركة', 'allow_credit_sales' => true, 'payment_terms_days' => 30,
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

    public function test_a_customer_without_permission_to_owe_is_refused(): void
    {
        $walkin = Customer::create(['business_id' => $this->business->id, 'name' => 'زبون مارّ']);

        $this->checkout(['credit' => true, 'customer_id' => $walkin->id])
            ->assertStatus(422)->assertJsonValidationErrors('credit');

        $this->assertSame(0, Order::count());
    }

    public function test_the_credit_limit_is_enforced(): void
    {
        $this->company->update(['credit_limit' => 60]);

        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])
            ->assertStatus(422)->assertJsonValidationErrors('credit');
    }

    public function test_the_limit_counts_what_is_already_owed(): void
    {
        $this->company->update(['credit_limit' => 150]);
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])->assertOk();

        // ١٠٠ عليه بالفعل، فلم يبقَ إلّا ٥٠ — والمئة الثانية تتجاوز
        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])
            ->assertStatus(422)->assertJsonValidationErrors('credit');
    }

    public function test_a_cashier_cannot_override_the_limit(): void
    {
        $this->company->update(['credit_limit' => 60]);

        $this->checkout([
            'credit' => true, 'customer_id' => $this->company->id,
            'credit_override_reason' => 'المدير وافق',
        ], $this->cashier)->assertStatus(422)->assertJsonValidationErrors('credit');
    }

    public function test_an_authorised_manager_overrides_with_a_reason(): void
    {
        $this->company->update(['credit_limit' => 60]);

        $this->checkout([
            'credit' => true, 'customer_id' => $this->company->id,
            'credit_override_reason' => 'فعالية وزارة — بعلم المالك',
        ])->assertOk();

        $this->assertSame(100.0, Ledger::balance($this->business->id, 'receivable'));
    }

    public function test_an_override_without_a_reason_is_still_refused(): void
    {
        // إذنٌ بلا سبب يجعل الحدَّ زينة: يُضغط الزرّ ولا يُعرف بعد شهرٍ لماذا
        $this->company->update(['credit_limit' => 60]);

        $this->checkout(['credit' => true, 'customer_id' => $this->company->id])
            ->assertStatus(422)->assertJsonValidationErrors('credit');
    }

    public function test_the_override_is_written_in_the_activity_log(): void
    {
        $this->company->update(['credit_limit' => 60]);
        $this->checkout([
            'credit' => true, 'customer_id' => $this->company->id,
            'credit_override_reason' => 'فعالية وزارة',
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'customer']);
        $this->assertStringContainsString('تجاوز حدَّ ائتمان', (string) DB::table('activity_logs')
            ->where('description', 'like', '%تجاوز%')->value('description'));
    }

    // ————— التعدّد المستأجَر —————

    public function test_a_customer_from_another_shop_cannot_be_charged(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create([
            'business_id' => $other->id, 'name' => 'عميلهم', 'allow_credit_sales' => true,
        ]);

        $this->checkout(['credit' => true, 'customer_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }
}

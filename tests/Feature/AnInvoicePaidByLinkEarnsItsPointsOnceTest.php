<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\InvoiceLoyalty;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * فاتورةُ عميلٍ سُدّدت برابط Paymob تُكسب صاحبَها نقاطَه — مرّةً واحدة.
 *
 * بقاعدة الصندوق نفسِها: مفتاحُ المتجر وباقتُه، و`floor(المبلغ × النسبة)`. وعلى
 * ما خُصِّص للفاتورة بعد إشعارٍ موقَّعٍ ثبت فيه المال — لا عند صفحة النجاح، ولا
 * على رصيدٍ بقي للعميل، ولا ثانيةً على طلبٍ كسب نقاطَه في البيع. والإلغاءُ يعكس
 * بحركة، وسقوطُ النقاط لا يمسّ المال ويُعاد. انظر `InvoiceLoyalty`.
 *
 * والتزامنُ الحقيقيّ — إشعاران في اللحظة نفسها من عمليّتين — في
 * `TwoPaymobNoticesAtOnceEarnOnePointsRowTest` على PostgreSQL.
 */
class AnInvoicePaidByLinkEarnsItsPointsOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private int $order = 616000000;

    private int $tx = 990000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الاختبار', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        foreach ([$this->shop, $this->other] as $b) {
            Ledger::seedChart($b->id);
            Setting::create(['business_id' => $b->id, 'key' => 'loyalty_enabled', 'value' => '1']);
            Setting::create(['business_id' => $b->id, 'key' => 'loyalty_earn_rate', 'value' => '5']);
        }

        Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@points.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الضيافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => PaymentGateway::TEST,
        ]);

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'amount_cents' => 0, 'shorten_url' => 'https://paymob.link/abc',
                'client_url' => 'https://oman.paymob.com/x', 'created_at' => '2026-10-25T10:00:00',
                'expires_at' => '2026-11-01T23:59:00', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** فاتورةٌ صادرةٌ بعشرة ريالات */
    private function invoice(float $price = 10, ?Customer $customer = null, ?Business $shop = null): CustomerInvoice
    {
        $shop ??= $this->shop;
        $invoice = CustomerInvoices::create($shop->id, $customer ?? $this->customer, ['issued_at' => '2026-10-25'], [
            ['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 0],
        ]);

        return CustomerInvoices::issue($invoice)->fresh();
    }

    private function link(?CustomerInvoice $invoice, ?float $amount = null): CustomerPaymentLink
    {
        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice, $amount, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();

        return $link->fresh();
    }

    private function obj(CustomerPaymentLink $link, array $over = []): array
    {
        return array_replace_recursive([
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-25T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => false,
        ], $over);
    }

    private function hook(array $obj): void
    {
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function pay(CustomerInvoice $invoice, ?float $amount = null): CustomerPaymentLink
    {
        $link = $this->link($invoice, $amount);
        $this->hook($this->obj($link));

        return $link->fresh();
    }

    private function points(?Customer $customer = null): int
    {
        return (int) ($customer ?? $this->customer)->fresh()->points;
    }

    /* ============================== الاكتساب ============================== */

    public function test_a_full_payment_earns_its_points_only_after_the_signed_notice(): void
    {
        $invoice = $this->invoice();
        $this->assertSame(10.0, (float) $invoice->total);
        $link = $this->link($invoice);

        // صفحةُ الدفع فُتحت ولم يصل إشعار: لا نقاط
        $this->assertSame(0, $this->points());

        $this->hook($this->obj($link));

        $this->assertSame(50, $this->points(), '١٠ ر.ع × ٥ = ٥٠ نقطة');
        $earn = PointTransaction::where('customer_id', $this->customer->id)->sole();
        $this->assertSame('earn', $earn->type);
        $this->assertSame(50, (int) $earn->points);
        $this->assertSame(50, (int) $earn->balance_after);
        $this->assertSame($invoice->id, (int) $earn->customer_invoice_id);
        $this->assertSame((int) $link->fresh()->customer_payment_id, (int) $earn->customer_payment_id);
        $this->assertSame('link:'.$link->id, $earn->source_key);
        $this->assertSame(InvoiceLoyalty::AWARDED, $link->fresh()->meta['loyalty']);

        // ويراها التاجرُ في الفاتورة وفي سجلّ العميل
        $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('loyalty.on', true)
                ->where('loyalty.rows.0.points', 50)
                ->where('loyalty.rows.0.balance_after', 50));
        $this->get(route('admin.customers.show', $this->customer->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pointHistory.0.points', 50)
                ->where('pointHistory.0.invoice.id', $invoice->id));
    }

    public function test_a_partial_payment_earns_on_what_it_paid(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, 4);

        $this->assertSame(20, $this->points());
    }

    public function test_two_partial_payments_earn_the_whole_once_without_losing_a_point_to_rounding(): void
    {
        $invoice = $this->invoice();
        $first = $this->pay($invoice, 3.333);
        $this->assertSame(16, $this->points(), '٣٫٣٣٣ × ٥ = ١٦٫٦٦٥ ← ١٦');

        $this->pay($invoice, 6.667);
        $this->assertSame(50, $this->points(), 'والمجموعُ خمسون كما لو سُدّدت مرّةً — لا ٤٩');

        // وإشعارُ الأولى يتكرّر: لا شيء
        $this->hook($this->obj($first, ['id' => (int) $first->provider_transaction_id]));
        $this->assertSame(50, $this->points());
        $this->assertSame(2, PointTransaction::where('customer_id', $this->customer->id)->count());
    }

    public function test_three_partial_payments_add_up_to_the_whole_and_no_more(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, 3.333);
        $this->assertSame(16, $this->points(), '٣٫٣٣٣ × ٥ = ١٦٫٦٦٥ ← ١٦');
        $this->pay($invoice, 3.333);
        $this->assertSame(33, $this->points(), '٦٫٦٦٦ × ٥ = ٣٣٫٣٣ ← ٣٣، لا ١٦ + ١٦');
        $this->pay($invoice, 3.334);
        $this->assertSame(50, $this->points(), 'والمجموعُ ١٠٫٠٠٠ × ٥ = ٥٠ بالضبط');

        $this->assertSame([16, 17, 17], PointTransaction::where('customer_id', $this->customer->id)->orderBy('id')->pluck('points')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(10.0, round($invoice->fresh()->paidTotal(), 3));
    }

    public function test_each_shop_earns_at_its_own_rate(): void
    {
        // متجرُ الجار يعطي نقطتين لكلّ ريال — والمتجرُ هنا خمسًا
        Setting::where('business_id', $this->other->id)->where('key', 'loyalty_earn_rate')->update(['value' => '2']);
        PaymentGateway::create([
            'business_id' => $this->other->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk2', 'secret_key' => 'sk2', 'hmac_secret' => 'hmac-other',
            'card_integration_id' => '444', 'api_key' => 'api2', 'mode' => PaymentGateway::TEST,
        ]);
        $theirCustomer = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '99000001']);
        $theirOwner = User::create([
            'business_id' => $this->other->id, 'name' => 'مالك الجار', 'email' => 'other@points.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->pay($this->invoice());

        $theirLink = PaymentLinks::create($this->other->id, $theirCustomer, $this->invoice(10, $theirCustomer, $this->other), null, null, $theirOwner->id);
        $theirLink->forceFill(['provider_order_id' => (string) (++$this->order)])->save();
        $obj = $this->obj($theirLink->fresh(), ['integration_id' => 444]);
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-other'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();

        $this->assertSame(50, $this->points(), '١٠ × ٥ هنا');
        $this->assertSame(20, $this->points($theirCustomer), '١٠ × ٢ عند الجار');
    }

    public function test_an_invoice_of_another_customer_in_the_same_shop_earns_no_one_anything(): void
    {
        $someoneElse = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميلٌ آخر', 'phone' => '91110000']);
        $hers = $this->invoice(10, $someoneElse);

        // لا يُنشأ رابطٌ لعميلٍ على فاتورة غيره أصلًا
        try {
            PaymentLinks::create($this->shop->id, $this->customer, $hers, null, null, $this->owner->id);
            $this->fail('أُنشئ رابطٌ لعميلٍ على فاتورة غيره');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ليست على هذا العميل', $e->getMessage());
        }

        // ورابطٌ عُبث بصفّه ليشير إليها: يردّه التحصيلُ نفسُه (`CustomerPayments`)، ولا نقاط
        $link = $this->link($this->invoice());
        $link->forceFill(['customer_invoice_id' => $hers->id])->save();
        $obj = $this->obj($link->fresh());
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj]);

        $this->assertSame(0, $this->points());
        $this->assertSame(0, $this->points($someoneElse));
        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(0.0, $hers->fresh()->paidTotal(), 'خُصِّص مالُ عميلٍ لفاتورة غيره');
    }

    public function test_a_declined_or_pending_payment_earns_nothing(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);

        $this->hook($this->obj($link, ['success' => false]));
        $this->hook($this->obj($link, ['pending' => true, 'success' => true]));

        $this->assertSame(0, $this->points());
        $this->assertSame(0, PointTransaction::count());
        $this->assertArrayNotHasKey('loyalty', (array) $link->fresh()->meta);
    }

    public function test_a_repeated_notice_earns_once(): void
    {
        $invoice = $this->invoice();
        $obj = $this->obj($this->link($invoice));

        $this->hook($obj);
        $this->hook($obj);
        $this->hook($obj);

        $this->assertSame(50, $this->points());
        $this->assertSame(1, PointTransaction::count());
        $this->assertSame(1, CustomerPayment::count());
    }

    public function test_an_existing_balance_is_added_to(): void
    {
        $this->customer->update(['points' => 120]);

        $this->pay($this->invoice());

        $this->assertSame(170, $this->points());
        $this->assertSame(170, (int) PointTransaction::sole()->balance_after);
    }

    /* ============================== ما لا يُكسب ============================== */

    public function test_a_shop_that_switched_loyalty_off_earns_nothing_and_says_nothing(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'loyalty_enabled')->update(['value' => '0']);
        $invoice = $this->invoice();

        $link = $this->pay($invoice);

        $this->assertSame(0, $this->points());
        $this->assertSame(InvoiceLoyalty::NONE, $link->meta['loyalty']);
        $this->assertNotNull(CustomerPayment::find($link->customer_payment_id), 'والمالُ سُجّل');
        $this->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('loyalty', null));
    }

    public function test_a_plan_without_loyalty_earns_nothing(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => ['invoices']]);
        $this->shop->update(['plan_id' => $plan->id]);

        $this->hook($this->obj($link));

        $this->assertSame(0, $this->points());
        $this->assertSame(InvoiceLoyalty::NONE, $link->fresh()->meta['loyalty']);

        // ولا يُقال لها «يكسب حين يسدّد» بعد أن أُطلق البرنامجُ لباقتها: سدّد ولم يكسب
        $this->shop->update(['plan_id' => null]);
        $this->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('loyalty', null));
        $this->get(route('admin.customerInvoices.show', $this->invoice()->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('loyalty.rows', [])->where('loyalty.waiting', false));
    }

    public function test_an_invoice_covering_a_till_sale_that_earned_its_points_earns_no_second_time(): void
    {
        $invoice = $this->invoice();
        $sale = Order::create(['business_id' => $this->shop->id, 'number' => 'S-0001', 'status' => OrderStatus::CONFIRMED,
            'total' => 10, 'subtotal' => 10, 'ordered_at' => now(), 'customer_id' => $this->customer->id, 'points_earned' => 50]);
        $invoice->orders()->attach($sale->id);

        $this->pay($invoice);

        $this->assertSame(0, $this->points());
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_only_the_share_not_earned_at_the_till_earns(): void
    {
        $invoice = $this->invoice();
        $earned = Order::create(['business_id' => $this->shop->id, 'number' => 'S-0002', 'status' => OrderStatus::CONFIRMED,
            'total' => 4, 'subtotal' => 4, 'ordered_at' => now(), 'customer_id' => $this->customer->id, 'points_earned' => 20]);
        $website = Order::create(['business_id' => $this->shop->id, 'number' => 'W-0003', 'status' => OrderStatus::CONFIRMED,
            'total' => 6, 'subtotal' => 6, 'ordered_at' => now(), 'customer_id' => $this->customer->id, 'points_earned' => 0]);
        $invoice->orders()->attach([$earned->id, $website->id]);

        $this->pay($invoice);

        $this->assertSame(30, $this->points(), 'الستّة التي لم تُكسب عليها نقاطٌ وحدها');
    }

    public function test_a_collection_link_without_an_invoice_earns_nothing(): void
    {
        $link = $this->pay($this->invoice(), null);
        $this->assertSame(50, $this->points());

        $free = $this->link(null, 10);
        $this->hook($this->obj($free));

        $this->assertSame(50, $this->points(), 'رصيدٌ للعميل لا شراء');
        $this->assertArrayNotHasKey('loyalty', (array) $free->fresh()->meta);
        $this->assertNotSame((int) $link->customer_payment_id, (int) $free->fresh()->customer_payment_id);
    }

    public function test_money_left_as_credit_on_a_paid_invoice_earns_nothing(): void
    {
        $invoice = $this->invoice();
        $late = $this->link($invoice);
        // سُدّدت قبل أن يدفع العميلُ الرابط: ماله كلُّه يبقى رصيدًا
        CustomerPayments::record($this->shop->id, $this->customer, 10, ['method' => 'نقدي'], [$invoice->id => 10], $this->owner->id);

        $this->hook($this->obj($late));

        $this->assertSame(0, $this->points());
        $this->assertSame(InvoiceLoyalty::NONE, $late->fresh()->meta['loyalty']);
    }

    public function test_another_shops_invoice_or_customer_never_earns_here(): void
    {
        $neighbour = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '99000000']);
        $theirs = $this->invoice(10, $neighbour, $this->other);
        $link = $this->link($this->invoice());
        // رابطٌ عُبث بصفّه ليشير إلى فاتورة متجرٍ آخر
        $link->forceFill(['customer_invoice_id' => $theirs->id])->save();

        $this->hook($this->obj($link->fresh()));

        $this->assertSame(0, $this->points());
        $this->assertSame(0, $this->points($neighbour));
        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(0.0, $theirs->fresh()->paidTotal(), 'ولا خُصِّص مالٌ لفاتورة الجار');
    }

    /* ============================== العكس والسقوط ============================== */

    public function test_cancelling_the_payment_reverses_its_points_once_and_never_below_zero(): void
    {
        $link = $this->pay($this->invoice());
        $this->assertSame(50, $this->points());

        // أنفق ثلاثين بين السداد والإلغاء
        $this->customer->update(['points' => 20]);
        $payment = CustomerPayment::findOrFail($link->customer_payment_id);
        CustomerPayments::cancel($payment, 'استرجاع', $this->owner->id);

        // الإلغاءُ نفسُه يعكس — لا نداءٌ آخر
        $this->assertSame(0, $this->points());
        $reverse = PointTransaction::where('type', 'redeem')->sole();
        $this->assertSame(-20, (int) $reverse->points, 'ما بقي عنده — لا تحت الصفر');
        $this->assertSame('link:'.$link->id.':reverse', $reverse->source_key);

        // ولا يُعكس ما عُكس
        CustomerPayments::cancel($payment->fresh(), 'مرّة ثانية', $this->owner->id);
        InvoiceLoyalty::reversePayment($payment->fresh());
        $this->assertSame(0, $this->points());
        $this->assertSame(2, PointTransaction::count(), 'حركةٌ وعكسُها — لا محو ولا ثالثة');

        // وإشعارٌ مكرّرٌ بعد الإلغاء لا يُعيدها
        $this->hook($this->obj($link, ['id' => (int) $link->provider_transaction_id]));
        $this->assertSame(0, $this->points());
    }

    public function test_cancelling_one_partial_payment_leaves_exactly_what_the_rest_earn_and_a_repayment_tops_up_to_the_whole(): void
    {
        /*
         * التراكمُ يوزّع كسورَ التقريب بترتيب الدفعات: ٣٫٣٣٣ ← ١٦ ثمّ ٦٫٦٦٧ ← ٣٤.
         * فإلغاءُ الأولى بعكس نقاطها وحدها (١٦) يُبقي ٣٤ — والسادسةُ وحدها
         * تستحقّ ٣٣. ثمّ سدادُها من جديد يعطي ١٧، فيصير المجموعُ ٥١ لفاتورةٍ
         * بعشرة ريالات. فالعكسُ يُبقي ما تستحقّه الدفعاتُ الباقية بالضبط.
         */
        $invoice = $this->invoice();
        $first = $this->pay($invoice, 3.333);
        $this->pay($invoice, 6.667);
        $this->assertSame(50, $this->points());

        CustomerPayments::cancel(CustomerPayment::findOrFail($first->customer_payment_id), 'خطأ في المبلغ', $this->owner->id);
        $this->assertSame(33, $this->points(), '٦٫٦٦٧ × ٥ = ٣٣٫٣٣ ← ٣٣، لا ٣٤');
        $this->assertSame(-17, (int) PointTransaction::where('type', 'redeem')->sole()->points);

        $this->pay($invoice, 3.333);
        $this->assertSame(50, $this->points(), 'والفاتورةُ مسدّدةً كلَّها تعطي خمسين — لا ٥١');
    }

    public function test_cancelling_the_later_partial_payment_works_the_same_way(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, 3.333);
        $second = $this->pay($invoice, 6.667);

        CustomerPayments::cancel(CustomerPayment::findOrFail($second->customer_payment_id), 'خطأ في المبلغ', $this->owner->id);
        $this->assertSame(16, $this->points(), '٣٫٣٣٣ × ٥ ← ١٦');

        $this->pay($invoice, 6.667);
        $this->assertSame(50, $this->points());
    }

    public function test_a_rate_changed_between_earning_and_cancelling_reverses_no_more_or_less_than_a_point_off_what_was_earned(): void
    {
        $invoice = $this->invoice();
        $first = $this->pay($invoice, 4);
        $this->pay($invoice, 6);
        $this->assertSame(50, $this->points(), '٢٠ + ٣٠');

        // ضاعف التاجرُ النسبةَ بعدها: الباقيةُ «تستحقّ» اليوم ٦٠ — والعكسُ لا يصير صفرًا
        Setting::where('business_id', $this->shop->id)->where('key', 'loyalty_earn_rate')->update(['value' => '10']);
        CustomerPayments::cancel(CustomerPayment::findOrFail($first->customer_payment_id), 'خطأ', $this->owner->id);

        $reversed = -(int) PointTransaction::where('type', 'redeem')->sole()->points;
        $this->assertGreaterThanOrEqual(19, $reversed, 'عُكس أقلّ ممّا كُسب بأكثر من كسر تقريب');
        $this->assertLessThanOrEqual(21, $reversed);
        $this->assertSame(50 - $reversed, $this->points());
    }

    public function test_a_link_paid_before_this_feature_earns_nothing_even_when_paymob_repeats(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $obj = $this->obj($link);
        Setting::where('business_id', $this->shop->id)->where('key', 'loyalty_enabled')->update(['value' => '0']);
        $this->hook($obj);
        // ما كتبه إصدارٌ قبل هذه الميزة: رابطٌ مدفوعٌ بلا علَم
        $old = $link->fresh();
        $old->forceFill(['meta' => array_diff_key((array) $old->meta, ['loyalty' => 1, 'loyalty_note' => 1])])->save();
        Setting::where('business_id', $this->shop->id)->where('key', 'loyalty_enabled')->update(['value' => '1']);

        $this->hook($obj);
        Artisan::call('customer-invoices:reconcile-loyalty');

        $this->assertSame(0, $this->points());
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_a_failure_after_the_money_keeps_the_payment_and_is_retried_once(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        PointTransaction::creating(fn () => throw new \RuntimeException('انقطع الاتّصال'));

        $this->hook($this->obj($link));

        $link = $link->fresh();
        $this->assertSame(CustomerPaymentLink::PAID, $link->status, 'المالُ سُجّل');
        $this->assertNotNull(CustomerPayment::find($link->customer_payment_id));
        $this->assertSame(InvoiceLoyalty::PENDING, $link->meta['loyalty']);
        $this->assertSame('انقطع الاتّصال', $link->meta['loyalty_error']);
        $this->assertSame(0, $this->points(), 'ولا نقاطَ نصفُ مكتوبة');

        PointTransaction::flushEventListeners();
        Artisan::call('customer-invoices:reconcile-loyalty');
        Artisan::call('customer-invoices:reconcile-loyalty');

        $this->assertSame(50, $this->points());
        $this->assertSame(1, PointTransaction::count());
        $this->assertArrayNotHasKey('loyalty_error', $link->fresh()->meta);
    }

    /* ============================== والتجهيز ============================== */

    public function test_a_prepared_invoice_reaches_the_board_once_and_earns_once(): void
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $invoice = $this->invoice();
        CustomerInvoicePreparations::save($invoice, [
            'enabled' => true, 'fulfillment_type' => 'pickup', 'branch_id' => Branch::where('business_id', $this->shop->id)->value('id'),
            'scheduled_for' => '2026-10-26 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
        ], $this->owner->id);
        $obj = $this->obj($this->link($invoice));

        $this->hook($obj);
        $first = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->value('activated_at');
        $this->hook($obj);

        $this->assertNotNull($first);
        $this->assertEquals($first, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->value('activated_at'));
        $this->assertSame(1, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame(50, $this->points());
        $this->assertSame(1, PointTransaction::count());
    }
}

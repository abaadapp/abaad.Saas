<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حساب المال في الفاتورة.
 *
 * CheckoutSecurityTest يحرس ما يُقبل ويُرفض؛ هذا يحرس **الأرقام نفسها**:
 * ترتيب الخصم قبل الضريبة، والتوصيل خارج الوعاء الضريبي، والكوبون نسبةً
 * ومبلغًا، وسقف الخصم، والتقريب.
 *
 * التقريب هنا ليس تفصيلًا: الريال العُماني ثلاث خانات عشرية (بيسة)، وخطأ
 * خانة واحدة في كل فاتورة يتراكم إلى فرق حقيقي في الصندوق آخر اليوم.
 */
class CheckoutMathTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الحساب', 'email' => 'math@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'كاشير',
            'email' => 'cashier@math.local',
            'password' => bcrypt('secret'),
            'role' => 'cashier',
            'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'صنف',
            'price' => 10,
            'cost' => 4,
            'quantity' => 100,
            'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->business->id);
    }

    private function sell(array $payload): Order
    {
        $this->actingAs($this->cashier)
            ->postJson('/pos/checkout', $payload + ['client_uuid' => uniqid('m', true), 'payment_method' => 'نقدي'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        return Order::latest('id')->firstOrFail();
    }

    private function line(int $qty = 1): array
    {
        return ['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty];
    }

    public function test_tax_is_five_percent_of_the_subtotal_when_there_is_no_discount(): void
    {
        $order = $this->sell(['items' => [$this->line(2)]]);

        $this->assertEqualsWithDelta(20.0, $order->subtotal, 0.0005);
        $this->assertEqualsWithDelta(1.0, $order->tax, 0.0005);   // 20 × 5%
        $this->assertEqualsWithDelta(21.0, $order->total, 0.0005);
    }

    public function test_tax_is_charged_after_the_discount_not_before(): void
    {
        Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'HALF', 'type' => 'نسبة', 'value' => 50, 'active' => true,
        ]);

        $order = $this->sell(['items' => [$this->line(2)], 'coupon_code' => 'HALF']);

        // 20 − 10 = 10 وعاءً ضريبيًا، لا 20
        $this->assertEqualsWithDelta(10.0, $order->discount, 0.0005);
        $this->assertEqualsWithDelta(0.5, $order->tax, 0.0005);
        $this->assertEqualsWithDelta(10.5, $order->total, 0.0005);
    }

    public function test_a_fixed_amount_coupon_subtracts_its_value(): void
    {
        Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'MINUS3', 'type' => 'مبلغ', 'value' => 3, 'active' => true,
        ]);

        $order = $this->sell(['items' => [$this->line(2)], 'coupon_code' => 'MINUS3']);

        $this->assertEqualsWithDelta(3.0, $order->discount, 0.0005);
        $this->assertEqualsWithDelta(0.85, $order->tax, 0.0005);   // (20 − 3) × 5%
        $this->assertEqualsWithDelta(17.85, $order->total, 0.0005);
    }

    public function test_delivery_is_added_after_tax_and_is_never_taxed(): void
    {
        $order = $this->sell(['items' => [$this->line(2)], 'delivery_fee' => 5]);

        // الضريبة على السلعة وحدها (20 × 5% = 1)، ثم يُضاف التوصيل كما هو
        $this->assertEqualsWithDelta(1.0, $order->tax, 0.0005);
        $this->assertEqualsWithDelta(26.0, $order->total, 0.0005);
    }

    public function test_a_discount_can_never_exceed_the_subtotal(): void
    {
        Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'HUGE', 'type' => 'مبلغ', 'value' => 999, 'active' => true,
        ]);

        $order = $this->sell(['items' => [$this->line(1)], 'coupon_code' => 'HUGE']);

        // بلا سقف يصبح المجموع سالبًا ويقيَّد "دخلًا" سالبًا في المالية
        $this->assertEqualsWithDelta(10.0, $order->discount, 0.0005);
        $this->assertEqualsWithDelta(0.0, $order->tax, 0.0005);
        $this->assertEqualsWithDelta(0.0, $order->total, 0.0005);
        $this->assertGreaterThanOrEqual(0, $order->total);
    }

    public function test_a_coupon_below_its_minimum_order_is_not_applied(): void
    {
        Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'BIG50', 'type' => 'مبلغ', 'value' => 5,
            'min_order' => 50, 'active' => true,
        ]);

        $order = $this->sell(['items' => [$this->line(2)], 'coupon_code' => 'BIG50']);

        $this->assertEqualsWithDelta(0.0, $order->discount, 0.0005);
        $this->assertNull($order->coupon_code);
        $this->assertEqualsWithDelta(21.0, $order->total, 0.0005);
    }

    public function test_an_expired_coupon_is_refused_at_checkout_not_only_at_apply(): void
    {
        Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'OLD', 'type' => 'نسبة', 'value' => 50,
            'expires_at' => now()->subDay(), 'active' => true,
        ]);

        // التحقّق عند زرّ «تطبيق» وحده لا يكفي: العميل قد يرسل الكود مباشرة
        $order = $this->sell(['items' => [$this->line(2)], 'coupon_code' => 'OLD']);

        $this->assertEqualsWithDelta(0.0, $order->discount, 0.0005);
        $this->assertEqualsWithDelta(21.0, $order->total, 0.0005);
    }

    public function test_a_used_up_coupon_stops_working(): void
    {
        $coupon = Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'ONCE', 'type' => 'مبلغ', 'value' => 2,
            'max_uses' => 1, 'used_count' => 0, 'active' => true,
        ]);

        $first = $this->sell(['items' => [$this->line(1)], 'coupon_code' => 'ONCE']);
        $this->assertEqualsWithDelta(2.0, $first->discount, 0.0005);
        $this->assertSame(1, $coupon->refresh()->used_count, 'عدّاد الاستخدام يجب أن يزيد');

        $second = $this->sell(['items' => [$this->line(1)], 'coupon_code' => 'ONCE']);
        $this->assertEqualsWithDelta(0.0, $second->discount, 0.0005);
        $this->assertSame(1, $coupon->refresh()->used_count, 'الاستخدام المرفوض لا يزيد العدّاد');
    }

    public function test_a_zero_vat_business_is_charged_no_tax(): void
    {
        Setting::where('business_id', $this->business->id)
            ->where('key', 'vat_rate')->update(['value' => '0']);

        $order = $this->sell(['items' => [$this->line(2)]]);

        $this->assertEqualsWithDelta(0.0, $order->tax, 0.0005);
        $this->assertEqualsWithDelta(20.0, $order->total, 0.0005);
    }

    public function test_totals_keep_three_decimals_for_the_omani_rial(): void
    {
        // 0.335 × 3 = 1.005 → ضريبة 0.05025 تُقرَّب إلى 0.050
        $fils = Product::create([
            'business_id' => $this->business->id,
            'name' => 'بيسة', 'price' => 0.335, 'cost' => 0.1, 'quantity' => 50, 'active' => true,
        ]);

        $order = $this->sell(['items' => [['id' => $fils->id, 'name' => $fils->name, 'qty' => 3]]]);

        $this->assertEqualsWithDelta(1.005, $order->subtotal, 0.0005);
        $this->assertEqualsWithDelta(0.05, $order->tax, 0.0005);
        $this->assertEqualsWithDelta(1.055, $order->total, 0.0005);
    }

    public function test_the_stored_total_equals_its_own_parts(): void
    {
        Coupon::create([
            'business_id' => $this->business->id,
            'code' => 'MIX', 'type' => 'نسبة', 'value' => 10, 'active' => true,
        ]);

        $order = $this->sell([
            'items' => [$this->line(3)],
            'coupon_code' => 'MIX',
            'delivery_fee' => 2.5,
        ]);

        // الفاتورة وثيقة: يجب أن تُجمع بنودها المخزّنة فتساوي إجماليها
        $recomputed = $order->subtotal - $order->discount + $order->tax + $order->delivery_fee;

        $this->assertEqualsWithDelta($recomputed, $order->total, 0.0005);
    }

    public function test_loyalty_redemption_lowers_the_taxable_amount_too(): void
    {
        Setting::insert([
            ['business_id' => $this->business->id, 'key' => 'loyalty_enabled', 'value' => '1'],
            ['business_id' => $this->business->id, 'key' => 'redeem_max_pct', 'value' => '100'],
            ['business_id' => $this->business->id, 'key' => 'redeem_min', 'value' => '0'],
        ]);

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'عميل وفيّ', 'points' => 500,
        ]);

        $order = $this->sell([
            'items' => [$this->line(2)],
            'customer' => $customer->name,
            'redeem_points' => 500,
        ]);

        // 500 نقطة = 5 ر.ع خصمًا، فالوعاء الضريبي 15 لا 20
        $this->assertEqualsWithDelta(5.0, $order->discount, 0.0005);
        $this->assertEqualsWithDelta(0.75, $order->tax, 0.0005);
        $this->assertEqualsWithDelta(15.75, $order->total, 0.0005);

        // الرصيد بعدها ليس صفرًا: البيعة نفسها تكسب نقاطًا جديدة. فنتحقّق من
        // قيد الصرف تحديدًا لا من الرصيد، وإلا اختلط الصرف بالكسب.
        $this->assertDatabaseHas('point_transactions', [
            'customer_id' => $customer->id,
            'type' => 'redeem',
            'points' => -500,
        ]);
        $this->assertLessThan(500, (int) $customer->refresh()->points, 'النقاط المصروفة تُخصم فعلًا');
    }
}

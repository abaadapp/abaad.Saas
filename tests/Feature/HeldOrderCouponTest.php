<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكوبون يجب أن ينجو من التعليق والاستكمال.
 *
 * كان `hold` لا يحفظ الكود و`resume` لا يعيده: الكاشير يطبّق خصم 10%،
 * يعلّق الطلب لينادي الزبون التالي، ثم يستكمله فيدفع الزبون السعر كاملًا.
 * لا خطأ يظهر ولا شيء ينبّه — فرق صامت في كل فاتورة معلّقة.
 *
 * ويُحفظ الكود وحده لا قيمة الخصم: الطلب قد يُستكمل غدًا وقد يكون
 * الكوبون انتهى، فالتحقق يقع وقت الدفع.
 */
class HeldOrderCouponTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'كاشير', 'email' => 'cashier@hold.local',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'صنف', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 5, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'business_id' => $this->business->id,
            'code' => 'SAVE10', 'type' => 'نسبة', 'value' => 10, 'active' => true,
        ], $attrs));
    }

    private function hold(?string $code): Order
    {
        $this->actingAs($this->cashier)->postJson('/pos/hold', [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 2]],
            'customer' => 'عميل نقدي',
            'coupon_code' => $code,
            'kind' => 'hold',
        ])->assertOk();

        return Order::where('is_held', true)->latest('id')->firstOrFail();
    }

    public function test_holding_an_order_remembers_its_coupon(): void
    {
        $this->coupon();
        $held = $this->hold('SAVE10');

        $this->assertSame('SAVE10', $held->coupon_code);
    }

    public function test_resuming_returns_the_coupon_to_the_cart(): void
    {
        $this->coupon();
        $held = $this->hold('SAVE10');

        $this->actingAs($this->cashier)
            ->get(route('pos.orders.resume', $held->id))
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('resume_cart.coupon_code', 'SAVE10');
    }

    public function test_the_discount_survives_hold_then_checkout(): void
    {
        $this->coupon();
        $held = $this->hold('SAVE10');

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 2]],
            'resume_id' => $held->id,
            'coupon_code' => 'SAVE10',
            'client_uuid' => 'hold-test-1',
        ])->assertOk();

        $sale = Order::where('is_held', false)->latest('id')->firstOrFail();

        // 20 − 2 = 18 وعاءً ضريبيًا → ضريبة 0.9 → إجمالي 18.9
        $this->assertEqualsWithDelta(2.0, $sale->discount, 0.0005, 'الخصم ضاع بين التعليق والدفع');
        $this->assertEqualsWithDelta(0.9, $sale->tax, 0.0005);
        $this->assertEqualsWithDelta(18.9, $sale->total, 0.0005);
        $this->assertSame('SAVE10', $sale->coupon_code);
    }

    public function test_only_the_code_is_stored_so_an_expired_coupon_stops_applying(): void
    {
        $coupon = $this->coupon();
        $held = $this->hold('SAVE10');

        // بين التعليق والاستكمال انتهت صلاحيته
        $coupon->update(['expires_at' => now()->subDay()]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 2]],
            'resume_id' => $held->id,
            'coupon_code' => 'SAVE10',
            'client_uuid' => 'hold-test-2',
        ])->assertOk();

        $sale = Order::where('is_held', false)->latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(0.0, $sale->discount, 0.0005, 'كوبون منتهٍ لا يُطبَّق ولو كان محفوظًا');
        $this->assertEqualsWithDelta(21.0, $sale->total, 0.0005);
    }

    public function test_an_order_held_without_a_coupon_stays_without_one(): void
    {
        $held = $this->hold(null);

        $this->assertNull($held->coupon_code);
    }
}

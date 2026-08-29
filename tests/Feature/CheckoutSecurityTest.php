<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * يحرس نقطة البيع ضد ما ثبت أنه كان يمرّ:
 * سعر من العميل، بيع بما يفوق المخزون، أرقام فواتير متصادمة، وكتابة نصفية.
 */
class CheckoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الفحص', 'email' => 'shop@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'كاشير الفحص',
            'email' => 'cashier@test.local',
            'password' => bcrypt('secret'),
            'role' => 'cashier',
            'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'باقة اختبار',
            'price' => 12.5,
            'cost' => 5,
            'quantity' => 10,
            'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->business->id);
    }

    private function sell(array $payload)
    {
        return $this->actingAs($this->cashier)
            ->postJson('/pos/checkout', $payload + ['client_uuid' => uniqid('t', true), 'payment_method' => 'نقدي']);
    }

    public function test_it_ignores_the_price_sent_by_the_client(): void
    {
        // 0.001 بدل 12.500 — كان يمرّ ويبيع المنتج فعليًا بمليم واحد
        $res = $this->sell(['items' => [['id' => $this->product->id, 'name' => 'x', 'price' => 0.001, 'qty' => 1]]]);

        $res->assertOk();
        $order = Order::latest('id')->first();

        $this->assertSame(12.5, (float) $order->subtotal, 'المجموع الفرعي يجب أن يُحتسب من سعر القاعدة');
        $this->assertSame(13.125, (float) $order->total, '12.500 + ضريبة 5%');
        $this->assertSame(12.5, (float) $order->items->first()->price);
    }

    public function test_it_rejects_a_negative_price_instead_of_booking_negative_income(): void
    {
        // كان يقيّد "دخلًا" بـ-50 في المالية
        $this->sell(['items' => [['id' => $this->product->id, 'name' => 'x', 'price' => -50, 'qty' => 1]]])->assertOk();

        $this->assertSame(0, Transaction::where('amount', '<', 0)->count());
        $this->assertGreaterThan(0, (float) Transaction::latest('id')->first()->amount);
    }

    public function test_it_ignores_a_forged_total_and_discount(): void
    {
        $res = $this->sell([
            'items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 2]],
            'total' => 0.001,
            'discount' => 9999,
            'tax' => 0,
        ]);

        $res->assertOk();
        $order = Order::latest('id')->first();
        $this->assertSame(25.0, (float) $order->subtotal);
        $this->assertSame(0.0, (float) $order->discount, 'خصم بلا كوبون ولا نقاط يجب أن يكون صفرًا');
        $this->assertSame(26.25, (float) $order->total);
    }

    public function test_it_refuses_to_sell_more_than_is_in_stock(): void
    {
        $res = $this->sell(['items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 99999]]]);

        $res->assertStatus(422);
        $this->assertSame(10, (int) $this->product->fresh()->quantity, 'المخزون يجب ألّا يتحرّك');
        $this->assertSame(0, Order::where('is_held', false)->count());
    }

    public function test_it_sums_quantities_of_the_same_product_across_lines(): void
    {
        // 6 + 6 = 12 وهي أكثر من 10 المتاحة، رغم أن كل بند وحده مقبول
        $res = $this->sell(['items' => [
            ['id' => $this->product->id, 'name' => 'x', 'qty' => 6],
            ['id' => $this->product->id, 'name' => 'x', 'qty' => 6],
        ]]);

        $res->assertStatus(422);
        $this->assertSame(10, (int) $this->product->fresh()->quantity);
    }

    public function test_it_rejects_a_product_from_another_business(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'منتج غيرنا', 'price' => 99,
            'quantity' => 50, 'active' => true,
        ]);

        $this->sell(['items' => [['id' => $theirs->id, 'name' => 'x', 'qty' => 1]]])->assertStatus(422);
        $this->assertSame(50, (int) $theirs->fresh()->quantity);
    }

    public function test_it_rejects_an_invented_line_item(): void
    {
        $this->sell(['items' => [['id' => null, 'name' => 'صنف لا وجود له', 'price' => 999, 'qty' => 1]]])
            ->assertStatus(422);
    }

    public function test_it_prices_addons_from_the_database(): void
    {
        $addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'بطاقة إهداء',
            'price' => 1.5, 'icon' => '🎁', 'active' => true,
        ]);

        $this->sell(['items' => [['id' => null, 'addon_id' => $addon->id, 'name' => 'بطاقة إهداء', 'price' => 0.001, 'qty' => 2]]])
            ->assertOk();

        $this->assertSame(3.0, (float) Order::latest('id')->first()->subtotal);
    }

    public function test_invoice_numbers_are_sequential_and_unique(): void
    {
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->sell(['items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 1]]])
                ->assertOk()->json('invoice');
        }

        $this->assertSame($numbers, array_unique($numbers), 'لا يجوز تكرار رقم فاتورة');
        $this->assertSame(['INV-000001', 'INV-000002', 'INV-000003', 'INV-000004', 'INV-000005'], $numbers);
    }

    public function test_a_rejected_sale_writes_nothing_at_all(): void
    {
        $before = [
            'orders' => Order::count(),
            'transactions' => Transaction::count(),
            'movements' => \App\Models\InventoryMovement::count(),
        ];

        $this->sell(['items' => [
            ['id' => $this->product->id, 'name' => 'x', 'qty' => 1],   // بند سليم
            ['id' => 999999, 'name' => 'وهمي', 'qty' => 1],            // بند يُسقط الطلب
        ]])->assertStatus(422);

        $this->assertSame($before['orders'], Order::count(), 'لا طلب جزئي');
        $this->assertSame($before['transactions'], Transaction::count(), 'لا معاملة يتيمة');
        $this->assertSame($before['movements'], \App\Models\InventoryMovement::count(), 'لا حركة مخزون');
        $this->assertSame(10, (int) $this->product->fresh()->quantity);
    }

    public function test_a_successful_sale_writes_every_side_effect(): void
    {
        $this->sell(['items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 3]]])->assertOk();

        $order = Order::latest('id')->first();
        $this->assertSame(7, (int) $this->product->fresh()->quantity, '10 − 3');
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
        $this->assertSame(1, \App\Models\InventoryMovement::where('product_id', $this->product->id)->count());
    }

    /**
     * بيعةٌ بلا وسيلة دفعٍ تُرفض ولا تُخمَّن.
     *
     * كانت تُردّ إلى أوّل المأذون بصمت، فتُقيَّد نقدًا وقد دُفعت بالبطاقة —
     * ويظهر الأثر عند إقفال الوردية: عجزٌ في الدرج لم يُحدثه الكاشير. وهو
     * أسوأ صنف من العطب لأنّ كلّ ما يُرى منه سليم.
     */
    public function test_a_sale_without_a_payment_method_is_refused(): void
    {
        // بلا مرور بـ`sell` — هي تضع الوسيلة، والمقصود غيابُها
        $this->actingAs($this->cashier)
            ->postJson('/pos/checkout', [
                'items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 1]],
                'client_uuid' => 'no-method-1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::where('is_held', false)->count());
    }

    public function test_resubmitting_the_same_client_uuid_does_not_duplicate_the_sale(): void
    {
        $payload = [
            'items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 1]],
            'client_uuid' => 'offline-outbox-1',
            'payment_method' => 'نقدي',
        ];

        $first = $this->actingAs($this->cashier)->postJson('/pos/checkout', $payload)->assertOk();
        $again = $this->actingAs($this->cashier)->postJson('/pos/checkout', $payload)->assertOk();

        $this->assertSame($first->json('invoice'), $again->json('invoice'));
        $this->assertTrue($again->json('duplicate'));
        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(9, (int) $this->product->fresh()->quantity, 'الخصم مرّة واحدة فقط');
    }

    public function test_loyalty_redemption_cannot_exceed_its_cap(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'عميل مخلص',
            'phone' => '96890000000', 'points' => 100000,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'loyalty_redeem_max_pct', 'value' => '50']);

        // يطلب استبدال 100,000 نقطة (=1000 ر.ع) على فاتورة 12.5 ر.ع
        $this->sell([
            'items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 1]],
            'customer' => $customer->name,
            'redeem_points' => 100000,
        ])->assertOk();

        $order = Order::latest('id')->first();
        $this->assertLessThanOrEqual(6.25, (float) $order->discount, 'السقف 50% من 12.500');
        $this->assertGreaterThanOrEqual(0, (float) $order->total, 'الإجمالي لا يصير سالبًا');
    }

    public function test_it_allows_overselling_only_when_the_business_opts_in(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'allow_negative_stock', 'value' => '1']);

        $this->sell(['items' => [['id' => $this->product->id, 'name' => 'x', 'qty' => 15]]])->assertOk();
        $this->assertSame(-5, (int) $this->product->fresh()->quantity);
    }
}

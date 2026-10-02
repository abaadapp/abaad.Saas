<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحةُ التجهيز لا تعرض إلّا ما يناسب الطلب ومن يقف أمامه.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) الإلغاءُ من اللوحة إلغاءٌ كامل (مخزونٌ ونقاطٌ وقيد) — فهو لمن مُنح
 *    `preparation.cancel`: المالكُ والمديرُ بدورهما، ومن سواهما باسمه. والزرُّ
 *    يغيب عمّن لا يملكه، والبابُ يردّه ولو وصله الطلبُ من غير الزرّ.
 * ٢) الحالُ تناسب نوعَ الطلب: الاستلامُ لا يخرج للتوصيل ولا يُسلَّم بسائق،
 *    والتوصيلُ لا «يُستلم من المحل». وما لا نوعَ له يبقى كما كان.
 * ٣) هاتفُ صاحب الطلب يصل البطاقة — من سجلّ العميل.
 */
class ThePrepBoardOffersOnlyWhatFitsTheOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private User $florist;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = $this->user('admin', 'o@abaad.om');
        // دورُ المخزون يملك «لوحة التجهيز» بدوره — ولا يملك الإلغاء منها
        $this->florist = $this->user('inventory', 'f@abaad.om');
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);
    }

    private function user(string $role, string $email, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => $role, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::READY, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 25,
        ]);

        return $order;
    }

    private function card(User $who, Order $order): array
    {
        $cards = $this->actingAs($who)->get(route('admin.preparation.index'))
            ->assertOk()->viewData('page')['props']['orders'];

        $card = collect($cards)->firstWhere('number', $order->number);
        $this->assertNotNull($card, 'الطلبُ ليس على اللوحة');

        return $card;
    }

    private function move(User $who, Order $order, string $to)
    {
        return $this->actingAs($who)->post(route('admin.preparation.move', $order->number), ['status' => $to]);
    }

    /* ============================ الإلغاء ============================ */

    public function test_the_cancel_button_is_for_whoever_was_granted_it(): void
    {
        $order = $this->order();

        $this->assertContains(OrderStatus::CANCELLED, $this->card($this->owner, $order)['next']);
        $this->assertContains(OrderStatus::CANCELLED, $this->card($this->user('manager', 'm@abaad.om'), $order)['next']);
        $this->assertNotContains(OrderStatus::CANCELLED, $this->card($this->florist, $order)['next']);

        // والباقي يبقى له: يجهّز ويُسلّم
        $this->assertContains(OrderStatus::PREPARING, $this->card($this->florist, $order)['next']);
    }

    public function test_the_door_refuses_a_cancel_that_did_not_come_from_a_button(): void
    {
        $order = $this->order();

        $this->move($this->florist, $order, OrderStatus::CANCELLED)
            ->assertSessionHas('toast.type', 'danger');

        $this->assertSame(OrderStatus::READY, $order->fresh()->status, 'أُلغي الطلبُ بلا صلاحيّة');
    }

    public function test_the_owner_still_cancels_from_the_board(): void
    {
        $order = $this->order();

        $this->move($this->owner, $order, OrderStatus::CANCELLED)->assertSessionHas('toast.type', 'success');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_florist_granted_it_by_name_may_cancel(): void
    {
        $granted = $this->user('inventory', 'g@abaad.om', ['dashboard', 'preparation', Permissions::PREPARATION_CANCEL]);
        $order = $this->order();

        $this->assertContains(OrderStatus::CANCELLED, $this->card($granted, $order)['next']);
        $this->move($granted, $order, OrderStatus::CANCELLED)->assertSessionHas('toast.type', 'success');
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_the_action_is_offered_in_the_employee_permissions_screen(): void
    {
        $this->assertArrayHasKey(Permissions::PREPARATION_CANCEL, Permissions::actionLabels());
        $this->assertTrue(Permissions::allowsAction('admin', Permissions::PREPARATION_CANCEL));
        $this->assertTrue(Permissions::allowsAction('manager', Permissions::PREPARATION_CANCEL));
        foreach (['inventory', 'sales', 'delivery', 'cashier'] as $role) {
            $this->assertFalse(Permissions::allowsAction($role, Permissions::PREPARATION_CANCEL), $role);
        }
    }

    /* ============================ الحال ونوع الطلب ============================ */

    public function test_a_pickup_order_is_not_sent_out_for_delivery(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::PICKUP]);

        $next = $this->card($this->owner, $order)['next'];
        $this->assertContains(OrderStatus::PICKED_UP, $next);
        $this->assertNotContains(OrderStatus::OUT_FOR_DELIVERY, $next);

        $this->move($this->owner, $order, OrderStatus::OUT_FOR_DELIVERY)->assertSessionHas('toast.type', 'danger');
        $this->assertSame(OrderStatus::READY, $order->fresh()->status);

        $this->move($this->owner, $order, OrderStatus::PICKED_UP)->assertSessionHas('toast.type', 'success');
        $this->assertSame(OrderStatus::PICKED_UP, $order->fresh()->status);
    }

    public function test_a_pickup_order_never_reads_delivered_or_failed_delivery(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::PICKUP, 'status' => OrderStatus::OUT_FOR_DELIVERY]);

        $next = $this->card($this->owner, $order)['next'];
        $this->assertNotContains(OrderStatus::DELIVERED, $next);
        $this->assertNotContains(OrderStatus::DELIVERY_FAILED, $next);

        $this->move($this->owner, $order, OrderStatus::DELIVERED)->assertSessionHas('toast.type', 'danger');
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, $order->fresh()->status);
    }

    public function test_a_delivery_order_is_not_picked_up_at_the_shop(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::DELIVERY]);

        $next = $this->card($this->owner, $order)['next'];
        $this->assertContains(OrderStatus::OUT_FOR_DELIVERY, $next);
        $this->assertNotContains(OrderStatus::PICKED_UP, $next);

        $this->move($this->owner, $order, OrderStatus::PICKED_UP)->assertSessionHas('toast.type', 'danger');
        $this->assertSame(OrderStatus::READY, $order->fresh()->status);
    }

    public function test_an_order_without_a_type_keeps_every_transition(): void
    {
        $order = $this->order(['fulfillment_type' => null]);

        $this->assertSame(OrderStatus::nextFrom(OrderStatus::READY), $this->card($this->owner, $order)['next']);
    }

    /* ============================ الهاتف ============================ */

    public function test_the_card_carries_the_customers_phone(): void
    {
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سارة', 'phone' => '96891234567']);
        $order = $this->order(['customer_id' => $customer->id, 'customer_name' => 'سارة']);

        $this->assertSame('96891234567', $this->card($this->owner, $order)['customer_phone']);
        $this->assertNull($this->card($this->owner, $this->order())['customer_phone'], 'عميلٌ نقديّ بلا سجلّ');
    }
}

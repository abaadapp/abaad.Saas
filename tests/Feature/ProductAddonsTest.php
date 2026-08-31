<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\ProductAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإضافات — من إضافةٍ في المتجر إلى إضافةٍ على المنتج وعلى البند.
 *
 * وأهمّ ما يُحرس هنا التوافق: قبل هذا كانت كلّ إضافات المتجر تظهر مع كلّ
 * منتج، وتُباع بندًا مستقلًّا في السلّة. والاثنان يجب أن يظلّا يعملان — ومن
 * لم يربط شيئًا بعد لا يجوز أن تختفي إضافاته لحظة الترقية.
 */
class ProductAddonsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $bouquet;
    private Product $chocolateStock;
    private Addon $chocolate;
    private Addon $wrapService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الإضافات', 'email' => 'a@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@a.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@a.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه',
            'price' => 18, 'cost' => 6, 'quantity' => 50, 'active' => true,
        ]);

        $this->chocolateStock = Product::create([
            'business_id' => $this->business->id, 'name' => 'شوكولاتة (مخزون)',
            'price' => 4, 'cost' => 1.5, 'quantity' => 30, 'active' => true,
        ]);

        $this->chocolate = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شوكولاتة', 'price' => 4,
            'active' => true, 'inventory_product_id' => $this->chocolateStock->id,
        ]);

        $this->wrapService = Addon::create([
            'business_id' => $this->business->id, 'name' => 'تغليف فاخر', 'price' => 1, 'active' => true,
        ]);

        $this->openShiftFor($this->business->id);
    }

    private function sell(array $items, int $expect = 200): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => $items,
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('a', true),
        ])->assertStatus($expect);
    }

    private function allow(array $addonIds): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => $addonIds])
            ->assertSessionHasNoErrors();
    }

    /* ------------------------------ التوافق ------------------------------ */

    public function test_a_standalone_addon_line_still_sells_as_it_always_did(): void
    {
        $this->sell([[
            'addon_id' => $this->wrapService->id, 'name' => 'تغليف فاخر', 'qty' => 2,
        ]]);

        $order = Order::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(2.0, (float) $order->subtotal, 0.0005);
        $this->assertNull($order->items->first()->product_id);
    }

    public function test_a_product_with_no_links_still_offers_every_shop_addon(): void
    {
        $allowed = ProductAddons::for($this->bouquet);

        $this->assertCount(2, $allowed);
    }

    public function test_linking_some_addons_narrows_the_list_to_those(): void
    {
        $this->allow([$this->chocolate->id]);

        $allowed = ProductAddons::for($this->bouquet->fresh());

        $this->assertCount(1, $allowed);
        $this->assertSame($this->chocolate->id, $allowed->first()->id);
    }

    public function test_a_switched_off_addon_is_never_offered(): void
    {
        $this->wrapService->update(['active' => false]);

        $this->assertCount(1, ProductAddons::for($this->bouquet));
    }

    /* ------------------------------- الحراسة ------------------------------ */

    public function test_an_addon_the_product_does_not_allow_is_refused_by_the_server(): void
    {
        $this->allow([$this->chocolate->id]);

        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->wrapService->id, 'qty' => 1]],
        ]], 422)->assertJsonValidationErrors('items.0.addons');

        $this->assertSame(0, Order::count());
    }

    public function test_an_addon_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@a.local', 'status' => 'نشط']);
        $theirs = Addon::create(['business_id' => $other->id, 'name' => 'بالونهم', 'price' => 2, 'active' => true]);

        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $theirs->id, 'qty' => 1]],
        ]], 422)->assertJsonValidationErrors('items.0.addons');
    }

    public function test_a_shop_cannot_link_another_shops_addon(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o2@a.local', 'status' => 'نشط']);
        $theirs = Addon::create(['business_id' => $other->id, 'name' => 'بالونهم', 'price' => 2, 'active' => true]);

        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => [$theirs->id]])
            ->assertSessionHasErrors('addon_ids.0');

        $this->assertDatabaseCount('product_addons', 0);
    }

    public function test_the_price_comes_from_the_database_not_from_the_request(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1, 'price' => 999,
            'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 1, 'price' => 0.001]],
        ]]);

        $addon = Order::latest('id')->firstOrFail()->items->first()->addons->first();

        $this->assertEqualsWithDelta(4.0, (float) $addon->unit_price, 0.0005);
    }

    /* ------------------------------- الحساب ------------------------------- */

    public function test_the_addon_rides_on_its_item_and_the_totals_include_it(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [
                ['addon_id' => $this->chocolate->id, 'qty' => 1],
                ['addon_id' => $this->wrapService->id, 'qty' => 2],
            ],
        ]]);

        $order = Order::latest('id')->firstOrFail();
        $item = $order->items->first();

        $this->assertCount(2, $item->addons);
        $this->assertEqualsWithDelta(6.0, (float) $item->addons_total, 0.0005);   // 4 + 2
        $this->assertEqualsWithDelta(18.0, (float) $item->total, 0.0005);
        $this->assertEqualsWithDelta(24.0, (float) $item->lineTotal(), 0.0005);
        $this->assertEqualsWithDelta(24.0, (float) $order->subtotal, 0.0005);
    }

    public function test_a_quantity_of_two_multiplies_only_the_addon_line(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->wrapService->id, 'qty' => 3]],
        ]]);

        $addon = Order::latest('id')->firstOrFail()->items->first()->addons->first();

        $this->assertSame(3, (int) $addon->quantity);
        $this->assertEqualsWithDelta(3.0, (float) $addon->total, 0.0005);
    }

    /* ------------------------------- اللقطة ------------------------------- */

    public function test_raising_the_addon_price_later_leaves_the_old_invoice_alone(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 1]],
        ]]);

        $this->chocolate->update(['price' => 5, 'name' => 'شوكولاتة بلجيكية']);

        $addon = Order::latest('id')->firstOrFail()->items()->first()->addons()->first();

        $this->assertEqualsWithDelta(4.0, (float) $addon->unit_price, 0.0005);
        $this->assertSame('شوكولاتة', $addon->name);
    }

    public function test_deleting_the_addon_does_not_erase_it_from_history(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 1]],
        ]]);

        $this->chocolate->delete();

        $addon = Order::latest('id')->firstOrFail()->items()->first()->addons()->first();

        $this->assertNotNull($addon);
        $this->assertSame('شوكولاتة', $addon->name);
        $this->assertNull($addon->addon_id);
    }

    /* ------------------------------- المخزون ------------------------------ */

    public function test_an_addon_tied_to_stock_takes_it_off_the_shelf(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 2]],
        ]]);

        $this->assertSame(28, (int) $this->chocolateStock->fresh()->quantity);
    }

    public function test_a_service_addon_needs_no_stock_and_moves_none(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->wrapService->id, 'qty' => 1]],
        ]]);

        $order = Order::latest('id')->firstOrFail();

        $this->assertNull($order->items->first()->addons->first()->cost);
        $this->assertSame(0, \App\Models\InventoryMovement::where('type', \App\Support\StockLedger::ADDON)->count());
    }

    public function test_the_cost_snapshot_follows_the_stock_item(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 1]],
        ]]);

        $addon = Order::latest('id')->firstOrFail()->items->first()->addons->first();
        $this->chocolateStock->update(['cost' => 9]);

        $this->assertEqualsWithDelta(1.5, (float) $addon->fresh()->cost, 0.0005);
    }

    public function test_deleting_the_line_gives_the_addon_stock_back_once(): void
    {
        $this->sell([
            [
                'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
                'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 2]],
            ],
            ['id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1],
        ]);

        $this->assertSame(28, (int) $this->chocolateStock->fresh()->quantity);

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $order->items->first(), 0, 'ألغى الزبون');

        $this->assertSame(30, (int) $this->chocolateStock->fresh()->quantity);
    }

    public function test_removing_a_line_recomputes_the_order_without_its_addons(): void
    {
        $this->sell([
            [
                'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
                'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 1]],
            ],
            ['id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1],
        ]);

        $order = Order::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(40.0, (float) $order->subtotal, 0.0005);   // 18+4 + 18

        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $order->items->first(), 0, 'ألغى الزبون');

        $this->assertEqualsWithDelta(18.0, (float) $order->fresh()->subtotal, 0.0005);
    }

    public function test_the_receipt_shows_the_addons_under_their_item(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $this->chocolate->id, 'qty' => 1]],
        ]]);

        $order = Order::latest('id')->firstOrFail();
        $details = $this->actingAs($this->cashier)->withSession(['pos_cashier_id' => $this->cashier->id])
            ->get(route('pos.order-details', $order->number))->assertOk();

        $details->assertInertia(fn ($page) => $page
            ->where('order.items.0.addons.0.name', 'شوكولاتة')
            ->where('order.items.0.addons.0.qty', 1));
    }
}

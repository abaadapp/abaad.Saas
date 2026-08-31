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

    /* ==================== الإضافة الخاصّة بمنتجٍ واحد ==================== */

    /**
     * «شريط ذهبي» صُنع لباقة الورد فلا يُعرض مع كيس السماد.
     *
     * والربط وحده لم يكن يكفي: غياب الربط عن كيس السماد معناه «كلّ إضافات
     * المتجر»، فكلّ إضافةٍ جديدةٍ كانت تنهال على كلّ منتجٍ لم يُربط له شيء —
     * وهم أكثر المنتجات.
     */
    public function test_a_private_addon_is_offered_only_with_its_own_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $fertiliser = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس سماد',
            'price' => 2, 'cost' => 1, 'quantity' => 20, 'active' => true,
        ]);

        $this->assertTrue(ProductAddons::for($this->bouquet)->contains('id', $ribbon->id));
        $this->assertFalse(ProductAddons::for($fertiliser)->contains('id', $ribbon->id));

        // وإضافةُ المتجر تبقى للجميع كما كانت
        $this->assertTrue(ProductAddons::for($fertiliser)->contains('id', $this->wrapService->id));
    }

    /** ولا تُباع مع سواه ولو حمل الطلب معرّفها */
    public function test_a_private_addon_is_refused_on_another_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $fertiliser = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس سماد',
            'price' => 2, 'cost' => 1, 'quantity' => 20, 'active' => true,
        ]);

        $this->sell([[
            'id' => $fertiliser->id, 'name' => 'كيس سماد', 'qty' => 1,
            'addons' => [['addon_id' => $ribbon->id, 'qty' => 1]],
        ]], 422)->assertJsonValidationErrors('items.0.addons');

        // ومع صاحبها تمرّ
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $ribbon->id, 'qty' => 1]],
        ]]);
    }

    /** وربطُها بمنتجٍ آخر يُردّ في بابه لا في نقطة البيع وحدها */
    public function test_another_products_addon_cannot_be_linked_here(): void
    {
        $chocolateBox = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة شوكولاتة',
            'price' => 6, 'cost' => 3, 'quantity' => 10, 'active' => true,
        ]);

        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $chocolateBox->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => [$ribbon->id]])
            ->assertSessionHasErrors('addon_ids.0');

        $this->assertDatabaseCount('product_addons', 0);
    }

    /** والشاشة لا تعرض على منتجٍ إضافاتِ جيرانه أصلًا */
    public function test_the_product_screen_hides_another_products_addon(): void
    {
        $chocolateBox = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة شوكولاتة',
            'price' => 6, 'cost' => 3, 'quantity' => 10, 'active' => true,
        ]);

        Addon::create([
            'business_id' => $this->business->id, 'product_id' => $chocolateBox->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $mine = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'بطاقة معايدة', 'price' => 0.3, 'active' => true,
        ]);

        $names = collect(\App\Http\Controllers\Admin\ProductCompositionController::payload($this->bouquet)['addons'])
            ->pluck('label')->all();

        $this->assertContains('بطاقة معايدة', $names);
        $this->assertContains('تغليف فاخر', $names);
        $this->assertNotContains('شريط ذهبي', $names);

        $this->assertTrue(
            collect(\App\Http\Controllers\Admin\ProductCompositionController::payload($this->bouquet)['addons'])
                ->firstWhere('value', $mine->id)['private'],
        );
    }

    /** وإضافةٌ تُنشأ من شاشة المنتج تُولد خاصّةً به إن قيل ذلك */
    public function test_the_quick_form_can_create_a_private_addon(): void
    {
        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط ذهبي', 'price' => 0.5, 'product_id' => $this->bouquet->id,
        ])->assertOk()->assertJsonPath('addon.private', true);

        $this->assertSame(
            $this->bouquet->id,
            (int) Addon::where('name', 'شريط ذهبي')->value('product_id'),
        );
    }

    /** واسمٌ واحد يجوز لمنتجين: «تغليف» لكلٍّ تغليفُه وسعرُه */
    public function test_the_same_private_name_is_free_on_another_product(): void
    {
        $chocolateBox = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة شوكولاتة',
            'price' => 6, 'cost' => 3, 'quantity' => 10, 'active' => true,
        ]);

        foreach ([$this->bouquet->id, $chocolateBox->id] as $id) {
            $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
                'name' => 'تغليف', 'price' => 1, 'product_id' => $id,
            ])->assertOk();
        }

        $this->assertSame(2, Addon::where('name', 'تغليف')->count());
    }

    /** ولا تُنسب إضافةٌ إلى منتج متجرٍ آخر */
    public function test_an_addon_cannot_be_owned_by_another_shops_product(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'own@a.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'باقتهم', 'price' => 9, 'cost' => 3, 'quantity' => 5,
        ]);

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط', 'price' => 1, 'product_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }
    /**
     * إضافةُ المنتج تُعرض معه ولو ضاق الربط على غيرها.
     *
     * قسمُ الإضافات في شاشة المنتج صار لما يخصّه وحده، فلا يُختار فيه شيء
     * ولا يُلغى. وربطٌ قديم في `product_addons` يضيّق إضافات المتجر — ولا
     * معنى لأن يُخرج إضافةً صُنعت لهذا المنتج بعينه.
     */
    public function test_a_private_addon_survives_an_old_narrowing_link(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        // ربطٌ لا يذكرها — كما لو كان قد كُتب قبل أن توجد
        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => [$this->chocolate->id]])
            ->assertSessionHasNoErrors();

        $offered = ProductAddons::for($this->bouquet)->pluck('id')->all();

        $this->assertContains($ribbon->id, $offered);
        $this->assertContains($this->chocolate->id, $offered);
        $this->assertNotContains($this->wrapService->id, $offered);

        // وتُقبل في البيع كذلك، لا في العرض وحده
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1,
            'addons' => [['addon_id' => $ribbon->id, 'qty' => 1]],
        ]]);
    }

    /** وتُحذف من شاشتها */
    public function test_a_private_addon_can_be_deleted_from_its_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('admin.products.addons.destroy', [$this->bouquet->id, $ribbon->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Addon::where('id', $ribbon->id)->count());
    }

    /**
     * وإضافةُ المتجر لا تُحذف من شاشة منتجٍ واحد.
     *
     * تُعرض مع كلّ ما يبيعه المحلّ، وحذفُها من هنا يمسّ ما لا يراه من ضغط
     * الزرّ.
     */
    public function test_a_shop_wide_addon_cannot_be_deleted_from_a_product_screen(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('admin.products.addons.destroy', [$this->bouquet->id, $this->wrapService->id]))
            ->assertNotFound();

        $this->assertSame(1, Addon::where('id', $this->wrapService->id)->count());
    }

    /** ولا إضافةُ منتجٍ آخر */
    public function test_another_products_addon_cannot_be_deleted_from_here(): void
    {
        $box = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة',
            'price' => 6, 'cost' => 3, 'quantity' => 4, 'active' => true,
        ]);
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $box->id,
            'name' => 'شريط', 'price' => 1, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('admin.products.addons.destroy', [$this->bouquet->id, $ribbon->id]))
            ->assertNotFound();

        $this->assertSame(1, Addon::where('id', $ribbon->id)->count());
    }
    /**
     * شاشةُ البيع لا تعرض على منتجٍ إضافةَ جاره.
     *
     * كانت تُرسل خريطة الربط الخام، وغيابُ الربط يُقرأ في الشاشة «كلّ
     * إضافات المتجر». وإضافةُ المنتج الخاصّة لا صفَّ ربطٍ لها، فكانت
     * تُعرض مع كلّ منتج — عكسُ معناها. والخادم يردّها عند الدفع، لكنّ
     * الكاشير يكون قد عرضها على الزبون.
     */
    public function test_the_pos_screen_offers_a_private_addon_only_with_its_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $fertiliser = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس سماد',
            'price' => 2, 'cost' => 1, 'quantity' => 20, 'active' => true,
        ]);

        $this->actingAs($this->owner);
        $rows = collect(\App\Support\Demo::products())->keyBy('id');

        $this->assertContains($ribbon->id, $rows[$this->bouquet->id]['addon_ids']);
        $this->assertNotContains($ribbon->id, $rows[$fertiliser->id]['addon_ids']);

        // وإضافةُ المتجر تبقى معروضةً مع الاثنين
        $this->assertContains($this->wrapService->id, $rows[$this->bouquet->id]['addon_ids']);
        $this->assertContains($this->wrapService->id, $rows[$fertiliser->id]['addon_ids']);
    }

    /** ومنتجٌ لم يُربط له شيء يبقى يعرض إضافات المتجر كلّها */
    public function test_an_unlinked_product_still_offers_every_shop_addon(): void
    {
        $this->actingAs($this->owner);
        $ids = collect(\App\Support\Demo::products())->firstWhere('id', $this->bouquet->id)['addon_ids'];

        $this->assertEqualsCanonicalizing([$this->chocolate->id, $this->wrapService->id], $ids);
    }
}

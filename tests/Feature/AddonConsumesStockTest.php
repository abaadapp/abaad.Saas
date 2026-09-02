<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\ProductAddons;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإضافة حين تأكل من الرفّ — واحدةً أو ثلاثًا.
 *
 * الربط بالمخزون كان سؤالًا واحدًا: أيّ صنفٍ ينقص؟ وهو يكفي «دبًّا» ولا
 * يكفي «زيادة ثلاث وردات». وما يُحرس هنا ثلاثة:
 *
 *   - أن يُخصم ما يُقال: ثلاثٌ لا واحدة، وستٌّ حين تُختار مرّتين.
 *   - أن يُردّ ما أُخذ: بلقطة البيع لا بإعداد الإضافة اليوم.
 *   - وألّا يُضاعَف شيءٌ مرّتين: لا بكمية البند، ولا بحركةٍ مكرّرة.
 */
class AddonConsumesStockTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Branch $branch;

    private Product $rose;
    private Product $bear;
    private Product $bouquet;

    private Addon $nameCard;    // خدمة بلا مخزون
    private Addon $bearAddon;   // قطعةٌ مقابل قطعة (بلا كمية مكتوبة — إرثٌ)
    private Addon $extraRoses;  // ثلاث ورداتٍ لكلّ إضافة
    private Addon $styling;     // وردتان — لتُجمع مع سابقتها على الصنف نفسه

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'email' => 'k@test.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@k.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@k.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->rose = $this->product('ورد أحمر', 100, cost: 0.300);
        $this->bear = $this->product('دبّ صغير', 10, cost: 2.000);
        $this->bouquet = $this->product('بوكيه الحب', 50, price: 18, cost: 0);

        $this->nameCard = $this->addon('كتابة اسم', 1.000);
        $this->bearAddon = $this->addon('دبّ', 5.000, $this->bear->id, null);
        $this->extraRoses = $this->addon('زيادة 3 وردات', 2.500, $this->rose->id, 3);
        $this->styling = $this->addon('تنسيق فاخر', 1.500, $this->rose->id, 2);

    }

    /* ------------------------------ أدواتٌ ------------------------------ */

    private function product(string $name, int $qty, float $price = 1, float $cost = 0): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => $name,
            'price' => $price, 'cost' => $cost, 'quantity' => $qty, 'active' => true,
        ]);
        BranchStock::ensureAllocated($this->business->id, $p->id, $qty);

        return $p;
    }

    private function addon(string $name, float $price, ?int $stockId = null, ?float $each = 1): Addon
    {
        return Addon::create([
            'business_id' => $this->business->id, 'name' => $name, 'price' => $price, 'active' => true,
            'inventory_product_id' => $stockId,
            'inventory_quantity' => $stockId ? $each : null,
        ]);
    }

    private function sell(array $items, int $expect = 200): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => $items,
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('k', true),
        ])->assertStatus($expect);
    }

    private function line(array $addons, int $qty = 1): array
    {
        return [[
            'id' => $this->bouquet->id, 'name' => 'بوكيه الحب', 'qty' => $qty,
            'addons' => $addons,
        ]];
    }

    private function stock(Product $p): int
    {
        return (int) $p->fresh()->quantity;
    }

    private function branchStock(Product $p): int
    {
        return (int) BranchStock::where('branch_id', $this->branch->id)
            ->where('product_id', $p->id)->value('quantity');
    }

    private function recipe(int $componentId, float $qty): void
    {
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'component_product_id' => $componentId, 'quantity' => $qty, 'sort_order' => 0,
        ]);
    }

    /* ------------------------- الخصم عند البيع ------------------------- */

    public function test_a_service_addon_sells_without_touching_stock(): void
    {
        $this->sell($this->line([['addon_id' => $this->nameCard->id, 'qty' => 1]]));

        $this->assertSame(100, $this->stock($this->rose));
        $this->assertSame(10, $this->stock($this->bear));
    }

    public function test_a_service_addon_writes_no_inventory_movement(): void
    {
        $this->sell($this->line([['addon_id' => $this->nameCard->id, 'qty' => 1]]));

        $this->assertSame(0, InventoryMovement::where('type', StockLedger::ADDON)->count());
    }

    public function test_an_addon_with_no_written_quantity_still_deducts_one(): void
    {
        // إرثُ ما قبل العمود: رُبطت بصنفٍ ولم يُكتب لها عدد — وكانت تُنقص واحدة
        $this->sell($this->line([['addon_id' => $this->bearAddon->id, 'qty' => 1]]));

        $this->assertSame(9, $this->stock($this->bear));
    }

    public function test_an_addon_of_three_deducts_three(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $this->assertSame(97, $this->stock($this->rose));
    }

    public function test_choosing_it_twice_deducts_six(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));

        $this->assertSame(94, $this->stock($this->rose));
    }

    public function test_the_item_quantity_does_not_multiply_the_addon(): void
    {
        /*
         * الإضافة كميّةٌ مطلقة على البند لا مضروبةٌ في كميّته — وهو ما يُحسب
         * به ثمنُها منذ البدء. فمضاعفتُها هنا كانت ستُنقص الرفّ ضِعفَ ما
         * دُفع ثمنُه، ولا يظهر ذلك إلا في الجرد.
         */
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]], qty: 4));

        $this->assertSame(97, $this->stock($this->rose));
    }

    public function test_the_movement_is_written_once_with_the_addon_type(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));

        $moves = InventoryMovement::where('type', StockLedger::ADDON)->get();

        $this->assertCount(1, $moves);
        $this->assertSame($this->rose->id, (int) $moves->first()->product_id);
        $this->assertSame('-6', $moves->first()->quantity);
    }

    public function test_two_addons_on_the_same_stock_item_are_deducted_together(): void
    {
        $this->sell($this->line([
            ['addon_id' => $this->extraRoses->id, 'qty' => 1],   // ٣
            ['addon_id' => $this->styling->id, 'qty' => 1],      // ٢
        ]));

        $this->assertSame(95, $this->stock($this->rose));
        $this->assertCount(1, InventoryMovement::where('type', StockLedger::ADDON)->get());
    }

    public function test_the_branch_of_the_sale_is_the_branch_that_pays(): void
    {
        $other = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع ثانٍ']);
        BranchStock::adjust($this->business->id, $other->id, $this->rose->id, 0);

        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $this->assertSame(97, $this->branchStock($this->rose));
        $this->assertSame(0, (int) BranchStock::where('branch_id', $other->id)
            ->where('product_id', $this->rose->id)->value('quantity'));
    }

    /* --------------------------- مع الوصفة --------------------------- */

    public function test_a_recipe_and_an_addon_on_different_items_each_take_their_own(): void
    {
        $this->recipe($this->rose->id, 12);

        $this->sell($this->line([['addon_id' => $this->bearAddon->id, 'qty' => 1]]));

        $this->assertSame(88, $this->stock($this->rose));
        $this->assertSame(9, $this->stock($this->bear));
    }

    public function test_a_recipe_and_an_addon_on_the_same_item_add_up(): void
    {
        $this->recipe($this->rose->id, 12);

        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $this->assertSame(85, $this->stock($this->rose));   // ١٢ + ٣
    }

    public function test_a_variant_recipe_and_an_addon_work_together(): void
    {
        $variant = \App\Models\ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'وسط', 'price' => 20, 'active' => true, 'sort_order' => 0,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'variant_id' => $variant->id, 'component_product_id' => $this->rose->id,
            'quantity' => 10, 'sort_order' => 0,
        ]);

        $this->sell([[
            'id' => $this->bouquet->id, 'variant_id' => $variant->id, 'name' => 'بوكيه الحب', 'qty' => 1,
            'addons' => [['addon_id' => $this->extraRoses->id, 'qty' => 1]],
        ]]);

        $this->assertSame(87, $this->stock($this->rose));   // ١٠ + ٣
    }

    public function test_the_bouquet_itself_is_never_deducted_when_it_has_a_recipe(): void
    {
        $this->recipe($this->rose->id, 12);

        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $this->assertSame(50, $this->stock($this->bouquet));
    }

    /* ----------------------------- الحراسة ----------------------------- */

    public function test_a_sale_that_exceeds_the_shelf_is_refused_whole(): void
    {
        $this->rose->update(['quantity' => 2]);
        BranchStock::where('product_id', $this->rose->id)->update(['quantity' => 2]);

        $this->sell($this->line([
            ['addon_id' => $this->extraRoses->id, 'qty' => 1],
            ['addon_id' => $this->bearAddon->id, 'qty' => 1],
        ]), 422)->assertJsonValidationErrors('items');

        // ولا يبقى نصفُ خصم: الدبّ لم يُمسّ لأنّ الورد لم يكفِ
        $this->assertSame(2, $this->stock($this->rose));
        $this->assertSame(10, $this->stock($this->bear));
        $this->assertSame(0, Order::count());
    }

    public function test_the_shortage_message_names_the_item_and_the_numbers(): void
    {
        $this->rose->update(['quantity' => 2]);
        BranchStock::where('product_id', $this->rose->id)->update(['quantity' => 2]);

        $response = $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]), 422);

        $this->assertStringContainsString('ورد أحمر', $response->json('errors.items.0'));
        $this->assertStringContainsString('3', $response->json('errors.items.0'));
    }

    public function test_a_tampered_consumption_from_the_screen_is_ignored(): void
    {
        $this->sell([[
            'id' => $this->bouquet->id, 'name' => 'بوكيه الحب', 'qty' => 1,
            'addons' => [[
                'addon_id' => $this->extraRoses->id, 'qty' => 1,
                'inventory_quantity' => 0, 'inventory_product_id' => $this->bear->id, 'price' => 0.001,
            ]],
        ]]);

        $this->assertSame(97, $this->stock($this->rose));
        $this->assertSame(10, $this->stock($this->bear));
        $this->assertEqualsWithDelta(2.5, (float) OrderItemAddon::first()->unit_price, 0.0005);
    }

    /* ------------------------------ اللقطة ------------------------------ */

    public function test_the_sold_row_remembers_the_item_and_the_amount(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $row = OrderItemAddon::firstOrFail();

        $this->assertSame($this->rose->id, (int) $row->inventory_product_id);
        $this->assertEqualsWithDelta(3.0, (float) $row->inventory_quantity, 0.0005);
    }

    public function test_the_cost_snapshot_counts_what_the_addon_eats(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));

        // ٠٫٣٠٠ للوردة × ٣ = ٠٫٩٠٠ للإضافة الواحدة
        $this->assertEqualsWithDelta(0.900, (float) OrderItemAddon::firstOrFail()->cost, 0.0005);
    }

    public function test_a_service_addon_carries_no_cost(): void
    {
        $this->sell($this->line([['addon_id' => $this->nameCard->id, 'qty' => 1]]));

        $this->assertNull(OrderItemAddon::firstOrFail()->cost);
    }

    public function test_a_later_price_change_does_not_move_a_sold_invoice(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $this->extraRoses->update(['price' => 9]);

        $this->assertEqualsWithDelta(2.5, (float) OrderItemAddon::firstOrFail()->unit_price, 0.0005);
    }

    public function test_a_later_component_cost_change_does_not_move_a_sold_invoice(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $this->rose->update(['cost' => 5]);

        $this->assertEqualsWithDelta(0.900, (float) OrderItemAddon::firstOrFail()->cost, 0.0005);
    }

    /* ------------------------- التصحيح والإلغاء ------------------------- */

    private function correct(int $itemId, int $addonId, int $qty): \Illuminate\Testing\TestResponse
    {
        $order = Order::latest('id')->firstOrFail();

        return $this->actingAs($this->cashier)->put(
            route('pos.orders.items.addons.update', [$order->number, $itemId, $addonId]),
            ['quantity' => $qty, 'reason' => 'الزبون غيّر رأيه'],
        );
    }

    public function test_reducing_the_addon_returns_what_it_ate(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));
        $this->assertSame(94, $this->stock($this->rose));

        $row = OrderItemAddon::firstOrFail();
        $this->correct((int) $row->order_item_id, (int) $row->id, 1)->assertSessionHasNoErrors();

        $this->assertSame(97, $this->stock($this->rose));   // ثلاثٌ عادت لا واحدة
    }

    public function test_increasing_the_addon_takes_only_the_difference(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $row = OrderItemAddon::firstOrFail();
        $this->correct((int) $row->order_item_id, (int) $row->id, 2)->assertSessionHasNoErrors();

        $this->assertSame(94, $this->stock($this->rose));
    }

    public function test_removing_the_addon_returns_all_of_it(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));

        $row = OrderItemAddon::firstOrFail();
        $this->correct((int) $row->order_item_id, (int) $row->id, 0)->assertSessionHasNoErrors();

        $this->assertSame(100, $this->stock($this->rose));
        $this->assertSame(0, OrderItemAddon::count());
    }

    public function test_correcting_the_addon_fixes_the_invoice_total(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));
        $order = Order::latest('id')->firstOrFail();
        $before = (float) $order->total;

        $row = OrderItemAddon::firstOrFail();
        $this->correct((int) $row->order_item_id, (int) $row->id, 1)->assertSessionHasNoErrors();

        // الفاتورة تنقص ثمن إضافةٍ واحدة — بضريبتها كما حُسبت يوم البيع
        $this->assertEqualsWithDelta(2.5, (float) $order->fresh()->items->first()->addons_total, 0.0005);
        $this->assertLessThan($before, (float) $order->fresh()->total);
        $this->assertEqualsWithDelta($before - 2.5, (float) $order->fresh()->total, 0.15);
    }

    public function test_correcting_the_addon_writes_no_duplicate_movement(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));

        $row = OrderItemAddon::firstOrFail();
        $this->correct((int) $row->order_item_id, (int) $row->id, 1)->assertSessionHasNoErrors();

        $this->assertCount(1, InventoryMovement::where('type', StockLedger::CORRECTION)->get());
    }

    public function test_cancelling_returns_what_the_addon_ate(): void
    {
        $this->recipe($this->rose->id, 12);
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));
        $this->assertSame(85, $this->stock($this->rose));

        OrderCorrection::cancel(Order::latest('id')->firstOrFail());

        $this->assertSame(100, $this->stock($this->rose));
    }

    public function test_cancelling_twice_returns_it_once(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $order = Order::latest('id')->firstOrFail();
        OrderCorrection::cancel($order);
        OrderCorrection::cancel($order->fresh());

        $this->assertSame(100, $this->stock($this->rose));
    }

    public function test_cancelling_an_old_order_uses_its_snapshot_not_todays_setting(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        // بعد شهر: صارت الإضافة خمس ورداتٍ بدل ثلاث
        $this->extraRoses->update(['inventory_quantity' => 5]);

        OrderCorrection::cancel(Order::latest('id')->firstOrFail());

        $this->assertSame(100, $this->stock($this->rose));   // ثلاثٌ عادت لا خمس
    }

    public function test_a_row_written_before_the_snapshot_is_read_as_one_each(): void
    {
        $this->sell($this->line([['addon_id' => $this->bearAddon->id, 'qty' => 2]]));

        // صفٌّ قديم: لا لقطة له، والإضافة الحيّة هي كلّ ما يُقرأ منه
        OrderItemAddon::query()->update(['inventory_product_id' => null, 'inventory_quantity' => null]);

        OrderCorrection::cancel(Order::latest('id')->firstOrFail());

        $this->assertSame(10, $this->stock($this->bear));
    }

    /* ------------------------------- المدى ------------------------------- */

    public function test_an_addon_with_no_scope_shows_with_every_product(): void
    {
        $this->assertTrue(ProductAddons::for($this->bouquet)->contains('id', $this->extraRoses->id));
    }

    public function test_a_selected_addon_shows_only_where_it_was_linked(): void
    {
        $other = $this->product('كيس سماد', 5);

        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->extraRoses->id), [
            'name' => 'زيادة 3 وردات', 'price' => 2.5,
            'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$this->bouquet->id],
            'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 3,
        ])->assertOk();

        $this->assertTrue(ProductAddons::for($this->bouquet->fresh())->contains('id', $this->extraRoses->id));
        $this->assertFalse(ProductAddons::for($other)->contains('id', $this->extraRoses->id));
    }

    public function test_narrowing_one_addon_does_not_strip_a_product_of_the_rest(): void
    {
        /*
         * الصفّ في product_addons يحمل معنيين: قائمةُ المنتج، ومدى الإضافة.
         * ولولا فصلُهما لصار اختيارُ «البوكيه» لإضافةٍ محدّدة يحصره فيها
         * وحدها — فيفقد كتابةَ الاسم والدبّ بضغطةٍ لا تقول ذلك.
         */
        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->extraRoses->id), [
            'name' => 'زيادة 3 وردات', 'price' => 2.5,
            'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$this->bouquet->id],
            'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 3,
        ])->assertOk();

        $allowed = ProductAddons::for($this->bouquet->fresh());

        $this->assertTrue($allowed->contains('id', $this->nameCard->id));
        $this->assertTrue($allowed->contains('id', $this->bearAddon->id));
    }

    public function test_a_product_owned_addon_is_offered_with_its_product_alone(): void
    {
        $other = $this->product('كيس سماد', 5);
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شريط ذهبي', 'price' => 1,
            'active' => true, 'product_id' => $this->bouquet->id,
        ]);

        $this->assertTrue(ProductAddons::for($this->bouquet)->contains('id', $ribbon->id));
        $this->assertFalse(ProductAddons::for($other)->contains('id', $ribbon->id));
    }

    public function test_the_server_refuses_an_addon_that_is_not_offered_with_the_product(): void
    {
        $other = $this->product('كيس سماد', 5);
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شريط ذهبي', 'price' => 1,
            'active' => true, 'product_id' => $this->bouquet->id,
        ]);

        $this->sell([[
            'id' => $other->id, 'name' => 'كيس سماد', 'qty' => 1,
            'addons' => [['addon_id' => $ribbon->id, 'qty' => 1]],
        ]], 422)->assertJsonValidationErrors('items.0.addons');
    }

    /* ---------------------------- تعدّد المتاجر ---------------------------- */

    public function test_a_stock_item_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'x@k.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'وردهم', 'price' => 1, 'cost' => 1,
            'quantity' => 50, 'active' => true,
        ]);

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'إضافة مسروقة', 'price' => 1,
            'inventory_product_id' => $theirs->id, 'inventory_quantity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('inventory_product_id');

        $this->assertSame(0, Addon::where('name', 'إضافة مسروقة')->count());
    }

    public function test_a_product_from_another_shop_cannot_be_put_in_an_addon_scope(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'y@k.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'منتجهم', 'price' => 1, 'cost' => 1,
            'quantity' => 5, 'active' => true,
        ]);

        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->nameCard->id), [
            'name' => 'كتابة اسم', 'price' => 1,
            'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$theirs->id],
        ])->assertStatus(422)->assertJsonValidationErrors('product_ids.0');
    }

    public function test_a_shop_cannot_edit_another_shops_addon(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'z@k.local', 'status' => 'نشط']);
        $theirs = Addon::create(['business_id' => $other->id, 'name' => 'إضافتهم', 'price' => 3, 'active' => true]);

        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $theirs->id), [
            'name' => 'مسروقة', 'price' => 99,
        ])->assertNotFound();

        $this->assertSame('إضافتهم', $theirs->fresh()->name);
    }

    public function test_a_consumption_of_zero_is_refused(): void
    {
        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'وعدٌ فارغ', 'price' => 1,
            'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('inventory_quantity');
    }

    public function test_unlinking_the_stock_item_clears_the_consumption(): void
    {
        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->extraRoses->id), [
            'name' => 'زيادة 3 وردات', 'price' => 2.5,
            'inventory_product_id' => null, 'inventory_quantity' => null,
        ])->assertOk();

        $fresh = $this->extraRoses->fresh();

        $this->assertNull($fresh->inventory_product_id);
        $this->assertNull($fresh->inventory_quantity);
    }

    /* ------------------------------ الربحية ------------------------------ */

    public function test_the_addon_cost_reaches_the_profit_report(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 2]]));

        $this->actingAs($this->owner);
        $stats = \App\Support\Demo::profitStats('month');

        // تكلفة الإضافتين ١٫٨٠٠ — وكانت تسقط من الحساب كلّه
        $this->assertGreaterThanOrEqual(1.8, (float) $stats['cogs']);
    }

    public function test_the_receipt_never_carries_the_cost_or_the_shelf(): void
    {
        $this->sell($this->line([['addon_id' => $this->extraRoses->id, 'qty' => 1]]));

        $shown = OrderItemAddon::firstOrFail()->toArray();

        $this->assertArrayNotHasKey('cost', $shown);
        $this->assertArrayNotHasKey('inventory_product_id', $shown);
        $this->assertArrayNotHasKey('inventory_quantity', $shown);
    }
}

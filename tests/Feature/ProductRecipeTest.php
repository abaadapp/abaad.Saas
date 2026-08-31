<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوصفة — بيعُ الباقة ينقص الورد لا الباقة.
 *
 * وهذا أخطر ما في المهمّة كلّها: خطأٌ في الخصم لا يظهر في شاشةٍ ولا في
 * رسالة، بل في جردٍ بعد شهر حين لا يعرف أحدٌ من أين جاء الفرق.
 */
class ProductRecipeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Branch $branch;
    private Product $bouquet;
    private Product $rose;
    private Product $wrap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الوصفات', 'email' => 'r@test.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@r.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@r.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه الحب',
            'price' => 18, 'cost' => 0, 'quantity' => 0, 'active' => true,
        ]);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورد أحمر',
            'price' => 1, 'cost' => 0.5, 'quantity' => 100, 'active' => true,
        ]);

        $this->wrap = Product::create([
            'business_id' => $this->business->id, 'name' => 'تغليف',
            'price' => 1, 'cost' => 0.25, 'quantity' => 40, 'active' => true,
        ]);

        $this->openShiftFor($this->business->id);
    }

    private function ingredient(Product $component, float $qty, ?ProductVariant $variant = null, float $wastage = 0): RecipeItem
    {
        return RecipeItem::create([
            'business_id' => $this->business->id,
            'product_id' => $this->bouquet->id,
            'variant_id' => $variant?->id,
            'component_product_id' => $component->id,
            'quantity' => $qty,
            'wastage_percent' => $wastage,
        ]);
    }

    private function variant(string $name, float $price): ProductVariant
    {
        return ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => $name, 'price' => $price, 'active' => true,
        ]);
    }

    private function sell(array $items, int $expect = 200): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => $items,
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('r', true),
        ])->assertStatus($expect);
    }

    private function line(int $qty = 1, ?ProductVariant $variant = null): array
    {
        return [
            'id' => $this->bouquet->id,
            'variant_id' => $variant?->id,
            'name' => 'بوكيه الحب',
            'qty' => $qty,
        ];
    }

    /* ------------------------------ التركيب ------------------------------ */

    public function test_a_product_can_hold_a_recipe(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $this->bouquet->id), [
                'component_product_id' => $this->rose->id, 'quantity' => 12,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $this->bouquet->id,
            'component_product_id' => $this->rose->id,
            'variant_id' => null,
        ]);
    }

    public function test_a_component_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@r.local', 'status' => 'نشط']);
        $theirRose = Product::create([
            'business_id' => $other->id, 'name' => 'وردهم', 'price' => 1, 'cost' => 1, 'quantity' => 99,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $this->bouquet->id), [
                'component_product_id' => $theirRose->id, 'quantity' => 5,
            ])->assertSessionHasErrors('component_product_id');

        $this->assertDatabaseCount('recipe_items', 0);
    }

    public function test_a_product_cannot_be_a_component_of_itself(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $this->bouquet->id), [
                'component_product_id' => $this->bouquet->id, 'quantity' => 1,
            ])->assertSessionHasErrors('component_product_id');
    }

    public function test_a_bouquet_cannot_be_nested_inside_another_recipe(): void
    {
        /*
         * الإصدار الأوّل يقصر المكوّنات على أصنافٍ مخزنيّة مباشرة — فلا حلقة
         * (أ يحوي ب، وب يحوي أ) تُدخل الخصم في تكرارٍ لا ينتهي.
         */
        $this->ingredient($this->rose, 12);

        $gift = Product::create([
            'business_id' => $this->business->id, 'name' => 'طقم هدية',
            'price' => 40, 'cost' => 0, 'quantity' => 0,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $gift->id), [
                'component_product_id' => $this->bouquet->id, 'quantity' => 1,
            ])->assertSessionHasErrors('component_product_id');
    }

    public function test_quantities_may_be_fractions(): void
    {
        $row = $this->ingredient($this->wrap, 0.5);

        $this->assertEqualsWithDelta(0.5, (float) $row->fresh()->quantity, 0.0001);
    }

    public function test_a_size_with_its_own_rows_ignores_the_base_recipe(): void
    {
        $this->ingredient($this->rose, 8);
        $large = $this->variant('كبير', 30);
        $this->ingredient($this->rose, 20, $large);

        $rows = Recipe::forLine($this->bouquet->fresh(), $large);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(20.0, (float) $rows->first()->quantity, 0.0001);
    }

    public function test_a_size_without_rows_falls_back_to_the_base_recipe(): void
    {
        $this->ingredient($this->rose, 8);
        $small = $this->variant('صغير', 12);

        $rows = Recipe::forLine($this->bouquet->fresh(), $small);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(8.0, (float) $rows->first()->quantity, 0.0001);
    }

    /* ------------------------------- الخصم ------------------------------- */

    public function test_selling_one_bouquet_deducts_its_components_and_not_itself(): void
    {
        $this->ingredient($this->rose, 12);
        $this->ingredient($this->wrap, 1);

        $this->sell([$this->line(1)]);

        $this->assertSame(88, (int) $this->rose->fresh()->quantity);
        $this->assertSame(39, (int) $this->wrap->fresh()->quantity);
        // الباقة نفسها لا تُخصم — مكوّناتها هي مخزونها
        $this->assertSame(0, (int) $this->bouquet->fresh()->quantity);
    }

    public function test_selling_two_doubles_the_consumption(): void
    {
        $this->ingredient($this->rose, 12);

        $this->sell([$this->line(2)]);

        $this->assertSame(76, (int) $this->rose->fresh()->quantity);
    }

    public function test_the_deduction_lands_on_the_branch_of_the_sale(): void
    {
        $second = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع ثانٍ']);

        \App\Models\BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $this->rose->id, 'quantity' => 60,
        ]);
        \App\Models\BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $second->id,
            'product_id' => $this->rose->id, 'quantity' => 40,
        ]);

        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(1)]);

        $this->assertSame(48, \App\Models\BranchStock::where('branch_id', $this->branch->id)
            ->where('product_id', $this->rose->id)->value('quantity'));
        // ولا يُمسّ الفرع الآخر
        $this->assertSame(40, \App\Models\BranchStock::where('branch_id', $second->id)
            ->where('product_id', $this->rose->id)->value('quantity'));
    }

    public function test_insufficient_components_block_the_sale_by_the_existing_policy(): void
    {
        $this->rose->update(['quantity' => 8]);
        $this->ingredient($this->rose, 12);

        $this->sell([$this->line(1)], 422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::count());
        $this->assertSame(8, (int) $this->rose->fresh()->quantity);
    }

    public function test_a_shop_that_allows_negative_stock_keeps_allowing_it(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'allow_negative_stock', 'value' => '1']);
        $this->rose->update(['quantity' => 8]);
        $this->ingredient($this->rose, 12);

        $this->sell([$this->line(1)]);

        $this->assertSame(-4, (int) $this->rose->fresh()->quantity);
    }

    public function test_a_rejected_sale_leaves_no_component_half_deducted(): void
    {
        // الورد يكفي والتغليف لا — فلا يُخصم الورد ولا يُنشأ طلب
        $this->wrap->update(['quantity' => 0]);
        $this->ingredient($this->rose, 12);
        $this->ingredient($this->wrap, 1);

        $this->sell([$this->line(1)], 422);

        $this->assertSame(100, (int) $this->rose->fresh()->quantity);
        $this->assertSame(0, (int) $this->wrap->fresh()->quantity);
        $this->assertSame(0, Order::count());
    }

    public function test_wastage_is_taken_off_the_shelf_not_only_off_the_books(): void
    {
        // ١٠ ورداتٍ بفاقد ٢٠٪ = ١٢ تُخصم فعلًا
        $this->ingredient($this->rose, 10, wastage: 20);

        $this->sell([$this->line(1)]);

        $this->assertSame(88, (int) $this->rose->fresh()->quantity);
    }

    public function test_fractions_are_summed_before_they_are_rounded_up(): void
    {
        /*
         * نصفُ لفّةٍ لكلّ باقة: باقتان تستهلكان لفّةً واحدة لا لفّتين.
         * والجمع قبل الرفع هو ما يمنع التضخيم — انظر Recipe::units.
         */
        $this->ingredient($this->wrap, 0.5);

        $this->sell([$this->line(2)]);

        $this->assertSame(39, (int) $this->wrap->fresh()->quantity);
    }

    public function test_each_component_gets_one_movement_not_several(): void
    {
        $this->ingredient($this->rose, 12);
        $this->ingredient($this->wrap, 1);

        $this->sell([$this->line(2)]);

        $this->assertSame(1, InventoryMovement::where('product_id', $this->rose->id)->count());
        $this->assertSame(1, InventoryMovement::where('product_id', $this->wrap->id)->count());
        // والباقة نفسها لا حركة لها — لم يُخصم منها شيء
        $this->assertSame(0, InventoryMovement::where('product_id', $this->bouquet->id)->count());
    }

    public function test_the_movement_says_why_and_for_which_order(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(1)]);

        $movement = InventoryMovement::where('product_id', $this->rose->id)->firstOrFail();
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(\App\Support\StockLedger::RECIPE, $movement->type);
        $this->assertSame('-12', $movement->quantity);
        $this->assertSame($order->number, $movement->note);
        $this->assertSame($this->branch->id, (int) $movement->branch_id);
    }

    /* ------------------------------ التكلفة ------------------------------ */

    public function test_the_cost_snapshot_is_the_recipe_cost_at_the_moment_of_sale(): void
    {
        $this->ingredient($this->rose, 12);   // 12 × 0.5 = 6
        $this->ingredient($this->wrap, 1);    // 1 × 0.25 = 0.25

        $this->sell([$this->line(1)]);

        $item = Order::latest('id')->firstOrFail()->items->first();

        $this->assertEqualsWithDelta(6.25, (float) $item->cost, 0.0005);
    }

    public function test_raising_a_component_cost_later_does_not_move_last_months_profit(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(1)]);

        $this->rose->update(['cost' => 5]);

        $item = Order::latest('id')->firstOrFail()->items()->first();

        $this->assertEqualsWithDelta(6.0, (float) $item->cost, 0.0005);
    }

    /* --------------------------- تصحيح الفاتورة --------------------------- */

    public function test_reducing_the_quantity_gives_the_components_back(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(2)]);

        $this->assertSame(76, (int) $this->rose->fresh()->quantity);

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'خطأ إدخال');

        $this->assertSame(88, (int) $this->rose->fresh()->quantity);
        // ولا تُردّ «الباقة» إلى رفٍّ لا رصيد لها فيه
        $this->assertSame(0, (int) $this->bouquet->fresh()->quantity);
    }

    public function test_raising_the_quantity_takes_more_components_and_respects_the_stock_guard(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(1)]);

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $order->items->first(), 2, 'الزبون طلب اثنتين');

        $this->assertSame(76, (int) $this->rose->fresh()->quantity);
    }

    public function test_a_correction_cannot_slip_past_the_stock_guard(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(1)]);

        // الرصيدان معًا: الحارس يقرأ رصيد الفرع، وتركُ صفّه عاليًا يجعل
        // الاختبار يفحص عمودًا لا يقرؤه أحد
        $this->rose->update(['quantity' => 3]);
        \App\Models\BranchStock::where('product_id', $this->rose->id)->update(['quantity' => 3]);

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->owner);

        $this->expectException(\RuntimeException::class);
        OrderCorrection::setQuantity($order, $order->items->first(), 5, 'محاولة تجاوز');
    }

    public function test_correcting_writes_one_movement_per_component_not_two(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(2)]);

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'تصحيح');

        $this->assertSame(2, InventoryMovement::where('product_id', $this->rose->id)->count());
        $this->assertSame(88, (int) $this->rose->fresh()->quantity);
    }

    public function test_changing_the_recipe_afterwards_does_not_rewrite_the_old_order(): void
    {
        $this->ingredient($this->rose, 12);
        $this->sell([$this->line(1)]);

        RecipeItem::query()->update(['quantity' => 30]);

        $item = Order::latest('id')->firstOrFail()->items()->first();

        $this->assertEqualsWithDelta(6.0, (float) $item->cost, 0.0005);
        $this->assertEqualsWithDelta(18.0, (float) $item->price, 0.0005);
    }
}

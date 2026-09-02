<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\User;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقة كاملةً: مقاسٌ ووصفةٌ وإضافات في بيعةٍ واحدة.
 *
 * الميزات الثلاث تعمل كلٌّ على حدة — وهذا يحرس اجتماعها: أن يكون الإيراد
 * صحيحًا، والخصم صحيحًا، واللقطة صحيحة، وألّا يُخصم شيءٌ مرّتين.
 */
class BouquetEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $bouquet;
    private Product $rose;
    private Product $wrap;
    private Product $bearStock;
    private ProductVariant $medium;
    private Addon $bear;
    private Addon $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل أبعاد', 'email' => 'e@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوض']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@e.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@e.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه الحب',
            'price' => 0, 'cost' => 0, 'quantity' => 0, 'active' => true,
        ]);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورد أحمر',
            'price' => 1, 'cost' => 0.5, 'quantity' => 100, 'active' => true,
        ]);

        $this->wrap = Product::create([
            'business_id' => $this->business->id, 'name' => 'تغليف',
            'price' => 1, 'cost' => 0.25, 'quantity' => 30, 'active' => true,
        ]);

        $this->bearStock = Product::create([
            'business_id' => $this->business->id, 'name' => 'دبّ',
            'price' => 5, 'cost' => 2, 'quantity' => 10, 'active' => true,
        ]);

        $this->medium = ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'وسط', 'price' => 18, 'active' => true,
        ]);

        ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'صغير', 'price' => 12, 'active' => true,
        ]);

        // وصفة «وسط»: ١٢ وردة وقطعة تغليف
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'variant_id' => $this->medium->id, 'component_product_id' => $this->rose->id, 'quantity' => 12,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'variant_id' => $this->medium->id, 'component_product_id' => $this->wrap->id, 'quantity' => 1,
        ]);

        $this->bear = Addon::create([
            'business_id' => $this->business->id, 'name' => 'دبّ', 'price' => 5,
            'active' => true, 'inventory_product_id' => $this->bearStock->id,
        ]);

        $this->card = Addon::create([
            'business_id' => $this->business->id, 'name' => 'بطاقة فاخرة', 'price' => 1, 'active' => true,
        ]);

    }

    private function sell(int $qty = 1, array $addons = []): Order
    {
        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [[
                'id' => $this->bouquet->id,
                'variant_id' => $this->medium->id,
                'name' => 'بوكيه الحب',
                'qty' => $qty,
                'addons' => $addons,
            ]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('e', true),
        ])->assertOk()->assertJsonPath('ok', true);

        return Order::latest('id')->firstOrFail();
    }

    public function test_the_whole_bouquet_sells_in_one_go(): void
    {
        $order = $this->sell(2, [
            ['addon_id' => $this->bear->id, 'qty' => 1],
            ['addon_id' => $this->card->id, 'qty' => 2],
        ]);

        $item = $order->items->first();

        /* الإيراد: ١٨ × ٢ = ٣٦، والإضافات ٥ + ٢ = ٧ */
        $this->assertEqualsWithDelta(43.0, (float) $order->subtotal, 0.0005);
        $this->assertEqualsWithDelta(36.0, (float) $item->total, 0.0005);
        $this->assertEqualsWithDelta(7.0, (float) $item->addons_total, 0.0005);

        /* اللقطة: المقاس باسمه، والتكلفة من الوصفة (١٢×٠٫٥ + ١×٠٫٢٥) */
        $this->assertSame('وسط', $item->variant_name);
        $this->assertEqualsWithDelta(6.25, (float) $item->cost, 0.0005);
        $this->assertSame('بوكيه الحب — وسط', $item->displayName());

        /* المخزون: ٢٤ وردة وقطعتا تغليف ودبّ — والباقة لا تُمسّ */
        $this->assertSame(76, (int) $this->rose->fresh()->quantity);
        $this->assertSame(28, (int) $this->wrap->fresh()->quantity);
        $this->assertSame(9, (int) $this->bearStock->fresh()->quantity);
        $this->assertSame(0, (int) $this->bouquet->fresh()->quantity);

        /* الربح الإجماليّ للبند: إيرادُه ناقص تكلفة وصفته وتكلفة إضافاته */
        $revenue = (float) $item->total + (float) $item->addons_total;
        $cost = (float) $item->cost * (int) $item->quantity
            + $item->addons->sum(fn ($a) => (float) $a->cost * (int) $a->quantity);

        $this->assertEqualsWithDelta(43.0, $revenue, 0.0005);
        $this->assertEqualsWithDelta(14.5, $cost, 0.0005);   // 6.25×2 + 2
        $this->assertEqualsWithDelta(28.5, $revenue - $cost, 0.0005);
    }

    public function test_nothing_is_deducted_twice(): void
    {
        $this->sell(1, [['addon_id' => $this->bear->id, 'qty' => 1]]);

        $this->assertSame(1, InventoryMovement::where('product_id', $this->rose->id)->count());
        $this->assertSame(1, InventoryMovement::where('product_id', $this->bearStock->id)->count());
        $this->assertSame(0, InventoryMovement::where('product_id', $this->bouquet->id)->count());
    }

    public function test_editing_the_quantity_moves_the_stock_once_and_correctly(): void
    {
        $order = $this->sell(2, [['addon_id' => $this->bear->id, 'qty' => 1]]);

        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'الزبون غيّر رأيه');

        $this->assertSame(88, (int) $this->rose->fresh()->quantity);
        $this->assertSame(29, (int) $this->wrap->fresh()->quantity);
        // الإضافة كميّةٌ مطلقة على البند — تغيير كميّة البند لا يمسّها
        $this->assertSame(9, (int) $this->bearStock->fresh()->quantity);
    }

    public function test_history_survives_every_later_change(): void
    {
        $order = $this->sell(1, [['addon_id' => $this->bear->id, 'qty' => 1]]);
        $item = $order->items->first();

        // يتغيّر كلّ شيء بعد البيع
        $this->medium->update(['name' => 'وسط فاخر', 'price' => 30]);
        $this->bear->update(['name' => 'دبّ كبير', 'price' => 12]);
        $this->rose->update(['cost' => 4]);
        RecipeItem::query()->update(['quantity' => 40]);

        $item = $item->fresh(['addons']);

        $this->assertSame('وسط', $item->variant_name);
        $this->assertEqualsWithDelta(18.0, (float) $item->price, 0.0005);
        $this->assertEqualsWithDelta(6.25, (float) $item->cost, 0.0005);
        $this->assertSame('دبّ', $item->addons->first()->name);
        $this->assertEqualsWithDelta(5.0, (float) $item->addons->first()->unit_price, 0.0005);
        $this->assertEqualsWithDelta(23.0, (float) $order->fresh()->subtotal, 0.0005);
    }

    public function test_a_held_order_comes_back_with_its_size_and_addons(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/hold', [
            'items' => [[
                'id' => $this->bouquet->id,
                'variant_id' => $this->medium->id,
                'name' => 'بوكيه الحب',
                'qty' => 1,
                'addons' => [['addon_id' => $this->bear->id, 'qty' => 1]],
            ]],
            'kind' => 'hold',
        ])->assertOk();

        $held = Order::where('is_held', true)->latest('id')->firstOrFail();
        $item = $held->items->first();

        $this->assertSame($this->medium->id, (int) $item->variant_id);
        $this->assertCount(1, $item->addons);

        $this->actingAs($this->cashier)->get(route('pos.orders.resume', $held->id))->assertRedirect();

        $cart = session('resume_cart');

        $this->assertSame($this->medium->id, (int) $cart['items'][0]['variant_id']);
        $this->assertSame($this->bear->id, (int) $cart['items'][0]['addons'][0]['addon_id']);
    }

    public function test_the_preparation_board_shows_the_size_and_the_addons(): void
    {
        $order = $this->sell(1, [['addon_id' => $this->bear->id, 'qty' => 2]]);
        // اللوحة تعرض ما له موعد — انظر awaitingPreparation
        $order->update([
            'status' => \App\Support\OrderStatus::PENDING,
            'scheduled_for' => now()->addHours(2),
        ]);

        $this->actingAs($this->owner)->get(route('admin.preparation.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders.0.items.0.name', 'بوكيه الحب — وسط')
                ->where('orders.0.items.0.addons.0.name', 'دبّ')
                ->where('orders.0.items.0.addons.0.qty', 2)
                ->etc());
    }
}

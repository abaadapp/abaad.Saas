<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المقاسات — «بوكيه الحب» صغير ووسط وكبير.
 *
 * وشرطُ القبول الأوّل ليس أن تعمل المقاسات، بل ألّا ينكسر ما قبلها: مئةُ
 * منتجٍ في متاجر قائمة لا مقاس لها، وبيعُها يجب أن يبقى كما كان حرفًا بحرف.
 */
class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $bouquet;
    private Product $simple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'v@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@v.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@v.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه الحب',
            'price' => 15, 'cost' => 6, 'quantity' => 50, 'active' => true,
        ]);

        $this->simple = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة مفردة',
            'price' => 2, 'cost' => 1, 'quantity' => 200, 'active' => true,
        ]);

        $this->openShiftFor($this->business->id);
    }

    private function variant(array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'business_id' => $this->business->id,
            'product_id' => $this->bouquet->id,
            'name' => 'وسط',
            'price' => 18,
            'active' => true,
        ], $attributes));
    }

    private function sell(array $items, int $expect = 200): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => $items,
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('v', true),
        ])->assertStatus($expect);
    }

    /* ------------------------------ الإنشاء ------------------------------ */

    public function test_a_business_can_add_a_size_to_its_product(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.variants.store', $this->bouquet->id), [
                'name' => 'كبير', 'price' => 25,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_variants', [
            'business_id' => $this->business->id,
            'product_id' => $this->bouquet->id,
            'name' => 'كبير',
        ]);
    }

    public function test_a_size_belongs_to_its_product_and_its_business(): void
    {
        $variant = $this->variant();

        $this->assertSame($this->bouquet->id, $variant->product->id);
        $this->assertSame($this->business->id, (int) $variant->business_id);
    }

    public function test_one_shop_cannot_touch_another_shops_size(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@v.local', 'status' => 'نشط']);
        $theirProduct = Product::create([
            'business_id' => $other->id, 'name' => 'باقتهم', 'price' => 9, 'cost' => 3, 'quantity' => 5,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.variants.store', $theirProduct->id), ['name' => 'كبير', 'price' => 25])
            ->assertNotFound();

        $this->assertDatabaseCount('product_variants', 0);
    }

    /* ------------------------------- البيع ------------------------------- */

    public function test_a_product_without_sizes_still_sells_exactly_as_before(): void
    {
        $this->sell([['id' => $this->simple->id, 'name' => 'وردة مفردة', 'qty' => 3]]);

        $order = Order::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(6.0, (float) $order->subtotal, 0.0005);
        $this->assertNull($order->items->first()->variant_id);
        $this->assertSame(197, (int) $this->simple->fresh()->quantity);
    }

    public function test_a_product_with_sizes_refuses_to_sell_without_one(): void
    {
        $this->variant();

        $this->sell([['id' => $this->bouquet->id, 'name' => 'بوكيه الحب', 'qty' => 1]], 422)
            ->assertJsonValidationErrors('items.0.variant_id');

        $this->assertSame(0, Order::count());
    }

    public function test_the_server_prices_the_size_and_ignores_what_the_screen_sent(): void
    {
        $variant = $this->variant(['price' => 18]);

        // سعرٌ ملفَّق في الطلب — يُتجاهل، ويُقرأ سعر المقاس من القاعدة
        $this->sell([[
            'id' => $this->bouquet->id, 'variant_id' => $variant->id,
            'name' => 'بوكيه الحب', 'qty' => 1, 'price' => 1,
        ]]);

        $order = Order::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(18.0, (float) $order->items->first()->price, 0.0005);
    }

    public function test_a_size_from_another_product_is_refused(): void
    {
        $variant = $this->variant();

        $this->sell([[
            'id' => $this->simple->id, 'variant_id' => $variant->id, 'name' => 'وردة', 'qty' => 1,
        ]], 422)->assertJsonValidationErrors('items.0.variant_id');
    }

    public function test_a_switched_off_size_cannot_be_sold_anew(): void
    {
        $variant = $this->variant(['active' => false]);
        $this->variant(['name' => 'كبير', 'price' => 25]);

        $this->sell([[
            'id' => $this->bouquet->id, 'variant_id' => $variant->id, 'name' => 'بوكيه', 'qty' => 1,
        ]], 422)->assertJsonValidationErrors('items.0.variant_id');
    }

    /* ------------------------------- اللقطة ------------------------------ */

    public function test_the_order_keeps_the_size_name_it_was_sold_under(): void
    {
        $variant = $this->variant(['name' => 'وسط', 'sku' => 'BQ-M', 'price' => 18]);

        $this->sell([[
            'id' => $this->bouquet->id, 'variant_id' => $variant->id, 'name' => 'بوكيه', 'qty' => 1,
        ]]);

        $item = Order::latest('id')->firstOrFail()->items->first();

        $this->assertSame('وسط', $item->variant_name);
        $this->assertSame('BQ-M', $item->variant_sku);
    }

    public function test_renaming_a_size_later_does_not_rewrite_an_old_invoice(): void
    {
        $variant = $this->variant(['name' => 'وسط', 'price' => 18]);

        $this->sell([[
            'id' => $this->bouquet->id, 'variant_id' => $variant->id, 'name' => 'بوكيه', 'qty' => 1,
        ]]);

        $variant->update(['name' => 'وسط فاخر', 'price' => 25]);

        $item = Order::latest('id')->firstOrFail()->items()->first();

        $this->assertSame('وسط', $item->variant_name);
        $this->assertEqualsWithDelta(18.0, (float) $item->price, 0.0005);
        $this->assertSame('بوكيه الحب — وسط', $item->displayName());
    }

    public function test_an_old_order_still_reads_after_the_size_is_deleted(): void
    {
        $variant = $this->variant();

        $this->sell([[
            'id' => $this->bouquet->id, 'variant_id' => $variant->id, 'name' => 'بوكيه', 'qty' => 1,
        ]]);

        $this->actingAs($this->owner)
            ->delete(route('admin.products.variants.destroy', [$this->bouquet->id, $variant->id]))
            ->assertSessionHasNoErrors();

        $item = Order::latest('id')->firstOrFail()->items()->first();

        // اللقطة تحمي العرض؛ والمعرّف باقٍ لأنّ الحذف ناعم
        $this->assertSame('وسط', $item->variant_name);
        $this->assertSame('بوكيه الحب — وسط', $item->displayName());
    }
}

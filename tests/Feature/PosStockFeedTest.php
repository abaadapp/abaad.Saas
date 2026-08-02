<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تغذية المخزون الحيّة لشاشة البيع — تُستطلَع كل 20 ثانية، فلا يجوز أن
 * تُسرّب منتجات نشاط آخر ولا أن تُفتح لغير مسجّل دخول.
 */
class PosStockFeedTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(Business $business): User
    {
        return User::create([
            'business_id' => $business->id,
            'name' => 'الكاشير',
            'email' => 'cashier' . $business->id . '@test.com',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'status' => 'نشط',
        ]);
    }

    private function product(Business $business, string $name, int $qty, int $alert = 5): Product
    {
        return Product::create([
            'business_id' => $business->id,
            'name' => $name,
            'price' => 1.5,
            'cost' => 1,
            'quantity' => $qty,
            'alert_qty' => $alert,
            'active' => true,
        ]);
    }

    public function test_a_guest_cannot_read_the_stock_feed(): void
    {
        $this->get(route('pos.stock-feed'))->assertRedirect();
    }

    public function test_it_returns_the_live_quantity_and_its_status(): void
    {
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $plenty = $this->product($business, 'قهوة', 10);
        $low = $this->product($business, 'شاي', 2);
        $out = $this->product($business, 'ماء', 0);

        $response = $this->actingAs($this->cashier($business))
            ->getJson(route('pos.stock-feed'))
            ->assertOk();

        $response->assertJsonPath('products.0', ['id' => $plenty->id, 'qty' => 10, 'stock_status' => 'متوفر']);
        $response->assertJsonPath('products.1', ['id' => $low->id, 'qty' => 2, 'stock_status' => 'منخفض']);
        $response->assertJsonPath('products.2', ['id' => $out->id, 'qty' => 0, 'stock_status' => 'نفد المخزون']);
    }

    public function test_it_reflects_a_sale_made_on_another_device(): void
    {
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $product = $this->product($business, 'قهوة', 10);
        $cashier = $this->cashier($business);

        $this->actingAs($cashier)->getJson(route('pos.stock-feed'))
            ->assertJsonPath('products.0.qty', 10);

        // زميل يبيع 6 قطع على جهاز آخر
        $product->update(['quantity' => 4]);

        $this->actingAs($cashier)->getJson(route('pos.stock-feed'))
            ->assertJsonPath('products.0.qty', 4);
    }

    public function test_it_never_leaks_another_businesss_products(): void
    {
        $mine = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $theirs = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $ours = $this->product($mine, 'قهوة', 10);
        $secret = $this->product($theirs, 'منتج سرّي', 99);

        $response = $this->actingAs($this->cashier($mine))
            ->getJson(route('pos.stock-feed'))
            ->assertOk();

        $response->assertJsonCount(1, 'products');
        $response->assertJsonPath('products.0.id', $ours->id);
        $response->assertJsonMissing(['id' => $secret->id]);
    }

    public function test_it_sends_quantities_only_not_whole_products(): void
    {
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->product($business, 'قهوة', 10);

        $row = $this->actingAs($this->cashier($business))
            ->getJson(route('pos.stock-feed'))
            ->json('products.0');

        // الاسم والسعر والصورة لا تتغيّر كل عشرين ثانية — إرسالها هدر
        $this->assertSame(['id', 'qty', 'stock_status'], array_keys($row));
    }
}

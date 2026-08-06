<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * العزل بين المتاجر داخل نقطة البيع.
 *
 * حارس الصلاحية يقول «له نقطة بيع» ولا يقول «أيّ متجر». والكاشير هو أقلّ
 * الأدوار صلاحية وأكثرها عددًا، فإن مرّ معرّف طلبٍ من متجر آخر عبر مسارات
 * /pos مرّ لأضعف حساب في النظام.
 */
class PosTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->theirs = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->cashier = User::create([
            'business_id' => $this->mine->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->mine->id);
    }

    private function theirOrder(string $number, bool $held = false): int
    {
        return (int) \DB::table('orders')->insertGetId([
            'business_id' => $this->theirs->id, 'number' => $number,
            'total' => 99, 'status' => $held ? 'معلّق' : 'مكتمل',
            'is_held' => $held, 'ordered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_cashier_cannot_open_another_businesses_order(): void
    {
        $this->theirOrder('ORD-JAR-1');

        $this->actingAs($this->cashier)
            ->get(route('pos.order-details', 'ORD-JAR-1'))
            ->assertNotFound();
    }

    public function test_a_cashier_cannot_print_another_businesses_receipt(): void
    {
        $this->theirOrder('ORD-JAR-2');

        $this->actingAs($this->cashier)
            ->get(route('pos.receipt.pdf', 'ORD-JAR-2'))
            ->assertNotFound();
    }

    public function test_a_cashier_cannot_resume_another_businesses_held_cart(): void
    {
        // الاستكمال ينقل سلّة كاملة إلى الشاشة: أصنافًا وأسعارًا واسم عميل
        $this->theirOrder('HOLD-JAR-1', held: true);

        $this->actingAs($this->cashier)
            ->get(route('pos.orders.resume', 'HOLD-JAR-1'))
            ->assertNotFound();
    }

    public function test_a_cashier_cannot_discard_another_businesses_held_cart(): void
    {
        $id = $this->theirOrder('HOLD-JAR-2', held: true);

        $this->actingAs($this->cashier)->delete(route('pos.orders.discard', 'HOLD-JAR-2'));

        $this->assertDatabaseHas('orders', ['id' => $id]);
    }

    public function test_the_stock_feed_only_carries_this_businesses_products(): void
    {
        \DB::table('products')->insert([
            'business_id' => $this->theirs->id, 'name' => 'منتج الجار',
            'price' => 10, 'quantity' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('products')->insert([
            'business_id' => $this->mine->id, 'name' => 'منتجي',
            'price' => 10, 'quantity' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $feed = $this->actingAs($this->cashier)
            ->getJson(route('pos.stock-feed'))->assertOk()->json();

        $ids = collect($feed['products'] ?? $feed)->pluck('id')->all();
        $theirIds = \DB::table('products')->where('business_id', $this->theirs->id)->pluck('id')->all();

        $this->assertEmpty(array_intersect($ids, $theirIds), 'تدفّق المخزون يحمل منتجات متجر آخر');
    }

    public function test_the_pos_screen_lists_only_this_businesses_customers(): void
    {
        \DB::table('customers')->insert([
            'business_id' => $this->theirs->id, 'name' => 'عميل الجار',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertDontSee('عميل الجار');
    }
}

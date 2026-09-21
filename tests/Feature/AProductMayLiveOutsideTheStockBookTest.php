<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\StockLedger;
use App\Support\Website\Shelf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صنفٌ قد يعيش خارج دفتر المخزون.
 *
 * خدمةٌ أو رسمُ توصيلٍ أو صنفٌ يُصنع عند الطلب: لا رفَّ له. فلا يُخصم عند
 * بيعه، ولا يُمنع بيعُه حين تكون كميّتُه صفرًا، ولا يرنّ الجرسُ عليه —
 * وكلُّ ما سبق المفتاحَ يبقى بضاعةً كما كان.
 */
class AProductMayLiveOutsideTheStockBookTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $this->business->id, 'name' => 'رسم توصيل',
            'price' => 2, 'cost' => 0, 'quantity' => 0, 'alert_qty' => 10, 'active' => true,
        ]);
    }

    private function sell(Product $p, int $qty = 1)
    {
        return $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => (float) $p->price]],
            'payment_method' => 'نقدي',
        ]);
    }

    /* ═══════════ الإنشاء ═══════════ */

    public function test_a_product_is_stock_tracked_by_default(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة ورد', 'price' => 10, 'quantity' => 5,
        ]);

        $p = Product::where('name', 'باقة ورد')->firstOrFail();
        $this->assertTrue($p->tracksStock());
        $this->assertSame(5, (int) $p->quantity);
        $this->assertSame(1, InventoryMovement::where('product_id', $p->id)->count(), 'رصيدٌ افتتاحيٌّ يُقيَّد');
    }

    public function test_an_untracked_product_starts_with_no_quantity_and_no_movement(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'رسم توصيل', 'price' => 2, 'quantity' => 99, 'tracks_stock' => '0',
        ]);

        $p = Product::where('name', 'رسم توصيل')->firstOrFail();
        $this->assertFalse($p->tracksStock());
        $this->assertSame(0, (int) $p->quantity, 'كميّةٌ أُرسلت لصنفٍ لا يُعدّ تُهمَل');
        $this->assertSame(0, InventoryMovement::where('product_id', $p->id)->count());
        $this->assertSame(Product::UNTRACKED, $p->stock_status);
    }

    /* ═══════════ البيع ═══════════ */

    public function test_an_untracked_product_sells_at_zero_quantity(): void
    {
        $p = $this->product(['tracks_stock' => false]);

        $this->sell($p, 3)->assertOk();

        $this->assertSame(0, (int) $p->fresh()->quantity, 'لا يُخصم');
        $this->assertSame(0, InventoryMovement::where('product_id', $p->id)->count(), 'ولا حركةَ له');
    }

    public function test_a_tracked_product_at_zero_is_still_refused(): void
    {
        $p = $this->product(['tracks_stock' => true]);

        $this->sell($p)->assertStatus(422);
    }

    public function test_selling_a_tracked_product_still_moves_stock(): void
    {
        $p = $this->product(['tracks_stock' => true, 'quantity' => 5]);

        $this->sell($p, 2)->assertOk();

        $this->assertSame(3, (int) $p->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::where('product_id', $p->id)->count());
    }

    /* ═══════════ الجرس والموقع ═══════════ */

    public function test_the_bell_does_not_ring_for_an_untracked_product(): void
    {
        $this->product(['tracks_stock' => false, 'name' => 'خدمة']);
        $this->product(['tracks_stock' => true, 'name' => 'بضاعة']);

        $names = Product::where('business_id', $this->business->id)->needsStockAlert()->pluck('name')->all();

        $this->assertSame(['بضاعة'], $names);
    }

    public function test_the_website_shows_an_untracked_product_as_available(): void
    {
        $service = $this->product(['tracks_stock' => false, 'name' => 'خدمة']);
        $goods = $this->product(['tracks_stock' => true, 'name' => 'بضاعة']);

        $available = Shelf::availability($this->business->id, [$service->id, $goods->id]);

        $this->assertTrue($available[$service->id]);
        $this->assertFalse($available[$goods->id]);
    }

    /* ═══════════ التعديل ═══════════ */

    public function test_unlinking_keeps_the_quantity_and_writes_no_movement(): void
    {
        $p = $this->product(['tracks_stock' => true, 'quantity' => 7]);

        $this->actingAs($this->owner)->put(route('admin.products.update', $p->id), [
            'name' => $p->name, 'price' => 2, 'tracks_stock' => '0',
        ]);

        $p->refresh();
        $this->assertFalse($p->tracksStock());
        $this->assertSame(7, (int) $p->quantity, 'الكميّةُ تُترك ليجدها إن أعاد الربط');
        $this->assertSame(0, InventoryMovement::where('product_id', $p->id)->count());
    }

    public function test_a_save_that_does_not_mention_the_link_does_not_flip_it(): void
    {
        $p = $this->product(['tracks_stock' => false]);

        $this->actingAs($this->owner)->put(route('admin.products.update', $p->id), [
            'name' => $p->name, 'price' => 3,
        ]);

        $this->assertFalse($p->fresh()->tracksStock());
    }

    public function test_quick_edit_ignores_quantity_on_an_untracked_product(): void
    {
        $p = $this->product(['tracks_stock' => false]);

        $this->actingAs($this->owner)->patch(route('admin.products.quick', $p->id), ['quantity' => 40])
            ->assertRedirect();

        $this->assertSame(0, (int) $p->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::where('product_id', $p->id)->count());
    }

    public function test_a_tracked_product_saved_without_the_field_stays_tracked(): void
    {
        $p = $this->product(['tracks_stock' => true, 'quantity' => 4]);

        $this->actingAs($this->owner)->put(route('admin.products.update', $p->id), [
            'name' => $p->name, 'price' => 3, 'quantity' => 4,
        ]);

        $this->assertTrue($p->fresh()->tracksStock());
    }

    /** والدفترُ نفسُه يردّ حركةَ صنفٍ لا يُعدّ — لا المتحكّمُ وحدَه */
    public function test_the_ledger_refuses_to_note_a_movement_on_an_untracked_product(): void
    {
        $p = $this->product(['tracks_stock' => false]);

        StockLedger::note($this->business->id, null, $p, 5, StockLedger::MANUAL, 'أحد');

        $this->assertSame(0, InventoryMovement::where('product_id', $p->id)->count());
    }
}

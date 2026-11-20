<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ‏الصندوقُ يعرف كلَّ مقاسٍ بباركوده — ولا يبيع صنفًا ناقصَ المقاس.
 *
 * ═══ العطب ═══
 *
 * باركودُ صنفٍ له مقاساتٌ كان يُدخله السلّةَ بلا مقاس، فيردّه الخادمُ عند الدفع
 * («اختر مقاس…»). وباركودُ المقاس نفسِه لم يكن يصل الصندوقَ: يُكتب في شاشة
 * الصنف ولا يعرفه الماسح.
 *
 * فصار صفُّ الصندوق يحمل باركودَ كلِّ مقاسٍ فعّال (والموقوفُ لا يُرسل)، والماسحُ
 * يُضيف المقاسَ بعينه أو يفتح نافذتَه (`tests/js/a-scanned-size-is-chosen-not-guessed.test.tsx`).
 * والخادمُ كما هو: يبيع المقاسَ بسعره، ويردّ صنفًا له مقاساتٌ بلا مقاس.
 */
class TheTillKnowsEachSizeByItsBarcodeTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل المقاسات', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@sizes.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'بوكيه', 'price' => 4.748, 'cost' => 1, 'quantity' => 50,
            'active' => true, 'barcode' => '6290000000005', 'sku' => 'BQ-1',
        ]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'name' => 'صغير', 'price' => 7.125, 'sku' => 'BQ-S', 'barcode' => '6290000000051', 'active' => true]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'name' => 'كبير', 'price' => 9.333, 'sku' => 'BQ-L', 'barcode' => '6290000000052', 'active' => true]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'name' => 'موقوف', 'price' => 99, 'barcode' => '6290000000059', 'active' => false]);

        $this->actingAs($this->owner);
        $this->activatePosDevice($this->shop->id, $this->branch->id);
        session(['current_branch' => $this->branch->id]);
    }

    public function test_each_live_size_reaches_the_till_with_its_barcode_and_a_stopped_one_does_not(): void
    {
        $till = collect($this->tillProductsOf($this->get(route('pos.index'))))->keyBy('name');

        $sizes = collect($till['بوكيه']['variants'])->keyBy('name');
        $this->assertSame(['صغير', 'كبير'], $sizes->keys()->all(), 'المقاسُ الموقوف لا يصل — فلا يُطابَق باركودُه');
        $this->assertSame('6290000000051', $sizes['صغير']['barcode']);
        $this->assertSame('BQ-L', $sizes['كبير']['sku']);
        $this->assertSame('6290000000052', $sizes['كبير']['barcode']);
    }

    public function test_a_scanned_size_sells_at_its_price_and_a_bouquet_without_one_is_refused(): void
    {
        $large = ProductVariant::where('product_id', $this->bouquet->id)->where('name', 'كبير')->value('id');
        $sale = fn (array $item, float $total, string $uuid) => $this->postJson('/pos/checkout', [
            'items' => [$item + ['id' => $this->bouquet->id, 'name' => 'بوكيه', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => $uuid, 'expected_total' => $total,
        ]);

        // ‏ما كان الماسحُ يُرسله: الصنفُ بلا مقاس — يُردّ ولا يُكتب شيء
        $sale([], 4.985, 'no-size')->assertStatus(422)->assertJsonValidationErrors('items.0.variant_id');
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->where('is_held', false)->count());

        // ‏وما يُرسله الآن: المقاسُ بعينه — بسعره (9.333 + 5% = 9.800)
        $sale(['variant_id' => $large], 9.8, 'large')->assertOk()->assertJsonPath('ok', true);
        $order = Order::where('business_id', $this->shop->id)->where('is_held', false)->sole();
        $this->assertEqualsWithDelta(9.8, (float) $order->total, 0.0001);
        $this->assertSame($large, (int) $order->items[0]->variant_id);
        $this->assertEqualsWithDelta(9.333, (float) $order->items[0]->price, 0.0001);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ‏صنفٌ عليه خصمٌ يصل الصندوقَ بسعر البيع الذي يبيع به الخادم — بالبيسة.
 *
 * ═══ العطب ═══
 *
 * الصندوقُ كان يبيع بـ`price`، والخادمُ بـ`Product::sellingPrice()` بعد خصم
 * الصنف. صنفٌ بـ12.276 عليه 15% في متجرٍ بخمسة: الشاشةُ 12.890 والفاتورةُ
 * 10.957. بالاتّصال يردّ الخادمُ البيعة (`assertScreenTotal`)؛ وبلا اتّصالٍ
 * يُقبض أكثرُ ممّا يُكتب، ويُعلَّم الفرقُ للمراجعة.
 *
 * فصار صفُّ الصندوق يحمل `sale_price` — من الدالّة نفسِها التي يبيع بها
 * الخادم، لا من حسابٍ ثانٍ في المتصفّح. والمقاسُ والإضافةُ لا يُخصمان عنده.
 *
 * والحالاتُ في `tests/fixtures/pos-product-discount.json` يقرؤها هذا الملفّ
 * ويقرؤها `tests/js/a-discounted-product-sells-at-the-servers-price.test.tsx`.
 */
class TheTillSellsADiscountedProductAtTheServersPriceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    /** @var array<string, Product> */
    private array $products = [];

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/pos-product-discount.json'), true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل الخصم', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@discount.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        foreach (self::fixture()['products'] as $p) {
            $product = Product::create([
                'business_id' => $this->shop->id, 'name' => $p['name'], 'price' => $p['price'], 'discount' => $p['discount'],
                'tax' => $p['tax'], 'barcode' => $p['barcode'] ?? null, 'cost' => 1, 'quantity' => 50, 'active' => true,
            ]);
            foreach ($p['variants'] ?? [] as $v) {
                ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $product->id, 'name' => $v['name'], 'price' => $v['price'], 'active' => true]);
            }
            if (isset($p['addon'])) {
                $addon = Addon::create(['business_id' => $this->shop->id, 'name' => $p['addon']['name'], 'price' => $p['addon']['price'], 'active' => true]);
                $addon->update(['scope' => Addon::SCOPE_SELECTED]);
                DB::table('product_addons')->insert(['business_id' => $this->shop->id, 'product_id' => $product->id, 'addon_id' => $addon->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->products[$p['key']] = $product;
        }
    }

    private function onTill(): void
    {
        $this->actingAs($this->owner);
        $this->activatePosDevice($this->shop->id, $this->branch->id);
        session(['current_branch' => $this->branch->id]);
    }

    public function test_the_till_row_carries_the_price_the_server_sells_at_and_the_shared_rows_do_not_change(): void
    {
        $this->onTill();
        $till = collect($this->tillProductsOf($this->get(route('pos.index'))))->keyBy('name');

        foreach (self::fixture()['products'] as $p) {
            $row = $till[$p['name']];
            // ‏السعرُ قبل الخصم كما هو — يقرؤه الطلبُ المخصَّص
            $this->assertSame((float) $p['price'], $row['price'], "«{$p['name']}»: تغيّر السعرُ قبل الخصم");

            if ($p['sale_price'] === null) {
                $this->assertArrayNotHasKey('sale_price', $row, "«{$p['name']}» بلا خصم — يُباع بسعره، فلا يُرسل سعرٌ ثانٍ");

                continue;
            }
            $this->assertSame((float) $p['sale_price'], $row['sale_price'], "«{$p['name']}»: سعرُ البيع غيرُ ما في الحالات المشتركة");
            $this->assertSame($this->products[$p['key']]->sellingPrice(), $row['sale_price'], "«{$p['name']}»: سعرُ البيع غيرُ ما يبيع به الخادم");
        }

        // ‏والمقاسُ بسعره — لا يُخصم عند الخادم ولا يُخصم هنا
        $this->assertSame([9.333], array_column($till['باقة بمقاسات مخصومة']['variants'], 'price'));
        // ‏والقائمةُ المشتركة (المشتريات وغيرُها) كما هي — بلا حقلٍ جديد
        foreach (Demo::products($this->branch->id) as $row) {
            $this->assertArrayNotHasKey('sale_price', $row);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $out = [];
        foreach (self::fixture()['cases'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('cases')]
    public function test_the_invoice_carries_the_total_the_till_now_shows_and_refuses_the_old_one(array $case): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'tax_mode', 'value' => $case['inclusive'] ? 'inclusive' : 'exclusive']);
        $this->onTill();

        $items = [];
        foreach ($case['lines'] as $line) {
            $product = $this->products[$line['key']];
            $item = ['id' => $product->id, 'name' => $product->name, 'qty' => $line['qty']];
            if (isset($line['variant'])) {
                $item['variant_id'] = ProductVariant::where('product_id', $product->id)->where('name', $line['variant'])->value('id');
            }
            if (isset($line['addon'])) {
                $item['addons'] = [['addon_id' => Addon::where('business_id', $this->shop->id)->value('id'), 'qty' => $line['addon']]];
            }
            $items[] = $item;
        }
        $sale = fn (float $total, string $uuid) => $this->postJson('/pos/checkout', [
            'items' => $items, 'payment_method' => 'نقدي', 'client_uuid' => $uuid, 'expected_total' => $total,
        ]);

        // ‏ما كانت الشاشةُ تقوله يُردّ — ولا يُكتب شيء
        if ($case['before'] !== $case['total']) {
            $sale((float) $case['before'], 'before-'.md5($case['name']))->assertStatus(422)->assertJsonValidationErrors('expected_total');
            $this->assertSame(0, Order::where('business_id', $this->shop->id)->where('is_held', false)->count());
        }

        // ‏وما تقوله الآن يُقبل ويُكتب هو هو
        $sale((float) $case['total'], 'now-'.md5($case['name']))->assertOk()->assertJsonPath('ok', true);
        $order = Order::where('business_id', $this->shop->id)->where('is_held', false)->sole();
        $this->assertEqualsWithDelta($case['total'], (float) $order->total, 0.0001, 'الإجماليُّ في الفاتورة غيرُ ما على الشاشة');

        // ‏وسطرُ الفاتورة بسعر البيع (أو بسعر المقاس) — لا بالسعر قبل الخصم
        $fixture = collect(self::fixture()['products'])->keyBy('key');
        foreach ($case['lines'] as $i => $line) {
            $p = $fixture[$line['key']];
            $expected = isset($line['variant']) ? collect($p['variants'])->firstWhere('name', $line['variant'])['price'] : ($p['sale_price'] ?? $p['price']);
            $this->assertEqualsWithDelta($expected, (float) $order->items[$i]->price, 0.0001, "سطرُ «{$p['name']}» بغير سعر بيعه");
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تكاملُ #141 (إضافات الموقع) مع #142 و#143 (مستحقّ البوتيك وتسويته).
 *
 * إضافةٌ قائمةٌ مربوطةٌ برفّ بوتيك تُباع في الموقع: تُكتب لقطتُها مرّةً
 * (`SaleLines::addonRow`) ويُكتب مستحقُّها معها، ويُثبَّت سعرُها قبل البنك
 * كما يُثبَّت سعرُ مكوّن الوصفة. والصندوقُ على حاله بعد الدمج.
 */
class AWebsiteAddonFromABoutiqueShelfIsOwedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $silver;

    private Product $plain;

    private Addon $silverAddon;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'boutiques_enabled' => true, 'boutique_components_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
        ]);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->silver = Product::create([
            'business_id' => $this->shop->id, 'name' => 'قطعة فضّة', 'price' => 7, 'cost' => 3,
            'quantity' => 10, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $this->lama->id,
        ]);
        $this->plain = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة بسيطة', 'price' => 20, 'cost' => 0,
            'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);

        // ربطٌ قائمٌ قبل #141 — يبقى، ويُفتح للموقع بيد صاحبه
        $this->silverAddon = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'فضّة اختياريّة', 'price' => 7,
            'active' => true, 'pos_enabled' => true, 'web_enabled' => true, 'scope' => Addon::SCOPE_ALL,
            'inventory_product_id' => $this->silver->id, 'inventory_quantity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function form(string $pay): array
    {
        return [
            'items' => [['id' => $this->plain->id, 'variant_id' => null, 'qty' => 1, 'addons' => [['id' => $this->silverAddon->id, 'qty' => 1]]]],
            'fulfil' => 'pickup', 'pay' => $pay, 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
        ];
    }

    public function test_a_website_addon_from_a_boutique_shelf_owes_its_price_once(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->form('cod'))->assertOk();

        $order = Order::where('business_id', $this->shop->id)->where('channel', 'website')->firstOrFail();
        $this->assertSame(27.0, (float) $order->total);

        $rows = BoutiqueComponentDue::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(BoutiqueComponentDue::ADDON, $rows->first()->source);
        $this->assertNotNull($rows->first()->order_item_addon_id);
        $this->assertSame(7.0, (float) $rows->first()->amount);

        $this->assertSame(7.0, Ledger::balance($this->shop->id, 'boutique_payable'));
        $this->assertSame(7.0, Ledger::balance($this->shop->id, 'cogs'));
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);
    }

    public function test_a_website_addon_price_is_pinned_before_the_bank(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876', 'active' => true,
        ]);
        Http::fake(['oman.paymob.com/v1/intention/' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);

        $this->postJson('/s/ribbon/checkout', $this->form('card'))->assertOk();
        $intent = StorePaymentIntent::where('business_id', $this->shop->id)->firstOrFail();

        $this->silver->update(['price' => 25]);
        $intent->update(['status' => StorePaymentIntent::PAID, 'provider_transaction_id' => 'tx-1']);
        $order = WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent->fresh());

        $this->assertSame(27.0, (float) $order->total);
        $this->assertSame(7.0, BoutiqueDues::owedFor($order));
    }

    public function test_the_till_still_owes_for_a_boutique_addon_after_the_merge(): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->plain->id, 'name' => $this->plain->name, 'qty' => 1, 'price' => 20,
                'addons' => [['addon_id' => $this->silverAddon->id, 'qty' => 2]]]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(34.0, (float) $order->total);
        $this->assertSame(14.0, BoutiqueDues::owedFor($order));
        $this->assertSame(8, (int) $this->silver->fresh()->quantity);
    }
}

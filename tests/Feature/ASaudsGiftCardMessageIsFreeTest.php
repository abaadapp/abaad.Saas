<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\GiftCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * رسالةُ كرت الهدية مجّانيّةٌ في إتمام RIBBON — لمتجر سعود وحده.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لمن في `storefront.ribbon_free_gift_card_message_businesses` وحده: خانةُ
 *     «أضف رسالة على كرت الهدية» بلا ثمنٍ ولا رفعِ ملفّ — ولو كان كرتُه
 *     المدفوع مفعّلًا مسعّرًا في الإعداد.
 *   - والمالُ لا يتحرّك: المجموعُ والخصمُ والضريبةُ والتوصيلُ والإجماليُّ
 *     ومبلغُ Paymob كما لو لم تُكتب رسالة. ولا سطرَ بيعٍ ولا صنفَ بصفرٍ ولا
 *     حركةَ مخزون — ولو أُرسل `gift_card=true` بيد صاحبه.
 *   - والنصُّ في `orders.card_message`.
 *   - وغيرُه يبيع كرتَه كما كان: ثمنُه في المجموع، وبندُه في الطلب، ورفعُه يعمل.
 *
 * والمتجران عامّان — يُكتب معرّفُ أحدهما في الإعداد، ولا يُفرض المعرّف ٥.
 * وسلوكُ الخانة في الصفحة في
 * `tests/js/the-ribbon-gift-card-message-costs-nothing.test.ts`.
 */
class ASaudsGiftCardMessageIsFreeTest extends TestCase
{
    use RefreshDatabase;

    private Business $free;

    private Business $paid;

    private Product $rose;

    private Product $theirRose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        Storage::fake('local');

        // كلاهما رفع مفتاحَ الكرت وسعّره — والفرقُ القائمةُ وحدها
        [$this->free, $this->rose] = $this->shop('ribbon', '0.200');
        [$this->paid, $this->theirRose] = $this->shop('paid', '0.500');

        config(['storefront.ribbon_free_gift_card_message_businesses' => [$this->free->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: Product} */
    private function shop(string $slug, string $cardPrice): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $shop->id, 'name' => 'مالك '.$slug, 'email' => $slug.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
            'store_delivery_fee' => '2', 'store_free_delivery_over' => '',
            'store_gift_card' => '1', 'store_gift_card_price' => $cardPrice,
        ]);

        $rose = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return [$shop, $rose];
    }

    private function order(Product $rose, array $over = []): array
    {
        return $over + [
            'items' => [['id' => $rose->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ];
    }

    /** @return array<string, mixed> ما يحمل المالَ من التسعيرة */
    private function money(string $slug, array $extra): array
    {
        $q = $this->postJson("/s/{$slug}/quote", $extra + [
            'items' => [['id' => ($slug === 'ribbon' ? $this->rose : $this->theirRose)->id, 'qty' => 1]],
            'fulfil' => 'delivery',
        ])->assertOk()->json();

        return [
            'subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'tax' => $q['tax'],
            'delivery' => $q['delivery'], 'total' => $q['total'],
            'lines' => array_map(fn ($l) => [$l['name'], (float) $l['price'], (int) $l['qty']], $q['lines']),
        ];
    }

    /* ═══════════ لمن ═══════════ */

    /*
     * وخرج منها سعود (2026-10-02): كرتُه صار صنفًا من رفّه
     * (`ribbon_gift_card_product_businesses`) — فالقائمةُ تُشحن فارغة،
     * والسلوكُ باقٍ لمن يُكتب فيها. انظر `ASaudsGiftCardIsAProductWithItsMessageTest`.
     */
    public function test_the_shipped_list_is_empty_now_that_saud_sells_his_card_as_a_product(): void
    {
        $config = require config_path('storefront.php');

        $this->assertSame([], $config['ribbon_free_gift_card_message_businesses']);
        $this->assertSame([5], $config['ribbon_gift_card_product_businesses']);
        $this->assertArrayNotHasKey('paymob_businesses', $config, 'البوّابةُ لا قائمةَ لها — لكلّ متجرٍ ذي سلّةٍ مفاتيحُه');
    }

    public function test_no_business_id_is_written_outside_the_list(): void
    {
        foreach ([
            app_path('Support/Store/GiftCard.php'),
            app_path('Support/Store/WebCheckout.php'),
            app_path('Http/Controllers/Store/RibbonController.php'),
            resource_path('views/store/ribbon/checkout.blade.php'),
            resource_path('js/store/ribbon-card-message.js'),
        ] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(business_?[iI]d|bid|id)\s*(===?|!==?)\s*5\b|\[\s*5\s*\]/',
                file_get_contents($file),
                basename($file).' يسأل عن متجرٍ بمعرّفه',
            );
        }
    }

    public function test_the_paid_setting_stays_as_it_was_and_only_the_list_decides(): void
    {
        $this->assertTrue(GiftCard::messageOnly($this->free->id));
        $this->assertFalse(GiftCard::enabled($this->free->id), 'الكرتُ المدفوع ما زال يُباع عند من رسالتُه مجّانيّة');
        $this->assertSame(0.2, GiftCard::price($this->free->id), 'الثمنُ المحفوظ يبقى في الإعداد — لا يُمحى');

        $this->assertFalse(GiftCard::messageOnly($this->paid->id));
        $this->assertTrue(GiftCard::enabled($this->paid->id));

        // ورفعُه من القائمة يُعيد كرتَه يُباع — بلا تعديلِ بيانات
        config(['storefront.ribbon_free_gift_card_message_businesses' => []]);
        $this->assertTrue(GiftCard::enabled($this->free->id));
    }

    /* ═══════════ الشاشة ═══════════ */

    public function test_the_free_checkout_offers_a_message_without_a_price_or_a_file(): void
    {
        $res = $this->get('/s/ribbon/checkout')->assertOk();
        $html = $res->getContent();

        $this->assertTrue($res->viewData('giftCard')['message_only']);
        $this->assertFalse($res->viewData('giftCard')['on']);
        $this->assertSame('', $res->viewData('giftCard')['price_text']);

        $this->assertStringContainsString('<input type="checkbox" data-rb-cardmsg', $html);
        $this->assertMatchesRegularExpression('/data-rb-cardmsg[^>]*>\s*<span>أضف رسالة على كرت الهدية<\/span>/u', $html);
        $this->assertStringContainsString('اكتب رسالتك التي تريد إرفاقها مع الطلب', $html);
        $this->assertMatchesRegularExpression('/data-rb-cardmsgbox hidden/', $html, 'خانةُ الكتابة مطويّةٌ حتّى تُختار');
        $this->assertStringContainsString('window.RBCardMessage', $html);

        // لا ثمنَ ولا كرتَ مدفوعًا ولا ملفّ — في المرسوم لا في محدِّدات السكربت المشترك
        $this->assertStringNotContainsString('0.200', $html);
        $this->assertStringNotContainsString('data-rb-cardprice>', $html);
        $this->assertStringNotContainsString('<input type="checkbox" data-rb-giftcard', $html);
        $this->assertStringNotContainsString('<input type="file" data-rb-cardfile', $html);
        $this->assertStringNotContainsString('rb-card-way-file', $html);
        $this->assertStringNotContainsString('>أضف كرت هدية<', $html);
    }

    public function test_the_english_checkout_says_it_in_english(): void
    {
        $html = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-rb-cardmsg[^>]*>\s*<span>Add a gift card message<\/span>/', $html);
        $this->assertStringContainsString('Write the message you want included with the order', $html);
        $this->assertStringNotContainsString('0.200', $html);
    }

    public function test_the_paid_shop_keeps_its_priced_card_and_its_file_choice(): void
    {
        $res = $this->get('/s/paid/checkout')->assertOk();
        $html = $res->getContent();

        $this->assertFalse($res->viewData('giftCard')['message_only']);
        $this->assertTrue($res->viewData('giftCard')['on']);
        $this->assertStringContainsString('<input type="checkbox" data-rb-giftcard', $html);
        $this->assertMatchesRegularExpression('/data-rb-cardprice>0\.500/', $html);
        $this->assertStringContainsString('rb-card-way-file', $html);
        $this->assertStringNotContainsString('<input type="checkbox" data-rb-cardmsg', $html);
        $this->assertStringNotContainsString('window.RBCardMessage', $html, 'سكربتُ الرسالة المجّانيّة ضُمِّن عند من يبيع كرتَه');
    }

    /* ═══════════ المال ═══════════ */

    public function test_the_message_moves_no_money_even_with_vat_a_coupon_and_a_forged_flag(): void
    {
        Setting::create(['business_id' => $this->free->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::where('business_id', $this->free->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Coupon::create(['business_id' => $this->free->id, 'code' => 'TEN', 'type' => 'نسبة', 'value' => 10, 'min_order' => 0, 'active' => true, 'used_count' => 0]);
        // وحدُّ التوصيل المجّانيّ فوق المجموع بقدر الكرت — فلو دخل الكرتُ لَعبره
        MarketingSettings::save($this->free->id, 'website', ['store_free_delivery_over' => '20.1']);

        $bare = $this->money('ribbon', ['promo' => 'TEN']);
        $message = $this->money('ribbon', ['promo' => 'TEN', 'card' => 'كل عام وأنت بخير']);
        $forged = $this->money('ribbon', ['promo' => 'TEN', 'card' => 'كل عام وأنت بخير', 'gift_card' => true]);

        $this->assertGreaterThan(0, $bare['tax'], 'الضريبةُ لم تُحسب — فلا يُحرس شيء');
        $this->assertGreaterThan(0, $bare['discount'], 'الخصمُ لم يُحسب — فلا يُحرس شيء');
        $this->assertSame(2.0, (float) $bare['delivery']);

        $this->assertSame($bare, $message);
        $this->assertSame($bare, $forged, 'علامةُ الكرت المدفوع بيد المرسِل حرّكت المال');
        $this->assertSame([['باقة ورد', 20.0, 1]], $forged['lines']);
    }

    public function test_the_order_keeps_the_text_and_sells_nothing_for_it(): void
    {
        $before = [Product::count(), InventoryMovement::count()];

        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['card' => 'كل عام وأنت بخير']))->assertOk();

        $order = Order::where('business_id', $this->free->id)->firstOrFail();
        $this->assertSame('كل عام وأنت بخير', $order->card_message);
        $this->assertNull($order->card_file);

        // بندٌ واحد: الباقة — لا كرتَ بثمنٍ ولا بصفر
        $this->assertSame([['باقة ورد', 20.0]], $order->items->map(fn (OrderItem $i) => [$i->name, (float) $i->total])->all());
        $this->assertSame(0, Product::where('name', GiftCard::PRODUCT_NAME)->count(), 'أُنشئ صنفُ كرت');
        $this->assertSame($before[0], Product::count());

        // والمالُ مالُ الطلب بلا رسالة: ٢٠ + ٢ توصيل
        $this->assertSame(22.0, (float) $order->total);
        $this->assertSame(20.0, (float) $order->subtotal);
        $this->assertSame(22.0, (float) Transaction::where('order_id', $order->id)->sole()->amount);

        // وحركةُ المخزون للباقة وحدها
        $this->assertSame([$this->rose->id], InventoryMovement::where('business_id', $this->free->id)->pluck('product_id')->unique()->values()->all());
    }

    public function test_a_forged_paid_card_on_the_free_shop_is_refused_not_sold(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['gift_card' => true, 'card' => 'نصّ']))
            ->assertStatus(422)->assertJsonValidationErrors('gift_card');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Product::where('name', GiftCard::PRODUCT_NAME)->count());
    }

    public function test_paymob_is_asked_for_the_same_amount_with_or_without_the_message(): void
    {
        PaymentGateway::create([
            'business_id' => $this->free->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876', 'active' => true,
        ]);
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);

        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['pay' => 'card']))->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['pay' => 'card', 'card' => 'كل عام وأنت بخير']))->assertOk();

        $amounts = collect(Http::recorded())->map(fn ($pair) => $pair[0]['amount'])->all();
        $this->assertSame([22000, 22000], $amounts);
    }

    public function test_the_free_shop_does_not_take_card_files(): void
    {
        $this->postJson('/s/ribbon/gift-card', ['file' => UploadedFile::fake()->image('card.png')])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles('gift-cards'));
    }

    /* ═══════════ وغيرُه يبيع كرتَه كما كان ═══════════ */

    public function test_the_paid_shop_still_sells_its_card(): void
    {
        $bare = $this->money('paid', []);
        $with = $this->money('paid', ['gift_card' => true]);

        $this->assertSame(20.0, (float) $bare['subtotal']);
        $this->assertSame(20.5, (float) $with['subtotal']);
        $this->assertSame(round((float) $bare['total'] + 0.5, 3), (float) $with['total']);

        $this->postJson('/s/paid/checkout', $this->order($this->theirRose, ['gift_card' => true, 'card' => 'مبروك']))->assertOk();

        $order = Order::where('business_id', $this->paid->id)->firstOrFail();
        $line = $order->items->firstWhere('name', GiftCard::PRODUCT_NAME);
        $this->assertNotNull($line, 'الكرتُ المدفوع لم يُكتب بندًا');
        $this->assertSame(0.5, (float) $line->total);
        $this->assertNotNull($line->product_id);
        $this->assertSame('مبروك', $order->card_message);
    }

    public function test_the_paid_shop_still_takes_card_files(): void
    {
        $this->postJson('/s/paid/gift-card', ['file' => UploadedFile::fake()->image('card.png')])
            ->assertOk()->assertJson(['ok' => true]);
    }
}

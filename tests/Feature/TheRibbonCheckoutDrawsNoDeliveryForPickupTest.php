<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\RibbonTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * إتمامُ RIBBON — لا صفَّ توصيلٍ لمن يستلم، ولا طلبَ بإجماليٍّ لم يره.
 *
 * ═══ الحالُ التي أنشأت هذا الملفّ ═══
 *
 * يختار الزبونُ «استلام من المحل» فيقرأ في ملخّصه «التوصيل: مجاني» — والرسمُ
 * صفرٌ لأنّه لا توصيلَ أصلًا. ووراءه عطبان آخران في الصفحة نفسِها:
 *
 *   - كانت الصفحةُ تبدأ بأوّل ما في القائمة، والقائمةُ تبدأ بالاستلام
 *     (`FlowerOrder::FULFILLMENT`)، والزرُّ المضيءُ «توصيل». فمن لم يلمس
 *     الزرّين سُعّرت سلّتُه استلامًا وأُرسل طلبُه استلامًا.
 *   - وكلُّ تسعيرةٍ تُرسم حين تعود: تبديلٌ سريع يرسم تسعيرةً قديمة، وضغطةُ
 *     «تأكيد» قبل عودة الأخيرة تُرسل طلبًا بإجماليٍّ غيرِ الذي رآه.
 *
 * ═══ وما يُحرس ═══
 *
 *   - الخادمُ يسعّر الاستلامَ بلا رسم ويقول إنّه استلام؛ والتوصيلَ برسمه، أو
 *     صفرًا لِما بلغ حدَّ المجّانيّ — وهذا وحده «مجاني».
 *   - والصفحةُ تبدأ بالتوصيل متى فُتح، وزرُّه المضيء، وصفُّ التوصيل مطويٌّ
 *     حتّى يقرّره `RBQuote.shipping` من التسعيرة.
 *   - وطلبٌ أُرسل بإجماليٍّ غيرِ الذي صار إليه يُردّ بالإجماليّ الجديد — في
 *     الدفع عند الاستلام وقبل أن تُفتح صفحةُ البطاقة — ولا يُكتب طلبٌ ولا
 *     تُفتح دفعة. ومن لم يرسل إجماليًّا (صفحةٌ قديمة) يمضي كما كان.
 *   - بالعربيّة والإنجليزيّة.
 *
 * والسباقُ بين التسعيرات وقفلُ الزرّ في
 * `tests/js/the-ribbon-checkout-draws-only-its-latest-quote.test.ts`.
 */
class TheRibbonCheckoutDrawsNoDeliveryForPickupTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-03-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'pickup@abaad.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_delivery_fee' => '2', 'store_free_delivery_over' => '50',
            'store_fulfil' => 'delivery,pickup',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    private function quote(string $fulfil, int $qty = 1, string $lang = 'ar'): array
    {
        return $this->postJson('/s/ribbon/quote?lang='.$lang, [
            'items' => [['id' => $this->rose->id, 'qty' => $qty]], 'fulfil' => $fulfil,
        ])->assertOk()->json();
    }

    private function order(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨ نوفمبر، منزل ٤',
            'date' => '2027-03-12',
        ];
    }

    private function place(array $over = [], string $lang = 'ar')
    {
        return $this->postJson('/s/ribbon/checkout?lang='.$lang, $this->order($over));
    }

    private function checkoutPage(string $lang = 'ar'): string
    {
        return $this->get('/s/ribbon/checkout?lang='.$lang)->assertOk()->getContent();
    }

    /* ═══════════════════════ الخادمُ يسعّر ═══════════════════════ */

    public function test_pickup_is_quoted_with_no_delivery_and_says_it_is_pickup(): void
    {
        $q = $this->quote(FlowerOrder::PICKUP);

        $this->assertSame(FlowerOrder::PICKUP, $q['fulfil']);
        $this->assertEquals(0, $q['delivery']);
        $this->assertEquals(20, $q['total']);
    }

    public function test_delivery_is_quoted_with_its_fee(): void
    {
        $q = $this->quote(FlowerOrder::DELIVERY);

        $this->assertSame(FlowerOrder::DELIVERY, $q['fulfil']);
        $this->assertEquals(2, $q['delivery']);
        $this->assertSame('2.000 ر.ع', $q['delivery_text']);
        $this->assertEquals(22, $q['total']);
    }

    /** وهذا وحده «مجاني»: توصيلٌ بلغ حدَّه — لا استلامٌ بلا توصيل */
    public function test_delivery_over_the_free_threshold_is_genuinely_free(): void
    {
        $q = $this->quote(FlowerOrder::DELIVERY, 3);

        $this->assertSame(FlowerOrder::DELIVERY, $q['fulfil']);
        $this->assertEquals(0, $q['delivery']);
        $this->assertEquals(60, $q['total']);
    }

    /* ═══════════════════════ والصفحةُ ترسم ═══════════════════════ */

    public function test_the_page_starts_on_delivery_when_it_is_offered_and_lights_that_button(): void
    {
        $html = $this->checkoutPage();

        $this->assertMatchesRegularExpression('/fulfil = "delivery"/', $html, 'الصفحةُ تبدأ بالتوصيل كما يبدأ الخادم');
        $this->assertMatchesRegularExpression('/class="rb-pill on" data-v="delivery" aria-pressed="true"/', $html);
        $this->assertMatchesRegularExpression('/class="rb-pill" data-v="pickup" aria-pressed="false"/', $html);
    }

    public function test_a_shop_that_only_hands_over_starts_on_pickup_and_asks_nothing(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_fulfil' => 'pickup']);

        $html = $this->checkoutPage();

        $this->assertMatchesRegularExpression('/fulfil = "pickup"/', $html);
        $this->assertStringNotContainsString('data-testid="rb-fulfil-delivery"', $html, 'طريقةٌ واحدةٌ لا تُسأل');
        $this->assertStringNotContainsString('data-testid="rb-fulfil-pickup"', $html);
    }

    public function test_the_delivery_row_is_folded_until_the_quote_decides_it(): void
    {
        $html = $this->checkoutPage();

        $this->assertMatchesRegularExpression('/<div style="display:none;justify-content:space-between" data-rb-ship-row data-testid="rb-ship-row">/', $html);
        $this->assertStringContainsString('window.RBQuote', $html, 'مكتبةُ التسعيرة مضمَّنةٌ في الصفحة');
        $this->assertStringContainsString('RBQuote.shipping(q, T)', $html);
        // ولا «مجاني» لكلّ رسمٍ صفرٍ كما كان — الصفُّ يُكتب من `RBQuote.shipping` وحدها
        $this->assertStringNotContainsString("\$('[data-rb-ship]').textContent = q.delivery > 0", $html);
        $this->assertStringContainsString("\$('[data-rb-ship]').textContent = ship.text;", $html);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function languages(): iterable
    {
        yield 'العربيّة' => ['ar', 'التوصيل', 'استلام من المحل', 'مجاني'];
        yield 'English' => ['en', 'Delivery', 'Pick up', 'Free'];
    }

    #[DataProvider('languages')]
    public function test_each_language_names_the_row_and_the_choices_in_its_own_words(string $lang, string $row, string $pickup, string $free): void
    {
        $t = RibbonTexts::for($lang);
        $html = $this->checkoutPage($lang);

        $this->assertSame($row, $t['shipping']);
        $this->assertStringContainsString('data-testid="rb-ship-row"><span>'.e($t['shipping']).'</span>', $html);
        $this->assertStringContainsString($pickup, $t['pickup']);
        $this->assertSame($free, $t['free']);
        // ونصُّ «يُحدَّث الإجمالي» يصل الصفحةَ بلغتها — في نصوصها المضمَّنة (`@json`)
        $this->assertStringContainsString(json_encode($t['quoting'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), $html);
        $this->assertNotSame(RibbonTexts::for('ar')['quoting'], RibbonTexts::for('en')['quoting']);
    }

    /* ═══════════════════════ وما يُكتب هو ما رآه ═══════════════════════ */

    public function test_pickup_is_placed_with_no_delivery_fee_at_the_total_it_saw(): void
    {
        $this->place(['agreed_total' => 20])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(FlowerOrder::PICKUP, $order->fulfillment_type);
        $this->assertSame('0.000', (string) $order->delivery_fee);
        $this->assertSame('20.000', (string) $order->total);
        $this->assertNull($order->delivery_address, 'لا عنوانَ لطلب استلام');
    }

    /**
     * رأى إجماليَّ الاستلام، ثمّ بدّل إلى التوصيل وضغط قبل أن تعود التسعيرة.
     */
    public function test_a_total_that_moved_since_it_was_shown_is_refused_and_named(): void
    {
        $before = Order::count();

        $res = $this->place(['fulfil' => 'delivery', 'agreed_total' => 20])->assertStatus(422);
        $this->assertStringContainsString('22.000', implode(' ', (array) $res->json('errors.total')));
        $this->assertSame($before, Order::count(), 'كُتب طلبٌ بإجماليٍّ لم يره');

        // فيقرّ بالجديد — وهو ما ترسله الصفحةُ بعد أن ترسمه
        $this->place(['fulfil' => 'delivery', 'agreed_total' => 22])->assertOk();
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('2.000', (string) $order->delivery_fee);
        $this->assertSame('22.000', (string) $order->total);
    }

    public function test_the_refusal_speaks_the_page_language(): void
    {
        $res = $this->place(['fulfil' => 'delivery', 'agreed_total' => 20], 'en')->assertStatus(422);
        $said = implode(' ', (array) $res->json('errors.total'));

        $this->assertStringContainsString('22.000', $said);
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}{3,}/u', str_replace(['ر.ع'], '', $said), 'رسالةٌ عربيّة على الصفحة الإنجليزيّة');
    }

    /** وصفحةٌ قديمةٌ في متصفّحٍ لا ترسل إجماليًّا — لا يُخترع لها إقرار */
    public function test_a_page_that_sends_no_total_is_not_asked_for_one(): void
    {
        $this->place(['fulfil' => 'delivery'])->assertOk();

        $this->assertSame('22.000', (string) Order::latest('id')->firstOrFail()->total);
    }

    public function test_the_card_page_does_not_open_for_a_total_it_did_not_see(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            // مفاتيحُ تجريبيّةٌ — لا مفتاحَ حقيقيٍّ في اختبار، ولا دفعةَ حقيقيّة
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_test_x', 'card_integration_id' => '4569876', 'active' => true,
        ]);
        Http::fake(['oman.paymob.com/v1/intention/' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);

        $this->place(['pay' => 'card', 'fulfil' => 'delivery', 'agreed_total' => 20])->assertStatus(422)->assertJsonValidationErrors(['total']);
        $this->assertSame(0, StorePaymentIntent::count(), 'فُتحت دفعةٌ بإجماليٍّ لم يره');
        Http::assertNothingSent();

        $this->place(['pay' => 'card', 'fulfil' => 'delivery', 'agreed_total' => 22])->assertOk()->assertJsonStructure(['redirect']);
        $this->assertSame('22.000', (string) StorePaymentIntent::latest('id')->firstOrFail()->amount);
        $this->assertSame(0, Order::count(), 'البطاقةُ لا تكتب طلبًا قبل أن يصل المال');
    }
}

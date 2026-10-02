<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\GiftCard;
use App\Support\Store\GiftCardProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كرتُ هدية سعود صنفٌ من رفّه ونصُّه معه — واسمُ الزبون وعنوانُه بالإنجليزيّة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإتمامُ عنده بلا شيءٍ من الكرت: لا رسالةٌ مجّانيّة ولا كرتٌ مدفوع.
 *   - صفحةُ صنف «كرت هدية» تطلب نصَّه، وصفحةُ الصنف العاديّ لا.
 *   - الخادمُ يردّ الكرتَ بلا نصّ في التسعيرة والإتمام، ويُسعّره من الصنف.
 *   - النصُّ في بند الطلب وفي `orders.card_message`، وكرتان بنصّين بندان.
 *   - صنفٌ بالاسم نفسِه في متجرٍ آخر ليس كرتًا هنا.
 *   - الاسمان والعنوانُ بالإنجليزيّة عنده وحده.
 *   - والمتجرُ الآخر على حاله: كرتُه المدفوع يُباع والعربيّةُ تُقبل.
 *
 * والمتجران عامّان — يُكتب معرّفُ أحدهما في الإعداد، ولا يُفرض المعرّف ٥.
 */
class ASaudsGiftCardIsAProductWithItsMessageTest extends TestCase
{
    use RefreshDatabase;

    private Business $saud;

    private Business $other;

    private Product $rose;

    private Product $card;

    private Product $theirRose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        Storage::fake('local');

        // كلاهما رفع مفتاحَ الكرت المدفوع وسعّره — والفرقُ القائمتان وحدهما
        [$this->saud, $this->rose] = $this->shop('ribbon');
        [$this->other, $this->theirRose] = $this->shop('other');

        $this->card = Product::create([
            'business_id' => $this->saud->id, 'name' => GiftCard::PRODUCT_NAME, 'name_en' => 'Gift card',
            'price' => 1.5, 'cost' => 0, 'quantity' => 0, 'alert_qty' => 0, 'tracks_stock' => false,
            'active' => true, 'published' => true,
        ]);

        config([
            'storefront.ribbon_free_gift_card_message_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [$this->saud->id],
            'storefront.ribbon_english_checkout_businesses' => [$this->saud->id],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: Product} */
    private function shop(string $slug): array
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
            'store_gift_card' => '1', 'store_gift_card_price' => '0.500',
        ]);

        $rose = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return [$shop, $rose];
    }

    private function order(array $items, array $over = []): array
    {
        return $over + [
            'items' => $items,
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'Maryam Al-Balushi', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'Way 1234, Bldg #5', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ];
    }

    private function cardItem(string $note, array $over = []): array
    {
        return $over + ['id' => $this->card->id, 'qty' => 1, 'note' => $note];
    }

    /* ═══════════ الإتمام بلا كرت ═══════════ */

    public function test_saud_checkout_carries_no_gift_card_control_and_the_other_keeps_its_own(): void
    {
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        // في الوسوم لا في نصّ السكربت: السكربتُ يسأل عنها ولا يجدها
        foreach (['data-rb-giftcard', 'data-rb-cardmsg', 'name="card"', 'data-rb-cardfile', 'data-rb-cardway', 'data-rb-align'] as $mark) {
            $this->assertDoesNotMatchRegularExpression('/<[a-z]+\s[^>]*'.preg_quote($mark, '/').'/', $html, "إتمامُ سعود ما زال يعرض $mark");
        }
        $this->assertFalse(GiftCard::enabled($this->saud->id), 'الكرتُ المدفوع عاد لسعود مع خروجه من قائمة الرسالة');

        $theirs = $this->get('/s/other/checkout')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*data-rb-giftcard/', $theirs, 'المتجرُ الآخر فقد كرتَه المدفوع');
        $this->assertTrue(GiftCard::enabled($this->other->id));
    }

    /* ═══════════ صفحةُ الصنف ═══════════ */

    public function test_the_card_page_asks_for_its_message_and_a_rose_page_does_not(): void
    {
        $card = $this->get('/s/ribbon/p/'.$this->card->id)->assertOk();
        $card->assertSee('data-rb-card-note', false)->assertSee('رسالة كرت الهدية');
        $html = $card->getContent();
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('data-rb-align', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);

        $this->get('/s/ribbon/p/'.$this->rose->id)->assertOk()->assertDontSee('data-rb-card-note', false);
    }

    /* ═══════════ الخادمُ يحرس ═══════════ */

    public function test_a_forged_card_without_its_message_is_refused_on_quote_and_checkout(): void
    {
        foreach (['', '   ', null] as $note) {
            $this->postJson('/s/ribbon/quote', ['items' => [$this->cardItem((string) $note)]])
                ->assertStatus(422)
                ->assertJsonPath('errors.items.0', 'اكتب رسالة كرت الهدية قبل إضافته إلى الطلب.');

            $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem((string) $note)]))
                ->assertStatus(422)
                ->assertJsonPath('errors.items.0', 'اكتب رسالة كرت الهدية قبل إضافته إلى الطلب.');
        }

        $this->postJson('/s/ribbon/checkout', $this->order([['id' => $this->card->id, 'qty' => 1]]))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_a_message_longer_than_the_card_is_refused_not_cut(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem(str_repeat('a', GiftCardProduct::MAX + 1))]))
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::count());
    }

    public function test_the_card_is_priced_from_the_product_and_its_message_kept_on_the_order(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([
            $this->cardItem("  Happy birthday\nfrom us  ", ['price' => 0.001]),
            ['id' => $this->rose->id, 'qty' => 1, 'note' => 'لا يُكتب', 'price' => 1],
        ]))->assertOk()->assertJsonPath('ok', true);

        $order = Order::with('items')->sole();
        $card = $order->items->firstWhere('product_id', $this->card->id);
        $rose = $order->items->firstWhere('product_id', $this->rose->id);

        $this->assertSame(1.5, (float) $card->price, 'ثمنُ الكرت من المتصفّح لا من الصنف');
        $this->assertSame("Happy birthday\nfrom us", $card->note);
        $this->assertNull($rose->note, 'بندٌ عاديٌّ حمل نصًّا من المتصفّح');
        $this->assertSame(20.0, (float) $rose->price);
        $this->assertSame("Happy birthday\nfrom us", $order->card_message);
        $this->assertSame(23.5, (float) $order->total, 'الإجماليّ: ورد ٢٠ + كرت ١٫٥ + توصيل ٢');

        // صنفٌ خارج دفتر المخزون لا تُكتب له حركة
        $this->assertSame(0, (int) $this->card->fresh()->quantity);
        $this->assertSame(9, (int) $this->rose->fresh()->quantity);
    }

    public function test_two_cards_with_two_messages_stay_two_lines_and_both_are_kept(): void
    {
        $q = $this->postJson('/s/ribbon/quote', ['items' => [$this->cardItem('For Mum'), $this->cardItem('For Dad')]])
            ->assertOk()->json('lines');

        $this->assertSame([[true, 'For Mum'], [true, 'For Dad']], array_map(fn ($l) => [$l['gift_card'], $l['note']], $q));

        $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem('For Mum'), $this->cardItem('For Dad')]))->assertOk();

        $order = Order::with('items')->sole();
        $this->assertSame(['For Mum', 'For Dad'], $order->items->pluck('note')->all());
        $this->assertSame("For Mum\n\nFor Dad", $order->card_message);
    }

    public function test_a_card_named_product_of_another_shop_is_not_a_card_here(): void
    {
        $theirCard = Product::create([
            'business_id' => $this->other->id, 'name' => GiftCard::PRODUCT_NAME, 'price' => 0.1, 'cost' => 0,
            'quantity' => 5, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);

        $this->assertFalse(GiftCardProduct::is($this->saud->id, $theirCard));
        $this->assertFalse(GiftCardProduct::is($this->other->id, $theirCard), 'متجرٌ خارج القائمة صار كرتُه صنفًا');

        $this->postJson('/s/ribbon/checkout', $this->order([['id' => $theirCard->id, 'qty' => 1, 'note' => 'Hi']]))
            ->assertStatus(422);
        $this->assertSame(0, Order::count());

        // وصنفُ «كرت هدية» في المتجر الآخر يبقى صنفًا عاديًّا: بلا نصٍّ ولا ردّ
        $this->postJson('/s/other/checkout', $this->order([['id' => $theirCard->id, 'qty' => 1, 'note' => 'Hi']], ['name' => 'مريم', 'address' => 'شارع ١٨']))
            ->assertOk();
        $this->assertNull(Order::with('items')->sole()->items->first()->note);
    }

    public function test_an_unpublished_card_is_not_a_card_and_is_not_sold(): void
    {
        $this->card->update(['published' => false]);

        $this->assertFalse(GiftCardProduct::is($this->saud->id, $this->card->fresh()));
        $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem('Hi')]))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_the_other_shop_still_sells_its_paid_card_as_before(): void
    {
        $q = $this->postJson('/s/other/quote', [
            'items' => [['id' => $this->theirRose->id, 'qty' => 1]], 'gift_card' => true,
        ])->assertOk()->json();

        $this->assertSame([['باقة ورد', 20.0], [GiftCard::PRODUCT_NAME, 0.5]], array_map(fn ($l) => [$l['name'], (float) $l['price']], $q['lines']));

        // وسعودُ يُردّ إن أرسل `gift_card` بيده — كرتُه صنفٌ لا خانة
        $this->postJson('/s/ribbon/checkout', $this->order([['id' => $this->rose->id, 'qty' => 1]], ['gift_card' => true]))
            ->assertStatus(422)->assertJsonValidationErrors('gift_card');
    }

    /* ═══════════ الإنجليزيّة لسعود وحده ═══════════ */

    public function test_saud_takes_english_names_and_addresses_only(): void
    {
        $rose = [['id' => $this->rose->id, 'qty' => 1]];

        $this->postJson('/s/ribbon/checkout', $this->order($rose, ['name' => 'مريم']))
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'اكتب الاسم بالإنجليزية فقط.');
        $this->postJson('/s/ribbon/checkout', $this->order($rose, ['recipient_name' => 'سارة', 'recipient_phone' => '96899110002']))
            ->assertStatus(422)->assertJsonPath('errors.recipient_name.0', 'اكتب اسم المستلم بالإنجليزية فقط.');
        $this->postJson('/s/ribbon/checkout', $this->order($rose, ['address' => 'شارع ١٨، الخوير']))
            ->assertStatus(422)->assertJsonPath('errors.address.0', 'اكتب العنوان بالإنجليزية فقط.');
        $this->assertSame(0, Order::count());

        $this->postJson('/s/ribbon/checkout', $this->order($rose, [
            'name' => "Mary O'Neil-Smith Jr.",
            'recipient_name' => 'Sara D’Souza', 'recipient_phone' => '96899110002',
            'address' => "Way 1234, Bldg #5 (Flat 2) - Al Khuwair / Muscat. Near Lulu's",
        ]))->assertOk();

        $order = Order::sole();
        $this->assertSame('Sara D’Souza', $order->recipient_name);
    }

    public function test_the_other_shop_still_takes_arabic_as_before(): void
    {
        $this->postJson('/s/other/checkout', $this->order([['id' => $this->theirRose->id, 'qty' => 1]], [
            'name' => 'مريم', 'recipient_name' => 'سارة', 'recipient_phone' => '96899110002', 'address' => 'شارع ١٨، الخوير',
        ]))->assertOk();

        $this->assertSame('سارة', Order::sole()->recipient_name);
    }

    public function test_the_three_fields_are_written_left_to_right_for_saud_alone(): void
    {
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        foreach (['name', 'recipient_name', 'address'] as $f) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="'.$f.'"[^>]*dir="ltr" lang="en"/', $html, "$f لا يُكتب من اليسار");
        }
        // والصفحةُ نفسُها بلغتها
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="phone"[^>]*lang="en"/', $html);

        $theirs = $this->get('/s/other/checkout')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*lang="en"/', $theirs);
    }

    public function test_no_business_id_is_written_outside_the_lists(): void
    {
        foreach ([
            app_path('Support/Store/GiftCardProduct.php'),
            app_path('Support/Store/EnglishCheckout.php'),
            app_path('Support/Store/WebCheckout.php'),
            app_path('Http/Controllers/Store/RibbonController.php'),
            resource_path('views/store/ribbon/checkout.blade.php'),
            resource_path('views/store/ribbon/product.blade.php'),
            resource_path('views/store/ribbon/cart.blade.php'),
        ] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(business_?[iI]d|bid|id)\s*(===?|!==?)\s*5\b|\[\s*5\s*\]/',
                file_get_contents($file),
                basename($file).' يسأل عن متجرٍ بمعرّفه',
            );
        }

        $config = require config_path('storefront.php');
        $this->assertSame([5], $config['ribbon_gift_card_product_businesses']);
        $this->assertSame([5], $config['ribbon_english_checkout_businesses']);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\PublicDocument;
use App\Support\Store\GiftCard;
use App\Support\Store\GiftCardProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * رسالةُ كرت الهدية اختياريّة — والكرتُ صنفٌ يُباع بثمنه بها أو بلاها.
 *
 * ═══ ما كان ═══
 *
 * كان الكرتُ لا يدخل السلّة بلا رسالة، والخادمُ يردّه في التسعير والإتمام:
 * «اكتب رسالة كرت الهدية». فمن أراد كرتًا يكتبه بيده لم يجد سبيلًا إليه —
 * وسلّةٌ قديمةٌ في المتصفّح فيها كرتٌ بلا `note` كانت تُردّ ولا يعرف صاحبُها
 * لماذا.
 *
 * ═══ ما يُحرس ═══
 *
 *   - كرتٌ برسالةٍ وبلاها، وبندٌ قديمٌ بلا مفتاح `note` أصلًا: كلُّها تُسعَّر
 *     وتُطلب، والثمنُ ثمنُ الصنف، والبندُ في الطلب.
 *   - لا رسالةَ مخترعة: كرتٌ بلا رسالةٍ لا يكتب شيئًا في `card_message`.
 *   - كرتان برسالتين بندان، وكلاهما في `card_message`؛ والفارغُ لا يُكتب.
 *   - الحدُّ باقٍ للنصّ المكتوب.
 *   - الورقةُ تطبع الكرتَ وثمنَه ولا تطبع رسالتَه — وسائرُ الملاحظات كما هي.
 *   - والكرتُ والإهداءُ مستقلّان: كلُّ جمعٍ بينهما يُقبل.
 *
 * والمتجران عامّان — يُكتب معرّفُ أحدهما في القائمة، ولا يُفرض المعرّف ٥.
 */
class ASaudsGiftCardMessageIsOptionalTest extends TestCase
{
    use RefreshDatabase;

    private Business $saud;

    private Business $other;

    private Product $rose;

    private Product $card;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');

        [$this->saud, $this->rose] = $this->shop('ribbon');
        [$this->other] = $this->shop('other');

        $this->card = Product::create([
            'business_id' => $this->saud->id, 'name' => GiftCard::PRODUCT_NAME, 'name_en' => 'Gift card',
            'price' => 1.5, 'cost' => 0, 'quantity' => 0, 'alert_qty' => 0, 'tracks_stock' => false,
            'is_gift_card' => true, 'active' => true, 'published' => true,
        ]);

        config([
            'storefront.ribbon_free_gift_card_message_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [$this->saud->id],
            'storefront.ribbon_english_checkout_businesses' => [],
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
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ];
    }

    private function cardItem(?string $note = null, int $qty = 1): array
    {
        $line = ['id' => $this->card->id, 'qty' => $qty];

        return $note === null ? $line : $line + ['note' => $note];
    }

    private function rose(): array
    {
        return ['id' => $this->rose->id, 'qty' => 1];
    }

    private function gift(array $items): array
    {
        return $this->order($items, ['is_gift' => true, 'recipient_name' => 'سارة', 'recipient_phone' => '96899110002', 'occasion' => 'birthday']);
    }

    /* ═══════════ التسعير ═══════════ */

    public function test_a_card_is_quoted_with_or_without_its_message_at_the_same_price(): void
    {
        foreach (['مبروك', '', '   ', null] as $note) {
            $line = $this->postJson('/s/ribbon/quote', ['items' => [$this->cardItem($note)]])->assertOk()->json('lines.0');

            $this->assertTrue($line['gift_card'], 'الكرتُ سقط من هويّته بلا رسالة');
            $this->assertEquals(1.5, (float) $line['price'], 'تبدّل ثمنُ الكرت بالرسالة');
            $this->assertSame($note === 'مبروك' ? 'مبروك' : null, $line['note']);
        }
    }

    /** وسلّةٌ قديمةٌ في المتصفّح — بندُ الكرت بلا مفتاح `note` أصلًا، وبكمّيّة */
    public function test_an_old_stored_cart_with_a_card_and_no_note_still_quotes_and_checks_out(): void
    {
        $old = [['id' => $this->card->id, 'variant_id' => null, 'qty' => 2], ['id' => $this->rose->id, 'variant_id' => null, 'qty' => 1]];

        $this->postJson('/s/ribbon/quote', ['items' => $old])->assertOk()->assertJsonPath('lines.0.gift_card', true);
        $this->postJson('/s/ribbon/checkout', $this->order($old))->assertOk();

        $o = Order::with('items')->sole();
        $card = $o->items->firstWhere('product_id', $this->card->id);
        $this->assertSame(2, (int) $card->quantity);
        $this->assertEquals(1.5, (float) $card->price);
        $this->assertSame(25.0, (float) $o->total, 'ورد ٢٠ + كرتان ٣ + توصيل ٢');
    }

    /* ═══════════ الطلب ═══════════ */

    public function test_a_blank_card_is_ordered_charged_and_invents_no_message(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([$this->rose(), $this->cardItem('')]))->assertOk();

        $o = Order::with('items')->sole();
        $card = $o->items->firstWhere('product_id', $this->card->id);
        $this->assertNotNull($card, 'الكرتُ بلا رسالةٍ سقط من الطلب');
        $this->assertEquals(1.5, (float) $card->price);
        $this->assertNull($card->note);
        $this->assertNull($o->card_message, 'كُتبت رسالةٌ مخترعة');
        $this->assertSame(23.5, (float) $o->total);
        // خارجَ دفتر المخزون كما كان
        $this->assertSame(0, (int) $this->card->fresh()->quantity);
    }

    public function test_a_written_message_reaches_the_order(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem("  كل عام وأنت بخير\nمن مريم  ")]))->assertOk();

        $o = Order::with('items')->sole();
        $this->assertSame("كل عام وأنت بخير\nمن مريم", $o->items->sole()->note);
        $this->assertSame("كل عام وأنت بخير\nمن مريم", $o->card_message);
    }

    public function test_several_cards_keep_each_message_and_a_blank_one_adds_nothing(): void
    {
        $items = [$this->cardItem('مبروك'), $this->cardItem('كل عام وأنت بخير'), $this->cardItem('')];

        $lines = $this->postJson('/s/ribbon/quote', ['items' => $items])->assertOk()->json('lines');
        $this->assertSame([[true, 'مبروك'], [true, 'كل عام وأنت بخير'], [true, null]], array_map(fn ($l) => [$l['gift_card'], $l['note']], $lines));

        $this->postJson('/s/ribbon/checkout', $this->order($items))->assertOk();
        $o = Order::with('items')->sole();
        $this->assertSame(3, $o->items->count());
        $this->assertSame("مبروك\n\nكل عام وأنت بخير", $o->card_message);
    }

    public function test_a_written_message_still_has_its_limit(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem(str_repeat('a', GiftCardProduct::MAX + 1))]))
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/s/ribbon/checkout', $this->order([$this->cardItem(str_repeat('a', GiftCardProduct::MAX))]))->assertOk();
    }

    /** ورسالةٌ على صنفٍ ليس كرتًا تُمحى — لا تلتصق بالورد */
    public function test_a_note_never_rides_on_an_ordinary_product(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([['id' => $this->rose->id, 'qty' => 1, 'note' => 'ليست لي'], $this->cardItem('')]))->assertOk();

        $o = Order::with('items')->sole();
        $this->assertNull($o->items->firstWhere('product_id', $this->rose->id)->note);
        $this->assertNull($o->card_message);
    }

    /* ═══════════ صفحةُ الكرت ═══════════ */

    public function test_the_card_page_says_the_message_is_optional_and_carries_no_required_error(): void
    {
        $ar = $this->get('/s/ribbon/p/'.$this->card->id)->assertOk()->getContent();
        $this->assertStringContainsString('رسالة كرت الهدية — اختياري', $ar);
        $this->assertStringNotContainsString('data-rb-card-note-err', $ar);
        $this->assertStringNotContainsString('اكتب رسالة كرت الهدية', $ar);
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]*data-rb-card-note[^>]*required/', $ar);

        $en = $this->get('/s/ribbon/p/'.$this->card->id.'?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('Gift card message — optional', $en);
        $this->assertStringNotContainsString('Write the gift card message', $en);

        // وكرتٌ إضافةً في صفحة الورد: بلا خطأ «اكتب الرسالة»
        $rose = $this->get('/s/ribbon/p/'.$this->rose->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-rb-up-note-err', $rose);
    }

    /* ═══════════ الورقة ═══════════ */

    /** @return array{a4: string, strip: string, public: string} */
    private function papers(Order $order): array
    {
        $this->actingAs(User::where('business_id', $this->saud->id)->firstOrFail());

        return [
            'a4' => PdfController::saleHtml($this->saud->id, $order->fresh('items'))['html'],
            'strip' => PdfController::saleHtml($this->saud->id, $order->fresh('items'), thermal: true)['html'],
            'public' => $this->get((string) PublicDocument::url($order))->assertOk()->getContent(),
        ];
    }

    public function test_the_paper_prints_a_blank_or_written_card_with_its_price_and_never_its_message(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order([$this->rose(), $this->cardItem('سرٌّ للمستلمة وحدها'), $this->cardItem('')]))->assertOk();
        $o = Order::sole();

        foreach ($this->papers($o) as $paper => $html) {
            $this->assertStringContainsString(GiftCard::PRODUCT_NAME, $html, "{$paper}: الكرتُ غاب عن ورقته");
            $this->assertStringContainsString('1.500', $html, "{$paper}: ثمنُ الكرت غاب");
            $this->assertStringNotContainsString('سرٌّ للمستلمة وحدها', $html, "{$paper}: رسالةُ الكرت طُبعت");
        }
    }

    /* ═══════════ الكرتُ والإهداء مستقلّان ═══════════ */

    public function test_every_mix_of_gift_order_and_gift_card_is_accepted(): void
    {
        $this->saud->update(['gift_orders_enabled' => true]);

        $cases = [
            'هديّةٌ بلا كرت' => [$this->gift([$this->rose()]), true, null],
            'هديّةٌ وكرتٌ بلا رسالة' => [$this->gift([$this->rose(), $this->cardItem('')]), true, null],
            'هديّةٌ وكرتٌ برسالة' => [$this->gift([$this->rose(), $this->cardItem('مبروك يا سارة')]), true, 'مبروك يا سارة'],
            'طلبٌ عاديٌّ وكرتٌ بلا رسالة' => [$this->order([$this->rose(), $this->cardItem()]), false, null],
            'طلبٌ عاديٌّ وكرتٌ برسالة' => [$this->order([$this->rose(), $this->cardItem('لك')]), false, 'لك'],
        ];

        foreach ($cases as $name => [$payload, $isGift, $message]) {
            $this->postJson('/s/ribbon/checkout', $payload)->assertOk();
            $o = Order::latest('id')->first();

            $this->assertSame($isGift, (bool) $o->is_gift, $name);
            $this->assertSame($message, $o->card_message, $name);
            $this->assertNull($o->recipient_location_mode, $name);
            $this->assertNotNull($o->delivery_address, $name);
            $cards = $o->items()->where('product_id', $this->card->id)->count();
            $this->assertSame(count($payload['items']) - 1, $cards, $name);
            // والمالُ مالُ البنود — لا ثمنَ للإهداء
            $this->assertEquals(20 + 1.5 * $cards + 2, (float) $o->total, $name);
        }
    }

    /** وكرتُ متجرٍ ليس في القائمة ليس كرتًا هنا — ولا يُقرأ معرّفُه من خارج متجره */
    public function test_the_card_stays_inside_its_own_shop(): void
    {
        $this->postJson('/s/other/quote', ['items' => [$this->cardItem('مبروك')]])->assertStatus(422);
        $this->assertFalse(GiftCardProduct::is($this->other->id, $this->card));
    }
}

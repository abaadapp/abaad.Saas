<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\RibbonTexts;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «أضف مع طلبك» — كلُّ إضافةٍ بكمّيّتها، من صفحة الصنف حتّى لوحة التجهيز.
 *
 * ═══ الحالُ التي أنشأت هذا الملفّ ═══
 *
 * كانت كلُّ إضافةٍ تدخل السلّةَ بواحدٍ ثابت (`RBUpsells.compose`): من أراد
 * علبتَي شوكولاتة وثلاثةَ بالونات مع باقته أضافها واحدةً ثمّ عدّلها في السلّة.
 * فصار لكلّ بطاقةٍ عدّادُها. والضغطُ والعدّادُ في
 * `tests/js/the-ribbon-product-page-adds-its-add-ons-in-one-click.test.ts`،
 * وهذا الملفُّ يحرس ما بعد الضغطة: ما ترسمه الصفحة، وما يُسعَّر، وما يُكتب.
 *
 * ═══ وما يُحرس ═══
 *
 *   - الصفحةُ ترسم لكلّ إضافةٍ عدّادًا بأسمائه للقارئ الصوتيّ، وسعرَ وحدتها
 *     للعرض، ومجموعًا — بالعربيّة والإنجليزيّة.
 *   - والإتمامُ يسعّر كلَّ بندٍ بكمّيّته من القاعدة: لا ثمنَ من المتصفّح، ولا
 *     تُضرب إضافةٌ في كمّيّة الصنف. والضريبةُ على المجموع مرّةً.
 *   - والطلبُ يكتب كلَّ بندٍ بكمّيّته مرّةً، والرفُّ يُخصم مرّةً لكلّ صنف.
 *   - ولوحةُ التجهيز تقرأ الإضافاتِ بكمّيّاتها، وكرتُ الهدية برسالته.
 *   - والحدود: مقاسٌ ناقصٌ يُردّ، وما فوق الرفّ يُردّ ولا يُخصم شيء، وسلّةٌ
 *     فوق الأربعين تُردّ ولا يُقصّ آخرُها بصمت، والكمّيّةُ لا تتجاوز خمسين.
 *   - وسلّةٌ قديمةٌ بصيغتها القديمة تُسعَّر كما كانت.
 */
class TheRibbonAddsEachExtraWithItsOwnQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    /** @var array<string, int> */
    private array $p = [];

    /** @var array<string, int> */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-05-04 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        // ضريبةٌ تُضاف فوق السعر — ٥٪ على المجموع كلّه مرّةً
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'extras@abaad.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_fulfil' => 'pickup']);

        $bouquets = $this->category('باقات');
        $extras = $this->category('الإضافات');

        $this->p['bouquet'] = $this->product('باقة ورد', 'Rose bouquet', 20, $bouquets, ['tracks_stock' => true, 'quantity' => 10]);
        $this->p['balloon'] = $this->product('بالون', 'Balloon', 1.5, $extras, ['tracks_stock' => true, 'quantity' => 10]);
        $this->p['choc'] = $this->product('شوكولاتة', 'Chocolate', 3, $extras, []);
        $this->v['small'] = ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['choc'], 'name' => 'علبة صغيرة', 'name_en' => 'Small box', 'price' => 2, 'active' => true, 'sort_order' => 1])->id;
        $this->v['large'] = ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['choc'], 'name' => 'علبة كبيرة', 'name_en' => 'Large box', 'price' => 4, 'active' => true, 'sort_order' => 2])->id;
        // وكرتُ الهدية صنفًا من الرفّ — خارجَ دفتر المخزون، ورسالتُه مع بنده
        $this->p['card'] = $this->product('كرت هدية', 'Gift card', 1, $extras, ['is_gift_card' => true, 'tracks_stock' => false, 'quantity' => 0]);

        config([
            'storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الإضافات', 'limit' => 6]],
            'storefront.ribbon_gift_card_product_businesses' => [$this->shop->id],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    private function category(string $name): int
    {
        return DB::table('categories')->insertGetId([
            'business_id' => $this->shop->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $name, string $en, float $price, int $category, array $attrs): int
    {
        return Product::create($attrs + [
            'business_id' => $this->shop->id, 'category_id' => $category, 'name' => $name, 'name_en' => $en,
            'price' => $price, 'cost' => 1, 'quantity' => 50, 'tracks_stock' => false, 'active' => true, 'published' => true,
        ])->id;
    }

    /** الباقةُ ×١، والشوكولاتة الصغيرة ×٢، والبالون ×٣، والكرتُ ×١ برسالته */
    private function basket(): array
    {
        return [
            ['id' => $this->p['bouquet'], 'variant_id' => null, 'qty' => 1],
            ['id' => $this->p['choc'], 'variant_id' => $this->v['small'], 'qty' => 2],
            ['id' => $this->p['balloon'], 'variant_id' => null, 'qty' => 3],
            ['id' => $this->p['card'], 'variant_id' => null, 'qty' => 1, 'note' => 'كل عام وأنت بخير'],
        ];
    }

    private function quote(array $items)
    {
        return $this->postJson('/s/ribbon/quote', ['items' => $items, 'fulfil' => 'pickup']);
    }

    private function place(array $items, array $over = [])
    {
        return $this->postJson('/s/ribbon/checkout', $over + [
            'items' => $items, 'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001', 'date' => '2027-05-05',
        ]);
    }

    private function stock(string $key): float
    {
        return (float) DB::table('products')->where('id', $this->p[$key])->value('quantity');
    }

    /* ═══════════════════════ الصفحة ═══════════════════════ */

    public function test_each_extra_gets_its_own_counter_named_for_a_screen_reader(): void
    {
        $html = $this->get('/s/ribbon/p/'.$this->p['bouquet'])->assertOk()->getContent();

        foreach (['balloon' => 'بالون', 'choc' => 'شوكولاتة'] as $key => $name) {
            $this->assertMatchesRegularExpression('/data-rb-up="'.$this->p[$key].'" data-qty="0"/', $html, $name.' بلا كمّيّةٍ قبل أن يُختار');
            $this->assertStringContainsString('aria-label="زيادة كمية '.$name.'"', $html);
            $this->assertStringContainsString('aria-label="إنقاص كمية '.$name.'"', $html);
        }

        // وسعرُ الوحدة للعرض: على البطاقة البسيطة، وعلى كلّ مقاسٍ لذات المقاس
        $this->assertMatchesRegularExpression('/data-rb-up="'.$this->p['balloon'].'" data-qty="0"\s+data-amount="1.5"/', $html);
        $this->assertMatchesRegularExpression('/data-rb-up-size="'.$this->v['large'].'" data-amount="4"/', $html);
        $this->assertStringContainsString('data-testid="rb-upsell-total"', $html);
        $this->assertStringContainsString('data-testid="rb-add-refused"', $html);
        // وكتابةُ المبلغ كما يكتبها الخادم — منازلُ الريال الثلاث ورمزُه بعده
        $this->assertStringContainsString('RBUpsells.money('.json_encode(['d' => 3, 'symbol' => 'ر.ع', 'before' => false], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).')', $html);
    }

    public function test_the_english_page_names_the_counters_in_english(): void
    {
        $html = $this->get('/s/ribbon/p/'.$this->p['bouquet'].'?lang=en')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Increase quantity of Balloon"', $html);
        $this->assertStringContainsString('aria-label="Decrease quantity of Chocolate"', $html);
        $this->assertStringContainsString('>'.RibbonTexts::for('en')['upTotal'].'<', $html);
        $this->assertStringNotContainsString('زيادة كمية', $html);
    }

    /* ═══════════════════════ التسعير ═══════════════════════ */

    public function test_each_line_is_priced_at_its_own_quantity_from_the_database(): void
    {
        $q = $this->quote($this->basket())->assertOk()->json();

        $this->assertSame([1, 2, 3, 1], array_column($q['lines'], 'qty'), 'إضافةٌ ضُربت في كمّيّة الصنف أو صارت واحدًا');
        $this->assertEquals([20, 2, 1.5, 1], array_column($q['lines'], 'price'));
        // ٢٠ + ٢×٢ + ٣×١٫٥ + ١ = ٢٩٫٥ — والضريبةُ ٥٪ عليها مرّةً
        $this->assertEquals(29.5, $q['subtotal']);
        $this->assertEquals(1.475, $q['tax']);
        $this->assertEquals(30.975, $q['total']);
    }

    /** ثمنٌ من المتصفّح لا يُقرأ — ولو كُتب في البند */
    public function test_a_price_sent_by_the_browser_is_ignored(): void
    {
        $items = $this->basket();
        $items[2]['price'] = 0.001;
        $items[2]['line'] = 0.001;

        $q = $this->quote($items)->assertOk()->json();
        $this->assertEquals(1.5, $q['lines'][2]['price']);
        $this->assertEquals(29.5, $q['subtotal']);
    }

    public function test_an_extra_that_needs_a_size_is_refused_without_one(): void
    {
        $items = $this->basket();
        $items[1]['variant_id'] = null;

        $this->quote($items)->assertStatus(422);
        $this->place($items)->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_an_old_cart_in_its_old_shape_is_priced_as_it_was(): void
    {
        // بلا `variant_id` ولا `note` — كما كانت السلّةُ تُكتب قبل هذا كلّه
        $q = $this->quote([['id' => $this->p['bouquet'], 'qty' => 2], ['id' => $this->p['balloon'], 'qty' => 1]])->assertOk()->json();

        $this->assertSame([2, 1], array_column($q['lines'], 'qty'));
        $this->assertEquals(41.5, $q['subtotal']);
    }

    /* ═══════════════════════ الطلب ═══════════════════════ */

    public function test_the_order_writes_each_line_once_at_its_quantity_and_takes_stock_once(): void
    {
        $this->place($this->basket(), ['agreed_total' => 30.975])->assertOk();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertCount(4, $order->items, 'بندٌ تكرّر أو سقط');
        $this->assertSame(
            [[$this->p['bouquet'], null, 1], [$this->p['choc'], $this->v['small'], 2], [$this->p['balloon'], null, 3], [$this->p['card'], null, 1]],
            $order->items->map(fn (OrderItem $i) => [(int) $i->product_id, $i->variant_id ? (int) $i->variant_id : null, (int) $i->quantity])->all(),
        );
        $this->assertSame(['20.000', '4.000', '4.500', '1.000'], $order->items->map(fn ($i) => (string) $i->total)->all());
        $this->assertSame('29.500', (string) $order->subtotal);
        $this->assertSame('1.475', (string) $order->tax);
        $this->assertSame('30.975', (string) $order->total);

        // والرفُّ يُخصم مرّةً لكلّ صنفٍ بكمّيّته — والكرتُ لا رفَّ له
        $this->assertSame(9.0, $this->stock('bouquet'));
        $this->assertSame(7.0, $this->stock('balloon'));
        $this->assertSame(0.0, $this->stock('card'));

        // ورسالةُ الكرت مع بنده وعلى الطلب
        $this->assertSame('كل عام وأنت بخير', $order->items[3]->note);
        $this->assertSame('كل عام وأنت بخير', $order->card_message);
    }

    public function test_two_gift_cards_from_one_card_share_its_message(): void
    {
        $this->place([
            ['id' => $this->p['bouquet'], 'qty' => 1],
            ['id' => $this->p['card'], 'qty' => 2, 'note' => 'مبروك'],
        ])->assertOk();

        $card = Order::with('items')->latest('id')->firstOrFail()->items->firstWhere('product_id', $this->p['card']);
        $this->assertSame(2, (int) $card->quantity);
        $this->assertSame('مبروك', $card->note);
    }

    public function test_more_than_the_shelf_holds_is_refused_and_nothing_is_taken(): void
    {
        $items = $this->basket();
        $items[2]['qty'] = 11;

        $res = $this->place($items)->assertStatus(422);
        $this->assertStringContainsString('بالون', json_encode($res->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Order::count());
        $this->assertSame(10.0, $this->stock('balloon'));
        $this->assertSame(10.0, $this->stock('bouquet'), 'خُصم الصنفُ وقد رُدّ الطلب');
    }

    /* ═══════════════════════ الحدود ═══════════════════════ */

    public function test_a_cart_beyond_the_line_limit_is_refused_not_silently_cut(): void
    {
        $lines = array_fill(0, WebCheckout::MAX_LINES + 1, ['id' => $this->p['bouquet'], 'qty' => 1]);

        $res = $this->quote($lines)->assertStatus(422);
        $this->assertStringContainsString((string) WebCheckout::MAX_LINES, implode(' ', (array) $res->json('errors.items')));
        $this->place($lines)->assertStatus(422);
        $this->assertSame(0, Order::count());

        // والحدُّ نفسُه يُقبل
        $this->quote(array_fill(0, WebCheckout::MAX_LINES, ['id' => $this->p['card'], 'qty' => 1]))->assertOk();
    }

    /** والكمّيّةُ فوق الحدّ تُسعَّر عند الحدّ — ويراها الزبونُ في ملخّصه */
    public function test_a_quantity_beyond_the_limit_is_held_at_it_and_shown(): void
    {
        $q = $this->quote([['id' => $this->p['card'], 'qty' => WebCheckout::MAX_QTY + 20]])->assertOk()->json();

        $this->assertSame(WebCheckout::MAX_QTY, $q['lines'][0]['qty']);
    }

    public function test_the_page_and_the_server_share_one_pair_of_limits(): void
    {
        $html = $this->get('/s/ribbon/p/'.$this->p['bouquet'])->assertOk()->getContent();

        $this->assertStringContainsString('var LIMITS = { qty: '.WebCheckout::MAX_QTY.', lines: '.WebCheckout::MAX_LINES.' };', $html);
        foreach (RibbonTexts::limitSays('ar') as $said) {
            $this->assertStringContainsString(json_encode($said, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), $html);
        }
        $this->assertStringContainsString((string) WebCheckout::MAX_QTY, RibbonTexts::limitSays('en')['qty']);
        $this->assertStringContainsString((string) WebCheckout::MAX_LINES, RibbonTexts::limitSays('en')['lines']);
    }

    /* ═══════════════════════ التجهيز ═══════════════════════ */

    public function test_the_preparation_board_reads_every_extra_at_its_quantity(): void
    {
        $this->place($this->basket())->assertOk();
        $order = Order::latest('id')->firstOrFail();

        $cards = $this->actingAs($this->owner)->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props']['orders'];
        $card = collect($cards)->firstWhere('number', $order->number);
        $this->assertNotNull($card, 'طلبُ الموقع ليس على لوحة التجهيز');

        $this->assertSame([1, 2, 3, 1], array_column($card['items'], 'qty'));
        $this->assertSame('كل عام وأنت بخير', $card['items'][3]['note']);
    }
}

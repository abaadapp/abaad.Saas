<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\GiftCardProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كرتُ هدية سعود يُعرف بعلامته (`products.is_gift_card`) لا باسم عرضه.
 *
 * ═══ ما وقع ═══
 *
 * على الإنتاج لم تُفتح خانةُ «رسالة كرت الهدية» لا في صفحة الكرت نفسِه ولا
 * في «أضف مع طلبك» — والمشتركُ بينهما `GiftCardProduct::is`، وكانت تعرف الكرتَ
 * بالاسم «كرت هدية» حرفًا بحرف. فصنفٌ اسمُه بغير هذا الحرف كرتٌ في عين
 * صاحبه وصنفٌ عاديٌّ في عين النظام.
 *
 * ═══ ما يُحرس ═══
 *
 *   أ) صفحةُ الكرت وحده: `gift_card = true` وخانتُه، وبلا نصٍّ يُردّ، وبنصٍّ يدخل.
 *   ب) الكرتُ إضافةً: `data-gift-card` وخانتُه مطويّة، والنصُّ لبنده وحده.
 *   ج) الحماية: صنفٌ عاديّ ليس كرتًا، والمتجرُ الآخر لا يُمسّ، وكرتان معلَّمان
 *      لا يُختار أحدُهما، والعلامةُ لا تُرسل من الشاشة ولا تُنسخ.
 *   د) هجرةُ العلامة: تُعلّم مرشّحًا واحدًا بعد التطبيع، ولا تختار من اثنين.
 *
 * وسلوكُ الضغط (فتحُ الخانة وطيُّها ومحوُها، والمنعُ بلا نصّ) في
 * `tests/js/the-ribbon-product-page-adds-its-add-ons-in-one-click.test.ts`.
 */
class ASaudsGiftCardIsKnownByItsMarkNotItsNameTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private int $addons;

    private int $rose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = $this->ribbon('saud');
        $this->other = $this->ribbon('other');

        $this->addons = $this->category($this->shop, 'الاضافات');
        $this->rose = $this->product($this->shop, $this->category($this->shop, 'باقات'), 'باقة ورد', ['price' => 20]);
        $this->product($this->shop, $this->addons, 'بالون', ['price' => 1.5]);

        config([
            'storefront.ribbon_gift_card_product_businesses' => [$this->shop->id],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
            'storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الاضافات', 'limit' => 6]],
        ]);
    }

    private function ribbon(string $slug): Business
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $shop->id, 'name' => 'مالك '.$slug, 'email' => $slug.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        return $shop;
    }

    private function category(Business $shop, string $name): int
    {
        return DB::table('categories')->insertGetId([
            'business_id' => $shop->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(Business $shop, int $category, string $name, array $attrs = []): int
    {
        return Product::create($attrs + [
            'business_id' => $shop->id, 'category_id' => $category, 'name' => $name,
            'price' => 5, 'cost' => 1, 'quantity' => 10, 'active' => true, 'published' => true,
        ])->id;
    }

    /** كرتٌ كما قد يكون على الإنتاج: اسمٌ بغير الحرف الثابت، مرتبطٌ بالمخزون، بلا علامة */
    private function legacyCard(string $name = 'كرت الهدية', array $attrs = []): int
    {
        return $this->product($this->shop, $this->addons, $name, $attrs + ['name_en' => 'Gift Card', 'price' => 1.5, 'tracks_stock' => true, 'quantity' => 4]);
    }

    private function mark(): void
    {
        (require base_path('database/migrations/2026_10_03_230100_the_shops_existing_gift_card_gets_its_mark.php'))->up();
    }

    private function page(int $id, string $slug = 'saud')
    {
        return $this->get("/s/{$slug}/p/{$id}")->assertOk();
    }

    private function quote(array $items, string $slug = 'saud')
    {
        return $this->postJson("/s/{$slug}/quote", ['items' => $items, 'fulfil' => 'pickup']);
    }

    private function marked(int $id): bool
    {
        return (bool) DB::table('products')->where('id', $id)->value('is_gift_card');
    }

    /* ═══════════ أ) الكرتُ وحده ═══════════ */

    public function test_the_card_page_alone_asks_for_its_message_once_the_card_is_marked(): void
    {
        $card = $this->legacyCard();

        // الحالُ التي وقعت: صفحةُ الكرت بلا خانة
        $before = $this->page($card);
        $this->assertFalse($before->viewData('product')['gift_card']);
        $this->assertStringNotContainsString('data-testid="rb-card-note-box"', $before->getContent());

        $this->mark();

        $after = $this->page($card);
        $this->assertTrue($after->viewData('product')['gift_card']);
        $html = $after->getContent();
        $this->assertStringContainsString('data-testid="rb-card-note-box"', $html);
        $this->assertSame(1, substr_count($html, 'data-rb-card-note '), 'خانةٌ واحدة لا ثانية');
        $this->assertMatchesRegularExpression('/<textarea id="rb-card-note"[^>]*data-rb-card-note/', $html);
        // وصفحةُ الكرت بلا «أضف مع طلبك»
        $this->assertSame([], $after->viewData('upsells'));

        // والرسالةُ اختياريّة: كرتٌ بلا رسالةٍ يبقى كرتًا بلا نصّ
        $this->quote([['id' => $card, 'qty' => 1]])
            ->assertOk()->assertJsonPath('lines.0.gift_card', true)->assertJsonPath('lines.0.note', null);
        $this->quote([['id' => $card, 'qty' => 1, 'note' => '  مبروك التخرّج  ']])
            ->assertOk()->assertJsonPath('lines.0.gift_card', true)->assertJsonPath('lines.0.note', 'مبروك التخرّج');
    }

    /* ═══════════ ب) الكرتُ إضافةً ═══════════ */

    public function test_the_card_as_an_add_on_carries_its_mark_and_its_message_rides_alone(): void
    {
        $card = $this->legacyCard();

        $before = $this->page($this->rose)->getContent();
        $this->assertDoesNotMatchRegularExpression('/<div class="rb-up"[^>]*data-gift-card/', $before);

        $this->mark();

        $res = $this->page($this->rose);
        $ups = collect($res->viewData('upsells'))->keyBy('id');
        $this->assertTrue($ups[$card]['gift_card']);
        $html = $res->getContent();
        $this->assertMatchesRegularExpression('/data-rb-up="'.$card.'"[^>]*data-gift-card/', $html);
        $this->assertSame(1, preg_match_all('/<div class="rb-up"[^>]*data-gift-card/', $html));
        $this->assertMatchesRegularExpression('/data-rb-up-note-box hidden/', $html);

        // كما يركّبها `RBUpsells`: الصنفُ بلا نصّ، والكرتُ بنصّه
        $lines = $this->quote([
            ['id' => $this->rose, 'qty' => 1, 'note' => 'لا يُكتب على الباقة'],
            ['id' => $card, 'qty' => 1, 'note' => 'كل عام وأنت بخير'],
        ])->assertOk()->json('lines');
        $this->assertSame([[false, null], [true, 'كل عام وأنت بخير']], array_map(fn ($l) => [(bool) $l['gift_card'], $l['note']], $lines));

        // وكرتٌ إضافةً بلا رسالةٍ مقبول — والرسالةُ اختياريّة
        $blank = $this->quote([['id' => $this->rose, 'qty' => 1], ['id' => $card, 'qty' => 1]])->assertOk()->json('lines');
        $this->assertSame([[false, null], [true, null]], array_map(fn ($l) => [(bool) $l['gift_card'], $l['note']], $blank));
    }

    /* ═══════════ ج) الحماية ═══════════ */

    public function test_an_ordinary_product_is_never_a_card_and_a_forged_note_is_dropped(): void
    {
        $gift = $this->product($this->shop, $this->addons, 'باقة هدية');
        $this->legacyCard();
        $this->mark();

        $this->assertFalse($this->marked($gift));
        $this->assertFalse(GiftCardProduct::is($this->shop->id, Product::find($gift)));
        $this->quote([['id' => $gift, 'qty' => 1, 'note' => 'مزوّر']])
            ->assertOk()->assertJsonPath('lines.0.gift_card', false)->assertJsonPath('lines.0.note', null);
    }

    public function test_another_shop_is_neither_marked_nor_read(): void
    {
        $theirs = $this->product($this->other, $this->category($this->other, 'الاضافات'), 'كرت هدية', ['name_en' => 'Gift card', 'tracks_stock' => true]);
        // وعلامةٌ في متجرٍ خارج القائمة لا تجعله كرتًا
        $forged = $this->product($this->other, $this->category($this->other, 'كروت'), 'كرت', ['is_gift_card' => true, 'tracks_stock' => false]);

        $this->mark();

        $this->assertFalse($this->marked($theirs));
        $this->assertTrue((bool) DB::table('products')->where('id', $theirs)->value('tracks_stock'));
        $this->assertFalse(GiftCardProduct::is($this->other->id, Product::find($forged)));
        $this->assertFalse(GiftCardProduct::is($this->shop->id, Product::find($forged)));
    }

    public function test_two_marked_cards_are_neither_chosen_nor_sold_quietly(): void
    {
        $a = $this->product($this->shop, $this->addons, 'كرت أ', ['is_gift_card' => true, 'tracks_stock' => false]);
        $b = $this->product($this->shop, $this->addons, 'كرت ب', ['is_gift_card' => true, 'tracks_stock' => false]);

        $this->assertFalse(GiftCardProduct::is($this->shop->id, Product::find($a)));
        $this->assertFalse(GiftCardProduct::is($this->shop->id, Product::find($b)));
        $this->quote([['id' => $a, 'qty' => 1, 'note' => 'Hi']])
            ->assertStatus(422)->assertJsonPath('errors.items.0', 'كرت الهدية غير متاح الآن.');
    }

    public function test_the_screen_cannot_send_the_mark_and_a_copy_is_never_the_card(): void
    {
        $owner = User::where('business_id', $this->shop->id)->firstOrFail();

        $this->actingAs($owner)->put('/admin/products/'.$this->rose, ['name' => 'باقة ورد', 'price' => 20, 'is_gift_card' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->marked($this->rose), 'العلامةُ وصلت من الشاشة');

        $card = $this->product($this->shop, $this->addons, 'كرت', ['is_gift_card' => true, 'tracks_stock' => false]);
        $this->actingAs($owner)->post('/admin/products/'.$card.'/duplicate');
        $copy = Product::where('business_id', $this->shop->id)->where('name', 'like', 'كرت — %')->sole();
        $this->assertFalse((bool) $copy->is_gift_card, 'النسخةُ صارت كرتًا ثانيًا');
        $this->assertTrue(GiftCardProduct::is($this->shop->id, Product::find($card)));
    }

    /* ═══════════ د) هجرةُ العلامة ═══════════ */

    public function test_the_mark_finds_one_candidate_by_its_known_names_and_leaves_price_and_stock(): void
    {
        $card = $this->legacyCard(' كرت  الهديّة ', ['name_en' => null]);

        $this->mark();

        $row = DB::table('products')->where('id', $card)->first();
        $this->assertTrue((bool) $row->is_gift_card);
        $this->assertFalse((bool) $row->tracks_stock);
        $this->assertSame([4, 1.5, true], [(int) $row->quantity, (float) $row->price, (bool) $row->published]);
        $this->assertSame(' كرت  الهديّة ', $row->name, 'الاسمُ لا يُغيَّر');
    }

    public function test_an_english_name_in_either_field_is_a_candidate(): void
    {
        $card = $this->product($this->shop, $this->addons, 'Gift  Card', ['name_en' => null]);

        $this->mark();

        $this->assertTrue($this->marked($card));
    }

    public function test_two_shown_candidates_mark_none(): void
    {
        $a = $this->legacyCard('كرت هدية');
        $b = $this->legacyCard('بطاقة هدية');

        $this->mark();

        $this->assertFalse($this->marked($a));
        $this->assertFalse($this->marked($b));
    }

    public function test_among_candidates_the_single_shown_one_is_marked(): void
    {
        $hidden = $this->legacyCard('كرت هدية', ['active' => false]);
        $shown = $this->legacyCard('كرت الهدية');

        $this->mark();

        $this->assertFalse($this->marked($hidden));
        $this->assertTrue($this->marked($shown));
    }

    public function test_a_shop_with_a_marked_card_is_left_alone(): void
    {
        $this->product($this->shop, $this->addons, 'كرتنا', ['is_gift_card' => true, 'tracks_stock' => false]);
        $legacy = $this->legacyCard();

        $this->mark();

        $this->assertFalse($this->marked($legacy));
    }
}

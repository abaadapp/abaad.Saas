<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CategoryName;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\ProductName;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * صنفٌ واحد وقسمٌ واحد — باسمين.
 *
 * ═══ ما يُحرس هنا ═══
 *
 * ١) القسمُ يُضاف بالاسمين، ويُعاد تسميتُه في صفّه: المعرّفُ هو هو، وأصنافُه
 *    مربوطةٌ به كما كانت — ولا يُسمّي تاجرٌ قسمَ غيره.
 * ٢) واجهةُ RIBBON تعرض كلًّا بلغة الصفحة، ويجد الزبونُ الصنفَ نفسَه بأيّ
 *    اسمَيه بحث في أيّ لغة. وبحثٌ لم يُصب يقول ذلك — لا «لا منتجات بعد».
 * ٣) الموقعُ المبنيّ — المعاينةُ والمنشور — يُسمّي الصنفَ بلغة الموقع كما
 *    يُسمّي القسم، ويحمل الاسمَ الآخر ليُبحث به، بالمعرّفات نفسِها.
 * ٤) سطرُ تذييل RIBBON من المحلّ نفسِه — لا وصفُ محلٍّ آخر.
 */
class TheCatalogSpeaksBothLanguagesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Category $gifts;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-10 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('RIBBON', 'ribbon', 'saud@abaad.om');

        $this->gifts = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا', 'name_en' => 'Gifts']);
        $this->bouquet = $this->product($this->shop, 'باقة ورد وردية', 'Pink Flower Bouquet', $this->gifts);
        $this->product($this->shop, 'شمعة معطّرة', 'Scented Candle', $this->gifts);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $name, string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return [$shop, $owner];
    }

    private function product(Business $shop, string $name, ?string $nameEn, ?Category $cat): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'name_en' => $nameEn, 'price' => 20,
            'category_id' => $cat?->id, 'cost' => 8, 'quantity' => 10, 'alert_qty' => 1,
            'active' => true, 'published' => true,
        ]);
    }

    /** بياناتُ صفحة الرفّ ونصُّها معًا */
    private function shelf(string $lang, array $query = []): array
    {
        $res = $this->get('/s/ribbon/shop?'.http_build_query(['lang' => $lang] + $query))->assertOk();

        return [$res->original->getData(), $res->getContent()];
    }

    /* ═══════════════ ١ · القسم ═══════════════ */

    public function test_the_category_name_follows_the_page_language(): void
    {
        $this->assertSame('هدايا', CategoryName::display('هدايا', 'Gifts', 'ar'));
        $this->assertSame('Gifts', CategoryName::display('هدايا', 'Gifts', 'en'));
        // والمعجمُ لما يعرفه يقينًا، والاسمُ كما كُتب لما لا يعرفه — لا ترجمةَ مخترَعة
        $this->assertSame('باقات المساء', CategoryName::display('باقات المساء', null, 'en'));
    }

    public function test_quick_add_keeps_both_names(): void
    {
        $res = $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'الإضافات الخاصة', 'name_en' => 'Special Add-ons'])
            ->assertOk();

        $this->assertSame('Special Add-ons', $res->json('category.name_en'));
        $this->assertDatabaseHas('categories', [
            'id' => $res->json('category.id'), 'business_id' => $this->shop->id,
            'name' => 'الإضافات الخاصة', 'name_en' => 'Special Add-ons',
        ]);
    }

    public function test_renaming_keeps_the_same_category_and_its_products(): void
    {
        $id = $this->gifts->id;

        $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.update', $id), ['name' => 'هدايا مميّزة', 'name_en' => 'Special Gifts'])
            ->assertOk()
            ->assertJsonPath('category.id', $id)
            ->assertJsonPath('category.name_en', 'Special Gifts');

        $this->assertSame(1, Category::where('business_id', $this->shop->id)->count(), 'أُنشئ قسمٌ بدل أن يُسمّى القائم');
        $fresh = Category::findOrFail($id);
        $this->assertSame('هدايا مميّزة', $fresh->name);
        $this->assertSame('Special Gifts', $fresh->name_en);
        $this->assertSame($id, (int) $this->bouquet->fresh()->category_id, 'انفصل الصنفُ عن قسمه');
    }

    public function test_renaming_to_its_own_name_is_allowed_and_to_a_siblings_is_not(): void
    {
        Category::create(['business_id' => $this->shop->id, 'name' => 'ورود']);

        $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.update', $this->gifts->id), ['name' => 'هدايا', 'name_en' => 'Presents'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.update', $this->gifts->id), ['name' => 'ورود'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame('هدايا', $this->gifts->fresh()->name);
    }

    public function test_the_same_name_in_another_shop_is_not_a_clash(): void
    {
        [$other] = $this->ribbon('BLOOM', 'bloom', 'bloom@abaad.om');
        Category::create(['business_id' => $other->id, 'name' => 'ورود']);

        $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.update', $this->gifts->id), ['name' => 'ورود'])
            ->assertOk();
    }

    public function test_a_merchant_cannot_rename_another_merchants_category(): void
    {
        [$other] = $this->ribbon('BLOOM', 'bloom', 'bloom@abaad.om');
        $theirs = Category::create(['business_id' => $other->id, 'name' => 'نباتات', 'name_en' => 'Plants']);

        $this->actingAs($this->owner)
            ->patchJson(route('admin.products.categories.update', $theirs->id), ['name' => 'مسروق', 'name_en' => 'Stolen'])
            ->assertNotFound();

        $this->assertSame('نباتات', $theirs->fresh()->name);
        $this->assertSame('Plants', $theirs->fresh()->name_en);
    }

    /* ═══════════════ ٢ · الصنف في RIBBON ═══════════════ */

    public function test_the_product_name_follows_the_page_language(): void
    {
        $this->assertSame('باقة ورد وردية', ProductName::display('باقة ورد وردية', 'Pink Flower Bouquet', 'ar'));
        $this->assertSame('Pink Flower Bouquet', ProductName::display('باقة ورد وردية', 'Pink Flower Bouquet', 'en'));
        // وبلا اسمٍ إنجليزيّ يبقى ما كتبه — لا ترجمةَ لاسم بضاعته
        $this->assertSame('باقة الربيع', ProductName::display('باقة الربيع', null, 'en'));
        $this->assertSame('باقة الربيع', ProductName::display('باقة الربيع', '  ', 'en'));
    }

    public function test_ribbon_shows_one_product_under_either_name(): void
    {
        [$ar] = $this->shelf('ar');
        [$en] = $this->shelf('en');

        $arCard = collect($ar['products'])->firstWhere('id', $this->bouquet->id);
        $enCard = collect($en['products'])->firstWhere('id', $this->bouquet->id);

        $this->assertSame('باقة ورد وردية', $arCard['name']);
        $this->assertSame('Pink Flower Bouquet', $enCard['name']);
        $this->assertSame('هدايا', $arCard['category']);
        $this->assertSame('Gifts', $enCard['category']);
        $this->assertCount(2, $ar['products'], 'صنفٌ لكلّ لغة');
        $this->assertCount(2, $en['products'], 'صنفٌ لكلّ لغة');

        $this->get('/s/ribbon/p/'.$this->bouquet->id.'?lang=en')->assertOk()->assertSee('Pink Flower Bouquet');
        $this->get('/s/ribbon/p/'.$this->bouquet->id.'?lang=ar')->assertOk()->assertSee('باقة ورد وردية');
    }

    /**
     * «ورد» و«Flower» — الصنفُ نفسُه، في الصفحتين.
     *
     * والبحثُ لا يُقيَّد بلغة الصفحة: زبونٌ على الإنجليزيّة يعرف الباقةَ باسمها
     * العربيّ، وآخرُ على العربيّة يكتب ما رآه في رابطٍ أُرسل إليه.
     */
    public function test_search_finds_the_same_product_by_either_name_in_either_language(): void
    {
        foreach (['ar', 'en'] as $lang) {
            foreach (['ورد', 'Flower', 'flower', 'PINK'] as $q) {
                [$data] = $this->shelf($lang, ['q' => $q]);

                $this->assertSame(
                    [$this->bouquet->id],
                    array_column($data['products'], 'id'),
                    "«{$q}» على الصفحة {$lang} لم يجد الباقة وحدها",
                );
            }
        }
    }

    public function test_a_search_that_matches_nothing_says_so_and_not_that_the_shop_is_empty(): void
    {
        [$ar, $arHtml] = $this->shelf('ar', ['q' => 'zzzqqq']);
        [$en, $enHtml] = $this->shelf('en', ['q' => 'zzzqqq']);

        $this->assertSame([], $ar['products']);
        $this->assertSame([], $en['products']);

        $this->assertStringContainsString('لا توجد منتجات تطابق بحثك.', $arHtml);
        $this->assertStringContainsString('No products match your search.', $enHtml);
        $this->assertStringNotContainsString('لا منتجات هنا بعد.', $arHtml);
        $this->assertStringNotContainsString('No products here yet.', $enHtml);
    }

    public function test_an_empty_shelf_still_says_it_is_empty(): void
    {
        Product::where('business_id', $this->shop->id)->update(['published' => false]);

        [, $arHtml] = $this->shelf('ar', ['q' => 'zzzqqq']);
        [, $enHtml] = $this->shelf('en');

        $this->assertStringContainsString('لا منتجات هنا بعد.', $arHtml);
        $this->assertStringContainsString('No products here yet.', $enHtml);
        $this->assertStringNotContainsString('No products match your search.', $enHtml);
    }

    public function test_a_category_with_nothing_on_it_says_so_about_the_category(): void
    {
        $empty = Category::create(['business_id' => $this->shop->id, 'name' => 'فارغ']);

        [$data, $html] = $this->shelf('en', ['cat' => $empty->id]);

        $this->assertSame([], $data['products']);
        $this->assertStringContainsString('No products in this category.', $html);
        $this->assertStringNotContainsString('No products here yet.', $html);
    }

    /**
     * والمرشِّحُ بالمعرّف — فالاسمُ يتبدّل بلغة الصفحة، وما تحته لا يتبدّل.
     */
    public function test_the_category_filter_is_by_id_and_its_label_by_language(): void
    {
        $addons = Category::create(['business_id' => $this->shop->id, 'name' => 'الإضافات الخاصة', 'name_en' => 'Special Add-ons']);
        $card = $this->product($this->shop, 'بطاقة إهداء', 'Gift Card Note', $addons);

        [$en, $enHtml] = $this->shelf('en', ['cat' => $addons->id]);
        [$ar, $arHtml] = $this->shelf('ar', ['cat' => $addons->id]);

        $this->assertSame([$card->id], array_column($en['products'], 'id'));
        $this->assertSame([$card->id], array_column($ar['products'], 'id'));

        $this->assertSame('Special Add-ons', collect($en['categories'])->firstWhere('id', $addons->id)['name']);
        $this->assertSame('الإضافات الخاصة', collect($ar['categories'])->firstWhere('id', $addons->id)['name']);
        $this->assertStringContainsString('/shop?cat='.$addons->id.'">Special Add-ons</a>', $enHtml);
        $this->assertStringContainsString('/shop?cat='.$addons->id.'">الإضافات الخاصة</a>', $arHtml);

        // وتبديلُ الترجمة لا يبدّل ما تحت المرشِّح
        $addons->update(['name_en' => 'Extras']);
        [$again] = $this->shelf('en', ['cat' => $addons->id]);
        $this->assertSame([$card->id], array_column($again['products'], 'id'));
    }

    /* ═══════════════ ٣ · تذييل RIBBON ═══════════════ */

    public function test_the_ribbon_footer_speaks_for_this_shop_only(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_tagline' => 'ورودٌ · هدايا · توصيل']);
        [$other] = $this->ribbon('BLOOM', 'bloom', 'bloom@abaad.om');

        $mine = $this->get('/s/ribbon')->assertOk()->getContent();
        $theirs = $this->get('/s/bloom')->assertOk()->getContent();

        $this->assertStringContainsString('© 2027 RIBBON', $mine);
        $this->assertStringContainsString('ورودٌ · هدايا · توصيل', $mine);

        $this->assertStringContainsString('© 2027 BLOOM', $theirs);
        $this->assertStringNotContainsString('ورودٌ · هدايا · توصيل', $theirs, 'ذُيّل محلٌّ بسطر محلٍّ آخر');
        $this->assertStringNotContainsString('FLOWERS · LOUNGE · AND MORE', $theirs);
        $this->assertStringNotContainsString('data-testid="rb-tagline"', $theirs);
    }

    /* ═══════════════ ٤ · الموقعُ المبنيّ ═══════════════ */

    /** موقعٌ مبنيّ بلغةٍ — ومعاينتُه ومنشورُه */
    private function website(string $locale): array
    {
        $biz = Business::create(['name' => 'Acme Flowers', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '96890000000', 'city' => 'مسقط']);
        $owner = User::create(['business_id' => $biz->id, 'name' => 'مالك', 'email' => 'acme'.$locale.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        Setting::create(['business_id' => $biz->id, 'key' => 'locale', 'value' => $locale]);

        $cat = Category::create(['business_id' => $biz->id, 'name' => 'هدايا', 'name_en' => 'Gifts']);
        $p = Product::create([
            'business_id' => $biz->id, 'name' => 'باقة ورد وردية', 'name_en' => 'Pink Flower Bouquet',
            'price' => 20, 'category_id' => $cat->id, 'active' => true, 'published' => true, 'quantity' => 10, 'alert_qty' => 1,
        ]);

        $this->actingAs($owner);
        $site = Builder::create($biz, 'store', 'modern', $owner->id);
        Publisher::publish($site, $owner->id);
        $site->refresh();

        return [
            'preview' => Preview::document($site),
            'live' => Preview::resolve($site->publishedVersion->payload, $biz->id),
            'product' => $p->id,
            'category' => $cat->id,
        ];
    }

    /** كلُّ بطاقات الأصناف في المستند — من أيّ قسم */
    private function products(array $doc): array
    {
        return collect($doc['pages'])->flatMap(fn ($p) => $p['sections'])
            ->flatMap(fn ($s) => $s['items'] ?? [])
            ->filter(fn ($i) => array_key_exists('final', $i))->values()->all();
    }

    private function categories(array $doc): array
    {
        return collect($doc['pages'])->flatMap(fn ($p) => $p['sections'])
            ->filter(fn ($s) => $s['type'] === 'categories')
            ->flatMap(fn ($s) => $s['items'] ?? [])->values()->all();
    }

    public function test_the_built_site_names_products_in_its_own_language_in_preview_and_live(): void
    {
        foreach (['ar' => ['باقة ورد وردية', 'Pink Flower Bouquet', 'هدايا'], 'en' => ['Pink Flower Bouquet', 'باقة ورد وردية', 'Gifts']] as $locale => [$shown, $other, $category]) {
            $site = $this->website($locale);

            foreach (['preview', 'live'] as $mode) {
                $cards = $this->products($site[$mode]);

                $this->assertNotEmpty($cards, "لا أصنافَ في {$mode}/{$locale}");
                $this->assertSame([$site['product']], array_values(array_unique(array_column($cards, 'id'))), 'صنفٌ لكلّ لغة');

                foreach ($cards as $card) {
                    $this->assertSame($shown, $card['name'], "{$mode}/{$locale}");
                    $this->assertSame($other, $card['other_name'], "{$mode}/{$locale}: الاسمُ الآخر لا يصل البحث");
                }

                foreach ($this->categories($site[$mode]) as $c) {
                    $this->assertSame($site['category'], $c['id']);
                    $this->assertSame($category, $c['name'], "{$mode}/{$locale}");
                }
            }

            $this->assertEquals($this->products($site['preview']), $this->products($site['live']), 'المعاينةُ غيرُ المنشور');
        }
    }

    public function test_a_product_with_one_name_sends_no_other_name(): void
    {
        $this->assertNull(ProductName::other('باقة الربيع', null, 'en'));
        $this->assertNull(ProductName::other('باقة الربيع', '', 'ar'));
        $this->assertNull(ProductName::other('Rose', 'Rose', 'en'));
        $this->assertSame('Rose', ProductName::other('وردة', 'Rose', 'ar'));
        $this->assertSame('وردة', ProductName::other('وردة', 'Rose', 'en'));
    }
}

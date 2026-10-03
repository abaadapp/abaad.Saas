<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Support\MarketingSettings;
use App\Support\Store\GiftCard;
use App\Support\Store\RibbonUpsells;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «أضف مع طلبك» في صفحة صنف RIBBON — لمتجر سعود وحده، قبل زرّ السلّة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لمن في `storefront.ribbon_product_upsells` وحده — لا لكلّ من يلبس RIBBON،
 *     ولا لمن في قائمة Paymob. ومن ليس فيها تبقى صفحتُه كما كانت.
 *   - والأصنافُ من قسم الإضافات **في المتجر نفسه** بالاسم حرفًا بحرف: معروضةٌ
 *     (`active`، `published`)، وعلى الرفّ (`Shelf`)، وغيرُ الصنف المفتوح،
 *     بترتيب الاسم ثمّ المعرّف، ولا تتجاوز الحدّ.
 *   - وإضافةٌ لها مقاساتٌ تصل بمقاساتها ولا يُختار لها واحدٌ في الصفحة.
 *   - والبنودُ بنودُ السلّة المعتادة: الإتمامُ يسعّرها من القاعدة.
 *
 * والمتجرُ هنا عامٌّ يُكتب معرّفُه في الإعداد — لا يُفرض عليه المعرّف ٥.
 * وسلوكُ الضغط (ما يدخل السلّة ومتى يُمنع) في
 * `tests/js/the-ribbon-product-page-adds-its-add-ons-in-one-click.test.ts`.
 */
class TheRibbonProductPageOffersAddOnsBeforeTheCartTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    /** @var array<string, int> */
    private array $p = [];

    /** @var array<string, int> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->ribbon('saud');
        $this->other = $this->ribbon('other');

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_delivery_note' => 'نوصل خلال ساعتين']);

        $this->cat['main'] = $this->category($this->shop, 'باقات', null);
        $this->cat['addons'] = $this->category($this->shop, 'الإضافات', null);

        $this->p['main'] = $this->product($this->shop, $this->cat['main'], 'باقة ورد', ['name_en' => 'Rose bouquet', 'price' => 20]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['main'], 'name' => 'صغير', 'price' => 15, 'active' => true, 'sort_order' => 1]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['main'], 'name' => 'كبير', 'price' => 30, 'active' => true, 'sort_order' => 2]);

        // المعروضةُ على الرفّ — وتُرتَّب بالاسم لا بترتيب الإنشاء
        $this->p['choc'] = $this->product($this->shop, $this->cat['addons'], 'شوكولاتة', ['name_en' => 'Chocolate', 'price' => 3]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['choc'], 'name' => 'علبة صغيرة', 'name_en' => 'Small box', 'price' => 2, 'active' => true, 'sort_order' => 1]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['choc'], 'name' => 'علبة كبيرة', 'name_en' => 'Large box', 'price' => 4, 'active' => true, 'sort_order' => 2]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->p['choc'], 'name' => 'موقوف', 'price' => 9, 'active' => false, 'sort_order' => 3]);
        $this->p['balloon'] = $this->product($this->shop, $this->cat['addons'], 'بالون', ['name_en' => 'Balloon', 'price' => 1.5]);

        // وما لا يُعرض
        $this->p['inactive'] = $this->product($this->shop, $this->cat['addons'], 'أ موقوف', ['active' => false]);
        $this->p['hidden'] = $this->product($this->shop, $this->cat['addons'], 'أ غير منشور', ['published' => false]);
        $this->p['empty'] = $this->product($this->shop, $this->cat['addons'], 'أ نفد', ['quantity' => 0, 'tracks_stock' => true]);

        // والمتجرُ الآخر: القسمُ نفسُه بالاسم نفسِه، وأسماءٌ تسبق في الترتيب
        $otherAddons = $this->category($this->other, 'الإضافات', null);
        $this->p['foreign'] = $this->product($this->other, $otherAddons, 'أ إضافة غريبة', []);
        $this->p['otherMain'] = $this->product($this->other, $this->category($this->other, 'باقات', null), 'باقة المتجر الآخر', []);

        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الإضافات', 'limit' => 6]]]);
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
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        return $shop;
    }

    private function category(Business $shop, string $name, ?string $en): int
    {
        return DB::table('categories')->insertGetId([
            'business_id' => $shop->id, 'name' => $name, 'name_en' => $en,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(Business $shop, int $category, string $name, array $attrs): int
    {
        return Product::create($attrs + [
            'business_id' => $shop->id, 'category_id' => $category, 'name' => $name,
            'price' => 5, 'cost' => 1, 'quantity' => 10, 'active' => true, 'published' => true,
        ])->id;
    }

    private function page(int $id, string $lang = 'ar', string $slug = 'saud')
    {
        return $this->get("/s/{$slug}/p/{$id}?lang={$lang}")->assertOk();
    }

    /** @return array<int, int> */
    private function upsellIds(int $id, string $lang = 'ar', string $slug = 'saud'): array
    {
        return collect($this->page($id, $lang, $slug)->viewData('upsells'))->pluck('id')->all();
    }

    /* ═══════════ لمن ═══════════ */

    public function test_the_owners_list_names_saud_alone_and_apart_from_paymob(): void
    {
        $config = require base_path('config/storefront.php');

        // الاسمُ كما كتبه سعود في قاعدته: «الاضافات» بلا همزة — والمطابقةُ حرفيّة
        $this->assertSame([5 => ['category' => 'الاضافات', 'limit' => 6]], $config['ribbon_product_upsells']);
        $this->assertArrayNotHasKey('paymob_businesses', $config, 'البوّابةُ لا قائمةَ لها — لكلّ متجرٍ ذي سلّةٍ مفاتيحُه');
    }

    /**
     * وتنبيهُ الصورة تحت زرّ الإضافة في الصفحة ذات الإضافات أيضًا — بعد
     * الإضافات والزرّ، وقبل ملاحظة التوصيل. والصفحةُ بلا إضافات حارسُها
     * `ASitesPhotoIsNotAPromiseTest`.
     */
    public function test_the_image_note_sits_under_the_add_button_after_the_add_ons(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_delivery_note' => 'التوصيل خلال اليوم',
            'store_image_note' => 'قد يختلف اللون حسب المتوفر',
        ]);

        $html = $this->page($this->p['main'])->getContent();

        $ups = mb_strpos($html, 'data-testid="rb-upsells"');
        $add = mb_strpos($html, 'data-testid="rb-add"');
        $note = mb_strpos($html, 'data-testid="rb-image-note"');
        $delivery = mb_strpos($html, 'التوصيل خلال اليوم');

        $this->assertNotFalse($ups, 'الصفحةُ بلا إضافات — الاختبارُ لا يقيس ما سُمّي له');
        $this->assertNotFalse($note);
        $this->assertGreaterThan($ups, $add);
        $this->assertGreaterThan($add, $note, 'التنبيهُ قبل زرّ الإضافة');
        $this->assertLessThan($delivery, $note, 'التنبيهُ بعد ملاحظة التوصيل');
        $this->assertSame(1, mb_substr_count($html, 'data-testid="rb-image-note"'));
    }

    public function test_an_empty_image_note_draws_nothing_on_the_add_ons_page(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_image_note' => '']);

        $this->assertStringNotContainsString('rb-image-note', $this->page($this->p['main'])->getContent());
    }

    public function test_a_ribbon_shop_outside_the_list_keeps_its_page_as_it_was(): void
    {
        config(['storefront.ribbon_product_upsells' => []]);

        $res = $this->page($this->p['main']);
        $this->assertSame([], $res->viewData('upsells'));
        $html = $res->getContent();
        $this->assertStringNotContainsString('data-rb-upsells', $html);
        $this->assertStringNotContainsString('RBUpsells', $html, 'ولا يُحمَّل سكربتُه');
        $this->assertStringNotContainsString('>أضف مع طلبك<', $html);
        // الصفُّ القديم: الكمّيّةُ والزرُّ في سطرٍ واحد
        $this->assertMatchesRegularExpression('/<div class="rb-qty">.*?data-rb-add/s', $html);
    }

    public function test_the_other_ribbon_shop_is_not_listed_and_sees_nothing(): void
    {
        $this->assertSame([], $this->upsellIds($this->p['otherMain'], 'ar', 'other'));
        $this->assertStringNotContainsString('data-rb-upsells', $this->page($this->p['otherMain'], 'ar', 'other')->getContent());
    }

    public function test_a_shop_outside_the_list_is_not_asked_about_in_the_database(): void
    {
        config(['storefront.ribbon_product_upsells' => []]);
        $current = Product::find($this->p['main']);

        DB::enableQueryLog();
        $got = RibbonUpsells::for($this->shop->id, $current, Product::where('business_id', $this->shop->id));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($got->isEmpty());
        $this->assertSame([], $queries);
    }

    public function test_a_half_written_entry_opens_nothing(): void
    {
        foreach ([['category' => '', 'limit' => 6], ['category' => 'الإضافات', 'limit' => 0], ['limit' => 6], 'الإضافات'] as $row) {
            config(['storefront.ribbon_product_upsells' => [$this->shop->id => $row]]);
            $this->assertNull(RibbonUpsells::settings($this->shop->id), json_encode($row, JSON_UNESCAPED_UNICODE));
            $this->assertSame([], $this->upsellIds($this->p['main']));
        }
    }

    /* ═══════════ ما يُعرض ═══════════ */

    public function test_the_listed_shop_offers_its_own_add_ons_by_name_then_id(): void
    {
        // بالاسم: «بالون» قبل «شوكولاتة» وإن أُنشئ بعدها — وما لا يُعرض خارجها
        $this->assertSame([$this->p['balloon'], $this->p['choc']], $this->upsellIds($this->p['main']));

        // وتعادلُ الاسم يُحسم بالمعرّف
        $twin = $this->product($this->shop, $this->cat['addons'], 'بالون', []);
        $this->assertSame([$this->p['balloon'], $twin, $this->p['choc']], $this->upsellIds($this->p['main']));
    }

    public function test_another_shops_add_ons_never_appear(): void
    {
        $ids = $this->upsellIds($this->p['main']);
        $this->assertNotContains($this->p['foreign'], $ids);

        // ولو كُتب المتجران كلاهما في القائمة — كلٌّ يرى قسمَه
        config(['storefront.ribbon_product_upsells' => [
            $this->shop->id => ['category' => 'الإضافات', 'limit' => 6],
            $this->other->id => ['category' => 'الإضافات', 'limit' => 6],
        ]]);
        $this->assertSame([$this->p['foreign']], $this->upsellIds($this->p['otherMain'], 'ar', 'other'));
        $this->assertNotContains($this->p['foreign'], $this->upsellIds($this->p['main']));
    }

    public function test_the_category_is_matched_whole_and_inside_the_shop_only(): void
    {
        // قسمٌ بالاسم نفسه عند الآخر وحده لا يكفي
        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'غير موجود', 'limit' => 6]]]);
        $this->category($this->other, 'غير موجود', null);
        $this->assertSame([], $this->upsellIds($this->p['main']));
        $this->assertStringNotContainsString('data-rb-upsells', $this->page($this->p['main'])->getContent());

        // ولا تُسوّى الهمزة: «الاضافات» في الإعداد لا تفتح «الإضافات» في المتجر
        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الاضافات', 'limit' => 6]]]);
        $this->assertSame([], $this->upsellIds($this->p['main']));

        // ولا يُطابَق بعضُ الاسم — ولا اسمٌ يحويه ويزيد عليه
        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الإضافة', 'limit' => 6]]]);
        $this->assertSame([], $this->upsellIds($this->p['main']));

        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الإضافات', 'limit' => 6]]]);
        $seasonal = $this->product($this->shop, $this->category($this->shop, 'الإضافات الموسمية', null), 'أ فانوس', []);
        $this->assertSame([$this->p['balloon'], $this->p['choc']], $this->upsellIds($this->p['main']));
        $this->assertNotContains($seasonal, $this->upsellIds($this->p['main']));
    }

    /**
     * والقسمُ يُحصر بالمتجر بنفسه — لا يتّكئ على حصر المستدعي.
     *
     * المتحكّمُ يمرّر قاعدةَ الواجهة محصورةً بالمتجر، فالعزلُ فيها قائم. وهذا
     * يُثبت أنّ القسمَ وحده يعزل أيضًا: لو مُرّر استعلامٌ بلا متجر لما عبر
     * قسمُ الإضافات عند الآخر إلى هذه الصفحة.
     *
     * والمتجرُ يبيع تحت الصفر: `Shelf` حينها لا تسأل القاعدة فلا تُسقط صنفًا
     * غريبًا — فيبقى القسمُ الحاجزَ وحده.
     */
    public function test_the_category_alone_keeps_the_other_shop_out(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'allow_negative_stock', 'value' => '1']);
        $unscoped = Product::query()->where('active', true)->where('published', true);

        $got = RibbonUpsells::for($this->shop->id, Product::find($this->p['main']), $unscoped)->pluck('id')->all();

        // الترتيبُ ليس سؤالَ هذا الحارس: «أ» تسبق في SQLite وتتأخّر في ترتيب PostgreSQL
        $this->assertEqualsCanonicalizing([$this->p['empty'], $this->p['balloon'], $this->p['choc']], $got);
        $this->assertNotContains($this->p['foreign'], $got);
    }

    public function test_hidden_off_and_empty_add_ons_are_left_out(): void
    {
        $ids = $this->upsellIds($this->p['main']);

        $this->assertNotContains($this->p['inactive'], $ids);
        $this->assertNotContains($this->p['hidden'], $ids);
        $this->assertNotContains($this->p['empty'], $ids, 'نفد من الرفّ');
    }

    public function test_the_shelf_rule_is_the_one_asked_not_a_bare_quantity(): void
    {
        // متجرٌ يبيع تحت الصفر لا نفادَ عنده — بقاعدة `Shelf` نفسِها
        Setting::create(['business_id' => $this->shop->id, 'key' => 'allow_negative_stock', 'value' => '1']);

        $this->assertContains($this->p['empty'], $this->upsellIds($this->p['main']));
    }

    public function test_other_products_of_the_shop_are_not_offered(): void
    {
        $other = $this->product($this->shop, $this->cat['main'], 'باقة ثانية', []);

        $this->assertNotContains($this->p['main'], $this->upsellIds($other));
        $this->assertNotContains($other, $this->upsellIds($this->p['main']));
    }

    /** وبه يخرج الصنفُ المفتوحُ من قائمته: لا تُفتح صفحةُ إضافةٍ على قسم الإضافات */
    public function test_an_add_on_page_offers_no_add_ons_not_even_itself(): void
    {
        foreach (['balloon', 'choc'] as $key) {
            $this->assertSame([], $this->upsellIds($this->p[$key]), $key);
            $this->assertStringNotContainsString('data-rb-upsells', $this->page($this->p[$key])->getContent());
        }
    }

    public function test_the_limit_is_kept(): void
    {
        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الإضافات', 'limit' => 1]]]);

        $this->assertSame([$this->p['balloon']], $this->upsellIds($this->p['main']));
    }

    public function test_a_sold_out_product_offers_nothing_beside_it(): void
    {
        Product::whereKey($this->p['main'])->update(['quantity' => 0, 'tracks_stock' => true]);

        $res = $this->page($this->p['main']);
        $this->assertSame([], $res->viewData('upsells'));
        $this->assertStringContainsString('data-testid="rb-soldout"', $res->getContent());
        $this->assertStringNotContainsString('data-rb-upsells', $res->getContent());
    }

    /* ═══════════ ما يصل الصفحة ═══════════ */

    public function test_each_add_on_carries_what_the_page_draws_from_the_database(): void
    {
        $up = collect($this->page($this->p['main'])->viewData('upsells'))->keyBy('id');

        $balloon = $up[$this->p['balloon']];
        $this->assertSame('بالون', $balloon['name']);
        $this->assertSame(1.5, $balloon['price']);
        $this->assertSame([], $balloon['sizes']);

        $choc = $up[$this->p['choc']];
        $this->assertSame(2.0, $choc['price'], 'أدنى مقاسٍ مفعّل');
        $this->assertTrue($choc['from']);
        $this->assertSame(['علبة صغيرة', 'علبة كبيرة'], array_column($choc['sizes'], 'name'), 'المفعّلةُ وحدها وبترتيبها');
        $this->assertSame([2.0, 4.0], array_column($choc['sizes'], 'price'));
    }

    public function test_the_arabic_page_draws_the_section_between_the_quantity_and_the_button(): void
    {
        $html = $this->page($this->p['main'])->getContent();

        $this->assertMatchesRegularExpression('/<h2[^>]*>أضف مع طلبك<\/h2>/u', $html);
        $this->assertMatchesRegularExpression('/data-rb-up-need hidden>اختر خيارًا لهذه الإضافة قبل الإضافة إلى السلة</u', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('بالون', $html);

        $order = [
            strpos($html, 'data-rb-sizes'),
            strpos($html, 'data-rb-qty'),
            strpos($html, 'data-rb-upsells'),
            strpos($html, 'data-testid="rb-add"'),
            strpos($html, 'نوصل خلال ساعتين'),
        ];
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'المقاسُ ثمّ الكمّيّةُ ثمّ الإضافاتُ ثمّ الزرُّ ثمّ ملاحظةُ التوصيل');

        // والسكربتُ الذي يركّب السلّة يُضمَّن كما هو
        $this->assertStringContainsString('window.RBUpsells', $html);
        $this->assertStringContainsString('RBUpsells.mount', $html);
    }

    public function test_the_english_page_says_it_in_english(): void
    {
        $html = $this->page($this->p['main'], 'en')->getContent();

        // العنوانُ المرسوم — لا تعليقُ السكربت المضمَّن
        $this->assertMatchesRegularExpression('/<h2[^>]*>Add to your order<\/h2>/', $html);
        $this->assertStringNotContainsString('>أضف مع طلبك<', $html);
        $this->assertMatchesRegularExpression('/data-rb-up-need hidden>Choose an option for this add-on before adding to cart</', $html);
        $this->assertStringContainsString('Balloon', $html);
        $this->assertStringContainsString('Small box', $html);
    }

    public function test_a_simple_add_on_needs_no_size_and_a_sized_one_has_none_chosen(): void
    {
        $html = $this->page($this->p['main'])->getContent();

        $this->assertMatchesRegularExpression('/<div class="rb-up" data-rb-up="'.$this->p['balloon'].'"\s+data-testid/', $html, 'البسيطُ بلا شرط مقاس');
        $this->assertMatchesRegularExpression('/<div class="rb-up" data-rb-up="'.$this->p['choc'].'"\s+data-needs-variant/', $html);

        preg_match('/data-rb-up="'.$this->p['choc'].'".*?<\/div>\s*<p/s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('data-rb-up-sizes hidden', $m[0], 'المقاساتُ مطويّةٌ حتّى تُختار الإضافة');
        $this->assertSame(2, substr_count($m[0], 'data-rb-up-size="'));
        $this->assertStringNotContainsString('aria-pressed="true"', $m[0], 'لا مقاسَ مختارٌ بالنيابة عن الزبون');
        $this->assertStringNotContainsString('rb-pill on', $m[0]);

        // والإضافةُ نفسُها غيرُ مختارة
        $this->assertStringNotContainsString('class="rb-up on"', $html);
    }

    /* ═══════════ السلّة والإتمام ═══════════ */

    public function test_the_lines_the_page_composes_are_priced_by_the_server(): void
    {
        $choc = ProductVariant::where('product_id', $this->p['choc'])->where('name', 'علبة كبيرة')->value('id');
        $small = ProductVariant::where('product_id', $this->p['main'])->where('name', 'صغير')->value('id');

        // بصيغة السلّة نفسِها — والثمنُ المرسَل يُتجاهل
        $q = $this->postJson('/s/saud/quote', ['items' => [
            ['id' => $this->p['main'], 'variant_id' => $small, 'qty' => 2],
            ['id' => $this->p['balloon'], 'variant_id' => null, 'qty' => 1, 'price' => 0.001],
            ['id' => $this->p['choc'], 'variant_id' => $choc, 'qty' => 1, 'price' => 0.001],
        ], 'fulfil' => 'pickup'])->assertOk()->json();

        $this->assertSame([30.0, 1.5, 4.0], array_map(fn ($l) => (float) $l['price'] * $l['qty'], $q['lines']));
        $this->assertSame(35.5, (float) $q['subtotal']);
    }

    /* ═══════════ وكرتُ الهدية إضافةً ═══════════ */

    /** كرتُ الهدية في قسم الإضافات — صنفٌ بلا مخزون، ومتجرُه في قائمة الكرت */
    private function giftCardAddOn(): int
    {
        config(['storefront.ribbon_gift_card_product_businesses' => [$this->shop->id]]);

        return $this->product($this->shop, $this->cat['addons'], GiftCard::PRODUCT_NAME, [
            'name_en' => 'Gift card', 'price' => 1.5, 'cost' => 0, 'quantity' => 0, 'tracks_stock' => false, 'is_gift_card' => true,
        ]);
    }

    public function test_the_gift_card_add_on_opens_its_own_message_box_and_no_other_does(): void
    {
        $card = $this->giftCardAddOn();

        $res = $this->page($this->p['main']);
        $ups = collect($res->viewData('upsells'))->keyBy('id');

        $this->assertTrue($ups[$card]['gift_card'], 'الكرتُ إضافةً لا يُعرف كرتًا');
        $this->assertFalse($ups[$this->p['balloon']]['gift_card']);
        $this->assertFalse($ups[$this->p['choc']]['gift_card']);

        $html = $res->getContent();
        $this->assertSame(1, preg_match_all('/<div class="rb-up"[^>]*data-gift-card/', $html), 'خانةُ النصّ لبطاقة الكرت وحدها');
        $this->assertSame(1, substr_count($html, 'data-testid="rb-upsell-card-note"'));
        $this->assertMatchesRegularExpression('/data-rb-up="'.$card.'"[^>]*data-gift-card/', $html);
        // مطويّةٌ حتّى يُختار الكرت — والتسميةُ تسميةُ صفحة الكرت نفسِها
        $this->assertMatchesRegularExpression('/data-rb-up-note-box hidden/', $html);
        $this->assertStringContainsString('رسالة كرت الهدية', $html);
        $this->assertStringNotContainsString('data-testid="rb-card-note"', $html, 'ولا خانةَ للصنف الرئيسيّ');
    }

    public function test_the_card_message_rides_on_the_card_line_only(): void
    {
        $card = $this->giftCardAddOn();
        $small = ProductVariant::where('product_id', $this->p['main'])->where('name', 'صغير')->value('id');

        // كما يركّبها `RBUpsells`: الصنفُ بلا نصّ، والكرتُ بنصّه — بصيغة صفحة الكرت نفسِها
        $q = $this->postJson('/s/saud/quote', ['items' => [
            ['id' => $this->p['main'], 'variant_id' => $small, 'qty' => 1, 'note' => 'لا يُكتب على الباقة'],
            ['id' => $card, 'variant_id' => null, 'qty' => 1, 'note' => '  كل عام وأنت بخير  '],
        ], 'fulfil' => 'pickup'])->assertOk()->json();

        $this->assertSame(
            [[false, null], [true, 'كل عام وأنت بخير']],
            array_map(fn ($l) => [(bool) $l['gift_card'], $l['note']], $q['lines']),
            'النصُّ انتقل إلى غير الكرت — أو لم يصل الكرت',
        );
    }

    public function test_the_card_add_on_without_its_message_is_refused_like_on_its_own_page(): void
    {
        $card = $this->giftCardAddOn();

        $this->postJson('/s/saud/quote', ['items' => [
            ['id' => $this->p['main'], 'qty' => 1],
            ['id' => $card, 'qty' => 1],
        ]])->assertStatus(422);
    }

    /* ═══════════ وكرتٌ أُنشئ قبل أن يصير صنفًا ═══════════ */

    /** هجرتا الكرت القائم كما تجريان على الإنتاج: فكُّ الربط، ثمّ العلامة */
    private function freeLegacyCards(): void
    {
        (require base_path('database/migrations/2026_10_03_200000_a_gift_card_made_before_it_was_a_card_leaves_the_stock_book.php'))->up();
        (require base_path('database/migrations/2026_10_03_230100_the_shops_existing_gift_card_gets_its_mark.php'))->up();
    }

    /**
     * ما وقع في متجر سعود: كرتٌ أُنشئ من «المنتجات» قبل قائمة الكرت بافتراض
     * الشاشة (`tracks_stock = true`) وبرصيدٍ يضعه على الرفّ — فيُعرض إضافةً
     * ولا يُعرف كرتًا، فلا تُفتح خانةُ رسالته. والهجرةُ تفكّ ربطَه وحده.
     */
    public function test_a_card_made_before_the_list_was_tracked_and_is_freed_into_a_card(): void
    {
        config(['storefront.ribbon_gift_card_product_businesses' => [$this->shop->id]]);
        $card = $this->product($this->shop, $this->cat['addons'], GiftCard::PRODUCT_NAME, [
            'name_en' => 'Gift card', 'price' => 1.5, 'quantity' => 7, 'tracks_stock' => true,
        ]);
        // وصنفٌ باسم الكرت في متجرٍ خارج القائمة — لا يُمسّ
        $theirs = $this->product($this->other, $this->category($this->other, 'كروت', null), GiftCard::PRODUCT_NAME, ['tracks_stock' => true]);

        $before = $this->page($this->p['main']);
        $this->assertFalse(collect($before->viewData('upsells'))->keyBy('id')[$card]['gift_card'], 'الحالُ التي وقعت لم تُستعد');
        // والبحثُ في وسم البطاقة: سكربتُ الإضافات المضمَّن في الصفحة يذكر العلامةَ نصًّا
        $this->assertDoesNotMatchRegularExpression('/<div class="rb-up"[^>]*data-gift-card/', $before->getContent());

        $this->freeLegacyCards();

        $after = $this->page($this->p['main']);
        $this->assertTrue(collect($after->viewData('upsells'))->keyBy('id')[$card]['gift_card']);
        $this->assertMatchesRegularExpression('/data-rb-up="'.$card.'"[^>]*data-gift-card/', $after->getContent());
        $this->assertMatchesRegularExpression('/data-rb-up-note-box hidden/', $after->getContent());

        // فكُّ الربط وحده: الكميّةُ والثمنُ كما هما، والأصنافُ الأخرى والمتجرُ الآخر على حالهم
        $row = DB::table('products')->where('id', $card)->first();
        $this->assertFalse((bool) $row->tracks_stock);
        $this->assertSame(7, (int) $row->quantity);
        $this->assertEquals(1.5, (float) $row->price);
        $this->assertTrue((bool) DB::table('products')->where('id', $this->p['balloon'])->value('tracks_stock'));
        $this->assertTrue((bool) DB::table('products')->where('id', $this->p['empty'])->value('tracks_stock'));
        $this->assertTrue((bool) DB::table('products')->where('id', $theirs)->value('tracks_stock'));

        // والكرتُ بعدها يدخل برسالته — ويُردّ بلاها
        $this->postJson('/s/saud/quote', ['items' => [['id' => $card, 'qty' => 1, 'note' => 'مبروك']], 'fulfil' => 'pickup'])
            ->assertOk()->assertJsonPath('lines.0.note', 'مبروك');
        $this->postJson('/s/saud/quote', ['items' => [['id' => $card, 'qty' => 1]]])->assertStatus(422);
    }

    public function test_twin_cards_are_left_for_the_owner_not_picked_by_the_migration(): void
    {
        config(['storefront.ribbon_gift_card_product_businesses' => [$this->shop->id]]);
        $a = $this->product($this->shop, $this->cat['addons'], GiftCard::PRODUCT_NAME, ['tracks_stock' => true]);
        $b = $this->product($this->shop, $this->cat['addons'], GiftCard::PRODUCT_NAME, ['tracks_stock' => true]);

        $this->freeLegacyCards();

        $this->assertSame([true, true], DB::table('products')->whereIn('id', [$a, $b])->orderBy('id')->pluck('tracks_stock')->map(fn ($v) => (bool) $v)->all());
    }

    public function test_a_shop_outside_the_card_list_keeps_its_card_named_product_tracked(): void
    {
        config(['storefront.ribbon_gift_card_product_businesses' => []]);
        $card = $this->product($this->shop, $this->cat['addons'], GiftCard::PRODUCT_NAME, ['tracks_stock' => true]);

        $this->freeLegacyCards();

        $this->assertTrue((bool) DB::table('products')->where('id', $card)->value('tracks_stock'));
        $this->assertFalse(collect($this->page($this->p['main'])->viewData('upsells'))->keyBy('id')[$card]['gift_card']);
    }

    public function test_an_add_on_from_another_shop_is_refused_at_the_quote(): void
    {
        $this->postJson('/s/saud/quote', ['items' => [
            ['id' => $this->p['main'], 'qty' => 1],
            ['id' => $this->p['foreign'], 'qty' => 1],
        ]])->assertStatus(422);
    }
}

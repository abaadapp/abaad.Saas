<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StoreNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * متجرُ الواجهة الخاصّة له صفحاتُ سائر متاجر أبعاد — وقائمةٌ تصل بينها.
 *
 * ═══ وما كان ناقصًا ═══
 *
 * المتجرُ العامّ يُبنى على أربع صفحات: «الرئيسية» و«المتجر» و«من نحن»
 * و«تواصل معنا» (انظر `Blueprints::PAGES`). وواجهةُ RIBBON كانت صفحةً
 * ورفًّا وصنفًا وسلّة، بلا قائمةٍ أصلًا: من أراد أن يعرف من يبيعه، أو أن
 * يسأل قبل أن يدفع، لا يجد بابًا.
 *
 * ═══ وكلُّ رابطٍ يُرسم يُفتح ═══
 *
 * وهو أثقلُ ما هنا: القائمةُ والتذييلُ والمتحكّمُ يسألون «أهذه الصفحة
 * قائمة؟»، ولو أجاب كلٌّ منهم بنفسه لَبقي في القائمة رابطٌ يردّ ٤٠٤.
 */
class AThemedStoreHasTheSamePagesAsEveryStoreTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'address' => 'الخوير', 'site_slug' => 'ribbon',
            'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
    }

    /** رفٌّ عامر — وإلّا سقطت «المتجر» من القائمة بحقّ */
    private function stock(): void
    {
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 12, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function say(array $values): void
    {
        MarketingSettings::save($this->shop->id, 'website', $values);
    }

    /* ═══════════ القائمة ═══════════ */

    public function test_the_four_pages_of_every_store_are_in_the_menu(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير منذ ٢٠١٥.']);

        $page = $this->get('/s/ribbon');

        $page->assertOk()->assertSee('data-testid="rb-nav"', false);

        foreach (['home', 'shop', 'about', 'contact'] as $key) {
            $page->assertSee('data-testid="rb-nav-'.$key.'"', false);
            $page->assertSee('data-testid="rb-foot-'.$key.'"', false);
        }
    }

    /**
     * وكلُّ رابطٍ في القائمة يُفتح — وهو الحارسُ الذي لا يُستغنى عنه.
     *
     * فالقائمةُ تُبنى من `StoreNav::links` والصفحةُ تُخدم بعد `StoreNav::has`،
     * وهما اليوم سؤالٌ واحد. ولو افترقا يومًا لَسقط هذا.
     */
    public function test_every_link_the_menu_draws_really_opens(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير منذ ٢٠١٥.']);

        $html = $this->get('/s/ribbon')->getContent();

        preg_match_all('/data-testid="rb-nav-([a-z]+)" *\n? *[^>]*>/', $html, $m);
        preg_match_all('/<a href="([^"]*)"\s+class="rb-nav-a/', $html, $hrefs);

        $this->assertNotEmpty($hrefs[1], 'لم تُرسم قائمةٌ أصلًا');

        foreach ($hrefs[1] as $href) {
            $this->get($href)->assertOk();
        }
    }

    public function test_a_one_link_menu_is_no_menu(): void
    {
        // لا بضاعةَ ولا نبذةَ ولا هاتفَ — فلا يبقى إلّا «الرئيسية»
        $this->shop->update(['phone' => '', 'address' => '', 'city' => '']);

        $this->get('/s/ribbon')->assertOk()->assertDontSee('data-testid="rb-nav"', false);
    }

    public function test_an_empty_shelf_drops_the_shop_link(): void
    {
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير.']);

        $page = $this->get('/s/ribbon');

        $page->assertOk()
            ->assertSee('data-testid="rb-nav-about"', false)
            ->assertDontSee('data-testid="rb-nav-shop"', false);
    }

    /* ═══════════ «من نحن» ═══════════ */

    public function test_about_opens_with_its_text(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير منذ ٢٠١٥.']);

        $this->get('/s/ribbon/about')
            ->assertOk()
            ->assertSee('data-testid="rb-about"', false)
            ->assertSee('محلُّ وردٍ في الخوير منذ ٢٠١٥.')
            ->assertSee('data-testid="rb-about-shop"', false);
    }

    public function test_about_with_no_text_is_not_a_page(): void
    {
        $this->stock();

        $this->get('/s/ribbon/about')->assertNotFound();
        $this->get('/s/ribbon')->assertOk()->assertDontSee('data-testid="rb-nav-about"', false);
    }

    public function test_about_on_an_empty_shelf_promises_no_shop(): void
    {
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير.']);

        $this->get('/s/ribbon/about')
            ->assertOk()
            ->assertDontSee('data-testid="rb-about-shop"', false);
    }

    /* ═══════════ «تواصل معنا» ═══════════ */

    public function test_contact_opens_with_what_he_wrote(): void
    {
        $this->stock();

        $this->get('/s/ribbon/contact')
            ->assertOk()
            ->assertSee('data-testid="rb-c-phone"', false)
            ->assertSee('tel:96895259066', false)
            ->assertSee('data-testid="rb-c-address"', false)
            ->assertSee('data-testid="rb-c-map"', false);
    }

    public function test_contact_with_nothing_to_say_is_not_a_page(): void
    {
        $this->shop->update(['phone' => '', 'email' => '', 'address' => '', 'city' => '']);

        $this->get('/s/ribbon/contact')->assertNotFound();
    }

    public function test_an_address_written_only_in_hours_still_opens_contact(): void
    {
        $this->shop->update(['phone' => '', 'email' => '', 'address' => '', 'city' => '']);
        $this->say(['store_hours' => 'يوميًّا ٩ ص – ١٠ م']);

        $this->get('/s/ribbon/contact')
            ->assertOk()
            ->assertSee('data-testid="rb-c-hours"', false)
            ->assertDontSee('data-testid="rb-c-map"', false);
    }

    /* ═══════════ ما أطفأه صاحبُه ═══════════ */

    /**
     * وصفحةٌ أُطفئت تُردّ «غير موجود» — لا صفحةً بيضاء، ولا رابطًا في القائمة.
     *
     * والرابطُ يبقى محفوظًا في متصفّحٍ ومفهرَسًا عند غوغل بعد أن تُطفأ،
     * فالفحصُ في المتحكّم لا في القائمة وحدها: إخفاءُ رابطٍ ليس حراسة.
     */
    public function test_a_page_he_switched_off_is_not_served(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير.', 'store_pages' => StoreNav::CONTACT]);

        $this->get('/s/ribbon/about')->assertNotFound();
        $this->get('/s/ribbon')->assertOk()->assertDontSee('data-testid="rb-nav-about"', false);
        $this->get('/s/ribbon/contact')->assertOk();
    }

    /**
     * ومفتاحٌ فارغٌ يعني «كلُّها» — قاعدةُ `StorePage::order` نفسُها.
     *
     * وترقيةٌ تُنزل المفتاح فارغًا على متجرٍ قائم لا تُطفئ له صفحة.
     */
    public function test_an_empty_key_keeps_every_page(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير.', 'store_pages' => '']);

        $this->get('/s/ribbon/about')->assertOk();
        $this->get('/s/ribbon/contact')->assertOk();
    }

    /* ═══════════ وما تقوله شاشةُ «الصفحات» لصاحبه ═══════════ */

    /**
     * ═══ و«مُشغَّلةٌ ولا تظهر» تُقال بسببها ═══
     *
     * وهذا حارسٌ وُضع بعد طفرةٍ نجت: `rows` كانت تحمل السببَ ولا يسأله أحد
     * في الخادم — الشاشةُ ترسمه إن وصلها، ولا شيءَ يتحقّق أنّه يُحسب.
     *
     * وصاحبُ المتجر يفتح «من نحن» في لوحته فيجدها مُشغَّلةً، ثمّ يفتح متجره
     * فلا يجدها. والسببُ عندنا مكتوب — نبذةٌ لم تُكتب — فلا يُترك يُخمَّن.
     */
    public function test_a_page_that_is_on_but_will_not_show_says_why(): void
    {
        $rows = collect(StoreNav::rows($this->shop->id))->keyBy('key');

        $this->assertStringContainsString('اكتب نبذتك', (string) $rows[StoreNav::ABOUT]['blocked']);
        $this->assertStringContainsString('لا صنفَ معروضًا', (string) $rows[StoreNav::SHOP]['blocked']);

        // والتواصلُ له هاتفٌ وعنوان — فلا شكوى
        $this->assertNull($rows[StoreNav::CONTACT]['blocked']);
        $this->assertNull($rows[StoreNav::HOME]['blocked']);
    }

    /** وما استقام لا يُشتكى منه */
    public function test_a_page_that_will_show_says_nothing(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير.']);

        foreach (StoreNav::rows($this->shop->id) as $row) {
            $this->assertNull($row['blocked'], $row['key'].' تُفتح ويُشتكى منها');
        }
    }

    /** ولا يُقال «لا تظهر» لصفحةٍ أطفأها بيده: هو يعرف لمَ أطفأها */
    public function test_a_page_he_switched_off_is_not_complained_about(): void
    {
        $this->say(['store_pages' => StoreNav::NONE]);

        $rows = collect(StoreNav::rows($this->shop->id))->keyBy('key');

        $this->assertFalse($rows[StoreNav::ABOUT]['on']);
        $this->assertNull($rows[StoreNav::ABOUT]['blocked']);
    }

    /** والرئيسيةُ والمتجرُ بلا مفتاحِ إطفاء — ومقبضٌ لا يُدير شيئًا أسوأُ من غيابه */
    public function test_the_home_and_shop_carry_no_switch(): void
    {
        $rows = collect(StoreNav::rows($this->shop->id))->keyBy('key');

        $this->assertTrue($rows[StoreNav::HOME]['fixed']);
        $this->assertTrue($rows[StoreNav::SHOP]['fixed']);
        $this->assertFalse($rows[StoreNav::ABOUT]['fixed']);
        $this->assertFalse($rows[StoreNav::CONTACT]['fixed']);
    }

    /* ═══════════ ما يقرؤه غوغل ═══════════ */

    public function test_the_title_and_description_follow_what_he_wrote(): void
    {
        $this->stock();
        $this->say([
            'store_seo_title' => 'ريبون لاونج — ورد وهدايا بمسقط',
            'store_seo_desc' => 'باقاتُ وردٍ تُوصَّل في مسقط خلال اليوم نفسه.',
        ]);

        $this->get('/s/ribbon')
            ->assertOk()
            ->assertSee('<title>ريبون لاونج — ورد وهدايا بمسقط</title>', false)
            ->assertSee('باقاتُ وردٍ تُوصَّل في مسقط خلال اليوم نفسه.');

        // واسمُ الصفحة يُضمّ إليه ولا يُبدّله — نتيجتان بعنوانٍ واحد لا تُميَّزان
        $this->get('/s/ribbon/shop')
            ->assertOk()
            ->assertSee('<title>المتجر — ريبون لاونج — ورد وهدايا بمسقط</title>', false);
    }

    public function test_a_page_that_writes_its_own_title_still_carries_his_name(): void
    {
        $this->stock();
        $this->say(['store_seo_title' => 'ريبون لاونج']);

        $this->get('/s/ribbon/cart')->assertOk()->assertSee('<title>السلة — ريبون لاونج</title>', false);
    }

    public function test_nothing_written_keeps_what_the_store_showed_before(): void
    {
        $this->stock();
        $this->say(['store_about' => 'محلُّ وردٍ في الخوير.']);

        $this->get('/s/ribbon')
            ->assertOk()
            ->assertSee('<title>RIBBON</title>', false)
            ->assertSee('محلُّ وردٍ في الخوير.');
    }

    public function test_he_can_keep_his_store_out_of_search(): void
    {
        $this->stock();

        $this->get('/s/ribbon')->assertOk()->assertDontSee('rb-noindex', false);

        $this->say(['store_seo_index' => '0']);

        $this->get('/s/ribbon')->assertOk()->assertSee('data-testid="rb-noindex"', false);
    }

    /* ═══════════ الإنجليزية ═══════════ */

    public function test_the_menu_reads_in_the_visitors_language(): void
    {
        $this->stock();
        $this->say(['store_about' => 'A flower shop in Al Khuwair.']);

        $this->get('/s/ribbon?lang=en')
            ->assertOk()
            ->assertSee('>Home<', false)
            ->assertSee('>About<', false)
            ->assertSee('>Contact<', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\RibbonTexts;
use App\Support\Store\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يكتبه صاحبُ متجر RIBBON لزبونه — لكلّ لغةٍ نصُّها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - عنوانُ الواجهة ووصفُها: ما كتبه للّغة، وإلّا نصُّ القالب للّغة نفسِها.
 *     ومتجرٌ لم يكتب شيئًا يرى الواجهةَ كما كانت حرفًا.
 *   - ولا تقع لغةٌ على أخرى: لا عربيٌّ في صفحةٍ إنجليزيّة ولا العكس —
 *     في الواجهة، والقسمِ الحرّ، وملاحظةِ التوصيل، وما يقرؤه غوغل.
 *   - وحفظُ لغةٍ لا يمحو الأخرى.
 *   - وما كُتب بالعربيّة قبل اليوم يبقى كما هو في الصفحة العربيّة.
 *   - ونصوصُ القالب (الأزرار وأسماءُ الأقسام) تبقى في `RibbonTexts` لا إعدادًا.
 *
 * والمتجرُ عامٌّ — لا معرّفَ مكتوبٌ يُفرض عليه.
 */
class ARibbonPageSpeaksEachLanguageItsOwnWordsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private int $rose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'name_en' => 'Rose bouquet',
            'price' => 20, 'cost' => 8, 'quantity' => 10, 'active' => true, 'published' => true,
        ])->id;

        // ملاحظةُ التوصيل تُرسم تحت زرّ السلّة — والقائمةُ التي تُخفيها لسعود شأنٌ آخر
        config(['storefront.ribbon_product_page_without_delivery_note_businesses' => []]);
    }

    private function set(array $values): void
    {
        MarketingSettings::save($this->shop->id, 'website', $values);
    }

    private function save(array $values)
    {
        return $this->actingAs($this->owner)->from(route('admin.website.design'))
            ->post(route('admin.marketing.store.save'), $values);
    }

    private function home(string $lang): string
    {
        return (string) $this->get('/s/ribbon?lang='.$lang)->assertOk()->getContent();
    }

    /** نصُّ وسمٍ بعلامته — ما يراه الزائر لا شرحَ القالب */
    private function mark(string $html, string $testid): ?string
    {
        return preg_match('#data-testid="'.$testid.'"[^>]*>(.*?)</#s', $html, $m) ? html_entity_decode(trim($m[1])) : null;
    }

    private function meta(string $html, string $pattern): ?string
    {
        return preg_match($pattern, $html, $m) ? html_entity_decode($m[1]) : null;
    }

    /* ═══════════ عنوانُ الواجهة ووصفُها ═══════════ */

    /** ١ و٢: من لم يكتب شيئًا يرى الواجهةَ كما كانت — بالعربيّة وبالإنجليزيّة */
    public function test_a_shop_that_wrote_nothing_keeps_its_hero_in_both_languages(): void
    {
        $ar = $this->home('ar');
        $en = $this->home('en');

        $this->assertSame('باقات ورد للتوصيل', $this->mark($ar, 'rb-hero-title'));
        $this->assertSame('اختر باقتك، حدّد الحجم وموعد التوصيل، وأتمّ الطلب في صفحة واحدة.', $this->mark($ar, 'rb-hero-sub'));
        $this->assertSame('Flower bouquets, delivered', $this->mark($en, 'rb-hero-title'));
        $this->assertSame('Pick a bouquet, choose a size and delivery time, and order on a single page.', $this->mark($en, 'rb-hero-sub'));

        // والنصُّ الأصليُّ نصُّ القالب نفسُه — لا نسخةٌ ثانيةٌ تفترق عنه
        $this->assertSame(RibbonTexts::for('ar')['heroTitle'], $this->mark($ar, 'rb-hero-title'));
        $this->assertSame(RibbonTexts::for('en')['heroSub'], $this->mark($en, 'rb-hero-sub'));
    }

    /** ٣ إلى ٨: لكلّ لغةٍ عنوانُها ووصفُها، ولا تقع إحداهما على الأخرى */
    public function test_each_language_reads_its_own_hero_and_never_the_others(): void
    {
        $this->save([
            'store_hero_title' => 'ورد يصل في ساعته',
            'store_hero_title_en' => 'Flowers that arrive on time',
            'store_hero_sub' => 'باقاتٌ تُنسَّق باليد كلَّ صباح.',
            'store_hero_sub_en' => 'Bouquets arranged by hand every morning.',
        ])->assertSessionHasNoErrors();

        $ar = $this->home('ar');
        $en = $this->home('en');

        $this->assertSame('ورد يصل في ساعته', $this->mark($ar, 'rb-hero-title'));
        $this->assertSame('باقاتٌ تُنسَّق باليد كلَّ صباح.', $this->mark($ar, 'rb-hero-sub'));
        $this->assertSame('Flowers that arrive on time', $this->mark($en, 'rb-hero-title'));
        $this->assertSame('Bouquets arranged by hand every morning.', $this->mark($en, 'rb-hero-sub'));

        $this->assertStringNotContainsString('ورد يصل في ساعته', $en, 'العنوانُ العربيّ في الصفحة الإنجليزيّة');
        $this->assertStringNotContainsString('باقاتٌ تُنسَّق', $en);
        $this->assertStringNotContainsString('Flowers that arrive on time', $ar, 'العنوانُ الإنجليزيّ في الصفحة العربيّة');
        $this->assertStringNotContainsString('Bouquets arranged by hand', $ar);
    }

    /** ولغةٌ كُتبت وحدها لا تقع على الأخرى — الأخرى تبقى على نصّ القالب */
    public function test_a_language_left_empty_keeps_its_own_default_not_the_other_text(): void
    {
        $this->set(['store_hero_title' => 'ورد يصل في ساعته', 'store_hero_sub_en' => 'Hand-tied daily.']);

        $this->assertSame('Flower bouquets, delivered', $this->mark($this->home('en'), 'rb-hero-title'));
        $this->assertSame('اختر باقتك، حدّد الحجم وموعد التوصيل، وأتمّ الطلب في صفحة واحدة.', $this->mark($this->home('ar'), 'rb-hero-sub'));
    }

    /** ٩: حفظُ لغةٍ لا يمحو الأخرى */
    public function test_saving_one_language_never_erases_the_other(): void
    {
        $this->save(['store_hero_title' => 'عنوانٌ عربيّ', 'store_hero_title_en' => 'English title'])->assertSessionHasNoErrors();
        $this->save(['store_hero_title' => 'عنوانٌ عربيٌّ جديد'])->assertSessionHasNoErrors();
        $this->save(['store_hero_sub_en' => 'English description'])->assertSessionHasNoErrors();

        $site = MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame('عنوانٌ عربيٌّ جديد', $site['store_hero_title']);
        $this->assertSame('English title', $site['store_hero_title_en'], 'حفظُ العربيّ محا الإنجليزيّ');
        $this->assertSame('English description', $site['store_hero_sub_en']);
        $this->assertSame('', $site['store_hero_sub']);
    }

    /** ١٠: وصورةُ الواجهة كما كانت — يختارها، وإلّا أوّلُ صنفٍ مبيعًا */
    public function test_the_hero_image_is_untouched(): void
    {
        $this->set(['store_hero_title' => 'عنوان', 'store_hero_title_en' => 'Title', 'store_hero_image' => '/storage/store/hero.jpg']);

        foreach (['ar', 'en'] as $lang) {
            $this->assertMatchesRegularExpression('#<img src="/storage/store/hero.jpg"[^>]*data-testid="rb-hero-image"#', $this->home($lang));
        }
    }

    /** وحدودُ النصّ تُقال برسالة — عنوانٌ ٨٠ ووصفٌ ٢٠٠ */
    public function test_the_hero_texts_have_their_limits(): void
    {
        $this->save(['store_hero_title_en' => str_repeat('a', 81)])->assertSessionHasErrors('store_hero_title_en');
        $this->save(['store_hero_sub' => str_repeat('ب', 201)])->assertSessionHasErrors('store_hero_sub');
        $this->save(['store_hero_title' => str_repeat('ب', 80), 'store_hero_sub_en' => str_repeat('a', 200)])->assertSessionHasNoErrors();
    }

    /**
     * و«العنوان الكبير» القديم يبقى عنوانَ الصفحة العربيّة لمن كتبه — ولا يُكتب
     * في الإنجليزيّة. وعنوانُ الواجهة العربيّ إن كُتب يتقدّمه.
     */
    public function test_an_old_headline_stays_arabic_only_and_yields_to_the_new_title(): void
    {
        $this->set(['store_headline' => 'عنوانٌ كُتب قبل اليوم']);

        $this->assertSame('عنوانٌ كُتب قبل اليوم', $this->mark($this->home('ar'), 'rb-hero-title'));
        $this->assertSame('Flower bouquets, delivered', $this->mark($this->home('en'), 'rb-hero-title'));

        $this->set(['store_hero_title' => 'العنوانُ الجديد']);
        $this->assertSame('العنوانُ الجديد', StorePage::heroTitle($this->shop->id, 'ar', RibbonTexts::for('ar')));
    }

    /* ═══════════ القسمُ الحرّ ═══════════ */

    /** ١١ إلى ١٣: لكلّ لغةٍ عنوانُها ونصُّها وزرُّها، والصورةُ والوجهةُ واحدتان */
    public function test_the_free_block_speaks_each_language_and_shares_its_image_and_link(): void
    {
        $this->save([
            'store_sections' => 'block',
            'store_block_on' => '1',
            'store_block_title' => 'اشتراك الورد', 'store_block_text' => 'باقةٌ كلَّ أسبوع.', 'store_block_cta' => 'اشترك',
            'store_block_title_en' => 'Flower subscription', 'store_block_text_en' => 'A bouquet every week.', 'store_block_cta_en' => 'Subscribe',
            'store_block_image' => '/storage/store/block.jpg', 'store_block_href' => '/shop',
        ])->assertSessionHasNoErrors();

        $ar = $this->home('ar');
        $en = $this->home('en');

        foreach (['اشتراك الورد', 'باقةٌ كلَّ أسبوع.', 'اشترك'] as $text) {
            $this->assertStringContainsString($text, $ar);
            $this->assertStringNotContainsString($text, $en, 'نصُّ القسم العربيّ في الصفحة الإنجليزيّة');
        }
        foreach (['Flower subscription', 'A bouquet every week.', 'Subscribe'] as $text) {
            $this->assertStringContainsString($text, $en);
            $this->assertStringNotContainsString($text, $ar, 'نصُّ القسم الإنجليزيّ في الصفحة العربيّة');
        }

        // والصورةُ والوجهةُ في الصفحتين
        foreach ([$ar, $en] as $html) {
            $this->assertStringContainsString('/storage/store/block.jpg', $html);
            $this->assertMatchesRegularExpression('#<a class="rb-btn" href="/shop">#', $html);
        }
    }

    /** وقسمٌ لم يُكتب للإنجليزيّة لا يُرسم فيها — لا يُعرض نصُّه العربيّ */
    public function test_a_block_written_only_in_arabic_does_not_show_on_the_english_page(): void
    {
        $this->set([
            'store_sections' => 'block', 'store_block_on' => '1',
            'store_block_title' => 'اشتراك الورد', 'store_block_text' => 'باقةٌ كلَّ أسبوع.',
        ]);

        $this->assertStringContainsString('rb-sec-block', $this->home('ar'));
        $this->assertStringNotContainsString('rb-sec-block', $this->home('en'));
        $this->assertNull(StorePage::block($this->shop->id, 'en'));
    }

    /* ═══════════ ملاحظةُ التوصيل ═══════════ */

    /** ١٤ و١٥: لكلّ لغةٍ ملاحظتُها، وفارغُ اللغة لا يُرسم */
    public function test_the_delivery_note_is_read_in_the_page_language_only(): void
    {
        $this->save(['store_delivery_note' => 'نوصل خلال ساعتين', 'store_delivery_note_en' => 'Delivered within two hours'])->assertSessionHasNoErrors();

        $ar = (string) $this->get("/s/ribbon/p/{$this->rose}?lang=ar")->assertOk()->getContent();
        $en = (string) $this->get("/s/ribbon/p/{$this->rose}?lang=en")->assertOk()->getContent();

        $this->assertSame('نوصل خلال ساعتين', $this->mark($ar, 'rb-delivery-note'));
        $this->assertSame('Delivered within two hours', $this->mark($en, 'rb-delivery-note'));
        $this->assertStringNotContainsString('نوصل خلال ساعتين', $en);
        $this->assertStringNotContainsString('Delivered within two hours', $ar);

        // وفارغُ الإنجليزيّة لا يُرسم فيها شيء — لا العربيّة
        $this->set(['store_delivery_note_en' => '']);
        $en = (string) $this->get("/s/ribbon/p/{$this->rose}?lang=en")->getContent();
        $this->assertStringNotContainsString('rb-delivery-note', $en);
        $this->assertStringNotContainsString('نوصل خلال ساعتين', $en);
    }

    /* ═══════════ ما يقرؤه غوغل ═══════════ */

    /** ١٦: لكلّ لغةٍ عنوانُها ووصفُها — وفارغُ الإنجليزيّة يُحسب كما كان، لا من العربيّ */
    public function test_search_texts_are_kept_apart_by_language(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_about' => 'Ribbon is a gifting house.']);
        $this->save(['store_seo_title' => 'ريبون — ورد مسقط', 'store_seo_desc' => 'باقاتٌ تُوصَّل في مسقط.'])->assertSessionHasNoErrors();

        $ar = $this->home('ar');
        $en = $this->home('en');

        $this->assertSame('ريبون — ورد مسقط', $this->meta($ar, '#<title>(.*?)</title>#s'));
        $this->assertSame('باقاتٌ تُوصَّل في مسقط.', $this->meta($ar, '#<meta name="description" content="([^"]*)"#'));

        // والإنجليزيّةُ لم يُكتب لها شيء: اسمُ النشاط والنبذة كما كانا — لا عنوانُ البحث العربيّ
        $this->assertSame('RIBBON', $this->meta($en, '#<title>(.*?)</title>#s'));
        $this->assertSame('Ribbon is a gifting house.', $this->meta($en, '#<meta name="description" content="([^"]*)"#'));
        $this->assertStringNotContainsString('ريبون — ورد مسقط', $en);
        $this->assertStringNotContainsString('باقاتٌ تُوصَّل في مسقط.', $en);

        $this->save(['store_seo_title_en' => 'Ribbon — Muscat flowers', 'store_seo_desc_en' => 'Bouquets delivered in Muscat.'])->assertSessionHasNoErrors();
        $en = $this->home('en');
        $ar = $this->home('ar');

        $this->assertSame('Ribbon — Muscat flowers', $this->meta($en, '#<title>(.*?)</title>#s'));
        $this->assertSame('Bouquets delivered in Muscat.', $this->meta($en, '#<meta name="description" content="([^"]*)"#'));
        $this->assertStringNotContainsString('Ribbon — Muscat flowers', $ar);
        $this->assertSame('ريبون — ورد مسقط', $this->meta($ar, '#<title>(.*?)</title>#s'), 'حفظُ الإنجليزيّ مسّ العربيّ');
    }

    /* ═══════════ وما كُتب قبل اليوم ═══════════ */

    /** ١٧: النصُّ العربيُّ المحفوظ قبل اليوم يبقى كما هو في الصفحة العربيّة */
    public function test_old_arabic_content_renders_exactly_as_before(): void
    {
        $this->set([
            'store_sections' => 'block', 'store_block_on' => '1',
            'store_block_title' => 'تنسيق الأعراس', 'store_block_text' => 'نزيّن قاعتك.', 'store_block_cta' => 'احجز', 'store_block_href' => '/contact',
            'store_delivery_note' => 'التوصيل داخل مسقط',
            'store_seo_title' => 'ريبون', 'store_seo_desc' => 'ورد وهدايا.',
        ]);

        $ar = $this->home('ar');
        foreach (['تنسيق الأعراس', 'نزيّن قاعتك.', 'احجز'] as $text) {
            $this->assertStringContainsString($text, $ar);
        }
        $this->assertSame('ريبون', $this->meta($ar, '#<title>(.*?)</title>#s'));

        $product = (string) $this->get("/s/ribbon/p/{$this->rose}?lang=ar")->getContent();
        $this->assertSame('التوصيل داخل مسقط', $this->mark($product, 'rb-delivery-note'));

        // والقيمُ نفسُها في القاعدة — لا يُنقل شيءٌ ولا يُترجَم
        $site = MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame('تنسيق الأعراس', $site['store_block_title']);
        $this->assertSame('', $site['store_block_title_en']);
        $this->assertSame('', $site['store_delivery_note_en']);
    }

    /** ونصوصُ القالب تبقى للقالب — لا تصير إعداداتٍ يكتبها صاحبُ المتجر */
    public function test_system_labels_stay_in_the_template_texts(): void
    {
        $keys = array_keys(MarketingSettings::GROUPS['website']);

        foreach (['shopNow', 'cart', 'add', 'viewAll', 'toCheckout', 'place'] as $label) {
            $this->assertArrayHasKey($label, RibbonTexts::for('ar'));
            $this->assertArrayHasKey($label, RibbonTexts::for('en'));
        }
        foreach (['store_shop_now', 'store_cart_label', 'store_view_all', 'store_checkout_label'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }
}

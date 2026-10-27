<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\RibbonTexts;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreWhatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * زرُّ واتساب يسأل عمّا يقف عليه الزائر — باسمه من القاعدة ورابطه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الرئيسيةُ باسم المتجر ورابطها. والقسمُ والمجموعةُ والصنفُ بأسمائها كما
 *     في القاعدة لا كما في الرابط، والبحثُ بعبارته — كلٌّ برابطه.
 *   - وما لا يُعرف اسمُه لا يُخمَّن: يُسأل بالرسالة العامّة.
 *   - والسلّةُ والإتمامُ والتأكيدُ يسألون بلا رابطٍ ولا شيءٍ من سلّة الزائر أو طلبه.
 *   - والرابطُ على النطاق الذي جاء منه الزائر، وبما يعيّن الصفحةَ وحده.
 *   - وبطاقةُ الصنف تسأل عنه دون فتح صفحته — لمن شُغّل له الزرّ وحده.
 *   - والصنفُ بخياراتٍ يُكتب خيارُه حين يختاره الزائر لا قبل.
 *   - والرقمُ رقمُ التاجر، واللغتان.
 */
class TheRibbonWhatsappAsksAboutWhereTheVisitorIsTest extends TestCase
{
    use RefreshDatabase;

    private const WA = 'https://wa.me/96891112233?text=';

    private Business $shop;

    private Category $perfumes;

    private Product $oud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        // وقوائمُ «سعود» فارغة — فلا يقع عليه متجرٌ يُنشأ هنا بمعرّفه
        foreach ((array) config('storefront') as $key => $value) {
            if (str_starts_with($key, 'ribbon_') && is_array($value)) {
                config(["storefront.{$key}" => []]);
            }
        }

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'city' => 'مسقط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_whatsapp' => '+968 9111 2233']);

        $this->perfumes = Category::create(['business_id' => $this->shop->id, 'name' => 'العطور', 'name_en' => 'Perfumes']);
        $this->oud = Product::create([
            'business_id' => $this->shop->id, 'category_id' => $this->perfumes->id, 'name' => 'عطر العود', 'name_en' => 'Oud perfume',
            'price' => 20, 'cost' => 8, 'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        config(['storefront.ribbon_floating_whatsapp_businesses' => [$this->shop->id]]);
    }

    /** رسالةُ الزرّ العائم في الصفحة — أو `null` إن لم يُرسم */
    private function asks(string $path, string $lang = 'ar'): ?string
    {
        $html = $this->get('/s/ribbon'.$path.(str_contains($path, '?') ? '&' : '?').'lang='.$lang)->assertOk()->getContent();

        return preg_match('/<a class="rb-wa-float" href="([^"]+)"/', $html, $m) ? $this->text($m[1]) : null;
    }

    /** نصُّ الرسالة من رابطها — ورقمُ التاجر أوّلُه */
    private function text(string $href): string
    {
        $url = html_entity_decode($href);
        $this->assertStringStartsWith(self::WA, $url, 'رقمُ التاجر وحده');

        return rawurldecode(substr($url, strlen(self::WA)));
    }

    /* ═══════════════════════ الصفحات ═══════════════════════ */

    public function test_the_home_page_names_the_shop_and_links_home(): void
    {
        $this->assertSame("السلام عليكم، لديّ استفسار عن متجر RIBBON.\nhttp://localhost/s/ribbon", $this->asks(''));
        $this->assertSame("Hello, I have a question about RIBBON.\nhttp://localhost/s/ribbon", $this->asks('', 'en'));
    }

    public function test_a_category_is_named_from_the_database_not_the_link(): void
    {
        $id = $this->perfumes->id;

        $this->assertSame("السلام عليكم، أريد الاستفسار عن قسم العطور.\nhttp://localhost/s/ribbon/shop?cat={$id}", $this->asks("/shop?cat={$id}"));
        $this->assertSame("Hello, I'd like to ask about the Perfumes section.\nhttp://localhost/s/ribbon/shop?cat={$id}", $this->asks("/shop?cat={$id}", 'en'));

        // وقسمٌ لا يُعرف لا يُخمَّن اسمُه — يُسأل بالرسالة العامّة
        $this->assertStringStartsWith("السلام عليكم، لديّ استفسار عن متجر RIBBON.\n", $this->asks('/shop?cat='.($id + 1000))); // رقمٌ مقصود: قسمٌ لا وجود له
    }

    public function test_a_collection_is_named_by_its_title(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => json_encode([
            ['slug' => 'eid', 'title_ar' => 'هدايا العيد', 'title_en' => 'Eid gifts', 'visible' => true, 'products' => [$this->oud->id]],
        ], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame("السلام عليكم، أريد الاستفسار عن مجموعة هدايا العيد.\nhttp://localhost/s/ribbon/shop?collection=eid", $this->asks('/shop?collection=eid'));
        $this->assertSame("Hello, I'd like to ask about the Eid gifts collection.\nhttp://localhost/s/ribbon/shop?collection=eid", $this->asks('/shop?collection=eid', 'en'));
    }

    public function test_a_product_page_names_the_product_and_links_it(): void
    {
        $id = $this->oud->id;

        $this->assertSame("السلام عليكم، أريد الاستفسار عن المنتج: عطر العود\nhttp://localhost/s/ribbon/p/{$id}", $this->asks("/p/{$id}"));
        $this->assertSame("Hello, I'd like to ask about this product: Oud perfume\nhttp://localhost/s/ribbon/p/{$id}", $this->asks("/p/{$id}", 'en'));
    }

    public function test_a_size_is_written_only_once_the_visitor_chooses_it(): void
    {
        foreach (['50 مل' => 20, '100 مل' => 30] as $name => $price) {
            ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->oud->id, 'name' => $name, 'price' => $price, 'active' => true]);
        }

        $html = $this->get("/s/ribbon/p/{$this->oud->id}?lang=ar")->assertOk()->getContent();
        // وعنوانُ الخيار في الرسالة عنوانُه في الصفحة نفسِها
        $size = RibbonTexts::for('ar')['size'];

        // الأوّلُ مختارٌ سلفًا لا باختيار الزائر — فلا يُكتب في الرسالة
        preg_match('/<a class="rb-wa-float" href="([^"]+)"/', $html, $m);
        $this->assertStringNotContainsString($size.':', $this->text($m[1]));

        // وما يختاره يُكتب مكانَ الخيار
        $this->assertSame(1, preg_match('/data-rb-wa-sized="([^"]+)"/', $html, $sized));
        $this->assertSame(
            "السلام عليكم، أريد الاستفسار عن المنتج: عطر العود\n{$size}: ".StoreWhatsapp::OPTION."\nhttp://localhost/s/ribbon/p/{$this->oud->id}",
            $this->text($sized[1]),
        );
        $this->assertStringContainsString('data-name="100 مل"', $html);
        $this->assertStringContainsString('wa.dataset.rbWaSized.replace("'.StoreWhatsapp::OPTION.'", encodeURIComponent(b.dataset.name))', $html);
    }

    public function test_a_product_without_sizes_has_no_size_link(): void
    {
        $this->assertStringNotContainsString('data-rb-wa-sized="', $this->get("/s/ribbon/p/{$this->oud->id}?lang=ar")->getContent());
    }

    public function test_a_search_carries_its_words(): void
    {
        $this->assertSame(
            "السلام عليكم، بحثت عن «عطر العود» وأريد المساعدة.\nhttp://localhost/s/ribbon/shop?q=".rawurlencode('عطر العود'),
            $this->asks('/shop?q='.rawurlencode('عطر العود')),
        );
        $this->assertSame("Hello, I searched for “oud” and would like some help.\nhttp://localhost/s/ribbon/shop?q=oud", $this->asks('/shop?q=oud', 'en'));
    }

    public function test_the_cart_and_checkout_ask_without_a_link(): void
    {
        $this->assertSame('السلام عليكم، أحتاج مساعدة بخصوص سلّتي في متجر RIBBON.', $this->asks('/cart'));
        $this->assertSame('Hello, I need help with my cart at RIBBON.', $this->asks('/cart', 'en'));
        $this->assertSame('السلام عليكم، أحتاج مساعدة بخصوص طلبي من متجر RIBBON.', $this->asks('/checkout'));
    }

    public function test_an_order_or_payment_page_never_carries_its_number_or_link(): void
    {
        $this->app->instance('request', Request::create('https://ribbon.om/done/4821?t=secret')); // رقمٌ مقصود: رقمُ طلبٍ في الرابط لا يُنقل
        $t = RibbonTexts::for('ar');

        foreach (['done', 'paying', 'checkout'] as $page) {
            $this->assertSame('السلام عليكم، أحتاج مساعدة بخصوص طلبي من متجر RIBBON.', StoreWhatsapp::page($t, 'RIBBON', $page, null, []), $page);
        }
    }

    public function test_the_link_stays_on_the_shops_own_domain_and_keeps_only_what_names_the_page(): void
    {
        $this->app->instance('request', Request::create('https://ribbon.om/shop?cat=3&utm_source=ig&fbclid=x&lang=en')); // رقمٌ مقصود: قسمٌ في رابط
        $t = RibbonTexts::for('ar');

        $this->assertSame(
            "السلام عليكم، أريد الاستفسار عن قسم العطور.\nhttps://ribbon.om/shop?cat=3",
            StoreWhatsapp::page($t, 'RIBBON', 'shop', ['type' => 'category', 'value' => 3], ['catName' => 'العطور']), // رقمٌ مقصود
        );
        $this->assertSame('https://ribbon.om/p/7', StoreWhatsapp::productLink('', 7)); // رقمٌ مقصود: صنفٌ على النطاق الخاصّ
    }

    public function test_a_custom_page_is_named_by_its_title(): void
    {
        $this->app->instance('request', Request::create('https://ribbon.om/page/weddings'));

        $this->assertSame(
            "السلام عليكم، أريد الاستفسار عن صفحة «حفلات الزفاف».\nhttps://ribbon.om/page/weddings",
            StoreWhatsapp::page(RibbonTexts::for('ar'), 'RIBBON', StoreCustomPages::PATH, ['type' => 'page', 'value' => 'weddings'], ['customPage' => ['title' => 'حفلات الزفاف']]),
        );
    }

    /* ═══════════════════════ بطاقةُ الصنف ═══════════════════════ */

    public function test_a_product_card_asks_about_its_product_without_opening_it(): void
    {
        $html = $this->get('/s/ribbon/shop?lang=ar')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a class="rb-card-wa" href="([^"]+)" target="_blank" rel="noopener" aria-label="استفسر عبر واتساب: عطر العود"/', $html, $m));
        $this->assertSame("السلام عليكم، أريد الاستفسار عن المنتج: عطر العود\nhttp://localhost/s/ribbon/p/{$this->oud->id}", $this->text($m[1]));

        // بجوار رابط البطاقة لا داخله — رابطٌ داخل رابطٍ لا يصحّ
        $this->assertMatchesRegularExpression('#<div class="rb-card-wrap">\s*<a href="/s/ribbon/p/\d+" class="rb-card" data-testid="rb-product">.*?</a>\s*<a class="rb-card-wa"#s', $html);

        $this->assertStringContainsString('aria-label="Ask on WhatsApp: Oud perfume"', $this->get('/s/ribbon/shop?lang=en')->getContent());
    }

    public function test_no_card_button_where_the_floating_one_is_off(): void
    {
        config(['storefront.ribbon_floating_whatsapp_businesses' => []]);
        $html = $this->get('/s/ribbon/shop?lang=ar')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="rb-product"', $html);
        $this->assertStringNotContainsString('rb-card-wa', $html);
        $this->assertStringNotContainsString('rb-card-wrap', $html);

        // ولا بلا رقم
        config(['storefront.ribbon_floating_whatsapp_businesses' => [$this->shop->id]]);
        MarketingSettings::save($this->shop->id, 'website', ['store_whatsapp' => '']);
        $this->assertStringNotContainsString('rb-card-wa', $this->get('/s/ribbon/shop?lang=ar')->getContent());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحةُ صنف RIBBON تعرض معرضَه كلَّه — والرفُّ صورةً واحدة. وخطُّ الواجهة IBM.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الرئيسيّةُ و«المتجر» يعرضان الصورةَ الرئيسيّة وحدها لكلّ صنف.
 *   - صفحةُ الصنف: الرئيسيّةُ كبيرةً أوّلًا، والإضافيّةُ مصغّراتٍ بترتيبها.
 *   - صنفٌ بصورةٍ واحدة بلا شريط مصغّرات.
 *   - صورةُ متجرٍ آخر لا تدخل معرضَ هذا، وصورةُ المشاركة الرئيسيّةُ وحدها.
 *   - شرحُ «صور المنتج» في اللوحة يقول أين تظهر كلٌّ منهما.
 *   - `IBM Plex Sans Arabic` أوّلُ الخطّ، ولا تباعدَ حروفٍ يقطع العربيّة.
 *
 * والتبديلُ بين المصغّرات في `tests/js/the-ribbon-gallery-card-note-and-cart-lines.test.ts`.
 */
class TheRibbonProductPageShowsItsWholeGalleryTest extends TestCase
{
    use RefreshDatabase;

    private const MAIN = 'https://cdn.example.test/rose-main.jpg';

    private const SIDE = 'https://cdn.example.test/rose-side.jpg';

    private const BOX = 'https://cdn.example.test/rose-box.jpg';

    private Business $shop;

    private Business $other;

    private Product $rose;

    private Product $lily;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = $this->shop('ribbon');
        $this->other = $this->shop('other');

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true, 'image' => self::MAIN,
        ]);
        // بترتيب العرض لا بترتيب الإنشاء
        ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $this->rose->id, 'path' => self::BOX, 'sort_order' => 2]);
        ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $this->rose->id, 'path' => self::SIDE, 'sort_order' => 1]);

        $this->lily = Product::create([
            'business_id' => $this->shop->id, 'name' => 'زنبق', 'price' => 12, 'cost' => 5,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
            'image' => 'https://cdn.example.test/lily.jpg',
        ]);
    }

    private function shop(string $slug): Business
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return $shop;
    }

    /* ═══════════ الرفُّ صورةً واحدة ═══════════ */

    public function test_the_home_and_shop_cards_show_the_main_photo_alone(): void
    {
        foreach (['/s/ribbon', '/s/ribbon/shop'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(self::MAIN, $html, "$url لا يعرض الرئيسيّة");
            $this->assertStringNotContainsString(self::SIDE, $html, "$url حمّل صورةً إضافيّة");
            $this->assertStringNotContainsString(self::BOX, $html, "$url حمّل صورةً إضافيّة");
            $this->assertStringNotContainsString('data-rb-thumbs', $html);
        }
    }

    /* ═══════════ صفحةُ الصنف معرضُه كلُّه ═══════════ */

    public function test_the_product_page_shows_the_main_photo_first_then_the_others_in_order(): void
    {
        $res = $this->get('/s/ribbon/p/'.$this->rose->id)->assertOk();
        $html = $res->getContent();

        $this->assertMatchesRegularExpression('/<img src="'.preg_quote(self::MAIN, '/').'"[^>]*data-rb-main/', $html, 'الكبيرةُ ليست الرئيسيّة');
        $this->assertSame(3, substr_count($html, 'data-testid="rb-thumb"'));

        preg_match_all('/<button[^>]*class="rb-thumb[^"]*" data-src="([^"]+)"/', $html, $m);
        $this->assertSame([self::MAIN, self::SIDE, self::BOX], $m[1]);
        $this->assertMatchesRegularExpression('/class="rb-thumb is-on" data-src="'.preg_quote(self::MAIN, '/').'" aria-pressed="true"/', $html);

        // وصورةُ المشاركة الرئيسيّةُ وحدها
        $this->assertMatchesRegularExpression('/<meta property="og:image" content="'.preg_quote(self::MAIN, '/').'">/', $html);
    }

    public function test_a_product_with_one_photo_has_no_thumbnail_strip(): void
    {
        $html = $this->get('/s/ribbon/p/'.$this->lily->id)->assertOk()->getContent();

        $this->assertStringContainsString('https://cdn.example.test/lily.jpg', $html);
        $this->assertStringNotContainsString('data-rb-thumbs', $html);
        $this->assertStringNotContainsString('data-testid="rb-thumb"', $html);
        $this->assertStringNotContainsString('ribbon-gallery', $html);
    }

    public function test_another_shops_photos_never_enter_this_gallery(): void
    {
        // صفٌّ في الجدول يحمل صنفَ هذا المتجر ومعرّفَ متجرٍ آخر
        ProductImage::create(['business_id' => $this->other->id, 'product_id' => $this->rose->id, 'path' => 'https://cdn.example.test/stranger.jpg', 'sort_order' => 0]);

        $theirs = Product::create([
            'business_id' => $this->other->id, 'name' => 'باقتهم', 'price' => 9, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 0, 'active' => true, 'published' => true, 'image' => 'https://cdn.example.test/their-main.jpg',
        ]);
        ProductImage::create(['business_id' => $this->other->id, 'product_id' => $theirs->id, 'path' => 'https://cdn.example.test/their-side.jpg', 'sort_order' => 1]);

        $html = $this->get('/s/ribbon/p/'.$this->rose->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('stranger.jpg', $html);
        $this->assertStringNotContainsString('their-', $html);
        $this->assertSame(3, substr_count($html, 'data-testid="rb-thumb"'));

        // وصنفُ المتجر الآخر لا يُفتح من هنا أصلًا
        $this->get('/s/ribbon/p/'.$theirs->id)->assertNotFound();
    }

    /* ═══════════ تحت زرّ السلّة ═══════════ */

    public function test_saud_shows_the_image_note_alone_under_the_cart_button_and_others_keep_both(): void
    {
        $notes = ['store_image_note' => 'قد يختلف لون الورد حسب المتوفر', 'store_delivery_note' => 'التوصيل داخل مسقط خلال اليوم نفسه'];
        MarketingSettings::save($this->shop->id, 'website', $notes);
        MarketingSettings::save($this->other->id, 'website', $notes);
        config(['storefront.ribbon_product_page_without_delivery_note_businesses' => [$this->shop->id]]);

        $theirRose = Product::create([
            'business_id' => $this->other->id, 'name' => 'باقتهم', 'price' => 9, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);

        // سعود: تنبيهُ الصورة تحت الزرّ مباشرةً، ولا ملاحظةَ توصيل بعده
        $html = $this->get('/s/ribbon/p/'.$this->lily->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-testid="rb-add">[^<]*<\/button>\s*<\/div>\s*<p class="rb-note" data-testid="rb-image-note">قد يختلف لون الورد حسب المتوفر<\/p>/u', $html);
        $this->assertStringNotContainsString('التوصيل داخل مسقط خلال اليوم نفسه', $html);
        $this->assertStringNotContainsString('rb-delivery-note', $html);

        // والمتجرُ الآخر كما كان: التنبيهُ ثمّ ملاحظةُ التوصيل
        $theirs = $this->get('/s/other/p/'.$theirRose->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/rb-image-note">قد يختلف لون الورد حسب المتوفر<\/p>\s*<div[^>]*data-testid="rb-delivery-note">التوصيل داخل مسقط خلال اليوم نفسه<\/div>/u', $theirs);

        // والإعدادُ لا يُمسّ، والإتمامُ على حاله
        $this->assertSame('التوصيل داخل مسقط خلال اليوم نفسه', MarketingSettings::group($this->shop->id, 'website')['store_delivery_note']);
        $this->get('/s/ribbon/checkout')->assertOk()->assertSee('قد يختلف لون الورد حسب المتوفر');
        $this->get('/s/ribbon')->assertOk();
        $this->get('/s/ribbon/shop')->assertOk();

        $this->assertSame([5], (require config_path('storefront.php'))['ribbon_product_page_without_delivery_note_businesses']);
    }

    /* ═══════════ شرحُ اللوحة ═══════════ */

    public function test_the_admin_gallery_says_where_each_photo_appears(): void
    {
        $this->assertStringContainsString(
            'الصورة الرئيسية تظهر في قائمة المنتجات، والصور الإضافية تظهر داخل صفحة المنتج.',
            file_get_contents(resource_path('js/Pages/Admin/Products/partials/Gallery.tsx')),
        );
    }

    /* ═══════════ الخطّ ═══════════ */

    public function test_ibm_plex_sans_arabic_leads_the_whole_theme_and_arabic_is_not_spaced_apart(): void
    {
        $layout = (string) file_get_contents(resource_path('views/store/ribbon/layout.blade.php'));
        $css = (string) preg_replace('/\{\{--.*?--\}\}|\/\*.*?\*\//s', '', $layout);

        $this->assertMatchesRegularExpression('/<link rel="stylesheet" href="[^"]*\/fonts\/ibm-plex-arabic\.css/', $layout);

        preg_match_all('/font-family:\s*([^;]+);/', $css, $stacks);
        $families = array_values(array_filter($stacks[1], fn ($s) => trim($s) !== 'inherit'));
        $this->assertNotEmpty($families);
        foreach ($families as $stack) {
            $this->assertStringStartsWith("'IBM Plex Sans Arabic'", trim($stack), 'خطٌّ يسبق IBM في الواجهة');
            $this->assertStringNotContainsString('GE Hili', $stack);
            $this->assertStringNotContainsString('Noto Kufi', $stack);
        }

        // والصفحةُ تُرسَم بالخطّ نفسِه
        $this->get('/s/ribbon')->assertOk()->assertSee('/fonts/ibm-plex-arabic.css', false);

        // شريطُ الصفحات بلا تباعدٍ في العربيّة — وما بقي منه للإنجليزيّة وحدها
        $this->assertMatchesRegularExpression('/\.rb-nav-a \{[^}]*\}/', $css);
        preg_match('/\n\s*\.rb-nav-a \{([^}]*)\}/', $css, $nav);
        $this->assertStringNotContainsString('letter-spacing', $nav[1]);

        foreach (preg_split('/\n/', $css) as $line) {
            if (str_contains($line, 'letter-spacing')) {
                $this->assertStringStartsWith('html[lang="en"]', trim($line), 'تباعدُ حروفٍ يقع على العربيّة: '.trim($line));
            }
        }

        foreach (glob(resource_path('views/store/ribbon/{,sections/}*.blade.php'), GLOB_BRACE) as $view) {
            foreach (preg_split('/\n/', (string) file_get_contents($view)) as $line) {
                if (str_contains($line, 'letter-spacing') && ! str_contains($line, '★') && ! str_contains($line, 'html[lang="en"]') && ! str_contains($line, '`letter-spacing`')) {
                    $this->fail(basename($view).' يباعد حروفًا قد تكون عربيّة: '.trim($line));
                }
            }
        }
    }
}

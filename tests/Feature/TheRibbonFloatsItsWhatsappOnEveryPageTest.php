<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * زرُّ واتساب عائمٌ في كلّ صفحات RIBBON — لمن في القائمة وحده.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لمن في `storefront.ribbon_floating_whatsapp_businesses` وحده — ومتجرٌ
 *     آخر يلبس RIBBON لا زرَّ له.
 *   - والرقمُ رقمُ «بيانات المتجر» (`store_whatsapp`) أرقامًا وحدها، كرابط
 *     التذييل. وبلا رقمٍ لا زرّ.
 *   - والرسالةُ الجاهزةُ بلغة الصفحة، مرمَّزةً في الرابط.
 *   - وهو في القالب العامّ: في كلّ صفحة، ولا يُكتب في صفحةٍ واحدة.
 *
 * والمتجرُ هنا عامٌّ يُكتب معرّفُه في الإعداد — لا يُفرض عليه المعرّف ٥.
 */
class TheRibbonFloatsItsWhatsappOnEveryPageTest extends TestCase
{
    use RefreshDatabase;

    private const AR_TEXT = 'السلام عليكم، أحتاج مساعدة بخصوص طلبي من متجر RIBBON.';

    private const EN_TEXT = 'Hello, I need help with my order from RIBBON.';

    private Business $shop;

    private Business $other;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = $this->ribbon('ribbon');
        $this->other = $this->ribbon('other');

        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ])->id;

        config(['storefront.ribbon_floating_whatsapp_businesses' => [$this->shop->id]]);
    }

    private function ribbon(string $slug): Business
    {
        $shop = Business::create([
            'name' => 'RIBBON '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'city' => 'مسقط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        // ونصُّ «من نحن» تُفتح به صفحتُها — بلاه تُردّ «غير موجود» (`StoreNav::has`)
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_whatsapp' => '+968 9111 2233', 'store_about' => 'ورودٌ وهدايا']);

        return $shop;
    }

    private function page(string $path = '', string $lang = 'ar', string $slug = 'ribbon'): string
    {
        return $this->get("/s/{$slug}{$path}?lang={$lang}")->assertOk()->getContent();
    }

    /** رابطُ الزرّ في الصفحة — أو `null` إن لم يُرسم */
    private function button(string $html): ?string
    {
        return preg_match('/<a class="rb-wa-float" href="([^"]+)"[^>]*data-testid="rb-wa-float"/', $html, $m)
            ? html_entity_decode($m[1])
            : null;
    }

    public function test_every_page_of_the_listed_shop_floats_the_button(): void
    {
        foreach (['', '/shop', '/p/'.$this->product, '/about', '/contact', '/cart', '/checkout'] as $path) {
            $html = $this->page($path);

            $this->assertNotNull($this->button($html), "لا زرَّ في «{$path}»");
            $this->assertStringContainsString('target="_blank" rel="noopener"', substr($html, strpos($html, 'class="rb-wa-float"'), 400));
            $this->assertStringContainsString('aria-label="تواصل معنا عبر واتساب"', $html);
            $this->assertStringContainsString('<svg viewBox="0 0 24 24"', substr($html, strpos($html, 'class="rb-wa-float"'), 600), 'الزرُّ شعارٌ لا كلمة');
        }
    }

    public function test_the_link_carries_the_cleaned_number_and_the_arabic_message(): void
    {
        $url = $this->button($this->page());

        $this->assertSame('https://wa.me/96891112233?text='.rawurlencode(self::AR_TEXT), $url);
        // والرقمُ نفسُه في التذييل — مصدرٌ واحد
        $this->assertStringContainsString('href="https://wa.me/96891112233"', $this->page());
    }

    public function test_the_english_page_sends_the_english_message(): void
    {
        $html = $this->page('', 'en');

        $this->assertSame('https://wa.me/96891112233?text='.rawurlencode(self::EN_TEXT), $this->button($html));
        $this->assertStringContainsString('aria-label="Chat with us on WhatsApp"', $html);
        $this->assertStringNotContainsString(rawurlencode(self::AR_TEXT), $html);
    }

    public function test_no_number_draws_no_button(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_whatsapp' => '']);

        $html = $this->page();
        $this->assertNull($this->button($html));
        $this->assertStringNotContainsString('rb-wa-float', $html, 'ولا قاعدةُ CSS له');
    }

    public function test_another_ribbon_shop_has_no_button(): void
    {
        $html = $this->page('', 'ar', 'other');

        $this->assertNull($this->button($html));
        $this->assertStringNotContainsString('rb-wa-float', $html);
        // وتذييلُه على حاله
        $this->assertStringContainsString('href="https://wa.me/96891112233"', $html);
    }

    public function test_the_button_lives_in_the_shared_layout_alone(): void
    {
        foreach (glob(resource_path('views/store/ribbon/*.blade.php')) as $file) {
            $count = substr_count(file_get_contents($file), 'data-testid="rb-wa-float"');
            $this->assertSame(basename($file) === 'layout.blade.php' ? 1 : 0, $count, basename($file));
        }

        $config = require config_path('storefront.php');
        $this->assertSame([5], $config['ribbon_floating_whatsapp_businesses']);
    }
}

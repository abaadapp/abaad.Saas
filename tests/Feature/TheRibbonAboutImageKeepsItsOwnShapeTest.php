<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Setting;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صورةُ «من نحن» في RIBBON بنسبة ملفّها — لا تُكبَّر في ٤:٣ ولا تُقصّ.
 *
 * ═══ ما كان ═══
 *
 * إطارٌ بـ`aspect-ratio:4/3` وصورةٌ بـ`object-fit:cover`: صورةٌ طويلةٌ أو
 * عريضةٌ تُكبَّر حتّى تملأه ويُقطع طرفاها — في صفحة «من نحن» وفي قسمها من
 * الرئيسيّة (وهو يعرض الشعار).
 *
 * ═══ ما يُحرس ═══
 *
 *   - الصورةُ `width:100%;height:auto` — بنسبتها، ولا تتجاوز عمودها.
 *   - ولا `aspect-ratio:4/3` ولا `object-fit:cover` في موضعها.
 *   - والشعارُ بدلُها حين لا صورة — بالقاعدة نفسِها.
 */
class TheRibbonAboutImageKeepsItsOwnShapeTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

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
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_about' => 'محلُّ وردٍ في مسقط']);
    }

    /** الوسمُ الذي يحمل `data-testid` — وإطارُه الذي قبله */
    private function tagAndFrame(string $html, string $testid): array
    {
        $this->assertSame(1, preg_match('/<div([^>]*)>\s*<img([^>]*data-testid="'.$testid.'"[^>]*)>/', $html, $m), 'لا صورةَ بـ'.$testid);

        return [$m[2], $m[1]];
    }

    private function assertWhole(string $img, string $frame): void
    {
        $this->assertStringContainsString('width:100%;height:auto;display:block', $img);

        foreach ([$img, $frame] as $part) {
            $this->assertStringNotContainsString('aspect-ratio:4/3', $part, 'الصورةُ في إطار ٤:٣ — تُكبَّر وتُقصّ');
            $this->assertStringNotContainsString('object-fit:cover', $part);
        }

        $this->assertStringContainsString('border-radius:var(--rb-r-lg)', $frame, 'الأركانُ المدوّرة باقية');
    }

    public function test_the_about_page_shows_its_image_at_its_own_ratio(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_about_image' => '/storage/about-tall.jpg']);
        $this->shop->update(['logo' => 'logos/logo.png']);

        [$img, $frame] = $this->tagAndFrame($this->get('/s/ribbon/about')->assertOk()->getContent(), 'rb-about-image');

        $this->assertStringContainsString('src="/storage/about-tall.jpg"', $img, 'الصورةُ المرفوعة تسبق الشعار');
        $this->assertWhole($img, $frame);
    }

    public function test_without_an_about_image_the_logo_stands_in_whole(): void
    {
        $this->shop->update(['logo' => 'logos/logo.png']);

        [$img, $frame] = $this->tagAndFrame($this->get('/s/ribbon/about')->assertOk()->getContent(), 'rb-about-image');

        $this->assertStringContainsString('src="/storage/logos/logo.png"', $img);
        $this->assertWhole($img, $frame);
    }

    public function test_without_either_no_empty_frame_is_drawn_on_the_about_page(): void
    {
        $this->assertStringNotContainsString('rb-about-image', $this->get('/s/ribbon/about')->assertOk()->getContent());
    }

    public function test_the_home_about_section_shows_the_logo_whole(): void
    {
        $this->shop->update(['logo' => 'logos/logo.png']);

        $html = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-sec-about"', $html);

        [$img, $frame] = $this->tagAndFrame($html, 'rb-sec-about-image');

        $this->assertStringContainsString('src="/storage/logos/logo.png"', $img);
        $this->assertWhole($img, $frame);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\CategoryName;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تذييلُ RIBBON بلغة صفحته، وكلُّ وسيلة تواصلٍ فيه تُضغط فتفتح وجهتَها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - سطرُ التذييل وساعاتُ العمل من حقل لغة الصفحة — وفارغُها لا يقع على الأخرى.
 *   - الهاتفُ `tel:`، والبريدُ `mailto:`، وواتساب `https://wa.me/{أرقام}`،
 *     وإنستغرام رابطُ الحساب — في تبويبٍ جديد.
 *   - فئةُ «الاضافات» بلا همزة تُقرأ Add-ons في الصفحة الإنجليزيّة، و`name_en`
 *     العربيّ لا يُعرض فيها متى عرف المعجمُ الاسم.
 */
class TheRibbonFooterSpeaksEachLanguageAndEveryContactOpensTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '+968 9525 9066',
            'email' => 'hello@ribbon.om', 'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold',
            'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1',
            'store_whatsapp' => '+968 9111 2233', 'store_instagram' => '@ribbon.om',
            'store_tagline' => 'ورودٌ · هدايا', 'store_tagline_en' => 'Flowers · Gifts',
            'store_hours' => 'يوميًا 10 ص – 10 م', 'store_hours_en' => 'Daily 10 AM – 10 PM',
        ]);

        $addons = Category::create(['business_id' => $this->shop->id, 'name' => 'الاضافات']);
        $copied = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد', 'name_en' => 'ورد']);
        foreach ([$addons, $copied] as $i => $c) {
            Product::create([
                'business_id' => $this->shop->id, 'category_id' => $c->id, 'name' => 'صنف '.$i, 'price' => 5,
                'cost' => 1, 'quantity' => 5, 'alert_qty' => 0, 'active' => true, 'published' => true,
            ]);
        }
    }

    private function page(string $lang, string $path = ''): string
    {
        return $this->get('/s/ribbon'.$path.($lang === 'en' ? '?lang=en' : ''))->assertOk()->getContent();
    }

    /* ═══════════ لغةُ السطر والساعات ═══════════ */

    public function test_each_language_reads_its_own_tagline_and_hours(): void
    {
        $ar = $this->page('ar');
        $this->assertStringContainsString('data-testid="rb-tagline">ورودٌ · هدايا<', $ar);
        $this->assertStringContainsString('يوميًا 10 ص – 10 م', $ar);
        $this->assertStringNotContainsString('Flowers · Gifts', $ar);
        $this->assertStringNotContainsString('Daily 10 AM', $ar);

        $en = $this->page('en');
        $this->assertStringContainsString('data-testid="rb-tagline">Flowers · Gifts<', $en);
        $this->assertStringContainsString('Daily 10 AM – 10 PM', $en);
        $this->assertStringNotContainsString('ورودٌ · هدايا', $en);
        $this->assertStringNotContainsString('يوميًا 10 ص', $en);

        // وصفحةُ «تواصل معنا» من الموضع نفسِه
        $this->assertStringContainsString('Daily 10 AM – 10 PM', $this->page('en', '/contact'));
        $this->assertStringContainsString('يوميًا 10 ص – 10 م', $this->page('ar', '/contact'));
    }

    public function test_an_empty_english_line_shows_nothing_rather_than_arabic(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_tagline_en' => '', 'store_hours_en' => '']);

        $en = $this->page('en');
        $this->assertStringNotContainsString('rb-tagline', $en);
        $this->assertStringNotContainsString('ورودٌ · هدايا', $en);
        $this->assertStringNotContainsString('يوميًا 10 ص', $en);
        $this->assertStringNotContainsString('rb-c-hours', $this->page('en', '/contact'));

        // والعربيّةُ على حالها
        $this->assertStringContainsString('ورودٌ · هدايا', $this->page('ar'));
    }

    /* ═══════════ كلُّ وسيلةٍ تُضغط ═══════════ */

    public function test_every_contact_in_the_footer_opens_where_it_points(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $html = $this->page($lang);

            $this->assertMatchesRegularExpression('/<a href="tel:\+96895259066" data-testid="rb-foot-phone"><bdi dir="ltr">\+968 9525 9066<\/bdi><\/a>/', $html);
            $this->assertMatchesRegularExpression('/<a href="mailto:hello@ribbon\.om" data-testid="rb-foot-email"><bdi dir="ltr">hello@ribbon\.om<\/bdi><\/a>/', $html);
            $this->assertMatchesRegularExpression('/<a href="https:\/\/wa\.me\/96891112233" target="_blank" rel="noopener" data-testid="rb-foot-whatsapp">WhatsApp<\/a>/', $html);
            $this->assertMatchesRegularExpression('/<a href="https:\/\/wa\.me\/96891112233" target="_blank" rel="noopener" data-testid="rb-foot-whatsapp-line">/', $html);
            $this->assertMatchesRegularExpression('/<a href="https:\/\/instagram\.com\/ribbon\.om" target="_blank" rel="noopener" data-testid="rb-foot-instagram">Instagram<\/a>/', $html);
        }

        // والعنوانُ نفسُه في صفحة «تواصل معنا» — طريقةٌ واحدة لا اثنتان
        $this->assertStringContainsString('href="https://wa.me/96891112233"', $this->page('ar', '/contact'));
    }

    public function test_a_missing_whatsapp_or_instagram_draws_no_link(): void
    {
        $this->shop->update(['phone' => null]);
        MarketingSettings::save($this->shop->id, 'website', ['store_whatsapp' => '', 'store_instagram' => '']);

        $html = $this->page('ar');
        foreach (['rb-foot-whatsapp', 'rb-foot-instagram', 'rb-foot-phone', 'wa.me/', 'instagram.com/'] as $mark) {
            $this->assertStringNotContainsString($mark, $html);
        }
    }

    /* ═══════════ أسماءُ الفئات بالإنجليزيّة ═══════════ */

    public function test_english_category_names_never_fall_back_to_arabic_when_the_lexicon_knows_them(): void
    {
        $this->assertSame('Add-ons', CategoryName::display('الاضافات', null, 'en'));
        $this->assertSame('Roses', CategoryName::display('ورد', 'ورد', 'en'), 'اسمٌ إنجليزيٌّ كلُّه عربيّ عُرض في الصفحة الإنجليزيّة');
        $this->assertSame('Extras', CategoryName::display('الاضافات', 'Extras', 'en'), 'ما كتبه التاجرُ بالإنجليزيّة لم يُحترم');
        $this->assertSame('الاضافات', CategoryName::display('الاضافات', 'Extras', 'ar'));

        $en = $this->page('en', '/shop');
        $this->assertStringContainsString('Add-ons', $en);
        $this->assertStringNotContainsString('>الاضافات<', $en);
    }
}

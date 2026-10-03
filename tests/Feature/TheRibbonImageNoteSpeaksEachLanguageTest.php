<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تنبيهُ الصورة في RIBBON بلغة صفحته — العربيُّ للعربيّة والإنجليزيُّ للإنجليزيّة.
 *
 * ولا يقع أحدُهما على الآخر: فارغُ اللغة لا يُرسم. وحفظُ أحدهما لا يمحو الآخر.
 * والميزةُ لـRIBBON وحده — الواجهةُ العامّة لا تقرأ التنبيه أصلًا.
 */
class TheRibbonImageNoteSpeaksEachLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const AR = 'كل باقة تُنسَّق يدويًا من ورد اليوم.';

    private const EN = 'Each bouquet is handcrafted using the freshest flowers available.';

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_image_note' => self::AR, 'store_image_note_en' => self::EN,
        ]);
        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function note(string $lang): ?string
    {
        $html = $this->get('/s/ribbon/p/'.$this->rose->id.($lang === 'en' ? '?lang=en' : ''))->assertOk()->getContent();

        return preg_match('#data-testid="rb-image-note">([^<]*)</p>#u', $html, $m) ? $m[1] : null;
    }

    public function test_each_language_reads_its_own_notice_and_never_the_other(): void
    {
        $this->assertSame(self::AR, $this->note('ar'));
        $this->assertSame(self::EN, $this->note('en'));

        $this->assertStringNotContainsString(self::EN, $this->get('/s/ribbon/checkout')->getContent());
        $this->assertStringNotContainsString(self::AR, $this->get('/s/ribbon/checkout?lang=en')->getContent());
    }

    public function test_an_empty_language_draws_no_notice(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_image_note_en' => '']);
        $this->assertNull($this->note('en'), 'التنبيهُ العربيّ وقع على الصفحة الإنجليزيّة');
        $this->assertSame(self::AR, $this->note('ar'));

        MarketingSettings::save($this->shop->id, 'website', ['store_image_note' => '', 'store_image_note_en' => self::EN]);
        $this->assertNull($this->note('ar'), 'التنبيهُ الإنجليزيّ وقع على الصفحة العربيّة');
        $this->assertSame(self::EN, $this->note('en'));
    }

    public function test_saving_one_language_leaves_the_other_alone(): void
    {
        $owner = User::where('business_id', $this->shop->id)->firstOrFail();

        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_image_note_en' => 'New English notice'])
            ->assertSessionHasNoErrors();
        $site = MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame([self::AR, 'New English notice'], [$site['store_image_note'], $site['store_image_note_en']]);

        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_image_note' => 'تنبيه جديد'])
            ->assertSessionHasNoErrors();
        $site = MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame(['تنبيه جديد', 'New English notice'], [$site['store_image_note'], $site['store_image_note_en']]);
    }

    public function test_the_notice_belongs_to_ribbon_alone(): void
    {
        // الواجهةُ العامّة لا تقرأ تنبيهَ الصورة — فلا يُفرض عليها شيءٌ هنا
        $generic = (string) file_get_contents(resource_path('views/store/show.blade.php'));
        $this->assertStringNotContainsString('image_note', $generic);
        $this->assertStringNotContainsString('imageNote', $generic);
    }
}

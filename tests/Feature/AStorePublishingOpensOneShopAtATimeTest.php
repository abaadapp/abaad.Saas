<?php
namespace Tests\Feature;
use App\Models\{Branch,Business,Category,Currency,Product,Setting,StoreSite,WebsiteVersion};
use App\Support\{Ledger,MarketingSettings};
use App\Support\Store\{StoreContent,ThemePublisher};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نظامُ النشر يُفتح متجرًا متجرًا — ويُغلق بلا أثر.
 *
 * ═══ ولمَ أمرٌ لا هجرة ═══
 *
 * الهجرةُ تنزل على القاعدة كلِّها دفعةً واحدة، وهذه تُبدّل **مصدرَ ما
 * يُحرَّر** في متاجرَ تبيع. فيُفتح لواحدٍ، ويُنظر، ثمّ لمن بعده — والتراجعُ
 * حذفُ صفٍّ لا ترحيلٌ عكسيّ.
 *
 * وأثقلُ ما هنا: الصفحةُ قبل الأمر وبعده حرفًا بحرف.
 */
class AStorePublishingOpensOneShopAtATimeTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $slug, ?string $theme = 'ribbon'): Business
    {
        $b = Business::create(['name'=>'متجر '.$slug,'type'=>'محل ورد','status'=>'نشط','phone'=>'968',
            'city'=>'مسقط','site_slug'=>$slug,'tier'=>'gold','storefront_theme'=>$theme]);
        Currency::create(['business_id'=>$b->id,'code'=>'OMR','name'=>'ريال','symbol'=>'ر.ع','rate'=>1,'is_base'=>true,'active'=>true]);
        Ledger::seedChart($b->id);
        Branch::create(['business_id'=>$b->id,'name'=>'الخوير']);
        Setting::create(['business_id'=>$b->id,'key'=>'vat_enabled','value'=>'0']);
        MarketingSettings::save($b->id,'website',['store_on'=>'1','store_headline'=>'قبل الترحيل','store_delivery_fee'=>'3']);
        $c = Category::create(['business_id'=>$b->id,'name'=>'باقات']);
        Product::create(['business_id'=>$b->id,'name'=>'باقة','price'=>12,'category_id'=>$c->id,'cost'=>4,'quantity'=>9,'active'=>true,'published'=>true]);
        return $b;
    }

    public function test_the_command_opens_and_undoes(): void
    {
        $b = $this->shop('ribbon');
        $before = (string) $this->get('/s/ribbon')->getContent();

        $this->artisan('store:publishing', ['business'=>$b->id])
            ->expectsOutputToContain('فُتح النظام')->assertSuccessful();

        $this->assertTrue(StoreContent::usesDrafts((int)$b->id));
        $this->assertSame([], StoreContent::changed((int)$b->id));
        $this->assertSame(1, WebsiteVersion::where('business_id',$b->id)->count());

        $strip = fn(string $h) => (string) preg_replace('~content="[A-Za-z0-9]{40}"~','content="T"',$h);
        $this->assertSame($strip($before), $strip((string) $this->get('/s/ribbon')->getContent()),
            'تبدّلت الصفحةُ بأمر الترحيل');

        // ويُعاد بلا ضرر
        $this->artisan('store:publishing', ['business'=>$b->id])
            ->expectsOutputToContain('مفتوحٌ أصلًا')->assertSuccessful();
        $this->assertSame(1, WebsiteVersion::where('business_id',$b->id)->count());

        // مسوّدةٌ لم تُنشر ثمّ تراجع
        ThemePublisher::saveDraft((int)$b->id, ['store_headline'=>'مسوّدة'], null);
        $this->artisan('store:publishing', ['business'=>$b->id,'--undo'=>true])
            ->expectsOutputToContain('أُغلق النظام')->assertSuccessful();

        $this->assertFalse(StoreContent::usesDrafts((int)$b->id));
        $this->assertSame(1, WebsiteVersion::where('business_id',$b->id)->count(), 'التراجعُ حذف نشراتٍ من التاريخ');
        $this->assertStringContainsString('قبل الترحيل', (string) $this->get('/s/ribbon')->getContent());

        // وبعد التراجع يعود الحفظُ مباشرًا
        MarketingSettings::save((int)$b->id,'website',['store_headline'=>'بعد التراجع']);
        $this->assertStringContainsString('بعد التراجع', (string) $this->get('/s/ribbon')->getContent());
    }

    /**
     * وقاعدةُ «أوقع الترحيلُ نظيفًا؟» تُسأل وحدَها.
     *
     * والشرطُ في `handle` لا يُختبَر: الفرعُ لا يقع إلّا على حالٍ مستحيلة
     * لا تُصطنع من خارج الأمر بلا خيطٍ مفتعَل. فتُحرَس **القاعدة**،
     * ويُصرَّح بأنّ وصلَها في الأمر بلا حارس.
     */
    public function test_the_migration_check_catches_a_changed_live_key(): void
    {
        $b = $this->shop('ribbon');
        $before = StoreContent::live((int) $b->id);

        ThemePublisher::enable($b, null);
        $this->assertTrue(ThemePublisher::migratedCleanly((int) $b->id, $before));

        // مفتاحٌ حيٌّ تبدّل → الترحيلُ ليس نظيفًا
        $this->assertFalse(ThemePublisher::migratedCleanly((int) $b->id,
            array_merge($before, ['store_headline' => 'غيرُ ما كان'])));

        // ومسوّدةٌ تفارق المنشورَ لحظةَ الفتح → كذلك
        ThemePublisher::saveDraft((int) $b->id, ['store_headline' => 'مسوّدة'], null);
        $this->assertFalse(ThemePublisher::migratedCleanly((int) $b->id, $before));
    }

    public function test_the_command_refuses_a_builder_business(): void
    {
        $b = $this->shop('builder', null);
        $this->artisan('store:publishing', ['business'=>$b->id])->assertFailed();
        $this->assertSame(0, StoreSite::count());
    }

    public function test_the_command_refuses_an_unknown_business(): void
    {
        $this->artisan('store:publishing', ['business'=>999999])->assertFailed();
    }
}

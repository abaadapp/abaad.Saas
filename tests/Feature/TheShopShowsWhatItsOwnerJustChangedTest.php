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
use App\Support\Store\StoreAsset;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ما يغيّره صاحبُ المتجر يراه في موقعه — الآن، لا بعد حين.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * أوّلُ ما يفعله من غيّر شيئًا أن يفتح موقعه ويتأكّد. فإن رأى القديمَ ظنّ
 * أنّ حفظَه لم يقع — فيحفظ ثانيةً، أو يبلّغ عن عطبٍ لا وجود له. وزبونُه
 * مثلُه: يقرأ ثمنًا رُفع أو صنفًا نفد.
 *
 * وبابان كانا يفترقان: الواجهةُ الخاصّة تُخدَم بلا خزن منذ كُتبت، وصفحةُ
 * المتجر البسيطة تأذن بخزنها دقيقتين. فصارا واحدًا.
 *
 * والأصولُ الساكنة شأنٌ آخر: يخدمها nginx بعنوانٍ ثابت بلا ترويسة خزن،
 * فيُعمِل المتصفّحُ قاعدةَ التخمين ويحتفظ بها أيّامًا. وهو نافعٌ ما لم
 * يتغيّر الملفّ — فإذا بُدِّل الشعارُ بقي العائدُ يرى القديم. فبصمةٌ في
 * العنوان تتغيّر بتغيّره.
 */
class TheShopShowsWhatItsOwnerJustChangedTest extends TestCase
{
    use RefreshDatabase;

    private Business $themed;

    private Business $plain;

    /** والثالثُ موقعٌ بُني بالمحرّر ونُشر — طريقٌ ثالثٌ له ترويستُه */
    private Business $built;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');

        $this->themed = $this->shop('RIBBON', 'ribbon', theme: true);
        $this->plain = $this->shop('متجر بسيط', 'basic', theme: false);

        /*
         * والموقعُ المبنيّ طريقٌ ثالث — ويُخدَم من دالّةٍ أخرى لها ترويستُها.
         *
         * وهو ما كشفته الطفرة: حارسٌ يفحص طريقين ويترك الثالث يُمرِّر
         * «أصلحتُ» على نصف علاج. ونصفُ العطب أسوأ من كلِّه — يُقرأ تفاوتًا
         * لا عطبًا فيُنسب إلى الشبكة.
         */
        $this->built = $this->shop('موقع مبنيّ', 'builtsite', theme: false);
        $site = Builder::create(
            $this->built,
            Blueprints::STORE,
            'modern',
            User::where('business_id', $this->built->id)->value('id'),
        );
        Publisher::publish($site, (int) User::where('business_id', $this->built->id)->value('id'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(string $name, string $slug, bool $theme): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug,
        ] + ($theme ? ['tier' => 'gold', 'storefront_theme' => 'ribbon'] : []));

        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($b->id);
        Branch::create(['business_id' => $b->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $b->id, 'name' => 'صاحبه', 'email' => $slug.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($b->id, 'website', ['store_on' => '1']);

        Product::create(['business_id' => $b->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8, 'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true]);

        return $b;
    }

    /* ═══════════ الصفحةُ لا تُخزَّن — البابان معًا ═══════════ */

    /**
     * ولا يُترك بابٌ يأذن بخزن صفحته.
     *
     * والفحصُ على البابين معًا لا على أحدهما: كانا يفترقان، ومن أصلح واحدًا
     * ونسي أخاه أعاد العطبَ نصفَه — ونصفُ العطب أسوأ، لأنّه يُقرأ تفاوتًا
     * لا عطبًا فيُنسب إلى الشبكة.
     */
    public function test_neither_door_lets_the_shop_page_be_stored(): void
    {
        foreach (['ribbon' => $this->themed, 'basic' => $this->plain, 'builtsite' => $this->built] as $slug => $_) {
            $header = (string) $this->get('/s/'.$slug)->assertOk()
                ->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $header, "المتجر /s/{$slug}");
            // و«عامّة» لا تُقال لصفحةٍ تخصّ متجرًا بعينه
            $this->assertStringNotContainsString('public', $header, "المتجر /s/{$slug}");
            $this->assertStringNotContainsString('max-age', $header, "المتجر /s/{$slug}");
        }
    }

    /** وثمنٌ يُغيَّر يُقرأ في الفتحة التالية — لا بعد دقيقتين */
    public function test_a_new_price_is_read_on_the_very_next_open(): void
    {
        $this->get('/s/basic')->assertOk()->assertSee('20');

        Product::where('business_id', $this->plain->id)->update(['price' => 33]);

        $this->get('/s/basic')->assertOk()->assertSee('33');
    }

    /** والواجهةُ الخاصّة كذلك — وهي التي يبيع منها المتجر الذهبيّ */
    public function test_the_themed_shop_reads_its_owners_change_at_once(): void
    {
        $this->get('/s/ribbon')->assertOk()->assertSee('باقة ورد');

        Product::where('business_id', $this->themed->id)->update(['name' => 'باقة تيوليب']);

        $this->get('/s/ribbon')->assertOk()->assertSee('باقة تيوليب')->assertDontSee('باقة ورد');
    }

    /* ═══════════ الأصولُ الساكنة — بصمةٌ تتغيّر بتغيّرها ═══════════ */

    /**
     * الشعارُ والأيقونةُ وورقةُ الخطّ تخرج ببصمة.
     *
     * وهي ما يجعل تبديلَ الشعار يبلغ من زار أمس: العنوانُ يتغيّر فيُطلب من
     * جديد. وبلا بصمةٍ يبقى العنوانُ هو هو، ولا شيء يقول للمتصفّح إنّ خلفه
     * ملفًّا آخر.
     */
    public function test_the_brand_files_carry_a_stamp_that_moves_with_them(): void
    {
        $html = $this->get('/s/ribbon')->assertOk()->getContent();

        foreach ([
            '/brand/ribbon/logo-cream.svg',
            '/brand/ribbon/favicon.svg',
            '/brand/ribbon/apple-touch-icon.png',
            '/fonts/ibm-plex-arabic.css',
        ] as $asset) {
            $this->assertMatchesRegularExpression(
                '#'.preg_quote($asset, '#').'\?v=[0-9a-f]{8}#',
                $html,
                'بلا بصمة: '.$asset,
            );
            // ولا عنوانٌ عارٍ بقي بجوار المبصوم
            $this->assertStringNotContainsString('"'.$asset.'"', $html, 'عنوانٌ عارٍ: '.$asset);
        }
    }

    /** والبصمةُ تتبدّل حين يتبدّل الملفّ — وإلّا فهي زينةٌ لا تُحدِّث شيئًا */
    public function test_the_stamp_changes_when_the_file_changes(): void
    {
        $file = public_path('brand/ribbon/logo-cream.svg');
        $before = StoreAsset::url('/brand/ribbon/logo-cream.svg');

        $this->assertStringContainsString('?v=', $before);

        // نلمس زمنَ الملفّ كما تفعل نشرةٌ تُبدّله، ثمّ نردّه كما كان
        $was = filemtime($file);
        try {
            touch($file, $was + 3600);
            clearstatcache(true, $file);

            $rerun = $this->refreshedStamp('/brand/ribbon/logo-cream.svg');
            $this->assertNotSame($before, $rerun, 'البصمةُ لم تتحرّك بتحرّك الملفّ');
        } finally {
            touch($file, $was);
            clearstatcache(true, $file);
        }
    }

    /** وما لا ملفَّ له عندنا يخرج كما هو — لا ببصمةٍ كاذبة */
    public function test_a_path_we_do_not_serve_goes_out_bare(): void
    {
        $this->assertSame('/brand/ribbon/nothing-here.svg', $this->refreshedStamp('/brand/ribbon/nothing-here.svg'));
    }

    /**
     * والبصمةُ محفوظةٌ في الطلب — فتُقرأ مرّةً واحدة.
     *
     * والحفظُ نفسُه يُبطل قياسَ التبدّل، فيُفرَّغ هنا بانعكاسٍ لا بمقبضٍ
     * يُفتح في الإنتاج لأجل اختبار.
     */
    private function refreshedStamp(string $path): string
    {
        $p = new \ReflectionProperty(StoreAsset::class, 'stamps');
        $p->setAccessible(true);
        $p->setValue(null, []);

        return StoreAsset::url($path);
    }
}

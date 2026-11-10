<?php

namespace Tests\Feature;

use App\Http\Middleware\StoreLivesOnItsSubdomain;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Website\Domains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * متجرٌ يخدم الخادمُ نطاقَه الفرعيّ يُزار عليه — و`/s/اسمه` يحوّل إليه.
 *
 * قرارُ المالك (2026-11-10): RIBBON على `ribbon.abaadapp.om` وحده، والرابطُ
 * القديم `app.abaadapp.om/s/ribbon` يتحوّل إليه: مؤقّتًا (302) أوّلًا، ثمّ
 * دائمًا (301) بإيداعٍ بعد أن يثبت النقل (`storefront.subdomain_redirect_status`).
 *
 * ═══ ما يُحرَس ═══
 *
 *   - الرابطُ القديم بـGET وHEAD → 302 إلى النطاق بمساره ومعلماته حرفًا، وعلى
 *     النطاق نفسِه بابُ `/s/…` يحوّل إلى جذره — فلا نسختان ولا حلقة.
 *   - POST (إتمامُ الطلب، وسؤالُ السعر، وبطاقةُ الهدية) لا يُحوَّل.
 *   - `canonical` و`hreflang` وخريطةُ الموقع وعودةُ Paymob على النطاق، والإشعارُ على النظام.
 *   - ومتجرٌ ليس في القائمة كما كان حرفًا، والقائمةُ الفارغة تُطفئ كلَّ هذا.
 */
class AStoreWithItsOwnSubdomainIsVisitedThereTest extends TestCase
{
    use RefreshDatabase;

    private Business $ribbon;

    private Business $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        // قوائمُ متجر سعود تُفرَّغ: المعرّفُ ٥ قد يقع على أحد متجرَي الاختبار
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false]);

        // معرّفاتٌ صريحة: تسلسلُ PostgreSQL لا يرجع بين الاختبارات فيقع أحدُها على ٥
        $this->ribbon = $this->shop('RIBBON', 'ribbon', 'ribbon@shop.test', id: 301);
        $this->other = $this->shop('BLOOM', 'bloom', 'bloom@shop.test', id: 302);

        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'ribbon']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(string $name, string $slug, string $email, int $id): Business
    {
        $shop = (new Business)->forceFill([
            'id' => $id, 'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000000',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        $shop->save();
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_about' => 'نبذةٌ تجريبيّة']);
        Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $shop;
    }

    /** لا تحويلَ بأيّ نوع — لا 301 ولا 302 ولا أخواتهما */
    private function assertNotRedirected(TestResponse $res, string $what): void
    {
        $this->assertNotContains($res->getStatusCode(), [301, 302, 303, 307, 308], $what);
    }

    private function product(Business $shop): int
    {
        return (int) Product::where('business_id', $shop->id)->value('id');
    }

    private function order(Business $shop, string $pay = 'cod'): array
    {
        return [
            'items' => [['id' => $this->product($shop), 'qty' => 1]],
            'name' => 'زبون تجربة', 'phone' => '95259066', 'fulfil' => 'pickup',
            'date' => '2027-02-12', 'pay' => $pay,
        ];
    }

    public function test_the_old_address_moves_with_its_path_and_query_as_sent(): void
    {
        $p = $this->product($this->ribbon);

        foreach ([
            '/s/ribbon' => '/',
            '/s/ribbon/shop' => '/shop',
            '/s/ribbon/shop?lang=en&cat=3' => '/shop?lang=en&cat=3',
            "/s/ribbon/p/{$p}?lang=en" => "/p/{$p}?lang=en",
            '/s/ribbon/cart' => '/cart',
            '/s/ribbon/checkout?lang=en' => '/checkout?lang=en',
            '/s/ribbon/about' => '/about',
            '/s/ribbon/done/12?t=abc' => '/done/12?t=abc',
            '/s/ribbon/paying/web-abc123' => '/paying/web-abc123',
            '/s/ribbon/sitemap.xml' => '/sitemap.xml',
            '/s/ribbon/sitemap-2.xml' => '/sitemap-2.xml',
            '/s/ribbon/page/'.rawurlencode('خدماتنا') => '/page/'.rawurlencode('خدماتنا'),
        ] as $old => $new) {
            $this->get('https://app.abaadapp.om'.$old)
                ->assertStatus(302)
                ->assertHeader('Location', 'https://ribbon.abaadapp.om'.$new);
        }

        $this->call('HEAD', 'https://app.abaadapp.om/s/ribbon/shop?lang=en')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://ribbon.abaadapp.om/shop?lang=en');
    }

    public function test_the_new_address_serves_and_its_own_old_door_leads_to_its_root_without_a_loop(): void
    {
        $p = $this->product($this->ribbon);

        foreach (['/', '/shop', "/p/{$p}", '/cart', '/checkout', '/about', '/sitemap.xml'] as $path) {
            $this->assertSame(200, $this->get('https://ribbon.abaadapp.om'.$path)->getStatusCode(), $path);
        }

        // والبابُ البديل على النطاق نفسِه نسخةٌ ثانية — يُحوَّل إلى جذره مرّةً ويقف
        $this->get('https://ribbon.abaadapp.om/s/ribbon/shop?lang=en')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://ribbon.abaadapp.om/shop?lang=en');
        $this->get('https://ribbon.abaadapp.om/shop?lang=en')->assertOk();
    }

    public function test_canonical_hreflang_and_links_name_the_subdomain(): void
    {
        $p = $this->product($this->ribbon);

        $home = (string) $this->get('https://ribbon.abaadapp.om/')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://ribbon.abaadapp.om"', $home);

        $page = (string) $this->get("https://ribbon.abaadapp.om/p/{$p}")->assertOk()->getContent();
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"https://ribbon.abaadapp.om/p/{$p}\"", $page);
        $this->assertStringContainsString("hreflang=\"en\" href=\"https://ribbon.abaadapp.om/p/{$p}?lang=en\"", $page);

        // ولا رابطَ يعود إلى البابين القديمين
        foreach ([$home, $page] as $html) {
            $this->assertStringNotContainsString('/s/ribbon', $html);
            $this->assertStringNotContainsString('app.abaadapp.om/s/', $html);
        }
    }

    public function test_the_sitemap_lists_the_subdomain_only(): void
    {
        $xml = (string) $this->get('https://ribbon.abaadapp.om/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $loc) {
            $this->assertStringStartsWith('https://ribbon.abaadapp.om', $loc);
            $this->assertStringNotContainsString('/s/ribbon', $loc);
        }
    }

    public function test_posts_are_never_redirected_and_an_order_from_an_old_page_still_lands(): void
    {
        // إتمامُ طلبٍ من صفحةٍ فُتحت على الرابط القديم: يُخدم حيث أُرسل
        $res = $this->postJson('https://app.abaadapp.om/s/ribbon/checkout', $this->order($this->ribbon))->assertOk();
        $done = (string) $res->json('redirect');
        $this->assertStringStartsWith('/s/ribbon/done/', $done);

        // وصفحةُ التأكيد التي يُرسَل إليها تتحوّل بمرجعها وتُفتح على النطاق
        $to = (string) $this->get('https://app.abaadapp.om'.$done)->assertStatus(302)->headers->get('Location');
        $this->assertSame('https://ribbon.abaadapp.om'.substr($done, strlen('/s/ribbon')), $to);
        $this->get($to)->assertOk();

        // وعلى النطاق نفسِه يُرسَل إلى جذره
        $res = $this->postJson('https://ribbon.abaadapp.om/checkout', $this->order($this->ribbon))->assertOk();
        $this->assertStringStartsWith('/done/', (string) $res->json('redirect'));

        foreach (['/s/ribbon/quote', '/s/ribbon/gift-card', '/s/ribbon/checkout'] as $path) {
            $this->assertNotRedirected($this->postJson('https://app.abaadapp.om'.$path, []), $path);
        }
    }

    public function test_the_door_itself_lets_a_post_through_even_if_a_post_route_joins_it(): void
    {
        // حارسٌ ثانٍ: المساراتُ في مجموعته GET وحدها اليوم — ومن أدخل إليها POST غدًا لا يُحوِّل طلبًا
        Route::post('/s/{slug}/probe', fn () => response('served', 200))
            ->middleware(StoreLivesOnItsSubdomain::class);

        $this->post('https://app.abaadapp.om/s/ribbon/probe')->assertOk()->assertSee('served');
    }

    public function test_paymob_returns_the_customer_to_the_subdomain_and_notifies_the_system(): void
    {
        PaymentGateway::create([
            'business_id' => $this->ribbon->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'public-x', 'secret_key' => 'secret-x', 'hmac_secret' => 'hmac-x',
            'card_integration_id' => '111', 'active' => true,
        ]);
        Http::fake(['oman.paymob.com/v1/intention/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => 777], 201)]);

        $this->postJson('https://ribbon.abaadapp.om/checkout', $this->order($this->ribbon, 'card'))->assertOk();

        $intent = StorePaymentIntent::where('business_id', $this->ribbon->id)->latest('id')->firstOrFail();
        $sent = Http::recorded(fn (Sent $r) => str_contains($r->url(), '/v1/intention/'))->first()[0];

        $this->assertSame('https://ribbon.abaadapp.om/paying/'.$intent->reference, $sent['redirection_url']);
        $this->assertSame(rtrim(config('app.url'), '/').'/webhooks/paymob', $sent['notification_url']);
    }

    public function test_a_shop_outside_the_list_is_untouched(): void
    {
        $p = $this->product($this->other);

        foreach (['/s/bloom', '/s/bloom/shop', "/s/bloom/p/{$p}", '/s/bloom/sitemap.xml'] as $path) {
            $this->get('https://app.abaadapp.om'.$path)->assertOk();
        }

        $html = (string) $this->get('https://app.abaadapp.om/s/bloom')->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://app.abaadapp.om/s/bloom"', $html);
        $this->assertSame(url('/s/bloom'), Domains::canonical($this->other->id, 'bloom'));
        $this->assertFalse(Domains::subdomainServed($this->other->id));
    }

    public function test_an_empty_list_turns_it_all_off(): void
    {
        config(['storefront.subdomain_businesses' => []]);

        $this->get('https://app.abaadapp.om/s/ribbon/shop')->assertOk();
        $html = (string) $this->get('https://app.abaadapp.om/s/ribbon')->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://app.abaadapp.om/s/ribbon"', $html);
        $this->assertFalse(Domains::subdomainServed($this->ribbon->id));
    }

    public function test_the_global_flag_writes_canonical_but_moves_no_one(): void
    {
        config(['storefront.subdomains' => true]);

        // متجرٌ خارج القائمة: أصلُه على نطاقه، وبابُه البديل يخدم كما كان — لا تحويل
        $this->get('https://app.abaadapp.om/s/bloom/shop')->assertOk();
        $this->assertSame('https://bloom.abaadapp.om', Domains::canonical($this->other->id, 'bloom'));
        $this->assertFalse(Domains::movedToSubdomain($this->other->id));
    }

    public function test_the_platform_doors_do_not_move(): void
    {
        $this->get('https://app.abaadapp.om/login')->assertOk();
        $this->get('https://app.abaadapp.om/health')->assertOk();

        // والمعاينةُ في اللوحة ليست من صفحات المتجر — لا تُحوَّل
        $owner = User::where('business_id', $this->ribbon->id)->firstOrFail();
        $this->assertNotRedirected($this->actingAs($owner)->get('https://app.abaadapp.om/store/preview'), 'preview');

        // وإشعارُ Paymob على النظام كما كان
        $this->assertNotRedirected($this->postJson('https://app.abaadapp.om/webhooks/paymob', []), 'webhook');
    }

    /**
     * مؤقّتٌ أوّلًا، ودائمٌ حين يُكتب 301 صراحةً — ولا ثالث.
     *
     * المتصفّحُ يحفظ 301 فلا يسأل الخادمَ بعده؛ فقيمةٌ خاطئةٌ في الإعداد تُقرأ
     * 302 لا تحويلًا دائمًا. والمؤقّتُ لا يحمل مدّةَ حفظ.
     */
    public function test_the_move_is_temporary_until_301_is_written_and_nothing_else_is_accepted(): void
    {
        $res = $this->get('https://app.abaadapp.om/s/ribbon/shop?lang=en')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://ribbon.abaadapp.om/shop?lang=en');
        $this->assertStringNotContainsString('max-age', (string) $res->headers->get('Cache-Control'));

        config(['storefront.subdomain_redirect_status' => 301]);
        $this->get('https://app.abaadapp.om/s/ribbon/shop?lang=en')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://ribbon.abaadapp.om/shop?lang=en');

        foreach ([307, 308, 200, 0, null, 'permanent'] as $wrong) {
            config(['storefront.subdomain_redirect_status' => $wrong]);
            $this->get('https://app.abaadapp.om/s/ribbon/shop')->assertStatus(302);
        }

        // والمتجرُ خارج القائمة لا يُحوَّل بأيّ قيمة
        config(['storefront.subdomain_redirect_status' => 301]);
        $this->get('https://app.abaadapp.om/s/bloom/shop')->assertOk();
    }

    /**
     * والتحويلُ على أبواب صفحات المتجر الأربعة وحدها، بـGET وHEAD وحدهما.
     *
     * فلا يقع على API ولا على إشعارات Paymob وواتساب ولا على POST — اليومَ ولا
     * يوم يُضاف مسارٌ إلى المجموعة بغير قصد.
     */
    public function test_the_door_sits_on_the_four_store_pages_only_and_on_get_only(): void
    {
        $carrying = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array(StoreLivesOnItsSubdomain::class, $route->gatherMiddleware(), true)) {
                $carrying[(string) $route->getName()] = $route->methods();
            }
        }

        ksort($carrying);
        $this->assertSame([
            'store.show' => ['GET', 'HEAD'],
            'store.show.page' => ['GET', 'HEAD'],
            'store.show.sitemap' => ['GET', 'HEAD'],
            'store.show.sitemap.part' => ['GET', 'HEAD'],
        ], $carrying);
    }

    /** وقائمةٌ بغير شكلها (`معرّف => 'الاسم'`) لا تنقل أحدًا */
    public function test_a_list_in_the_wrong_shape_moves_no_one(): void
    {
        foreach ([[$this->ribbon->id], [$this->ribbon->id => ''], [$this->ribbon->id => 'bloom'], ['ribbon' => 'ribbon'], [$this->ribbon->id => ['ribbon']]] as $wrong) {
            config(['storefront.subdomain_businesses' => $wrong]);
            $this->get('https://app.abaadapp.om/s/ribbon/shop')->assertOk();
            $this->get('https://app.abaadapp.om/s/bloom/shop')->assertOk();
        }

        // والاسمُ يُقرأ كما يُقرأ في كلّ مكان — بحروفٍ صغيرة
        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'RIBBON']]);
        $this->get('https://app.abaadapp.om/s/ribbon/shop')->assertStatus(302);
    }

    /**
     * ولا استعلامَ زائدًا لبقية التجّار — صفحةُ متجرٍ آخر تسأل الجدولَ كما كانت.
     */
    public function test_other_merchants_pages_cost_no_extra_query(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('https://app.abaadapp.om/s/bloom/shop')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        config(['storefront.subdomain_businesses' => []]);
        $count();
        $without = $count();

        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'ribbon']]);
        $with = $count();

        $this->assertSame($without, $with, 'صفحةُ متجرٍ آخر صارت تسأل الجدولَ أكثر');
    }

    /**
     * غيّر سعودٌ عنوانَ موقعه من إعداداته — فيبقى `ribbon.abaadapp.om` له.
     *
     * النطاقُ مثبَّتٌ بمعرّف المتجر: الأصلُ والروابطُ القديمة `/s/ribbon/…` كلُّها
     * إليه، والنطاقُ يعرضه، ولا يظهر في أيّ مكانٍ نطاقٌ باسمٍ جديد لا سجلَّ له.
     */
    public function test_renaming_the_moved_shop_keeps_its_domain_and_its_old_links(): void
    {
        $p = $this->product($this->ribbon);
        $this->ribbon->forceFill(['site_slug' => 'ribbon-flowers'])->save();

        $this->assertSame('https://ribbon.abaadapp.om', Domains::canonical($this->ribbon->id, 'ribbon-flowers'));
        $this->assertSame('https://ribbon.abaadapp.om', Domains::canonical($this->ribbon->id));
        $this->assertSame('ribbon.abaadapp.om', Domains::state($this->ribbon->id)['platform']['host']);

        // والنطاقُ يعرض متجرَ سعود — بمنتجه
        foreach (['/', '/shop', "/p/{$p}", '/cart', '/sitemap.xml'] as $path) {
            $this->assertSame(200, $this->get('https://ribbon.abaadapp.om'.$path)->getStatusCode(), $path);
        }
        $this->get('https://ribbon.abaadapp.om/')->assertSee('RIBBON');

        // والروابطُ القديمة تصل إليه كما كانت
        $this->get('https://app.abaadapp.om/s/ribbon/shop?lang=en')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://ribbon.abaadapp.om/shop?lang=en');

        // وبابُه الجديد يعمل، وأصلُه النطاقُ لا اسمٌ جديد
        $html = (string) $this->get('https://app.abaadapp.om/s/ribbon-flowers')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://ribbon.abaadapp.om"', $html);
        $this->assertStringNotContainsString('ribbon-flowers.abaadapp.om', $html);
    }

    /**
     * ولا يتّخذ تاجرٌ آخر اسمَ النطاق من إعداداته — ولو تركه سعود.
     *
     * وهو البابُ الوحيد الذي يكتب به التاجرُ عنوانَه، فلا يستحوذ أحدٌ على
     * `ribbon.abaadapp.om` ولا على روابط سعود القديمة. وسعودٌ نفسُه يعود إليه متى شاء.
     */
    public function test_no_other_merchant_can_take_the_pinned_name(): void
    {
        $owner = User::where('business_id', $this->other->id)->firstOrFail();

        // وهو لسعود
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['site_slug' => 'ribbon'])
            ->assertSessionHasErrors('site_slug');

        // وبعد أن تركه سعود — بالحروف الكبيرة أيضًا
        $this->ribbon->forceFill(['site_slug' => 'ribbon-flowers'])->save();
        foreach (['ribbon', 'RIBBON', ' ribbon '] as $try) {
            $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['site_slug' => $try])
                ->assertSessionHasErrors('site_slug');
        }
        $this->assertSame('bloom', $this->other->refresh()->site_slug);
        // والنطاقُ ما زال يعرض متجرَ سعود لا متجرَ من حاول
        $this->get('https://ribbon.abaadapp.om/')->assertOk()->assertSee('RIBBON')->assertDontSee('BLOOM');

        // وسعودٌ يعود إلى اسمه
        $saud = User::where('business_id', $this->ribbon->id)->firstOrFail();
        $this->actingAs($saud)->post(route('admin.marketing.store.save'), ['site_slug' => 'ribbon'])
            ->assertSessionDoesntHaveErrors('site_slug');
        $this->assertSame('ribbon', $this->ribbon->refresh()->site_slug);
    }

    /**
     * وقائمةٌ كُتب فيها اسمُ متجرٍ آخر خطأً لا تنتزع عنوانَه.
     *
     * لا يقع هذا من الإعدادات (يمنعه الحجز)، بل من خطأٍ في كتابة القائمة. فيبقى
     * ذلك المتجرُ على عنوانه بلا تحويل، ويبقى سعودٌ على بابه.
     */
    public function test_a_list_naming_another_shops_name_takes_nothing_from_it(): void
    {
        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'bloom']]);

        foreach (['/s/bloom', '/s/bloom/shop', '/s/ribbon/shop'] as $path) {
            $this->get('https://app.abaadapp.om'.$path)->assertOk();
        }
        $this->assertSame(url('/s/bloom'), Domains::canonical($this->other->id, 'bloom'));
        $this->assertSame(url('/s/ribbon'), Domains::canonical($this->ribbon->id, 'ribbon'));
        $this->assertNull(Domains::pinnedBusiness('bloom'));
        $this->get('https://bloom.abaadapp.om/')->assertOk()->assertSee('BLOOM');
    }

    /**
     * وإن وُجد الاسمُ عند غير صاحبه في القاعدة (كتابةٌ يدويّةٌ لا تأتي من الإعدادات):
     * لا يُرسل زبونُ أحدٍ إلى متجر غيره — سعودٌ على بابه، والآخرُ على عنوانه.
     */
    public function test_a_name_held_by_someone_else_in_the_database_sends_no_one_anywhere(): void
    {
        $this->ribbon->forceFill(['site_slug' => 'ribbon-flowers'])->save();
        $this->other->forceFill(['site_slug' => 'ribbon'])->save();

        $this->assertSame(url('/s/ribbon-flowers'), Domains::canonical($this->ribbon->id, 'ribbon-flowers'));
        $this->assertNull(Domains::pinnedLabel($this->ribbon->id));
        $this->assertNotRedirected($this->get('https://app.abaadapp.om/s/ribbon/shop'), 'held elsewhere');
        $this->get('https://app.abaadapp.om/s/ribbon-flowers/shop')->assertOk();
    }

    /**
     * والحجزُ يُسأل عند تغيير العنوان وحده — فلا يُردّ من يحفظ إعداداته بعنوانه.
     *
     * قائمةٌ كُتب فيها اسمُ متجرٍ آخر خطأً، أو بيئةُ تجربةٍ فيها متجرٌ باسم `ribbon`
     * ليس رقمُه ٥: صاحبُ الاسم يحفظ إعداداتِ متجره كما كان، ولا يأخذ اسمَه أحد.
     */
    public function test_the_shop_holding_a_listed_name_still_saves_its_settings(): void
    {
        $bloomOwner = User::where('business_id', $this->other->id)->firstOrFail();
        $saud = User::where('business_id', $this->ribbon->id)->firstOrFail();

        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'bloom']]);
        $this->actingAs($bloomOwner)->post(route('admin.marketing.store.save'), ['site_slug' => 'bloom', 'store_on' => true])
            ->assertSessionDoesntHaveErrors('site_slug');
        $this->assertSame('bloom', $this->other->refresh()->site_slug);

        config(['storefront.subdomain_businesses' => [$this->ribbon->id + 1000 => 'ribbon']]);
        $this->actingAs($saud)->post(route('admin.marketing.store.save'), ['site_slug' => 'ribbon', 'store_on' => true])
            ->assertSessionDoesntHaveErrors('site_slug');

        // والحجزُ ما زال يمنع من يغيّر عنوانَه إلى اسمٍ محجوزٍ لغيره
        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'ribbon']]);
        $this->ribbon->forceFill(['site_slug' => 'ribbon-flowers'])->save();
        $this->actingAs($bloomOwner)->post(route('admin.marketing.store.save'), ['site_slug' => 'ribbon'])
            ->assertSessionHasErrors('site_slug');
    }
}

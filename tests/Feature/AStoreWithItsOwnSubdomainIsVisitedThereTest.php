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

        config(['storefront.subdomain_businesses' => [$this->ribbon->id]]);
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
}

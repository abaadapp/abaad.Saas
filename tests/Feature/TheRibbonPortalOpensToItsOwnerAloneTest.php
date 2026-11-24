<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\CustomerPortalSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Portal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\RibbonPortalShop;
use Tests\TestCase;

/**
 * بوابةُ RIBBON لا تُفتح إلّا لصاحبها — ولا تُعرف إلّا في متجرها.
 *
 *   - الرقمُ وحدَه لا يكشف شيئًا، ولا يقول أمسجَّلٌ هو أم لا.
 *   - والرابطُ وحدَه كذلك، ولا يُخمَّن، ويُغلق بالإلغاء وبتغيّر الرقم وبحذف العميل ساعتَها.
 *   - ولا يبلغ أحدٌ فاتورةَ غيره برقمها ولا بمعرّفها.
 *   - ومتجرٌ آخر لا بابَ له ولا زرَّ ولا مفتاحَ في صفحاته — ولو كتب العنوانَ بيده.
 *   - ومشاركةُ الرابط لمن اختاره صاحبُ النشاط، ولا تفتح له شيئًا آخر.
 */
class TheRibbonPortalOpensToItsOwnerAloneTest extends TestCase
{
    use RefreshDatabase;
    use RibbonPortalShop;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-29 10:00:00');
        app()->setLocale('ar');
        Http::preventStrayRequests();
        $this->openRibbon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(User $user): static
    {
        $this->defaultCookies = [];
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function grant(array $share, array $manage = []): void
    {
        Portal::saveStaff((int) $this->ribbon->id, $share, $manage);
    }

    /* ═════════════ الرقمُ وحدَه والرابطُ وحدَه ═════════════ */

    public function test_the_public_page_shows_nothing_and_a_phone_number_alone_opens_nothing(): void
    {
        $this->order($this->maryam, 'INV-1');
        $page = $this->my()->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="portal-public"', $page);
        foreach (['مريم', 'INV-1', '91234567'] as $private) {
            $this->assertStringNotContainsString($private, $page);
        }

        // بلا رابط: الرقمُ المسجَّل وغيرُ المسجَّل ورقمُ عميلِ متجرٍ آخر سواءٌ في الردّ
        $this->buyer('جارة', '99999999', $this->neighbour);
        $answers = [];
        foreach (['', 'x', str_repeat('A', 43), $this->maryam->id] as $key) {
            foreach (['91234567', '90000001', '99999999'] as $phone) {
                RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
                $r = $this->attempt((string) $key, $phone)->assertStatus(422);
                $this->assertNull($r->getCookie(Access::COOKIE));
                $answers[] = $r->getContent();
            }
        }
        // والرابطُ الصحيح برقمٍ خاطئ: الردُّ نفسُه
        RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
        $answers[] = $this->attempt($this->key($this->maryam), '90000001')->assertStatus(422)->getContent();
        $this->assertCount(1, array_unique($answers), 'لا يُعرف من الردّ أصحّ الرابطُ أم الرقم');

        $this->assertSame(0, CustomerPortalSession::count());
        $this->myJson('/data')->assertStatus(401);
        $this->myJson('/points')->assertStatus(401);
        $this->my('/paper/order/1')->assertRedirect();
        $this->my('/paper/order/1/sheet')->assertForbidden();
        $this->my('/paper/order/1/pdf')->assertForbidden();
    }

    public function test_another_shops_customer_with_the_same_number_does_not_open_this_one(): void
    {
        $twin = $this->buyer('مريم الجار', '91234567', $this->neighbour);
        $this->order($twin, 'NEIGHBOUR-9');
        $mine = $this->order($this->maryam, 'INV-MINE');

        // ورابطٌ لعميل متجرٍ آخر لا يعمل هنا ولو صدر
        $foreign = Access::share($twin, null);
        $this->attempt(explode('#', $foreign['url'])[1], '91234567')->assertStatus(422);

        $cookie = $this->enter($this->maryam);
        $seen = $this->myJson('/data', $cookie)->getContent();
        $this->assertStringContainsString('INV-MINE', $seen);
        $this->assertStringNotContainsString('NEIGHBOUR-9', $seen);

        // وسجلّان برقمٍ واحد في المتجر نفسه لا يُدمجان: لكلٍّ رابطُه ومشترياتُه
        $same = $this->buyer('مريم الثانية', null, null, ['contact_phone' => '91234567']);
        $this->order($same, 'INV-SECOND');
        $other = $this->enter($same, '91234567');
        $this->assertStringContainsString('INV-SECOND', $this->myJson('/data', $other)->getContent());
        $this->assertStringNotContainsString('INV-MINE', $this->myJson('/data', $other)->getContent());
        $this->my("/paper/order/{$mine->id}", $other)->assertNotFound();
        // ولا سجلَّ تغيّر
        $this->assertSame(2, Customer::where('business_id', $this->ribbon->id)->count());
    }

    public function test_guessing_is_stopped(): void
    {
        $key = $this->key($this->maryam);
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt($key, '9000000'.$i)->assertStatus(422);
        }
        // والسادسةُ تُردّ ولو صحّت — حتّى تنقضي المدّة
        $this->attempt($key, '91234567')->assertStatus(429)->assertJsonPath('ok', null);
        $this->assertSame(0, CustomerPortalSession::count());

        Carbon::setTestNow(now()->addMinutes(16));
        $this->attempt($key, '91234567')->assertOk();

        // وعنوانٌ يجرّب روابطَ كثيرة يُردّ كذلك
        RateLimiter::clear('ribbon-portal:ip:127.0.0.1');
        for ($i = 1; $i <= 20; $i++) {
            $this->attempt(str_pad((string) $i, 43, 'B'), '91234567');
            Carbon::setTestNow(now()->addSeconds(20));
        }
        $this->attempt($key, '91234567')->assertStatus(429);

        // وبابُ الدخول بعدّادٍ فوق ذلك كلِّه
        foreach (['my.login', 'my.same', 'my.data', 'my.points', 'my.paper', 'my.paper.sheet', 'my.paper.pdf', 'my.index'] as $name) {
            $this->assertNotEmpty(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $name);
        }
    }

    /* ═════════════ الرابط ═════════════ */

    public function test_a_link_holds_no_name_or_number_and_only_its_fingerprint_is_kept(): void
    {
        $url = Access::share($this->maryam, $this->owner)['url'];
        [$address, $key] = explode('#', $url);

        $this->assertSame('https://ribbon.abaadapp.om/my', $address, 'الرمزُ في جزء # لا يبلغ الخادمَ ولا سجلَّه');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $key);
        foreach (['91234567', '968', rawurlencode('مريم'), (string) base64_encode('91234567')] as $hint) {
            $this->assertStringNotContainsString($hint, $url);
        }

        $stored = json_encode([DB::table('customer_portal_links')->get(), DB::table('activity_logs')->get(), DB::table('cache')->get()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($key, $stored);
        $this->assertStringNotContainsString('91234567', json_encode(DB::table('customer_portal_links')->get()));
        $this->assertSame(hash('sha256', $key), DB::table('customer_portal_links')->value('token_hash'));
        $this->assertArrayNotHasKey('token_hash', CustomerPortalLink::first()->toArray());

        // وما في القاعدة وحدَه لا يشتقّه: يلزم مفتاحُ الخادم
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($url, (fn () => Access::share($this->maryam->fresh(), null)['url'])());
    }

    public function test_the_same_link_is_shared_again_until_it_is_reissued_or_revoked(): void
    {
        $first = $this->key($this->maryam);
        $this->assertSame($first, $this->key($this->maryam));
        $cookie = $this->enter($this->maryam);
        $this->my('', $cookie)->assertSee('مريم');

        // إعادةُ الإصدار تُغلق القديمَ وجلساتِه ساعتَها
        $second = explode('#', Access::reissue($this->maryam, $this->owner)['url'])[1];
        $this->assertNotSame($first, $second);
        $this->attempt($first, '91234567')->assertStatus(422);
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->my('', $cookie)->assertDontSee('مريم')->assertCookieExpired(Access::COOKIE);
        $this->attempt($second, '91234567')->assertOk();

        // والإلغاءُ كذلك
        $cookie = $this->enter($this->maryam);
        $this->assertTrue(Access::revoke($this->maryam, $this->owner));
        $this->attempt($second, '91234567')->assertStatus(422);
        $this->myJson('/data', $cookie)->assertStatus(401);

        // ورابطٌ حيٌّ واحدٌ لكلّ عميل — تحرسه القاعدة
        $this->key($this->maryam);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customer_portal_links')->insert(['business_id' => $this->ribbon->id, 'customer_id' => $this->maryam->id, 'token_hash' => str_repeat('a', 64), 'nonce' => str_repeat('b', 64), 'phone_hash' => str_repeat('c', 64)]);
    }

    public function test_a_changed_number_or_a_deleted_customer_closes_everything_at_once(): void
    {
        $cookie = $this->enter($this->maryam);
        $key = $this->key($this->maryam);

        $this->maryam->update(['phone' => '95550000']);
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->attempt($key, '91234567')->assertStatus(422);
        $this->attempt($key, '95550000')->assertStatus(422);
        $this->assertSame(Access::REVOKED_PHONE, CustomerPortalLink::latest('id')->value('revoked_why'));
        // وعودةُ الرقم لا تفتحه: يُصدر من جديد
        $this->maryam->update(['phone' => '91234567']);
        $this->attempt($key, '91234567')->assertStatus(422);

        $cookie = $this->enter($this->maryam);
        $this->maryam->delete();
        $this->myJson('/data', $cookie)->assertStatus(401);
        $this->my('', $cookie)->assertDontSee('مريم');

        // ورقمٌ كُتب بصيغةٍ أخرى ليس رقمًا تغيّر
        $sara = $this->buyer('سارة', '92223333');
        $cookie = $this->enter($sara);
        $sara->update(['phone' => '+968 9222 3333']);
        $this->myJson('/data', $cookie)->assertOk();
        $this->assertNull(Access::share($this->buyer('بلا رقم', null), $this->owner), 'من لا رقمَ له لا رابطَ له');
    }

    /* ═════════════ فاتورةُ غيره ═════════════ */

    public function test_no_one_reaches_anothers_invoice_by_its_number_or_id(): void
    {
        $fatima = $this->buyer('فاطمة', '92222222');
        $order = $this->order($fatima, 'INV-F');
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->ribbon->id, $fatima, [], [['description' => 'تنسيق', 'quantity' => 1, 'unit_price' => 9]], $this->owner->id), $this->owner->id);
        $nb = $this->order($this->buyer('جارة', '93333333', $this->neighbour), 'NB-1');
        $mine = $this->order($this->maryam, 'INV-M');
        $held = $this->order($this->maryam, 'HELD', ['is_held' => true]);
        $draft = CustomerInvoices::create($this->ribbon->id, $this->maryam, [], [['description' => 'مسودة', 'quantity' => 1, 'unit_price' => 5]], $this->owner->id);

        $cookie = $this->enter($this->maryam);
        foreach (['', '/sheet', '/pdf', '/pdf?download=1'] as $tail) {
            $this->my("/paper/order/{$mine->id}{$tail}", $cookie)->assertOk();
            foreach (["order/{$order->id}", "invoice/{$invoice->id}", "order/{$nb->id}", "order/{$held->id}", "invoice/{$draft->id}", 'order/999999', "invoice/{$mine->id}000"] as $not) {
                $this->my("/paper/{$not}{$tail}", $cookie)->assertNotFound();
            }
        }
        foreach (['/paper/order/INV-F', '/paper/order/0', '/paper/user/1', '/paper/order/1e3', '/paper/order/99999999999'] as $odd) {
            $this->my($odd, $cookie)->assertNotFound();
        }
        $this->assertSame([], $this->myJson('/data?q=INV-F', $cookie)->json('rows'));
        $this->assertSame([], $this->myJson('/data?customer_id='.$fatima->id.'&q=INV-F&business_id='.$this->neighbour->id, $cookie)->json('rows'));

        // ورابطُ التحقّق العامّ على ورق المتجر كما كان لا يُبنى منه بابٌ هنا
        $this->my('/paper/order/'.$mine->number, $cookie)->assertNotFound();
    }

    /* ═════════════ الجلسةُ والترويسات ═════════════ */

    public function test_the_session_is_its_own_and_nothing_is_stored_or_indexed(): void
    {
        $response = $this->attempt($this->key($this->maryam), '91234567');
        $cookie = $response->getCookie(Access::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/my', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain(), 'لمضيف البوابة وحده');
        $this->assertTrue(Access::cookie(Request::create('https://ribbon.abaadapp.om/my'), str_repeat('a', 64))->isSecure());
        $this->assertStringNotContainsString($cookie->getValue(), json_encode(DB::table('customer_portal_sessions')->get()));

        $raw = (string) $cookie->getValue();
        foreach (['', '/data', '/points'] as $path) {
            $r = $this->my($path, $raw);
            $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'), $path);
            $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'), $path);
        }
        $this->assertStringNotContainsString('public', (string) $this->my('/logo', $raw)->assertOk()->headers->get('Cache-Control'));
        $page = $this->my('', $raw);
        $csp = (string) $page->headers->get('Content-Security-Policy');
        foreach (["default-src 'none'", "connect-src 'self'", "frame-ancestors 'self'"] as $rule) {
            $this->assertStringContainsString($rule, $csp);
        }
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp)[0], 'لا نصَّ من غير الصفحة');
        $html = $page->getContent();
        $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $html);
        foreach (['googletagmanager', 'google-analytics', 'gtag(', 'fbq(', 'snap', 'http://', 'https://'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, 'لا أداةَ خارجيّةً ولا رابطَ خارجًا: '.$foreign);
        }
        // والصفحةُ بلا متجر: لا سلّةَ ولا أصنافَ ولا قائمة
        foreach (['/cart', '/shop', '/checkout', 'rb-head'] as $store) {
            $this->assertStringNotContainsString($store, $html);
        }

        // كعكةُ البوابة ليست دخولًا إلى اللوحة، ودخولُ اللوحة ليس دخولًا إلى البوابة
        $this->visitor($raw)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->staff($this->owner)->getJson('http://ribbon.abaadapp.om/my/data')->assertStatus(401);

        // وتنقضي، ويُخرج منها
        $this->visitor($raw)->post('http://ribbon.abaadapp.om/my/logout')->assertRedirect()->assertCookieExpired(Access::COOKIE);
        $this->myJson('/data', $raw)->assertStatus(401);
        $again = $this->enter($this->maryam);
        Carbon::setTestNow(now()->addDays(Access::DAYS + 1));
        $this->myJson('/data', $again)->assertStatus(401);
        $this->myJson('/data', str_repeat('f', 64))->assertStatus(401);
    }

    public function test_a_link_opened_on_a_signed_in_device_asks_only_when_it_is_someone_elses(): void
    {
        $fatima = $this->buyer('فاطمة', '92222222');
        $cookie = $this->enter($this->maryam);

        $same = fn (string $key) => $this->visitor($cookie)->postJson('http://ribbon.abaadapp.om/my/same', ['key' => $key])->json('same');
        $this->assertTrue($same($this->key($this->maryam)));
        $this->assertFalse($same($this->key($fatima)));
        $this->assertFalse($same(str_repeat('A', 43)));
        $this->my('?switch=1', $cookie)->assertOk()->assertSee('data-testid="portal-login"', false)->assertDontSee('مريم');
        $this->myJson('/data', $cookie)->assertOk();
    }

    /* ═════════════ متجرٌ واحد ═════════════ */

    public function test_it_exists_on_ribbons_domain_alone(): void
    {
        $cookie = $this->enter($this->maryam);
        foreach (['http://neighbour.abaadapp.om/my', 'http://app.abaadapp.om/my', 'http://abaadapp.om/my', 'http://www.abaadapp.om/my', 'http://ribbon.abaadapp.om.evil.com/my', 'http://shop.example.com/my', 'http://neighbour.abaadapp.om/my/data'] as $elsewhere) {
            $this->visitor($cookie)->get($elsewhere)->assertNotFound();
        }

        // والقفلُ يُغلق كلَّ باب
        config(['storefront.ribbon_customer_portal.enabled' => false]);
        $this->my('', $cookie)->assertNotFound();
        $this->myJson('/data', $cookie)->assertNotFound();
        $this->staff($this->owner)->postJson(route('admin.customers.portal.share', $this->maryam->id))->assertNotFound();
        $this->staff($this->owner)->get(route('admin.settings.portal.index'))->assertNotFound();
        config(['storefront.ribbon_customer_portal.enabled' => true]);
        $this->my('', $cookie)->assertOk();

        // واسمُ النطاق حمله متجرٌ آخر خطأً، أو المعرّفُ لغير صاحب النطاق: لا بوابة
        $this->ribbon->forceFill(['site_slug' => 'ribbon-shop'])->save();
        $this->my('', $cookie)->assertOk();
        $this->neighbour->forceFill(['site_slug' => 'ribbon'])->save();
        $this->my('', $cookie)->assertNotFound();
        $this->neighbour->forceFill(['site_slug' => 'neighbour'])->save();
        config(['storefront.ribbon_customer_portal.business' => (int) $this->neighbour->id]);
        $this->my('', $cookie)->assertNotFound();
        config(['storefront.ribbon_customer_portal.business' => [(int) $this->ribbon->id]]);
        $this->assertNull(Portal::businessId(), 'معرّفٌ واحدٌ لا قائمة');
    }

    public function test_the_shipped_setting_is_saud_alone_and_no_platform_switch_exists(): void
    {
        $shipped = (require base_path('config/storefront.php'))['ribbon_customer_portal'];
        $this->assertSame(5, $shipped['business']); // رقمٌ مقصود: متجرُ سعود في config/storefront.php
        $this->assertSame('ribbon', (require base_path('config/storefront.php'))['subdomain_businesses'][5]); // رقمٌ مقصود

        $read = fn (string $dir) => collect(File::allFiles(base_path($dir)))->map(fn ($f) => [$f->getRelativePathname(), $f->getContents()]);
        foreach ($read('app/Http/Controllers/SuperAdmin')->merge($read('resources/js/Pages/Platform')) as [$name, $code]) {
            $this->assertStringNotContainsStringIgnoringCase('RibbonPortal', $code, $name);
        }
        $this->assertStringNotContainsString('ribbon_customer_portal', (string) file_get_contents(app_path('Support/PlanFeatures.php')));
        // ولا تُسأل الواجهةُ ولا الاسم
        foreach ($read('app/Support/RibbonPortal') as [$name, $code]) {
            $this->assertDoesNotMatchRegularExpression('/SERVES_THEME|store_theme|->name\s*===|business_id\s*===\s*5/', $code, $name);
        }
    }

    public function test_another_shop_has_no_button_no_key_and_no_door(): void
    {
        $them = $this->staffer('مالك الجار', 'admin', null, $this->neighbour);
        $theirs = $this->buyer('عميل الجار', '94444444', $this->neighbour);

        foreach ([route('admin.customers.index') => 'portal', route('admin.customers.show', $theirs->id) => 'portalLink', route('admin.settings.index') => 'portalUrl'] as $screen => $key) {
            $this->assertArrayNotHasKey($key, $this->staff($them)->get($screen)->assertOk()->viewData('page')['props'], $screen);
        }
        foreach ([
            ['post', route('admin.customers.portal.share', $theirs->id)], ['post', route('admin.customers.portal.reissue', $theirs->id)], ['delete', route('admin.customers.portal.revoke', $theirs->id)],
            ['post', route('admin.customers.portal.share', $this->maryam->id)], ['get', route('admin.customers.portal.poster')], ['get', route('admin.settings.portal.index')],
            ['post', route('admin.settings.portal.staff')], ['delete', route('admin.settings.portal.revokeAll')], ['delete', route('admin.settings.portal.revoke', 1)], ['post', route('pos.orders.portal', 'INV-1')],
        ] as [$verb, $url]) {
            $this->staff($them)->json(strtoupper($verb), $url)->assertNotFound();
        }
        $this->assertSame(0, CustomerPortalLink::count());

        // ولا اسمَ ولا عنوانَ لها في صفحاته — ولا في جدول المسارات الذي يحمله متصفّحُه
        foreach ([route('admin.customers.index'), route('admin.settings.index'), route('admin.customers.show', $theirs->id), route('pos.index')] as $screen) {
            $html = $this->staff($them)->get($screen)->getContent();
            $this->assertStringNotContainsString('.portal.', $html, $screen);
            $this->assertStringNotContainsString('orders.portal', $html, $screen);
            $this->assertStringNotContainsString('ribbon.abaadapp.om', $html, $screen);
        }

        // ولا يكتب لنفسه إعدادَها فتُفتح
        Setting::create(['business_id' => $this->neighbour->id, 'key' => Portal::STAFF, 'value' => json_encode(['share' => [$them->id], 'manage' => [$them->id]])]);
        $this->assertFalse(Portal::mayShare($them));
        $this->assertFalse(Portal::mayManage($them));
    }

    /* ═════════════ من يشارك ═════════════ */

    public function test_the_owner_and_whoever_they_named_share_and_the_rest_do_not(): void
    {
        $clerk = $this->staffer('موظفة', 'sales', ['customers', 'pos']);
        $till = $this->staffer('كاشير', 'cashier');
        $plain = $this->staffer('بائع', 'sales', ['customers', 'pos', 'orders', 'settings']);
        $this->grant([$clerk->id, $till->id, $this->staffer('غريب', 'sales', null, $this->neighbour)->id]);
        $this->assertSame(['share' => [$clerk->id, $till->id], 'manage' => []], Portal::staff((int) $this->ribbon->id), 'من موظّفي المتجر وحدهم');

        // صاحبُ النشاط ومن اختاره: الزرُّ في القائمة والملفّ
        foreach ([[$this->owner, true], [$clerk, true], [$plain, false]] as [$user, $may]) {
            $index = $this->staff($user)->get(route('admin.customers.index'))->assertOk()->viewData('page')['props'];
            $show = $this->staff($user)->get(route('admin.customers.show', $this->maryam->id))->assertOk()->viewData('page')['props'];
            $this->assertSame($may, isset($index['portal']), $user->name);
            $this->assertSame($may, isset($show['portalLink']), $user->name);
            $this->staff($user)->postJson(route('admin.customers.portal.share', $this->maryam->id))->assertStatus($may ? 200 : 403);
        }
        $this->assertSame('https://ribbon.abaadapp.om/my', $index['portal']['url'] ?? $this->staff($this->owner)->get(route('admin.customers.index'))->viewData('page')['props']['portal']['url']);

        // والمشاركةُ لا تفتح الفواتيرَ ولا النقاطَ ولا الإعدادات
        $this->assertFalse($clerk->allows('orders'));
        $this->staff($clerk)->get(route('admin.customerInvoices.index'))->assertForbidden();
        $this->staff($clerk)->postJson(route('admin.customers.points.adjust', $this->maryam->id), ['direction' => 'add', 'points' => 5, 'reason' => 'x'])->assertForbidden();
        $this->staff($clerk)->get(route('admin.settings.portal.index'))->assertForbidden();
        // ومن لا يدخل العملاء لا يشارك منه ولو اختير
        $this->staff($till)->postJson(route('admin.customers.portal.share', $this->maryam->id))->assertForbidden();
        $this->assertSame(['customers', 'pos'], $clerk->fresh()->permissions, 'صلاحيّاتُه كما كانت');

        // والردُّ رابطٌ ورسالةٌ لرقمه المسجَّل — ولا يُرسَل شيء
        $reply = $this->staff($clerk)->postJson(route('admin.customers.portal.share', $this->maryam->id))->assertOk()->json();
        $this->assertStringStartsWith('https://wa.me/96891234567?text=', $reply['whatsapp']);
        $this->assertStringContainsString('أهلًا بك في RIBBON، يسعدنا أن نوفر لك بوابتك الخاصة', $reply['message']);
        $this->assertStringContainsString($reply['url'], $reply['message']);
        $this->assertStringNotContainsString('مريم', $reply['message']);
        $this->maryam->update(['language' => 'en']);
        $this->assertStringContainsString('Welcome to RIBBON', $this->staff($clerk)->postJson(route('admin.customers.portal.share', $this->maryam->id))->json('message'));
        Http::assertNothingSent();

        // وعميلٌ بلا رقم: يُقال، ولا يُصدر
        $this->staff($clerk)->postJson(route('admin.customers.portal.share', $this->buyer('بلا رقم', null)->id))->assertStatus(422);
        // وعميلُ متجرٍ آخر ليس هنا
        $this->staff($this->owner)->postJson(route('admin.customers.portal.share', $this->buyer('جار', '97777777', $this->neighbour)->id))->assertNotFound();
    }

    public function test_the_till_offers_it_after_a_sale_to_a_registered_customer_only(): void
    {
        $till = $this->staffer('كاشير', 'cashier');
        $sell = fn (User $by, array $over = []) => $this->staff($by)->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), $over + [
            'items' => [['id' => $this->bouquet->id, 'name' => 'باقة', 'qty' => 1]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true),
        ])->assertOk()->json();

        $this->assertArrayNotHasKey('portal', $sell($till, ['customer_id' => $this->maryam->id]), 'كاشيرٌ لم يُختر');
        $this->grant([$till->id]);
        $this->assertArrayNotHasKey('portal', $sell($till), 'بيعةٌ بلا عميل');
        $sold = $sell($till, ['customer_id' => $this->maryam->id]);
        $this->assertStringEndsWith('/portal', $sold['portal']);

        $reply = $this->staff($till)->postJson('http://localhost'.$sold['portal'])->assertOk()->json();
        $this->assertSame(Access::share($this->maryam->fresh(), null)['url'], $reply['url']);
        // وبيعةٌ بلا عميلٍ أو رقمٌ ليس لها: لا رابط، والبيعةُ كما هي
        $this->staff($till)->postJson(route('pos.orders.portal', $sell($till)['invoice']))->assertNotFound();
        $this->staff($till)->postJson(route('pos.orders.portal', 'NOPE'))->assertNotFound();
        $nb = $this->order($this->buyer('جار', '98888888', $this->neighbour), 'NB-7');
        $this->staff($till)->postJson(route('pos.orders.portal', $nb->number))->assertNotFound();
        $this->assertDatabaseHas('orders', ['number' => $sold['invoice'], 'customer_id' => $this->maryam->id]);
    }

    public function test_the_owner_runs_it_from_settings_and_a_manager_revokes_but_does_not_grant(): void
    {
        $manager = $this->staffer('مديرة البوابة', 'sales', ['settings', 'customers']);
        $clerk = $this->staffer('موظف', 'sales', ['customers']);

        $this->staff($this->owner)->post(route('admin.settings.portal.staff'), ['share' => [$clerk->id], 'manage' => [$manager->id]])->assertSessionHasNoErrors();
        $this->assertTrue(Portal::mayManage($manager->fresh()));
        $this->assertTrue(Portal::mayShare($manager->fresh()), 'ومن يدير يشارك');
        $this->assertFalse(Portal::mayManage($clerk->fresh()));
        $this->staff($manager)->post(route('admin.settings.portal.staff'), ['share' => [], 'manage' => []])->assertForbidden();

        $this->key($this->maryam);
        $cookie = $this->enter($this->maryam);
        $props = $this->staff($manager)->get(route('admin.settings.portal.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('مريم البلوشي', $props['links'][0]['customer']);
        $this->assertFalse($props['mayGrant']);
        $this->assertArrayNotHasKey('token_hash', $props['links'][0]);
        $this->assertSame(route('admin.settings.portal.index'), $this->staff($manager)->get(route('admin.settings.index'))->viewData('page')['props']['portalUrl']);

        $this->staff($manager)->delete(route('admin.settings.portal.revoke', $props['links'][0]['id']))->assertSessionHasNoErrors();
        $this->myJson('/data', $cookie)->assertStatus(401);

        foreach (['91110001', '91110002'] as $i => $phone) {
            $this->key($this->buyer('عميل '.$i, $phone));
        }
        $other = Access::share($this->buyer('جار', '96666666', $this->neighbour), null)['link'];
        $this->staff($manager)->delete(route('admin.settings.portal.revokeAll'));
        $this->assertSame(0, CustomerPortalLink::where('business_id', $this->ribbon->id)->whereNull('revoked_at')->count());
        $this->assertNull($other->fresh()->revoked_at, 'ولا يُمسّ متجرٌ آخر');
        $this->staff($manager)->delete(route('admin.settings.portal.revoke', $other->id))->assertNotFound();

        // ورمزُ QR ورقةٌ تُطبع بالرابط العامّ وحده
        $poster = $this->staff($this->owner)->get(route('admin.customers.portal.poster'))->assertOk()->getContent();
        $this->assertStringContainsString('<svg viewBox="0 0 320 320"', $poster, 'بلا viewBox يُقصّ الرمزُ حين يُصغَّر فلا يُمسح');
        $this->assertStringContainsString('ribbon.abaadapp.om/my', $poster);
        $this->assertStringNotContainsString('#', explode('ribbon.abaadapp.om/my', $poster)[1][0] ?? '');
    }
}

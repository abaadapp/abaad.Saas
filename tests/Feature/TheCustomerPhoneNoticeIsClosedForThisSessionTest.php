<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Support\CustomerPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تنبيهُ بيانات العملاء يُغلَق من لوحة التحكم إلى آخر الجلسة — ولا أبعدَ منها.
 *
 * الخادمُ وحدَه يعرف أنّه أُغلق، وفي الجلسة لا في القاعدة: الخروجُ يُبطلها
 * فيعود مع الدخول التالي. ولا يتعدّى صاحبَه في متجره، ولا يمسّ قسمَ العملاء.
 */
class TheCustomerPhoneNoticeIsClosedForThisSessionTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = $this->makeShop('زهور مسقط');
        $this->owner = $this->makeUser($this->shop, 'owner@notice.test');
        $this->badPhones($this->shop, 3);
    }

    private function makeShop(string $name): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);

        return $shop;
    }

    private function makeUser(Business $shop, string $email, string $role = 'admin', ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    private function badPhones(Business $shop, int $n): void
    {
        foreach (range(1, $n) as $i) {
            DB::table('customers')->insert([
                'business_id' => $shop->id, 'name' => 'ناقص '.$i, 'phone' => '9123'.$i,
                'language' => 'ar', 'points' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        CustomerPhone::forget($shop->id);
    }

    private function dismiss(): TestResponse
    {
        return $this->post(route('admin.dashboard.phoneReview.dismiss'));
    }

    private function assertNotice(int $count, string $why = ''): void
    {
        $page = $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame($count, $page->viewData('page')['props']['customerPhoneReview'], $why);
    }

    /** دخولٌ من بابه — لا `actingAs`: الجلسةُ هي ما يُختبر */
    private function signIn(string $email): void
    {
        $this->post(route('login.attempt'), ['email' => $email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /* ═════════════ يظهر، ويُغلق، ويبقى مغلقًا ═════════════ */

    public function test_it_shows_while_customers_need_a_review_and_closes_at_once(): void
    {
        $this->signIn('owner@notice.test');
        $this->assertNotice(3, 'يظهر وفي المتجر من يحتاج مراجعة');

        $this->dismiss()->assertRedirect(route('admin.dashboard'));

        $this->assertNotice(0, 'اختفى بعد الإغلاق');
    }

    public function test_it_stays_closed_after_a_refresh_and_after_walking_the_sections(): void
    {
        $this->signIn('owner@notice.test');
        $this->dismiss();

        $this->assertNotice(0, 'بعد التحديث');
        $this->assertNotice(0, 'وبعد تحديثٍ ثانٍ');

        foreach (['admin.customers.index', 'admin.orders.index', 'admin.products.index', 'admin.settings.index'] as $section) {
            $this->get(route($section))->assertOk();
            $this->assertNotice(0, "وبعد العودة من {$section}");
        }

        // ونبضةُ اللوحة لا تعيده
        $this->getJson(route('admin.dashboard.stats'))->assertOk();
        $this->assertNotice(0);
    }

    public function test_it_returns_after_signing_out_and_in_again(): void
    {
        $this->signIn('owner@notice.test');
        $this->dismiss();
        $this->assertNotice(0);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(session()->has('phone_review_dismissed'), 'الخروجُ أبطل الجلسةَ بما فيها');

        $this->signIn('owner@notice.test');
        $this->assertNotice(3, 'عاد مع الدخول التالي');
    }

    public function test_it_does_not_return_when_nobody_needs_a_review(): void
    {
        $this->signIn('owner@notice.test');
        $this->assertNotice(3);

        DB::table('customers')->where('business_id', $this->shop->id)->update(['phone' => '91234567']);
        CustomerPhone::forget($this->shop->id);

        $this->assertNotice(0, 'بلا إغلاق: لا أحدَ يحتاج مراجعة');

        $this->dismiss();
        $this->post(route('logout'));
        $this->signIn('owner@notice.test');
        $this->assertNotice(0, 'وبعد إغلاقٍ ودخول: ما زال العددُ صفرًا');
    }

    /* ═════════════ ولا يتعدّى صاحبَه ═════════════ */

    public function test_closing_it_touches_no_other_account_session_or_shop(): void
    {
        $colleague = $this->makeUser($this->shop, 'accountant@notice.test', 'accountant');
        $next = $this->makeShop('متجر الجار');
        $nextOwner = $this->makeUser($next, 'next@notice.test');
        $this->badPhones($next, 2);

        $this->signIn('owner@notice.test');
        $this->dismiss();
        $this->assertNotice(0);
        $mine = session()->all();

        // حسابٌ آخر في المتجر نفسِه، ومالكُ متجرٍ آخر — كلٌّ في جلسته
        foreach ([[$colleague, 3], [$nextOwner, 2]] as [$user, $count]) {
            $this->flushSession();
            $this->actingAs($user);
            $this->assertNotice($count, $user->email);
        }

        // والحسابُ نفسُه من جهازٍ آخر
        $this->flushSession();
        $this->actingAs($this->owner);
        $this->assertNotice(3, 'جلسةٌ أخرى للحساب نفسِه');

        // ولو بقيت الجلسةُ وتبدّل من فيها أو متجرُه: لا يرث إغلاقَ غيره
        foreach ([[$colleague, 3], [$nextOwner, 2]] as [$user, $count]) {
            $this->flushSession();
            $this->withSession(['phone_review_dismissed' => $mine['phone_review_dismissed']])->actingAs($user);
            $this->assertNotice($count, 'في جلسةٍ ورثها '.$user->email);
        }
        $this->owner->forceFill(['business_id' => $next->id])->save();
        $this->flushSession();
        $this->withSession(['phone_review_dismissed' => $mine['phone_review_dismissed']])->actingAs($this->owner->fresh());
        $this->assertNotice(2, 'الحسابُ نفسُه في نشاطٍ آخر');
    }

    public function test_a_shop_opened_tomorrow_has_it_with_nothing_to_set(): void
    {
        $new = $this->makeShop('متجرٌ جديد');
        $this->makeUser($new, 'new@notice.test');
        $this->badPhones($new, 1);

        $this->signIn('new@notice.test');
        $this->assertNotice(1);
        $this->dismiss()->assertRedirect(route('admin.dashboard'));
        $this->assertNotice(0);
    }

    /* ═════════════ ولا يمسّ قسمَ العملاء ولا بياناتٍ ═════════════ */

    public function test_the_customers_section_says_what_it_said(): void
    {
        $this->signIn('owner@notice.test');
        $read = fn (array $query = []) => collect($this->get(route('admin.customers.index', $query))->viewData('page')['props'])
            ->only(['phoneReview', 'customers', 'filters', 'pagination'])->all();
        $list = $read();
        $filtered = $read(['missing' => 'phone']);

        $this->dismiss();

        $this->assertSame(3, $list['phoneReview']);
        $this->assertCount(3, $filtered['customers']);
        $this->assertEquals($list, $read(), 'قائمةُ العملاء وتنبيهُها كما كانا');
        $this->assertEquals($filtered, $read(['missing' => 'phone']), 'ومُرشِّحُ المراجعة كما كان');
        $this->assertSame(3, CustomerPhone::reviewCount($this->shop->id), 'والعددُ نفسُه');
    }

    public function test_nothing_is_written_outside_the_session(): void
    {
        $this->signIn('owner@notice.test');
        $this->assertNotice(3);

        $tables = array_diff(
            array_column(Schema::getTables(), 'name'),
            ['sessions', 'cache', 'cache_locks', 'migrations', 'sqlite_sequence'],
        );
        $print = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => md5(json_encode(DB::table($t)->get()))])->all();
        $before = $print();

        $this->dismiss();
        $this->assertNotice(0);

        $this->assertSame($before, $print(), 'لا صفَّ تغيّر: لا إعدادَ نشاطٍ ولا عميلَ ولا سجلّ');
        $this->assertTrue(session()->has("phone_review_dismissed.{$this->shop->id}.{$this->owner->id}"), 'وهو في الجلسة لصاحبه في متجره');
    }

    /* ═════════════ والبابُ لمن يُعرض له التنبيه ═════════════ */

    public function test_the_door_is_for_whoever_is_shown_the_notice(): void
    {
        // زائر
        $this->dismiss()->assertRedirect(route('login'));

        // لوحةٌ بلا عملاء: لا يُعرض له فلا يُغلقه
        $this->actingAs($this->makeUser($this->shop, 'noc@notice.test', 'sales', ['dashboard', 'orders']));
        $this->dismiss()->assertForbidden();
        $this->assertFalse(session()->has('phone_review_dismissed'));

        // وعملاءُ بلا لوحة
        $this->flushSession();
        $this->actingAs($this->makeUser($this->shop, 'nod@notice.test', 'sales', ['customers']));
        $this->dismiss()->assertForbidden();
        $this->assertFalse(session()->has('phone_review_dismissed'));

        // ومن مُنحهما يُغلقه لنفسه
        $this->flushSession();
        $this->actingAs($this->makeUser($this->shop, 'both@notice.test', 'sales', ['dashboard', 'customers']));
        $this->assertNotice(3);
        $this->dismiss()->assertRedirect(route('admin.dashboard'));
        $this->assertNotice(0);
    }

    public function test_the_door_is_a_guarded_post(): void
    {
        $route = Route::getRoutes()->getByName('admin.dashboard.phoneReview.dismiss');
        $this->assertSame(['POST'], $route->methods());
        foreach (['web', 'auth', 'tenant', 'business', 'panel', 'ability'] as $guard) {
            $this->assertContains($guard, $route->gatherMiddleware());
        }
        $this->actingAs($this->owner)->get('/admin/dashboard/phone-review/dismiss')->assertStatus(405);

        // وبلا رمز الحماية يُردّ — والحارسُ مُطفأٌ في بيئة الاختبار وحدها
        $env = $this->app['env'];
        $this->app['env'] = 'staging';
        $this->dismiss()->assertStatus(419);
        $this->app['env'] = $env;
        $this->assertFalse(session()->has('phone_review_dismissed'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\GoogleBusinessAccount;
use App\Models\GoogleBusinessReview;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\GoogleBusiness;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * كلُّ تاجرٍ يربط Google بنفسه — ولا يمسّ ربطُ متجرٍ متجرًا آخر.
 *
 * بابان لا يختلطان:
 *
 * - **خرائط Google / Places** بمفتاح التاجر من مشروعه في Google Cloud — ولا
 *   مفتاحَ لأبعاد يقع على أحد (`GoogleMapsIsTheMerchantsOwnKeyTest` يحرس
 *   أكثره). وهنا ما أُضيف: اختبارُ الاتّصال، ومحوُ المفتاح من نصّ الخطأ،
 *   وألّا يبقى المفتاح في الجلسة حين يُرفض حقلٌ معه.
 *
 * - **ملفُّ الأعمال (التقييمات والردّ)** بإذن التاجر نفسِه عبر OAuth — بعميلٍ
 *   واحدٍ للمنصّة. وهنا: الحالةُ مربوطةٌ بالمتجر والمستخدم والوقت وتُستعمل
 *   مرّة، والموقعُ يُقرأ من Google لا من الطلب، والإذنُ المسحوبُ يُعاد بيد
 *   التاجر، والتقييمُ لا ينتقل بين متجرين.
 */
class AMerchantConnectsItsOwnGoogleTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE_A = 'ChIJ_PLACE_OF_SHOP_A_xxxxxx';

    private const ACCOUNT = 'accounts/111';

    private const LOCATION = 'locations/222';

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();
        Http::preventStrayRequests();

        config([
            'services.google_business.client_id' => 'platform-client-id',
            'services.google_business.client_secret' => 'platform-client-secret',
            'services.google_business.redirect' => 'https://platform.test/admin/integrations/google-business/callback',
        ]);

        [$this->a, $this->ownerA] = $this->shop('متجر أ', 'a@shop.test');
        [$this->b, $this->ownerB] = $this->shop('متجر ب', 'b@shop.test');

        $this->actingAs($this->ownerA);
    }

    /** @return array{0:Business, 1:User} */
    private function shop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner];
    }

    private function branchOf(Business $shop): Branch
    {
        return Branch::where('business_id', $shop->id)->firstOrFail();
    }

    private function connect(Business $shop, ?string $refresh = 'refresh-of-shop'): GoogleBusinessAccount
    {
        return GoogleBusinessAccount::create([
            'business_id' => $shop->id,
            'account_name' => self::ACCOUNT,
            'account_email' => 'owner@gmail.test',
            'access_token' => 'access-of-shop-'.$shop->id,
            'refresh_token' => $refresh ? $refresh.'-'.$shop->id : null,
            'token_expires_at' => now()->addHour(),
            'scopes' => GoogleBusiness::SCOPE,
            'linked_at' => now(),
        ]);
    }

    private function mapLocation(Branch $branch, string $location): BranchGooglePlace
    {
        return BranchGooglePlace::create([
            'branch_id' => $branch->id,
            'place_id' => '',
            'place_name' => '',
            'gbp_location' => $location,
            'gbp_linked_at' => now(),
            'linked_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function pending(string $state, ?int $business = null, ?int $user = null, ?int $at = null): array
    {
        return ['google_business_state' => [
            'state' => $state,
            'business' => $business ?? $this->a->id,
            'user' => $user ?? $this->ownerA->id,
            'at' => $at ?? now()->getTimestamp(),
        ]];
    }

    private function fakeTokenExchange(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 3600,
        ], 200)]);
    }

    /* ═══════════════ ١ · خرائط Google — اختبارُ الاتّصال بمفتاح المتجر وحده ═══════════════ */

    public function test_the_connection_test_uses_this_shops_stored_key_only(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-KEY-OF-A');
        GoogleReviews::storeKey($this->b->id, 'AIza-KEY-OF-B');
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'x']]], 200)]);

        $this->postJson(route('admin.integrations.google.key.test'))
            ->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(fn (Request $r) => $r->header('X-Goog-Api-Key') === ['AIza-KEY-OF-A']
            && str_contains($r->url(), 'places.googleapis.com/v1/places:searchText')
            && $r->header('X-Goog-FieldMask') === ['places.id']);
        Http::assertNotSent(fn (Request $r) => $r->header('X-Goog-Api-Key') === ['AIza-KEY-OF-B']);
    }

    public function test_a_pasted_key_is_tested_before_it_is_saved_and_not_stored(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-SAVED-OF-A');
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []], 200)]);

        $this->postJson(route('admin.integrations.google.key.test'), ['google_api_key' => 'AIza-PASTED-NEW'])
            ->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(fn (Request $r) => $r->header('X-Goog-Api-Key') === ['AIza-PASTED-NEW']);
        // الاختبارُ لا يحفظ: المحفوظ باقٍ كما كان
        $this->assertSame('AIza-SAVED-OF-A', GoogleReviews::apiKey($this->a->id));
    }

    public function test_a_shop_without_a_key_is_told_so_and_google_is_not_called(): void
    {
        // متجرُ ب له مفتاح — ولا يقع على أ
        GoogleReviews::storeKey($this->b->id, 'AIza-KEY-OF-B');
        Http::fake();

        $this->postJson(route('admin.integrations.google.key.test'))
            ->assertOk()->assertJson(['ok' => false]);

        Http::assertNothingSent();
    }

    public function test_the_key_never_comes_back_in_a_refusal(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-SECRET-OF-A');
        Http::fake(['places.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key AIza-SECRET-OF-A is not authorized for this API.'],
        ], 403)]);

        $body = $this->postJson(route('admin.integrations.google.key.test'))->assertOk()->getContent();

        $this->assertStringNotContainsString('AIza-SECRET-OF-A', $body);
        $this->assertStringContainsString('false', $body);
    }

    public function test_a_refused_key_field_is_not_kept_in_the_session(): void
    {
        $long = 'AIza-'.str_repeat('x', 300);

        $this->from(route('admin.integrations.google'))
            ->post(route('admin.integrations.google.key'), ['google_api_key' => $long])
            ->assertSessionHasErrors('google_api_key');

        $this->assertNull(session()->getOldInput('google_api_key'), 'بقي المفتاح نصًّا في الجلسة');
        $this->assertNull(MarketingSettings::group($this->a->id, 'google')['google_api_key'] ?? null ?: null);
    }

    /* ═══════════════ ٢ · المكانُ والإيصال — لكلّ متجرٍ مكانُه ═══════════════ */

    public function test_a_receipt_carries_its_own_shops_place_and_never_a_neighbours(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::PLACE_A, 'displayName' => ['text' => 'متجر أ'], 'rating' => 4.8, 'userRatingCount' => 12,
        ], 200)]);
        GoogleReviews::storeKey($this->a->id, 'AIza-KEY-OF-A');
        BranchGoogle::link($this->branchOf($this->a), self::PLACE_A);

        foreach ([$this->a, $this->b] as $shop) {
            MarketingSettings::save($shop->id, 'google', ['google_review_on_receipt' => true]);
        }

        $this->assertStringContainsString(self::PLACE_A, (string) GoogleReviews::onReceipt($this->a->id, $this->branchOf($this->a)->id));

        // متجرُ ب بلا مكان: لا رمز — لا مكانُ أ ولا مكانٌ للمنصّة
        $this->assertNull(GoogleReviews::onReceipt($this->b->id, $this->branchOf($this->b)->id));
        $this->assertNull(GoogleReviews::onReceipt($this->b->id));
        // ورقمُ فرعِ أ على ورقةِ ب لا يفتح مكانَ أ
        $this->assertNull(GoogleReviews::onReceipt($this->b->id, $this->branchOf($this->a)->id));
    }

    /* ═══════════════ ٣ · الإذن — يبدؤه التاجر، ويعود إلى متجره هو ═══════════════ */

    public function test_the_merchant_starts_the_authorisation_itself_and_the_state_names_its_shop(): void
    {
        $response = $this->get(route('admin.integrations.googleBusiness.connect'));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $response->headers->get('Location'));

        $pending = session('google_business_state');
        $this->assertIsArray($pending);
        $this->assertSame($this->a->id, $pending['business']);
        $this->assertSame($this->ownerA->id, $pending['user']);
        $this->assertStringContainsString('state='.$pending['state'], $response->headers->get('Location'));
    }

    public function test_a_callback_started_in_another_shop_is_refused(): void
    {
        $this->fakeTokenExchange();

        $this->withSession($this->pending('st', business: $this->b->id))
            ->get(route('admin.integrations.googleBusiness.callback', ['state' => 'st', 'code' => 'c']))
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(0, GoogleBusinessAccount::count(), 'حُفظ إذنُ متجرٍ على متجرٍ آخر');
    }

    public function test_a_callback_started_by_another_user_is_refused(): void
    {
        $this->fakeTokenExchange();

        $this->withSession($this->pending('st', user: $this->ownerB->id))
            ->get(route('admin.integrations.googleBusiness.callback', ['state' => 'st', 'code' => 'c']))
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(0, GoogleBusinessAccount::count());
    }

    public function test_a_stale_callback_is_refused(): void
    {
        $this->fakeTokenExchange();

        $this->withSession($this->pending('st', at: now()->subMinutes(11)->getTimestamp()))
            ->get(route('admin.integrations.googleBusiness.callback', ['state' => 'st', 'code' => 'c']))
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(0, GoogleBusinessAccount::count());
    }

    public function test_a_callback_cannot_be_replayed(): void
    {
        $this->fakeTokenExchange();

        $this->withSession($this->pending('st'))
            ->get(route('admin.integrations.googleBusiness.callback', ['state' => 'st', 'code' => 'c']))
            ->assertRedirect();

        $this->assertSame(1, GoogleBusinessAccount::where('business_id', $this->a->id)->count());
        $this->assertNull(session('google_business_state'), 'بقيت الحالةُ بعد استعمالها');

        // العنوانُ نفسُه مرّةً ثانية — لا حالةَ تنتظره
        $this->get(route('admin.integrations.googleBusiness.callback', ['state' => 'st', 'code' => 'c2']))->assertRedirect();
        Http::assertSentCount(1);
    }

    public function test_the_authorisation_lands_on_the_shop_that_asked_for_it(): void
    {
        $this->fakeTokenExchange();

        $this->withSession($this->pending('st'))
            ->get(route('admin.integrations.googleBusiness.callback', ['state' => 'st', 'code' => 'c']))
            ->assertRedirect();

        $this->assertNotNull(GoogleBusiness::for($this->a->id));
        $this->assertNull(GoogleBusiness::for($this->b->id));
    }

    /* ═══════════════ ٤ · الموقعُ من Google لا من الطلب ═══════════════ */

    private function fakeAccountLocations(): void
    {
        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response([
                'accounts' => [['name' => self::ACCOUNT, 'accountName' => 'حساب أ']],
            ], 200),
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'locations' => [['name' => self::LOCATION, 'title' => 'متجر أ', 'storefrontAddress' => ['addressLines' => ['الخوض']]]],
            ], 200),
        ]);
    }

    public function test_a_location_of_this_authorisation_is_linked(): void
    {
        $this->connect($this->a);
        $this->fakeAccountLocations();

        $this->post(route('admin.integrations.googleBusiness.branch.link', $this->branchOf($this->a)->id), [
            'location' => self::LOCATION, 'account' => self::ACCOUNT,
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::LOCATION, BranchGooglePlace::where('branch_id', $this->branchOf($this->a)->id)->value('gbp_location'));
    }

    public function test_a_location_google_did_not_list_for_this_authorisation_is_refused(): void
    {
        $this->connect($this->a);
        $this->fakeAccountLocations();

        $this->post(route('admin.integrations.googleBusiness.branch.link', $this->branchOf($this->a)->id), [
            'location' => 'locations/999-NOT-MINE', 'account' => self::ACCOUNT,
        ])->assertSessionHasErrors('location');

        $this->assertSame(0, BranchGooglePlace::whereNotNull('gbp_location')->count());
    }

    public function test_an_account_google_did_not_list_for_this_authorisation_is_refused(): void
    {
        $this->connect($this->a);
        $this->fakeAccountLocations();

        $this->post(route('admin.integrations.googleBusiness.branch.link', $this->branchOf($this->a)->id), [
            'location' => self::LOCATION, 'account' => 'accounts/OF-SOMEONE-ELSE',
        ])->assertSessionHasErrors('location');

        $this->assertSame(0, BranchGooglePlace::whereNotNull('gbp_location')->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'accounts/OF-SOMEONE-ELSE'));
    }

    /* ═══════════════ ٥ · التقييماتُ لمتجرها وحده ═══════════════ */

    public function test_one_shops_reviews_never_appear_in_another_shops_screen(): void
    {
        $this->connect($this->a);
        $this->connect($this->b);
        $this->mapLocation($this->branchOf($this->a), 'locations/A');

        GoogleBusinessReview::create([
            'branch_id' => $this->branchOf($this->a)->id, 'review_id' => 'rev-of-a', 'rating' => 2,
            'comment' => 'تعليقٌ على متجر أ وحده', 'reviewed_at' => now(), 'first_seen_at' => now(),
        ]);

        $this->actingAs($this->ownerB);
        $props = $this->get(route('admin.integrations.googleBusiness'))->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['reviews']);
        $this->assertStringNotContainsString('تعليقٌ على متجر أ وحده', json_encode($props, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_sync_never_moves_another_shops_review_into_this_shop(): void
    {
        $this->connect($this->a);
        $this->connect($this->b);
        $this->mapLocation($this->branchOf($this->b), 'locations/B');

        $theirs = GoogleBusinessReview::create([
            'branch_id' => $this->branchOf($this->a)->id, 'review_id' => 'shared-id', 'rating' => 5,
            'comment' => 'لمتجر أ', 'reviewed_at' => now(), 'first_seen_at' => now(),
        ]);

        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['reviews' => [[
            'reviewId' => 'shared-id', 'starRating' => 'ONE', 'comment' => 'كُتب فوقه',
            'createTime' => '2026-09-01T10:00:00Z',
        ]]], 200)]);

        $result = GoogleBusiness::syncReviews($this->branchOf($this->b));

        $this->assertTrue($result['ok']);
        $fresh = $theirs->fresh();
        $this->assertSame($this->branchOf($this->a)->id, $fresh->branch_id, 'انتقل تقييمُ متجرٍ إلى متجرٍ آخر');
        $this->assertSame('لمتجر أ', $fresh->comment);
        $this->assertSame(5, $fresh->rating);
    }

    /* ═══════════════ ٦ · إذنٌ انقطع — يُعيده التاجر بنفسه ═══════════════ */

    public function test_a_grant_google_withdrew_is_wiped_and_the_screen_offers_reconnect(): void
    {
        $account = $this->connect($this->a);
        $account->forceFill(['token_expires_at' => now()->subMinutes(5)])->save();

        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.',
        ], 400)]);

        $this->assertNull(GoogleBusiness::accessToken($account->fresh()));

        $raw = GoogleBusinessAccount::where('business_id', $this->a->id)->firstOrFail();
        $this->assertNull($raw->refresh_token, 'بقي رمزٌ لا يفتح شيئًا');
        $this->assertNull($raw->revoked_at, 'عُدّ فصلًا بيد التاجر');
        $this->assertTrue(GoogleBusiness::needsReconnect($this->a->id));

        $props = $this->get(route('admin.integrations.googleBusiness'))->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['connected']);
        $this->assertTrue($props['reconnect']);
        $this->assertNotNull($props['lastError']);

        // ومتجرٌ آخر لا يتأثّر
        $this->assertFalse(GoogleBusiness::needsReconnect($this->b->id));
    }

    public function test_a_passing_failure_keeps_the_grant(): void
    {
        $account = $this->connect($this->a);
        $account->forceFill(['token_expires_at' => now()->subMinutes(5)])->save();

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'server_error'], 503)]);

        $this->assertNull(GoogleBusiness::accessToken($account->fresh()));
        $this->assertNotNull(GoogleBusinessAccount::where('business_id', $this->a->id)->value('refresh_token'));
        $this->assertFalse(GoogleBusiness::needsReconnect($this->a->id));
    }

    public function test_a_merchants_own_disconnect_is_not_called_a_lost_grant(): void
    {
        $account = $this->connect($this->a);
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        GoogleBusiness::revoke($account);

        $this->assertFalse(GoogleBusiness::needsReconnect($this->a->id));
        $this->assertNull(GoogleBusiness::for($this->a->id));
    }

    /* ═══════════════ ٧ · المنصّة ترى الحال لا المفاتيح ═══════════════ */

    public function test_the_platform_sees_status_and_never_a_key_or_a_token(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-PRIVATE-KEY-OF-A-9z9z');
        $this->connect($this->a);
        $this->mapLocation($this->branchOf($this->a), self::LOCATION);

        $boss = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@platform.test',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $show = $this->actingAs($boss)->get(route('super-admin.businesses.show', $this->a->id))->assertOk();
        $google = $show->viewData('page')['props']['google'];

        $this->assertTrue($google['maps']['hasKey']);
        $this->assertTrue($google['business']['connected']);
        $this->assertSame(1, $google['business']['locations']);

        $settings = $this->actingAs($boss)->get(route('super-admin.settings.index'))->assertOk();
        $health = $settings->viewData('page')['props']['googleHealth'];
        $this->assertTrue($health['gbpConfigured']);
        $this->assertSame(1, $health['gbpConnected']);

        foreach ([$show, $settings] as $page) {
            $json = json_encode($page->viewData('page')['props']);
            foreach (['AIza-PRIVATE-KEY-OF-A-9z9z', '9z9z', 'access-of-shop-', 'refresh-of-shop-'] as $secret) {
                $this->assertStringNotContainsString($secret, $json, 'وصل المنصّةَ سرٌّ: '.$secret);
            }
        }
    }

    /* ═══════════════ ٨ · ولا اسمَ متجرٍ في الكود ═══════════════ */

    public function test_no_shop_is_named_in_the_google_code(): void
    {
        $files = [
            'app/Support/GoogleBusiness.php',
            'app/Support/GoogleReviews.php',
            'app/Support/GooglePlaces.php',
            'app/Http/Controllers/Admin/GoogleBusinessController.php',
            'app/Http/Controllers/Admin/IntegrationsController.php',
            'resources/js/Pages/Admin/Integrations/Google.tsx',
            'resources/js/Pages/Admin/Integrations/GoogleBusiness.tsx',
            'resources/js/Pages/Platform/Businesses/partials/GoogleCard.tsx',
        ];

        foreach ($files as $file) {
            $code = file_get_contents(base_path($file));

            $this->assertDoesNotMatchRegularExpression('/RIBBON|Ribbon\b/', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/\bsaud\b|سعود/iu', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/business_id\s*(===?|==)\s*\d+|businessId\s*===?\s*\d+/', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/AIza[0-9A-Za-z_-]{20,}/', $code, $file.' يحمل مفتاحًا');
        }
    }
}

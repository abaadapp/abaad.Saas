<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\GoogleBusinessAccount;
use App\Models\GoogleBusinessReview;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\Demo;
use App\Support\GoogleBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * كلُّ تاجرٍ يدير تقييمات Google بإذنه هو — بلا يدٍ لأبعاد ولا لجاره.
 *
 * ═══ ما كسر قبل هذا ═══
 *
 *  ١) تُقرأ الصفحةُ الأولى (٥٠ تقييمًا) ويُختم كلُّ ما عداها «اختفى».
 *  ٢) فرعٌ ثانٍ من حساب أعمالٍ آخر يكتب فوق `account_name` فيكسر الأوّل بصمت.
 *  ٣) لا سحبَ دوريّ، فلا يصل تنبيهٌ إلّا من ضغط الزرّ.
 *  ٤) مديرُ المنصّة يدخل باسم التاجر فيردّ على زبائنه.
 *  ٥) إذنٌ سُحب ورمزُه لم ينته يبقى «مربوطًا» يفشل بلا زرٍّ يُصلحه.
 */
class EveryMerchantRunsItsOwnGoogleReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEWS = 'mybusiness.googleapis.com/*';

    private const TOKEN_URL = 'oauth2.googleapis.com/token';

    private Business $shop;

    private Business $neighbour;

    private Branch $first;

    private Branch $second;

    private Branch $theirs;

    private User $owner;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();

        config([
            'services.google_business.client_id' => 'test-client-id',
            'services.google_business.client_secret' => 'test-client-secret',
            'services.google_business.redirect' => 'https://app.abaadapp.om/admin/integrations/google-business/callback',
        ]);

        [$this->shop, $this->owner] = $this->shopWithOwner('ورد أبعاد', 'o@abaadapp.om');
        [$this->neighbour, $this->otherOwner] = $this->shopWithOwner('ورد الجار', 'n@abaadapp.om');

        $this->first = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوض']);
        $this->second = Branch::create(['business_id' => $this->shop->id, 'name' => 'المعبيلة']);
        $this->theirs = Branch::create(['business_id' => $this->neighbour->id, 'name' => 'فرع الجار']);
    }

    /* ═══════════════ أدوات ═══════════════ */

    /** @return array{0: Business, 1: User} */
    private function shopWithOwner(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $shop->id, 'name' => 'مدير', 'role' => 'admin']);

        return [$shop, User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ])];
    }

    private function granted(Business $shop, ?string $accountName = 'accounts/1', string $access = 'access-a'): GoogleBusinessAccount
    {
        return GoogleBusinessAccount::create([
            'business_id' => $shop->id,
            'account_name' => $accountName,
            'access_token' => $access,
            'refresh_token' => 'refresh-'.$access,
            'token_expires_at' => now()->addHour(),
            'scopes' => GoogleBusiness::SCOPE,
            'linked_at' => now(),
        ]);
    }

    private function mapped(Branch $branch, string $location, ?string $account = 'accounts/1'): BranchGooglePlace
    {
        return BranchGooglePlace::create([
            'branch_id' => $branch->id,
            'place_id' => 'place-'.$branch->id,
            'place_name' => $branch->name,
            'gbp_location' => $location,
            'gbp_account' => $account,
            'gbp_linked_at' => now(),
            'linked_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function review(string $id, string $stars = 'FIVE', string $created = '2024-01-01T10:00:00Z'): array
    {
        return [
            'reviewId' => $id, 'starRating' => $stars, 'comment' => 'نصّ التقييم',
            'reviewer' => ['displayName' => 'زبونة'],
            'createTime' => $created, 'updateTime' => $created,
        ];
    }

    /**
     * صفحاتٌ كما تردّها Google: كلُّ صفحةٍ بالرمز الذي يصل إليها.
     *
     * @param  array<int, array<int, string>>  $pages  معرّفاتُ كلّ صفحة
     */
    private function fakePages(array $pages, ?int $total = null, array $failAt = []): void
    {
        $this->forgetFakes();

        Http::fake([self::REVIEWS => function (Request $request) use ($pages, $total, $failAt) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $index = isset($query['pageToken']) ? (int) substr((string) $query['pageToken'], 1) : 0;

            if (isset($failAt[$index])) {
                return Http::response(['error' => ['message' => 'boom']], $failAt[$index]);
            }

            return Http::response(array_filter([
                'reviews' => array_map(fn ($id) => $this->review($id), $pages[$index]),
                'nextPageToken' => isset($pages[$index + 1]) ? 'p'.($index + 1) : null,
                'totalReviewCount' => $total,
            ], fn ($v) => $v !== null), 200);
        }]);
    }

    /** `Http::fake` ثانيةً لا تلغي الأولى: أوّلُ نمطٍ مطابقٍ يفوز */
    private function forgetFakes(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /** @return array<int, string> */
    private function ids(string $prefix, int $from, int $to): array
    {
        return array_map(fn ($n) => $prefix.$n, range($from, $to));
    }

    /* ═══════════════ ١ · الصفحات ═══════════════ */

    /** مئةٌ وعشرون تقييمًا تُقرأ كلُّها، ولا يُختم منها واحد */
    public function test_every_page_is_read_and_nothing_past_the_first_is_called_gone(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        $this->fakePages([$this->ids('r', 1, 50), $this->ids('r', 51, 100), $this->ids('r', 101, 120)], 120);

        $this->assertTrue(GoogleBusiness::syncReviews($this->first)['ok']);
        $result = GoogleBusiness::syncReviews($this->first);

        $this->assertSame(120, $result['total']);
        $this->assertSame(120, GoogleBusinessReview::live()->count(), 'خُتم تقييمٌ قائمٌ لأنّه ليس في الصفحة الأولى');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageToken=p2'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageSize=50'));
    }

    /** وفشلُ صفحةٍ في الوسط لا يختم شيئًا، ويبقى ما قُرئ قبلها */
    public function test_a_failed_page_stamps_nothing(): void
    {
        $account = $this->granted($this->shop);
        $place = $this->mapped($this->first, 'locations/1');

        $this->fakePages([$this->ids('r', 1, 60)]);
        GoogleBusiness::syncReviews($this->first);
        $syncedAt = $place->fresh()->gbp_synced_at;
        $account->forceFill(['synced_at' => now()->subDay()])->save();
        $this->travel(5)->minutes();

        $this->fakePages([$this->ids('r', 1, 50), $this->ids('r', 51, 60)], null, [1 => 500]);
        $result = GoogleBusiness::syncReviews($this->first);

        $this->assertFalse($result['ok']);
        $this->assertSame(60, GoogleBusinessReview::live()->count(), 'خُتم تقييمٌ في مزامنةٍ لم تكتمل');
        $this->assertNotNull($place->fresh()->gbp_sync_error);
        $this->assertTrue($place->fresh()->gbp_synced_at->eq($syncedAt), 'قيل «آخر سحب» عن سحبٍ لم يكتمل');
        $this->assertTrue($account->fresh()->synced_at->lt(now()->subHour()));
    }

    /** ثمّ مزامنةٌ مكتملة تختم ما اختفى حقًّا، وتمحو خطأ الفرع */
    public function test_the_next_complete_sync_stamps_only_what_really_went(): void
    {
        $this->granted($this->shop);
        $place = $this->mapped($this->first, 'locations/1');

        $this->fakePages([$this->ids('r', 1, 60)]);
        GoogleBusiness::syncReviews($this->first);
        $this->fakePages([$this->ids('r', 1, 50)], null, [0 => 503]);
        GoogleBusiness::syncReviews($this->first);

        $this->fakePages([$this->ids('r', 1, 50), $this->ids('r', 51, 59)]);
        $this->assertTrue(GoogleBusiness::syncReviews($this->first)['ok']);

        $this->assertSame(59, GoogleBusinessReview::live()->count());
        $this->assertNotNull(GoogleBusinessReview::where('review_id', 'r60')->value('gone_at'));
        $this->assertNull($place->fresh()->gbp_sync_error);
    }

    /** ورمزُ صفحةٍ يعود نفسُه لا يدور أبدًا ولا يختم */
    public function test_a_repeating_page_token_ends_without_stamping(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        GoogleBusinessReview::create(['branch_id' => $this->first->id, 'review_id' => 'old', 'rating' => 5]);

        Http::fake([self::REVIEWS => Http::response([
            'reviews' => [$this->review('r1')], 'nextPageToken' => 'same',
        ], 200)]);

        $this->assertFalse(GoogleBusiness::syncReviews($this->first)['ok']);
        $this->assertNull(GoogleBusinessReview::where('review_id', 'old')->value('gone_at'));
        Http::assertSentCount(2);
    }

    /** وقراءةٌ أقلُّ ممّا تعدّه Google لا يُختم بها: الناقصُ لم يصل، لا أنّه اختفى */
    public function test_reading_fewer_than_google_counts_stamps_nothing(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        GoogleBusinessReview::create(['branch_id' => $this->first->id, 'review_id' => 'old', 'rating' => 5]);

        $this->fakePages([['r1']], 5);
        $this->assertTrue(GoogleBusiness::syncReviews($this->first)['ok']);
        $this->assertNull(GoogleBusinessReview::where('review_id', 'old')->value('gone_at'));

        $this->fakePages([[]], 3);
        GoogleBusiness::syncReviews($this->first);
        $this->assertNull(GoogleBusinessReview::where('review_id', 'old')->value('gone_at'), 'قائمةٌ فارغةٌ بعدٍّ قائمٍ محت المتجر كلَّه');
    }

    /* ═══════════════ ٢ · فرعان من حسابين ═══════════════ */

    /** لكلّ فرعٍ حسابُه: السحبُ والردُّ يذهبان إلى عنوانه هو */
    public function test_each_branch_speaks_through_its_own_business_account(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1', 'accounts/1');
        $this->mapped($this->second, 'locations/2', 'accounts/2');

        $this->fakePages([['r1']]);
        GoogleBusiness::syncReviews($this->second);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/2/locations/2/reviews'));

        $review = GoogleBusinessReview::where('review_id', 'r1')->firstOrFail();
        $this->forgetFakes();
        Http::fake([self::REVIEWS => Http::response(['comment' => 'شكرًا', 'updateTime' => '2026-11-01T00:00:00Z'], 200)]);

        $this->assertTrue(GoogleBusiness::reply($review, 'شكرًا')['ok']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'accounts/2/locations/2/reviews/r1/reply'));
    }

    /** وربطُ فرعٍ من حسابٍ آخر لا يكسر فرعًا قديمًا يقرأ حسابَ المتجر */
    public function test_linking_a_second_account_does_not_break_an_older_branch(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1', null);

        GoogleBusiness::linkLocation($this->second, 'locations/2', 'accounts/2');

        $this->assertSame('accounts/1', GoogleBusinessAccount::where('business_id', $this->shop->id)->value('account_name'));
        $this->assertSame('accounts/2', BranchGooglePlace::where('branch_id', $this->second->id)->value('gbp_account'));

        $this->fakePages([['r1']]);
        GoogleBusiness::syncReviews($this->first);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts/1/locations/1/reviews'));
    }

    /** والحساباتُ والمواقعُ صفحاتٌ أيضًا: موقعٌ في الصفحة الثانية يُربط */
    public function test_a_location_on_a_later_page_can_be_linked(): void
    {
        $this->granted($this->shop);

        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/*' => Http::sequence()
                ->push(['accounts' => [['name' => 'accounts/1', 'accountName' => 'أ']], 'nextPageToken' => 'a1'], 200)
                ->push(['accounts' => [['name' => 'accounts/2', 'accountName' => 'ب']]], 200),
            'mybusinessbusinessinformation.googleapis.com/*' => Http::sequence()
                ->push(['locations' => [['name' => 'locations/7', 'title' => 'سابع']], 'nextPageToken' => 'l1'], 200)
                ->push(['locations' => [['name' => 'locations/8', 'title' => 'ثامن']]], 200),
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.integrations.googleBusiness.branch.link', $this->first->id), [
                'location' => 'locations/8', 'account' => 'accounts/2',
            ])->assertSessionHasNoErrors();

        $place = BranchGooglePlace::where('branch_id', $this->first->id)->firstOrFail();
        $this->assertSame('accounts/2', $place->gbp_account);
        $this->assertSame('locations/8', $place->gbp_location);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageToken=a1'));
    }

    /** وموقعٌ مكانُه على الخرائط غيرُ مكان الفرع يُسأل عنه قبل الربط */
    public function test_a_location_on_another_place_needs_confirmation(): void
    {
        $this->granted($this->shop);
        BranchGooglePlace::create([
            'branch_id' => $this->first->id, 'place_id' => 'place-mine', 'place_name' => 'الخوض', 'linked_at' => now(),
        ]);

        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/1']]], 200),
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['locations' => [
                ['name' => 'locations/9', 'title' => 'موقع', 'metadata' => ['placeId' => 'place-other']],
            ]], 200),
        ]);

        $url = route('admin.integrations.googleBusiness.branch.link', $this->first->id);
        $data = ['location' => 'locations/9', 'account' => 'accounts/1'];

        $this->actingAs($this->owner)->post($url, $data)->assertSessionHasErrors('location');
        $this->assertNull(BranchGooglePlace::where('branch_id', $this->first->id)->value('gbp_location'));

        $this->actingAs($this->owner)->post($url, $data + ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('locations/9', BranchGooglePlace::where('branch_id', $this->first->id)->value('gbp_location'));
    }

    /** وتعثُّرُ فرعٍ يُقال عليه وحده، وجارُه في المتجر سليم */
    public function test_one_branchs_failure_stays_on_that_branch(): void
    {
        $this->granted($this->shop);
        $good = $this->mapped($this->first, 'locations/1');
        $bad = $this->mapped($this->second, 'locations/2');

        Http::fake([self::REVIEWS => fn (Request $r) => str_contains($r->url(), 'locations/2')
            ? Http::response(['error' => ['message' => 'not found']], 404)
            : Http::response(['reviews' => [$this->review('r1')]], 200)]);

        GoogleBusiness::syncReviews($this->first);
        GoogleBusiness::syncReviews($this->second);

        $this->assertNull($good->fresh()->gbp_sync_error);
        $this->assertNotNull($good->fresh()->gbp_synced_at);
        $this->assertNotNull($bad->fresh()->gbp_sync_error);
        $this->assertNull($bad->fresh()->gbp_synced_at);
    }

    /* ═══════════════ ٣ · متجران مستقلّان ═══════════════ */

    /** لكلّ متجرٍ رمزُه وتقييماتُه، ولا يصل أحدُهما إلى الآخر */
    public function test_two_shops_share_nothing(): void
    {
        $this->granted($this->shop, 'accounts/1', 'access-a');
        $this->granted($this->neighbour, 'accounts/9', 'access-b');
        $this->mapped($this->first, 'locations/1');
        $this->mapped($this->theirs, 'locations/9', 'accounts/9');

        Http::fake([self::REVIEWS => fn (Request $r) => Http::response([
            'reviews' => [$this->review(str_contains($r->url(), 'locations/9') ? 'theirs-1' : 'mine-1')],
        ], 200)]);

        GoogleBusiness::syncReviews($this->first);
        GoogleBusiness::syncReviews($this->theirs);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'locations/1') && $r->hasHeader('Authorization', 'Bearer access-a'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'locations/9') && $r->hasHeader('Authorization', 'Bearer access-b'));

        $theirs = GoogleBusinessReview::where('review_id', 'theirs-1')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.integrations.googleBusiness.reply', $theirs->id), ['comment' => 'تجاوز'])
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('admin.integrations.googleBusiness.branch.sync', $this->theirs->id))
            ->assertNotFound();

        $this->actingAs($this->owner)->get(route('admin.integrations.googleBusiness', ['branch' => $this->theirs->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reviews', [])->etc());

        $this->actingAs($this->owner)->get(route('admin.integrations.googleBusiness'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reviews', 1)->where('reviews.0.branch', $this->first->name)->etc());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    /* ═══════════════ ٤ · انقطاع الإذن ═══════════════ */

    /** إذنٌ سحبه صاحبُه ورمزُه لم ينته: يُمحى ويُعرض «أعِد الربط»، ولا يُختم شيء */
    public function test_a_grant_withdrawn_at_google_turns_into_reconnect(): void
    {
        $account = $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        GoogleBusinessReview::create(['branch_id' => $this->first->id, 'review_id' => 'old', 'rating' => 5]);

        Http::fake([
            self::REVIEWS => Http::response(['error' => ['message' => 'unauthenticated']], 401),
            self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->assertFalse(GoogleBusiness::syncReviews($this->first)['ok']);

        $fresh = $account->fresh();
        $this->assertNull($fresh->refresh_token, 'بقي رمزٌ لا يفتح شيئًا');
        $this->assertNull($fresh->access_token);
        $this->assertSame(GoogleBusiness::RECONNECT, GoogleBusiness::state($this->shop->id)['state']);
        $this->assertNull(GoogleBusinessReview::where('review_id', 'old')->value('gone_at'));
    }

    /** ورمزٌ رُدّ وتجديدُه ناجح: يُعاد النداء مرّةً ويكمل، ولو في منتصف الصفحات */
    public function test_a_refused_access_token_is_renewed_once_mid_sync(): void
    {
        $account = $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        Http::fake([
            self::REVIEWS => Http::sequence()
                ->push(['reviews' => [$this->review('r1')], 'nextPageToken' => 'p1'], 200)
                ->push(['error' => ['message' => 'expired']], 401)
                ->push(['reviews' => [$this->review('r2')]], 200),
            self::TOKEN_URL => Http::response(['access_token' => 'access-new', 'expires_in' => 3600], 200),
        ]);

        $this->assertTrue(GoogleBusiness::syncReviews($this->first)['ok']);
        $this->assertSame('access-new', $account->fresh()->access_token);
        $this->assertSame(2, GoogleBusinessReview::live()->count());
    }

    /** وإذنٌ أُلغيت منه خانةُ إدارة الملفّ لا يُحفظ */
    public function test_a_grant_without_the_management_scope_is_not_stored(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'openid email',
        ], 200)]);

        $this->actingAs($this->owner)
            ->withSession(['google_business_state' => [
                'state' => 'word', 'business' => $this->shop->id, 'user' => $this->owner->id, 'at' => now()->getTimestamp(),
            ]])
            ->get(route('admin.integrations.googleBusiness.callback', ['state' => 'word', 'code' => 'c']))
            ->assertRedirect();

        $this->assertSame(0, GoogleBusinessAccount::count());
    }

    /* ═══════════════ ٥ · الحالات ═══════════════ */

    /** حصّةٌ صفرٌ وواجهةٌ معطَّلة عند أبعاد: لا يُطلب من التاجر شيء، ويزول بأوّل نجاح */
    public function test_an_unapproved_project_is_told_apart_from_a_shops_problem(): void
    {
        $account = $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        Http::fake([self::REVIEWS => Http::response(['error' => [
            'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded',
            'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'RATE_LIMIT_EXCEEDED',
                'metadata' => ['quota_limit_value' => '0'],
            ]],
        ]], 429)]);

        $result = GoogleBusiness::syncReviews($this->first);

        $this->assertStringContainsString('ليس عليك شيء', (string) $result['error']);
        $this->assertSame(GoogleBusiness::API_BLOCKED, GoogleBusiness::state($this->shop->id)['state']);
        $this->assertNotNull($account->fresh()->refresh_token, 'مُحي إذنُ التاجر لعطبٍ عند أبعاد');

        $this->fakePages([['r1']]);
        GoogleBusiness::syncReviews($this->first);

        $this->assertNull($account->fresh()->api_blocked_at);
        $this->assertSame(GoogleBusiness::READY, GoogleBusiness::state($this->shop->id)['state']);
    }

    public function test_a_disabled_api_is_the_same_state(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        Http::fake([self::REVIEWS => Http::response(['error' => [
            'code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'denied',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED']],
        ]], 403)]);

        GoogleBusiness::syncReviews($this->first);

        $this->assertSame(GoogleBusiness::API_BLOCKED, GoogleBusiness::state($this->shop->id)['state']);
    }

    /** وزحامٌ عابرٌ بحصّةٍ قائمةٍ أو رفضُ موقعٍ بعينه ليس «لم يُعتمد» */
    public function test_a_busy_quota_or_a_refused_location_is_not_unapproved(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        Http::fake([self::REVIEWS => Http::sequence()
            ->push(['error' => ['message' => 'slow down', 'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'metadata' => ['quota_limit_value' => '300'],
            ]]]], 429)
            ->push(['error' => ['message' => 'The caller does not have permission']], 403)]);

        GoogleBusiness::syncReviews($this->first);
        $this->assertSame(GoogleBusiness::READY, GoogleBusiness::state($this->shop->id)['state']);

        GoogleBusiness::syncReviews($this->first);
        $this->assertSame(GoogleBusiness::READY, GoogleBusiness::state($this->shop->id)['state']);
    }

    /** الرحلةُ بحالاتها — ومفتاحُ الخرائط لا يقوم مقام الإذن */
    public function test_the_journey_reads_each_state(): void
    {
        $id = $this->shop->id;

        config(['services.google_business.client_id' => null]);
        $this->assertSame(GoogleBusiness::UNAVAILABLE, GoogleBusiness::state($id)['state']);
        config(['services.google_business.client_id' => 'test-client-id']);

        BranchGooglePlace::create([
            'branch_id' => $this->first->id, 'place_id' => 'place-1', 'place_name' => 'الخوض', 'linked_at' => now(),
        ]);
        $off = GoogleBusiness::state($id);
        $this->assertSame(GoogleBusiness::OFF, $off['state'], 'عُدّ ربطُ الخرائط إذنًا بإدارة التقييمات');
        $this->assertTrue($off['maps']);

        $account = $this->granted($this->shop);
        $this->assertSame(GoogleBusiness::LINK_BRANCHES, GoogleBusiness::state($id)['state']);

        GoogleBusiness::linkLocation($this->first, 'locations/1', 'accounts/1');
        $this->assertSame(GoogleBusiness::READY, GoogleBusiness::state($id)['state']);

        $account->forceFill(['refresh_token' => null])->save();
        $this->assertSame(GoogleBusiness::RECONNECT, GoogleBusiness::state($id)['state']);
    }

    /** وربطُ ملفّ الأعمال لفرعٍ بلا خرائط لا يُحسب ربطَ خرائط */
    public function test_a_business_location_alone_is_not_a_maps_link(): void
    {
        $this->granted($this->shop);
        GoogleBusiness::linkLocation($this->second, 'locations/2', 'accounts/1');

        $this->assertNull(BranchGoogle::for($this->second), 'عُدّ فرعٌ بلا معرّف مكانٍ مربوطًا على الخرائط');
        $this->assertFalse(GoogleBusiness::state($this->shop->id)['maps']);
    }

    /* ═══════════════ ٦ · مَن يصل ═══════════════ */

    /** مديرُ المنصّة الداخلُ باسم التاجر لا يقرأ ولا يردّ ولا يربط ولا يفصل */
    public function test_a_platform_admin_signed_in_as_the_owner_reaches_nothing(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        $review = GoogleBusinessReview::create(['branch_id' => $this->first->id, 'review_id' => 'r1', 'rating' => 1, 'first_seen_at' => now()]);

        $this->actingAs($this->owner)->withSession(['impersonator_id' => $this->otherOwner->id]);

        $this->get(route('admin.integrations.googleBusiness'))->assertForbidden();
        $this->get(route('admin.integrations.googleBusiness.connect'))->assertForbidden();
        $this->post(route('admin.integrations.googleBusiness.reply', $review->id), ['comment' => 'باسمه'])->assertForbidden();
        $this->post(route('admin.integrations.googleBusiness.branch.sync', $this->first->id))->assertForbidden();
        $this->post(route('admin.integrations.googleBusiness.locations'))->assertForbidden();
        $this->post(route('admin.integrations.googleBusiness.alerts'), ['gbp_low_rating' => 2])->assertForbidden();
        $this->delete(route('admin.integrations.googleBusiness.disconnect'))->assertForbidden();

        $this->assertNotNull(GoogleBusinessAccount::firstOrFail()->refresh_token);
        Http::assertNothingSent();

        $keys = collect(Demo::notifications())->pluck('key')->filter(fn ($k) => str_starts_with((string) $k, 'gbp-review-'));
        $this->assertCount(0, $keys, 'وصل مديرَ المنصّة تنبيهٌ بتقييم متجر');
    }

    /** وما يراه في لوحته حالٌ وأعداد: لا رمزَ ولا نصَّ خطأٍ ولا كلامَ زبون */
    public function test_the_platform_view_is_diagnostic_only(): void
    {
        $this->granted($this->shop, 'accounts/1', 'very-secret-access');
        $this->mapped($this->first, 'locations/1');
        GoogleBusinessAccount::where('business_id', $this->shop->id)->update(['last_error' => 'سرّ الخطأ']);

        $status = GoogleBusiness::status($this->shop->id);

        $this->assertSame(
            ['state', 'connected', 'reconnect', 'apiBlocked', 'locations', 'syncedAt', 'error'],
            array_keys($status),
        );
        $this->assertTrue($status['error']);
        $this->assertStringNotContainsString('very-secret-access', (string) json_encode($status));
        $this->assertStringNotContainsString('سرّ الخطأ', (string) json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /** ومن لا يملك قسم التكاملات لا يصل، والمقيَّدُ بفرعٍ يرى فرعَه وحده */
    public function test_staff_see_only_what_they_were_given(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        $this->mapped($this->second, 'locations/2');

        $mine = GoogleBusinessReview::create(['branch_id' => $this->first->id, 'review_id' => 'r1', 'rating' => 5, 'reviewed_at' => now(), 'first_seen_at' => now()]);
        $other = GoogleBusinessReview::create(['branch_id' => $this->second->id, 'review_id' => 'r2', 'rating' => 1, 'reviewed_at' => now(), 'first_seen_at' => now()]);

        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard'],
        ]);
        $this->actingAs($cashier)->get(route('admin.integrations.googleBusiness'))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.integrations.googleBusiness.reply', $mine->id), ['comment' => 'أهلًا'])->assertForbidden();

        $limited = User::create([
            'business_id' => $this->shop->id, 'name' => 'مدير فرع', 'email' => 'b@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'integrations'],
        ]);
        $limited->branches()->attach($this->first->id);

        $this->actingAs($limited)->get(route('admin.integrations.googleBusiness'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reviews', 1)->where('reviews.0.id', $mine->id)
                ->has('branches', 1)->where('branches.0.id', $this->first->id)->etc());

        $this->actingAs($limited)->post(route('admin.integrations.googleBusiness.reply', $other->id), ['comment' => 'أهلًا'])->assertNotFound();
        $this->actingAs($limited)->post(route('admin.integrations.googleBusiness.branch.sync', $this->second->id))->assertNotFound();
        $this->actingAs($limited)->delete(route('admin.integrations.googleBusiness.disconnect'))->assertForbidden();
        $this->actingAs($limited)->get(route('admin.integrations.googleBusiness.connect'))->assertForbidden();

        $keys = collect(Demo::notifications())->pluck('key');
        $this->assertTrue($keys->contains('gbp-review-'.$mine->id));
        $this->assertFalse($keys->contains('gbp-review-'.$other->id), 'نُبّه مديرُ فرعٍ بتقييم فرعٍ ليس له');

        Http::assertNothingSent();
    }

    /** وتقييمٌ اختفى من Google لا يُردّ عليه */
    public function test_a_gone_review_cannot_be_answered(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        $gone = GoogleBusinessReview::create([
            'branch_id' => $this->first->id, 'review_id' => 'r1', 'rating' => 1, 'gone_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.integrations.googleBusiness.reply', $gone->id), ['comment' => 'أهلًا'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /* ═══════════════ ٧ · السحبُ الدوريّ والتنبيه ═══════════════ */

    /** يقرأ لكلّ متجرٍ، ويتخطّى الموقوف والمفصول، ولا ينشر ردًّا أبدًا */
    public function test_the_scheduled_sync_reads_every_shop_and_writes_nothing_to_google(): void
    {
        $this->allowIntegrations();
        $this->granted($this->shop);
        $this->granted($this->neighbour, 'accounts/9', 'access-b');
        $this->mapped($this->first, 'locations/1');
        $this->mapped($this->theirs, 'locations/9', 'accounts/9');

        $suspended = Business::create(['name' => 'موقوف', 'type' => 'عام', 'status' => 'موقوف']);
        $branch = Branch::create(['business_id' => $suspended->id, 'name' => 'فرع']);
        $this->granted($suspended, 'accounts/5', 'access-s');
        $this->mapped($branch, 'locations/5', 'accounts/5');

        $unplugged = Business::create(['name' => 'مفصول', 'type' => 'عام', 'status' => 'نشط']);
        $gone = Branch::create(['business_id' => $unplugged->id, 'name' => 'فرع']);
        $this->granted($unplugged, 'accounts/6', 'access-u')->forceFill(['revoked_at' => now(), 'refresh_token' => null])->save();
        $this->mapped($gone, 'locations/6', 'accounts/6');

        Http::fake([self::REVIEWS => fn (Request $r) => str_contains($r->url(), 'locations/1')
            ? Http::response(['error' => ['message' => 'boom']], 500)
            : Http::response(['reviews' => [$this->review('theirs-1')]], 200)]);

        $this->assertSame(0, Artisan::call('google:sync-reviews'));

        // فشلُ الأوّل لم يوقف الثاني
        $this->assertSame(1, GoogleBusinessReview::where('branch_id', $this->theirs->id)->count());

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'locations/5') || str_contains($r->url(), 'locations/6'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    /** ومتجرٌ عطّلته حالةُ «لم يُعتمد» لا يُسأل كلَّ ساعة، ويُعاد بعد ست ساعات */
    public function test_an_unapproved_project_is_not_hammered_hourly(): void
    {
        $this->allowIntegrations();
        $this->granted($this->shop)->forceFill(['api_blocked_at' => now()->subHour()])->save();
        $this->mapped($this->first, 'locations/1');
        $this->fakePages([['r1']]);

        Artisan::call('google:sync-reviews');
        Http::assertNothingSent();

        GoogleBusinessAccount::query()->update(['api_blocked_at' => now()->subHours(7)]);
        Artisan::call('google:sync-reviews');
        Http::assertSentCount(1);
    }

    /** ومتجرٌ لا تشمل باقتُه التكاملات لا يُسأل عنه */
    public function test_a_plan_without_integrations_is_not_read(): void
    {
        $this->allowIntegrations(['marketing']);
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');
        $this->fakePages([['r1']]);

        Artisan::call('google:sync-reviews');

        Http::assertNothingSent();
    }

    /** وبلا عميل OAuth لا يُنادى أحد */
    public function test_nothing_is_called_while_unconfigured(): void
    {
        config(['services.google_business.client_secret' => null]);
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        $this->assertSame(0, Artisan::call('google:sync-reviews'));
        Http::assertNothingSent();
    }

    /** وأوّلُ سحبٍ لا يقول عن تقييمٍ قديمٍ «جديد»، وما يأتي بعده يصل الجرس */
    public function test_only_what_is_really_new_reaches_the_bell(): void
    {
        $this->allowIntegrations();
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        $this->fakePages([$this->ids('old', 1, 60)]);
        Artisan::call('google:sync-reviews');

        $this->actingAs($this->owner);
        $this->assertSame([], $this->bell(), 'انهال الجرسُ بتقييماتٍ قديمةٍ يوم الربط');

        $this->forgetFakes();
        Http::fake([self::REVIEWS => Http::response(['reviews' => [
            $this->review('low-1', 'ONE', now()->toIso8601String()),
            ...array_map(fn ($id) => $this->review($id), $this->ids('old', 1, 60)),
        ]], 200)]);
        Artisan::call('google:sync-reviews');

        $bell = $this->bell();
        $this->assertCount(1, $bell);
        $this->assertSame('danger', $bell[0]['color']);
    }

    /* ═══════════════ ٨ · الشاشة ═══════════════ */

    /** التقييماتُ صفحاتٌ بلا سقف، والمرشِّحُ في الخادم */
    public function test_the_screen_pages_and_filters_every_review(): void
    {
        $this->granted($this->shop);
        $this->mapped($this->first, 'locations/1');

        foreach (range(1, 60) as $n) {
            GoogleBusinessReview::create([
                'branch_id' => $this->first->id, 'review_id' => 'r'.$n, 'rating' => $n % 5 + 1,
                'reviewed_at' => now()->subMinutes($n), 'reply' => $n % 2 ? 'ردّ' : null,
            ]);
        }

        $this->actingAs($this->owner)->get(route('admin.integrations.googleBusiness', ['page' => 3]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reviews', 10)
                ->where('pagination.total', 60)->where('pagination.last_page', 3)->etc());

        $this->actingAs($this->owner)->get(route('admin.integrations.googleBusiness', ['stars' => 2, 'unanswered' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pagination.total', 6)
                ->where('filters.stars', 2)->where('filters.unanswered', true)->etc());
    }

    /** الجرسُ لهذا المتجر — تقييماتُ Google وحدها @return list<array<string, mixed>> */
    private function bell(): array
    {
        return collect(Demo::notifications())
            ->filter(fn ($i) => str_starts_with((string) ($i['key'] ?? ''), 'gbp-review-'))
            ->values()->all();
    }

    /** باقةٌ بقدراتها — الأمرُ الدوريّ يسأل عنها */
    private function allowIntegrations(array $capabilities = ['integrations']): void
    {
        $plan = Plan::create([
            'name' => 'باقة', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => $capabilities,
        ]);

        Business::query()->update(['plan_id' => $plan->id]);
    }
}

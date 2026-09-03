<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سحبُ تقييمات Google — بمفتاح Places لا بموافقةٍ على النشاط.
 *
 * وما يحرسه هذا الملفّ ثلاثةٌ يتشابه ظاهرها كلَّه على الشاشة: محلٌّ غير مربوط،
 * ومفتاحٌ غائب، ورفضٌ من Google. ولو ردّت الثلاثةُ قائمةً فارغة لَقرأ التاجر
 * «لا تقييمات» في مواقفَ ثلاثةٍ اثنان منها ليسا عن تقييماته أصلًا.
 *
 * وحارسان لا يُرى أثرهما إلّا بعد شهور: **المفتاح لا يخرج إلى الشاشة**، و**ذاكرةُ
 * المسحوب تسقط مع المعرّف** — فمن بدّل ارتباطه لا يبقى يعرض تقييمات محلٍّ آخر.
 */
class GoogleReviewPullTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private const OTHER = 'ChIJrTLr_LlXwokRBiuGZuRoGaB';

    private const KEY = 'AIzaSyTESTKEY0000000000000000000000';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function link(string $placeId = self::PLACE): void
    {
        MarketingSettings::save($this->business->id, 'google', [
            'google_place_id' => $placeId,
            'google_maps_url' => $placeId,
        ]);
    }

    /** ردٌّ كما ترسله Google — بحقولها هي، لا بحقولنا */
    private function fakeOk(int $count = 128, float $rating = 4.6): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->body($count, $rating), 200)]);
    }

    /** جسدُ الردّ وحدَه — لمن يحتاج ردَّين متتاليَين مختلفَين */
    private function body(int $count = 128, float $rating = 4.6): array
    {
        return [
            'id' => self::PLACE,
            'displayName' => ['text' => 'محلّ الورد'],
            'rating' => $rating,
            'userRatingCount' => $count,
            'googleMapsUri' => 'https://maps.google.com/?cid=1',
            'reviews' => [[
                'name' => 'places/x/reviews/abc',
                'rating' => 5,
                'text' => ['text' => 'باقةٌ جميلة ووصلت في وقتها'],
                'relativePublishTimeDescription' => 'قبل أسبوع',
                'publishTime' => '2026-08-20T10:00:00Z',
                'authorAttribution' => ['displayName' => 'سالم', 'photoUri' => 'https://lh3.google.com/a'],
            ]],
        ];
    }

    /* ========================= المواقف الأربعة ========================= */

    public function test_a_shop_with_no_place_id_is_told_so_not_shown_zero_reviews(): void
    {
        GoogleReviews::storeKey($this->business->id, self::KEY);

        $this->assertSame('unlinked', GoogleReviews::pull($this->business->id)['state']);
    }

    public function test_a_linked_shop_without_a_key_is_told_the_key_is_missing(): void
    {
        $this->link();

        $this->assertSame('nokey', GoogleReviews::pull($this->business->id)['state']);
    }

    public function test_it_pulls_the_rating_the_count_and_the_texts(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        $this->fakeOk();

        $pulled = GoogleReviews::pull($this->business->id);

        $this->assertSame('ok', $pulled['state']);
        $this->assertSame(4.6, $pulled['place']['rating']);
        $this->assertSame(128, $pulled['place']['count']);
        $this->assertSame('محلّ الورد', $pulled['place']['name']);
        $this->assertCount(1, $pulled['place']['reviews']);
        $this->assertSame('سالم', $pulled['place']['reviews'][0]['author']);
        $this->assertStringContainsString('وصلت في وقتها', $pulled['place']['reviews'][0]['text']);
    }

    public function test_the_key_travels_in_the_header_never_in_the_url(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        $this->fakeOk();

        GoogleReviews::pull($this->business->id);

        Http::assertSent(function ($request) {
            // مفتاحٌ في العنوان يُكتب في سجلّات الوسطاء كلّها
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return $request->header('X-Goog-Api-Key')[0] === self::KEY
                && str_contains($request->url(), self::PLACE);
        });
    }

    /* =========================== حين ترفض Google =========================== */

    public function test_a_refused_key_says_what_to_fix_not_just_the_number(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        Http::fake(['places.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key not valid'],
        ], 403)]);

        $pulled = GoogleReviews::pull($this->business->id);

        $this->assertSame('error', $pulled['state']);
        $this->assertStringContainsString('Places API', $pulled['error']);
    }

    public function test_a_refusal_is_not_kept_in_memory(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push([], 403)
            ->push($this->body(), 200)]);

        $this->assertSame('error', GoogleReviews::pull($this->business->id)['state']);

        /*
         * ومفتاحٌ صُحِّح في Google Cloud يعمل في اللحظة. فحفظُ الرفض ستَّ
         * ساعاتٍ يجعل التاجر يصحّح ثمّ يرى الرفضَ نفسه فيظنّ أنّه لم يُصلح.
         */
        $this->assertSame('ok', GoogleReviews::pull($this->business->id)['state']);
    }

    /* ============================ الذاكرة ============================ */

    public function test_a_second_read_does_not_call_google_again(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        $this->fakeOk();

        GoogleReviews::pull($this->business->id);
        GoogleReviews::pull($this->business->id);

        // كلُّ نداءٍ مدفوعٌ من حساب التاجر — وفتحُ الصفحة ليس سببًا للدفع
        Http::assertSentCount(1);
    }

    public function test_refresh_asks_google_again(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        $this->fakeOk();

        GoogleReviews::pull($this->business->id);
        $this->post(route('admin.marketing.google.refresh'))->assertRedirect();

        Http::assertSentCount(2);
    }

    public function test_changing_the_place_drops_what_was_pulled_for_the_old_one(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->body(count: 128), 200)
            ->push($this->body(count: 7), 200)]);

        GoogleReviews::pull($this->business->id);

        // ربطٌ جديد — ومحلٌّ آخر لا يرث تقييمات الأوّل
        $this->post(route('admin.marketing.google.save'), ['google_maps_url' => self::OTHER])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, GoogleReviews::pull($this->business->id)['place']['count']);
    }

    public function test_relinking_the_same_place_asks_google_again(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->body(count: 128), 200)
            ->push($this->body(count: 140), 200)]);

        GoogleReviews::pull($this->business->id);

        /*
         * ومن فكّ ربطه ثمّ أعاده يقصد أن يرى ما عند Google الآن.
         *
         * ولولا إسقاطُ القديم قبل الكتابة لَقرأ ما كان قبل ستّ ساعات، وظنّ
         * أنّ إعادة الربط لم تصنع شيئًا.
         */
        $this->post(route('admin.marketing.google.save'), ['google_maps_url' => self::OTHER]);
        $this->post(route('admin.marketing.google.save'), ['google_maps_url' => self::PLACE]);

        $this->assertSame(140, GoogleReviews::pull($this->business->id)['place']['count']);
    }

    public function test_one_shops_pull_never_reaches_another(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        $this->fakeOk();
        GoogleReviews::pull($this->business->id);

        $other = Business::create(['name' => 'جارتي', 'type' => 'عام', 'status' => 'نشط']);

        $this->assertSame('unlinked', GoogleReviews::pull($other->id)['state']);
    }

    /* ============================ المفتاح ============================ */

    public function test_the_key_is_stored_encrypted_and_reads_back(): void
    {
        GoogleReviews::storeKey($this->business->id, self::KEY);

        $raw = MarketingSettings::group($this->business->id, 'google')['google_api_key'];

        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertSame(self::KEY, GoogleReviews::apiKey($this->business->id));
    }

    public function test_the_page_shows_a_hint_of_the_key_never_the_key(): void
    {
        $this->link();
        GoogleReviews::storeKey($this->business->id, self::KEY);
        $this->fakeOk();

        $response = $this->get(route('admin.marketing.google'));

        $response->assertOk();
        // المفتاح لا يخرج في حمولة الصفحة — تُقرأ على شاشةٍ في المحلّ
        $response->assertDontSee(self::KEY);
        $response->assertInertia(fn ($page) => $page
            /*
             * ولا المعمَّى: حمولةُ الصفحة تُقرأ في أدوات المتصفّح، وما لا
             * تعرضه الشاشة لا سبب لإرساله أصلًا.
             */
            ->missing('settings.google_api_key')
            ->where('keyHint', '••••'.substr(self::KEY, -4))
            ->where('google.state', 'ok'));
    }

    public function test_an_empty_field_does_not_wipe_the_saved_key(): void
    {
        GoogleReviews::storeKey($this->business->id, self::KEY);

        /*
         * الشاشة لا تعرض المفتاح المحفوظ، فحفظُها لتبديل شيءٍ آخر يصل بحقلٍ
         * فارغ. ولو عُدّ ذلك محوًا لَفقد التاجر مفتاحه كلّما حفظ.
         */
        $this->post(route('admin.marketing.google.key'), ['google_api_key' => ''])
            ->assertSessionHasErrors('google_api_key');

        $this->assertSame(self::KEY, GoogleReviews::apiKey($this->business->id));
    }

    public function test_the_key_is_removed_by_its_own_button(): void
    {
        GoogleReviews::storeKey($this->business->id, self::KEY);

        $this->delete(route('admin.marketing.google.key.forget'))->assertRedirect();

        $this->assertNull(GoogleReviews::apiKey($this->business->id));
    }

    public function test_an_undecryptable_key_counts_as_missing_not_as_a_broken_page(): void
    {
        $this->link();
        // ما لا يُفكّ (بُدّل `APP_KEY` مثلًا) لا يُرمى استثناءً في وجه التاجر
        MarketingSettings::save($this->business->id, 'google', ['google_api_key' => 'not-encrypted-at-all']);

        $this->assertNull(GoogleReviews::apiKey($this->business->id));
        $this->assertSame('nokey', GoogleReviews::pull($this->business->id)['state']);
        $this->get(route('admin.marketing.google'))->assertOk();
    }
}

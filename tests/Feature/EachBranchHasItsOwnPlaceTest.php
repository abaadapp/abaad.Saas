<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * لكلِّ فرعٍ مكانُه على الخريطة — وتقييمُ الزبون يصل الفرعَ الذي اشترى منه.
 *
 * ═══ أثقلُ ما هنا ═══
 *
 * معرّفٌ واحدٌ للمتجر كلِّه كان يعني أنّ إيصال فرع المعبيلة يحمل رمزًا يفتح
 * ملفَّ فرع الخوض. وهو عطبٌ **لا يراه صاحبُه أبدًا**: هو لا يمسح إيصالاته
 * بنفسه، والتقييماتُ تصل مكانًا آخر بهدوء.
 *
 * ═══ ولا يُصدَّق ما يأتي من المتصفّح ═══
 *
 * الشاشةُ تعرض نتائج البحث ويختار التاجر، والمُرسَل معرّفٌ وحده. فما يُحفظ
 * يُقرأ من ردّ Google في تلك اللحظة — ومن بدّل اسمًا أو معدّلًا في الطلب لا
 * يجعل شاشتَنا تشهد بما لم تقله Google.
 */
class EachBranchHasItsOwnPlaceTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private const B = 'ChIJrTLr_LlXwokRBiuGZuRoGaB';

    private Business $shop;

    private Branch $khoud;

    private Branch $mawaleh;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();
        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'ورد أبعاد', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        $this->khoud = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوض']);
        $this->mawaleh = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع المعبيلة']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /* ═══════════════ أدوات ═══════════════ */

    private function platformKey(string $key = 'platform-places-key'): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => GoogleReviews::PLATFORM_KEY],
            ['value' => Crypt::encryptString($key)],
        );
    }

    /** ردُّ «تفاصيل المكان» كما ترسله Google — بحقولها هي */
    private function fakeDetails(string $name = 'ورد أبعاد — الخوض', ?float $rating = 4.8, int $count = 127): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::A,
            'displayName' => ['text' => $name],
            'rating' => $rating,
            'userRatingCount' => $count,
            'googleMapsUri' => 'https://maps.google.com/?cid=111',
        ], 200)]);
    }

    private function fakeSearch(array $places): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => $places], 200)]);
    }

    private function linkVia(Branch $branch, string $placeId = self::A, array $extra = [])
    {
        return $this->post(
            route('admin.integrations.google.branch.link', $branch->id),
            ['place_id' => $placeId] + $extra,
        );
    }

    /** صفٌّ مربوطٌ مباشرةً — حين يقيس الاختبارُ ما بعد الربط لا الربطَ نفسه */
    private function linked(Branch $branch, string $placeId = self::A, ?float $rating = 4.8, int $count = 127): BranchGooglePlace
    {
        return BranchGooglePlace::create([
            'branch_id' => $branch->id,
            'place_id' => $placeId,
            'place_name' => 'ورد أبعاد',
            'maps_url' => 'https://maps.google.com/?cid=111',
            'rating' => $rating,
            'review_count' => $count,
            'synced_at' => now(),
            'linked_at' => now(),
        ]);
    }

    /* ═══════════════ ١ · إعداد المنصّة ═══════════════ */

    /** المفتاح يُحفظ معمًّى — ونصُّه لا يُقرأ من القاعدة */
    public function test_the_platform_key_is_stored_encrypted(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-0000000000');

        $stored = (string) Setting::whereNull('business_id')
            ->where('key', GoogleReviews::PLATFORM_KEY)->value('value');

        $this->assertNotSame('AIzaSy-PLATFORM-0000000000', $stored, 'المفتاح مكتوبٌ نصًّا في القاعدة');
        $this->assertSame('AIzaSy-PLATFORM-0000000000', Crypt::decryptString($stored));
        $this->assertSame('AIzaSy-PLATFORM-0000000000', GoogleReviews::platformKey());
    }

    /**
     * ولا يصل المفتاح إلى شاشة التاجر بحال.
     *
     * لو أُرسل لَقرأه أيُّ زائرٍ من مصدر الصفحة، والنداءاتُ تُحتسب على من
     * يملكه — أبعادَ في الغالب.
     */
    public function test_the_merchant_screen_never_carries_the_key(): void
    {
        $this->platformKey('AIzaSy-SECRET-9999');
        GoogleReviews::storeKey($this->shop->id, 'AIzaSy-MERCHANT-8888');

        $body = $this->get(route('admin.integrations.google'))->assertOk()->getContent();

        $this->assertStringNotContainsString('AIzaSy-SECRET-9999', $body, 'مفتاح المنصّة خرج إلى الشاشة');
        $this->assertStringNotContainsString('AIzaSy-MERCHANT-8888', $body, 'مفتاح التاجر خرج إلى الشاشة');
    }

    /** وبلا مفتاحٍ تُطفأ الخدمة بلطف — لا تنكسر الصفحة */
    public function test_a_missing_key_disables_the_search_gracefully(): void
    {
        $this->post(route('admin.integrations.google.search'), ['q' => 'ورد'])
            ->assertOk()
            ->assertJson(['ok' => false, 'results' => []])
            ->assertJsonPath('error', __('خدمة Google Maps غير مفعلة حاليًا.'));

        Http::assertNothingSent();
    }

    /** وبلا مفتاحٍ لا يُكتب ربطٌ بلا شهادة */
    public function test_a_missing_key_blocks_linking(): void
    {
        $this->linkVia($this->khoud)->assertSessionHasErrors('place_id');

        Http::assertNothingSent();
        $this->assertSame(0, BranchGooglePlace::count());
    }

    /** ومفتاحٌ لا يُفكّ تعميتُه يُعدّ غائبًا لا صفحةً مكسورة */
    public function test_an_undecryptable_key_counts_as_missing(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => GoogleReviews::PLATFORM_KEY],
            ['value' => 'not-encrypted-at-all'],
        );

        $this->assertNull(GoogleReviews::platformKey());
        $this->get(route('admin.integrations.google'))->assertOk();
    }

    /* ═══════════════ ٢ · البحث ═══════════════ */

    public function test_the_merchant_searches_by_name_through_our_server(): void
    {
        $this->platformKey();
        $this->fakeSearch([[
            'id' => self::A,
            'displayName' => ['text' => 'ورد أبعاد — الخوض'],
            'formattedAddress' => 'الخوض، مسقط',
            'rating' => 4.8,
            'userRatingCount' => 127,
            'googleMapsUri' => 'https://maps.google.com/?cid=111',
        ]]);

        $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results.0.place_id', self::A)
            ->assertJsonPath('results.0.name', 'ورد أبعاد — الخوض')
            ->assertJsonPath('results.0.address', 'الخوض، مسقط')
            ->assertJsonPath('results.0.rating', 4.8)
            ->assertJsonPath('results.0.count', 127);
    }

    /**
     * وحرفان لا يُنادى عليهما.
     *
     * والحدُّ في الخادم لا في الشاشة وحدها: من يكتب العنوان بيده يتجاوز أيّ
     * تمهّلٍ في المتصفّح، والنداءُ مدفوع.
     */
    public function test_a_query_shorter_than_the_minimum_calls_nobody(): void
    {
        $this->platformKey();
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []], 200)]);

        $this->post(route('admin.integrations.google.search'), ['q' => 'ور'])
            ->assertOk()
            ->assertJsonPath('results', []);

        Http::assertNothingSent();
    }

    /** ولا نتيجة: يُقال ما يُفعل لا «لا شيء» */
    public function test_no_result_is_said_plainly(): void
    {
        $this->platformKey();
        $this->fakeSearch([]);

        $this->post(route('admin.integrations.google.search'), ['q' => 'محلٌّ لا وجود له'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results', []);
    }

    /** وخطأُ Google لا يخرج بنصّه الخام — يخرج بما يُفعل */
    public function test_a_refused_key_says_what_to_fix(): void
    {
        $this->platformKey();
        Http::fake(['places.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key not valid'],
        ], 403)]);

        $body = $this->post(route('admin.integrations.google.search'), ['q' => 'ورد'])
            ->assertOk()->json();

        $this->assertFalse($body['ok']);
        $this->assertStringContainsString('Places API', (string) $body['error']);
    }

    /* ═══════════════ ٣ · الربط بالفرع ═══════════════ */

    public function test_a_branch_is_linked_to_its_own_place(): void
    {
        $this->platformKey();
        $this->fakeDetails();

        $this->linkVia($this->khoud)->assertSessionHasNoErrors();

        $row = BranchGoogle::for($this->khoud);

        $this->assertNotNull($row);
        $this->assertSame(self::A, $row->place_id);
        $this->assertSame('ورد أبعاد — الخوض', $row->place_name);
        $this->assertSame(4.8, $row->rating);
        $this->assertSame(127, $row->review_count);
        $this->assertSame($this->owner->id, $row->linked_by);
        $this->assertNotNull($row->synced_at);
    }

    /**
     * وما جاء من المتصفّح لا يُكتب — يُكتب ما ردّته Google.
     *
     * وهو أثقلُ حارسٍ في الربط: من بدّل الاسمَ والمعدّل في الطلب يجعل شاشتَنا
     * تشهد لمحلٍّ بما لم تقله Google عنه.
     */
    public function test_place_data_sent_from_the_browser_is_ignored(): void
    {
        $this->platformKey();
        $this->fakeDetails(name: 'الاسم الحقيقي', rating: 3.1, count: 9);

        $this->linkVia($this->khoud, self::A, [
            'place_name' => 'اسمٌ لفّقه المتصفّح',
            'rating' => 5.0,
            'review_count' => 9999,
            'maps_url' => 'https://evil.example/',
        ])->assertSessionHasNoErrors();

        $row = BranchGoogle::for($this->khoud);

        $this->assertSame('الاسم الحقيقي', $row->place_name, 'كُتب اسمٌ جاء من المتصفّح');
        $this->assertSame(3.1, $row->rating);
        $this->assertSame(9, $row->review_count);
        $this->assertSame('https://maps.google.com/?cid=111', $row->maps_url);
    }

    /** ومعرّفٌ لا تعرفه Google لا يُكتب صفًّا */
    public function test_a_place_google_does_not_know_is_not_stored(): void
    {
        $this->platformKey();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['message' => 'Not found']], 404)]);

        $this->linkVia($this->khoud)->assertSessionHasErrors('place_id');

        $this->assertSame(0, BranchGooglePlace::count());
    }

    /** ومعرّفٌ غيرُ مقروءٍ يُردّ قبل أن يُنادى أحد */
    public function test_an_unreadable_place_id_never_reaches_google(): void
    {
        $this->platformKey();

        $this->linkVia($this->khoud, 'ليس معرّفًا')->assertSessionHasErrors('place_id');

        Http::assertNothingSent();
        $this->assertSame(0, BranchGooglePlace::count());
    }

    /** وردٌّ ناجحٌ بلا اسمٍ لا يُقبل: «تمّ الربط» تحته سطرٌ فارغ لا يُتحقّق منه */
    public function test_a_nameless_place_is_refused(): void
    {
        $this->platformKey();
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::A, 'displayName' => ['text' => '  '],
        ], 200)]);

        $this->linkVia($this->khoud)->assertSessionHasErrors('place_id');
        $this->assertSame(0, BranchGooglePlace::count());
    }

    /* ═══════════════ ٤ · الفروع لا تختلط ═══════════════ */

    public function test_two_branches_hold_two_different_places(): void
    {
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $this->assertSame(self::A, BranchGoogle::for($this->khoud)->place_id);
        $this->assertSame(self::B, BranchGoogle::for($this->mawaleh)->place_id);

        $this->assertNotSame(
            BranchGoogle::reviewUrl(BranchGoogle::for($this->khoud)),
            BranchGoogle::reviewUrl(BranchGoogle::for($this->mawaleh)),
            'الفرعان يرسلان الزبائن إلى الملفّ نفسِه',
        );
    }

    /** وفرعٌ غيرُ مربوطٍ لا يرث ربطَ جاره */
    public function test_an_unlinked_branch_inherits_nothing(): void
    {
        $this->linked($this->khoud);

        $this->assertNull(BranchGoogle::for($this->mawaleh));
        $this->assertNull(BranchGoogle::reviewUrl(BranchGoogle::for($this->mawaleh)));
    }

    /** وفرعُ متجرٍ آخر لا يُربط ولا يُفكّ — ويُردّ كما يُردّ غيرُ الموجود */
    public function test_a_branch_of_another_shop_cannot_be_touched(): void
    {
        $this->platformKey();
        $this->fakeDetails();

        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعهم']);

        $this->linkVia($theirs)->assertNotFound();
        $this->delete(route('admin.integrations.google.branch.unlink', $theirs->id))->assertNotFound();
        $this->post(route('admin.integrations.google.branch.refresh', $theirs->id))->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, BranchGooglePlace::count());
    }

    /* ═══════════════ ٥ · فكُّ الربط ═══════════════ */

    public function test_unlinking_stamps_the_row_and_keeps_it(): void
    {
        $this->linked($this->khoud);

        $this->delete(route('admin.integrations.google.branch.unlink', $this->khoud->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(BranchGoogle::for($this->khoud), 'بقي مربوطًا بعد الفكّ');

        /* والصفُّ باقٍ: من يسأل غدًا «بمَ كان مربوطًا؟» يجد جوابًا */
        $row = BranchGooglePlace::where('branch_id', $this->khoud->id)->firstOrFail();
        $this->assertNotNull($row->unlinked_at);
        $this->assertSame(self::A, $row->place_id);
    }

    /** وإعادةُ الربط ترفع الختم على الصفّ نفسِه — لا صفَّين لفرع */
    public function test_relinking_reuses_the_same_row(): void
    {
        $this->linked($this->khoud);
        BranchGoogle::unlink($this->khoud);

        $this->platformKey();
        $this->fakeDetails();
        $this->linkVia($this->khoud)->assertSessionHasNoErrors();

        $this->assertSame(1, BranchGooglePlace::where('branch_id', $this->khoud->id)->count());
        $this->assertNotNull(BranchGoogle::for($this->khoud));
    }

    /* ═══════════════ ٦ · المعدّل والعدّ ═══════════════ */

    public function test_the_screen_carries_the_rating_and_the_count(): void
    {
        $this->linked($this->khoud, rating: 4.8, count: 127);

        $this->get(route('admin.integrations.google'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('branches.0.name', 'فرع الخوض')
                ->where('branches.0.linked', true)
                ->where('branches.0.rating', 4.8)
                ->where('branches.0.reviewCount', 127)
                ->where('branches.1.linked', false)
                ->etc());
    }

    /**
     * ومعرّفُ المكان لا يُرسل إلى شاشة التاجر.
     *
     * لا يفعل به شيئًا، وإظهارُه يدعو إلى لصقه بيدٍ — وهو الطريق الذي جاءت
     * هذه الشاشة لتُغنيَ عنه.
     */
    public function test_the_raw_place_id_is_not_shown_to_the_merchant(): void
    {
        $this->linked($this->khoud);

        $branches = $this->get(route('admin.integrations.google'))
            ->viewData('page')['props']['branches'];

        $this->assertArrayNotHasKey('placeId', $branches[0]);
        $this->assertArrayNotHasKey('place_id', $branches[0]);
    }

    /** والمكانُ الذي لم يُقيَّم بعد فارغٌ لا صفر — والفرقُ يراه صاحبُ المحلّ */
    public function test_an_unrated_place_is_empty_not_zero(): void
    {
        $this->linked($this->khoud, rating: null, count: 0);

        $branches = $this->get(route('admin.integrations.google'))
            ->viewData('page')['props']['branches'];

        $this->assertNull($branches[0]['rating']);
    }

    /* ═══════════════ ٧ · الذاكرة والحصّة ═══════════════ */

    /** كم نداءً وقع حتّى الآن — يُقاس ولا يُفترض */
    private function calls(): int
    {
        return count(Http::recorded());
    }

    /**
     * فتحُ الشاشة مرّةً بعد مرّةٍ لا يزيد الفاتورة.
     *
     * ═══ ولمَ لا يُقال «صفرُ نداءات» ═══
     *
     * الشاشةُ تقرأ شيئين: معدّلَ كلّ فرعٍ (محفوظٌ، يُزامَن كلَّ اثنتي عشرةَ
     * ساعة) ونصوصَ التقييمات (تُسحب حيّةً وتبقى في الذاكرة ستَّ ساعات —
     * شروطُ Google تمنع حفظها). فالفتحةُ الأولى الباردة قد تُنادي مرّةً
     * للنصوص. والمقيسُ هنا ما يهمّ: **الفتحةُ الثانية لا تكلّف شيئًا**.
     */
    public function test_opening_the_screen_again_costs_nothing(): void
    {
        $this->platformKey();
        $this->linked($this->khoud);
        $this->fakeDetails();

        $this->get(route('admin.integrations.google'))->assertOk();
        $first = $this->calls();

        $this->get(route('admin.integrations.google'))->assertOk();

        $this->assertSame($first, $this->calls(), 'كلُّ فتحةِ شاشةٍ نداءٌ جديدٌ على Google');
    }

    /** وربطٌ حديثٌ لا يُزامَن أصلًا — المزامنةُ للقديم وحده */
    public function test_a_fresh_link_is_not_synced(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, rating: 4.0, count: 10);
        $this->fakeDetails(rating: 4.9, count: 200);

        $this->assertFalse(BranchGoogle::isStale($place));
        $this->assertFalse(BranchGoogle::sync($place), 'زوُمن ربطٌ حديث');

        Http::assertNothingSent();
        $this->assertSame(4.0, BranchGoogle::for($this->khoud)->rating);
    }

    /** والقديمُ يُزامَن مرّةً ثمّ يصير حديثًا فلا يُزامَن ثانية */
    public function test_a_stale_link_is_synced_once(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, rating: 4.0, count: 10);
        $place->forceFill(['synced_at' => now()->subHours(BranchGoogle::STALE_HOURS + 1)])->save();

        $this->fakeDetails(name: 'ورد أبعاد — الخوض', rating: 4.9, count: 200);

        $this->assertTrue(BranchGoogle::sync($place->fresh()), 'لم يُزامَن ربطٌ قديم');
        $after = $this->calls();

        $this->assertSame(4.9, BranchGoogle::for($this->khoud)->rating);
        $this->assertSame(200, BranchGoogle::for($this->khoud)->review_count);

        // وصار حديثًا: نداءٌ ثانٍ لا يقع
        $this->assertFalse(BranchGoogle::sync(BranchGoogle::for($this->khoud)));
        $this->assertSame($after, $this->calls());
    }

    /**
     * وعطلُ Google لا يمحو ما عُرف.
     *
     * معدّلٌ يختفي من الشاشة لأنّ نداءً فشل يجعل التاجر يظنّ ربطَه انفكّ.
     */
    public function test_a_google_failure_keeps_the_last_known_numbers(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, rating: 4.8, count: 127);
        $place->forceFill(['synced_at' => now()->subDays(2)])->save();

        Http::fake(['places.googleapis.com/*' => Http::response([], 500)]);

        $this->get(route('admin.integrations.google'))->assertOk();

        $this->assertSame(4.8, BranchGoogle::for($this->khoud)->rating);
        $this->assertSame(127, BranchGoogle::for($this->khoud)->review_count);
    }

    /** و«حدِّث الآن» يسأل Google ولو كان المحفوظ حديثًا */
    public function test_refresh_asks_google_even_when_fresh(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, rating: 4.0, count: 10);
        $this->fakeDetails(rating: 4.9, count: 200);

        $this->post(route('admin.integrations.google.branch.refresh', $this->khoud->id))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $this->assertSame(4.9, BranchGoogle::for($this->khoud)->rating);
    }

    /* ═══════════════ ٨ · الإيصال ═══════════════ */

    /**
     * ورمزُ الإيصال بملفّ **فرع الورقة** — ولا احتياطَ إلى فرعٍ آخر.
     *
     * وهو العطبُ الذي جاء الربطُ بالفرع ليُصلحه: زبونٌ اشترى من المعبيلة
     * يمسح رمزًا فيكتب تقييمًا يُحسب للخوض.
     */
    public function test_the_receipt_code_follows_the_branch_of_the_paper(): void
    {
        MarketingSettings::save($this->shop->id, 'google', ['google_review_on_receipt' => '1']);
        $this->linked($this->khoud, self::A);

        $this->assertSame(
            'https://search.google.com/local/writereview?placeid='.self::A,
            GoogleReviews::onReceipt($this->shop->id, $this->khoud->id),
        );

        $this->assertNull(
            GoogleReviews::onReceipt($this->shop->id, $this->mawaleh->id),
            'طُبع على إيصال فرعٍ غير مربوطٍ رمزُ فرعٍ آخر',
        );
    }

    /* ═══════════════ ٩ · طلب التقييم ═══════════════ */

    private function order(string $status, ?Branch $branch = null): Order
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبونة', 'phone' => '96890000001',
        ]);

        return Order::create([
            'business_id' => $this->shop->id,
            'branch_id' => ($branch ?? $this->khoud)->id,
            'customer_id' => $customer->id,
            'number' => 'INV-'.uniqid(),
            'status' => $status, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'customer_name' => 'زبونة',
        ]);
    }

    public function test_a_delivered_order_can_ask_for_a_review(): void
    {
        $this->linked($this->khoud, self::A);
        $order = $this->order(OrderStatus::DELIVERED);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $toast = session('toast');

        $this->assertSame('success', $toast['type']);
        $this->assertStringContainsString('wa.me/96890000001', $toast['link']['url']);
        $this->assertStringContainsString(rawurlencode(self::A), $toast['link']['url']);
        $this->assertNotNull($order->fresh()->review_request_sent_at);
    }

    /** وطلبٌ لم يُسلَّم لا يُسأل عنه: لا تجربةَ قبل أن يصل الورد */
    public function test_an_undelivered_order_is_refused(): void
    {
        $this->linked($this->khoud);
        $order = $this->order(OrderStatus::PREPARING);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->review_request_sent_at);
    }

    /** وفرعٌ غيرُ مربوطٍ لا يُرسل رابطًا */
    public function test_an_unlinked_branch_cannot_ask_for_a_review(): void
    {
        $order = $this->order(OrderStatus::DELIVERED, $this->mawaleh);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->review_request_sent_at);
    }

    /**
     * والرابطُ رابطُ **فرع هذا الطلب**.
     *
     * وهو ما يفرّق طلبًا من الخوض عن طلبٍ من المعبيلة: تقييمُ الزبون يُحسب
     * للفرع الذي اشترى منه.
     */
    public function test_the_link_belongs_to_the_orders_own_branch(): void
    {
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $this->post(route('admin.orders.reviewRequest', $this->order(OrderStatus::DELIVERED, $this->mawaleh)->number));

        $this->assertStringContainsString(rawurlencode(self::B), session('toast')['link']['url']);
        $this->assertStringNotContainsString(rawurlencode(self::A), session('toast')['link']['url']);
    }

    /** ولا رقمَ لا رسالة — ويُقال، لا يُصمت */
    public function test_an_order_without_a_phone_is_told_so(): void
    {
        $this->linked($this->khoud);

        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->khoud->id,
            'number' => 'INV-NOPHONE', 'status' => OrderStatus::DELIVERED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(), 'customer_name' => 'نقدي',
        ]);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
    }

    /** والشاشةُ لا تعرض الزرَّ إلا حين يعمل — بابٌ معروضٌ لا يُفتح أسوأ من غيابه */
    public function test_the_button_is_only_offered_when_it_works(): void
    {
        $this->linked($this->khoud);

        $ready = $this->order(OrderStatus::DELIVERED);
        $early = $this->order(OrderStatus::PREPARING);
        $unlinked = $this->order(OrderStatus::DELIVERED, $this->mawaleh);

        $show = fn (Order $o) => $this->get(route('admin.orders.show', $o->number))
            ->viewData('page')['props']['googleReview'];

        $this->assertTrue($show($ready)['show']);
        $this->assertFalse($show($early)['show']);
        $this->assertFalse($show($unlinked)['show']);
        $this->assertNotNull($show($unlinked)['reason'], 'أُخفي الزرُّ بلا سببٍ يُقرأ');
    }

    /* ═══════════════ ١٠ · صفحة المتجر ═══════════════ */

    private function publish(): void
    {
        $this->shop->forceFill(['site_slug' => 'ward-abaad'])->save();
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'بوكيه',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    public function test_the_site_shows_the_rating_only_when_the_merchant_asks(): void
    {
        $this->publish();
        $this->linked($this->khoud, rating: 4.8, count: 127);

        $this->assertNull(Storefront::page($this->shop->fresh())['google'], 'عُرض المعدّل بلا إذن');

        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);

        $shown = Storefront::page($this->shop->fresh())['google'];

        $this->assertSame(4.8, $shown['rating']);
        $this->assertSame(127, $shown['count']);
    }

    /** والإسنادُ شرطُ Google — يُقرأ في الصفحة نفسِها */
    public function test_the_page_carries_the_google_attribution(): void
    {
        $this->publish();
        $this->linked($this->khoud, rating: 4.8, count: 127);
        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);

        $this->get(route('store.show', 'ward-abaad'))
            ->assertOk()
            ->assertSee('4.8')
            ->assertSee('المصدر: Google', false);
    }

    /** وفرعٌ غيرُ مربوطٍ لا يُظهر شيئًا ولا يكسر الصفحة */
    public function test_an_unlinked_shop_shows_nothing_and_still_opens(): void
    {
        $this->publish();
        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);

        $this->assertNull(Storefront::page($this->shop->fresh())['google']);

        $this->get(route('store.show', 'ward-abaad'))
            ->assertOk()
            ->assertDontSee('المصدر: Google', false);
    }

    /** ومكانٌ مربوطٌ بلا تقييماتٍ لا يُعرض «٠ تقييم» */
    public function test_a_place_with_no_reviews_shows_no_number(): void
    {
        $this->publish();
        $this->linked($this->khoud, rating: null, count: 0);
        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);

        $this->assertNull(Storefront::page($this->shop->fresh())['google']);
    }

    /** وصفحةُ المتجر لا تُنادي Google مهما فُتحت — حصّةٌ تنفد بزوّار لا بتجّار */
    public function test_the_public_page_never_calls_google(): void
    {
        $this->platformKey();
        $this->publish();
        $this->linked($this->khoud);
        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);
        Http::fake(['places.googleapis.com/*' => Http::response([], 200)]);

        $this->get(route('store.show', 'ward-abaad'))->assertOk();
        $this->get(route('store.show', 'ward-abaad'))->assertOk();

        Http::assertNothingSent();
    }

    /* ═══════════════ ١١ · لوحة المنصّة ═══════════════ */

    /** ومديرُ المنصّة يقرأ حالَ التهيئة وعددَ المربوط — لا مفتاحًا ولا رمزًا */
    public function test_the_platform_reads_the_configuration_health(): void
    {
        $this->platformKey('AIzaSy-PLATFORM-HEALTH');
        $this->linked($this->khoud);

        $admin = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $response = $this->actingAs($admin)->get(route('super-admin.settings.index'))->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['googleHealth']['configured']);
        $this->assertSame(1, $props['googleHealth']['linkedBranches']);
        $this->assertStringNotContainsString('AIzaSy-PLATFORM-HEALTH', $response->getContent());
    }
}

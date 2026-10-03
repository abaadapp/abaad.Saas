<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\GoogleReviews;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * خرائطُ Google ميزةٌ اختياريّة بمفتاح التاجر وحده — ولا تقع على أبعاد.
 *
 * ═══ ما كان ═══
 *
 * `GoogleReviews::apiKey` كان يردّ مفتاحَ التاجر، **وإلّا مفتاحَ المنصّة**:
 * فكلُّ متجرٍ لم يلصق مفتاحه يُنادي Google على فاتورة أبعاد.
 *
 * ═══ ما يُحرس ═══
 *
 *   ١. بلا مفتاح: المتجرُ كلُّه يعمل (الفروع والبيع)، وميزةُ Google تُقال
 *      «اربطها» ولا يُنادى Google أصلًا.
 *   ٢. بمفتاحه: يُنادى Google بمفتاحه هو.
 *   ٣. مفتاحٌ مرفوض: ردٌّ مضبوطٌ يقول ما يُصلَح، وما سواه يعمل.
 *   ٤. متجران بمفتاحين: كلٌّ بمفتاحه، ولا يعبر مفتاحٌ حدَّ متجره.
 *   ٥. صفُّ مفتاحٍ قديمٍ لأبعاد وتاجرٌ بلا مفتاح: لا يقع عليه أبدًا — ثمّ
 *      تحذفه الهجرة، ولا كودَ يقرؤه.
 *   ٦. متجرٌ بفرعين: مفتاحٌ واحد، ولكلّ فرعٍ مكانُه، والنداءان بالمفتاح نفسه.
 *   ٧. الفروعُ مستقلّة: تحديثُ فرعٍ أو فكُّه لا يمسّ غيره.
 *   والإطفاءُ يُوقف النداءات ولا يمحو مفتاحًا ولا مكانَ فرع ولا رابطَ إيصال،
 *   والمفتاحُ لا يظهر كاملًا لأحد — لا للتاجر ولا لمدير المنصّة.
 */
class GoogleMapsIsTheMerchantsOwnKeyTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private Business $a;

    private Business $b;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();
        // ولا نداءَ حقيقيّ على Google — ما لم يُزوَّر يُرمى
        Http::preventStrayRequests();

        $this->a = $this->shop('ورد أ', 'a@shop.test');
        $this->b = $this->shop('ورد ب', 'b@shop.test');

        /*
         * صفُّ مفتاحِ أبعاد القديم — كما قد يبقى على قاعدةٍ لم تُرحَّل بعد.
         * لا دالّةَ تكتبه بعد اليوم، فيُكتب خامًا ليُثبَت أنّه لا يقع على أحد.
         */
        Setting::create([
            'business_id' => null, 'key' => 'google_places_key',
            'value' => Crypt::encryptString('AIza-ABAAD-PLATFORM'),
        ]);

        $this->actingAs($this->owner($this->a));
        session(['current_branch' => Branch::where('business_id', $this->a->id)->value('id')]);
    }

    private function shop(string $name, string $email): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($shop->id);
        User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Product::create([
            'business_id' => $shop->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);

        return $shop;
    }

    private function owner(Business $shop): User
    {
        return User::where('business_id', $shop->id)->firstOrFail();
    }

    private function placeReply(string $name = 'ورد أ', string $id = self::PLACE, float $rating = 4.7, int $count = 90): array
    {
        return [
            'id' => $id, 'displayName' => ['text' => $name], 'rating' => $rating,
            'userRatingCount' => $count, 'googleMapsUri' => 'https://maps.google.com/?cid='.$id, 'reviews' => [],
        ];
    }

    /** ما تردّه Google لكلّ معرّف — يُبدَّل في الاختبار نفسه */
    private array $replies = [];

    private bool $faked = false;

    /**
     * ردُّ Google بحسب المكان المطلوب — لكلّ معرّفٍ اسمُه ومعدّله.
     *
     * والتزويرُ يُسجَّل مرّةً ويقرأ `$replies` حيًّا: `Http::fake` ثانيةٌ تُضاف
     * بعد الأولى ولا تحلّ محلّها، فيبقى الردُّ القديم يُجيب.
     */
    private function fakePlaces(array $places): void
    {
        $this->replies = [...$this->replies, ...$places];

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake(function (Request $r) {
            foreach ($this->replies as $id => $reply) {
                if (str_contains($r->url(), '/places/'.$id)) {
                    return Http::response($reply);
                }
            }

            return Http::response(['error' => ['message' => 'not found']], 404);
        });
    }

    private function branchOf(Business $shop, string $name = 'الرئيسي'): Branch
    {
        return Branch::firstOrCreate(['business_id' => $shop->id, 'name' => $name]);
    }

    /** المفاتيحُ التي نودي بها Google — بترتيبها */
    private function keysSent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-Goog-Api-Key')[0] ?? null)->all();
    }

    /* ═══════════════ ١ · بلا مفتاح — المتجرُ يعمل وGoogle لا يُنادى ═══════════════ */

    public function test_without_a_key_the_shop_works_and_google_is_never_called(): void
    {
        Http::fake();

        // الفروعُ تُنشأ وتُعدَّل بلا Google
        $this->post(route('admin.branches.store'), ['name' => 'فرع القرم'])->assertSessionHasNoErrors();
        $branch = Branch::where('business_id', $this->a->id)->where('name', 'فرع القرم')->firstOrFail();

        // والبيعُ يتمّ بلا Google
        $product = Product::where('business_id', $this->a->id)->firstOrFail();
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => 'وردة', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        // وصفحةُ Google تُفتح وتقول إنّها غير مربوطة
        $this->get(route('admin.integrations.google'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('enabled', false)->where('keyHint', null)->etc());

        // وما يحتاج Google يُقال «اربطه» — لا استثناء ولا نداء
        $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', __('اربط Google Maps بمفتاحك لتفعيل هذه الميزة.'));

        $this->assertFalse(BranchGoogle::link($branch, self::PLACE)['ok']);
        $this->assertNotSame('ok', GoogleReviews::pull($this->a->id)['state']);

        Http::assertNothingSent();
    }

    /* ═══════════════ ٢ · بمفتاحه — يُنادى بمفتاحه هو ═══════════════ */

    public function test_with_its_own_key_google_is_called_with_that_key(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->placeReply())]);
        GoogleReviews::storeKey($this->a->id, 'AIza-MERCHANT-A');

        $branch = Branch::where('business_id', $this->a->id)->firstOrFail();
        $out = BranchGoogle::link($branch, self::PLACE);

        $this->assertTrue($out['ok']);
        $this->assertSame(self::PLACE, BranchGoogle::for($branch)?->place_id);
        $this->assertSame(['AIza-MERCHANT-A'], $this->keysSent());
    }

    /* ═══════════════ ٣ · مفتاحٌ مرفوض — ردٌّ مضبوط، وما سواه يعمل ═══════════════ */

    public function test_a_refused_key_fails_gracefully_and_the_shop_still_works(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid', 'status' => 'PERMISSION_DENIED']], 403)]);
        GoogleReviews::storeKey($this->a->id, 'AIza-EXPIRED');

        $res = $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])->assertOk();
        $res->assertJsonPath('ok', false);
        $this->assertStringContainsString('تعذر استخدام مفتاح Google', (string) $res->json('error'));
        $this->assertStringNotContainsString('AIza-EXPIRED', (string) $res->getContent(), 'المفتاحُ في رسالة الخطأ');

        // وفشلُ Google لا يمسّ ما سواه
        $this->get(route('admin.integrations.google'))->assertOk();
        $this->post(route('admin.branches.store'), ['name' => 'فرع الخوض'])->assertSessionHasNoErrors();
        $this->assertSame(['AIza-EXPIRED'], array_values(array_unique($this->keysSent())));
    }

    /* ═══════════════ ٤ · متجران بمفتاحين — لا يعبر مفتاحٌ حدَّه ═══════════════ */

    public function test_each_shop_calls_google_with_its_own_key_only(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []])]);
        GoogleReviews::storeKey($this->a->id, 'AIza-KEY-OF-A');
        GoogleReviews::storeKey($this->b->id, 'AIza-KEY-OF-B');

        $this->actingAs($this->owner($this->a))->post(route('admin.integrations.google.search'), ['q' => 'ورد أ'])->assertOk();
        $this->actingAs($this->owner($this->b))->post(route('admin.integrations.google.search'), ['q' => 'ورد ب'])->assertOk();

        $this->assertSame(['AIza-KEY-OF-A', 'AIza-KEY-OF-B'], $this->keysSent());

        // ولا يرى أحدُهما مفتاحَ الآخر — ولا مفتاحَه هو كاملًا
        $page = $this->actingAs($this->owner($this->b))->get(route('admin.integrations.google'))->assertOk();
        $page->assertDontSee('AIza-KEY-OF-A', false)->assertDontSee('AIza-KEY-OF-B', false);
        $this->assertSame('••••OF-B', $page->viewData('page')['props']['keyHint']);
    }

    /* ═══════════════ ٥ · مفتاحُ أبعاد لا يقع على تاجرٍ بلا مفتاح ═══════════════ */

    public function test_the_platform_key_is_never_used_as_a_fallback(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->placeReply())]);
        $this->assertNotNull(Setting::whereNull('business_id')->where('key', 'google_places_key')->value('value'), 'التمهيد لم يحفظ مفتاح المنصّة');

        $this->assertNull(GoogleReviews::apiKey($this->a->id));
        $this->assertFalse(GoogleReviews::enabled($this->a->id));

        $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])->assertJsonPath('ok', false);
        $this->assertFalse(BranchGoogle::link(Branch::where('business_id', $this->a->id)->firstOrFail(), self::PLACE)['ok']);
        GoogleReviews::pull($this->a->id);

        Http::assertNothingSent();
        Http::assertNotSent(fn (Request $r) => $r->hasHeader('X-Goog-Api-Key', 'AIza-ABAAD-PLATFORM'));
    }

    /* ═══════════════ والإطفاءُ يُوقف ولا يمحو ═══════════════ */

    public function test_turning_google_off_stops_calls_but_keeps_the_key_and_the_places(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->placeReply())]);
        GoogleReviews::storeKey($this->a->id, 'AIza-MERCHANT-A');
        $branch = Branch::where('business_id', $this->a->id)->firstOrFail();
        $this->assertTrue(BranchGoogle::link($branch, self::PLACE)['ok']);
        $before = count(Http::recorded());

        $this->post(route('admin.integrations.google.enabled'), ['enabled' => false])->assertSessionHasNoErrors();

        $this->assertFalse(GoogleReviews::enabled($this->a->id));
        $this->assertNull(GoogleReviews::apiKey($this->a->id));
        $this->assertSame('••••NT-A', GoogleReviews::keyHint($this->a->id), 'الإطفاءُ محا المفتاح');
        $this->assertSame(1, BranchGooglePlace::count(), 'الإطفاءُ محا مكانَ الفرع');
        $this->assertNotNull(BranchGoogle::reviewUrl(BranchGoogle::for($branch)), 'ورابطُ التقييم في الإيصال باقٍ');

        $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])->assertJsonPath('ok', false);
        $this->assertCount($before, Http::recorded(), 'نودي Google وهي مطفأة');

        // وتُفعَّل بلا لصقٍ جديد
        $this->post(route('admin.integrations.google.enabled'), ['enabled' => true])->assertSessionHasNoErrors();
        $this->assertSame('AIza-MERCHANT-A', GoogleReviews::apiKey($this->a->id));
    }

    public function test_it_cannot_be_turned_on_without_a_key(): void
    {
        $this->post(route('admin.integrations.google.enabled'), ['enabled' => true])
            ->assertSessionHasErrors('google_api_key');

        $this->assertFalse(GoogleReviews::enabled($this->a->id));
    }

    /** وتاجرٌ حفظ مفتاحه قبل حقل التفعيل يبقى مفعّلًا — بلا إصلاحٍ يدويّ */
    public function test_a_shop_that_saved_its_key_before_the_switch_stays_on(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-OLD-KEY');
        // كما كان قبل الحقل: مفتاحٌ محفوظٌ ولا قرار
        MarketingSettings::save($this->a->id, 'google', ['google_enabled' => '']);

        $this->assertTrue(GoogleReviews::enabled($this->a->id));
        $this->assertSame('AIza-OLD-KEY', GoogleReviews::apiKey($this->a->id));
        $this->assertFalse(GoogleReviews::enabled($this->b->id), 'متجرٌ بلا إعدادٍ لم يُطفأ');
    }

    /** والتبديلُ لصاحب المتجر بصلاحيّته — لا لمتجرٍ آخر */
    public function test_the_switch_writes_the_session_shop_only(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-KEY-OF-A');
        GoogleReviews::storeKey($this->b->id, 'AIza-KEY-OF-B');

        $this->actingAs($this->owner($this->a))
            ->post(route('admin.integrations.google.enabled'), ['enabled' => false, 'business_id' => $this->b->id]);

        $this->assertFalse(GoogleReviews::enabled($this->a->id));
        $this->assertTrue(GoogleReviews::enabled($this->b->id), 'طلبُ متجرٍ أطفأ متجرًا آخر');
    }

    /* ═══════════════ ٣ب · أسبابُ الرفض بأسمائها ═══════════════ */

    /**
     * كلُّ رفضٍ يقول ما يُصلَح — والمفتاحُ غيرُ الصالح يُقال مفتاحًا لا معرّفًا.
     *
     * Google تردّ المفتاحَ غيرَ الصالح بـ400. وكانت كلُّ 400 تُقرأ «رفضت Google
     * معرّف المكان» فيبحث التاجر عن عيبٍ في محلّه والعيبُ في مفتاحه.
     */
    public function test_each_google_refusal_says_what_to_fix(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIza-MERCHANT-A');

        $cases = [
            [400, 'API_KEY_INVALID', 'API key not valid. Please pass a valid API key.', 'مفتاح Google غير صالح أو أُلغي'],
            [400, '', 'API key not valid. Please pass a valid API key.', 'مفتاح Google غير صالح أو أُلغي'],
            [403, 'BILLING_DISABLED', 'This API method requires billing to be enabled.', 'الفوترة غير مفعّلة'],
            [403, 'SERVICE_DISABLED', 'Places API (New) has not been used in project 1.', 'Places API (New) غير مفعّلة'],
            [403, 'API_KEY_IP_ADDRESS_BLOCKED', 'The provided API key has an IP address restriction.', 'قيود مفتاح Google تمنع خادم أبعاد'],
            [403, '', 'Permission denied.', 'تعذر استخدام مفتاح Google'],
            [429, 'RATE_LIMIT_EXCEEDED', 'Quota exceeded.', 'تجاوزتَ حصّة Google'],
        ];

        $sequence = Http::sequence();
        foreach ($cases as [$status, $reason, $message]) {
            $sequence->push(['error' => [
                'code' => $status, 'message' => $message,
                'details' => $reason === '' ? [] : [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $reason]],
            ]], $status);
        }
        $sequence->whenEmpty(fn () => throw new ConnectionException('cURL error 28 key=AIza-MERCHANT-A'));
        Http::fake(['places.googleapis.com/*' => $sequence]);

        foreach ($cases as [$status, $reason, , $expected]) {
            $res = $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])->assertOk();

            $res->assertJsonPath('ok', false);
            $this->assertStringContainsString($expected, (string) $res->json('error'), "$status $reason");
            $this->assertStringNotContainsString('AIza-MERCHANT-A', (string) $res->getContent());
        }

        // وانقطاعُ الشبكة يُقال انقطاعًا — لا استثناءٌ يكسر الشاشة
        $res = $this->post(route('admin.integrations.google.search'), ['q' => 'ورد أبعاد'])->assertOk();
        $res->assertJsonPath('ok', false);
        $this->assertStringNotContainsString('AIza-MERCHANT-A', (string) $res->getContent());

        // وما سوى Google يعمل
        $this->get(route('admin.integrations.google'))->assertOk();
        $this->post(route('admin.branches.store'), ['name' => 'فرع بعد الرفض'])->assertSessionHasNoErrors();
    }

    /* ═══════════════ ٦ · متجرٌ بفرعين — مفتاحٌ واحد، ومكانان ═══════════════ */

    public function test_one_key_serves_every_branch_and_each_branch_keeps_its_own_place(): void
    {
        $placeA = 'ChIJ_PLACE_OF_BRANCH_A_000001';
        $placeB = 'ChIJ_PLACE_OF_BRANCH_B_000002';
        $this->fakePlaces([
            $placeA => $this->placeReply('فرع الخوض', $placeA, 4.2, 10),
            $placeB => $this->placeReply('فرع القرم', $placeB, 4.9, 77),
        ]);
        GoogleReviews::storeKey($this->a->id, 'AIza-ONE-KEY-FOR-ALL');

        $khoud = $this->branchOf($this->a);
        $qurum = $this->branchOf($this->a, 'فرع القرم');

        // من الشاشة كما يفعل التاجر — ولا يُطلب مفتاحٌ عند تبديل الفرع
        $this->post(route('admin.integrations.google.branch.link', $khoud->id), ['place_id' => $placeA])->assertSessionHasNoErrors();
        $this->post(route('admin.integrations.google.branch.link', $qurum->id), ['place_id' => $placeB])->assertSessionHasNoErrors();

        $this->assertSame(['AIza-ONE-KEY-FOR-ALL', 'AIza-ONE-KEY-FOR-ALL'], $this->keysSent());

        $a = BranchGoogle::for($khoud);
        $b = BranchGoogle::for($qurum);
        $this->assertSame([$placeA, 'فرع الخوض', 4.2, 10], [$a->place_id, $a->place_name, (float) $a->rating, $a->review_count]);
        $this->assertSame([$placeB, 'فرع القرم', 4.9, 77], [$b->place_id, $b->place_name, (float) $b->rating, $b->review_count]);
        $this->assertNotSame(BranchGoogle::reviewUrl($a), BranchGoogle::reviewUrl($b));
        $this->assertNotSame($a->maps_url, $b->maps_url);
        $this->assertNotNull($a->synced_at);
        $this->assertNotNull($b->synced_at);

        // ولا مفتاحَ للفرع: المفتاحُ مفتاحُ المتجر وحده
        $this->assertSame(0, Setting::where('key', 'like', '%branch%google%key%')->count());

        // وفرعٌ جديدٌ غدًا يُربط بالمفتاح نفسه
        $future = $this->branchOf($this->a, 'فرع المعبيلة');
        $this->fakePlaces(['ChIJ_PLACE_OF_BRANCH_C_000003' => $this->placeReply('فرع المعبيلة', 'ChIJ_PLACE_OF_BRANCH_C_000003')]);
        $this->assertTrue(BranchGoogle::link($future, 'ChIJ_PLACE_OF_BRANCH_C_000003')['ok']);
        $this->assertSame(['AIza-ONE-KEY-FOR-ALL'], array_values(array_unique($this->keysSent())));

        // والشاشةُ تعرض حالَ كلّ فرع
        $rows = collect($this->get(route('admin.integrations.google'))->viewData('page')['props']['branches'])->keyBy('name');
        $this->assertTrue($rows['الرئيسي']['linked']);
        $this->assertTrue($rows['فرع القرم']['linked']);
        $this->assertTrue($rows['فرع المعبيلة']['linked']);
    }

    /* ═══════════════ ٧ · الفروعُ مستقلّة ═══════════════ */

    public function test_updating_or_unlinking_one_branch_leaves_the_other_alone(): void
    {
        $placeA = 'ChIJ_PLACE_OF_BRANCH_A_000001';
        $placeB = 'ChIJ_PLACE_OF_BRANCH_B_000002';
        $this->fakePlaces([
            $placeA => $this->placeReply('فرع الخوض', $placeA, 4.2, 10),
            $placeB => $this->placeReply('فرع القرم', $placeB, 4.9, 77),
        ]);
        GoogleReviews::storeKey($this->a->id, 'AIza-MERCHANT-A');
        $khoud = $this->branchOf($this->a);
        $qurum = $this->branchOf($this->a, 'فرع القرم');
        BranchGoogle::link($khoud, $placeA);
        BranchGoogle::link($qurum, $placeB);
        $before = BranchGoogle::for($qurum)->only(['place_id', 'place_name', 'rating', 'review_count', 'maps_url', 'synced_at', 'unlinked_at']);

        // تحديثُ الخوض — معدّلٌ جديد له وحده
        $this->fakePlaces([$placeA => $this->placeReply('فرع الخوض', $placeA, 3.1, 11)]);
        $this->post(route('admin.integrations.google.branch.refresh', $khoud->id))->assertSessionHas('toast', fn ($t) => $t['type'] === 'success');

        $this->assertSame(3.1, (float) BranchGoogle::for($khoud)->rating);
        $this->assertEquals($before, BranchGoogle::for($qurum)->fresh()->only(array_keys($before)), 'تحديثُ فرعٍ غيّر فرعًا آخر');

        // فكُّ الخوض — والقرمُ مربوطٌ كما هو
        $this->delete(route('admin.integrations.google.branch.unlink', $khoud->id))->assertRedirect();

        $this->assertNull(BranchGoogle::for($khoud));
        $this->assertSame($placeB, BranchGoogle::for($qurum)?->place_id, 'فكُّ فرعٍ فكَّ فرعًا آخر');
        $this->assertSame(2, BranchGooglePlace::count(), 'الفكُّ محا صفًّا — والفكُّ ختمٌ لا محو');

        // وإعادةُ ربطه بلا مفتاحٍ جديد
        $this->fakePlaces([$placeA => $this->placeReply('فرع الخوض', $placeA)]);
        $this->assertTrue(BranchGoogle::link($khoud, $placeA)['ok']);
        $this->assertSame($placeA, BranchGoogle::for($khoud)?->place_id);
    }

    /* ═══════════════ ٨ · الإطفاءُ لا يمسّ رابطَ الإيصال ═══════════════ */

    public function test_turning_google_off_keeps_the_receipt_review_link_and_calls_nothing(): void
    {
        $this->fakePlaces([self::PLACE => $this->placeReply()]);
        GoogleReviews::storeKey($this->a->id, 'AIza-MERCHANT-A');
        MarketingSettings::save($this->a->id, 'google', ['google_review_on_receipt' => '1']);
        $branch = $this->branchOf($this->a);
        BranchGoogle::link($branch, self::PLACE);
        $url = GoogleReviews::onReceipt($this->a->id, $branch->id);
        $this->assertNotNull($url);
        $sent = count(Http::recorded());

        GoogleReviews::setEnabled($this->a->id, false);

        $this->assertSame($url, GoogleReviews::onReceipt($this->a->id, $branch->id), 'الإطفاءُ أسقط رابطَ الإيصال');
        $this->assertSame(self::PLACE, BranchGoogle::for($branch)?->place_id);
        $this->assertSame(4.7, (float) BranchGoogle::for($branch)->rating, 'الإطفاءُ محا المعدّلَ المحفوظ');

        // ولا نداءَ من أيّ باب: الشاشة، والتحديث، والسحب
        $this->get(route('admin.integrations.google'))->assertOk();
        $this->post(route('admin.integrations.google.branch.refresh', $branch->id))->assertSessionHas('toast', fn ($t) => $t['type'] === 'danger');
        GoogleReviews::pull($this->a->id, refresh: true);
        $this->assertCount($sent, Http::recorded(), 'نودي Google وهي مطفأة');
    }

    /* ═══════════════ ١١ · المفتاحُ لا يظهر كاملًا بعد حفظه ═══════════════ */

    public function test_the_saved_key_never_comes_back_whole(): void
    {
        Http::fake();
        $key = 'AIzaSy-FULL-MERCHANT-SECRET-Z9q1';

        $this->post(route('admin.integrations.google.key'), ['google_api_key' => $key])->assertSessionHasNoErrors();

        $page = $this->get(route('admin.integrations.google'))->assertOk();
        $page->assertDontSee($key, false);
        $props = $page->viewData('page')['props'];
        $this->assertSame('••••Z9q1', $props['keyHint']);
        $this->assertArrayNotHasKey('google_api_key', $props['settings']);
        $this->assertStringNotContainsString($key, json_encode($props));

        // ولا في القاعدة نصًّا، ولا في سجلّ النشاط
        $stored = (string) Setting::where('business_id', $this->a->id)->where('key', 'google_api_key')->value('value');
        $this->assertNotSame($key, $stored);
        $this->assertSame($key, Crypt::decryptString($stored));
        $this->assertStringNotContainsString($key, ActivityLog::query()->get()->toJson());

        // ولا في خطأ تحقّق — مفتاحٌ طويلٌ يُردّ بلا أن يُعاد نصُّه
        $long = 'AIza'.str_repeat('X', 300);
        $this->post(route('admin.integrations.google.key'), ['google_api_key' => $long])->assertSessionHasErrors('google_api_key');
        $this->assertStringNotContainsString($long, json_encode(session('errors')?->getBag('default')->toArray()));
    }

    /* ═══════════════ ١٢ · مديرُ المنصّة لا يرى مفتاحَ تاجر ═══════════════ */

    public function test_the_platform_admin_never_sees_a_merchants_key(): void
    {
        GoogleReviews::storeKey($this->a->id, 'AIzaSy-MERCHANT-OF-A-K7w2');

        $boss = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $page = $this->actingAs($boss)->get(route('super-admin.settings.index'))->assertOk();
        $page->assertDontSee('AIzaSy-MERCHANT-OF-A-K7w2', false);
        $this->assertStringNotContainsString('K7w2', json_encode($page->viewData('page')['props']), 'وصل المنصّةَ تلميحُ مفتاح التاجر');

        // ولا يدير مفاتيح التجّار من بابهم: الإعدادُ يُكتب لمتجر الجلسة وحده
        $this->actingAs($boss)->post(route('admin.integrations.google.key'), ['google_api_key' => 'AIza-BOSS-WROTE']);
        $this->assertSame('AIzaSy-MERCHANT-OF-A-K7w2', GoogleReviews::apiKey($this->a->id));
    }

    /* ═══════════════ ١٣ · تاجرٌ لا يمسّ متجرَ تاجر ═══════════════ */

    public function test_a_merchant_cannot_touch_another_merchants_google(): void
    {
        $this->fakePlaces([self::PLACE => $this->placeReply('ورد ب')]);
        GoogleReviews::storeKey($this->b->id, 'AIza-KEY-OF-B');
        $theirs = $this->branchOf($this->b);
        BranchGoogle::link($theirs, self::PLACE);
        $sent = count(Http::recorded());

        $this->actingAs($this->owner($this->a));

        // فروعُ غيره ٤٠٤ — ربطًا وتحديثًا وفكًّا
        $this->post(route('admin.integrations.google.branch.link', $theirs->id), ['place_id' => self::PLACE])->assertNotFound();
        $this->post(route('admin.integrations.google.branch.refresh', $theirs->id))->assertNotFound();
        $this->delete(route('admin.integrations.google.branch.unlink', $theirs->id))->assertNotFound();
        $this->assertSame(self::PLACE, BranchGoogle::for($theirs)?->place_id);

        // ورقمُ متجرٍ في الطلب لا يُبدّل المتجر: مفتاحُه هو يُكتب، ومفتاحُ غيره باقٍ
        $this->post(route('admin.integrations.google.key'), ['google_api_key' => 'AIza-KEY-OF-A', 'business_id' => $this->b->id])->assertSessionHasNoErrors();
        $this->delete(route('admin.integrations.google.key.forget'), ['business_id' => $this->b->id]);
        $this->assertSame('AIza-KEY-OF-B', GoogleReviews::apiKey($this->b->id));

        // ولا يرى تلميحَ مفتاحه ولا يُنادى بمفتاحه
        $page = $this->get(route('admin.integrations.google'))->assertOk();
        $page->assertDontSee('AIza-KEY-OF-B', false)->assertDontSee('••••OF-B', false);
        $this->assertCount($sent, Http::recorded());
    }

    /* ═══════════════ ١٤ · دليلُ المفتاح — يُتمّه التاجر وحده ═══════════════ */

    public function test_the_self_service_guide_covers_every_step(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Integrations/Google.tsx'));

        foreach ([
            'كيف أحصل على مفتاح Google؟',
            'افتح Google Cloud وأنشئ مشروعًا جديدًا',
            'https://console.cloud.google.com/projectcreate',
            'ولا تدفعها أبعاد نيابةً عنك',
            'https://mapsplatform.google.com/pricing/',
            'Places API (New)',
            'https://console.cloud.google.com/apis/library/places.googleapis.com',
            'من قسم Credentials أنشئ API Key جديدًا',
            'لا تشارك مفتاحك مع أي شخص ولا ترسله في المحادثات أو البريد.',
            'قيّد استخدامه بعنوان IP الخاص بخادم أبعاد',
            'API restrictions → Restrict key → Places API (New)',
            'الصق مفتاح Google هنا واضغط حفظ.',
            'بعد حفظ المفتاح، اربط كل فرع بالموقع الصحيح له في Google.',
            'مفتاح واحد يكفي لجميع فروع متجرك. لا تحتاج إلى إنشاء مفتاح جديد لكل فرع.',
            'لم تربط Google بعد. متجرك وفروعك ومبيعاتك تعمل بشكل طبيعي، لكن ميزات Google ستبقى متوقفة.',
            'Google Maps متوقفة لهذا المتجر. لن تُرسل طلبات جديدة إلى Google، وستبقى بيانات الفروع المحفوظة وروابط التقييم كما هي.',
            'الفوترة واستهلاك Google على حسابك في Google، وليس على أبعاد.',
            'يحتاج ربط',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, "الدليل لا يذكر: $needle");
        }

        // ولا سعرَ محفور، ولا «اطلب من الدعم»، ولا «راجعنا»
        $this->assertSame(0, preg_match('/[٠-٩0-9][٠-٩0-9٬,]{2,}\s*نداء/u', $source), 'سعرٌ أو حصّةٌ محفورة في الدليل');
        $this->assertStringNotContainsString('من الدعم', $source);
        $this->assertStringNotContainsString('راجعنا', $source);
    }

    /** وعنوانُ الخادم يُعرض حين يُضبط وحده — وبلا ضبطٍ تحذيرٌ صادق لا عنوانٌ مخترع */
    public function test_the_server_address_is_shown_only_when_configured(): void
    {
        config(['services.outbound_ip' => null]);
        $this->get(route('admin.integrations.google'))->assertInertia(fn ($p) => $p->where('serverIp', null)->etc());

        config(['services.outbound_ip' => '198.51.100.23']);
        $this->get(route('admin.integrations.google'))->assertInertia(fn ($p) => $p->where('serverIp', '198.51.100.23')->etc());

        $source = file_get_contents(resource_path('js/Pages/Admin/Integrations/Google.tsx'));
        $this->assertStringContainsString('data-testid="google-server-ip-missing"', $source);
        $this->assertStringContainsString('فلا تقيّد المفتاح بعنوان IP تخمينًا', $source);
    }

    /* ═══════════════ ١٥ · لا مفتاحَ لأبعاد — لا كودَ ولا صفّ ═══════════════ */

    public function test_the_migration_removes_abaads_key_and_nothing_else(): void
    {
        $this->fakePlaces([self::PLACE => $this->placeReply()]);
        GoogleReviews::storeKey($this->a->id, 'AIza-MERCHANT-A');
        $branch = $this->branchOf($this->a);
        BranchGoogle::link($branch, self::PLACE);
        Setting::create(['business_id' => null, 'key' => 'google_billing_state', 'value' => 'trial']);
        Setting::create(['business_id' => null, 'key' => 'google_trial_ends_at', 'value' => '2026-12-01']);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'app_name'], ['value' => 'أبعاد']);
        $place = DB::table('branch_google_places')->get();

        (require database_path('migrations/2026_10_03_210000_abaad_keeps_no_google_maps_key_of_its_own.php'))->up();

        $this->assertSame(0, Setting::whereNull('business_id')
            ->whereIn('key', ['google_places_key', 'google_billing_state', 'google_trial_ends_at'])->count());
        $this->assertSame('أبعاد', Setting::whereNull('business_id')->where('key', 'app_name')->value('value'));
        $this->assertSame('AIza-MERCHANT-A', GoogleReviews::apiKey($this->a->id), 'الهجرةُ محت مفتاحَ تاجر');
        $this->assertEquals($place, DB::table('branch_google_places')->get(), 'الهجرةُ مسّت أماكنَ الفروع');
    }

    public function test_no_code_can_read_a_platform_places_key(): void
    {
        $this->assertFileDoesNotExist(app_path('Support/GoogleBilling.php'), 'ما زال GoogleBilling');

        foreach (['app', 'routes', 'config', 'resources/js'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($files as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|tsx?)$/', $file->getFilename())) {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                foreach (['platformKey', 'storePlatformKey', 'PLATFORM_KEY', 'GoogleBilling'] as $needle) {
                    $this->assertStringNotContainsString($needle, $code, "$needle في ".$file->getPathname());
                }
                $this->assertSame(0, preg_match("/['\"]google_places_key['\"]/", $code), 'يُقرأ google_places_key في '.$file->getPathname());
            }
        }
    }
}

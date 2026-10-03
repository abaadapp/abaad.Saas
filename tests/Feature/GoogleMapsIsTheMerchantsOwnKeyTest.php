<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\GoogleReviews;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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
 *   ٥. مفتاحُ أبعاد محفوظٌ وتاجرٌ بلا مفتاح: لا يقع عليه أبدًا.
 *   والإطفاءُ يُوقف النداءات ولا يمحو مفتاحًا ولا مكانَ فرع.
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

        // مفتاحُ المنصّة المهجور محفوظ — ليُثبَت أنّه لا يقع على أحد
        GoogleReviews::storePlatformKey('AIza-ABAAD-PLATFORM');

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

    private function placeReply(string $name = 'ورد أ'): array
    {
        return [
            'id' => self::PLACE, 'displayName' => ['text' => $name], 'rating' => 4.7,
            'userRatingCount' => 90, 'googleMapsUri' => 'https://maps.google.com/?cid=1', 'reviews' => [],
        ];
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
        $this->assertStringContainsString('رفضت Google المفتاح', (string) $res->json('error'));

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
        $this->assertSame('AIza-ABAAD-PLATFORM', GoogleReviews::platformKey(), 'التمهيد لم يحفظ مفتاح المنصّة');

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
}

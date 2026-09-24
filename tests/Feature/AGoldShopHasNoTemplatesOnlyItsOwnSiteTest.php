<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\User;
use App\Models\Website;
use App\Support\MarketingSettings;
use App\Support\SalesChannel;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * من لبس واجهةً خاصّة — RIBBON — فموقعُه هي، ولا قوالبَ جاهزةً تُعرض عليه.
 *
 * ═══ ما كان ═══
 *
 * الواجهةُ تُخدم على عنوانه، لكنّ «الموقع الإلكتروني» في لوحته كان يفتح على
 * شاشة الاختيار: ثلاثةُ قوالبَ جاهزة يُدعى إلى أن يبني منها موقعًا ثانيًا لن
 * يراه زبونٌ قطّ — والشاشةُ تقول له إنّ موقعه الحقيقيّ ليس موقعه.
 *
 * ═══ ما صار ═══
 *
 * `‎/website‎` يفتح لوحةَ تشغيل الواجهة: حالُها ورابطُها ومنتجاتُها وطلباتُها.
 * وكلُّ شاشات البانِي — الاختيارُ والإنشاءُ والمحرّرُ والصفحاتُ والتصميم —
 * تردّه إليها. وسائرُ المتاجر على ما كانت عليه.
 */
class AGoldShopHasNoTemplatesOnlyItsOwnSiteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Business $plain;

    private User $plainOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'سعود', 'email' => 'ribbon@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '1']);

        $this->plain = Business::create(['name' => 'عادي', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'plain']);
        Currency::create(['business_id' => $this->plain->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->plainOwner = User::create(['business_id' => $this->plain->id, 'name' => 'جار', 'email' => 'plain@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
    }

    private function props(User $user, string $route, array $params = []): array
    {
        return $this->actingAs($user)->get(route($route, $params))->assertOk()->viewData('page');
    }

    /* ═══════════ لوحةُ الواجهة لا شاشةُ القوالب ═══════════ */

    public function test_the_themed_shop_opens_its_own_hub_not_the_template_picker(): void
    {
        $page = $this->props($this->owner, 'admin.website.index');

        $this->assertSame('Admin/Website/Hub', $page['component']);
        $this->assertSame('ribbon', $page['props']['theme']);
        $this->assertArrayNotHasKey('templates', $page['props']);
        $this->assertSame('published', $page['props']['site']['state']);
        $this->assertSame('ribbon', $page['props']['site']['template']);
        $this->assertStringContainsString('ribbon', (string) $page['props']['site']['url']);
        $this->assertSame([], $page['props']['readiness']);
        $this->assertSame('checkout', $page['props']['channel']);
        $this->assertSame(0, $page['props']['orders']['today']);
    }

    public function test_a_plain_shop_still_picks_a_template(): void
    {
        $page = $this->props($this->plainOwner, 'admin.website.index');

        $this->assertSame('Admin/Website/Wizard', $page['component']);
        $this->assertNotEmpty($page['props']['templates']);
        $this->assertNull($page['props']['context']['storefrontTheme']);
    }

    public function test_the_theme_is_shared_with_every_screen(): void
    {
        $this->assertSame('ribbon', $this->props($this->owner, 'admin.dashboard')['props']['context']['storefrontTheme']);
    }

    /** ومن أطفأ «نشر المتجر» يُقال له إنّ موقعه لا يفتح — لا «مسوّدة» بلا معنى */
    public function test_an_unpublished_themed_site_says_so(): void
    {
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '0']);

        $props = $this->props($this->owner, 'admin.website.index')['props'];

        $this->assertSame('draft', $props['site']['state']);
        $this->assertNull($props['site']['url']);
        $this->assertCount(1, $props['readiness']);
        $this->assertFalse($props['readiness'][0]['ok']);
        $this->assertStringContainsString('نشر المتجر', $props['readiness'][0]['detail']);
    }

    /** وطلباتُ الموقع تُعدّ من الدفتر — بقناة الموقع ولليوم وحده، ولهذا المتجر وحده */
    public function test_todays_website_orders_are_counted(): void
    {
        $mk = fn (int $bid, string $channel, string $at) => Order::create([
            'business_id' => $bid, 'number' => 'W'.random_int(1000, 999999), 'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => 'جديد', 'is_held' => false, 'channel' => $channel,
            'ordered_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        $mk($this->business->id, SalesChannel::WEBSITE, now()->toDateTimeString());
        $mk($this->business->id, SalesChannel::WEBSITE, now()->toDateTimeString());
        $mk($this->business->id, SalesChannel::POS, now()->toDateTimeString());
        $mk($this->business->id, SalesChannel::WEBSITE, now()->subDays(2)->toDateTimeString());
        $mk($this->plain->id, SalesChannel::WEBSITE, now()->toDateTimeString());

        $this->assertSame(2, $this->props($this->owner, 'admin.website.index')['props']['orders']['today']);
    }

    /** موظّفُ التشغيل يرى اللوحة نفسَها — لا «لا موقعَ بعد» */
    public function test_an_operator_of_the_themed_shop_sees_the_hub(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'staff@abaad.om',
            'password' => bcrypt('x'), 'role' => 'staff', 'status' => 'نشط', 'permissions' => ['website'],
        ]);

        $page = $this->props($staff, 'admin.website.index');

        $this->assertSame('Admin/Website/Hub', $page['component']);
        $this->assertFalse($page['props']['may']['configure']);
    }

    /* ═══════════ ولا بابَ إلى البانِي ═══════════ */

    public function test_the_themed_shop_cannot_build_a_site_from_a_template(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.website.create'), ['template' => 'mono'])
            ->assertRedirect(route('admin.website.index'));

        $this->assertSame(0, Website::where('business_id', $this->business->id)->count());

        // والجارُ يبني كما كان
        $this->actingAs($this->plainOwner)->post(route('admin.website.create'), ['template' => 'mono']);
        $this->assertSame(1, Website::where('business_id', $this->plain->id)->count());
    }

    /**
     * وما بقي من شاشات البانِي — وهو ما **لا وجود له** عنده.
     *
     * «التصميم» قالبٌ يُبدَّل وألوانٌ تُختار، وواجهتُه تصميمُ صاحبها في قالبٍ
     * واحد. و«الصفحات» صفحاتٌ تُضاف وتُحذف، وواجهتُه صفحةٌ واحدة يفتح
     * محرّرَها تبويبُ «صفحة المتجر». و«السيو» يكتب في صفٍّ لا يملكه.
     */
    public static function builderScreens(): array
    {
        return [
            'التصميم' => ['admin.website.design'],
            'الصفحات' => ['admin.website.pages'],
            'السيو' => ['admin.website.seo'],
        ];
    }

    /** وحتى من بُني له موقعٌ قبل أن يلبس واجهته — شاشاتُ البانِي تردّه إلى لوحة واجهته */
    #[DataProvider('builderScreens')]
    public function test_builder_screens_send_the_themed_shop_back_to_its_hub(string $route): void
    {
        Builder::create($this->business, Blueprints::STORE, 'mono', $this->owner->id);

        $this->actingAs($this->owner)->get(route($route))->assertRedirect(route('admin.website.index'));
        $this->actingAs($this->owner)->post(route('admin.website.publish'))->assertRedirect(route('admin.website.index'));
    }

    /**
     * ═══ وأربعُ شاشاتٍ صارت له على مسارات جاره نفسِها ═══
     *
     * وهذا هو الشكلُ الذي طُلب: من فتح «الموقع الإلكتروني» في أيّ متجرٍ من
     * متاجر أبعاد يجد ترويسةً وشريطَ تبويباتٍ وبطاقةَ حالٍ وأبوابًا. وكان
     * صاحبُ الواجهة الخاصّة يُساق إلى بطاقةٍ في «الإعدادات» فيها ثلاثون
     * مقبضًا بلا شريطٍ ولا أبواب — لوحتان مختلفتان للشيء نفسه.
     *
     * والمساراتُ نفسُها لا مساراتٌ ثانية: رابطٌ يُحفظ أو يُشارَك يعمل عند
     * الاثنين، ولا يتعلّم أحدُهما عنوانًا لا يعرفه الآخر.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function themedScreens(): array
    {
        return [
            'عام' => ['admin.website.site', 'Admin/Website/ThemeSite'],
            'صفحة المتجر' => ['admin.website.editor', 'Admin/Website/ThemeEditor'],
            'المتجر والطلبات' => ['admin.website.shop', 'Admin/Website/ThemeShop'],
            'العنوان والنشر' => ['admin.website.domain', 'Admin/Website/ThemeDomain'],
        ];
    }

    #[DataProvider('themedScreens')]
    public function test_the_themed_shop_has_its_own_screens_on_the_same_paths(string $route, string $component): void
    {
        // وحتى من بُني له موقعٌ قبل أن يلبس واجهته: لا يُفتح له البانِي هنا
        Builder::create($this->business, Blueprints::STORE, 'mono', $this->owner->id);

        $this->assertSame($component, $this->props($this->owner, $route)['component']);
    }

    /** والجارُ يبقى على شاشات البانِي — لا يُساق إلى شاشات واجهةٍ لا يلبسها */
    #[DataProvider('themedScreens')]
    public function test_a_plain_shop_keeps_the_builder_screens(string $route, string $component): void
    {
        Builder::create($this->plain, Blueprints::STORE, 'mono', $this->plainOwner->id);

        $this->assertNotSame($component, $this->props($this->plainOwner, $route)['component']);
    }

    /* ═══════════ والمعاينةُ تعاين الواجهة ═══════════ */

    public function test_the_settings_preview_shows_the_themed_storefront(): void
    {
        $this->actingAs($this->owner)->get(route('admin.store.preview'))
            ->assertOk()
            ->assertViewIs('store.ribbon.home')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->actingAs($this->plainOwner)->get(route('admin.store.preview'))
            ->assertOk()
            ->assertViewIs('store.show');
    }
}

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
        /*
         * وقائمةُ الجاهزية انتقلت إلى هنا من شاشة «عام» — تلك شاشةٌ لا
         * يُضبط فيها شيء، وقد حُذفت حين صار الضبطُ صفحةً واحدة. و«أين
         * متجري الآن» سؤالُ لوحةٍ لا سؤالُ نموذج.
         */
        $this->assertNotSame([], $page['props']['readiness']);

        $facts = collect($page['props']['readiness'])->keyBy('key');
        $this->assertTrue($facts['slug']['ok'], 'له عنوانٌ — فالسطرُ يقول ذلك');
        $this->assertTrue($facts['published']['ok'], 'ومنشورٌ — فالسطرُ يقول ذلك');
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
        /* وما ينقص يُقال في القائمة نفسِها — و«النشر» أوّلُ ما ينقص */
        $missing = array_values(array_filter(
            $props['readiness'],
            fn ($f) => ! $f['ok'] && ! $f['optional'],
        ));

        $this->assertNotSame([], $missing, 'متجرٌ لم يُنشر ولا شيء يقول ذلك');
        $this->assertContains('published', array_column($missing, 'key'));
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
     * وما يبقى ممنوعًا عليه — بناءُ موقعٍ من قالبٍ ونشرُه.
     *
     * وهذا وحدَه: واجهتُه تقرأ إعداداته في اللحظة، فلا لقطةَ تُنشر ولا
     * قالبَ يُبدَّل. وما عدا ذلك من شاشات القسم صار له مثلُه على المسارات
     * نفسِها — انظر `themedScreens` أدناه.
     */
    public function test_the_themed_shop_still_cannot_publish_a_built_site(): void
    {
        Builder::create($this->business, Blueprints::STORE, 'mono', $this->owner->id);

        $this->actingAs($this->owner)->post(route('admin.website.publish'))
            ->assertRedirect(route('admin.website.index'));
    }

    /**
     * و«التصميم» يصل إلى محرّر صفحته — كما يصل عند جاره إلى لوحة تصميمه.
     *
     * ولا يُردّ إلى لوحة التشغيل كما كان: تبويبٌ يُعرض ثمّ يقذف من ضغطه
     * إلى شاشةٍ أخرى يُعلّمه أنّ الشريط لا يُصدَّق.
     */
    public function test_design_opens_his_page_editor(): void
    {
        Builder::create($this->business, Blueprints::STORE, 'mono', $this->owner->id);

        $this->actingAs($this->owner)->get(route('admin.website.design'))
            ->assertRedirect(route('admin.website.editor'));
    }

    /**
     * ═══ وستُّ شاشاتٍ له على مسارات جاره نفسِها ═══
     *
     * والمساراتُ نفسُها لا مساراتٌ ثانية: رابطٌ يُحفظ أو يُشارَك يعمل عند
     * الاثنين، ولا يتعلّم أحدُهما عنوانًا لا يعرفه الآخر. وما خلفها من
     * صنعةٍ أخرى: `Theme*` لمن لبس واجهةً خاصّة، وشاشاتُ البانِي لجاره.
     *
     * ═══ وقد جُمعت مرّةً ثمّ رُدّت ═══
     *
     * قِيست الستُّ يومًا فوُجد التوزيعُ غيرَ عادل: ثلاثٌ فيها ثلاثةُ مقابض،
     * وواحدةٌ فيها اثنان وثلاثون ومعها زرّا حفظٍ متجاوران. فجُمعت في صفحةٍ
     * واحدة بعمودٍ يقفز. ثمّ رُدّت ستًّا بقرارٍ من صاحب المنتج: أن يكون
     * لصاحب الواجهة ما لجاره حرفًا بحرف.
     *
     * والعطبُ المقيسُ لم يُهمَل: «المتجر والطلبات» صارت مجموعاتٍ بزرّ حفظٍ
     * **واحد** (انظر `ThemeStore`)، وكلُّ شاشةٍ تُرسل مفاتيحَها وحدَها فلا
     * تمحو ما ضبطته أختُها (انظر `AThemedShopWearsTheSameWebsiteShellTest`).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function themedScreens(): array
    {
        return [
            'عام' => ['admin.website.site', 'Admin/Website/ThemeSettings'],
            'صفحة المتجر' => ['admin.website.editor', 'Admin/Website/ThemeEditor'],
            'الصفحات' => ['admin.website.pages', 'Admin/Website/ThemePages'],
            'المتجر والطلبات' => ['admin.website.shop', 'Admin/Website/ThemeStore'],
            'الدومين' => ['admin.website.domain', 'Admin/Website/ThemeDomain'],
            'الظهور في البحث' => ['admin.website.seo', 'Admin/Website/ThemeSeo'],
        ];
    }

    /**
     * ولا يُحوَّل مسارٌ منها إلى مرساةٍ في صفحةٍ أخرى.
     *
     * وكانت الأربعةُ تُحوَّل إلى `site#anchor` يومَ كان الضبطُ صفحةً واحدة.
     * فمن حفظ إشارةً مرجعيّةً إلى «الدومين» وقف على أوّل صفحةٍ طويلة يبحث
     * فيها. والآن يقف على شاشته — ويُسأل هنا عن الجواب نفسِه: ٢٠٠ لا تحويل.
     */
    public function test_no_tab_is_a_redirect_to_an_anchor(): void
    {
        Builder::create($this->business, Blueprints::STORE, 'mono', $this->owner->id);

        foreach (['admin.website.shop', 'admin.website.seo', 'admin.website.domain', 'admin.website.pages'] as $name) {
            $this->actingAs($this->owner)->get(route($name))
                ->assertOk($name.' ما زال يُحوَّل إلى مرساة');
        }
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

<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\PlanFeatures;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * الموقعُ الإلكترونيّ يُباع مع الباقة — لوحتُه وعنوانُه العامّ معًا.
 *
 * ═══ ما يُحرَس ═══
 *
 *   - كلُّ مسارٍ في عائلة الموقع — `admin.website.*` والأبوابُ القديمة تحت
 *     التسويق ومعاينةُ المتجر — يُردّ بـ403 على باقةٍ لا تبيعه. ويُقرأ من
 *     جدول المسارات لا من قائمةٍ هنا: بابٌ يُضاف غدًا يُقاس يومَ يُكتب.
 *   - والموقعُ العامّ: الرئيسيّةُ والصفحاتُ الداخليّة والسلّةُ وإتمامُ الطلب
 *     وكرتُ الهديّة — ٤٠٤ بلا طلبٍ يُكتب.
 *   - والاحترافيّةُ تعمل كما كانت.
 *   - والتنزيلُ يُغلق ولا يمحو؛ والترقيةُ تُعيد الموقعَ كما كان بلا بناء.
 *   - ولكلّ متجرٍ باقتُه: إغلاقُ موقع متجرٍ لا يمسّ جاره.
 */
class TheWebsiteIsSoldWithThePlanTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        // كما تزرعها البذرة الجديدة — الأساسيّةُ واتساب وحده
        $this->basic = Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => ['whatsapp']]);
        $this->pro = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => PlanFeatures::keys()]);
    }

    /** متجرٌ بواجهةٍ خاصّة منشورة — يبيع من سلّته */
    private function shop(Plan $plan, string $slug = 'ribbon'): Business
    {
        $shop = Business::create([
            'name' => 'RIBBON '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon', 'plan_id' => $plan->id,
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد أحمر', 'price' => 20, 'cost' => 8,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $shop->fresh();
    }

    private function owner(Business $shop): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o'.$shop->id.'@site.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function checkout(string $slug)
    {
        $product = Product::whereHas('business', fn ($q) => $q->where('site_slug', $slug))->firstOrFail();

        return $this->postJson('/s/'.$slug.'/checkout', [
            'items' => [['id' => $product->id, 'qty' => 1]],
            'name' => 'Ahmed', 'phone' => '95259066', 'fulfil' => 'pickup', 'date' => now()->addDay()->toDateString(), 'pay' => 'cod',
        ]);
    }

    /** @return list<Route> كلُّ مسارٍ يطلب قدرةَ الموقع — من جدول المسارات */
    private function websiteRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => CheckPlanFeature::featureFor((string) $r->getName()) === 'website')
            ->values()->all();
    }

    private function hit(Route $route)
    {
        $params = collect($route->parameterNames())->mapWithKeys(fn ($p) => [$p => 1])->all();
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return $this->call($method, route($route->getName(), $params), [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /* ═════════════ لوحةُ الموقع ═════════════ */

    public function test_every_website_door_refuses_a_plan_without_the_website(): void
    {
        $owner = $this->owner($this->shop($this->basic));
        $routes = $this->websiteRoutes();

        // العائلةُ كلُّها في الخريطة — لا بابٌ واحد يمرّ لأنّه نُسي
        $names = array_map(fn (Route $r) => $r->getName(), $routes);
        foreach (['admin.website.index', 'admin.website.create', 'admin.website.publish', 'admin.website.restore', 'admin.website.maintenance',
            'admin.website.settings.save', 'admin.website.pages', 'admin.website.editor', 'admin.website.sections.update', 'admin.website.media',
            'admin.website.design', 'admin.website.shop', 'admin.website.seo', 'admin.website.domain', 'admin.website.products',
            'admin.website.store.publish', 'admin.website.store.restore', 'admin.marketing.website', 'admin.marketing.website.save',
            'admin.marketing.store.save', 'admin.marketing.store.image', 'admin.marketing.store.products', 'admin.marketing.domain.path',
            'admin.marketing.seo', 'admin.marketing.seo.save', 'admin.store.preview'] as $expected) {
            $this->assertContains($expected, $names, "«{$expected}» لا يطلب قدرةَ الموقع");
        }

        foreach ($routes as $route) {
            $response = $this->actingAs($owner)->hit($route);
            $this->assertSame(403, $response->status(), "«{$route->getName()}» فُتح لباقةٍ لا تبيع الموقع");
        }

        $this->actingAs($owner)->getJson(route('admin.website.index'))
            ->assertForbidden()
            ->assertJsonPath('message', '«الموقع الإلكتروني» ليست في باقة «الباقة الأساسية». رقِّ الباقة لفتحها.');
    }

    public function test_the_professional_plan_opens_the_website_screens(): void
    {
        $owner = $this->owner($this->shop($this->pro));

        foreach (['admin.website.index', 'admin.website.pages', 'admin.website.design', 'admin.website.domain', 'admin.website.seo', 'admin.store.preview'] as $name) {
            $status = $this->actingAs($owner)->get(route($name))->status();
            $this->assertNotSame(403, $status, "«{$name}» رُدّ على الاحترافيّة");
            $this->assertLessThan(500, $status, "«{$name}» سقط على الاحترافيّة");
        }
    }

    /* ═════════════ الموقعُ العامّ ═════════════ */

    public function test_the_public_site_and_its_checkout_are_closed_without_the_website(): void
    {
        $shop = $this->shop($this->basic);

        $this->get('/s/ribbon')->assertNotFound();
        $this->get('/s/ribbon/shop')->assertNotFound();
        $this->postJson('/s/ribbon/quote', ['items' => []])->assertNotFound();
        $this->postJson('/s/ribbon/gift-card', [])->assertNotFound();
        $this->checkout('ribbon')->assertNotFound();

        $this->assertSame(0, Order::where('business_id', $shop->id)->count(), 'طلبٌ كُتب من موقعٍ مغلق');
        $this->assertSame(Storefront::SERVES_NONE, Storefront::serves($shop), 'النطاقُ الخاصّ يقرأ هذا السؤال نفسَه');
        $this->assertFalse(Storefront::serving($shop));
    }

    public function test_the_professional_site_sells_as_before(): void
    {
        $shop = $this->shop($this->pro);

        $this->get('/s/ribbon')->assertOk();
        $this->get('/s/ribbon/shop')->assertOk()->assertSee('باقة ورد أحمر');
        $this->checkout('ribbon')->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, Order::where('business_id', $shop->id)->count());
    }

    /** والتنزيلُ يُغلق ولا يمحو — والترقيةُ تُعيد الموقعَ كما كان */
    public function test_a_downgrade_hides_the_site_and_an_upgrade_brings_it_back_untouched(): void
    {
        $shop = $this->shop($this->pro);
        $before = [
            'slug' => $shop->site_slug,
            'settings' => MarketingSettings::group($shop->id, 'website'),
            'products' => Product::where('business_id', $shop->id)->count(),
        ];
        $this->get('/s/ribbon')->assertOk();

        $shop->update(['plan_id' => $this->basic->id]);
        $this->get('/s/ribbon')->assertNotFound();

        $shop->refresh();
        $this->assertSame($before['slug'], $shop->site_slug);
        $this->assertSame($before['settings'], MarketingSettings::group($shop->id, 'website'));
        $this->assertSame($before['products'], Product::where('business_id', $shop->id)->count());

        $shop->update(['plan_id' => $this->pro->id]);
        $this->get('/s/ribbon/shop')->assertOk()->assertSee('باقة ورد أحمر');
    }

    /** ولكلّ متجرٍ باقتُه — إغلاقُ موقع متجرٍ لا يمسّ جاره، ولا يُفتح بلوحة جاره */
    public function test_one_shops_plan_never_decides_for_another(): void
    {
        $open = $this->shop($this->pro, 'open-shop');
        $closed = $this->shop($this->basic, 'closed-shop');

        $this->get('/s/open-shop')->assertOk();
        $this->get('/s/closed-shop')->assertNotFound();

        $this->actingAs($this->owner($open))->get(route('admin.website.index'))->assertOk();
        $this->actingAs($this->owner($closed))->get(route('admin.website.index'))->assertForbidden();
    }

    /** وزرُّ «موقعي» في الترويسة لا يقود إلى ٤٠٤ */
    public function test_the_header_link_does_not_point_at_a_closed_site(): void
    {
        $owner = $this->owner($this->shop($this->basic));

        $website = $this->actingAs($owner)->get(route('admin.dashboard'))->viewData('page')['props']['context']['website'] ?? null;

        $this->assertNull($website);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\MarketingSettings;
use App\Support\PlanFeatures;
use App\Support\SeedData;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * الأساسيّةُ تُشغّل المتجرَ يومَه — ولا شيءَ فوق ذلك.
 *
 * ═══ القرار (2026-10-13، الثاني) ═══
 *
 * 9.900 شهريًّا و99.000 سنويًّا، فرعٌ وثلاثةُ موظّفين ومئةُ منتج. ولا تفتح
 * قدرةً: لا واتساب، ولا موقع، ولا تطبيقات تكامليّة، ولا ولاء، ولا أدوات
 * تسويق، ولا تقارير متقدّمة، ولا صلاحيات مخصّصة. قائمتُها `[]` لا `null`.
 *
 * ═══ وما يُحرس هنا ═══
 *
 *   - البذرة: الأساسيّةُ `[]`، والاحترافيّةُ والمؤسساتُ تفتحان التكاملات.
 *   - واتساب مغلقٌ في كلّ بابه: الشاشات، والربط، والإرسالُ الآليّ (`SKIP_PLAN`).
 *   - الموقعُ مغلقٌ: لوحتُه ومعاينتُه وواجهتُه العامّة.
 *   - والتشغيلُ اليوميُّ مفتوح: اللوحةُ والمبيعاتُ والمنتجاتُ والعملاءُ
 *     والمخزونُ والمشترياتُ والفواتيرُ والتقاريرُ على الشاشة والإعدادات.
 *   - ولا يُقرأ اسمُ «الباقة الأساسية» في قرارٍ قطّ — البذرةُ وحدها تكتبه.
 */
class TheBasicPlanRunsTheShopAndNothingMoreTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Plan> */
    private array $plans = [];

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        foreach (SeedData::plans() as $spec) {
            [$branches, $employees, $products] = $spec['limits'];
            $this->plans[$spec['name']] = Plan::create([
                'name' => $spec['name'], 'monthly_price' => $spec['monthly'], 'yearly_price' => $spec['yearly'],
                'features' => $spec['features'], 'capabilities' => $spec['capabilities'],
                'max_branches' => $branches, 'max_employees' => $employees, 'max_products' => $products,
            ]);
        }

        $this->shop = Business::create([
            'name' => 'محل يومي', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
            'plan_id' => $this->basic()->id,
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@basic.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function basic(): Plan
    {
        return $this->plans['الباقة الأساسية'];
    }

    /** المستخدمُ يُقرأ من جديد في كلّ نداء — كما في كلّ طلبٍ حقيقيّ */
    private function as()
    {
        return $this->actingAs($this->owner->fresh());
    }

    private function hit(Route $route)
    {
        $params = collect($route->parameterNames())->mapWithKeys(fn ($p) => [$p => 1])->all();
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return $this->as()->call($method, route($route->getName(), $params), [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @return list<Route> */
    private function routesFor(string $feature): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => CheckPlanFeature::featureFor((string) $r->getName()) === $feature)
            ->values()->all();
    }

    /* ═══════════ ١ · البذرة ═══════════ */

    public function test_basic_opens_no_capability_and_the_others_open_integrations(): void
    {
        $this->assertSame([], $this->basic()->fresh()->capabilities, '`[]` صارت شيئًا آخر');

        foreach (PlanFeatures::keys() as $key) {
            $this->assertFalse(PlanFeatures::allowedBy($this->basic()->fresh(), $key), "الأساسيّةُ تفتح «{$key}»");
        }

        foreach (['الباقة الاحترافية', 'باقة المؤسسات'] as $name) {
            $this->assertContains('integrations', $this->plans[$name]->capabilities, $name);
            $this->assertTrue(PlanFeatures::allowedBy($this->plans[$name], 'integrations'), $name);
        }
    }

    /* ═══════════ ٢ · واتساب ═══════════ */

    public function test_whatsapp_is_closed_on_basic_screens_setup_and_sending(): void
    {
        Bus::fake();

        foreach ($this->routesFor('whatsapp') as $route) {
            $this->assertSame(403, $this->hit($route)->status(), "«{$route->getName()}» فُتح على الأساسيّة");
        }

        // والإرسالُ الآليّ يسكت بالباقة — لا بإطفاء المتجر ولا المنصّة
        $this->shop->update(['whatsapp_enabled' => true]);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppFeature::GLOBAL_KEY], ['value' => '1']);
        $this->assertSame(WhatsAppStatus::SKIP_PLAN, WhatsAppFeature::blockReason($this->shop->fresh()));

        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '97778888']);
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'S-1', 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'customer_id' => $customer->id,
            'customer_name' => 'سالم',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->assertNull(WhatsAppAutomation::handle($order, WhatsAppEvent::ORDER_CONFIRMED));
        $this->assertSame(0, WhatsAppMessage::where('business_id', $this->shop->id)->count());
        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }

    /* ═══════════ ٣ · الموقع ═══════════ */

    public function test_the_website_is_closed_on_basic_panel_preview_and_storefront(): void
    {
        foreach ($this->routesFor('website') as $route) {
            $this->assertSame(403, $this->hit($route)->status(), "«{$route->getName()}» فُتح على الأساسيّة");
        }

        Product::create(['business_id' => $this->shop->id, 'name' => 'بوكيه', 'price' => 10, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true]);
        $this->shop->forceFill(['site_slug' => 'yawmi'])->save();
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);

        $this->get(route('store.show', 'yawmi'))->assertNotFound();
    }

    /* ═══════════ ٤ · والتشغيلُ اليوميُّ مفتوح ═══════════ */

    public function test_the_daily_operations_stay_open_on_basic(): void
    {
        Http::fake();

        foreach ([
            'admin.dashboard', 'pos.index', 'admin.orders.index', 'admin.products.index', 'admin.customers.index',
            'admin.inventory.index', 'admin.purchases.index', 'admin.customerInvoices.index',
            'admin.finance.paymentLinks', 'admin.reports.index', 'admin.reports.sales', 'admin.settings.index',
            // وPaymob — بنيةُ روابط الدفع على الفواتير
            'admin.integrations.paymob',
        ] as $name) {
            $status = $this->as()->get(route($name))->status();
            $this->assertNotSame(403, $status, "«{$name}» أُغلق على الأساسيّة");
            $this->assertLessThan(500, $status, "«{$name}» سقط على الأساسيّة");
        }
    }

    /* ═══════════ ٥ · ولا قرارَ باسم الباقة ═══════════ */

    public function test_no_decision_reads_the_basic_plan_by_its_name(): void
    {
        /*
         * نصٌّ حرفيٌّ في الكود لا في تعليق: الشرحُ يذكر الأساسيّةَ باسمها ليُفهم،
         * والقرارُ لا يقرؤه. فتُقرأ رموزُ PHP لا سطورُها.
         */
        $readers = collect(File::allFiles(app_path()))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->filter(fn ($f) => collect(token_get_all(file_get_contents($f->getPathname())))
                ->contains(fn ($t) => is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], 'الباقة الأساسية')))
            ->map(fn ($f) => str_replace(app_path().'/', '', $f->getPathname()))
            ->values()->all();

        // البذرةُ تكتب الاسمَ لتُنشئ الباقة — ولا يقرؤه حارسٌ ولا متحكّم
        $this->assertSame(['Support/SeedData.php'], $readers);
    }
}

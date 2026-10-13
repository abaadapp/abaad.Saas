<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\PlanFeatures;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * أدواتُ التسويق تُباع مع الباقة — والولاءُ وواتساب لكلٍّ قدرتُه.
 *
 * ═══ ما يُحرَس ═══
 *
 *   - `marketing_tools` تُغلق التقييماتِ والكوبونات وحدهما — شاشةً وكتابة.
 *   - ولا تُغلق الولاءَ ولا واتساب: الأوّلُ تحت `loyalty` والثاني تحت
 *     `whatsapp`، وكلٌّ يُفتح ويُغلق وحده.
 *   - والأساسيّةُ تبقى على رقم أبعاد: حارسُ الإرسال لا يردّها بالباقة.
 *   - وتكاملُ Google ليس منها: ردُّ تقييمات Google يبقى مفتوحًا.
 */
class MarketingToolsAreSoldWithThePlanTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@tools.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function onPlan(array $capabilities): void
    {
        $plan = Plan::create(['name' => 'باقة', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => $capabilities]);
        $this->shop->update(['plan_id' => $plan->id]);
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

    public function test_the_tools_are_exactly_reviews_and_coupons(): void
    {
        $tools = collect(Router::getRoutes()->getRoutes())
            ->map(fn (Route $r) => (string) $r->getName())
            ->filter(fn ($n) => CheckPlanFeature::featureFor($n) === 'marketing_tools')
            ->sort()->values()->all();

        $this->assertSame([
            'admin.coupons.destroy', 'admin.coupons.limits', 'admin.coupons.store', 'admin.coupons.toggle',
            'admin.marketing.coupons',
            'admin.marketing.reviews', 'admin.marketing.reviews.destroy', 'admin.marketing.reviews.reply',
            'admin.marketing.reviews.status', 'admin.marketing.reviews.store',
        ], $tools);

        // ولا تقع على ما له قدرتُه — ولا على تكامل Google
        $this->assertSame('loyalty', CheckPlanFeature::featureFor('admin.marketing.loyalty'));
        $this->assertSame('whatsapp', CheckPlanFeature::featureFor('admin.marketing.whatsapp'));
        $this->assertSame('whatsapp', CheckPlanFeature::featureFor('admin.marketing.whatsapp.log'));
        // والردُّ على تقييمات Google من التطبيقات التكامليّة لا من أدوات التسويق
        $this->assertSame('integrations', CheckPlanFeature::featureFor('admin.integrations.googleBusiness.reply'));
        $this->assertNull(CheckPlanFeature::featureFor('admin.marketing.index'));
    }

    public function test_a_plan_without_the_tools_is_refused_every_tool_door(): void
    {
        $this->onPlan(['whatsapp']);

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (CheckPlanFeature::featureFor((string) $route->getName()) !== 'marketing_tools') {
                continue;
            }

            $this->assertSame(403, $this->hit($route)->status(), "«{$route->getName()}» فُتح بلا أدوات التسويق");
        }

        $this->as()->post(route('admin.coupons.store'), ['code' => 'EID', 'type' => 'نسبة', 'value' => 10]);
        $this->assertSame(0, Coupon::where('business_id', $this->shop->id)->count(), 'كوبونٌ أُنشئ بلا أدوات التسويق');
    }

    public function test_a_plan_with_the_tools_opens_them(): void
    {
        $this->onPlan(PlanFeatures::keys());

        $this->as()->get(route('admin.marketing.reviews'))->assertOk();
        $this->as()->get(route('admin.marketing.coupons'))->assertOk();
        $this->as()->post(route('admin.coupons.store'), ['code' => 'EID', 'type' => 'نسبة', 'value' => 10])->assertSessionHasNoErrors();

        $this->assertSame(1, Coupon::where('business_id', $this->shop->id)->count());
    }

    /** والولاءُ بقدرته وحدها — يُفتح بلا الأدوات ويُغلق معها */
    public function test_loyalty_answers_to_loyalty_alone(): void
    {
        $this->onPlan(['loyalty']);
        $this->as()->get(route('admin.marketing.loyalty'))->assertOk();
        $this->as()->get(route('admin.marketing.reviews'))->assertForbidden();

        $this->onPlan(['marketing_tools']);
        $this->as()->get(route('admin.marketing.loyalty'))->assertForbidden();
        $this->as()->get(route('admin.marketing.reviews'))->assertOk();
    }

    /** وواتساب بقدرته وحدها — والأساسيّةُ تبقى على رقم أبعاد */
    public function test_whatsapp_answers_to_whatsapp_alone_and_basic_keeps_abaads_number(): void
    {
        $this->onPlan(['whatsapp']);
        $this->assertNotSame(403, $this->as()->get(route('admin.marketing.whatsapp'))->status());

        $this->shop->update(['whatsapp_enabled' => true]);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppFeature::GLOBAL_KEY], ['value' => '1']);
        $this->assertNotSame(WhatsAppStatus::SKIP_PLAN, WhatsAppFeature::blockReason($this->shop->fresh()), 'الأساسيّةُ رُدّت عن رقم أبعاد بالباقة');

        $this->onPlan(['marketing_tools']);
        $this->as()->get(route('admin.marketing.whatsapp'))->assertForbidden();
        $this->as()->get(route('admin.marketing.coupons'))->assertOk();
    }
}

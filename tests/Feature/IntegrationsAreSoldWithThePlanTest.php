<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\Branch;
use App\Models\Business;
use App\Models\GoogleBusinessAccount;
use App\Models\Plan;
use App\Models\User;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use App\Support\PlanFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * التطبيقاتُ التكامليّة تُباع مع الباقة — وPaymob للجميع، وواتساب بقدرته.
 *
 * ═══ قرارُ المالك (2026-10-13) ═══
 *
 *   - صفحةُ التكاملات وGoogle وGoogle Business تحت `integrations`.
 *   - ربطُ واتساب تحت `whatsapp` — لا تحت التكاملات.
 *   - Paymob مفتوحٌ لكلّ باقة: بنيةُ دفعٍ لفواتير العملاء وروابط الدفع.
 *
 * ═══ وما يُحرس هنا ═══
 *
 *   - كلُّ بابٍ تحت `admin.integrations.` مقرَّرٌ: محروسٌ، أو مفتوحٌ بالاسم.
 *   - الباقةُ بلا التكاملات تُردّ عن كلّ بابٍ منها — قراءةً وكتابة، بالرابط
 *     المباشر — ولا يُكتب شيء.
 *   - `connect/{tool}` يتبع الأداة: Google بالتكاملات وواتساب بواتساب.
 *   - Paymob مفتوحٌ على باقةٍ لا تملك شيئًا، والردودُ العامّة لا تمرّ بالباقة.
 */
class IntegrationsAreSoldWithThePlanTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // الطريقُ السابق — رمزٌ من تطبيق أبعاد للتاجر؛ والافتراضُ اليوم تطبيقُ التاجر (`WhatsAppOwnApp`)
        config(['whatsapp.merchant_link' => 'abaad_app']);
        app()->setLocale('ar');
        Http::fake();

        $this->shop = Business::create(['name' => 'متجر التكاملات', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@integrations-plan.om',
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

    /** @return list<Route> */
    private function integrationRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with((string) $r->getName(), 'admin.integrations.'))
            ->values()->all();
    }

    /* ═══════════ ١ · كلُّ بابٍ مقرَّر ═══════════ */

    public function test_every_integrations_door_is_decided(): void
    {
        $undecided = collect($this->integrationRoutes())
            ->map(fn (Route $r) => (string) $r->getName())
            ->reject(fn ($n) => in_array(CheckPlanFeature::featureFor($n), ['integrations', 'whatsapp'], true))
            ->reject(fn ($n) => in_array($n, CheckPlanFeature::OPEN_INTEGRATIONS, true))
            // يُحرس في متحكّمه بأداته — انظر `test_connect_follows_the_tool`
            ->reject(fn ($n) => $n === 'admin.integrations.connect')
            ->values()->all();

        $this->assertSame([], $undecided, 'بابٌ تحت التكاملات لم يُقرَّر: احرسه أو اذكره مفتوحًا بالاسم');

        $this->assertSame('integrations', CheckPlanFeature::featureFor('admin.integrations.index'));
        $this->assertSame('integrations', CheckPlanFeature::featureFor('admin.integrations.google.key'));
        $this->assertSame('integrations', CheckPlanFeature::featureFor('admin.integrations.googleBusiness.callback'));
        $this->assertSame('whatsapp', CheckPlanFeature::featureFor('admin.integrations.whatsapp.connect'));
        $this->assertNull(CheckPlanFeature::featureFor('admin.integrations.paymob'));
        $this->assertNull(CheckPlanFeature::featureFor('admin.integrations.paymob.save'));
    }

    /* ═══════════ ٢ · الردُّ قراءةً وكتابة ═══════════ */

    public function test_a_plan_without_integrations_is_refused_every_integrations_door(): void
    {
        $this->onPlan([]);

        $guarded = collect($this->integrationRoutes())
            ->filter(fn (Route $r) => CheckPlanFeature::featureFor((string) $r->getName()) === 'integrations');

        $this->assertGreaterThan(15, $guarded->count(), 'الخريطةُ لا تحرس أبوابَ Google');

        foreach ($guarded as $route) {
            $this->assertSame(403, $this->hit($route)->status(), "«{$route->getName()}» فُتح بلا التكاملات");
        }
    }

    public function test_a_refused_write_writes_nothing(): void
    {
        GoogleBusinessAccount::create([
            'business_id' => $this->shop->id, 'account_name' => 'accounts/1', 'account_email' => 'owner@gmail.com',
            'access_token' => 'access-token-value', 'refresh_token' => 'refresh-token-value', 'token_expires_at' => now()->addHour(),
        ]);
        $this->onPlan([]);

        $this->as()->post(route('admin.integrations.google.key'), ['google_api_key' => 'AIza-new-key'])->assertForbidden();
        $this->as()->post(route('admin.integrations.google.enabled'), ['enabled' => true])->assertForbidden();
        $this->as()->delete(route('admin.integrations.googleBusiness.disconnect'))->assertForbidden();
        $this->as()->post(route('admin.integrations.connect', 'google'))->assertForbidden();

        $this->assertNull(GoogleReviews::apiKey($this->shop->id), 'مفتاحٌ حُفظ من بابٍ مغلق');
        $this->assertSame(1, GoogleBusinessAccount::where('business_id', $this->shop->id)->count(), 'فُصل الربطُ من بابٍ مغلق');
        $this->assertNotSame('1', MarketingSettings::group($this->shop->id, 'connect')['google_setup_started'] ?? null);
    }

    public function test_a_plan_with_integrations_opens_them(): void
    {
        $this->onPlan(PlanFeatures::keys());

        foreach (['admin.integrations.index', 'admin.integrations.google', 'admin.integrations.googleBusiness'] as $name) {
            $this->as()->get(route($name))->assertOk();
        }
    }

    /* ═══════════ ٣ · الربطُ يتبع أداتَه ═══════════ */

    public function test_connect_follows_the_tool(): void
    {
        $this->onPlan(['whatsapp']);
        $this->as()->post(route('admin.integrations.connect', 'whatsapp'))->assertRedirect(route('admin.integrations.whatsapp'));
        $this->as()->post(route('admin.integrations.connect', 'google'))->assertForbidden();

        $this->onPlan(['integrations']);
        $this->as()->post(route('admin.integrations.connect', 'google'))->assertRedirect(route('admin.integrations.google'));
        $this->as()->post(route('admin.integrations.connect', 'whatsapp'))->assertForbidden();
        // وربطُ واتساب نفسُه بقدرته — لا تفتحه التكاملات
        $this->as()->get(route('admin.integrations.whatsapp'))->assertForbidden();
    }

    /* ═══════════ ٤ · Paymob والردودُ العامّة ═══════════ */

    public function test_paymob_stays_open_on_a_plan_that_opens_nothing(): void
    {
        $this->onPlan([]);

        $this->as()->get(route('admin.integrations.paymob'))->assertOk();
        $this->assertNotSame(403, $this->as()->post(route('admin.integrations.paymob.save'), [])->status());
    }

    public function test_public_callbacks_do_not_answer_to_a_plan(): void
    {
        foreach (['webhooks.paymob', 'webhooks.whatsapp', 'webhooks.whatsapp.verify'] as $name) {
            $route = Router::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertNotContains('plan', $route->gatherMiddleware(), "«{$name}» يمرّ بحارس الباقة");
            $this->assertNull(CheckPlanFeature::featureFor($name));
        }
    }
}

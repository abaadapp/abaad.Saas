<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\MerchantAccount;
use App\Support\PlanFeatures;
use App\Support\Signup;
use App\Support\TrialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * تجربةُ الأساسيّة هي الأساسيّة — بلا نقطة البيع ولا إضافة، مطفأً كان الإطلاقُ أو مفتوحًا.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. التسجيلُ الذاتيّ على الأساسيّة أربعةَ عشر يومًا، ومختومٌ «بلا نقطة البيع».
 *   2. والإطلاقُ مطفأ (السياسة السابقة): لا نقطةَ بيعٍ مجّانيّة — كان يفتحها لكلّ باقة.
 *   3. والإطلاقُ مفتوح (`addons_v1`): الأساسيّةُ بلا إضافة، كما كانت.
 *   4. كلُّ بابٍ اسمُه `pos.*` يُردّ بطلبٍ مباشر — لا فتحُ الصندوق وحده.
 *   5. الموقعُ والتسويقُ والولاءُ وواتساب مغلقةٌ في الخادم.
 *   6. اللوحةُ تقول الباقةَ و«تجربة مجانية» ويومَ انتهائها.
 *   7. التجربةُ لا تطلب إضافةً ولا تجدّدها — للعرض والاستفسار.
 *   8. تجربةٌ قائمةٌ قبل الختم لا يُغلق عليها شيء، والسدادُ يُنهي الإغلاق، ومنحةُ أبعاد تفتح.
 *   9. اسمُ دخولٍ على نطاق أبعاد لا يجعل التاجرَ مديرَ منصّة.
 */
class ATrialOfBasicIsBasicWithoutThePosTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 10:00:00');
        app()->setLocale('ar');
        $this->seedPlans();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** يسجّل زائرٌ بنفسه — والطلبُ نفسُه الذي يرسله نموذجُ التسجيل */
    private function signUp(string $email = 'visitor@example.com'): Business
    {
        $this->post(route('register.store'), [
            'name' => 'زائر يجرّب', 'phone' => '+96891234567', 'type' => 'محل ورود', 'shop' => 'Trial Flowers',
            'team_size' => '2–5 موظفين', 'address' => 'مسقط', 'email' => $email, 'password' => 'abaad2026',
        ])->assertRedirect(route('admin.setup.index'));

        return Business::where('registration_source', Business::SOURCE_SELF_SIGNUP)->latest('id')->firstOrFail();
    }

    /** يطرق كلَّ بابٍ اسمُه `pos.*` كما هو في المسارات — بمعاملاتٍ أيًّا كانت */
    private function knockOnEveryPosDoor(): array
    {
        $open = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'pos.')) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $status = $this->json($method, '/'.ltrim($uri, '/'))->getStatusCode();
            if ($status !== 403) {
                $open[$name] = $status;
            }
        }

        return $open;
    }

    /* ═══════════════ ١–٢ · الإطلاقُ مطفأ ═══════════════ */

    public function test_a_self_signup_with_the_launch_shut_gets_basic_for_fourteen_days_and_no_pos(): void
    {
        $this->assertFalse(PricingPolicy::enabled());
        $business = $this->signUp();

        $this->assertSame(PricingPolicy::LEGACY, $business->pricing_policy, 'السياسةُ كما يختمها الإطلاقُ المطفأ');
        $this->assertSame($this->plans['basic']->id, (int) $business->plan_id);
        $this->assertSame(now()->addDays(Signup::TRIAL_DAYS)->toDateString(), $business->ends_at->toDateString());
        $this->assertSame('1', Setting::where('business_id', $business->id)->where('key', TrialOffer::WITHOUT_POS)->value('value'));

        // كانت السابقةُ تفتح نقطةَ البيع لكلّ باقة — والتجربةُ الآن لا
        $this->assertTrue(TrialOffer::closesPos($business));
        $this->assertFalse(PlanFeatures::allows($business, Catalog::POS));
        foreach (['website', 'marketing_tools', 'loyalty', 'whatsapp'] as $key) {
            $this->assertFalse(PlanFeatures::allows($business, $key), $key);
        }

        $this->assertSame([], $this->knockOnEveryPosDoor(), 'بابٌ من نقطة البيع بقي مفتوحًا لتجربة الأساسيّة');
    }

    /* ═══════════════ ٣ · الإطلاقُ مفتوح ═══════════════ */

    public function test_a_self_signup_with_the_launch_open_is_on_addons_and_still_has_only_basic(): void
    {
        $this->launch(9.900, 99.000);
        $business = $this->signUp();

        $this->assertSame(PricingPolicy::ADDONS, $business->pricing_policy);
        $this->assertFalse(PlanFeatures::allows($business, Catalog::POS));
        foreach (Catalog::FEATURE_KEYS as $key) {
            $this->assertFalse(PlanFeatures::allows($business, $key), $key);
        }

        $this->assertSame([], $this->knockOnEveryPosDoor());
    }

    /* ═══════════════ ٤–٥ · الخادمُ يردّ لا الزرّ ═══════════════ */

    public function test_the_trial_cannot_reach_the_till_or_any_addon_by_a_direct_request(): void
    {
        $this->signUp();

        $this->getJson(route('pos.index'))->assertForbidden();
        $this->postJson(route('pos.checkout'), [])->assertForbidden();
        $this->postJson(route('pos.setup.activate'), [])->assertForbidden();
        // وما كان مفتوحًا لإغلاق عملٍ قائم — ولا عملَ للتجربة هناك
        $this->getJson(route('pos.receipts'))->assertForbidden();
        $this->postJson(route('pos.customers.store'), ['name' => 'زبون'])->assertForbidden();
        $this->postJson(route('pos.printJobs.claim'))->assertForbidden();

        foreach (['admin.website.index', 'admin.marketing.coupons', 'admin.marketing.reviews', 'admin.marketing.loyalty', 'admin.marketing.whatsapp', 'admin.integrations.whatsapp'] as $door) {
            $this->get(route($door))->assertForbidden();
        }
    }

    /* ═══════════════ ٦ · اللوحةُ تقول ما هو ═══════════════ */

    public function test_the_dashboard_names_the_plan_the_trial_and_its_last_day(): void
    {
        $business = $this->signUp();

        $this->get(route('admin.dashboard'))->assertInertia(fn ($page) => $page
            ->where('auth.planFeatures.pos', false)
            ->where('context.subscription.trial', true)
            ->where('context.subscription.plan', $this->plans['basic']->name)
            ->where('context.subscription.endsAt', $business->ends_at->format('Y-m-d'))
            ->where('context.subscription.daysLeft', 14)
            ->where('context.subscription.trialWithoutPos', true));
    }

    /* ═══════════════ ٧ · للعرض والاستفسار ═══════════════ */

    public function test_the_trial_neither_requests_nor_renews_an_addon(): void
    {
        $this->launch(9.900, 99.000);
        $business = $this->signUp();

        $this->get(route('admin.subscription.addons'))->assertInertia(fn ($page) => $page
            ->where('trial', true)->where('may_request', false));

        $this->post(route('admin.subscription.addons.request'), ['code' => 'pos', 'cycle' => 'monthly'])->assertForbidden();
        $this->assertSame(0, AddonOrder::where('business_id', $business->id)->count(), 'لا طلبَ تفعيلٍ ولا دورةَ فوترة من التجربة');
        $this->assertSame(0, Invoice::where('business_id', $business->id)->count());

        // ومنحةٌ من أبعاد في التجربة لا يجدّدها التاجرُ ولا يوقف تجديدَها
        $grant = AddonOrders::grant($business, $this->addon('website'), 1, now()->addDays(10)->toDateString(), 'تجربة الموقع للعميل', $this->platform);
        $this->put(route('admin.subscription.addons.renews', $grant->id), ['renews' => true])->assertForbidden();
    }

    /* ═══════════════ ٨ · لا يُمسّ ما وُعد به قبل الختم ═══════════════ */

    public function test_a_trial_that_began_before_the_stamp_keeps_what_it_was_promised(): void
    {
        [$business] = $this->shop('basic', 'early@shop.om');
        $business->forceFill(['registration_source' => Business::SOURCE_SELF_SIGNUP])->save();

        $this->assertSame(PricingPolicy::LEGACY, $business->fresh()->pricing_policy);
        $this->assertFalse(TrialOffer::closesPos($business->fresh()));
        $this->assertTrue(PlanFeatures::allows($business->fresh(), Catalog::POS), 'تجربةٌ قائمة لا يُغلق عليها شيءٌ في منتصفها');
    }

    public function test_paying_ends_the_trial_and_a_grant_from_abaad_opens_the_till_inside_it(): void
    {
        $business = $this->signUp();
        $this->assertFalse(PlanFeatures::allows($business, Catalog::POS));

        // منحةٌ ساريةٌ من فريق أبعاد تفتحها في التجربة
        $grant = AddonOrders::grant($business, $this->addon('pos'), 1, now()->addDays(7)->toDateString(), 'تجربة الصندوق بموافقة أبعاد', $this->platform);
        Entitlements::flush();
        $this->assertTrue(PlanFeatures::allows($business->fresh(), Catalog::POS));
        AddonOrders::end($grant, 'انتهت التجربة');
        Entitlements::flush();
        $this->assertFalse(PlanFeatures::allows($business->fresh(), Catalog::POS));

        // والسدادُ يُنهي التجربة: تقرّر السياسةُ والباقةُ كما لغيره
        Invoice::create([
            'number' => 'INV-T-0001', 'business_id' => $business->id, 'amount' => 9.9,
            'issued_at' => now(), 'status' => Billing::PAID, 'kind' => Billing::KIND_SUBSCRIPTION,
        ]);
        $this->assertFalse(TrialOffer::closesPos($business->fresh()));
        $this->assertTrue(PlanFeatures::allows($business->fresh(), Catalog::POS), 'السياسةُ السابقة بعد السداد كما كانت');
    }

    /* ═══════════════ ٩ · نطاقُ البريد ليس صلاحيّة ═══════════════ */

    public function test_a_merchant_admin_on_the_abaad_domain_is_not_the_platform(): void
    {
        [$business, $owner] = $this->shop('basic', 'owner'.MerchantAccount::DOMAIN);
        $this->assertSame('admin', $owner->role);
        $this->assertStringEndsWith(MerchantAccount::DOMAIN, $owner->email);
        $this->actingAs($owner);

        $order = AddonOrder::create([
            'business_id' => $business->id, 'subscription_addon_id' => $this->addon('pos')->id, 'kind' => AddonOrders::KIND_NEW,
            'cycle' => 'monthly', 'pricing' => Catalog::PAID, 'quantity' => 1, 'unit_price' => 9.9, 'amount' => 9.9,
            'status' => AddonOrders::REQUESTED, 'open_key' => 'new:'.$business->id.':pos',
        ]);

        $this->get(route('super-admin.subscriptions.addons'))->assertForbidden();
        $this->post(route('super-admin.addonOrders.approve', $order->id))->assertForbidden();
        $this->post(route('super-admin.businesses.addons.grant', $business->id), ['code' => 'pos', 'quantity' => 1, 'ends_at' => now()->addMonth()->toDateString(), 'reason' => 'منحة لنفسي'])->assertForbidden();
        $this->post(route('super-admin.businesses.renew', $business->id), ['cycle' => 'yearly'])->assertForbidden();
        $this->post(route('super-admin.businesses.upgrade', $business->id), ['plan_id' => $this->plans['enterprise']->id, 'cycle' => 'monthly'])->assertForbidden();
        $this->post(route('super-admin.addons.launch'), ['enabled' => false])->assertForbidden();
        $this->put(route('super-admin.plans.policyPrice', $this->plans['basic']->id), ['monthly' => 0.1, 'yearly' => 1])->assertForbidden();

        $this->assertSame(AddonOrders::REQUESTED, $order->fresh()->status);
        $this->assertSame(0, BusinessAddon::where('business_id', $business->id)->count());
        $this->assertSame($this->plans['basic']->id, (int) $business->fresh()->plan_id);

        // ولا يصنع لنفسه مديرَ منصّة من وظيفةٍ في متجره
        $this->post(route('admin.jobTitles.store'), ['name' => 'مدير المنصة', 'role' => 'super_admin'])->assertSessionHasErrors('role');
        $this->assertFalse(JobTitle::where('role', 'super_admin')->exists());
        $this->assertSame(1, User::where('role', 'super_admin')->count(), 'مديرُ المنصّة الوحيد هو من زُرع');

        // ومديرُ المنصّة الموثَّق يعتمد الطلب نفسَه
        $this->actingAs($this->platform);
        $this->get(route('super-admin.subscriptions.addons'))->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Models\JobTitle;
use App\Models\SubscriptionAddon;
use App\Models\User;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\MerchantAccount;
use App\Support\PlanFeatures;
use App\Support\SelfSignupPolicyNotReady;
use App\Support\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * تجربةُ الأساسيّة هي الأساسيّة المعلنة — بلا نقطة البيع ولا إضافة، ولا تُفتح على غيرها.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. التسجيلُ الذاتيّ على الأساسيّة أربعةَ عشر يومًا، مختومًا على `addons_v1`.
 *   2. والإطلاقُ مطفأٌ أو شرطُه ناقص: يُردّ التسجيلُ برسالةٍ صريحة ولا يُكتب شيء —
 *      كان يُختم على السابقة فتصير نقطةُ البيع مشمولةً مجّانًا بأوّل سداد.
 *   3. وإطلاقٌ أُطفئ بين السؤال والكتابة لا يترك متجرًا على السابقة.
 *   4. كلُّ بابٍ اسمُه `pos.*` يُردّ بطلبٍ مباشر، وكلُّ مسارٍ تحت `/pos` اسمُه منها ومحروس.
 *   5. والحارسُ يُغلق إن لم يكن خلف الحساب متجر.
 *   6. الموقعُ والتسويقُ والولاءُ وواتساب مغلقةٌ في الخادم.
 *   7. اللوحةُ تقول الباقةَ و«تجربة مجانية» ويومَ انتهائها.
 *   8. التجربةُ لا تطلب إضافةً ولا تجدّدها — للعرض والاستفسار.
 *   9. السدادُ لا يفتح نقطةَ البيع في الأساسيّة؛ منحةُ أبعاد تفتحها؛ وتسجيلٌ قديمٌ على السابقة بحقوقه.
 *   10. اسمُ دخولٍ على نطاق أبعاد لا يجعل التاجرَ مديرَ منصّة.
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

    /* ═══════════════ ٢–٣ · لا تسجيلَ على السابقة ═══════════════ */

    public function test_a_self_signup_is_refused_plainly_while_the_launch_is_shut_and_nothing_is_written(): void
    {
        $this->assertFalse(PricingPolicy::enabled());
        $this->assertFalse(Signup::ready());

        // والصفحةُ تقوله قبل أن يُملأ شيء
        $this->get(route('register'))->assertInertia(fn ($page) => $page->where('closed', true));

        $this->post(route('register.store'), [
            'name' => 'زائر يجرّب', 'phone' => '+96891234567', 'type' => 'محل ورود', 'shop' => 'Trial Flowers',
            'email' => 'visitor@example.com', 'password' => 'abaad2026',
        ])->assertSessionHasErrors(['signup' => 'التسجيل غير متاح الآن. تواصل مع فريق أبعاد وسنفتح حسابك.']);

        $this->assertSame(0, Business::count(), 'متجرٌ على السياسة السابقة يناقض الباقات المعلنة');
        $this->assertSame(0, User::where('email', 'visitor@example.com')->count());
        $this->assertGuest();
    }

    public function test_an_open_launch_with_a_missing_condition_also_refuses_rather_than_falling_back(): void
    {
        $this->launch(9.900, 99.000);
        $this->assertTrue(Signup::ready());

        // شرطٌ نقص بعد الفتح — نقطةُ البيع لم تعد تُعرض للبيع
        SubscriptionAddon::where('code', Catalog::POS)->update(['for_sale' => false]);
        $this->assertTrue(PricingPolicy::enabled());
        $this->assertFalse(Signup::ready());

        $this->expectException(SelfSignupPolicyNotReady::class);
        try {
            Signup::register(['name' => 'زائر', 'phone' => '+96891234567', 'type' => 'محل ورود', 'shop' => 'Half Open', 'email' => 'half@example.com', 'password' => 'abaad2026']);
        } finally {
            $this->assertSame(0, Business::count());
        }
    }

    public function test_a_launch_switched_off_between_the_question_and_the_write_leaves_no_shop(): void
    {
        $this->launch(9.900, 99.000);
        // كأنّ المفتاحَ أُطفئ بعد السؤال: يُختم المتجرُ على السابقة لحظةَ إنشائه
        Business::creating(fn (Business $b) => $b->pricing_policy = PricingPolicy::LEGACY);

        try {
            Signup::register(['name' => 'زائر', 'phone' => '+96891234567', 'type' => 'محل ورود', 'shop' => 'Race', 'email' => 'race@example.com', 'password' => 'abaad2026']);
            $this->fail('خُتم متجرٌ على السابقة بالتسجيل الذاتيّ');
        } catch (SelfSignupPolicyNotReady) {
            // المعاملةُ كلُّها أُلغيت: لا متجر ولا مالك ولا تصنيفات
            $this->assertSame(0, Business::count());
            $this->assertSame(0, User::where('email', 'race@example.com')->count());
        }
    }

    /* ═══════════════ ٣ · الإطلاقُ مفتوح ═══════════════ */

    public function test_a_self_signup_with_the_launch_open_is_on_addons_for_fourteen_days_and_has_only_basic(): void
    {
        $this->launch(9.900, 99.000);
        $this->get(route('register'))->assertInertia(fn ($page) => $page->where('closed', false));
        $business = $this->signUp();

        $this->assertSame(PricingPolicy::ADDONS, $business->pricing_policy);
        $this->assertSame($this->plans['basic']->id, (int) $business->plan_id);
        $this->assertSame(now()->addDays(Signup::TRIAL_DAYS)->toDateString(), $business->ends_at->toDateString());
        $this->assertFalse(PlanFeatures::allows($business, Catalog::POS));
        foreach (Catalog::FEATURE_KEYS as $key) {
            $this->assertFalse(PlanFeatures::allows($business, $key), $key);
        }

        $this->assertSame([], $this->knockOnEveryPosDoor());
    }

    /* ═══════════════ ٤–٥ · الخادمُ يردّ لا الزرّ ═══════════════ */

    public function test_the_trial_cannot_reach_the_till_or_any_addon_by_a_direct_request(): void
    {
        $this->launch(9.900, 99.000);
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
        $this->launch(9.900, 99.000);
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

    /* ═══════════════ ٩ · السدادُ لا يفتح، والمنحةُ تفتح، والقديمُ بحقوقه ═══════════════ */

    public function test_paying_does_not_put_the_till_in_basic_and_a_grant_from_abaad_opens_it(): void
    {
        $this->launch(9.900, 99.000);
        $business = $this->signUp();

        // منحةٌ ساريةٌ من فريق أبعاد تفتحها في التجربة، وانقضاؤها يغلقها
        $grant = AddonOrders::grant($business, $this->addon('pos'), 1, now()->addDays(7)->toDateString(), 'تجربة الصندوق بموافقة أبعاد', $this->platform);
        Entitlements::flush();
        $this->assertTrue(PlanFeatures::allows($business->fresh(), Catalog::POS));
        AddonOrders::end($grant, 'انتهت التجربة');
        Entitlements::flush();
        $this->assertFalse(PlanFeatures::allows($business->fresh(), Catalog::POS));

        // وسدادُ الأساسيّة لا يجعل نقطةَ البيع مشمولةً فيها — كانت السابقةُ تفعل
        Invoice::create([
            'number' => 'INV-T-0001', 'business_id' => $business->id, 'amount' => 9.9,
            'issued_at' => now(), 'status' => Billing::PAID, 'kind' => Billing::KIND_SUBSCRIPTION,
        ]);
        $this->assertTrue(Billing::hasPaidSubscription($business->fresh()));
        $this->assertFalse(PlanFeatures::allows($business->fresh(), Catalog::POS), 'السدادُ فتح نقطةَ البيع في الأساسيّة');
    }

    public function test_an_old_legacy_self_signup_keeps_what_it_was_promised(): void
    {
        // كالمتجر #6 على الإنتاج: سجّل قبل الإطلاق — لا يُمسّ
        [$business] = $this->shop('basic', 'early@shop.om');
        $business->forceFill(['registration_source' => Business::SOURCE_SELF_SIGNUP])->save();
        $this->launch(9.900, 99.000);

        $this->assertSame(PricingPolicy::LEGACY, $business->fresh()->pricing_policy);
        $this->assertTrue(PlanFeatures::allows($business->fresh(), Catalog::POS), 'تسجيلٌ قديمٌ على السابقة فقد ما وُعد به');
    }

    /* ═══════════════ ٤–٥ · كلُّ مسارات نقطة البيع، والحارسُ يُغلق بلا متجر ═══════════════ */

    public function test_every_route_under_pos_is_named_pos_and_passes_the_plan_guard(): void
    {
        $loose = [];
        foreach (Route::getRoutes() as $route) {
            if (! preg_match('#^pos(/|$)#', $route->uri())) {
                continue;
            }
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'pos.') || ! in_array('plan', $route->gatherMiddleware(), true)) {
                $loose[] = $route->uri().' ('.($name ?: 'بلا اسم').')';
            }
        }

        $this->assertSame([], $loose, 'مسارٌ تحت /pos لا يمرّ بحارس الاستحقاق');
    }

    public function test_the_pos_guard_closes_when_no_shop_stands_behind_the_account(): void
    {
        $this->assertFalse(PlanFeatures::allowsPosRecords(null));

        // حسابٌ يحمل رقمَ متجرٍ لا يُقرأ — لا «بلا باقةٍ مفتوح» على صندوق
        [, $owner] = $this->shop('basic', 'ghost@shop.om');
        $owner->setRelation('business', null);
        $this->actingAs($owner);

        $this->getJson(route('pos.receipts'))->assertForbidden();
        $this->getJson(route('pos.index'))->assertForbidden();
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

<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\CustomArrangement;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;
use App\Support\Signup;
use App\Support\Store\GiftOrders;
use App\Support\WhatsAppFeature;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * إطلاقُ إضافات الاشتراك لا يمسّ متجرًا قائمًا — ولا تسجيلًا والإطلاقُ مطفأ.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. المتجرُ القائم يحتفظ بقدراته وأسعاره قبل الإطلاق وبعده.
 *   2. ويحتفظ بنقطة البيع بعد تجديد اشتراكه.
 *   15. التسجيلُ الذاتيّ والتجربةُ كما كانا والإطلاقُ مطفأ؛ ومفتوحًا يُختم الجديدُ بلا نقطة بيع.
 *   16. إطفاءُ الإطلاق لا يمسّ متجرًا خُتمت سياستُه.
 *   18. الترحيلُ على قاعدةٍ قائمة: كلُّ متجرٍ على السابقة، ولا باقةَ ولا اشتراكَ ولا فاتورةَ تتغيّر.
 *   والإطلاقُ لا يُفتح قبل سعر الأساسيّة؛ وباقةٌ قدراتُها `null` كما كانت.
 */
class AnExistingShopKeepsItsSubscriptionWhenAddonsLaunchTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->seedPlans();
    }

    /** ما يراه متجرٌ من قدراته وحدوده وسعره — يُقارن قبل الإطلاق وبعده */
    private function snapshot(Business $business): array
    {
        $business = $business->fresh();

        return [
            'features' => PlanFeatures::map($business),
            'branches' => PlanLimits::cap($business, 'branches'),
            'price' => [Billing::price($business, 'monthly'), Billing::price($business, 'yearly')],
            'gift' => GiftOrders::on($business->id),
            'custom' => CustomArrangement::enabled($business->id),
            'own' => WhatsAppFeature::canUseOwnNumber($business),
        ];
    }

    public function test_an_existing_shop_on_every_plan_keeps_its_features_limits_and_prices_through_the_launch(): void
    {
        $shops = [];
        foreach (['basic', 'professional', 'enterprise'] as $i => $plan) {
            [$shops[$plan]] = $this->shop($plan, "old{$i}@shop.om");
        }
        $before = array_map(fn ($b) => $this->snapshot($b), $shops);

        $this->launch();

        foreach ($shops as $plan => $business) {
            $this->assertSame(PricingPolicy::LEGACY, $business->fresh()->pricing_policy, $plan);
            $this->assertSame($before[$plan], $this->snapshot($business), "تغيّر اشتراكُ متجرٍ قائمٍ على {$plan} بالإطلاق");
        }

        // نقطةُ البيع مفتوحةٌ لكلّ متجرٍ قائم — حتى الأساسيّة التي لا تشملها
        $this->assertTrue($this->allows($shops['basic'], Catalog::POS));
        // وسعرُ الأساسيّة القديم كما هو — لا يصير سعرَها الجديد
        $this->assertSame([9.9, 99.0], $this->snapshot($shops['basic'])['price']);
    }

    public function test_an_existing_basic_shop_keeps_its_pos_after_a_renewal_and_is_never_asked_to_buy_it(): void
    {
        [$business, $owner] = $this->shop('basic');
        $this->launch();

        $renewal = Billing::renew($business, 'yearly');
        $this->assertSame(99.0, (float) $renewal['invoice']->amount, 'سعر التجديد تغيّر بالإطلاق');
        Billing::markPaid($renewal['invoice']);

        $this->assertSame(PricingPolicy::LEGACY, $business->fresh()->pricing_policy, 'التجديد نقل المتجر إلى السياسة الجديدة');
        $this->assertTrue($this->allows($business, Catalog::POS));

        $this->actingAs($owner)->getJson(route('pos.hold'))->assertStatus(405);
        $response = $this->actingAs($owner)->postJson(route('pos.hold'), []);
        $this->assertNotSame(403, $response->status(), 'أُقفلت نقطةُ البيع على متجرٍ قائم');

        // ولا تُعرض عليه الإضافاتُ للشراء — ولا يُقبل طلبُها
        $this->actingAs($owner)->get(route('admin.subscription.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('policy', PricingPolicy::LEGACY)->where('available', [])->where('may_request', false));
        $this->expectException(DomainException::class);
        AddonOrders::request($business, $this->addon('website'), 'monthly');
    }

    public function test_a_plan_with_unset_capabilities_still_opens_everything_for_its_old_shops(): void
    {
        $custom = Plan::create(['name' => 'باقة قديمة', 'monthly_price' => 5, 'yearly_price' => 50, 'capabilities' => null]);
        [$business] = $this->shop('basic', 'nullcap@shop.om');
        $business->update(['plan_id' => $custom->id]);
        $this->launch();

        foreach (array_keys(PlanFeatures::CAPABILITIES) as $key) {
            if (! in_array($key, PlanFeatures::ENTERPRISE, true)) {
                $this->assertTrue($this->allows($business, $key), $key);
            }
        }
        $this->assertTrue($this->allows($business, Catalog::POS));
    }

    public function test_the_launch_cannot_open_before_the_new_basic_price_is_approved(): void
    {
        $this->assertNotSame([], PricingPolicy::blockers());

        try {
            PricingPolicy::setEnabled(true);
            $this->fail('فُتح الإطلاق بلا سعر الأساسيّة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('سعر الباقة الأساسيّة', $e->getMessage());
        }
        $this->assertFalse(PricingPolicy::enabled());

        // ومتجرٌ يُنشأ ومفتاحُه مكتوبٌ بيدٍ قبل السعر يبقى على السابقة
        DB::table('settings')->insert(['business_id' => null, 'key' => PricingPolicy::SWITCH, 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);
        [$business] = $this->shop('basic', 'early@shop.om');
        $this->assertSame(PricingPolicy::LEGACY, $business->pricing_policy);

        // ولا يُفوتَر متجرٌ على السياسة الجديدة بلا سعرٍ معتمد — ولا يُفترض أنّه سعرُ نقطة البيع
        $business->forceFill(['pricing_policy' => PricingPolicy::ADDONS])->save();
        $this->expectException(DomainException::class);
        Billing::price($business->fresh(), 'monthly');
    }

    public function test_self_signup_and_its_trial_are_untouched_while_the_launch_is_off_and_a_new_shop_gets_no_paid_feature_after_it(): void
    {
        $data = fn (string $email) => [
            'name' => 'سالم', 'phone' => '9'.random_int(1000000, 9999999), 'type' => 'محل ورود', 'shop' => 'ورد '.$email,
            'email' => $email, 'password' => 'Secret-Pass-123',
        ];

        $before = Signup::register($data('before@signup.om'))->business;
        $this->assertSame(PricingPolicy::LEGACY, $before->pricing_policy);
        $this->assertSame($this->plans['basic']->id, (int) $before->plan_id);
        $this->assertSame(now()->addDays(Signup::TRIAL_DAYS)->toDateString(), $before->ends_at->toDateString());
        $this->assertTrue($this->allows($before, Catalog::POS));

        $this->launch();
        $after = Signup::register($data('after@signup.om'))->business;

        $this->assertSame(PricingPolicy::ADDONS, $after->pricing_policy);
        $this->assertSame($this->plans['basic']->id, (int) $after->plan_id, 'الباقةُ نفسُها');
        $this->assertSame(now()->addDays(Signup::TRIAL_DAYS)->toDateString(), $after->ends_at->toDateString(), 'التجربةُ نفسُها');
        foreach ($this->soldKeys() as $key) {
            $this->assertFalse($this->allows($after, $key), "التجربةُ فتحت «{$key}» بلا شراء");
        }
        $this->assertFalse(GiftOrders::on($after->id));
        $this->assertFalse(CustomArrangement::enabled($after->id));
        $this->assertFalse(WhatsAppFeature::canUseOwnNumber($after));
        $this->assertSame([4.9, 49.0], [Billing::price($after, 'monthly'), Billing::price($after, 'yearly')]);

        // والقديمُ بجواره كما كان
        $this->assertTrue($this->allows($before, Catalog::POS));
        $this->assertTrue(CustomArrangement::enabled($before->id));
    }

    public function test_turning_the_launch_off_sends_new_shops_back_and_leaves_stamped_shops_where_they_are(): void
    {
        $this->launch();
        [$stamped] = $this->shop('basic', 'stamped@shop.om');
        $order = AddonOrders::request($stamped, $this->addon('pos'), 'monthly')['order'];
        $this->pay(AddonOrders::approve($order));
        $this->assertTrue($this->allows($stamped, Catalog::POS));

        PricingPolicy::setEnabled(false);
        [$later] = $this->shop('basic', 'later@shop.om');

        $this->assertSame(PricingPolicy::LEGACY, $later->pricing_policy);
        $this->assertSame(PricingPolicy::ADDONS, $stamped->fresh()->pricing_policy, 'الإطفاء أعاد تصنيف متجرٍ مختوم');
        $this->assertTrue($this->allows($stamped, Catalog::POS), 'الإطفاء أسقط ما دفعه');
        $this->assertFalse($this->allows($stamped, 'website'));
        $this->assertSame(4.9, Billing::price($stamped->fresh(), 'monthly'), 'الإطفاء غيّر سعر متجرٍ مختوم');
    }

    public function test_the_migration_on_a_live_database_stamps_every_shop_legacy_and_changes_no_plan_subscription_or_invoice(): void
    {
        [$a] = $this->shop('basic', 'live1@shop.om');
        [$b] = $this->shop('professional', 'live2@shop.om');
        Billing::markPaid(Billing::renew($a, 'monthly')['invoice']);
        Billing::renew($b, 'yearly');

        $plans = DB::table('plans')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all();
        $subscriptions = DB::table('subscriptions')->orderBy('id')->get()->map(fn ($s) => (array) $s)->all();
        $invoices = DB::table('invoices')->orderBy('id')->get()->map(fn ($i) => (array) $i)->all();

        // كما لو نزل الترحيلُ على قاعدةٍ فيها هذا كلُّه قبله
        $migration = require database_path('migrations/2026_10_30_090000_future_merchants_grow_their_subscription_with_addons.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('businesses', 'pricing_policy'));
        $migration->up();

        $this->assertSame([PricingPolicy::LEGACY], DB::table('businesses')->distinct()->pluck('pricing_policy')->all());
        $this->assertSame($plans, DB::table('plans')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all(), 'الترحيل مسّ باقة');
        $this->assertSame($subscriptions, DB::table('subscriptions')->orderBy('id')->get()->map(fn ($s) => (array) $s)->all());
        $this->assertSame($invoices, DB::table('invoices')->orderBy('id')->get()->map(fn ($i) => (array) $i)->all());
        $this->assertSame(0, DB::table('business_addons')->count());
        $this->assertFalse(PricingPolicy::enabled(), 'الترحيل فتح الإطلاق');

        // والكتالوجُ زُرع بأسعار المالك وربطُه بالباقات برموزها
        $this->assertSame(9, DB::table('subscription_addons')->count());
        $pos = DB::table('subscription_addons')->where('code', 'pos')->first(['monthly_price', 'yearly_price']);
        $this->assertSame([9.9, 99.0], [(float) $pos->monthly_price, (float) $pos->yearly_price]);
        $modes = fn (string $plan) => DB::table('subscription_addon_plans as p')->join('subscription_addons as a', 'a.id', '=', 'p.subscription_addon_id')
            ->where('p.plan_id', $this->plans[$plan]->id)->orderBy('a.code')->pluck('p.mode', 'a.code')->all();
        $this->assertSame(['loyalty' => 'eligible', 'marketing' => 'eligible', 'pos' => 'eligible', 'website' => 'eligible', 'whatsapp' => 'eligible'], $modes('basic'));
        $this->assertSame('included', $modes('professional')['pos']);
        $this->assertSame('eligible', $modes('professional')['extra_branch']);
        $this->assertArrayNotHasKey('extra_branch', $modes('enterprise'));

        // وما بُني على الجديد لا يُسقطه الرجوع
        $a->forceFill(['pricing_policy' => PricingPolicy::ADDONS])->save();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_an_addon_invoice_is_kept_apart_from_the_plan_and_paying_it_renews_no_subscription(): void
    {
        $this->launch();
        [$business] = $this->shop('basic', 'apart@shop.om');
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('website'), 'yearly')['order']);

        $invoice = $order->invoice;
        $this->assertSame([Billing::KIND_ADDON, null, 129.0], [$invoice->kind, $invoice->plan_id, (float) $invoice->amount]);
        $endsBefore = $business->fresh()->ends_at?->toDateString();

        Billing::markPaid($invoice);

        $this->assertFalse(Billing::hasPaidSubscription($business->fresh()), 'سدادُ إضافةٍ قُرئ اشتراكًا مدفوعًا');
        $this->assertSame($endsBefore, $business->fresh()->ends_at?->toDateString(), 'سدادُ إضافةٍ مدّ الاشتراك');
        $this->assertSame(0, Subscription::where('business_id', $business->id)->count());
        $this->assertSame(1, Invoice::where('business_id', $business->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\BusinessAddon;
use App\Models\PlanPolicyPrice;
use App\Models\Setting;
use App\Models\SubscriptionAddon;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * «التسعير والإعدادات» — كان «الإطلاق»؛ والمقبضُ نفسُه بشروطه وقفله.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. الشاشةُ تقول الحالةَ وشروطَ الجاهزيّة واحدًا واحدًا — من `PricingPolicy::checks`،
 *      و`blockers` رسائلُ ما لم يكتمل منها بترتيبها.
 *   2. زيارتُها لا تكتب في المفتاح شيئًا.
 *   3. لا يُفعَّل بشرطٍ ناقص — ولا بنقطة بيعٍ مشمولةٍ في الأساسيّة الجديدة.
 *   4. ولا يُفعَّل بلا موافقةٍ صريحة تصل مع الطلب، ولو اكتملت شروطُه.
 *   5. يُفعَّل في اختبارٍ معزولٍ بعد اكتمالها: الجديدُ على الإضافات، والقائمُ كما هو.
 *   6. الإيقافُ لا ينقل متجرًا ولا يُسقط استحقاقًا ولا سعرًا ولا إضافة.
 */
class TheAddonLaunchLivesInPricingAndSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlans();
    }

    private function switchRow(): ?string
    {
        return Setting::whereNull('business_id')->where('key', PricingPolicy::SWITCH)->value('value');
    }

    private function enable(bool $acknowledged = true)
    {
        return $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => true] + ($acknowledged ? ['acknowledged' => true] : []));
    }

    public function test_the_screen_reads_the_state_and_each_readiness_check_and_visiting_writes_nothing(): void
    {
        $before = [$this->switchRow(), Setting::count(), PlanPolicyPrice::count()];

        $this->actingAs($this->platform)->get(route('super-admin.subscriptions.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('launch.enabled', false)
                ->where('launch.checks', fn ($checks) => collect($checks)->pluck('key')->all() === ['basic_price', 'basic_capabilities', 'pos_separate', 'catalog_priced'])
                ->where('launch.checks.0.ok', false)
                ->where('launch.checks.2.ok', true)
                ->where('launch.blockers', ['سعر الباقة الأساسيّة في السياسة الجديدة لم يُعتمد.']));
        $this->actingAs($this->platform)->get(route('super-admin.subscriptions.addons'))->assertOk();

        $this->assertSame($before, [$this->switchRow(), Setting::count(), PlanPolicyPrice::count()], 'زيارةُ الشاشة كتبت شيئًا');
        $this->assertFalse(PricingPolicy::enabled());
    }

    public function test_blockers_are_exactly_the_problems_of_the_failed_checks_in_order(): void
    {
        $this->posIn('basic', Catalog::INCLUDED);
        $this->addon('loyalty')->update(['monthly_price' => null]);

        $checks = PricingPolicy::checks();
        $this->assertSame(array_merge(...array_column($checks, 'problems')), PricingPolicy::blockers());
        $this->assertSame([false, true, false, false], array_column($checks, 'ok'));
        $this->assertCount(3, PricingPolicy::blockers());
    }

    public function test_it_refuses_missing_conditions_and_a_pos_included_in_the_new_basic(): void
    {
        // بلا سعرٍ للأساسيّة — ولو وُوفق
        $this->enable()->assertSessionHasErrors('addon');
        $this->assertFalse(PricingPolicy::enabled());

        PricingPolicy::setPlanPrice($this->plans['basic'], PricingPolicy::ADDONS, 4.9, 49);
        $this->posIn('basic', Catalog::INCLUDED);
        $this->enable()->assertSessionHasErrors('addon');
        $this->assertFalse(PricingPolicy::enabled(), 'فُعّل ونقطةُ البيع مشمولةٌ في الأساسيّة الجديدة');
        $this->assertNull($this->switchRow());
    }

    public function test_it_refuses_without_an_explicit_acknowledgement_even_when_ready(): void
    {
        PricingPolicy::setPlanPrice($this->plans['basic'], PricingPolicy::ADDONS, 4.9, 49);
        $this->assertSame([], PricingPolicy::blockers());

        $this->enable(false)->assertSessionHasErrors('acknowledged');
        $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => true, 'acknowledged' => false])
            ->assertSessionHasErrors('acknowledged');
        $this->assertFalse(PricingPolicy::enabled());
        $this->assertNull($this->switchRow());
    }

    public function test_in_isolation_it_turns_on_for_new_signups_only_and_the_old_shops_stay(): void
    {
        [$old] = $this->shop('basic', 'old@shop.om');
        PricingPolicy::setPlanPrice($this->plans['basic'], PricingPolicy::ADDONS, 4.9, 49);

        $this->enable()->assertSessionHasNoErrors();
        $this->assertTrue(PricingPolicy::enabled());

        [$new] = $this->shop('basic', 'new@shop.om');
        $this->assertSame(PricingPolicy::ADDONS, $new->pricing_policy);
        $this->assertSame(PricingPolicy::LEGACY, $old->fresh()->pricing_policy);
        $this->assertTrue($this->allows($old, Catalog::POS));
        $this->assertFalse($this->allows($new, Catalog::POS), 'الأساسيّةُ الجديدة فتحت نقطةَ البيع بلا شراء');
        $this->assertSame(9.9, PricingPolicy::planPrice($old->fresh(), 'monthly'));
        $this->assertSame(4.9, PricingPolicy::planPrice($new->fresh(), 'monthly'));

        $this->actingAs($this->platform)->get(route('super-admin.subscriptions.addons'))
            ->assertInertia(fn ($page) => $page->where('launch.enabled', true)->where('launch.counts.legacy', 1)->where('launch.counts.addons', 1));
    }

    public function test_the_emergency_stop_moves_no_shop_and_drops_no_entitlement_price_or_addon(): void
    {
        [$old] = $this->shop('professional', 'old@shop.om');
        $this->launch();
        [$v1] = $this->shop('basic', 'v1@shop.om');
        $order = AddonOrders::request($v1, $this->addon('pos'), 'monthly')['order'];
        AddonOrders::approve($order);
        $this->pay($order);
        $this->assertTrue($this->allows($v1, Catalog::POS));

        $catalog = DB::table('subscription_addons')->orderBy('id')->get()->toJson();
        $prices = PlanPolicyPrice::orderBy('id')->get(['plan_id', 'policy', 'monthly_price', 'yearly_price'])->toArray();
        $entitlements = BusinessAddon::orderBy('id')->get()->toArray();

        // والإيقافُ لا يحتاج موافقةً في الطلب: بابُ طوارئ — وتأكيداه في الشاشة
        $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse(PricingPolicy::enabled());

        $this->assertSame(PricingPolicy::ADDONS, $v1->fresh()->pricing_policy, 'الإيقافُ نقل متجرًا إلى السابقة');
        $this->assertSame(PricingPolicy::LEGACY, $old->fresh()->pricing_policy);
        $this->assertTrue($this->allows($v1, Catalog::POS), 'سقط استحقاقٌ مدفوعٌ بالإيقاف');
        $this->assertSame(4.9, PricingPolicy::planPrice($v1->fresh(), 'monthly'));
        $this->assertSame(29.9, PricingPolicy::planPrice($old->fresh(), 'monthly'));
        $this->assertSame($catalog, DB::table('subscription_addons')->orderBy('id')->get()->toJson());
        $this->assertSame($prices, PlanPolicyPrice::orderBy('id')->get(['plan_id', 'policy', 'monthly_price', 'yearly_price'])->toArray());
        $this->assertSame($entitlements, BusinessAddon::orderBy('id')->get()->toArray());
        $this->assertSame(9, SubscriptionAddon::count());

        // والجديدُ بعد الإيقاف على السابقة
        [$after] = $this->shop('basic', 'after@shop.om');
        $this->assertSame(PricingPolicy::LEGACY, $after->pricing_policy);
    }

    public function test_a_new_merchant_still_sees_prices_and_buys_and_has_no_showcase(): void
    {
        $this->launch();
        [$business, $owner] = $this->shop('basic', 'buyer@shop.om');

        $this->actingAs($owner)->get(route('admin.subscription.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('policy', PricingPolicy::ADDONS)
                ->where('showcase', [])
                ->where('may_request', true)
                ->where('plan.price.monthly', 4.9)
                ->where('available', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === 'pos' && (float) $r['monthly_price'] === 9.9 && (float) $r['yearly_price'] === 99.0)));
    }

    private function posIn(string $plan, ?string $mode): void
    {
        $pos = $this->addon('pos');
        $mode === null
            ? $pos->plans()->detach($this->plans[$plan]->id)
            : $pos->plans()->syncWithoutDetaching([$this->plans[$plan]->id => ['mode' => $mode]]);
    }
}

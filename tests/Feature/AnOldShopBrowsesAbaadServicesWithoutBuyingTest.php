<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanPolicyPrice;
use App\Models\Setting;
use App\Models\SubscriptionAddon;
use App\Models\SupportConversation;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\AddonViews;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use App\Support\PlanFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * متجرُ السياسة السابقة يتعرّف على خدمات أبعاد — ولا يشتري شيئًا ولا يتبدّل اشتراكُه.
 *
 * ═══ ما كان ═══
 *
 * `AddonViews::forMerchant` يعطي السابقةَ كتالوجًا فارغًا، فيرى التاجرُ صفحةً
 * فيها سطرٌ واحد. صار يعطيها `showcase`: الخدماتُ المعروضة باسمها ووصفها
 * وحالتها عنده — بلا سعرٍ ولا رمزٍ ولا نوعِ تسعير.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. الكتالوجُ من الخادم، والباقةُ الحقيقيّة، ولا سعرَ ولا رمزَ فيه.
 *   2. الحالةُ من حارس القدرة نفسِه — لا من غياب صفٍّ في `business_addons`.
 *   3. منحةٌ ساريةٌ تُرى «في اشتراكك»، والموقوفُ بيعُها لا يُعرض إلّا لمن يملكها.
 *   4. الزيارةُ لا تكتب شيئًا: لا طلبَ ولا فاتورةَ ولا استحقاقَ ولا مفتاحَ إطلاق.
 *   5. الطلبُ عبر HTTP مباشرةً يُردّ — والإطلاقُ مفتوحٌ أو مطفأ.
 *   6. نقطةُ البيع وسعرُ التجديد كما كانا.
 *   7. لا يرى متجرٌ منحةَ متجرٍ آخر ولا باقتَه.
 *   8. «تواصل مع أبعاد» يفتح نموذجَ الدعم بموضوعه — ولا يُرسل شيئًا.
 *   9. الإضافةُ الجديدة موقوفٌ بيعُها حتى يُقرَّر عرضُها، والقائمةُ كما هي.
 *  10. الموقوفُ بيعُها لا يراها إلّا من له استحقاقٌ ساريٌ لها — لا المنتهي ولا المُنهى.
 */
class AnOldShopBrowsesAbaadServicesWithoutBuyingTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlans();
    }

    private function page($owner)
    {
        return $this->actingAs($owner)->get(route('admin.subscription.addons'))->assertOk();
    }

    public function test_the_old_shop_reads_a_real_catalog_from_the_server_with_its_plan_and_no_price(): void
    {
        [$business, $owner] = $this->shop('basic', 'old@shop.om');
        $this->assertSame(PricingPolicy::LEGACY, $business->pricing_policy);

        $this->page($owner)->assertInertia(fn ($page) => $page->component('Admin/Subscription/Addons')
            ->where('policy', PricingPolicy::LEGACY)
            ->where('plan.name', $this->plans['basic']->name)
            ->where('plan.price', null)
            ->where('subscription.status', 'نشط')
            ->where('subscription.live', true)
            ->where('available', [])
            ->where('may_request', false)
            ->has('showcase', SubscriptionAddon::where('for_sale', true)->count()));

        $showcase = AddonViews::forMerchant($business->fresh())['showcase'];
        $this->assertNotEmpty($showcase);
        foreach ($showcase as $card) {
            // تعريفٌ لا شراء: لا شيءَ في البطاقة يُشترى به
            $this->assertSame(['id', 'capability', 'name', 'description', 'state', 'note'], array_keys($card));
            $this->assertContains($card['state'], [AddonViews::SHOWN_INCLUDED, AddonViews::SHOWN_LEARN, AddonViews::SHOWN_NOT_INCLUDED]);
        }

        // والوصفُ ما يفتحه الباب حين لم يُكتب للإضافة وصف
        $pos = collect($showcase)->firstWhere('capability', Catalog::POS);
        $this->assertSame(Catalog::DESCRIPTIONS[Catalog::POS], $pos['description']);
        $api = collect($showcase)->firstWhere('capability', null);
        $this->assertNull($api['description'], 'نُسب وصفٌ لخدمةٍ بلا باب');
    }

    public function test_each_state_comes_from_the_gate_that_opens_it_not_from_a_missing_entitlement(): void
    {
        [$basic] = $this->shop('basic', 'basic@shop.om');
        [$pro] = $this->shop('professional', 'pro@shop.om');
        $this->assertSame(0, BusinessAddon::count(), 'لا استحقاقَ لأحد — والحالةُ تُقرأ من الحارس');

        foreach ([$basic, $pro] as $business) {
            $cards = collect(AddonViews::showcase($business->fresh()))->keyBy(fn ($c) => $c['capability'] ?? 'none');

            // نقطةُ البيع مفتوحةٌ للسابقة دائمًا — ولم تُشترَ
            $this->assertSame(AddonViews::SHOWN_INCLUDED, $cards[Catalog::POS]['state']);

            foreach (Catalog::FEATURE_KEYS as $key) {
                $this->assertSame(
                    PlanFeatures::allows($business->fresh(), $key) ? AddonViews::SHOWN_INCLUDED : AddonViews::SHOWN_NOT_INCLUDED,
                    $cards[$key]['state'],
                    $key.' لا يطابق حارسه',
                );
            }

            // وما لا مصدرَ يقوله: «تعرّف على الخدمة» — لا «غير مشترك»
            $this->assertSame(AddonViews::SHOWN_LEARN, $cards['none']['state']);
            $this->assertSame(AddonViews::SHOWN_LEARN, $cards[Catalog::BRANCHES]['state']);
        }

        // والأساسيّةُ القديمة لا تفتح الموقع، والاحترافيّةُ تفتحه — كما يقول الحارس
        $this->assertSame(AddonViews::SHOWN_NOT_INCLUDED, collect(AddonViews::showcase($basic->fresh()))->firstWhere('capability', 'website')['state']);
        $this->assertSame(AddonViews::SHOWN_INCLUDED, collect(AddonViews::showcase($pro->fresh()))->firstWhere('capability', 'website')['state']);

        // والفروعُ بحدّ الباقة كما يحسبه `PlanLimits`
        $this->assertSame(__('حدّ الفروع في باقتك: :n', ['n' => 1]), collect(AddonViews::showcase($basic->fresh()))->firstWhere('capability', Catalog::BRANCHES)['note']);

        // وباقةٌ قديمةٌ لم تُملأ قدراتُها تفتح كلَّ شيء — فتُرى كلُّها في اشتراكه
        $open = Plan::create(['name' => 'باقة قديمة', 'monthly_price' => 5, 'yearly_price' => 50, 'capabilities' => null]);
        $basic->update(['plan_id' => $open->id]);
        foreach (Catalog::FEATURE_KEYS as $key) {
            $this->assertSame(AddonViews::SHOWN_INCLUDED, collect(AddonViews::showcase($basic->fresh()))->firstWhere('capability', $key)['state']);
        }
    }

    public function test_a_grant_reads_as_included_and_a_paused_service_shows_only_to_the_shop_that_holds_it(): void
    {
        [$mine, $owner] = $this->shop('basic', 'mine@shop.om');
        [$theirs, $theirOwner] = $this->shop('basic', 'theirs@shop.om');

        $api = $this->addon('api_integrations');
        AddonOrders::grant($mine, $api, 1, null, 'منحة اختبار', $this->platform);
        $api->update(['for_sale' => false]);

        $state = fn ($business) => collect(AddonViews::showcase($business->fresh()))->firstWhere('id', $api->id)['state'] ?? 'hidden';
        $this->assertSame(AddonViews::SHOWN_INCLUDED, $state($mine));
        $this->assertSame('hidden', $state($theirs), 'عُرضت خدمةٌ موقوفٌ بيعُها لمن لا يملكها');

        // ولا يرى المتجرُ الآخرُ منحةَ الأوّل ولا شيئًا منه
        $this->page($theirOwner)->assertInertia(fn ($page) => $page
            ->where('plan.name', $this->plans['basic']->name)
            ->where('active', [])
            ->where('orders', []));
        $this->page($owner)->assertInertia(fn ($page) => $page->where('active', []));
    }

    public function test_visiting_the_page_writes_nothing_and_changes_no_switch(): void
    {
        [$business, $owner] = $this->shop('basic', 'visit@shop.om');
        $before = [
            AddonOrder::count(), Invoice::count(), BusinessAddon::count(), PlanPolicyPrice::count(),
            Setting::whereNull('business_id')->where('key', PricingPolicy::SWITCH)->value('value'),
            DB::table('subscription_addons')->orderBy('id')->get()->toJson(),
            $business->fresh()->only(['pricing_policy', 'plan_id', 'ends_at']),
        ];

        $this->page($owner);
        $this->page($owner);

        $this->assertEquals($before, [
            AddonOrder::count(), Invoice::count(), BusinessAddon::count(), PlanPolicyPrice::count(),
            Setting::whereNull('business_id')->where('key', PricingPolicy::SWITCH)->value('value'),
            DB::table('subscription_addons')->orderBy('id')->get()->toJson(),
            $business->fresh()->only(['pricing_policy', 'plan_id', 'ends_at']),
        ]);
    }

    public function test_a_direct_http_request_from_an_old_shop_is_refused_with_the_launch_off_or_on(): void
    {
        [$business, $owner] = $this->shop('basic', 'direct@shop.om');

        foreach ([false, true] as $launched) {
            if ($launched) {
                $this->launch();
                $this->assertSame(PricingPolicy::LEGACY, $business->fresh()->pricing_policy, 'الإطلاقُ نقل متجرًا قائمًا');
            }

            foreach (['website', 'pos', 'api_integrations'] as $code) {
                $this->actingAs($owner)->post(route('admin.subscription.addons.request'), ['code' => $code, 'cycle' => 'monthly'])
                    ->assertSessionHasErrors('code');
            }
        }

        $this->assertSame(0, AddonOrder::count());
        $this->assertSame(0, Invoice::where('business_id', $business->id)->count());
        $this->assertSame(0, BusinessAddon::count());
    }

    public function test_the_old_shop_keeps_pos_its_services_and_its_renewal_price(): void
    {
        [$business] = $this->shop('professional', 'keep@shop.om');
        $before = collect(PlanFeatures::map($business->fresh()))->all();
        $this->launch();

        $this->assertTrue($this->allows($business, Catalog::POS));
        $this->assertSame($before, PlanFeatures::map($business->fresh()), 'تبدّلت خدماتُ متجرٍ قائم');
        $this->assertSame(29.9, PricingPolicy::planPrice($business->fresh(), 'monthly'));
        $this->assertSame(299.0, PricingPolicy::planPrice($business->fresh(), 'yearly'));
    }

    public function test_contact_abaad_opens_the_support_form_with_its_subject_and_sends_nothing(): void
    {
        [, $owner] = $this->shop('basic', 'ask@shop.om');

        $this->actingAs($owner)->get(route('admin.help.index', ['topic' => 'services']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Help/Index')
                ->where('draft.subject', 'الاستفسار عن خدمات أبعاد')
                ->where('draft.category', 'الحساب والاشتراك'));
        $this->actingAs($owner)->get(route('admin.help.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('draft', null));

        $this->assertSame(0, SupportConversation::count());
        $this->assertSame(0, AddonOrder::count());
    }

    public function test_english_shows_the_english_name_and_description(): void
    {
        [$business] = $this->shop('basic', 'en@shop.om');
        $this->addon('loyalty')->update(['description' => 'نقاط', 'description_en' => 'Points']);

        app()->setLocale('en');
        $loyalty = collect(AddonViews::showcase($business->fresh()))->firstWhere('capability', 'loyalty');
        app()->setLocale('ar');

        $this->assertSame(['Loyalty & rewards', 'Points'], [$loyalty['name'], $loyalty['description']]);
    }

    public function test_a_new_addon_starts_off_sale_and_stays_out_of_the_old_shops_catalog_while_existing_ones_keep_their_state(): void
    {
        [$business] = $this->shop('basic', 'catalog@shop.om');
        $this->addon('marketing')->update(['for_sale' => false]);
        $before = SubscriptionAddon::orderBy('id')->get(['id', 'code', 'for_sale', 'for_new_merchants'])->toArray();

        // بلا «تُباع الآن»: موقوفٌ بيعُها — لا يقرّره عمودُ القاعدة
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), [
            'name' => 'تصوير المنتجات', 'pricing' => 'quote', 'unit' => 'store', 'for_new_merchants' => true,
        ])->assertSessionHasNoErrors();
        // وبها صريحةً: تُباع
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), [
            'name' => 'دعم بأولوية', 'pricing' => 'quote', 'unit' => 'store', 'for_sale' => true, 'for_new_merchants' => true,
        ])->assertSessionHasNoErrors();

        $draft = SubscriptionAddon::where('name', 'تصوير المنتجات')->sole();
        $ready = SubscriptionAddon::where('name', 'دعم بأولوية')->sole();
        $this->assertFalse($draft->for_sale, 'إضافةٌ جديدةٌ عُرضت للبيع بلا قرار');
        $this->assertTrue($ready->for_sale);

        // والإضافاتُ القائمة بحالاتها — المعروضةُ والموقوفة
        $this->assertSame($before, SubscriptionAddon::whereKey(array_column($before, 'id'))->orderBy('id')->get(['id', 'code', 'for_sale', 'for_new_merchants'])->toArray());

        $ids = collect(AddonViews::showcase($business->fresh()))->pluck('id');
        $this->assertNotContains($draft->id, $ids, 'ظهرت خدمةٌ غيرُ جاهزةٍ في كتالوج تاجرٍ قديم');
        $this->assertNotContains($this->addon('marketing')->id, $ids);
        $this->assertContains($ready->id, $ids);
    }

    public function test_an_off_sale_service_shows_only_to_a_shop_with_a_live_entitlement(): void
    {
        $api = $this->addon('api_integrations');
        [$live] = $this->shop('basic', 'live@shop.om');
        [$none] = $this->shop('basic', 'none@shop.om');
        [$expired] = $this->shop('basic', 'expired@shop.om');
        [$ended] = $this->shop('basic', 'ended@shop.om');

        AddonOrders::grant($live, $api, 1, null, 'منحة سارية', $this->platform);
        AddonOrders::grant($expired, $api, 1, null, 'منحة انقضت', $this->platform)
            ->forceFill(['starts_at' => now()->subMonths(2)->toDateString(), 'ends_at' => now()->subDay()->toDateString()])->save();
        AddonOrders::end(AddonOrders::grant($ended, $api, 1, null, 'منحة أُنهيت', $this->platform), 'أنهاها صاحب المنصة');
        $api->update(['for_sale' => false]);

        $shown = fn ($business) => collect(AddonViews::showcase($business->fresh()))->firstWhere('id', $api->id);
        $this->assertSame(AddonViews::SHOWN_INCLUDED, $shown($live)['state'] ?? null);
        $this->assertNull($shown($none), 'عُرضت خدمةٌ موقوفةٌ لمن لا يملكها');
        $this->assertNull($shown($expired), 'عُرضت خدمةٌ موقوفةٌ لاستحقاقٍ منتهٍ');
        $this->assertNull($shown($ended), 'عُرضت خدمةٌ موقوفةٌ لاستحقاقٍ مُنهى');

        // وعرضُها من جديد يُظهرها للجميع
        $api->update(['for_sale' => true]);
        $this->assertSame(AddonViews::SHOWN_LEARN, $shown($none)['state']);
    }
}

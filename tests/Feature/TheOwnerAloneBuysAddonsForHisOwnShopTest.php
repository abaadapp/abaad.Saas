<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Models\PlanPolicyPrice;
use App\Models\User;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * الأبوابُ: التاجرُ يطلب لمتجره وحده وبيد صاحبه، وصاحبُ المنصّة يدير الباقي.
 *
 * ═══ ما يُحرس ═══
 *
 *   9. لا يبلغ متجرٌ إضافاتِ غيره ولا طلباتِه ولا فواتيرَه.
 *   10. الموظّفُ — ولو مديرًا — لا يطلب ولا يوقف تجديدًا: لصاحب النشاط وحده.
 *   والتاجرُ لا يفتح شيئًا بتعديل الطلب: السعرُ والحالُ والمتجرُ من الخادم.
 *   ولوحةُ المنصّة: الكتالوجُ والرمزُ الثابتُ والشمولُ المحميّ والإطلاقُ والمنحُ والسداد.
 */
class TheOwnerAloneBuysAddonsForHisOwnShopTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->seedPlans();
        $this->launch();
    }

    public function test_the_owner_requests_for_his_shop_and_a_tampered_request_opens_nothing(): void
    {
        [$business, $owner] = $this->shop('basic');
        [$other] = $this->shop('basic', 'other@shop.om');

        $this->actingAs($owner)->post(route('admin.subscription.addons.request'), [
            'code' => 'pos', 'cycle' => 'yearly', 'quantity' => 9,
            // ما لا يُقرأ من الطلب أبدًا
            'unit_price' => 0, 'amount' => 0, 'status' => 'paid', 'business_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $order = AddonOrder::sole();
        $this->assertSame([$business->id, 'yearly', 1, 99.0, AddonOrders::REQUESTED], [
            $order->business_id, $order->cycle, $order->quantity, (float) $order->unit_price, $order->status,
        ]);
        $this->assertSame(0, BusinessAddon::count(), 'فُتحت إضافةٌ بطلب');
        $this->assertFalse($this->allows($business, Catalog::POS));
        $this->assertSame(0, AddonOrder::where('business_id', $other->id)->count());

        // ورمزُ إضافةٍ لا تُباع في باقته يُردّ بسببه
        $this->actingAs($owner)->post(route('admin.subscription.addons.request'), ['code' => 'extra_branch', 'cycle' => 'monthly'])
            ->assertSessionHasErrors('code');
    }

    public function test_an_employee_even_a_manager_cannot_buy_or_stop_a_renewal(): void
    {
        [$business, $owner] = $this->shop('basic');
        $manager = User::create([
            'business_id' => $business->id, 'name' => 'المدير', 'email' => 'manager@shop.om',
            'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'نشط',
        ]);
        $cashier = User::create([
            'business_id' => $business->id, 'name' => 'الكاشير', 'email' => 'cashier@shop.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $row = AddonOrders::grant($business, $this->addon('loyalty'), 1, null, 'منحة اختبار', $this->platform);

        $this->actingAs($manager)->get(route('admin.subscription.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('mayBuy', false));
        $this->actingAs($manager)->post(route('admin.subscription.addons.request'), ['code' => 'pos', 'cycle' => 'monthly'])->assertForbidden();
        $this->actingAs($manager)->put(route('admin.subscription.addons.renews', $row->id), ['renews' => false])->assertForbidden();
        $this->actingAs($cashier)->get(route('admin.subscription.addons'))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.subscription.addons.request'), ['code' => 'pos', 'cycle' => 'monthly'])->assertForbidden();

        $this->assertSame(0, AddonOrder::count());
        $this->actingAs($owner)->get(route('admin.subscription.addons'))->assertInertia(fn ($page) => $page->where('mayBuy', true));
    }

    public function test_a_shop_never_reaches_another_shops_addons_requests_or_invoices(): void
    {
        [$mine, $owner] = $this->shop('basic', 'mine@shop.om');
        [$theirs] = $this->shop('basic', 'theirs@shop.om');
        $their = AddonOrders::grant($theirs, $this->addon('website'), 1, null, 'منحة الجار', $this->platform);
        $order = AddonOrders::approve(AddonOrders::request($theirs, $this->addon('pos'), 'monthly')['order']);

        $this->actingAs($owner)->get(route('admin.subscription.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('active', [])->where('orders', []));
        $this->actingAs($owner)->put(route('admin.subscription.addons.renews', $their->id), ['renews' => false])->assertNotFound();
        $this->assertNull($their->fresh()->cancelled_at);

        // وأبوابُ المنصّة لا يبلغها تاجر
        foreach ([
            ['post', route('super-admin.addonOrders.approve', $order->id)],
            ['post', route('super-admin.invoices.pay', $order->invoice_id)],
            ['post', route('super-admin.businesses.addons.grant', $mine->id), ['addon_id' => $this->addon('pos')->id, 'reason' => 'منحة لنفسي']],
            ['post', route('super-admin.addons.launch'), ['enabled' => false]],
            ['get', route('super-admin.subscriptions.addons')],
        ] as $call) {
            $status = $this->actingAs($owner)->call(strtoupper($call[0]), $call[1], $call[2] ?? [])->status();
            $this->assertContains($status, [302, 403, 404], $call[1]);
        }

        $this->assertFalse($this->allows($mine, Catalog::POS));
        $this->assertSame(0, BusinessAddon::where('business_id', $mine->id)->count());
        $this->assertSame('غير مدفوعة', Invoice::find($order->invoice_id)->status);
        $this->assertTrue(PricingPolicy::enabled());
    }

    public function test_the_platform_runs_the_catalog_with_a_fixed_code_and_a_protected_inclusion(): void
    {
        $this->actingAs($this->platform)->get(route('super-admin.subscriptions.addons'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/Subscriptions/Addons')
                ->has('catalog', 9)->where('launch.enabled', true)->where('launch.blockers', []));

        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), [
            'code' => 'priority_support', 'name' => 'دعم بأولوية', 'pricing' => 'paid', 'unit' => 'store',
            'monthly_price' => 1.5, 'yearly_price' => 15, 'for_sale' => true, 'for_new_merchants' => true,
        ])->assertSessionHasNoErrors();
        $added = $this->addon('priority_support');
        $this->assertNull($added->capability, 'خدمةٌ بلا باب');

        // والرمزُ لا يتغيّر بالتعديل
        $this->actingAs($this->platform)->put(route('super-admin.addons.update', $added->id), [
            'code' => 'renamed', 'name' => 'دعم بأولوية', 'pricing' => 'quote', 'unit' => 'store', 'for_sale' => false, 'for_new_merchants' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['priority_support', 'quote', null, false], [$added->fresh()->code, $added->fresh()->pricing, $added->fresh()->monthly_price, $added->fresh()->for_sale]);

        // مدفوعةٌ بلا سعر تُردّ، ورمزٌ مكرَّر يُردّ
        $this->actingAs($this->platform)->post(route('super-admin.addons.store'), [
            'code' => 'pos', 'name' => 'x', 'pricing' => 'paid', 'unit' => 'store', 'for_sale' => true, 'for_new_merchants' => true,
        ])->assertSessionHasErrors(['code', 'monthly_price', 'yearly_price']);

        // والشمولُ لا يُسحب من باقةٍ عليها متاجرُ السياسة الجديدة
        [$pro] = $this->shop('professional', 'pro@shop.om');
        $pos = $this->addon('pos');
        $this->actingAs($this->platform)->put(route('super-admin.addons.plans', $pos->id), [
            'plans' => [(string) $this->plans['basic']->id => 'eligible', (string) $this->plans['professional']->id => 'eligible'],
        ])->assertSessionHasErrors('plans');
        $this->assertTrue($this->allows($pro, Catalog::POS), 'سُحبت نقطةُ البيع من متجرٍ مشمولٍ بها');

        // وما تفتحه لا يتبدّل تحت من اشتراها
        [$basic] = $this->shop('basic', 'b@shop.om');
        AddonOrders::grant($basic, $this->addon('loyalty'), 1, null, 'منحة اختبار', $this->platform);
        $loyalty = $this->addon('loyalty');
        $this->actingAs($this->platform)->put(route('super-admin.addons.update', $loyalty->id), [
            'name' => $loyalty->name, 'capability' => 'website', 'pricing' => 'paid', 'unit' => 'store',
            'monthly_price' => 3.9, 'yearly_price' => 39, 'for_sale' => true, 'for_new_merchants' => true,
        ])->assertSessionHasErrors('capability');
    }

    public function test_the_platform_launches_grants_approves_and_records_payment_through_its_screens(): void
    {
        PricingPolicy::setEnabled(false);
        PlanPolicyPrice::query()->delete();

        $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => true]);
        $this->assertFalse(PricingPolicy::enabled(), 'فُتح الإطلاقُ بلا سعر');

        $this->actingAs($this->platform)->put(route('super-admin.plans.policyPrice', $this->plans['basic']->id), ['monthly' => 5.5, 'yearly' => 55])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => true]);
        $this->assertTrue(PricingPolicy::enabled());

        [$business] = $this->shop('basic', 'flow@shop.om');
        $order = AddonOrders::request($business, $this->addon('website'), 'monthly')['order'];

        $this->actingAs($this->platform)->post(route('super-admin.addonOrders.approve', $order->id))->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(AddonOrders::INVOICED, $order->status);
        $this->assertFalse($this->allows($business, 'website'));

        $this->actingAs($this->platform)->post(route('super-admin.invoices.pay', $order->invoice_id));
        $this->assertTrue($this->allows($business, 'website'));

        $this->actingAs($this->platform)->post(route('super-admin.businesses.addons.grant', $business->id), [
            'addon_id' => $this->addon('loyalty')->id, 'ends_at' => now()->addMonths(3)->toDateString(), 'reason' => 'عرض خاص للافتتاح',
        ])->assertSessionHasNoErrors();
        $grant = BusinessAddon::where('source', BusinessAddon::GRANT)->sole();
        $this->assertSame([0.0, 'عرض خاص للافتتاح', $this->platform->id], [(float) $grant->unit_price, $grant->reason, $grant->created_by]);
        $this->assertTrue($this->allows($business, 'loyalty'));

        $this->actingAs($this->platform)->post(route('super-admin.businessAddons.end', $grant->id), ['reason' => 'انتهى العرض'])->assertSessionHasNoErrors();
        $this->assertFalse($this->allows($business, 'loyalty'));
        $this->assertNotNull($grant->fresh()->cancelled_at, 'الإنهاءُ حذف الصفّ');

        $this->actingAs($this->platform)->get(route('super-admin.businesses.show', $business->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('addons.policy', PricingPolicy::ADDONS)->has('addons.entitlements', 2));
    }
}

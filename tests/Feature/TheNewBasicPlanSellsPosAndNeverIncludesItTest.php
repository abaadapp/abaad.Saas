<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\BusinessAddon;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * نقطةُ البيع في الأساسيّة الجديدة تُشترى — ولا تُشمل.
 *
 * كان شرطُ الإطلاق يسأل: أهي مربوطةٌ بالأساسيّة؟ فمرّت «مشمولةً» فيها، وفُتح
 * الإطلاقُ وكلُّ متجرٍ جديدٍ يبيع بلا شراء — والسياسةُ كلُّها جاءت تفصلها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الكتالوجُ كما يُزرع: تُشترى في الأساسيّة، وتُشمل في الاحترافيّة والمؤسسات.
 *   - الإطلاقُ يُردّ ونقطةُ البيع مشمولةٌ في الأساسيّة أو غيرُ معروضةٍ للبيع.
 *   - وما دام مفتوحًا لا يُطوى فيها ولا يُرفع من البيع — يُطفأ أوّلًا.
 *   - المتجرُ القديمُ يبيع كما كان أيًّا كان الكتالوج.
 *   - ولا يكسر تعديلُ الكتالوج استحقاقًا مدفوعًا ولا طلبًا مفتوحًا.
 *   - البيعُ الجديد وحده يحتاجها؛ وما بعد البيع مفتوحٌ بعد انقضائها.
 */
class TheNewBasicPlanSellsPosAndNeverIncludesItTest extends TestCase
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

    private function posIn(string $plan, ?string $mode): void
    {
        $pos = $this->addon('pos');
        $pos->plans()->detach($this->plans[$plan]->id);
        if ($mode !== null) {
            $pos->plans()->attach($this->plans[$plan]->id, ['mode' => $mode]);
        }
    }

    private function modeIn(string $plan): ?string
    {
        return $this->addon('pos')->plans()->whereKey($this->plans[$plan]->id)->value('subscription_addon_plans.mode');
    }

    private function buyPos($business): BusinessAddon
    {
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('pos'), 'monthly')['order']);
        $this->pay($order);

        return BusinessAddon::findOrFail($order->fresh()->business_addon_id);
    }

    public function test_the_seeded_catalog_sells_pos_in_basic_and_includes_it_only_above(): void
    {
        $this->assertSame(Catalog::ELIGIBLE, $this->modeIn('basic'));
        $this->assertSame(Catalog::INCLUDED, $this->modeIn('professional'));
        $this->assertSame(Catalog::INCLUDED, $this->modeIn('enterprise'));

        $this->launch();
        $this->assertSame([], PricingPolicy::blockers());

        [$basic] = $this->shop('basic', 'b@shop.om');
        [$pro] = $this->shop('professional', 'p@shop.om');
        $this->assertFalse($this->allows($basic, Catalog::POS), 'الأساسيّةُ الجديدة باعت بلا شراء');
        $this->assertTrue($this->allows($pro, Catalog::POS));
    }

    public function test_the_launch_is_refused_while_pos_is_folded_into_basic_or_not_on_sale(): void
    {
        PricingPolicy::setPlanPrice($this->plans['basic'], PricingPolicy::ADDONS, 4.9, 49);
        $refusedWith = function (string $why) {
            $this->assertTrue(collect(PricingPolicy::blockers())->contains(fn ($b) => str_contains($b, $why)), $why);
            try {
                PricingPolicy::setEnabled(true);
                $this->fail('فُتح الإطلاق: '.$why);
            } catch (DomainException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
            // ومن الشاشة: يُردّ بسببه، ولا يُفتح
            $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => true, 'acknowledged' => true])
                ->assertSessionHasErrors('addon');
            $this->assertFalse(PricingPolicy::enabled());
        };

        $this->posIn('basic', Catalog::INCLUDED);
        $refusedWith('نقطة البيع مشمولةٌ في الباقة الأساسيّة');

        $this->posIn('basic', null);
        $refusedWith('نقطة البيع ليست إضافةً تُشترى في الباقة الأساسيّة');

        $this->posIn('basic', Catalog::ELIGIBLE);
        $this->addon('pos')->update(['for_sale' => false]);
        $refusedWith('نقطة البيع لا تُعرض للبيع');

        $this->addon('pos')->update(['for_sale' => true, 'for_new_merchants' => false]);
        $refusedWith('نقطة البيع لا تُعرض للبيع');

        // ومتجرٌ يُنشأ في أثناء ذلك على السابقة
        [$meanwhile] = $this->shop('basic', 'meanwhile@shop.om');
        $this->assertSame(PricingPolicy::LEGACY, $meanwhile->pricing_policy);

        $this->addon('pos')->update(['for_new_merchants' => true]);
        PricingPolicy::setEnabled(true);
        $this->assertTrue(PricingPolicy::enabled());
    }

    public function test_while_the_launch_is_on_pos_cannot_be_folded_into_basic_or_pulled_from_sale(): void
    {
        $this->launch();
        $pos = $this->addon('pos');
        $plans = fn (string $basicMode) => ['plans' => [
            (string) $this->plans['basic']->id => $basicMode,
            (string) $this->plans['professional']->id => Catalog::INCLUDED,
            (string) $this->plans['enterprise']->id => Catalog::INCLUDED,
        ]];
        $fields = fn (array $over) => $over + [
            'name' => $pos->name, 'capability' => Catalog::POS, 'pricing' => 'paid', 'unit' => 'store',
            'monthly_price' => 9.9, 'yearly_price' => 99, 'for_sale' => true, 'for_new_merchants' => true,
        ];

        $this->actingAs($this->platform)->put(route('super-admin.addons.plans', $pos->id), $plans(Catalog::INCLUDED))
            ->assertSessionHasErrors('plans');
        $this->assertSame(Catalog::ELIGIBLE, $this->modeIn('basic'), 'طُويت نقطةُ البيع في الأساسيّة والإطلاقُ مفتوح');

        $this->actingAs($this->platform)->put(route('super-admin.addons.update', $pos->id), $fields(['for_sale' => false]))
            ->assertSessionHasErrors('addon');
        $this->assertTrue($pos->fresh()->for_sale, 'رُفعت نقطةُ البيع من البيع والإطلاقُ مفتوح');
        $this->assertTrue(PricingPolicy::enabled());

        // وتعديلٌ لا يمسّ الشرط يُحفظ والإطلاقُ مفتوح
        $this->actingAs($this->platform)->put(route('super-admin.addons.update', $pos->id), $fields(['name' => 'نقطة البيع']))
            ->assertSessionHasNoErrors();
        $this->assertSame('نقطة البيع', $pos->fresh()->name);

        // ومطفأً يُحفظ — ثمّ لا يُفتح الإطلاقُ عليه
        $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => false]);
        $this->actingAs($this->platform)->put(route('super-admin.addons.plans', $pos->id), $plans(Catalog::INCLUDED))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->platform)->post(route('super-admin.addons.launch'), ['enabled' => true, 'acknowledged' => true])
            ->assertSessionHasErrors('addon');
        $this->assertFalse(PricingPolicy::enabled());
    }

    public function test_a_legacy_shop_keeps_selling_whatever_the_catalog_says_about_pos(): void
    {
        [$old, $owner] = $this->shop('basic', 'old@shop.om');
        Currency::create(['business_id' => $old->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->activatePosDevice($old->id);
        $product = Product::create(['business_id' => $old->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true]);
        $sell = fn () => $this->actingAs($owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('l', true),
        ]);

        $this->launch();
        $sell()->assertOk();

        // تُرفع من البيع، وتُفصل عن الأساسيّة كلّها — والقديمُ يبيع كما كان
        PricingPolicy::setEnabled(false);
        $this->addon('pos')->update(['for_sale' => false]);
        $this->posIn('basic', null);
        $sell()->assertOk();

        $this->assertSame(PricingPolicy::LEGACY, $old->fresh()->pricing_policy);
        $this->assertTrue($this->allows($old, Catalog::POS));
        $this->assertSame(2, Order::where('business_id', $old->id)->count());
        $this->assertSame(0, BusinessAddon::where('business_id', $old->id)->count(), 'اشترى القديمُ ما يملكه');
    }

    public function test_catalog_edits_never_break_a_paid_pos_or_an_open_order(): void
    {
        $this->launch();
        [$paying] = $this->shop('basic', 'paying@shop.om');
        $row = $this->buyPos($paying);
        [$pro] = $this->shop('professional', 'pro@shop.om');
        AddonOrders::request($pro, $this->addon('gift_orders'), 'monthly');

        $edit = fn (string $code, array $over) => $this->actingAs($this->platform)->put(route('super-admin.addons.update', $this->addon($code)->id), $over + [
            'name' => $this->addon($code)->name, 'pricing' => 'paid', 'unit' => 'store',
            'monthly_price' => 9.9, 'yearly_price' => 99, 'for_sale' => true, 'for_new_merchants' => true,
        ]);

        // ما تفتحه لا يتبدّل تحت استحقاقٍ مدفوع — ولا تحت طلبٍ لم يُحسم
        $edit('pos', ['capability' => null])->assertSessionHasErrors('capability');
        $edit('gift_orders', ['capability' => null])->assertSessionHasErrors('capability');
        $this->assertSame([Catalog::POS, Catalog::GIFT_ORDERS], [$this->capabilityOf('pos'), $this->capabilityOf('gift_orders')]);

        // وفصلُها عن الأساسيّة (والإطلاقُ مطفأ) يوقف بيعَها الجديد ولا يُسقط ما دُفع
        PricingPolicy::setEnabled(false);
        $this->posIn('basic', null);
        $this->assertTrue($this->allows($paying, Catalog::POS), 'سقط ما دُفع بتعديل الكتالوج');
        $this->assertSame(9.9, (float) AddonOrders::renew($row->fresh())->amount, 'التجديدُ بغير سعره');

        [$next] = $this->shop('basic', 'next@shop.om');
        $next->forceFill(['pricing_policy' => PricingPolicy::ADDONS])->save();
        $this->expectExceptionMessage('لا تُباع في باقتك');
        AddonOrders::request($next->fresh(), $this->addon('pos'), 'monthly');
    }

    public function test_only_a_new_sale_needs_pos_and_every_after_sale_door_stays_open_after_it_ends(): void
    {
        foreach ([
            'pos.index', 'pos.setup', 'pos.setup.activate', 'pos.checkout', 'pos.hold',
            'pos.movements.store', 'pos.coupon.apply', 'pos.orders.items.store', 'pos.orders.items.replace',
        ] as $route) {
            $this->assertSame(Catalog::POS, CheckPlanFeature::featureFor($route), "{$route} يبيع بلا نقطة بيع");
        }
        foreach ([
            'pos.orders', 'pos.order-details', 'pos.receipts', 'pos.receipts.show', 'pos.receipts.search',
            'pos.receipt.pdf', 'pos.receipt.paper', 'pos.orders.balance.collect', 'pos.orders.payment.update',
            'admin.orders.index', 'admin.orders.show', 'admin.orders.status', 'admin.orders.balance.collect',
            'admin.orders.items.update', 'admin.orders.receipt', 'admin.orders.pdf', 'admin.orders.taxInvoice',
        ] as $route) {
            $this->assertNotSame(Catalog::POS, CheckPlanFeature::featureFor($route), "{$route} أُغلق بانقضاء نقطة البيع");
        }

        $this->launch();
        [$business, $owner] = $this->shop('basic');
        Currency::create(['business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->activatePosDevice($business->id);
        $product = Product::create(['business_id' => $business->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true]);
        $this->buyPos($business);
        $this->actingAs($owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('e', true),
        ])->assertOk();
        $order = Order::where('business_id', $business->id)->sole();

        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->assertFalse($this->allows($business, Catalog::POS));
        $this->actingAs($owner)->getJson(route('pos.orders'))->assertOk();
        $this->actingAs($owner)->get(route('admin.orders.show', $order->number))->assertOk();
        foreach ([
            route('admin.orders.items.update', [$order->number, $order->items()->value('id')]),
            route('admin.orders.balance.collect', $order->number),
        ] as $url) {
            $this->assertNotSame(403, $this->actingAs($owner)->call(str_contains($url, '/items/') ? 'PUT' : 'POST', $url, [])->status(), $url);
        }
        $this->assertSame(1, Order::where('business_id', $business->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\User;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\Tenancy;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * فريقُ أبعاد يفتح اشتراكَ التاجر ويُنمّيه — والتاجرُ يستعرض ويسأل ولا يفعّل بنفسه.
 *
 * ═══ ما يُحرس ═══
 *
 *  • حسابٌ جديد من لوحة المنصّة بإحدى ثلاث طرق: تجربةُ الأساسيّة بلا فاتورة؛ أو اشتراكٌ
 *    مدفوعٌ بباقته ودورته وإضافاته — فاتورةٌ للباقة وفاتورةٌ لكلّ إضافة، والحسابُ «بانتظار
 *    السداد» لا يُدخل إليه حتى تُسدَّد؛ أو منحةٌ بتاريخٍ وسبب.
 *  • السعرُ سعرُ سياسة الحساب، والإضافاتُ ما يُباع في باقته — يحكم بها الخادم.
 *  • التجربةُ تُرقّى في حسابها نفسِه: تصدر فاتورةُ الباقة، والمتجرُ على باقته حتى تُسدَّد.
 *  • نصفُ السنة للإضافات: ستّةُ أشهرٍ بسعر الشهر، بفاتورةٍ واحدة ودورةٍ حقيقيّة وتجديدٍ مثلها.
 *  • المجّانيّةُ لا تُفعَّل بطلب التاجر، والمنحةُ لا تكون بلا تاريخ.
 *  • والمتجرُ على السياسة السابقة كما كان: سعرُه ونقطةُ بيعه، ولا إضافاتٍ تُطلب له.
 */
class AbaadsTeamOpensAndGrowsEveryMerchantsSubscriptionTest extends TestCase
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

    /** شركةٌ من نموذج «إضافة شركة» — بطريقة اشتراكها */
    private function open(string $name, array $subscription)
    {
        return $this->actingAs($this->platform)->post(route('super-admin.businesses.store'), $subscription + [
            'name' => $name, 'type' => 'عام', 'status' => 'نشط', 'phone' => '+96891234567',
            'login_username' => 'u'.substr(md5($name), 0, 10), 'login_password' => 'secret12345',
        ]);
    }

    private function opened(string $name): Business
    {
        return Business::where('name', $name)->firstOrFail();
    }

    private function ownerOf(Business $business): User
    {
        return User::where('business_id', $business->id)->firstOrFail();
    }

    public function test_a_paid_merchant_opened_by_abaad_gets_one_invoice_per_item_and_opens_only_when_the_plan_is_paid(): void
    {
        $this->launch(9.9, 99);
        $pro = $this->plans['professional'];

        $this->open('متجر الاحترافية', [
            'subscription_mode' => 'paid', 'plan_id' => $pro->id, 'cycle' => 'semiannual',
            'addons' => [['addon_id' => $this->addon('gift_orders')->id], ['addon_id' => $this->addon('extra_branch')->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $shop = $this->opened('متجر الاحترافية');
        $this->assertSame([PricingPolicy::ADDONS, $pro->id, Business::AWAITING_PAYMENT], [$shop->pricing_policy, (int) $shop->plan_id, $shop->status]);

        // فاتورةٌ للباقة بنصف سنتها، وفاتورةٌ لكلّ إضافة — ثلاثٌ لا أكثر
        $invoices = Invoice::where('business_id', $shop->id)->orderBy('id')->get();
        $this->assertCount(3, $invoices);
        $this->assertSame(round((float) $pro->monthly_price * 6, 3), (float) $invoices[0]->amount);
        $this->assertSame([Billing::KIND_SUBSCRIPTION, Billing::KIND_ADDON, Billing::KIND_ADDON], $invoices->pluck('kind')->all());
        $this->assertSame([29.4, 118.8], [(float) $invoices[1]->amount, (float) $invoices[2]->amount], 'نصف سنة الإضافة ستّةُ أشهرٍ بسعر شهرها');
        $this->assertStringContainsString('نصف سنويًّا', $invoices[1]->note);

        // ولا يُدخل إليه قبل السداد — ولا إضافةَ تسري
        $this->assertSame(Tenancy::AWAITING_PAYMENT, Tenancy::blockReason($this->ownerOf($shop)));
        $this->assertSame(0, BusinessAddon::where('business_id', $shop->id)->count());

        Billing::markPaid($invoices[0]);
        $shop = $shop->fresh();
        $this->assertSame(['نشط', '2027-05-02'], [$shop->status, $shop->ends_at->toDateString()]);
        $this->assertNull(Tenancy::blockReason($this->ownerOf($shop)));
        $this->assertSame(0, BusinessAddon::where('business_id', $shop->id)->count(), 'إضافةٌ سرت قبل سداد فاتورتها');

        Billing::markPaid($invoices[1]);
        $gift = BusinessAddon::where('business_id', $shop->id)->firstOrFail();
        $this->assertSame(['semiannual', '2027-05-02'], [$gift->cycle, $gift->ends_at->toDateString()]);
    }

    public function test_the_server_refuses_what_the_plan_includes_or_does_not_sell_and_writes_nothing(): void
    {
        $this->launch(9.9, 99);

        // نقطةُ البيع مشمولةٌ في الاحترافيّة — لا تُشترى فيها مرّتين
        $this->open('متجر مرفوض', [
            'subscription_mode' => 'paid', 'plan_id' => $this->plans['professional']->id, 'cycle' => 'monthly',
            'addons' => [['addon_id' => $this->addon('pos')->id]],
        ])->assertSessionHasErrors('subscription_mode');
        // والفرعُ الإضافيّ لا يُباع للمؤسسات
        $this->open('متجر مرفوض', [
            'subscription_mode' => 'paid', 'plan_id' => $this->plans['enterprise']->id, 'cycle' => 'monthly',
            'addons' => [['addon_id' => $this->addon('extra_branch')->id]],
        ])->assertSessionHasErrors('subscription_mode');

        $this->assertNull(Business::where('name', 'متجر مرفوض')->first(), 'شركةٌ بقيت بعد رفض إضافتها');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, AddonOrder::count());
    }

    public function test_a_trial_opened_by_abaad_is_basic_without_an_invoice_and_without_pos_on_the_new_policy(): void
    {
        $this->launch(9.9, 99);

        $this->open('تجربة من الفريق', ['subscription_mode' => 'trial', 'plan_id' => $this->plans['enterprise']->id])->assertSessionHasNoErrors();
        $trial = $this->opened('تجربة من الفريق');

        $this->assertSame([$this->plans['basic']->id, '2026-11-16', 'نشط'], [(int) $trial->plan_id, $trial->ends_at->toDateString(), $trial->status]);
        $this->assertSame(0, Invoice::where('business_id', $trial->id)->count());
        $this->assertFalse($this->allows($trial, Catalog::POS));
        $this->actingAs($this->ownerOf($trial))->getJson(route('pos.index'))->assertForbidden();

        // ولا إضافاتٍ تُفوتَر للتجربة
        $this->open('تجربة بإضافة', ['subscription_mode' => 'trial', 'addons' => [['addon_id' => $this->addon('pos')->id]]])
            ->assertSessionHasErrors('addons');
    }

    public function test_a_grant_is_dated_and_reasoned_and_an_invoice_with_it_starts_after_the_grant(): void
    {
        $this->launch(9.9, 99);
        $pro = $this->plans['professional'];

        $this->open('ممنوح بلا تاريخ', ['subscription_mode' => 'grant', 'plan_id' => $pro->id, 'grant_reason' => 'عرض افتتاح'])->assertSessionHasErrors('grant_until');
        $this->open('ممنوح', [
            'subscription_mode' => 'grant', 'plan_id' => $pro->id, 'cycle' => 'monthly',
            'grant_until' => '2026-11-12', 'grant_reason' => 'عرض افتتاح متّفق عليه',
        ])->assertSessionHasNoErrors();

        $granted = $this->opened('ممنوح');
        $this->assertSame(['نشط', '2026-11-12'], [$granted->status, $granted->ends_at->toDateString()]);
        $this->assertNull(Tenancy::blockReason($this->ownerOf($granted)));
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $granted->id]);

        Billing::markPaid(Invoice::where('business_id', $granted->id)->firstOrFail());
        $this->assertSame('2026-12-12', $granted->fresh()->ends_at->toDateString(), 'الدورةُ المدفوعة تبدأ بعد المنحة');
    }

    public function test_a_trial_becomes_a_paid_plan_in_its_own_account_only_once_paid(): void
    {
        $this->launch(9.9, 99);
        [$trial, $owner] = $this->shop('basic', 'trial@shop.om');
        $product = Product::create(['business_id' => $trial->id, 'name' => 'ورد', 'price' => 5]);
        $this->assertFalse($this->allows($trial, Catalog::POS));

        $this->actingAs($this->platform)->post(route('super-admin.businesses.upgrade', $trial->id), [
            'plan_id' => $this->plans['professional']->id, 'cycle' => 'yearly',
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('business_id', $trial->id)->firstOrFail();
        $this->assertSame([(int) $this->plans['professional']->id, (float) $this->plans['professional']->yearly_price], [(int) $invoice->plan_id, (float) $invoice->amount]);
        // والمتجرُ على باقته حتى السداد — لا مزايا تُفتح بفاتورةٍ لم تُدفع
        $this->assertSame($this->plans['basic']->id, (int) $trial->fresh()->plan_id);
        $this->assertFalse($this->allows($trial, Catalog::POS));

        Billing::markPaid($invoice);
        $paid = $trial->fresh();
        $this->assertSame([$this->plans['professional']->id, '2027-11-02'], [(int) $paid->plan_id, $paid->ends_at->toDateString()]);
        $this->assertTrue($this->allows($paid, Catalog::POS), 'نقطةُ البيع مشمولةٌ في الاحترافيّة');
        // الحسابُ نفسُه بمالكه وبياناته — لا متجرَ ثانٍ
        $this->assertSame(1, Business::where('name', $trial->name)->count());
        $this->assertSame($trial->id, $owner->fresh()->business_id);
        $this->assertTrue($product->fresh()->exists);
        $this->assertSame(Billing::SUB_ACTIVE, Subscription::where('invoice_id', $invoice->id)->value('status'));
    }

    public function test_an_upgrade_whose_shop_moved_plan_before_payment_is_refused(): void
    {
        $this->launch(9.9, 99);
        [$trial] = $this->shop('basic', 'moved@shop.om');
        $upgrade = Billing::upgrade($trial, $this->plans['professional'], 'monthly');

        $trial->update(['plan_id' => $this->plans['enterprise']->id]);

        $this->expectException(DomainException::class);
        Billing::markPaid($upgrade['invoice']);
    }

    public function test_semiannual_addons_bill_six_months_once_and_renew_the_same(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'half@shop.om');

        $order = AddonOrders::approve(AddonOrders::request($shop, $this->addon('loyalty'), 'semiannual')['order']);
        $this->assertSame([23.4, 'semiannual'], [(float) $order->invoice->amount, $order->cycle]);
        $this->pay($order);

        $row = BusinessAddon::where('business_id', $shop->id)->firstOrFail();
        $this->assertSame('2027-05-02', $row->ends_at->toDateString());

        $renewal = AddonOrders::renew($row);
        $this->assertSame([23.4, 'semiannual'], [(float) $renewal->invoice->amount, $renewal->cycle]);
        $this->pay($renewal);
        $this->assertSame('2027-11-02', $row->fresh()->ends_at->toDateString(), 'التجديدُ ستّةُ أشهرٍ بعد الأولى');
        $this->assertSame(2, Invoice::where('business_id', $shop->id)->count());
    }

    public function test_a_merchant_asks_but_never_activates_an_addon_and_abaad_orders_a_paid_one_through_its_invoice(): void
    {
        $this->launch(9.9, 99);
        [$shop, $owner] = $this->shop('basic', 'asks@shop.om');
        $this->addon('whatsapp')->update(['pricing' => Catalog::FREE]);

        // المجّانيّةُ بطلب التاجر تنتظر الاعتماد
        $this->actingAs($owner)->post(route('admin.subscription.addons.request'), ['code' => $this->addon('whatsapp')->code, 'cycle' => 'monthly'])->assertSessionHasNoErrors();
        $this->assertSame(AddonOrders::REQUESTED, AddonOrder::where('business_id', $shop->id)->value('status'));
        $this->assertFalse($this->allows($shop, 'whatsapp'), 'تفعّلت إضافةٌ بطلب التاجر وحده');

        // وطلبُ الفريق للتاجر يمرّ بالفاتورة — ولا يسري قبل سدادها
        $this->actingAs($this->platform)->post(route('super-admin.businesses.addons.order', $shop->id), [
            'addon_id' => $this->addon('website')->id, 'cycle' => 'yearly',
        ])->assertSessionHasNoErrors();
        $order = AddonOrder::where('business_id', $shop->id)->where('subscription_addon_id', $this->addon('website')->id)->firstOrFail();
        $this->assertSame([AddonOrders::INVOICED, 129.0], [$order->status, (float) $order->invoice->amount]);
        $this->assertFalse($this->allows($shop, 'website'));
        $this->pay($order);
        $this->assertTrue($this->allows($shop, 'website'));

        // و«حسب الطلب» بلا سعرٍ لا تصدر، وسعرٌ غيرُ الكتالوج بلا سببٍ لا يُقبل
        $this->post(route('super-admin.businesses.addons.order', $shop->id), ['addon_id' => $this->addon('pos')->id, 'cycle' => 'monthly', 'unit_price' => 5])
            ->assertSessionHasErrors('addon');
        $this->assertSame(0, AddonOrder::where('subscription_addon_id', $this->addon('pos')->id)->count());
    }

    public function test_an_addon_grant_without_an_end_date_is_refused(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'grant@shop.om');

        $this->actingAs($this->platform)->post(route('super-admin.businesses.addons.grant', $shop->id), [
            'addon_id' => $this->addon('loyalty')->id, 'reason' => 'تعويض عن عطل',
        ])->assertSessionHasErrors('ends_at');
        $this->assertSame(0, BusinessAddon::count());

        $this->expectException(DomainException::class);
        AddonOrders::grant($shop, $this->addon('loyalty'), 1, null, 'تعويض عن عطل');
    }

    public function test_a_legacy_merchant_keeps_its_price_and_pos_and_no_addon_is_sold_to_it(): void
    {
        // متجرٌ قائمٌ قبل الإطلاق — ثمّ يُفتح الإطلاق
        [$legacy] = $this->shop('basic', 'legacy@shop.om');
        $this->launch(9.9, 99);
        $legacy = $legacy->fresh();
        $this->assertSame(PricingPolicy::LEGACY, $legacy->pricing_policy);

        $this->assertTrue($this->allows($legacy, Catalog::POS));
        $this->assertSame((float) $this->plans['basic']->monthly_price, Billing::price($legacy, 'monthly'));
        $this->assertSame(round((float) $this->plans['basic']->monthly_price * 6, 3), Billing::price($legacy, 'semiannual'));

        $this->actingAs($this->platform)->post(route('super-admin.businesses.addons.order', $legacy->id), [
            'addon_id' => $this->addon('website')->id, 'cycle' => 'monthly',
        ])->assertSessionHasErrors('addon');
        $this->assertSame(0, AddonOrder::count());

        // وحسابٌ جديدٌ والإطلاقُ مطفأ: على السابقة بسعرها، والإضافاتُ تُردّ
        $this->shutLaunch();
        $this->open('جديد على السابقة', [
            'subscription_mode' => 'paid', 'plan_id' => $this->plans['professional']->id, 'cycle' => 'monthly',
            'addons' => [['addon_id' => $this->addon('gift_orders')->id]],
        ])->assertSessionHasErrors('subscription_mode');
        $this->open('جديد على السابقة', ['subscription_mode' => 'paid', 'plan_id' => $this->plans['professional']->id, 'cycle' => 'monthly'])->assertSessionHasNoErrors();
        $created = $this->opened('جديد على السابقة');
        $this->assertSame(PricingPolicy::LEGACY, $created->pricing_policy);
        $this->assertSame((float) $this->plans['professional']->monthly_price, (float) Invoice::where('business_id', $created->id)->value('amount'));
    }

    public function test_the_create_screen_shows_each_plan_at_its_policy_price_in_three_cycles_with_its_addons(): void
    {
        $this->launch(9.9, 99);

        $this->actingAs($this->platform)->get(route('super-admin.businesses.create'))->assertOk()
            ->assertInertia(function ($page) {
                $page->where('options.subscription.policy', PricingPolicy::ADDONS)
                    ->where('options.subscription.trial_days', 14)
                    ->where('options.subscription.cycles', fn ($c) => collect($c)->pluck('value')->all() === ['monthly', 'semiannual', 'yearly']);
                $plans = collect($page->toArray()['props']['options']['subscription']['plans'])->keyBy('id');
                $basic = $plans[$this->plans['basic']->id];
                $this->assertEquals(['monthly' => 9.9, 'semiannual' => 59.4, 'yearly' => 99.0], $basic['prices']);
                $this->assertSame(['pos', 'website', 'marketing', 'loyalty', 'whatsapp'], collect($basic['eligible'])->map(fn ($e) => SubscriptionAddon::find($e['id'])->code)->all());
                $this->assertSame([], $basic['included']);
                $pro = $plans[$this->plans['professional']->id];
                $this->assertCount(5, $pro['included']);
                $this->assertSame(['extra_branch', 'gift_orders', 'whatsapp_own_number', 'api_integrations'], collect($pro['eligible'])->map(fn ($e) => SubscriptionAddon::find($e['id'])->code)->all());
            });
    }
}

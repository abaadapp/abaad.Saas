<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Subscription;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\Store\GiftOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * شاشتا التعديل القديمتان لا تفتحان ما يُشترى بفاتورة.
 *
 * `BusinessController::update` و`SubscriptionController::update` (عبر `syncBusiness`)
 * تكتبان في المتجر باقتَه وتاريخَه وحالتَه. ولمتجر السياسة الجديدة الباقةُ تفتح ما
 * تشمله — فكانت القائمةُ المنسدلة ترقيةً بلا فاتورة، وحقلُ التاريخ تجديدًا بلا سداد،
 * ومربّعُ الإهداء إضافةً مدفوعةً بلا تاريخ.
 *
 * ═══ ما يُحرس ═══
 *
 *  • لا ترقيةَ ولا تمديدَ ولا فتحَ لحسابٍ بانتظار السداد من الشاشتين بلا فاتورة.
 *  • إلّا منحةً صريحة: سببٌ مكتوب، وتاريخُ انتهاءٍ لم يمضِ، وسطرٌ في سجلّ النشاط.
 *  • ومربّعُ الإهداء لا يفتح الإضافةَ لمتجر السياسة الجديدة — من أيّ بابٍ كُتب.
 *  • والتصحيحُ الذي لا يفتح شيئًا يمرّ: مبلغٌ، وتاريخٌ أقصر، وتنزيلٌ.
 *  • والمتجرُ على السياسة السابقة يُعدَّل كما كان.
 *  • وزرُّ «منحة إدارية…» في الشاشتين لمن يُسأل عنها وحده — والسابقةُ بلا زرّ.
 *
 * والترقيةُ المدفوعة لا تفتح إلّا بسدادها: `AbaadsTeamOpensAndGrowsEveryMerchantsSubscriptionTest`.
 */
class AnOldEditScreenOpensNothingWithoutPaymentTest extends TestCase
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

    /** نموذجُ «تعديل الشركة» كما ترسله الشاشة — بما يُغيَّر فيه */
    private function editShop(Business $shop, array $change = [])
    {
        return $this->actingAs($this->platform)->put(route('super-admin.businesses.update', $shop->id), $change + [
            'name' => $shop->name, 'type' => $shop->type, 'status' => $shop->status,
            'plan_id' => $shop->plan_id, 'starts_at' => $shop->starts_at?->toDateString(), 'ends_at' => $shop->ends_at?->toDateString(),
        ]);
    }

    /** نافذةُ «تعديل الاشتراك» كما ترسلها الشاشة */
    private function editCycle(Subscription $cycle, array $change = [])
    {
        return $this->actingAs($this->platform)->put(route('super-admin.subscriptions.update', $cycle->id), $change + [
            'plan_id' => $cycle->plan_id, 'starts_at' => $cycle->starts_at->toDateString(), 'ends_at' => $cycle->ends_at->toDateString(),
            'amount' => (string) $cycle->amount, 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ]);
    }

    /** دورةٌ مسدَّدة — كما يكتبها التجديدُ بعد السداد */
    private function paidCycle(Business $shop): Subscription
    {
        $invoice = Billing::renew($shop->fresh(), 'monthly')['invoice'];
        Billing::markPaid($invoice);

        return Subscription::where('invoice_id', $invoice->id)->sole();
    }

    private function granted(Business $shop): bool
    {
        return ActivityLog::where('description', 'like', '%منحة إدارية لـ«'.$shop->name.'»%')->exists();
    }

    public function test_the_shop_screen_does_not_upgrade_a_new_policy_shop_without_its_invoice(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'plan@shop.om');
        $this->assertSame(PricingPolicy::ADDONS, $shop->pricing_policy);

        $this->editShop($shop, ['plan_id' => $this->plans['professional']->id])->assertSessionHasErrors('grant_reason');

        $this->assertSame((int) $this->plans['basic']->id, (int) $shop->fresh()->plan_id);
        $this->assertFalse($this->allows($shop, Catalog::POS), 'قائمةٌ منسدلة فتحت نقطةَ البيع بلا فاتورة');
        $this->assertFalse($this->granted($shop));
    }

    public function test_an_explicit_grant_with_a_reason_and_a_date_opens_and_is_logged(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'grant@shop.om');

        // تاريخٌ مضى لا يكون منحة — الحسابُ يُفتح إلى يومٍ مكتوب
        $this->editShop($shop, ['plan_id' => $this->plans['professional']->id, 'ends_at' => '2026-11-01', 'grant_reason' => 'عرض إطلاق متفق عليه'])
            ->assertSessionHasErrors('ends_at');
        $this->assertSame((int) $this->plans['basic']->id, (int) $shop->fresh()->plan_id);

        $this->editShop($shop, ['plan_id' => $this->plans['professional']->id, 'grant_reason' => 'عرض إطلاق متفق عليه'])
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $this->plans['professional']->id, (int) $shop->fresh()->plan_id);
        $this->assertTrue($this->allows($shop, Catalog::POS));
        $log = ActivityLog::where('description', 'like', '%منحة إدارية%')->sole();
        $this->assertStringContainsString('عرض إطلاق متفق عليه', $log->description);
        $this->assertStringContainsString('حتى '.$shop->ends_at->toDateString(), $log->description);
    }

    public function test_the_shop_screen_neither_extends_a_new_policy_shop_nor_opens_an_account_awaiting_payment(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'dates@shop.om');
        $ends = $shop->ends_at->toDateString();

        $this->editShop($shop, ['ends_at' => '2027-11-02'])->assertSessionHasErrors('grant_reason');
        $this->editShop($shop, ['ends_at' => null])->assertSessionHasErrors('grant_reason');
        $this->assertSame($ends, $shop->fresh()->ends_at->toDateString());

        // حسابٌ أُنشئ مدفوعًا بانتظار سداده — لا يُفتح بالحالة، ولا بالتعطيل ثمّ إعادة التشغيل
        $shop->forceFill(['status' => Business::AWAITING_PAYMENT])->save();
        $this->editShop($shop->fresh(), ['status' => 'نشط'])->assertSessionHasErrors('grant_reason');
        $this->editShop($shop->fresh(), ['status' => 'معطل'])->assertSessionHasErrors('grant_reason');
        $this->actingAs($this->platform)->delete(route('super-admin.businesses.destroy', $shop->id));
        $this->assertSame(Business::AWAITING_PAYMENT, $shop->fresh()->status);
    }

    public function test_the_gift_toggle_does_not_open_a_paid_addon_on_the_new_policy(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'gift@shop.om');

        $this->editShop($shop, ['gift_orders_enabled' => true])->assertSessionHasErrors('gift_orders_enabled');
        $this->assertFalse((bool) $shop->fresh()->gift_orders_enabled);

        // ولو كُتب المربّعُ من بابٍ آخر (الإنشاء) — الإضافةُ وحدها تفتحه
        $shop->forceFill(['gift_orders_enabled' => true])->save();
        $this->assertFalse(GiftOrders::on($shop->id), 'مربّعٌ بلا فاتورةٍ ولا تاريخ فتح إضافةً مدفوعة');
    }

    public function test_the_subscription_screen_neither_upgrades_nor_extends_a_new_policy_shop_without_payment(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('basic', 'cycle@shop.om');
        $cycle = $this->paidCycle($shop);
        $ends = $shop->fresh()->ends_at->toDateString();

        $this->editCycle($cycle, ['plan_id' => $this->plans['professional']->id])->assertSessionHasErrors('grant_reason');
        $this->editCycle($cycle, ['ends_at' => '2028-01-01'])->assertSessionHasErrors('grant_reason');

        // والرميُ داخل المعاملة يُرجع الدورةَ والمتجرَ كما كانا
        $this->assertSame([(int) $this->plans['basic']->id, $ends], [(int) $shop->fresh()->plan_id, $shop->fresh()->ends_at->toDateString()]);
        $this->assertSame([(int) $this->plans['basic']->id, $ends], [(int) $cycle->fresh()->plan_id, $cycle->fresh()->ends_at->toDateString()]);
        $this->assertFalse($this->allows($shop, Catalog::POS));

        $this->editCycle($cycle, ['plan_id' => $this->plans['professional']->id, 'grant_reason' => 'تعويض عن انقطاع الخدمة'])->assertSessionHasNoErrors();
        $this->assertSame((int) $this->plans['professional']->id, (int) $shop->fresh()->plan_id);
        $this->assertTrue($this->granted($shop));
    }

    public function test_a_correction_that_opens_nothing_still_passes_without_a_reason(): void
    {
        $this->launch(9.9, 99);
        [$shop] = $this->shop('professional', 'fix@shop.om');
        $cycle = $this->paidCycle($shop);

        // مبلغٌ وتاريخٌ أقصر في الدورة، ثمّ تنزيلٌ واسمٌ في الشركة — لا شيءَ يُفتح
        $this->editCycle($cycle, ['amount' => '25.000', 'ends_at' => $cycle->ends_at->copy()->subDays(3)->toDateString()])->assertSessionHasNoErrors();
        $this->editShop($shop->fresh(), ['name' => 'اسم مصحَّح', 'plan_id' => $this->plans['basic']->id])->assertSessionHasNoErrors();

        $fixed = $shop->fresh();
        $this->assertSame(['اسم مصحَّح', (int) $this->plans['basic']->id], [$fixed->name, (int) $fixed->plan_id]);
        $this->assertSame($cycle->ends_at->copy()->subDays(3)->toDateString(), $fixed->ends_at->toDateString());
        $this->assertSame(0, ActivityLog::where('description', 'like', '%منحة إدارية%')->count());
    }

    public function test_a_legacy_shop_is_edited_on_both_screens_as_before(): void
    {
        [$legacy] = $this->shop('basic', 'legacy@shop.om');
        $this->assertSame(PricingPolicy::LEGACY, $legacy->pricing_policy);
        $cycle = $this->paidCycle($legacy);

        $this->editCycle($cycle, ['ends_at' => '2028-01-01'])->assertSessionHasNoErrors();
        $this->editShop($legacy->fresh(), ['plan_id' => $this->plans['professional']->id, 'ends_at' => '2028-06-01', 'gift_orders_enabled' => true])
            ->assertSessionHasNoErrors();

        $edited = $legacy->fresh();
        $this->assertSame([(int) $this->plans['professional']->id, '2028-06-01'], [(int) $edited->plan_id, $edited->ends_at->toDateString()]);
        $this->assertTrue(GiftOrders::on($legacy->id), 'مربّعُ الإهداء يفتح للسابقة كما كان');
        $this->assertSame(0, ActivityLog::where('description', 'like', '%منحة إدارية%')->count());
    }

    public function test_the_two_screens_offer_the_grant_button_only_to_a_shop_it_is_asked_of(): void
    {
        // السابقةُ قبل الإطلاق، والجديدةُ بعده — كما يختمهما الإنشاء
        [$legacy] = $this->shop('basic', 'old-button@shop.om');
        $this->launch(9.9, 99);
        [$fresh] = $this->shop('basic', 'new-button@shop.om');
        $this->paidCycle($legacy);
        $this->paidCycle($fresh);

        foreach ([[$legacy, false], [$fresh, true]] as [$shop, $asked]) {
            $this->actingAs($this->platform)->get(route('super-admin.businesses.edit', $shop->id))->assertOk()
                ->assertInertia(fn ($page) => $page->where('business.grantable', $asked));
        }

        $this->actingAs($this->platform)->get(route('super-admin.subscriptions.index'))->assertOk()
            ->assertInertia(function ($page) use ($legacy, $fresh) {
                $rows = collect($page->toArray()['props']['subscriptions'])->keyBy('business_id');
                $this->assertSame([false, true], [$rows[$legacy->id]['grantable'], $rows[$fresh->id]['grantable']]);
            });

        // وحسابٌ بانتظار السداد يُسأل عنها في أيّ سياسة
        $legacy->forceFill(['status' => Business::AWAITING_PAYMENT])->save();
        $this->actingAs($this->platform)->get(route('super-admin.businesses.edit', $legacy->id))
            ->assertInertia(fn ($page) => $page->where('business.grantable', true));
    }
}

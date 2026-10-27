<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AddonOrder;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Billing;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * الطلبُ يُفعَّل بالشروط التي طُلب بها — لا بما صار عليه الكتالوج ساعةَ السداد.
 *
 * كان التفعيلُ يسأل الكتالوجَ: أمجّانيّةٌ هي؟ فإضافةٌ بيعت شهرًا بـ٩٫٩ ثمّ
 * جُعلت «مجّانيّة» قبل سداد فاتورتها كانت تُفعَّل بلا نهايةٍ ولا تجديد — فاتورةٌ
 * مدفوعة تفتح ما لم يُبَع.
 *
 * ═══ ما يُحرس ═══
 *
 *   - مدفوعةٌ صارت مجّانيّةً قبل السداد أو قبل الاعتماد: تُفوتَر وتُفعَّل بدورتها.
 *   - مجّانيّةٌ صارت مدفوعة: لا يُطالَب من أخذها بشيء، ولا تُجدَّد بفاتورة.
 *   - السعرُ الشهريّ والسنويّ يتغيّران بعد الفاتورة: لا الفاتورةُ ولا المدّةُ ولا التجديد.
 *   - لا تفعيلَ قبل السداد، ولا مرّتين بسدادٍ مكرَّر.
 *   - متجرٌ رُقّي إلى باقةٍ تشمل الإضافةَ لا تُصدر له فاتورةُ تجديدها.
 */
class AnAddonOrderKeepsTheTermsItWasPlacedOnTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 10:00:00');
        app()->setLocale('ar');
        $this->seedPlans();
        $this->launch();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeFree(string $code): void
    {
        $this->addon($code)->update(['pricing' => Catalog::FREE, 'monthly_price' => null, 'yearly_price' => null]);
    }

    private function entitlementOf(AddonOrder $order): BusinessAddon
    {
        return BusinessAddon::findOrFail($order->fresh()->business_addon_id);
    }

    /** ما يقوله الاستحقاقُ عن نفسه: مصدرُه ودورتُه وسعرُه وبدايتُه ونهايتُه وتجديدُه */
    private function terms(BusinessAddon $row): array
    {
        $row = $row->fresh();

        return [$row->source, $row->cycle, (float) $row->unit_price, $row->starts_at->toDateString(), $row->ends_at?->toDateString(), (bool) $row->renews];
    }

    public function test_a_paid_addon_made_free_after_its_invoice_still_opens_for_the_cycle_it_was_sold_for(): void
    {
        [$a] = $this->shop('basic', 'monthly@shop.om');
        [$b] = $this->shop('basic', 'yearly@shop.om');
        $monthly = AddonOrders::approve(AddonOrders::request($a, $this->addon('pos'), 'monthly')['order']);
        $yearly = AddonOrders::approve(AddonOrders::request($b, $this->addon('pos'), 'yearly')['order']);
        $this->assertSame([9.9, 99.0], [(float) $monthly->invoice->amount, (float) $yearly->invoice->amount]);

        $this->makeFree('pos');
        $this->pay($monthly);
        $this->pay($yearly);

        $this->assertSame([BusinessAddon::PURCHASE, 'monthly', 9.9, '2026-11-02', '2026-12-02', true], $this->terms($this->entitlementOf($monthly)), 'فُعّلت فاتورةٌ مدفوعة بلا نهاية');
        $this->assertSame([BusinessAddon::PURCHASE, 'yearly', 99.0, '2026-11-02', '2027-11-02', true], $this->terms($this->entitlementOf($yearly)));
        $this->assertSame([9.9, 99.0], [(float) $monthly->invoice->fresh()->amount, (float) $yearly->invoice->fresh()->amount], 'تغيّر مبلغُ فاتورةٍ صدرت');
        $this->assertSame([Catalog::PAID, Catalog::PAID], [$monthly->fresh()->pricing, $yearly->fresh()->pricing]);

        // وتنقضي بدورتها — لا تبقى مفتوحةً لأنّ الكتالوج صار مجّانًا
        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->assertFalse($this->allows($a, Catalog::POS));
        $this->assertTrue($this->allows($b, Catalog::POS));
    }

    public function test_a_paid_addon_made_free_before_approval_is_still_invoiced_at_its_price_unless_the_platform_discounts_it(): void
    {
        [$a] = $this->shop('basic', 'a@shop.om');
        [$b] = $this->shop('basic', 'b@shop.om');
        $ordered = AddonOrders::request($a, $this->addon('website'), 'monthly')['order'];
        $discounted = AddonOrders::request($b, $this->addon('website'), 'yearly')['order'];

        $this->makeFree('website');

        $approved = AddonOrders::approve($ordered);
        $this->assertSame([AddonOrders::INVOICED, 12.9], [$approved->status, (float) $approved->invoice->amount]);
        $this->assertFalse($this->allows($a, 'website'), 'فُتحت قبل سداد فاتورتها');

        // والتنازلُ عن السعر قرارٌ يُكتب سببُه — ويبقى استحقاقًا بدورته لا بلا نهاية
        $waived = AddonOrders::approve($discounted, 0.0, 'صارت مجّانيّة قبل اعتماد طلبه');
        $this->assertNull($waived->invoice_id);
        $this->assertSame([BusinessAddon::PURCHASE, 'yearly', 0.0, '2026-11-02', '2027-11-02', true], $this->terms($this->entitlementOf($waived)));
    }

    public function test_a_free_addon_made_paid_charges_nothing_for_what_was_taken_free(): void
    {
        $this->makeFree('loyalty');
        [$early] = $this->shop('basic', 'early@shop.om');
        // والمجّانيّةُ تنتظر اعتمادَ مدير المنصّة — لا تُفعَّل بطلب التاجر (`AddonOrders::request`)
        $taken = AddonOrders::request($early, $this->addon('loyalty'), 'monthly')['order'];
        $this->assertSame([AddonOrders::REQUESTED, Catalog::FREE, null], [$taken->status, $taken->pricing, $taken->invoice_id]);
        $taken = AddonOrders::approve($taken);
        $this->assertSame([AddonOrders::PAID, Catalog::FREE, null], [$taken->status, $taken->pricing, $taken->invoice_id]);
        $row = $this->entitlementOf($taken);
        $this->assertSame([BusinessAddon::PURCHASE, null, 0.0, '2026-11-02', null, false], $this->terms($row));

        $this->addon('loyalty')->update(['pricing' => Catalog::PAID, 'monthly_price' => 3.9, 'yearly_price' => 39]);

        Carbon::setTestNow('2027-06-01 10:00:00');
        $this->assertTrue($this->allows($early, 'loyalty'), 'سقط ما أُخذ مجّانًا');
        try {
            AddonOrders::renew($row->fresh());
            $this->fail('طولب بفاتورةٍ عمّا أُخذ مجّانًا');
        } catch (DomainException $e) {
            $this->assertStringContainsString('مجّانًا بلا دورة', $e->getMessage());
        }
        $this->assertSame(0, Invoice::where('business_id', $early->id)->count());

        // ومن يطلبها بعد التغيير يُفوتَر بسعرها
        [$late] = $this->shop('basic', 'late@shop.om');
        $order = AddonOrders::approve(AddonOrders::request($late, $this->addon('loyalty'), 'monthly')['order']);
        $this->assertSame([Catalog::PAID, 3.9], [$order->pricing, (float) $order->invoice->amount]);
    }

    public function test_monthly_and_yearly_price_changes_after_the_invoice_touch_neither_the_invoice_nor_the_term_nor_the_renewal(): void
    {
        [$a] = $this->shop('basic', 'm@shop.om');
        [$b] = $this->shop('basic', 'y@shop.om');
        $monthly = AddonOrders::approve(AddonOrders::request($a, $this->addon('marketing'), 'monthly')['order']);
        $yearly = AddonOrders::approve(AddonOrders::request($b, $this->addon('marketing'), 'yearly')['order']);

        $this->addon('marketing')->update(['monthly_price' => 5, 'yearly_price' => 50]);
        $this->pay($monthly);
        $this->pay($yearly);

        $m = $this->entitlementOf($monthly);
        $y = $this->entitlementOf($yearly);
        $this->assertSame([2.9, 29.0], [(float) $monthly->invoice->fresh()->amount, (float) $yearly->invoice->fresh()->amount]);
        $this->assertSame([BusinessAddon::PURCHASE, 'monthly', 2.9, '2026-11-02', '2026-12-02', true], $this->terms($m));
        $this->assertSame([BusinessAddon::PURCHASE, 'yearly', 29.0, '2026-11-02', '2027-11-02', true], $this->terms($y));

        // والتجديدُ بالسعر المتّفق ودورته
        $this->assertSame(2.9, (float) AddonOrders::renew($m)->invoice->amount);
        $renewal = AddonOrders::renew($y);
        $this->assertSame(29.0, (float) $renewal->invoice->amount);
        $this->pay($renewal);
        $this->assertSame('2028-11-02', $y->fresh()->ends_at->toDateString());
    }

    public function test_nothing_opens_before_its_invoice_is_paid_and_a_repeated_payment_opens_it_once(): void
    {
        [$business] = $this->shop('basic');
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('whatsapp'), 'monthly')['order']);

        // فاتورةٌ غيرُ مسدَّدةٍ تصل التفعيلَ من بابٍ آخر — تُردّ
        try {
            AddonOrders::settle($order->invoice);
            $this->fail('فُعّلت إضافةٌ بفاتورةٍ غير مسدَّدة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('قبل سداد فاتورتها', $e->getMessage());
        }
        $this->assertSame(0, BusinessAddon::count());
        $this->assertSame(AddonOrders::INVOICED, $order->fresh()->status);

        $this->pay($order);
        $this->pay($order);
        Billing::markPaid($order->invoice->fresh());
        AddonOrders::settle($order->invoice->fresh());

        $this->assertSame(1, BusinessAddon::where('business_id', $business->id)->count(), 'سدادٌ مكرَّر فتح استحقاقين');
        $this->assertSame('2026-12-02', $this->entitlementOf($order)->ends_at->toDateString(), 'سدادٌ مكرَّر مدّ الدورة');
        $this->assertSame(1, Invoice::where('business_id', $business->id)->count());
        $this->assertSame(1, ActivityLog::where('description', 'like', 'فُعّلت إضافة%')->count());
    }

    public function test_a_shop_moved_up_to_a_plan_that_includes_the_addon_gets_no_renewal_invoice_for_it(): void
    {
        [$business] = $this->shop('basic');
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('pos'), 'monthly')['order']);
        $this->pay($order);
        $row = $this->entitlementOf($order);

        $business->update(['plan_id' => $this->plans['professional']->id]);

        try {
            AddonOrders::renew($row->fresh());
            $this->fail('صدرت فاتورةُ نقطة بيعٍ فوق باقةٍ تشملها');
        } catch (DomainException $e) {
            $this->assertStringContainsString('صارت مشمولةً في باقة المتجر', $e->getMessage());
        }
        $this->assertSame(1, Invoice::where('business_id', $business->id)->count());
        $this->assertTrue($this->allows($business, Catalog::POS));
    }
}

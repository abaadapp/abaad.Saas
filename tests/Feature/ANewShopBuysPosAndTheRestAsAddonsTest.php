<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\Branch;
use App\Models\BusinessAddon;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\CustomArrangement;
use App\Support\PlanLimits;
use App\Support\Store\GiftOrders;
use App\Support\WhatsAppFeature;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * متجرٌ جديدٌ على سياسة الإضافات — يشتري نقطةَ البيع وغيرَها، وتُفعَّل بسدادها.
 *
 * ═══ ما يُحرس ═══
 *
 *   3. الأساسيّةُ الجديدة بلا نقطة بيع — في الخادم لا في الزرّ.
 *   4. سدادُ الإضافة يفتح البيع.
 *   5. انقضاؤها يمنع البيع الجديد ويُبقي ما بيع وإيصالاتِه.
 *   6. الموقعُ وحده بلا نقطة بيع.
 *   7. الفرعُ الإضافيّ يرفع السقف، وانقضاؤه لا يحذف فرعًا.
 *   8. المشمولةُ لا تُشترى، والساريةُ لا تُشترى ثانية، والطلبُ المفتوحُ لا يتكرّر.
 *   11. الفواتيرُ والتجديدُ والإلغاءُ وإيقافُ التجديد.
 *   13. سعرُ الكتالوج يتغيّر ولا يمسّ طلبًا ولا دورةً ثُبّتت.
 *   14. «حسب الطلب» لا تسري بلا اعتمادٍ وسداد.
 *   17. فاتورةُ الإضافة لا تكتب في دفاتر التاجر ولا تمسّ Paymob.
 *   والهدايا والطلبات المخصّصة بإضافتها.
 */
class ANewShopBuysPosAndTheRestAsAddonsTest extends TestCase
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

    /** يطلب ويُعتمد ويُسدَّد — الطريقُ كلُّه */
    private function buy($business, string $code, string $cycle = 'monthly', int $qty = 1, ?float $price = null): BusinessAddon
    {
        $order = AddonOrders::request($business, $this->addon($code), $cycle, $qty)['order'];
        $order = AddonOrders::approve($order, $price);
        if ($order->status === AddonOrders::INVOICED) {
            $this->pay($order);
        }

        return BusinessAddon::findOrFail($order->fresh()->business_addon_id);
    }

    /** نقطةُ بيعٍ مجهَّزة وصنفٌ يُباع — والبيعةُ عبر الصندوق نفسِه */
    private function till($business): Product
    {
        Currency::create(['business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $this->activatePosDevice($business->id);

        return Product::create(['business_id' => $business->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true]);
    }

    private function sell(Product $product)
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('a', true),
        ]);
    }

    public function test_a_new_basic_shop_has_no_pos_on_the_server_until_it_pays_and_then_it_sells(): void
    {
        [$business, $owner] = $this->shop('basic');
        $this->assertSame(PricingPolicy::ADDONS, $business->pricing_policy);
        $product = $this->till($business);
        $this->actingAs($owner);

        // لا زرٌّ يُخفى فقط: الخادمُ يردّ البيعَ نفسَه، وفتحَ الصندوق، وتفعيلَ الجهاز
        $refused = $this->sell($product)->assertForbidden();
        $this->assertStringContainsString('إضافات متجرك', (string) $refused->json('message'));
        $this->getJson(route('pos.index'))->assertForbidden();
        $this->postJson(route('pos.setup.activate'), [])->assertForbidden();
        $this->postJson(route('pos.hold'), [])->assertForbidden();
        $this->assertSame(0, Order::where('business_id', $business->id)->count());
        $this->get(route('admin.dashboard'))->assertInertia(fn ($page) => $page->where('auth.planFeatures.pos', false));

        // طلبٌ بلا سداد لا يفتح شيئًا
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('pos'), 'monthly')['order']);
        $this->assertSame(AddonOrders::INVOICED, $order->status);
        $this->sell($product)->assertForbidden();

        $this->pay($order);
        $this->sell($product)->assertOk();
        $this->assertSame(1, Order::where('business_id', $business->id)->count());
        $this->get(route('admin.dashboard'))->assertInertia(fn ($page) => $page->where('auth.planFeatures.pos', true));
    }

    public function test_an_expired_pos_stops_new_sales_and_keeps_every_old_sale_and_its_receipt(): void
    {
        [$business, $owner] = $this->shop('basic');
        $product = $this->till($business);
        $row = $this->buy($business, 'pos');
        $this->assertSame('2026-12-02', $row->ends_at->toDateString());

        $this->actingAs($owner);
        $number = $this->sell($product)->assertOk()->json('order.number') ?? Order::where('business_id', $business->id)->value('number');

        // يومُ الانتهاء نفسُه مفتوح — كاشتراك الباقة
        Carbon::setTestNow('2026-12-02 22:00:00');
        $this->sell($product)->assertOk();

        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->sell($product)->assertForbidden();
        $this->assertFalse($this->allows($business, Catalog::POS));

        $this->assertSame(2, Order::where('business_id', $business->id)->count(), 'انقضاءُ الإضافة مسّ ما بيع');
        $this->assertNotSame(403, $this->getJson(route('pos.receipts.show', $number))->status(), 'أُغلق الإيصالُ القديم');
        $this->get(route('admin.orders.index'))->assertOk();
        $this->assertNull($row->fresh()->cancelled_at, 'الانقضاءُ كتب في الصفّ');
    }

    public function test_the_website_alone_works_without_pos(): void
    {
        [$business, $owner] = $this->shop('basic');
        $this->buy($business, 'website', 'yearly');

        $this->assertTrue($this->allows($business, 'website'));
        $this->assertFalse($this->allows($business, Catalog::POS));
        $this->assertFalse($this->allows($business, 'loyalty'));
        $this->actingAs($owner)->get(route('admin.website.index'))->assertSuccessful();
        $this->getJson(route('pos.index'))->assertForbidden();
    }

    public function test_an_extra_branch_raises_the_cap_without_making_a_branch_and_its_end_removes_none(): void
    {
        [$business] = $this->shop('professional');
        $cap = (int) $this->plans['professional']->max_branches;
        $this->assertSame($cap, PlanLimits::cap($business->fresh(), 'branches'));

        $this->buy($business, 'extra_branch', 'monthly', 2);
        $this->assertSame($cap + 2, PlanLimits::cap($business->fresh(), 'branches'));
        $this->assertSame(1, Branch::where('business_id', $business->id)->count(), 'الإضافةُ أنشأت فرعًا');

        foreach (range(2, $cap + 2) as $i) {
            Branch::create(['business_id' => $business->id, 'name' => 'فرع '.$i]);
        }
        $this->assertTrue(PlanLimits::reached($business->fresh(), 'branches'));

        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->assertSame($cap, PlanLimits::cap($business->fresh(), 'branches'));
        $this->assertSame($cap + 2, Branch::where('business_id', $business->id)->count(), 'الانقضاءُ حذف فرعًا');
        $this->assertTrue(PlanLimits::reached($business->fresh(), 'branches'), 'فوق السقف ولا يُمنع الجديد');
    }

    public function test_an_included_addon_is_never_bought_an_active_one_never_twice_and_an_open_request_never_repeats(): void
    {
        [$pro] = $this->shop('professional', 'pro@shop.om');
        $this->assertTrue($this->allows($pro, Catalog::POS), 'الاحترافيّةُ تشمل نقطةَ البيع');

        try {
            AddonOrders::request($pro, $this->addon('pos'), 'monthly');
            $this->fail('بيعت إضافةٌ مشمولة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('مشمولةٌ في باقتك', $e->getMessage());
        }

        [$basic] = $this->shop('basic', 'basic@shop.om');
        $first = AddonOrders::request($basic, $this->addon('loyalty'), 'monthly');
        $again = AddonOrders::request($basic, $this->addon('loyalty'), 'yearly');
        $this->assertTrue($first['created']);
        $this->assertFalse($again['created']);
        $this->assertSame($first['order']->id, $again['order']->id);
        $this->assertSame(1, AddonOrder::where('business_id', $basic->id)->count());

        $this->pay(AddonOrders::approve($first['order']));
        $this->expectException(DomainException::class);
        AddonOrders::request($basic, $this->addon('loyalty'), 'monthly');
    }

    public function test_a_basic_shop_cannot_buy_what_its_plan_does_not_sell(): void
    {
        [$basic] = $this->shop('basic');

        foreach (['extra_branch', 'gift_orders', 'whatsapp_own_number', 'api_integrations'] as $code) {
            try {
                AddonOrders::request($basic, $this->addon($code), 'monthly');
                $this->fail("بيعت «{$code}» في الأساسيّة");
            } catch (DomainException $e) {
                $this->assertStringContainsString('لا تُباع في باقتك', $e->getMessage());
            }
        }

        $this->addon('website')->update(['for_sale' => false]);
        $this->expectException(DomainException::class);
        AddonOrders::request($basic, $this->addon('website'), 'monthly');
    }

    public function test_invoices_renewals_cancelling_and_stopping_renewal(): void
    {
        [$business] = $this->shop('basic');

        // الإلغاءُ قبل السداد يُلغي الفاتورةَ معه ويحرّر الطلب
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('marketing'), 'monthly')['order']);
        $invoice = $order->invoice;
        AddonOrders::cancel($order);
        $this->assertSame(Billing::CANCELLED, $invoice->fresh()->status);
        $this->assertFalse($this->allows($business, 'marketing_tools'));
        // والفاتورةُ الملغاة لا تُسدَّد بعدها — ولا تفتح شيئًا
        try {
            Billing::markPaid($invoice->fresh());
            $this->fail('سُدّدت فاتورةٌ ملغاة');
        } catch (DomainException) {
        }
        $this->assertFalse($this->allows($business, 'marketing_tools'));

        $row = $this->buy($business, 'marketing');
        $this->assertSame(['2026-11-02', '2026-12-02', 2.9], [$row->starts_at->toDateString(), $row->ends_at->toDateString(), (float) $row->unit_price]);

        // التجديدُ المبكّر يبدأ من نهاية الدورة — لا يضيع يومٌ
        Carbon::setTestNow('2026-11-25 10:00:00');
        $renewal = AddonOrders::renew($row);
        $this->assertSame([AddonOrders::INVOICED, 2.9, Billing::KIND_ADDON], [$renewal->status, (float) $renewal->amount, $renewal->invoice->kind]);

        // وتجديدٌ معلّقٌ واحد
        try {
            AddonOrders::renew($row->fresh());
            $this->fail('صدرت فاتورتا تجديد');
        } catch (DomainException) {
        }

        $this->pay($renewal);
        $this->pay($renewal); // سدادٌ مكرَّر لا يمدّ مرّتين
        $this->assertSame('2027-01-02', $row->fresh()->ends_at->toDateString());

        // إيقافُ التجديد: سارية حتى نهايتها، ولا فاتورة تجديد
        AddonOrders::setRenews($row->fresh(), false);
        $this->assertTrue($this->allows($business, 'marketing_tools'));
        $this->expectExceptionMessage('أوقف التاجر');
        AddonOrders::renew($row->fresh());
    }

    public function test_a_catalog_price_change_never_reprices_a_request_an_invoice_or_an_agreed_cycle(): void
    {
        [$business] = $this->shop('basic');
        $pending = AddonOrders::request($business, $this->addon('whatsapp'), 'yearly')['order'];
        $row = $this->buy($business, 'loyalty', 'monthly');

        $this->addon('whatsapp')->update(['yearly_price' => 59]);
        $this->addon('loyalty')->update(['monthly_price' => 7.5]);

        $approved = AddonOrders::approve($pending);
        $this->assertSame(29.0, (float) $approved->invoice->amount, 'أُعيد تسعيرُ طلبٍ ثُبّت');
        $renewal = AddonOrders::renew($row);
        $this->assertSame(3.9, (float) $renewal->amount, 'جُدّدت دورةٌ بسعر الكتالوج الجديد');

        // وخصمٌ إداريٌّ يُكتب سببُه
        [$other] = $this->shop('basic', 'discount@shop.om');
        $order = AddonOrders::request($other, $this->addon('website'), 'monthly')['order'];
        try {
            AddonOrders::approve($order, 10.0);
            $this->fail('غُيّر سعرٌ مثبَّتٌ بلا سبب');
        } catch (DomainException) {
        }
        $this->assertSame(10.0, (float) AddonOrders::approve($order, 10.0, 'عرض افتتاح المتجر')->invoice->amount);
    }

    public function test_an_on_request_addon_needs_an_approved_price_and_payment_before_it_opens(): void
    {
        [$business] = $this->shop('professional');
        $this->assertTrue((bool) $business->whatsapp_own_allowed, 'العمودُ يُمنح بالولادة');

        /*
         * وخدمةُ واتساب تُشترى مرّةً: الاحترافيّةُ تشملها، فربطُ رقم المتجر مفتوحٌ
         * بها بلا شراء «ربط واتساب برقم المتجر» فوقها (`WhatsAppFeature::servicePurchased`).
         */
        $this->assertTrue(WhatsAppFeature::canUseOwnNumber($business), 'طُلب شراءُ واتساب مرّتين');

        // والإضافةُ «حسب الطلب» نفسُها لا تسري قبل سعرٍ معتمَدٍ وسداد
        $order = AddonOrders::request($business, $this->addon('whatsapp_own_number'), 'monthly')['order'];
        $this->assertNull($order->unit_price);
        $this->assertNull($order->invoice_id);
        $this->assertFalse(Entitlements::active($business->fresh(), Catalog::OWN_NUMBER));

        try {
            AddonOrders::approve($order);
            $this->fail('اعتُمد «حسب الطلب» بلا سعر');
        } catch (DomainException $e) {
            $this->assertStringContainsString('سعرًا معتمدًا', $e->getMessage());
        }

        $order = AddonOrders::approve($order, 15.0, 'عرض سعر رقم 7');
        $this->assertSame(15.0, (float) $order->invoice->amount);
        $this->assertFalse(Entitlements::active($business->fresh(), Catalog::OWN_NUMBER), 'سرت قبل السداد');

        $this->pay($order);
        $this->assertTrue(Entitlements::active($business->fresh(), Catalog::OWN_NUMBER));

        // والسحبُ يبقى سحبًا
        $business->update(['whatsapp_own_allowed' => false]);
        $this->assertFalse(WhatsAppFeature::canUseOwnNumber($business->fresh()));
    }

    /** وفريقُ أبعاد يختار له رقمَه على باقةٍ تشمل واتساب — بلا إضافةٍ فوقها */
    public function test_the_platform_opens_the_shops_own_number_without_selling_whatsapp_twice(): void
    {
        [$pro] = $this->shop('professional', 'pro@shop.om');
        $this->assertTrue($this->allows($pro, 'whatsapp'));
        $this->assertFalse(WhatsAppFeature::needsOwnNumberAddon($pro));

        // وصلتُه جاهزة: تُرسل، ورُوجع دليلُ فوترتها (`WhatsAppOwnApp::readyForOwnMode`) — فالإضافةُ وحدها السؤال
        \App\Models\WhatsAppConnection::create([
            'owner_type' => \App\Support\WhatsAppMode::OWNER_BUSINESS, 'business_id' => $pro->id, 'provider' => 'meta_cloud',
            'purpose' => \App\Support\WhatsAppMode::PURPOSE_NOTIFICATIONS, 'waba_id' => 'W-PRO', 'phone_number_id' => 'PN-PRO',
            'access_token' => 'pro-shop-token-0123456789', 'status' => \App\Models\WhatsAppConnection::ACTIVE,
            'billing_state' => \App\Support\WhatsAppOwnApp::BILLING_CONFIRMED,
        ]);

        $this->actingAs($this->platform)->put(route('super-admin.businesses.whatsapp.update', $pro->id), [
            'whatsapp_mode' => \App\Support\WhatsAppMode::BUSINESS_OWN,
        ])->assertSessionHasNoErrors();
        $this->assertSame(\App\Support\WhatsAppMode::BUSINESS_OWN, $pro->fresh()->whatsapp_mode);

        // وباقةٌ لا تشمله: الإضافةُ وحدها تفتحه
        [$basic] = $this->shop('basic', 'basic@shop.om');
        $this->assertFalse($this->allows($basic, 'whatsapp'));
        $this->assertTrue(WhatsAppFeature::needsOwnNumberAddon($basic));
    }

    public function test_gift_and_custom_orders_are_opened_by_their_addon_for_new_shops_only(): void
    {
        [$business] = $this->shop('professional');
        $this->assertFalse(GiftOrders::on($business->id));
        $this->assertFalse(CustomArrangement::enabled($business->id));

        $this->buy($business, 'gift_orders', 'yearly');
        $this->assertTrue(GiftOrders::on($business->id));
        $this->assertTrue(CustomArrangement::enabled($business->id));
    }

    public function test_an_addon_invoice_writes_nothing_in_the_shops_books_and_a_grant_has_no_invoice(): void
    {
        [$business] = $this->shop('basic');
        $entries = JournalEntry::count();
        $invoices = Invoice::count();

        $this->buy($business, 'pos', 'yearly');
        $this->assertSame($entries, JournalEntry::count(), 'فاتورةُ المنصّة كتبت في دفاتر التاجر');
        $this->assertSame($invoices + 1, Invoice::count());
        $this->assertSame(99.0, (float) Invoice::latest('id')->first()->amount);

        AddonOrders::grant($business, $this->addon('loyalty'), 1, now()->addYear()->toDateString(), 'هدية افتتاح', $this->platform);
        $this->assertSame($invoices + 1, Invoice::count(), 'المنحةُ أصدرت فاتورة');
        $this->assertTrue($this->allows($business, 'loyalty'));
        $this->assertSame($entries, JournalEntry::count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueInvoiceLine;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\StorePaymentIntent;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppOrderSession;
use App\Support\BoutiqueInvoiceDues;
use App\Support\Boutiques;
use App\Support\CustomArrangement;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use App\Support\WhatsAppOrderCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * كلُّ بابٍ يبيع صنفَ بوتيك — أيدخل حسابَه، ومتى، ومرّةً واحدة؟
 *
 * ═══ ما يثبته هذا الملفّ ═══
 *
 *   ١. الأبوابُ التي تكتب **طلبًا** (الصندوقُ نقدًا وآجلًا ومعلّقًا ودون اتصال،
 *      وتصحيحُ الفاتورة، والموقعُ بالدفع عند الاستلام وبالبطاقة) تكتب لقطةَ
 *      البوتيك على البند، فيدخل حسابَه مرّةً واحدة.
 *   ٢. ورقةُ الطلب ودفعتُه ورابطُه لا تُعيد عدَّه.
 *   ٣. وبابان يكتبان **فاتورةَ عميلٍ بلا طلب** — اليدويّةُ وطلبُ واتساب الآليّ —
 *      يحفظان اللقطةَ عند الإصدار، ويدخل صنفُ البوتيك حسابَه حين يكتمل تحصيلُها
 *      (`BoutiqueInvoiceDues`، وتفصيلُه في `ACustomerInvoiceOwesItsBoutiqueOnceItIsCollectedTest`).
 *   ٤. وقطعةُ البوتيك داخل منتجٍ من المتجر (وصفةٌ أو إضافةٌ أو طلبٌ مخصّص) لا
 *      تُستحقّ والمفتاحُ مطفأ (#146).
 */
class EveryDoorThatSellsABoutiquePieceTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private const PERIOD = '2027-02';

    private Boutique $lama;

    private Product $silver;

    private Customer $company;

    private int $tx = 41000;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->openRibbon('doors', 'doors@checkout.local');
        $this->shop->forceFill(['boutiques_enabled' => true, 'order_notes_and_edits_enabled' => true, 'customer_invoice_preparation_enabled' => true, 'customer_invoice_preparation_enabled_at' => now()->subDay()])->save();
        // حسابُ القطع داخل منتجات المتجر مطفأٌ — كما هو في كلّ متجر (#146)
        $this->assertFalse((bool) $this->shop->fresh()->boutique_components_enabled);
        // روابطُ الدفع تُفتح بمفتاح API حيّ — وباقي المفاتيح مزيَّفة
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::LIVE, 'omannet_integration_id' => '222']);
        PaymentGateway::where('business_id', $this->shop->id)->firstOrFail()->update(['api_key' => 'api-doors']);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->silver = Product::create([
            'business_id' => $this->shop->id, 'name' => 'قطعة فضّة', 'price' => 12, 'cost' => 1, 'quantity' => 100,
            'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $this->lama->id,
        ]);
        $this->company = Customer::create([
            'language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة الورد', 'phone' => '92222222',
            'customer_type' => 'شركة', 'allow_credit_sales' => true, 'payment_terms_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ما للبوتيك — من القارئين اللذين يقرؤهما النظام ═══════════ */

    private function assertOwed(float $gross, float $commission, float $net, string $why = ''): void
    {
        $r = Boutiques::soldTotals((int) $this->shop->id, $this->lama);
        $this->assertEquals([$gross, $commission, $net], [$r['gross'], $r['commission'], $r['net']], $why.' — تقريرُ المبيعات');

        // والكشفُ الذي تُصدر منه الورقة يقول الشيء نفسَه — لا قارئَ يعدّ ما لا يعدّه الآخر
        $s = Boutiques::statement((int) $this->shop->id, (int) $this->lama->id, self::PERIOD);
        $this->assertEquals([$gross, $commission, $net], [$s['gross'], $s['commission'], $s['net']], $why.' — الكشف');
        $this->assertEquals(0, $s['components']['count'], 'استُحقّت قطعةٌ والمفتاحُ مطفأ');
    }

    /** صدرت ولم يكتمل تحصيلُها: في التقرير بيعٌ، وفي الكشف «بانتظار التحصيل» لا مستحقّ */
    private function assertAwaits(float $net, string $why = '', float $gross = 12.0, float $commission = 2.4): void
    {
        $s = Boutiques::statement((int) $this->shop->id, (int) $this->lama->id, self::PERIOD);
        $this->assertEquals([0.0, 0.0, 0.0, $net], [$s['gross'], $s['commission'], $s['net'], $s['awaiting']['net']], $why.' — الكشف');
        $this->assertEquals([$net, $net], [($r = Boutiques::soldTotals((int) $this->shop->id, $this->lama))['net'], $r['awaiting_net']], $why.' — التقرير');
    }

    private function sell(array $extra = [], int $qty = 1): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', $extra + [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => $qty, 'price' => 12]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('door', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->where('is_held', false)->orderByDesc('id')->firstOrFail();
    }

    private function fakeLinks(): void
    {
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'amount_cents' => 12000, 'shorten_url' => 'https://paymob.link/abc123',
                'created_at' => '2027-02-10T10:00:00', 'expires_at' => '2027-02-18T23:59:00', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);
    }

    /** إشعارُ Paymob «دُفع الرابط» — موقَّعًا */
    private function payLink(CustomerPaymentLink $link): void
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2027-02-10T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status, 'الإشعارُ لم يُغلق الرابط — الاختبارُ لا يقيس شيئًا');
    }

    /* ═══════════ ١ · الصندوق ═══════════ */

    public function test_a_till_sale_paid_in_cash_is_in_the_account_once(): void
    {
        $this->sell(qty: 2);
        $this->assertOwed(24.0, 4.8, 19.2);
        $this->assertSame(98, (int) $this->silver->fresh()->quantity);
    }

    /** آجلٌ من الصندوق: البيعُ وقع فيدخل الحساب، وورقتُه فوقه لا تُعيد عدَّه، ولا سدادُها */
    public function test_a_till_credit_sale_is_counted_once_by_its_order_and_never_by_its_invoice_or_its_payment(): void
    {
        $order = $this->sell(['credit' => true, 'customer_id' => $this->company->id]);
        $invoice = CustomerInvoice::where('business_id', $this->shop->id)->sole();
        $this->assertTrue($invoice->coversOrders());
        $this->assertSame([(int) $order->items->first()->id], $invoice->items->pluck('order_item_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all());
        $this->assertOwed(12.0, 2.4, 9.6, 'الآجلُ بيعٌ قائم');

        // دُفع نصفُها ثمّ باقيها — تحصيلٌ لا بيع
        foreach ([5.0, 7.0] as $amount) {
            CustomerPayments::record((int) $this->shop->id, $this->company, $amount, ['method' => 'نقدي'], [$invoice->id => $amount], $this->owner->id);
            $this->assertOwed(12.0, 2.4, 9.6, 'التحصيلُ أعاد عدَّ البيع');
        }
        $this->assertEquals(0.0, $invoice->fresh()->outstanding());
    }

    /** ورقةُ الشهر تجمع طلباتٍ آجلة — ولا تُعيد عدَّ بند */
    public function test_a_monthly_invoice_over_credit_sales_counts_nothing_twice(): void
    {
        $orders = [];
        foreach (range(1, 2) as $_) {
            $orders[] = $this->sell(['credit' => true, 'customer_id' => $this->company->id])->id;
        }
        // ورقتا البيع تُلغيان لتُجمعا في ورقةٍ واحدة — الطلباتُ باقية
        foreach (CustomerInvoice::where('business_id', $this->shop->id)->get() as $i) {
            CustomerInvoices::cancel($i, 'تُجمع في ورقة الشهر', $this->owner->id);
        }
        $this->assertOwed(24.0, 4.8, 19.2);

        $month = CustomerInvoices::consolidate($this->company, $orders, [], $this->owner->id);
        $this->assertSame(CustomerInvoice::ISSUED, $month->status);
        $this->assertOwed(24.0, 4.8, 19.2, 'ورقةُ الشهر أعادت عدَّ الطلبات');
    }

    public function test_a_held_sale_is_no_sale_until_it_is_paid_and_then_once(): void
    {
        $item = ['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 1];
        $this->actingAs($this->owner)->postJson(route('pos.hold'), ['items' => [$item], 'kind' => 'hold'])->assertOk();
        $held = Order::where('business_id', $this->shop->id)->where('is_held', true)->sole();
        $this->assertOwed(0.0, 0.0, 0.0, 'المعلّقُ ليس بيعًا');

        // والنسبةُ تُقرأ ساعةَ الدفع لا ساعةَ التعليق
        $this->lama->update(['commission_rate' => 25]);
        $this->actingAs($this->owner)->postJson('/pos/checkout', ['items' => [$item], 'payment_method' => 'نقدي', 'resume_id' => $held->id, 'client_uuid' => 'held-door'])->assertOk();
        $this->assertOwed(12.0, 3.0, 9.0);
    }

    /** دون اتصال ثمّ يُعاد إرسالُها — بيعةٌ واحدة */
    public function test_an_offline_sale_sent_again_and_again_is_one_sale(): void
    {
        $body = [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 1, 'price' => 12]],
            'payment_method' => 'نقدي', 'client_uuid' => 'offline-door', 'replay' => true,
        ];
        foreach (range(1, 4) as $_) {
            $this->actingAs($this->owner)->postJson('/pos/checkout', $body)->assertOk();
        }
        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertOwed(12.0, 2.4, 9.6);
    }

    /** صنفُ بوتيكٍ يُضاف إلى فاتورةٍ صدرت، ثمّ تُنقص كمّيّتُه، ثمّ تُلغى */
    public function test_a_correction_adds_lowers_and_cancels_what_the_boutique_is_owed(): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->rose->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 20]],
            'payment_method' => 'نقدي', 'client_uuid' => 'corr-door',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->sole();
        $this->assertOwed(0.0, 0.0, 0.0, 'فاتورةٌ بلا بوتيك');

        $this->actingAs($this->owner);
        OrderCorrection::addLine($order->fresh(), ['product_id' => $this->silver->id, 'qty' => 3], 'نسيها', OrderCorrection::SETTLE_COLLECTED, 'نقدي');
        $this->assertOwed(36.0, 7.2, 28.8);

        $item = OrderItem::where('order_id', $order->id)->where('boutique_id', $this->lama->id)->sole();
        OrderCorrection::setQuantity($order->fresh(), $item, 1, 'رُدّت قطعتان', OrderCorrection::SETTLE_REFUNDED, 'نقدي');
        $this->assertOwed(12.0, 2.4, 9.6);

        OrderCorrection::cancel($order->fresh(), 'أُلغيت', boutiqueReturned: true);
        $this->assertOwed(0.0, 0.0, 0.0, 'الملغاةُ بقيت في الحساب');
    }

    /* ═══════════ ٢ · الموقع ═══════════ */

    public function test_a_website_order_is_in_the_account_once(): void
    {
        $this->checkout(['items' => [['id' => $this->silver->id, 'qty' => 2]]])->assertOk();
        $this->assertSame('website', Order::where('business_id', $this->shop->id)->sole()->channel);
        $this->assertOwed(24.0, 4.8, 19.2);
    }

    /** بطاقةٌ عند البنك ليست بيعًا حتّى تُدفع — وإشعارٌ يُعاد لا يُعيد البيع */
    public function test_a_card_payment_is_no_sale_while_pending_and_one_sale_when_paid(): void
    {
        $this->bankOpens();
        $this->checkout(['pay' => 'card', 'items' => [['id' => $this->silver->id, 'qty' => 1]]])->assertOk();
        $intent = StorePaymentIntent::latest('id')->firstOrFail();
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
        $this->assertOwed(0.0, 0.0, 0.0, 'طلبٌ ينتظر البنك ليس بيعًا');

        $this->paid($intent);
        $this->paid($intent);
        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertOwed(12.0, 2.4, 9.6);
    }

    /* ═══════════ الخصمُ من أيّ بابٍ جاء ═══════════ */

    /**
     * سياسةٌ واحدة في كلّ باب: خصمُ المنتج نفسِه يُنقص سعرَ بيعه فيقع على البوتيك
     * بحصّته، وخصمُ الفاتورة (كوبونٌ ونقاط) على المتجر ولا يمسّ البوتيك.
     */
    public function test_a_coupon_never_lowers_the_boutiques_due_whichever_door_sold_it(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'TEN', 'type' => 'نسبة', 'value' => 10, 'active' => true]);
        $till = $this->sell(['coupon_code' => 'TEN']);
        $this->assertEquals([1.2, 10.8], [(float) $till->discount, (float) $till->total]);
        $this->assertOwed(12.0, 2.4, 9.6, 'كوبونُ الصندوق');

        $this->checkout(['items' => [['id' => $this->silver->id, 'qty' => 1]], 'promo' => 'TEN'])->assertOk();
        $web = Order::where('business_id', $this->shop->id)->where('channel', 'website')->sole();
        $this->assertEquals([1.2, 10.8], [(float) $web->discount, (float) $web->total], 'كوبونُ الموقع لم يُطبَّق — الاختبارُ لا يقيس شيئًا');
        $this->assertOwed(24.0, 4.8, 19.2, 'كوبونُ الموقع');

        // وتصحيحُ فاتورةٍ مخصومةٍ يعيد حساب الخصم ولا يحمّل البوتيكَ منه شيئًا
        $this->actingAs($this->owner);
        OrderCorrection::addLine($till->fresh(), ['product_id' => $this->silver->id, 'qty' => 1], 'زيادة', OrderCorrection::SETTLE_COLLECTED, 'نقدي');
        $this->assertOwed(36.0, 7.2, 28.8, 'تصحيحُ فاتورةٍ عليها كوبون');
    }

    public function test_a_products_own_discount_lowers_the_price_the_boutique_is_owed_from_in_both_doors(): void
    {
        $this->silver->update(['discount' => 25]);          // ١٢ ← ٩
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 1, 'price' => 9]],
            'payment_method' => 'نقدي', 'client_uuid' => 'own-discount',
        ])->assertOk();
        $this->assertOwed(9.0, 1.8, 7.2, 'خصمُ المنتج في الصندوق');
        $this->checkout(['items' => [['id' => $this->silver->id, 'qty' => 1]]])->assertOk();
        $this->assertOwed(18.0, 3.6, 14.4, 'خصمُ المنتج في الموقع');
        $this->assertSame(0, (int) round((float) Order::where('business_id', $this->shop->id)->sum('discount') * 1000), 'خصمُ المنتج ليس خصمَ فاتورة — لا يُخصم مرّتين');
    }

    /** إضافةُ المتجر المدفوعةُ على صنف البوتيك للمتجر كلِّها — لا تدخل مبيعاتِ البوتيك */
    public function test_a_shops_addon_on_a_boutique_piece_is_the_shops(): void
    {
        $wrap = Addon::create(['business_id' => $this->shop->id, 'name' => 'تغليف', 'price' => 3, 'active' => true, 'pos_enabled' => true, 'scope' => Addon::SCOPE_ALL]);
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 1, 'price' => 12, 'addons' => [['addon_id' => $wrap->id, 'qty' => 1]]]],
            'payment_method' => 'نقدي', 'client_uuid' => 'addon-door',
        ])->assertOk();
        $this->assertEquals(15.0, (float) Order::where('business_id', $this->shop->id)->sole()->total);
        $this->assertOwed(12.0, 2.4, 9.6, 'إضافةُ المتجر دخلت حسابَ البوتيك');
    }

    /* ═══════════ ٣ · رابطُ الدفع — تحصيلٌ لا بيع ═══════════ */

    /** رابطٌ على ورقةِ طلبٍ آجل: البيعُ في الطلب، والرابطُ يحصّله وحده */
    public function test_a_payment_link_over_an_orders_invoice_counts_nothing_again(): void
    {
        $this->fakeLinks();
        $this->sell(['credit' => true, 'customer_id' => $this->company->id]);
        $invoice = CustomerInvoice::where('business_id', $this->shop->id)->sole();
        [$link] = PaymentLinks::forInvoice($invoice, $this->owner->id);
        $this->assertOwed(12.0, 2.4, 9.6, 'رابطٌ أُنشئ ولم يُدفع');

        $this->payLink($link);
        $this->assertEquals(0.0, $invoice->fresh()->outstanding());
        $this->assertOwed(12.0, 2.4, 9.6, 'الرابطُ المدفوع أعاد عدَّ البيع');
    }

    /** رابطٌ مستقلٌّ بلا فاتورة: لا بنودَ فيه، فلا بوتيكَ له نصيب */
    public function test_a_link_without_an_invoice_sells_nothing(): void
    {
        $this->fakeLinks();
        $link = PaymentLinks::create((int) $this->shop->id, $this->company, null, 12.0, 'قطعة فضّة', $this->owner->id);
        $this->payLink($link);
        $this->assertOwed(0.0, 0.0, 0.0);
    }

    /* ═══════════ ٤ · فاتورةُ العميل بلا طلب ═══════════ */

    private function invoiceFor(int $qty = 1): CustomerInvoice
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->company->id, 'issued_at' => now()->toDateString(),
            'items' => [['product_id' => $this->silver->id, 'description' => $this->silver->name, 'quantity' => $qty, 'unit_price' => 12]],
            'payment_method' => 'آجل',
        ])->assertRedirect();

        return CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    /** اللقطةُ في جدولها — وبندُ الفاتورة وسجلُّ القطع كما كانا */
    public function test_the_invoice_line_keeps_whose_piece_it_was_in_its_own_row(): void
    {
        foreach (['boutique_id', 'boutique_name', 'rate', 'commission', 'net', 'collected_at', 'boutique_settlement_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('boutique_invoice_lines', $column), $column);
        }
        $this->assertFalse(Schema::hasColumn('customer_invoice_items', 'boutique_id'));
        $this->assertFalse(collect(Schema::getColumns('boutique_component_dues'))->firstWhere('name', 'order_id')['nullable']);
    }

    public function test_a_manual_invoice_enters_the_account_once_when_it_is_fully_collected(): void
    {
        $invoice = $this->invoiceFor();
        $this->assertSame(CustomerInvoice::DRAFT, $invoice->status);
        $this->assertOwed(0.0, 0.0, 0.0, 'المسودّةُ ليست بيعًا');

        $issued = CustomerInvoices::issue($invoice, $this->owner->id);
        $this->assertFalse($issued->coversOrders());
        $this->assertEquals(12.0, Ledger::balance((int) $this->shop->id, 'sales'));
        $this->assertAwaits(9.6, 'الصادرة');
        $this->assertSame(100, (int) $this->silver->fresh()->quantity, 'فاتورةُ العميل لا تُخرج من الرفّ');

        CustomerPayments::record((int) $this->shop->id, $this->company, 5.0, ['method' => 'نقدي'], [$issued->id => 5.0], $this->owner->id);
        $this->assertAwaits(9.6, 'المدفوعُ منها 5');
        CustomerPayments::record((int) $this->shop->id, $this->company, 7.0, ['method' => 'نقدي'], [$issued->id => 7.0], $this->owner->id);
        $this->assertOwed(12.0, 2.4, 9.6, 'اكتملت');
        $this->assertSame(0, OrderItem::count(), 'كُتب طلبٌ وهميّ');
        $this->assertSame(0, BoutiqueComponentDue::count());
    }

    /** ورابطُ دفعها يكمل تحصيلَها — وإشعارُه المكرّر لا يزيد */
    public function test_a_paid_link_over_a_manual_invoice_enters_the_account_once(): void
    {
        $this->fakeLinks();
        $invoice = CustomerInvoices::issue($this->invoiceFor(), $this->owner->id);
        [$link] = PaymentLinks::forInvoice($invoice, $this->owner->id);
        $this->assertAwaits(9.6, 'رابطٌ لم يُدفع');
        $this->payLink($link);
        $this->assertEquals(0.0, $invoice->fresh()->outstanding());
        $this->assertOwed(12.0, 2.4, 9.6);
        $this->tx--;
        $this->payLink($link);
        $this->assertOwed(12.0, 2.4, 9.6, 'إشعارٌ مكرّر');
    }

    /** وطلبُ واتساب الآليّ يكتب فاتورةَ عميلٍ — فهو من هذا الباب */
    public function test_an_automatic_whatsapp_order_enters_the_account_when_it_is_paid(): void
    {
        $chat = WhatsAppChat::create(['business_id' => $this->shop->id, 'phone_number_id' => 'PN-1', 'source_mode' => 'own', 'customer_phone' => '96891234567']);
        $session = WhatsAppOrderSession::create([
            'business_id' => $this->shop->id, 'chat_id' => $chat->id, 'customer_phone' => '96891234567', 'language' => 'ar',
            'state' => WhatsAppOrderSession::CONFIRMING, 'total' => 12, 'customer_id' => $this->company->id,
            'cart' => [['product_id' => $this->silver->id, 'variant_id' => null, 'qty' => 1, 'addons' => []]],
            'details' => ['fulfil' => 'pickup', 'name' => 'مريم', 'recipient_self' => true, 'date' => '2027-02-12', 'slot' => '0'],
        ]);
        $invoice = WhatsAppOrderCheckout::invoice($this->shop->fresh(), $session);

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status, 'الطلبُ الآليّ يُصدر ورقتَه قبل الدفع');
        $this->assertGreaterThan(0.0, $invoice->outstanding(), 'ولم يُدفع بعد');
        $this->assertAwaits(9.6, 'طلبٌ لم يُدفع');
        // والطلبُ نفسُه مرّةً أخرى ورقةٌ واحدة ولقطةٌ واحدة
        WhatsAppOrderCheckout::invoice($this->shop->fresh(), $session->fresh());
        $this->assertSame(1, BoutiqueInvoiceLine::count());

        CustomerPayments::record((int) $this->shop->id, $this->company, 12.0, ['method' => 'نقدي'], [$invoice->id => 12.0], $this->owner->id);
        $this->assertOwed(12.0, 2.4, 9.6, 'دُفع');
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count(), 'كُتب طلبٌ مع الفاتورة — صار العدُّ مرّتين ممكنًا');
    }

    /** إشعارٌ بمبلغٍ وحده لا يمسّ بوتيكًا، وإشعارٌ سمّى القطعةَ يُنقص ما لصاحبها */
    public function test_a_credit_note_lowers_the_boutique_only_by_the_piece_it_names(): void
    {
        $invoice = CustomerInvoices::issue($this->invoiceFor(2), $this->owner->id);
        CustomerInvoices::creditNote($invoice, 2.0, 0.0, 'خصم', $this->owner->id, basis: 'discount');
        $this->assertAwaits(19.2, 'خصمٌ على المتجر', 24.0, 4.8);
        $items = BoutiqueInvoiceDues::noteItems($invoice, [(int) $invoice->items->first()->id => ['quantity' => 1]]);
        CustomerInvoices::creditNote($invoice->fresh(), 12.0, 0.0, 'رُدّت قطعة', $this->owner->id, items: $items, basis: CustomerCreditNote::RETURN);
        $this->assertAwaits(9.6, 'عادت واحدة');
    }

    /* ═══════════ ٥ · قطعةٌ داخل منتج المتجر — والمفتاحُ مطفأ (#146) ═══════════ */

    public function test_a_piece_inside_the_shops_own_products_owes_nothing_while_the_switch_is_off(): void
    {
        $this->rose->recipeItems()->create(['business_id' => $this->shop->id, 'component_product_id' => $this->silver->id, 'quantity' => 1]);
        $addon = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'فضّة اختياريّة', 'price' => 12, 'active' => true, 'pos_enabled' => true,
            'scope' => Addon::SCOPE_ALL, 'inventory_product_id' => $this->silver->id, 'inventory_quantity' => 1,
        ]);
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->rose->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 20, 'addons' => [['addon_id' => $addon->id, 'qty' => 1]]]],
            'payment_method' => 'نقدي', 'client_uuid' => 'pieces-door',
        ])->assertOk();

        $this->assertSame(0, BoutiqueComponentDue::count());
        $this->assertOwed(0.0, 0.0, 0.0, 'استُحقّت قطعةٌ والمفتاحُ مطفأ');
    }

    /** والطلبُ المخصّص كذلك: يأخذ قطعةَ البوتيك مادّةً ولا يُستحقّ عنها — والمفتاحُ المفتوح يردّه */
    public function test_a_custom_order_takes_a_boutique_piece_and_owes_nothing_while_the_switch_is_off(): void
    {
        CustomOrderTemplate::ensureDefault((int) $this->shop->id);
        $body = [
            'items' => [['name' => 'تنسيق مخصّص', 'qty' => 1, 'custom' => [
                'mode' => CustomArrangement::MODE_VALUE, 'price' => 30, 'flower_value' => 30,
                'components' => [['product_id' => $this->silver->id, 'quantity' => 1]],
            ]]],
            'payment_method' => 'نقدي', 'client_uuid' => 'custom-door',
        ];
        $this->actingAs($this->owner)->postJson('/pos/checkout', $body)->assertOk();
        $this->assertSame(99, (int) $this->silver->fresh()->quantity, 'خرجت من الرفّ');
        $this->assertSame(0, BoutiqueComponentDue::count());
        $this->assertOwed(0.0, 0.0, 0.0);

        // ومتى فُتح المفتاحُ ردّه النظام ولم يبعه بلا مستحقّ
        $this->shop->forceFill(['boutique_components_enabled' => true])->save();
        $this->actingAs($this->owner)->postJson('/pos/checkout', ['client_uuid' => 'custom-door-2'] + $body)->assertStatus(422);
    }

    /* ═══════════ ٦ · حارسُ الأبواب ═══════════ */

    /**
     * كلُّ ملفٍّ يكتب بندَ بيعٍ مذكورٌ هنا بحاله — فبابٌ جديد لا يُفتح بلا جوابٍ عن البوتيك.
     *
     * من كتب بندَ طلبٍ وجب أن يكتب صاحبَه (`Boutiques::itemColumns`). ومن كتب بندَ فاتورةِ
     * عميلٍ حُفظت لقطتُه عند الإصدار (`BoutiqueInvoiceDues::snapshot`).
     */
    public function test_no_new_door_sells_without_answering_for_the_boutique(): void
    {
        $orders = [];
        $invoices = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $code = $file->getContents();
            $path = str_replace('\\', '/', $file->getRelativePathname());
            if (preg_match('/\$order->items\(\)->create(Many)?\(|OrderItem::(query\(\)->)?(create|insert|forceCreate)\(|table\(\'order_items\'\)->insert/', $code)) {
                $orders[$path] = str_contains($code, 'Boutiques::itemColumns(');
            }
            if (preg_match('/CustomerInvoiceItem::(query\(\)->)?(create|insert|forceCreate)\(|\$invoice->items\(\)->create(Many)?\(|table\(\'customer_invoice_items\'\)->insert/', $code)) {
                $invoices[] = $path;
            }
        }
        ksort($orders);
        sort($invoices);

        // بياناتُ العرض والبذر ليست أبوابَ بيع: لا بوتيكَ في أصنافها
        $seed = ['Console/Commands/SeedSalesHistory.php', 'Support/DemoStore.php'];
        $this->assertSame([
            'Console/Commands/SeedSalesHistory.php' => false,
            'Http/Controllers/Pos/PosController.php' => true,
            'Support/DemoStore.php' => false,
            'Support/OrderCorrection.php' => true,
            'Support/Store/WebCheckout.php' => true,
        ], $orders, 'بابٌ جديد يكتب بندَ طلب — أيكتب صاحبَه ونسبتَه؟');
        $this->assertSame($seed, array_keys(array_filter($orders, fn ($writes) => ! $writes)));
        $this->assertSame(['Support/CustomerInvoices.php'], $invoices, 'بابٌ جديد يكتب بندَ فاتورة عميل');

        // وطلبُ واتساب الآليّ يمرّ من الفاتورة لا من طلب
        $this->assertStringContainsString('CustomerInvoices::create(', (string) file_get_contents(app_path('Support/WhatsAppOrderCheckout.php')));
    }
}

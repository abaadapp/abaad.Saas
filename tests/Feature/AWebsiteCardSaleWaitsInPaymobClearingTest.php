<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Bank;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\PaymobSettlements;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * بيعةُ الموقع بالبطاقة تنتظر في «Paymob تحت التسوية» — ولا تدخل البنك مرّتين.
 *
 * كانت تُدين البنكَ بإجماليّها يومَ البيع. وPaymob تحوّل بعد أيّامٍ صافيًا
 * بعد رسومها، فيُسجّل التاجرُ تلك الدفعة تسويةً (`PaymobSettlements::record`)
 * تُدين البنكَ بالصافي من حساب التسوية: المالُ نفسُه في البنك مرّتين،
 * وحسابُ التسوية يُستنزف من تحصيلات روابط لم تُسوَّ، والرسومُ لا تُقيَّد.
 * وجدها تدقيقُ ماليّة RIBBON (2026-10-24) في الكود؛ ولا بيعَ بطاقةٍ حيًّا في
 * موقعه بعد، فلا أثرَ في بياناته.
 */
class AWebsiteCardSaleWaitsInPaymobClearingTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);

        $this->shop = Business::create([
            'name' => 'متجر الورد', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000001',
            'city' => 'مسقط', 'site_slug' => 'wardshop', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@ward.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 0, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876', 'active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 0, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** زبونٌ يدفع ٢٠ بالبطاقة في الموقع، ويصل إشعارُ Paymob الموقَّع */
    private function paidWebsiteOrder(): Order
    {
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);
        $this->postJson('/s/wardshop/checkout', [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'name' => 'زبون', 'phone' => '95000001', 'fulfil' => 'pickup', 'date' => '2027-02-12', 'pay' => 'card',
        ])->assertOk();

        $intent = StorePaymentIntent::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $obj = [
            'amount_cents' => 20000, 'created_at' => '2027-02-10T10:00:10.100000', 'currency' => 'OMR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 990000 + $intent->id,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 770000 + $intent->id, 'merchant_order_id' => $intent->reference], 'owner' => 4705,
            'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function bank(): float
    {
        return round(Bank::leaf($this->shop->id)->balance(), 3);
    }

    private function clearing(): float
    {
        return Ledger::balance($this->shop->id, 'paymob_clearing');
    }

    public function test_a_website_card_sale_waits_in_paymob_clearing_not_in_the_bank(): void
    {
        $order = $this->paidWebsiteOrder();

        $this->assertSame(['website', 'بطاقة', 'مدفوع'], [$order->channel, $order->payment_method, $order->payment_status]);
        $this->assertSame(20.0, $this->clearing(), 'المالُ عند Paymob حتّى تحوّله');
        $this->assertSame(0.0, $this->bank(), 'دخل البنكَ مالٌ لم يحوَّل بعد');
        $this->assertSame(20.0, Ledger::netRevenue($this->shop->id, Carbon::parse('2027-02-01')), 'والإيرادُ يومَ البيع كما كان');
    }

    public function test_the_settlement_reaches_the_bank_at_its_net_and_books_the_fee_once(): void
    {
        $this->paidWebsiteOrder();

        PaymobSettlements::record($this->shop->id, [
            'gross' => 20, 'fee' => 0.6, 'net' => 19.4, 'settled_on' => '2027-02-13',
            'bank_account_id' => Bank::current($this->shop->id)->id,
        ], $this->owner->id);

        $this->assertSame(0.0, $this->clearing());
        $this->assertSame(19.4, $this->bank(), 'دخل البنكَ الإجماليُّ والصافي معًا');
        $this->assertSame(0.6, Ledger::balance($this->shop->id, 'payment_gateway_fees'));
        $this->assertSame(20.0, Ledger::netRevenue($this->shop->id, Carbon::parse('2027-02-01')), 'التسويةُ ليست إيرادًا');

        // وكشفُ البنك يقرأ ما وصل البنكَ وحده: سطرٌ واحدٌ بالصافي
        $statement = Demo::bankStatement();
        $this->assertSame([19.4], array_column($statement['rows'], 'credit'));
        $this->assertSame(19.4, $statement['closing']);
    }

    public function test_a_payout_larger_than_what_waits_is_refused(): void
    {
        $this->paidWebsiteOrder();

        try {
            PaymobSettlements::record($this->shop->id, ['gross' => 40, 'fee' => 1, 'net' => 39, 'settled_on' => '2027-02-13'], $this->owner->id);
            $this->fail('قُبلت تسويةٌ بأكثر ممّا ينتظر عند Paymob');
        } catch (RuntimeException) {
        }

        $this->assertSame([20.0, 0.0], [$this->clearing(), $this->bank()]);
    }

    public function test_cancelling_the_website_sale_takes_it_out_of_clearing(): void
    {
        $order = $this->paidWebsiteOrder();

        OrderCorrection::cancel($order, 'اختبار');

        $this->assertSame([0.0, 0.0], [$this->clearing(), $this->bank()]);
    }

    /**
     * وبيعةٌ رُحّلت إلى البنك قبل هذا ثمّ أُعيد ترحيلُها بعد تصحيح تعود إلى
     * البنك — التصحيحُ لا ينقل مالًا قديمًا إلى التسوية بصمت.
     */
    public function test_a_reposted_old_sale_stays_where_it_was_first_posted(): void
    {
        $order = $this->paidWebsiteOrder();
        $leaf = Bank::leaf($this->shop->id);
        $clearing = Ledger::account($this->shop->id, 'paymob_clearing');

        // ترحيلُ ما قبل الإصلاح: مدينُ البيعة البنكُ لا التسوية
        $entry = JournalEntry::where('business_id', $this->shop->id)->where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->where('source', Books::SALE)->firstOrFail();
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('account_id', $clearing->id)
            ->update(['account_id' => $leaf->id]);
        $this->assertSame([0.0, 20.0], [$this->clearing(), $this->bank()], 'تهيئة: بيعةٌ قديمة في البنك');

        Books::unpostSale($order);
        Books::recordSale($order->fresh(['items.addons']));

        $this->assertSame([0.0, 20.0], [$this->clearing(), $this->bank()], 'نقل التصحيحُ مالًا قديمًا من البنك إلى التسوية');
    }

    public function test_a_card_sale_at_the_till_still_enters_the_bank(): void
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->rose->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 20]],
            'payment_method' => 'بطاقة',
        ])->assertOk();

        $this->assertSame([0.0, 20.0], [$this->clearing(), $this->bank()]);
    }

    /** وتسويةُ متجرٍ لا تمسّ حسابَ تسويةِ جاره */
    public function test_a_neighbours_settlement_cannot_drain_this_shops_clearing(): void
    {
        $this->paidWebsiteOrder();

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);

        try {
            PaymobSettlements::record($other->id, ['gross' => 20, 'fee' => 0.6, 'net' => 19.4, 'settled_on' => '2027-02-13'], null);
            $this->fail('سُوّي عند الجار مالٌ ليس في تسويته');
        } catch (RuntimeException) {
        }

        $this->assertSame(20.0, $this->clearing());
        $this->assertSame(0.0, Ledger::balance($other->id, 'paymob_clearing'));
        $this->assertSame(0, BankAccount::where('business_id', $other->id)->count());
    }
}

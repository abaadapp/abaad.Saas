<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\SubscriptionAddon;
use App\Models\User;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Entitlements;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\PaymobSettlements;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PaymobPayouts;
use Tests\Support\SelfSignupOpens;
use Tests\TestCase;

/**
 * تاجرٌ يسجّل اليومَ بنفسه يُسوّي Paymob من أوّل بيعة — بلا إعدادٍ يدويٍّ خاصٍّ به.
 *
 * المسارُ كلُّه كما يمشيه التاجر: يسجّل (`register.store`)، ويفتح موقعَه ويربط
 * Paymob بمفاتيح اختبار ويضيف حسابَه البنكيّ، ثمّ يدفع زبونٌ بالبطاقة ويصل
 * الإشعارُ مرّتين، ثمّ يُسجَّل تحويلُ Paymob برسومه، ثمّ يُصحَّح طلبٌ ويُردّ فرقُه
 * بالبطاقة، ويُدفع رابط. وفي كلّ خطوة: الشجرةُ فيها ما يُرحَّل إليه، والمالُ في
 * مكانه مرّةً واحدة، والقيودُ متوازنة، ولا شيءَ خارج متجره.
 */
class ANewShopSettlesPaymobFromItsFirstSaleTest extends TestCase
{
    use PaymobPayouts;
    use RefreshDatabase;
    use SelfSignupOpens;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-01 10:00:00');
        app()->setLocale('ar');
        RateLimiter::clear('signup:127.0.0.1');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        Plan::create(['name' => 'الباقة الأساسية', 'code' => Plan::CODE_BASIC, 'monthly_price' => 9.9, 'yearly_price' => 99]);
        // والإطلاقُ مفتوحٌ بشرطه — وإلّا يُردّ التسجيلُ الذاتيّ (`Signup::ready`)
        $this->openSelfSignup();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** يسجّل بنفسه، ثمّ يفتح موقعَه ويربط Paymob (مفاتيح اختبار) ويضيف بنكَه — كما في شاشات الإعداد */
    private function aMerchantSignsUpAndOpensHisShop(): void
    {
        $this->post(route('register.store'), [
            'name' => 'تاجر جديد', 'phone' => '+96891112222', 'type' => 'محل ورود', 'shop' => 'New Flowers',
            'team_size' => '2–5 موظفين', 'address' => 'مسقط', 'email' => 'new@shop.om', 'password' => 'abaad2026',
        ])->assertRedirect(route('admin.setup.index'));

        $this->shop = Business::where('registration_source', Business::SOURCE_SELF_SIGNUP)->sole();
        $this->owner = User::where('business_id', $this->shop->id)->sole();
        $this->actingAs($this->owner);

        // والموقعُ على الأساسيّة الجديدة إضافةٌ يفتحها فريقُ أبعاد — لا تشملها التجربة
        AddonOrders::grant($this->shop, SubscriptionAddon::where('code', 'website')->firstOrFail(), 1, '2027-12-31', 'موقع المتجر الجديد بموافقة أبعاد');
        Entitlements::flush();

        // الموقعُ والدفعُ بالبطاقة — ما يضبطه التاجرُ من إعداداته
        $this->shop->forceFill(['site_slug' => 'newflowers', 'storefront_theme' => 'ribbon', 'tier' => 'gold'])->save();
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 0, 'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_new', 'secret_key' => 'sk_test_new', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876', 'active' => true,
        ]);
        $this->post(route('admin.finance.banks.store'), ['bank_name' => 'بنك مسقط', 'opening_balance' => 0, 'opening_date' => '2027-03-01'])
            ->assertSessionHasNoErrors();
    }

    /** إشعارُ Paymob الموقَّع لنيّةٍ — يُرسَل مرّةً أخرى كما تعيده Paymob */
    private function noticeAgain(StorePaymentIntent $intent, int $qty = 1): void
    {
        $obj = [
            'amount_cents' => 20000 * $qty, 'created_at' => '2027-03-01T10:00:10.100000', 'currency' => 'OMR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 990000 + $intent->id,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 770000 + $intent->id, 'merchant_order_id' => $intent->reference], 'owner' => 4705,
            'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();
    }

    public function test_a_merchant_who_signs_up_today_takes_settles_and_refunds_through_paymob_from_the_first_sale(): void
    {
        $this->aMerchantSignsUpAndOpensHisShop();
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);

        // ١ · أوّلُ بيعةٍ بالبطاقة — والإشعارُ يصل مرّتين: طلبٌ واحد، ومالُه في التسوية مرّةً واحدة
        $first = $this->newSale();
        $this->noticeAgain($first);
        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertSame([20.0, 0.0], [$this->clearing(), $this->bank()]);
        $this->assertSame('1360', DB::table('accounts')->where('business_id', $this->shop->id)->where('system_key', 'paymob_clearing')->value('code'));

        // ٢ · تحويلُ Paymob برسومه: الصافي إلى البنك، والرسومُ مصروف، والتسويةُ صفر
        $this->assertSame([$this->key($first) => PaymobSettlements::IN_CLEARING], collect(PaymobSettlements::candidates($this->shop->id))->pluck('booked', 'key')->all());
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$first], 0.6, 19.4)->status);
        $this->assertSame([0.0, 19.4, 0.6], [$this->clearing(), $this->bank(), $this->fees()]);

        // ٣ · طلبٌ بباقتين يُصحَّح إلى واحدة ويُردّ فرقُه بالبطاقة: يخرج من التسوية، وما بقي يُسوّى
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        $second = $this->newSale(2);
        $order = Order::findOrFail($second->order_id);
        OrderCorrection::replaceLine($order->fresh(), $order->items()->firstOrFail(), ['product_id' => $this->rose->id, 'qty' => 1], 'أعاد باقة', OrderCorrection::SETTLE_REFUNDED, 'بطاقة');
        $this->assertSame([20.0, 19.4], [$this->clearing(), $this->bank()], 'خرج الردُّ من البنك لا من التسوية');

        // ٤ · ورابطُ دفع، ثمّ تحويلٌ يحمل الاثنين
        $link = $this->paidLink(30);
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$second, $link], 1.5, 48.5)->status);
        $this->assertSame([0.0, 67.9, 2.1], [$this->clearing(), $this->bank(), $this->fees()]);

        // ٥ · ولا تُسوّى مدفوعةٌ مرّتين
        try {
            $this->settle([$first], 0.6, 19.4);
            $this->fail('سُوّيت مدفوعةٌ مرّتين');
        } catch (\RuntimeException) {
        }

        // وكلُّ قيدٍ في متجره متوازن، ولا سطرَ على حساب متجرٍ آخر
        $this->assertTrue(Ledger::trialBalance($this->shop->id)['balanced']);
        $this->assertSame(0, DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->whereColumn('a.business_id', '<>', 'e.business_id')->count());
        $this->assertSame(0, (int) DB::scalar('select count(*) from (select journal_entry_id from journal_lines group by journal_entry_id having round(sum(debit) - sum(credit), 3) <> 0) x'));
        $this->assertGreaterThan(0, JournalEntry::where('business_id', $this->shop->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\CouponLimits;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * فرصةُ الكوبون الأخيرة تُحجز حتّى يصل المال — ولا يُقبض سعرٌ غيرُ المعروض.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * الدفعُ بالبطاقة لا يُنشئ طلبَه في حينه: يُنشأ من إشعار البوّابة بعد أن
 * يُقبض المال. وبين فتح صفحة البنك ووصول الإشعار دقائق.
 *
 * فكودٌ بقيت له فرصةٌ واحدة: يفتح فلانٌ صفحةَ الدفع عليها بسعرٍ مخفَّض،
 * ويشتري غيرُه بالدفع عند الاستلام في أثنائها فينفد الكود. ثمّ يصل إشعارُ
 * فلانٍ: `quote` تردّ الكوبونَ فيُسقط الخصمُ **صامتًا**، ويُكتب الطلبُ
 * بالسعر الكامل — والمالُ الذي خرج من بطاقته هو المخفَّض. فيقرأ فاتورةً
 * بواحدٍ وعشرين وقد دفع تسعةَ عشر، ولا شيء يقول لأحدٍ ما وقع.
 *
 * فالفرصةُ تُحجز عند فتح الصفحة، وتُحسب في الحدّين حتّى تصير استعمالًا أو
 * تنقضي مدّتُها أو يُردّ الدفع. وما يُكتب هو ما قُبض أو لا يُكتب.
 */
class ACouponsLastChanceIsHeldUntilTheMoneyArrivesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'ribbon@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════════════════ أدواتٌ ═══════════════════════ */

    private function gateway(): PaymentGateway
    {
        return PaymentGateway::create([
            'business_id' => $this->shop->id,
            'provider' => PaymentGateway::PAYMOB,
            // مفاتيحُ تجريبيّةٌ — لا مفتاحَ حقيقيٍّ في اختبار، ولا دفعةَ حقيقيّة
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876',
            'active' => true,
        ]);
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'business_id' => $this->shop->id, 'code' => 'SAVE5', 'type' => 'مبلغ',
            'value' => 5, 'min_order' => 0, 'active' => true, 'used_count' => 0,
        ], $attrs));
    }

    private function order(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'card',
            'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
            'promo' => 'SAVE5',
        ];
    }

    /** يفتح صفحةَ الدفع — ويردّ نيّتَها */
    private function openCard(array $over = []): StorePaymentIntent
    {
        $this->gateway();
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);

        $this->postJson('/s/ribbon/checkout', $this->order($over))->assertOk();

        return StorePaymentIntent::latest('id')->firstOrFail();
    }

    /** إشعارٌ موقَّعٌ كما تُرسله Paymob */
    private function notice(StorePaymentIntent $intent, array $over = []): array
    {
        return array_merge([
            'amount_cents' => (int) round(((float) $intent->amount) * 1000),
            'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => 998877,
            'integration_id' => 4569876,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference],
            'owner' => 4705,
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true,
        ], $over);
    }

    private function fire(array $obj, ?string $hmac = null)
    {
        $hmac ??= Paymob::signature($obj, 'hmac_secret_value');

        return $this->postJson('/webhooks/paymob?hmac='.$hmac, ['obj' => $obj]);
    }

    /** شراءٌ بالدفع عند الاستلام — يُنشئ طلبَه في حينه */
    private function cod(array $over = [])
    {
        return $this->postJson('/s/ribbon/checkout', $this->order($over + ['pay' => 'cod']));
    }

    /* ═════════════ الحجزُ عند فتح صفحة الدفع ═════════════ */

    public function test_opening_the_payment_page_holds_the_slot_without_using_it(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);

        $intent = $this->openCard();

        $held = DB::table('coupon_redemptions')->firstOrFail();

        $this->assertNull($held->order_id, 'حُسب استعمالًا ولم يُدفع بعد');
        $this->assertSame((int) $intent->id, (int) $held->store_payment_intent_id);
        $this->assertNotNull($held->reserved_until);
        $this->assertSame(0, (int) $coupon->fresh()->used_count, 'العدّادُ الإجماليُّ لا يُزاد بحجز');
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ قبل أن يصل المال');
    }

    public function test_the_held_slot_stops_another_buyer_from_taking_the_last_use(): void
    {
        // فرصةٌ واحدةٌ إجمالًا، وفلانٌ في صفحة البنك عليها
        $this->coupon(['max_uses' => 1]);
        $this->openCard();

        // فيأتي غيرُه بالدفع عند الاستلام
        $other = $this->cod(['name' => 'سالم', 'phone' => '96899220002']);

        $other->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $other->json('errors'), 'أُخذت فرصةٌ محجوزة — فيُقبض سعرٌ غيرُ المعروض');
        $this->assertSame(0, Order::count());
    }

    public function test_his_own_hold_never_blocks_his_own_order(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();

        // دفع، فيصل إشعارُه — وحجزُه هو لا يُحسب عليه
        $this->fire($this->notice($intent))->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('SAVE5', $order->coupon_code, 'رُدّ كوبونُه بحجزِ نفسِه');
        $this->assertSame('15.000', (string) $order->total);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    public function test_his_own_hold_never_trips_the_total_limit_either(): void
    {
        /*
         * وفرصةٌ واحدةٌ إجمالًا، وهو الذي حجزها. فلو حُسبت عليه لَردّ الحدُّ
         * الإجماليُّ طلبَه هو: دفع على آخر فرصة، فمنعته فرصتُه.
         */
        $coupon = $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        $this->fire($this->notice($intent))->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('SAVE5', $order->coupon_code, 'رُدّ كوبونُه بحجزِ نفسِه على الحدّ الإجماليّ');
        $this->assertSame('15.000', (string) $order->total);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    public function test_an_expired_hold_of_his_own_stops_counting_against_him(): void
    {
        /*
         * حجزَ فرصتَه ولم يدفع، فانقضت الساعة. فلو بقي حجزُه محسوبًا عليه
         * لَصار حدُّه «مرّةً واحدة» مستهلكًا بفتح صفحةٍ أغلقها — وهو لم يشترِ
         * شيئًا قطّ.
         */
        $this->coupon(['per_customer_limit' => 1]);
        $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));

        // فيشتري بالدفع عند الاستلام بالرقم نفسِه
        $this->cod()->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame('15.000', (string) Order::firstOrFail()->total);
    }

    public function test_the_hold_becomes_one_use_not_two(): void
    {
        $this->coupon(['per_customer_limit' => 2]);
        $intent = $this->openCard();

        $this->fire($this->notice($intent))->assertOk();

        $rows = DB::table('coupon_redemptions')->get();

        $this->assertCount(1, $rows, 'حجزٌ واستعمالٌ صفّان على شراءٍ واحد');
        $this->assertSame((int) Order::firstOrFail()->id, (int) $rows[0]->order_id);
        $this->assertNull($rows[0]->reserved_until, 'بقي محجوزًا وقد صار استعمالًا');
    }

    /* ═════════════ وما يُقبض هو ما يُكتب ═════════════ */

    public function test_the_order_carries_exactly_what_the_card_was_charged(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();

        $this->assertSame('15.000', (string) $intent->amount, 'الخصمُ لم يدخل المبلغَ المُرسَل إلى البنك');

        $this->fire($this->notice($intent))->assertOk();

        $this->assertSame('15.000', (string) Order::firstOrFail()->total);
    }

    public function test_a_price_that_moved_after_payment_writes_no_order_and_wakes_the_merchant(): void
    {
        /*
         * وهذا البابُ ليس للكوبون وحده: سعرُ صنفٍ رُفع بين الدفع والإشعار
         * يقع فيه كذلك. ولا يُكتب طلبٌ بمبلغٍ غير الذي دُفع — تبقى الدفعةُ
         * بلا طلبٍ ويُوقَظ صاحبُ المحلّ ليقرّر.
         */
        $intent = $this->openCard(['promo' => '']);

        $this->rose->update(['price' => 30]);

        $this->fire($this->notice($intent))->assertOk();

        $this->assertSame(0, Order::count(), 'كُتب طلبٌ بمبلغٍ غير الذي قُبض');
        $intent->refresh();
        $this->assertTrue($intent->strayPayment(), 'لم يُعلَّم أنّ مالًا وصل بلا طلب');
        $this->assertStringContainsString('تغيّر مبلغُ الطلب', (string) $intent->error);
    }

    /* ═════════════ وفشلُ الدفع يردّ الفرصة ═════════════ */

    public function test_a_refused_card_gives_the_slot_back(): void
    {
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        $this->assertSame(1, DB::table('coupon_redemptions')->count());

        $this->fire($this->notice($intent, ['success' => false]))->assertOk();

        $this->assertSame(0, DB::table('coupon_redemptions')->count(), 'بقيت فرصةٌ محجوزةً لمن لم يدفع');

        // فيستعملها غيرُه
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();
    }

    public function test_a_voided_payment_gives_the_slot_back(): void
    {
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        $this->fire($this->notice($intent, ['is_voided' => true]))->assertOk();

        $this->assertSame(0, DB::table('coupon_redemptions')->count());
    }

    public function test_a_repeated_failure_notice_never_returns_the_slot_twice(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();

        $this->fire($this->notice($intent, ['success' => false]))->assertOk();
        $this->fire($this->notice($intent, ['success' => false]))->assertOk();

        $this->assertSame(0, DB::table('coupon_redemptions')->count());
    }

    public function test_a_late_refund_notice_never_erases_a_use_that_happened(): void
    {
        /*
         * دفع، وأُنشئ طلبُه، ثمّ وصل إشعارُ استرجاع. الفرصةُ صارت استعمالًا
         * ولا تُمحى بإشعار: إلغاءُ الطلب كاملًا هو الذي يردّها، بقرار صاحب
         * المحلّ — وإلّا مُحيت فاتورةٌ قائمةٌ من سجلّ الحدود وبقيت في الدفاتر.
         */
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();

        $this->fire($this->notice($intent))->assertOk();
        $this->assertSame(1, Order::count());

        $this->fire($this->notice($intent, ['is_refunded' => true, 'id' => 998878]))->assertOk();

        $this->assertSame(1, DB::table('coupon_redemptions')->whereNotNull('order_id')->count());
    }

    public function test_an_expired_hold_stops_holding(): void
    {
        $this->coupon(['max_uses' => 1]);
        $this->openCard();

        // ساعةٌ ودقيقة — مدّةُ صفحة الدفع انقضت (`Paymob::EXPIRES`)
        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));

        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();

        $this->assertSame(1, Order::count());
    }

    /* ═════════════ وتكرارُ الإشعار لا يُنشئ طلبين ═════════════ */

    public function test_a_repeated_paid_notice_writes_one_order_and_one_use(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 2]);
        $intent = $this->openCard();

        $this->fire($this->notice($intent))->assertOk();
        $this->fire($this->notice($intent))->assertOk();
        $this->fire($this->notice($intent))->assertOk();

        $this->assertSame(1, Order::count(), 'إشعارٌ أُعيد إرسالُه أنشأ طلبًا ثانيًا');
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    public function test_refreshing_the_payment_page_holds_one_slot_per_attempt_not_per_refresh(): void
    {
        /*
         * كلُّ نيّةِ دفعٍ حجزٌ واحد — القيدُ الفريد على معرّف النيّة. ومحاولةٌ
         * ثانيةٌ نيّةٌ أخرى فحجزٌ آخر: هي فرصةٌ أخرى يمسكها الزبونُ نفسُه،
         * ويُقاس عليه حدُّه فيُردّ إن تجاوزه.
         */
        $this->coupon(['per_customer_limit' => 1]);
        $this->openCard();

        $second = $this->postJson('/s/ribbon/checkout', $this->order());

        $second->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $second->json('errors'));
        $this->assertSame(1, DB::table('coupon_redemptions')->count(), 'حُجزت فرصتان لزبونٍ حدُّه واحدة');
    }

    /* ═════════════ وعزلُ المتاجر في الحجوز كذلك ═════════════ */

    public function test_a_neighbours_hold_never_touches_my_coupon(): void
    {
        $mine = $this->coupon(['max_uses' => 1]);

        $jar = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create(['business_id' => $jar->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $his = Coupon::create([
            'business_id' => $jar->id, 'code' => 'SAVE5', 'type' => 'مبلغ',
            'value' => 5, 'min_order' => 0, 'active' => true, 'used_count' => 0, 'max_uses' => 1,
        ]);

        $this->openCard();

        $this->assertNotNull(CouponLimits::totalRefusal($mine->fresh()), 'حجزي لا يمسك كودي');
        $this->assertNull(CouponLimits::totalRefusal($his), 'حجزٌ في متجري أمسك كودَ الجار');
    }

    /* ═════════════ والإلغاءُ والإرجاعُ الجزئيّ ═════════════ */

    public function test_a_full_cancellation_returns_the_slot_once_not_twice(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();
        $this->fire($this->notice($intent))->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame(1, DB::table('coupon_redemptions')->count());

        OrderCorrection::cancel($order, 'نفد الصنف');
        // وضغطةٌ ثانيةٌ على «إلغاء» — لا تردّ الفرصةَ مرّتين ولا تُنزل العدّاد تحت الصفر
        OrderCorrection::cancel($order->fresh(), 'نفد الصنف');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, DB::table('coupon_redemptions')->count());
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
    }

    public function test_a_partial_return_keeps_the_use_as_it_was(): void
    {
        /*
         * سياسةُ المشروع: الإلغاءُ الكاملُ يردّ الفرصة، والإرجاعُ الجزئيُّ لا
         * يردّها — الزبونُ انتفع بالكوبون على ما أبقاه.
         */
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard(['items' => [['id' => $this->rose->id, 'qty' => 3]]]);
        $this->fire($this->notice($intent))->assertOk();

        $order = Order::firstOrFail();
        $item = $order->items()->firstOrFail();

        // ثلاثٌ تصير واحدةً — إرجاعُ اثنتين، وهو تصحيحٌ لا إلغاء
        OrderCorrection::setQuantity($order, $item, 1, 'أرجع اثنتين');

        $this->assertSame(1, DB::table('coupon_redemptions')->whereNotNull('order_id')->count(), 'أُعيدت الفرصةُ بإرجاعٍ جزئيّ');
        $this->assertNotSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }
}

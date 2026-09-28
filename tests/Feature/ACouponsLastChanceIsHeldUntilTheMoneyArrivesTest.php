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
use App\Models\Transaction;
use App\Models\User;
use App\Support\CouponLimits;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\Store\Paymob;
use App\Support\Store\RibbonTexts;
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

    /** بابُ ردّ المال عند Paymob — يُزيَّف وحدَه ليُقاس أنّه نُودي */
    private const REFUND_URL = 'oman.paymob.com/api/acceptance/void_refund/refund';

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
        // ولا تُنشأ مرّتين: `openCard` قد يُنادى مرّتين في اختبارٍ واحد
        return PaymentGateway::firstOrCreate([
            'business_id' => $this->shop->id,
            'provider' => PaymentGateway::PAYMOB,
        ], [
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

    /**
     * يزيّف بابَي البوّابة: فتحُ الدفعة، وردُّ المال.
     *
     * ولا اتّصالَ حقيقيٌّ ولا مفتاحَ حقيقيّ — ولا ريالٌ يتحرّك.
     */
    private function fakeGateway(?array $refund = null, int $refundStatus = 200): void
    {
        Http::fake([
            self::REFUND_URL => Http::response($refund ?? ['id' => 55443322, 'success' => true], $refundStatus),
            'oman.paymob.com/v1/intention/' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201),
        ]);
    }

    /** يفتح صفحةَ الدفع — ويردّ نيّتَها */
    private function openCard(array $over = []): StorePaymentIntent
    {
        $this->gateway();
        $this->fakeGateway();

        $this->postJson('/s/ribbon/checkout', $this->order($over))->assertOk();

        return StorePaymentIntent::latest('id')->firstOrFail();
    }

    /**
     * يُشعل الردَّ الآليّ — مطفأٌ في الإنتاج حتّى يُجرَّب على حساب.
     *
     * فحالتان تُقاسان: المطفأةُ (تُعلَّق الدفعةُ للمراجعة) والمشتعلةُ (يُنادى
     * بابُ البوّابة). ولا يُقاس أحدُهما ويُظنّ الآخر.
     */
    private function autoRefund(): void
    {
        config(['storefront.paymob_auto_refund' => true]);
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
        $this->assertStringContainsString('تبدّل المبلغ بعد الدفع', (string) $intent->error);

        /*
         * والأصلُ أن يُعلَّق للمراجعة اليدويّة: بابُ الردّ لم يُجرَّب على حساب،
         * فلا يُنادى على بوّابة تاجرٍ حقيقيّ، ولا يُكتب «رُدّ» وهو لم يُردّ.
         */
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'void_refund/refund'));
        $this->assertSame(StorePaymentIntent::REFUND_PENDING, $intent->refund_status);
        $this->assertNull($intent->refunded_at, 'كُتب تاريخُ ردٍّ لم يقع');
        $this->assertNull($intent->provider_refund_id);
        $this->assertStringContainsString('يلزم ردٌّ يدويّ', (string) $intent->refund_error);
        $this->assertTrue($intent->needsAttention(), 'سكت الجرسُ عن مالٍ لم يُردّ');
    }

    public function test_the_manager_is_told_plainly_that_money_must_go_back(): void
    {
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();
        $this->fire($this->notice($intent))->assertOk();

        $this->actingAs($this->owner);
        $bell = collect(Demo::notifications());

        $row = $bell->firstWhere('key', 'refund-due-'.$intent->id);

        $this->assertNotNull($row, 'لا صفَّ يقول لمدير المتجر إنّ مالًا يلزمه ردّ');
        $this->assertStringContainsString('تحتاج ردًّا', (string) $row['text']);
        $this->assertStringContainsString('15', (string) $row['text'], 'لم يُقل المبلغُ');
        $this->assertStringContainsString('Paymob', (string) $row['hint'], 'لم يُقل من أين يُردّ');

        // ولا يُقال له «دفعةٌ وصلت» وحدَها — فلا يعرف ماذا يفعل بها
        $this->assertNull($bell->firstWhere('key', 'stray-payment-'.$intent->id));
    }

    public function test_with_the_switch_on_the_gateway_is_called_and_only_it_confirms(): void
    {
        $this->autoRefund();
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();
        $this->fire($this->notice($intent))->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'void_refund/refund')
            // ورقمُ العمليّة رقمٌ كما توثّقه مجموعةُ Paymob الرسميّة
            && $r['transaction_id'] === 998877
            && $r['amount_cents'] === 15000);

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::REFUND_SENT, $intent->refund_status);
        $this->assertSame('55443322', (string) $intent->provider_refund_id);
        $this->assertNotNull($intent->refunded_at);
        $this->assertFalse($intent->needsAttention());
    }

    /* ═════════════ ومهلةُ الحجز مهلةُ الجلسة نفسُها ═════════════ */

    public function test_the_hold_lasts_exactly_as_long_as_the_payment_session(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();

        $held = DB::table('coupon_redemptions')->firstOrFail();

        $this->assertNotNull($intent->expires_at, 'مهلةُ الجلسة لم تُكتب على النيّة');
        $this->assertSame(
            $intent->expires_at->toDateTimeString(),
            (string) $held->reserved_until,
            'مدّةُ الحجز تُحسب على حدة — فتفترق عن مهلة الصفحة يوم تتبدّل',
        );
        $this->assertSame(now()->addSeconds(Paymob::EXPIRES)->toDateTimeString(), $intent->expires_at->toDateTimeString());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/intention/') && $r['expiration'] === Paymob::EXPIRES);
    }

    public function test_an_expired_hold_is_swept_not_merely_ignored(): void
    {
        $this->coupon(['per_customer_limit' => 2]);
        $this->openCard();

        $this->assertSame(1, DB::table('coupon_redemptions')->count());

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));

        // حجزٌ جديدٌ يكنس المنقضي — لا صفَّ يبقى إلى الأبد عن دفعةٍ لم تقع
        $this->openCard();

        $rows = DB::table('coupon_redemptions')->get();
        $this->assertCount(1, $rows, 'بقي حجزٌ منقضٍ في الجدول');
    }

    /* ═════════════ وتأكيدٌ متأخّرٌ بعد انقضاء الحجز ═════════════ */

    public function test_a_late_confirmation_on_a_lost_slot_refunds_instead_of_writing_a_wrong_order(): void
    {
        /*
         * فتح صفحةَ الدفع على آخر فرصة، فانقضت المهلةُ قبل أن يُصدَّق دفعُه،
         * وأخذ غيرُه الفرصةَ في أثنائها. فلا طلبَ بالسعر الكامل — هو وافق
         * على المخفَّض ودفعه — ولا مالٌ يُحتجز حتّى يفتح صاحبُ المحلّ جرسَه.
         */
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));

        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();
        $this->assertSame(1, Order::count());

        $this->fire($this->notice($intent))->assertOk();

        $this->assertSame(1, Order::count(), 'كُتب طلبٌ ثانٍ بمبلغٍ غير الذي قُبض');

        $intent->refresh();
        $this->assertNull($intent->order_id, 'كُتب له طلبٌ بمبلغٍ آخر');
        // ولا يُترك بلا معالجة: إمّا رُدّ، وإمّا عُلِّق ونُبِّه عليه
        $this->assertSame(StorePaymentIntent::REFUND_PENDING, $intent->refund_status);
        $this->assertTrue($intent->needsAttention());
    }

    public function test_a_late_confirmation_refunds_when_the_switch_is_on(): void
    {
        $this->autoRefund();
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();

        $this->fire($this->notice($intent))->assertOk();

        $this->assertSame(1, Order::count(), 'كُتب طلبٌ ثانٍ بمبلغٍ غير الذي قُبض');
        $this->assertSame(StorePaymentIntent::REFUND_SENT, $intent->refresh()->refund_status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'void_refund/refund') && $r['amount_cents'] === 15000);
    }

    public function test_a_repeated_confirmation_never_refunds_twice(): void
    {
        $this->autoRefund();
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();

        // ثلاثةُ إشعاراتٍ بالمعرّف نفسِه — Paymob تُعيد الإرسال
        $this->fire($this->notice($intent))->assertOk();
        $this->fire($this->notice($intent))->assertOk();
        $this->fire($this->notice($intent))->assertOk();

        $this->assertSame(
            1,
            Http::recorded(fn ($r) => str_contains($r->url(), 'void_refund/refund'))->count(),
            'رُدّ المالُ مرّتين',
        );
    }

    public function test_a_refund_the_gateway_refuses_keeps_the_bell_ringing(): void
    {
        /*
         * ولا يُكتب «رُدّ» إلّا إن قالت البوّابةُ ذلك: طمأنينةٌ كاذبةٌ بردٍّ
         * لم يقع أسوأُ من غياب الردّ — صاحبُ المحلّ يقرأ «انتهت» ومالُ الزبون
         * عنده.
         */
        $this->autoRefund();
        $this->coupon(['max_uses' => 1]);
        $this->gateway();
        $this->fakeGateway(['detail' => 'transaction not refundable'], refundStatus: 422);

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();
        $intent = StorePaymentIntent::latest('id')->firstOrFail();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();

        $this->fire($this->notice($intent))->assertOk();

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::REFUND_FAILED, $intent->refund_status);
        $this->assertStringContainsString('not refundable', (string) $intent->refund_error);
        $this->assertTrue($intent->needsAttention(), 'سكت الجرسُ عن مالٍ لم يُردّ');
    }

    public function test_the_bell_falls_silent_for_money_that_came_back(): void
    {
        /*
         * وجرسُ «دفعةٌ وصلت ولم يُنشأ لها طلب» يُقرأ من `Demo` لا من النموذج.
         * فيُقاس من حيث يقرؤه التاجر: ما رُدّ انتهى، وما لم يُردّ يبقى يرنّ.
         */
        $this->autoRefund();
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->cod(['name' => 'سالم', 'phone' => '96899220002'])->assertOk();
        $this->fire($this->notice($intent))->assertOk();

        $this->actingAs($this->owner);
        $keys = array_column(Demo::notifications(), 'key');

        $this->assertNotContains('refund-due-'.$intent->id, $keys, 'يرنّ الجرسُ لمالٍ رُدّ');
        $this->assertNotContains('stray-payment-'.$intent->id, $keys);

        // وما فشل ردُّه يبقى يرنّ — مالٌ محتجزٌ لا طلبَ له ولا رُدّ
        $intent->forceFill([
            'refund_status' => StorePaymentIntent::REFUND_FAILED,
            'provider_refund_id' => null,
        ])->save();

        $this->assertContains(
            'refund-due-'.$intent->id,
            array_column(Demo::notifications(), 'key'),
            'سكت الجرسُ عن مالٍ لم يُردّ',
        );
    }

    public function test_the_refund_is_claimed_before_it_is_sent(): void
    {
        /*
         * والمطالبةُ حارسٌ ثانٍ تحت حارسِ `settle`: نداءان متقاربان على
         * `refund` — من مسارٍ آخر، أو من محاولةٍ تُعاد — لا يُرسلان ردَّين
         * على المال نفسِه. ويُقاس هنا مباشرةً لأنّ `settle` تحجب الثاني قبله.
         */
        $this->autoRefund();
        $this->coupon(['max_uses' => 1]);
        $intent = $this->openCard();
        $intent->forceFill([
            'status' => StorePaymentIntent::PAID,
            'provider_transaction_id' => '998877',
            'paid_at' => now(),
        ])->save();

        $this->assertTrue(Paymob::refund($intent->refresh(), 'سبب'));
        $this->assertFalse(Paymob::refund($intent->refresh(), 'سبب'), 'رُدّ المالُ مرّةً ثانية');

        $this->assertSame(
            1,
            Http::recorded(fn ($r) => str_contains($r->url(), 'void_refund/refund'))->count(),
            'أُرسل ردَّان على المال نفسِه',
        );
    }

    /* ═════════════ وخصمٌ سقط في الدفع عند الاستلام يُقال ═════════════ */

    public function test_a_discount_that_vanished_before_placing_is_told_not_swallowed(): void
    {
        /*
         * كتب كودَه ورأى السعرَ المخفَّض، ثمّ نفدت مرّاتُ الكود من صندوقٍ آخر
         * قبل أن يضغط «أكمل الطلب». وكان الطلبُ يمضي بالسعر الكامل صامتًا.
         */
        $this->coupon(['max_uses' => 1]);

        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']))->assertOk();

        $res = $this->cod(['agreed_total' => 15]);

        $res->assertStatus(422);
        $said = implode(' ', (array) ($res->json('errors.promo') ?? []));
        $this->assertStringContainsString('انتهت مرات استخدام الكوبون', $said, 'لم يُقل له لماذا سقط الخصم');
        $this->assertStringContainsString('20', $said, 'لم يُقل له الإجماليُّ الجديد');
        $this->assertSame(1, Order::count(), 'كُتب طلبٌ بسعرٍ لم يوافق عليه');
    }

    public function test_the_same_order_goes_through_once_he_agrees_to_the_new_total(): void
    {
        $this->coupon(['max_uses' => 1]);
        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']))->assertOk();

        $this->cod(['agreed_total' => 15])->assertStatus(422);

        // فيقرّ بالإجماليّ الجديد — وهو ما ترسله الصفحةُ بعد إعادة التسعير
        $this->cod(['agreed_total' => 20])->assertOk();

        $mine = Order::where('customer_name', 'مريم')->firstOrFail();
        $this->assertSame('20.000', (string) $mine->total);
        $this->assertNull($mine->coupon_code, 'كُتب كوبونٌ لم يُقبل');
    }

    public function test_an_order_without_a_code_is_never_asked_to_agree_again(): void
    {
        $this->cod(['promo' => ''])->assertOk();

        $this->assertSame(1, Order::count());
    }

    public function test_a_coupon_that_still_works_needs_no_second_confirmation(): void
    {
        $this->coupon(['max_uses' => 5]);

        $this->cod()->assertOk();

        $this->assertSame('15.000', (string) Order::firstOrFail()->total);
    }

    public function test_the_page_asks_for_an_explicit_agreement_not_a_silent_resend(): void
    {
        /*
         * والموافقةُ ضغطةٌ لا إعادةُ إرسال: يقرأ السببَ والإجماليَّ الجديد في
         * صندوقٍ يُفتح له، ويُقرّ بزرٍّ — أو يتراجع فلا يُنشأ طلب.
         *
         * ويُقاس من الصفحة نفسِها: لا vitest لها (قالبُ Blade لا React)، فما
         * يُقاس وجودُ الصندوق ومقبضيه ونصوصِه بلغتي المتجر.
         */
        $blade = (string) file_get_contents(resource_path('views/store/ribbon/checkout.blade.php'));

        foreach (['data-rb-agree-box', 'data-rb-agree-why', 'data-rb-agree]', 'data-rb-agree-no'] as $hook) {
            $this->assertStringContainsString($hook, $blade, 'صندوقُ الموافقة ناقصٌ: '.$hook);
        }

        $this->assertStringContainsString("\$t['priceChanged']", $blade);
        $this->assertStringContainsString("\$t['agreeNew']", $blade);
        $this->assertStringContainsString("\$t['keepBrowsing']", $blade);
        // والإجماليُّ المُقَرُّ يُرسَل مع الطلب
        $this->assertStringContainsString('agreed_total: shownTotal', $blade);

        foreach (['ar', 'en'] as $lang) {
            $texts = RibbonTexts::for($lang);
            foreach (['priceChanged', 'agreeNew', 'keepBrowsing'] as $key) {
                $this->assertNotEmpty($texts[$key] ?? null, "نصُّ {$key} غائبٌ بلغة {$lang}");
            }
        }
    }

    public function test_refusing_the_new_total_writes_no_order(): void
    {
        /*
         * ومن لم يوافق فلا طلب: الخادمُ لا يقبل إلّا إجماليًّا يطابق ما صار
         * إليه التسعير. فمن أغلق الصندوق ولم يُقرّ لا يصل منه شيء — ولو أُرسل
         * الإجماليُّ القديم رُدّ.
         */
        $this->coupon(['max_uses' => 1]);
        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']))->assertOk();

        $before = Order::count();

        $this->cod(['agreed_total' => 15])->assertStatus(422);
        $this->cod(['agreed_total' => null])->assertStatus(422);
        $this->cod()->assertStatus(422);

        $this->assertSame($before, Order::count(), 'كُتب طلبٌ بسعرٍ لم يُقَرّ');
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

    public function test_a_partial_return_owes_the_difference_and_sends_no_automatic_refund(): void
    {
        /*
         * ═══ والإرجاعُ الجزئيُّ يمشي بسياسة المشروع كما هي ═══
         *
         * الفاتورةُ تُصحَّح فيتبعها قيدُها (`syncTransaction`)، فيُقرأ المستحقُّ
         * للزبون فرقًا بين ما دفع وما صار عليه الطلب. وردُّ ذلك الفرق إلى
         * البطاقة يبقى بيد صاحب المحلّ — كما كان قبل هذا التغيير، ولأنّ بابَ
         * الردّ لم يُجرَّب على حساب.
         *
         * ولا تُردّ فرصةُ الكوبون: الزبونُ انتفع به على ما أبقاه.
         */
        $this->autoRefund();
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard(['items' => [['id' => $this->rose->id, 'qty' => 3]]]);
        $this->fire($this->notice($intent, ['amount_cents' => 55000]))->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('55.000', (string) $order->total, 'ثلاثُ باقاتٍ بخمسةٍ خصمًا');

        OrderCorrection::setQuantity($order, $order->items()->firstOrFail(), 1, 'أرجع اثنتين');

        $fresh = $order->fresh();
        $owed = 55.0 - (float) $fresh->total;

        $this->assertGreaterThan(0, $owed, 'لا مستحقَّ للزبون بعد إرجاعٍ جزئيّ');
        // والقيدُ يتبع الفاتورة — فالمستحقُّ مقروءٌ في الدفاتر لا مخمَّن
        $this->assertSame(
            (string) $fresh->total,
            (string) Transaction::where('order_id', $order->id)->value('amount'),
            'القيدُ بقي على المبلغ القديم — فلا يُعرف كم للزبون',
        );

        // ولا ردَّ آليٌّ يُرسَل على إرجاعٍ جزئيّ
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'void_refund/refund'));
        $this->assertNull($intent->refresh()->refund_status);

        // ولا فرصةَ كوبونٍ تُردّ
        $this->assertSame(1, DB::table('coupon_redemptions')->whereNotNull('order_id')->count());
    }

    public function test_a_partial_refund_notice_never_returns_the_coupon_use(): void
    {
        /*
         * وإشعارُ إرجاعٍ جزئيٍّ من لوحة البوّابة يُقرأ جزئيًّا: يُكتب أنّه وقع
         * ويُنبَّه عليه، ولا يردّ الفرصة.
         */
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();
        $this->fire($this->notice($intent))->assertOk();

        // نصفُ المبلغ فقط
        $this->fire($this->notice($intent, ['id' => 998899, 'is_refunded' => true, 'amount_cents' => 7000]))->assertOk();

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::REFUND_SENT, $intent->refund_status);
        $this->assertStringContainsString('جزء', (string) $intent->refund_error, 'قُرئ الإرجاعُ الجزئيُّ ردًّا كاملًا');
        $this->assertSame(1, DB::table('coupon_redemptions')->whereNotNull('order_id')->count(), 'أُعيدت الفرصةُ بإرجاعٍ جزئيّ');
    }

    public function test_a_refunded_order_still_standing_is_put_before_the_merchant(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $intent = $this->openCard();
        $this->fire($this->notice($intent))->assertOk();
        $order = Order::firstOrFail();

        $this->fire($this->notice($intent, ['id' => 998899, 'is_refunded' => true]))->assertOk();

        $this->actingAs($this->owner);
        $row = collect(Demo::notifications())->firstWhere('key', 'refunded-order-'.$intent->id);

        $this->assertNotNull($row, 'استُرجعت دفعةٌ وطلبُها قائمٌ ولا شيء يقول');
        $this->assertStringContainsString($order->number, (string) $row['text']);
        $this->assertStringContainsString('ألغِ الطلب', (string) $row['hint']);

        // ثمّ يُلغيه بيده — فتعود الفرصةُ والعدّادُ معًا، مرّةً واحدة
        OrderCorrection::cancel($order, 'استُرجعت الدفعة');
        OrderCorrection::cancel($order->fresh(), 'استُرجعت الدفعة');

        $this->assertSame(0, DB::table('coupon_redemptions')->count());
        $this->assertSame(0, (int) Coupon::firstOrFail()->used_count);
        // ويسكت الصفُّ بعد الإلغاء
        $this->assertNull(collect(Demo::notifications())->firstWhere('key', 'refunded-order-'.$intent->id));
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

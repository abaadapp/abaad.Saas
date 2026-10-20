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
use App\Support\Store\Paymob;
use App\Support\Store\WebCheckout;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * لا تُفتح صفحةُ دفعٍ بخصمٍ محدودٍ إلّا على حجزٍ كُتب فعلًا.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * `CouponLimits::hold` كان يقول «حُجزت» (`null`) متى لم يجد سببًا للردّ، ولا
 * يسأل `reserve` هل كتبت. و`reserve` لا تكتب صفًّا لزبونٍ لا يُعرف: لا رقمَ
 * يُقرأ ولا بطاقةَ له في المتجر. وتحقُّقُ الموقع كان يقبل «((((((((»: نمطُه
 * يعدّ الخاناتِ لا الأرقام.
 *
 * فتُفتح لهذا صفحةُ البنك بسعرٍ مخفَّضٍ ولا شيءَ يُمسك فرصتَه. ويأخذها غيرُه
 * بالدفع عند الاستلام في أثناء ذلك، فيصل إشعارُ الأوّل إلى كودٍ نفد: دفع ورُدّ
 * طلبُه، والمالُ عند صاحب المحلّ بلا طلب.
 *
 * ═══ والقاعدة ═══
 *
 * - `hold` لا يقول «تمّ» إلّا لحجزٍ كُتب، أو لكودٍ لا حدَّ له — ويُقرّر ذلك من
 *   الصفّ المقفل لا من نسخةٍ قُرئت قبله.
 * - والموقعُ لا يقبل رقمًا لا يقرؤه النظام (`WhatsAppPhone`)، ويقبل كلَّ رقمٍ
 *   دوليٍّ صحيح كما كان.
 *
 * والسباقُ بين عمليّتين في `TheLastCouponUseIsSpentOnceAcrossTillsAndPagesTest`.
 */
class ALimitedCouponOpensNoPaymentPageWithoutItsHoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        /*
         * ومتجرُ سعود في الإنتاج رقمُه ٥، وله قواعدُ خاصّة (اسمٌ بالإنجليزيّة،
         * وكرتُ هديّة). ورقمُ الصفّ هنا يتبع تسلسلَ القاعدة — فقد يصير ٥.
         */
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'ribbon@unheld.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        // مفاتيحُ تجريبيّةٌ — لا مفتاحَ حقيقيٍّ في اختبار، ولا دفعةَ حقيقيّة
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════════════════ أدواتٌ ═══════════════════════ */

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

    private function opened(): PromiseInterface
    {
        return Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201);
    }

    /** نيّةُ دفعٍ مفتوحة — كما يكتبها `Paymob::prepare` */
    private function intent(): StorePaymentIntent
    {
        return StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'reference' => 'web-hold-'.uniqid(), 'payload' => [],
            'amount' => 15, 'currency' => 'OMR', 'status' => StorePaymentIntent::PENDING,
            'expires_at' => now()->addSeconds(Paymob::EXPIRES),
        ]);
    }

    /** الحجوزُ الحيّة — فرصٌ مُمسَكةٌ لم تصر استعمالًا */
    private function holds(): int
    {
        return DB::table('coupon_redemptions')
            ->whereNull('order_id')
            ->where('reserved_until', '>', now())
            ->count();
    }

    private function notice(StorePaymentIntent $intent): array
    {
        return [
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
        ];
    }

    private function paid(StorePaymentIntent $intent): void
    {
        $obj = $this->notice($intent);

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();
    }

    /* ═════════════ رقمٌ لا يُعرف به أحد ═════════════ */

    /** @return array<string, array{string}> */
    public static function unreadablePhones(): array
    {
        return [
            'أقواسٌ بلا رقم' => ['(((((((('],
            'شرطاتٌ بلا رقم' => ['--------'],
            'رموزٌ ومسافاتٌ بلا رقم' => ['+ ( ) - + ( )'],
            'أصفارٌ وحدها' => ['0000 0000'],
            'أطولُ من أيّ رقمٍ في الدنيا' => ['1234 5678 9012 3456'],
        ];
    }

    #[DataProvider('unreadablePhones')]
    public function test_a_phone_the_shop_cannot_read_opens_no_payment_page_on_a_limited_coupon(string $phone): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);

        $res = $this->postJson('/s/ribbon/checkout', $this->order(['phone' => $phone]));

        $this->assertNotSame(200, $res->status(), 'فُتحت صفحةُ دفعٍ بخصمٍ محدود وحجوزُها '.$this->holds().' — فلا يُمسك فرصتَها شيء');
        $res->assertStatus(422);
        $this->assertArrayHasKey('phone', (array) $res->json('errors'), 'رُدّ الطلبُ بغير سببه: الرقمُ هو ما يُصلَح');
        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count(), 'بقيت نيّةُ دفعٍ لطلبٍ رُدّ');
        $this->assertSame(0, DB::table('coupon_redemptions')->count(), 'بقي حجزٌ لصفحةٍ لم تُفتح');

        // والفرصةُ لمن يأتي برقمٍ يُقرأ
        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']))
            ->assertOk();
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    /* ═════════════ والحجزُ نفسُه: «لم يُكتب» ليس «لا حاجةَ إليه» ═════════════ */

    public function test_a_hold_for_no_one_is_refused_not_reported_as_written(): void
    {
        /*
         * هذا هو السببُ لا العَرَض: لو فات الموقعَ رقمٌ لا يُقرأ — أو نادى
         * `hold` بابٌ آخر بلا زبون — لا يقول الحجزُ «تمّ» وهو لم يكتب شيئًا.
         */
        $coupon = $this->coupon(['max_uses' => 1]);

        $why = CouponLimits::hold($coupon, $this->intent(), null, null);

        $this->assertNotNull($why, 'قال الحجزُ «تمّ» ولم يُكتب صفّ — فتُفتح صفحةُ دفعٍ لا يُمسك فرصتَها شيء');
        // ويُقال له ما يُصلحه: الرقم — لا «أعد المحاولة» على ما لا تُصلحه المحاولة
        $this->assertStringContainsString('رقم هاتف', $why);
        $this->assertSame(0, DB::table('coupon_redemptions')->count());
    }

    public function test_a_hold_the_table_did_not_write_is_refused(): void
    {
        /*
         * ونيّةٌ واحدةٌ حجزٌ واحد (القيدُ الفريد على `store_payment_intent_id`).
         * فصفٌّ ثانٍ للنيّة نفسِها تبتلعه `insertOrIgnore` صامتة — ولا يمرّ نجاحًا.
         */
        $first = $this->coupon(['max_uses' => 1]);
        $second = $this->coupon(['code' => 'GIFT3', 'value' => 3, 'max_uses' => 1]);
        $intent = $this->intent();

        $this->assertNull(CouponLimits::hold($first, $intent, 'phone:96899110001', null));

        $why = CouponLimits::hold($second, $intent, 'phone:96899110001', null);

        $this->assertNotNull($why, 'قال الحجزُ «تمّ» والقاعدةُ لم تكتب صفَّه');
        $this->assertSame(0, DB::table('coupon_redemptions')->where('coupon_id', $second->id)->count());
    }

    public function test_a_limit_set_after_the_basket_was_priced_is_read_under_the_lock(): void
    {
        /*
         * والكوبونُ في اليد قُرئ عند التسعير بلا قفل — بلا حدٍّ يومئذ. ثمّ ضبط
         * صاحبُ المحلّ حدًّا. فلو صُدّقت تلك النسخةُ لَفُتحت الصفحةُ بلا حجز،
         * أو على كودٍ نفد. فالقرارُ من الصفّ المقفل.
         */
        $coupon = $this->coupon();
        $priced = $coupon->fresh();

        $coupon->update(['max_uses' => 2, 'used_count' => 1]);
        $this->assertNull(CouponLimits::hold($priced, $this->intent(), 'phone:96899110001', null));
        $this->assertSame(1, $this->holds(), 'فُتحت الصفحةُ على كودٍ صار محدودًا ولم يُحجز له');

        $this->assertNotNull(
            CouponLimits::hold($priced, $this->intent(), 'phone:96899220002', null),
            'فُتحت صفحةٌ ثانيةٌ على كودٍ لم تبقَ فيه فرصة',
        );
        $this->assertSame(1, $this->holds());
    }

    /* ═════════════ والرقمُ الصحيح يحجز كما كان ═════════════ */

    public function test_an_omani_number_holds_the_last_use_and_the_payment_spends_it_once(): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);

        $this->postJson('/s/ribbon/checkout', $this->order(['phone' => '9123 4567']))->assertOk();

        $intent = StorePaymentIntent::firstOrFail();
        $held = DB::table('coupon_redemptions')->sole();
        $this->assertSame('phone:96891234567', $held->customer_key);
        $this->assertSame((int) $intent->id, (int) $held->store_payment_intent_id);
        $this->assertSame(1, $this->holds());

        $this->paid($intent);

        $used = DB::table('coupon_redemptions')->sole();
        $this->assertSame((int) Order::firstOrFail()->id, (int) $used->order_id, 'لم يصر الحجزُ استعمالًا');
        $this->assertNull($used->reserved_until, 'بقي محجوزًا وقد صار استعمالًا');
        $this->assertSame(1, (int) $coupon->fresh()->used_count, 'حُسب الشراءُ الواحد مرّتين');
    }

    /** @return array<string, array{string, string}> */
    public static function numbersAsPeopleWriteThem(): array
    {
        return [
            'عُمان بمفتاحها' => ['+968 9123 4567', 'phone:96891234567'],
            'عُمان بصفرين وشرطات' => ['00968-9123-4567', 'phone:96891234567'],
            'بأرقامٍ عربيّة' => ['٩١٢٣ ٤٥٦٧', 'phone:96891234567'],
            'الإمارات' => ['+971 50 123 4567', 'phone:971501234567'],
            'السعودية بصفرين' => ['00966 50 123 4567', 'phone:966501234567'],
            'بريطانيا' => ['+44 20 7946 0958', 'phone:442079460958'],
            'أمريكا بأقواس' => ['+1 (212) 555-0123', 'phone:12125550123'],
            'الهند' => ['+91 98765 43210', 'phone:919876543210'],
        ];
    }

    #[DataProvider('numbersAsPeopleWriteThem')]
    public function test_a_real_number_from_anywhere_still_opens_the_page_and_holds_it(string $phone, string $key): void
    {
        $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);

        $this->postJson('/s/ribbon/checkout', $this->order(['phone' => $phone]))->assertOk();

        $this->assertSame($key, DB::table('coupon_redemptions')->sole()->customer_key);
        $this->assertSame(1, $this->holds());
    }

    /* ═════════════ وكودٌ بلا حدّ كما كان ═════════════ */

    public function test_a_coupon_with_no_limit_opens_the_page_and_holds_nothing(): void
    {
        $this->coupon();
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(15.0, (float) StorePaymentIntent::firstOrFail()->amount, 'سقط الخصمُ عن كودٍ لا حدَّ له');
        $this->assertSame(0, DB::table('coupon_redemptions')->count(), 'حُجز لكودٍ لا فرصةَ فيه تُزاحَم');
    }

    /* ═════════════ والدفعُ عند الاستلام ═════════════ */

    public function test_cash_on_delivery_still_spends_a_limited_coupon_once_per_customer(): void
    {
        $coupon = $this->coupon(['max_uses' => 5, 'per_customer_limit' => 1]);

        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod']))->assertOk();

        $this->assertSame((int) Order::firstOrFail()->id, (int) DB::table('coupon_redemptions')->sole()->order_id);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);

        // والزبونةُ نفسُها برقمها مكتوبًا بشكلٍ آخر
        $again = $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'phone' => '+968 9911 0001']));

        $again->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $again->json('errors'));
        $this->assertSame(1, Order::count());
    }

    public function test_cash_on_delivery_asks_for_a_readable_phone_too(): void
    {
        /*
         * والقاعدةُ للموقع كلِّه لا للبطاقة وحدها: رقمٌ لا يُقرأ لا يتّصل به
         * أحد، ولا تصله رسالةُ طلبه. وهي التغييرُ الوحيد على الدفع عند الاستلام.
         */
        $res = $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'promo' => null, 'phone' => '((((((((']));

        $res->assertStatus(422);
        $this->assertArrayHasKey('phone', (array) $res->json('errors'));
        $this->assertSame(0, Order::count());
    }

    /* ═════════════ ودفعةٌ قُبضت قبل القاعدة تُتمّ طلبَها ═════════════ */

    public function test_a_payment_taken_before_the_phone_rule_still_becomes_its_order(): void
    {
        /*
         * صفحةٌ فُتحت قبل هذه القاعدة برقمٍ لا يُقرأ، ودفع صاحبُها بعدها. فلو
         * سُئل رقمُه الآن لَرُدّ طلبُه والمالُ مقبوض. فلا يُسأل في دفعةٍ قُبضت —
         * كما لا تُسأل وسيلةُ الدفع (`WebCheckout::payments`) ولا رسالةُ البطاقة.
         */
        $payload = $this->order(['phone' => '((((((((', 'promo' => null]);
        $intent = Paymob::prepare($this->shop, $payload, WebCheckout::quote($this->shop, $payload));

        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'دُفع ثمنُ الطلب ثمّ رُدّ برقمٍ قُبل يومَ فُتحت صفحتُه');
        $this->assertSame((int) Order::firstOrFail()->id, (int) $intent->fresh()->order_id);
    }
}

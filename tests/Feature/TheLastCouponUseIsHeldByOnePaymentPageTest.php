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
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\Paymob;
use App\Support\Store\WebCheckout;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * آخرُ فرصةٍ في الكوبون تحجزها صفحةُ دفعٍ واحدة — ولو وصل اثنان معًا.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * كان الدفعُ بالبطاقة (`WebCheckout::toCard`) يسأل «بقيت فرصة؟» ثمّ يطرق بابَ
 * Paymob ثمّ يحجز. والطَّرقُ ثانيةٌ أو أكثر: من يصل في أثنائها يسأل فيجد
 * الفرصةَ حرّةً — فالأوّلُ لم يحجز بعد — فيحجزها، ثمّ يعود الأوّلُ من البنك
 * فيحجزها ثانيةً. فرصةٌ واحدة، وصفحتا دفعٍ بسعرٍ مخفَّض، وزبونان يدفعان.
 * ثمّ يصل إشعارا الدفع إلى كودٍ نفد: يُردّ الطلبان والمالُ مقبوض.
 *
 * والسباقُ هنا يُصنع بلا ساعةٍ ولا حظّ: الزبونُ الثاني يصل **من داخل** جواب
 * البنك المزيَّف للأوّل — أي في اللحظة التي كان فيها الأوّلُ عند البنك.
 * وما يحتاج قفلَ صفٍّ حقيقيًّا بين عمليّتين في
 * `TheLastCouponUseIsSpentOnceAcrossTillsAndPagesTest` (على PostgreSQL).
 *
 * ═══ والقاعدة ═══
 *
 * الفحصُ والحجزُ معًا تحت قفل صفّ الكوبون، في معاملةٍ تُغلق **قبل** أن يُطرق
 * البنك. وإن لم تُفتح الصفحةُ عادت الفرصةُ في حينها.
 */
class TheLastCouponUseIsHeldByOnePaymentPageTest extends TestCase
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
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'ribbon@last-use.local',
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

    /**
     * زبونٌ ثانٍ يطلب صفحةَ الدفع — ويُردّ ما قيل له.
     *
     * يُنادى من داخل جواب البنك المزيَّف للأوّل: هو السباقُ نفسُه.
     *
     * @return array<string, mixed>|string `opened` إن فُتحت صفحتُه، وإلّا أخطاؤه
     */
    private function secondBuyer(array $over): array|string
    {
        try {
            WebCheckout::toCard($this->shop->fresh(), $this->order($over));

            return 'opened';
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    /** الحجوزُ الحيّة — فرصٌ مُمسَكةٌ لم تصر استعمالًا */
    private function liveHolds(): int
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

    /* ═════════════ زبونان على آخر فرصة ═════════════ */

    public function test_a_second_buyer_who_arrives_while_the_first_is_at_the_bank_cannot_take_the_same_last_use(): void
    {
        $this->coupon(['max_uses' => 1]);

        $second = null;
        Http::fake(function (Request $request) use (&$second) {
            // الأوّلُ عند البنك — فيصل سالم
            if ($second === null) {
                $second = 'waiting';
                $second = $this->secondBuyer(['name' => 'سالم', 'phone' => '96899220002']);
            }

            return $this->opened();
        });

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        $this->assertIsArray($second, 'فُتحت لسالم صفحةُ دفعٍ على فرصةٍ يحجزها غيرُه');
        $this->assertArrayHasKey('promo', $second);
        $this->assertSame(1, $this->liveHolds(), 'حُجزت الفرصةُ الواحدة مرّتين — فيدفع زبونان سعرًا لا يملكه إلّا واحد');
        $this->assertSame(1, StorePaymentIntent::count(), 'بقيت نيّةُ دفعٍ لمن رُدّ');
    }

    public function test_the_same_customer_cannot_hold_two_payment_pages_on_his_only_use(): void
    {
        $this->coupon(['per_customer_limit' => 1]);

        $second = null;
        Http::fake(function (Request $request) use (&$second) {
            // ويفتح مريمُ الصفحةَ ثانيةً من هاتفٍ آخر وهي ما زالت عند البنك
            if ($second === null) {
                $second = 'waiting';
                $second = $this->secondBuyer([]);
            }

            return $this->opened();
        });

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        $this->assertIsArray($second, 'فُتحت لها صفحتان على فرصةٍ واحدة');
        $this->assertArrayHasKey('promo', $second);
        $this->assertSame(1, $this->liveHolds());
    }

    /* ═════════════ الحجزُ قبل البنك، والبنكُ بلا قفل ═════════════ */

    public function test_the_slot_is_held_before_the_bank_is_asked(): void
    {
        $this->coupon(['max_uses' => 1]);

        $heldWhenAsked = null;
        Http::fake(function (Request $request) use (&$heldWhenAsked) {
            $heldWhenAsked = $this->liveHolds();

            return $this->opened();
        });

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        $this->assertSame(1, $heldWhenAsked, 'طُرق البنكُ والفرصةُ غيرُ محجوزة — فيأخذها غيرُه في أثنائه');
    }

    public function test_the_bank_is_asked_with_no_transaction_left_open(): void
    {
        /*
         * وقفلُ صفّ الكوبون لا يبقى مفتوحًا والبنكُ يُطرق.
         *
         * الطَّرقُ يأخذ حتّى عشرين ثانية (`Paymob::open`)، والقفلُ في معاملة. فلو
         * بقيت مفتوحةً لَانتظر كلُّ صندوقٍ وكلُّ زبونٍ يستعمل الكودَ نفسَه حتّى
         * يجيب البنك — وبنكٌ بطيءٌ يوقف الدرجَ في المحلّ.
         *
         * و`RefreshDatabase` تفتح معاملتَها هي، فيُقاس الفرقُ عنها لا العددُ نفسُه.
         * وقياسُ القفل نفسِه من اتّصالٍ آخر في ملفّ PostgreSQL.
         */
        $this->coupon(['max_uses' => 1]);

        $outside = DB::transactionLevel();
        $during = null;
        Http::fake(function (Request $request) use (&$during) {
            $during = DB::transactionLevel();

            return $this->opened();
        });

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        $this->assertSame($outside, $during, 'طُرق البنكُ ومعاملةُ الحجز مفتوحة — فيبقى صفُّ الكوبون مقفلًا طوال انتظاره');
    }

    /* ═════════════ وإن لم تُفتح الصفحة عادت الفرصة ═════════════ */

    public function test_a_bank_that_refuses_to_open_the_page_gives_the_slot_back(): void
    {
        $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => Http::response(['detail' => 'Invalid integration'], 400)]);

        $res = $this->postJson('/s/ribbon/checkout', $this->order());

        $res->assertStatus(422);
        $this->assertArrayHasKey('pay', (array) $res->json('errors'));
        $this->assertSame(0, $this->liveHolds(), 'بقيت الفرصةُ محجوزةً لصفحةٍ لم تُفتح');
        $this->assertSame(StorePaymentIntent::FAILED, StorePaymentIntent::firstOrFail()->status);

        // فيأخذها غيرُه في الحال
        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']))
            ->assertOk();
        $this->assertSame('SAVE5', Order::firstOrFail()->coupon_code);
    }

    public function test_a_bank_that_never_answers_gives_the_slot_back_and_says_so(): void
    {
        /*
         * وبنكٌ لا يجيب أصلًا (انقطاعٌ أو مهلة) يرمي `ConnectionException` — وليست
         * `RuntimeException`، فكانت تخرج للزبون صفحةَ خطأ ٥٠٠، وتبقى النيّةُ
         * «معلَّقة» كأنّ صفحتَها فُتحت. والآن تُكتب «فشلت»، وتعود الفرصة، ويُقال
         * له ما يُقال لبنكٍ ردّه: جرّب طريقةً أخرى.
         */
        $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => Http::failedConnection('cURL error 28: Operation timed out')]);

        $res = $this->postJson('/s/ribbon/checkout', $this->order());

        $res->assertStatus(422);
        $this->assertArrayHasKey('pay', (array) $res->json('errors'));
        $this->assertSame(0, $this->liveHolds(), 'بقيت الفرصةُ محجوزةً لبنكٍ لم يجب');
        $this->assertSame(StorePaymentIntent::FAILED, StorePaymentIntent::firstOrFail()->status);

        $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']))
            ->assertOk();
    }

    /* ═════════════ والدفعُ يحوّل الحجزَ استعمالًا واحدًا ═════════════ */

    public function test_a_paid_last_use_becomes_exactly_one_use_and_closes_the_code(): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();
        $intent = StorePaymentIntent::firstOrFail();

        $obj = $this->notice($intent);
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();

        $rows = DB::table('coupon_redemptions')->get();
        $this->assertCount(1, $rows, 'حجزٌ واستعمالٌ صفّان على شراءٍ واحد');
        $this->assertSame((int) Order::firstOrFail()->id, (int) $rows[0]->order_id);
        $this->assertNull($rows[0]->reserved_until, 'بقي محجوزًا وقد صار استعمالًا');
        $this->assertSame(1, (int) $coupon->fresh()->used_count, 'حُسب الشراءُ الواحد مرّتين في العدّاد');

        // والكودُ نفد: من يأتي بعده يُقال له، ولا يُحجز له شيء
        $next = $this->postJson('/s/ribbon/checkout', $this->order(['pay' => 'cod', 'name' => 'سالم', 'phone' => '96899220002']));
        $next->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $next->json('errors'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
    }

    /* ═════════════ والحجزُ المنقضي يعود إلى الناس ═════════════ */

    public function test_an_expired_hold_frees_the_last_use_for_another_payment_page(): void
    {
        $this->coupon(['max_uses' => 1]);
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();
        $first = StorePaymentIntent::firstOrFail();

        // أغلقت مريمُ الصفحةَ ولم تدفع، وانقضت مدّتُها
        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));

        $this->postJson('/s/ribbon/checkout', $this->order(['name' => 'سالم', 'phone' => '96899220002']))->assertOk();
        $second = StorePaymentIntent::latest('id')->firstOrFail();

        $rows = DB::table('coupon_redemptions')->get();
        $this->assertCount(1, $rows, 'بقي الحجزُ المنقضي في الجدول');
        $this->assertSame((int) $second->id, (int) $rows[0]->store_payment_intent_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $this->liveHolds());
    }
}

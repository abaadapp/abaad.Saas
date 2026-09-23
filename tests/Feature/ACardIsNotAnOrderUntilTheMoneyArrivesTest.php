<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الدفعُ بالبطاقة — ولا طلبَ قبل أن يصل المال.
 *
 * ═══ وأثقلُ ما يُحرَس ═══
 *
 * أنّ الطلبَ لا يُكتب من الرابط الذي يعود به الزائر. ذاك الرابطُ في يده:
 * من كتب فيه «نجح» صار طلبُه مدفوعًا بلا أن يدفع. والحقيقةُ في الإشعار
 * الموقَّع وحدَه.
 *
 * ثمّ أنّ الطلبَ لا يُكتب قبل الدفع: لو كُتب لَخصم كلُّ زائرٍ فتح صفحةَ
 * البطاقة ثمّ أغلقها باقةً من الرفّ.
 */
class ACardIsNotAnOrderUntilTheMoneyArrivesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gateway(array $over = []): PaymentGateway
    {
        return PaymentGateway::create(array_merge([
            'business_id' => $this->shop->id,
            'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc',
            'secret_key' => 'sk_test_abc',
            'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876',
            'active' => true,
        ], $over));
    }

    private function order(array $over = []): array
    {
        return array_merge([
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'name' => 'سعود الحارثي',
            'phone' => '95259066',
            'fulfil' => 'pickup',
            'date' => '2027-02-12',
            'pay' => 'card',
        ], $over);
    }

    /* ═══════════ البوّابة تُعرض حين تكتمل ═══════════ */

    /** ولا تُعرض البطاقةُ لمن لا بوّابةَ له — زرٌّ يقود إلى لا شيء */
    public function test_no_card_option_without_a_gateway(): void
    {
        $this->assertArrayNotHasKey('card', WebCheckout::payments($this->shop->id));
    }

    /**
     * ولا لمن بوّابتُه ناقصة.
     *
     * زبونٌ يدفع على بوّابةٍ بلا سرِّ توقيعٍ يخرج مالُه ولا يُصدَّق إشعارُه —
     * فلا يُنشأ له طلبٌ أبدًا. والنقصُ أسوأُ من الإطفاء.
     */
    public function test_no_card_option_on_a_half_built_gateway(): void
    {
        $this->gateway(['hmac_secret' => null]);

        $this->assertArrayNotHasKey('card', WebCheckout::payments($this->shop->id));
        $this->assertFalse(Paymob::enabled($this->shop->id));
    }

    /** وتُعرض حين تكتمل الأربعة */
    public function test_the_card_shows_when_the_gateway_is_whole(): void
    {
        $this->gateway();

        $this->assertSame('بطاقة', WebCheckout::payments($this->shop->id)['card'] ?? null);
    }

    /* ═══════════ ولا طلبَ قبل المال ═══════════ */

    /**
     * يُفتح بابُ الدفع ولا يُكتب طلبٌ ولا يُخصم مخزون.
     *
     * ولو كُتب لَخصم كلُّ زائرٍ فتح الصفحةَ ثمّ أغلقها باقةً من الرفّ —
     * فينفد ما هو موجود، ويُردّ زبونٌ حاضرٌ بالنقد عن صنفٍ يملأ الثلّاجة.
     */
    public function test_opening_the_card_page_writes_no_order_and_moves_no_stock(): void
    {
        $this->gateway();
        Http::fake([
            'oman.paymob.com/v1/intention/' => Http::response(['client_secret' => 'csk_test_xyz', 'intention_order_id' => '777'], 201),
        ]);

        $res = $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        $this->assertStringStartsWith('https://oman.paymob.com/unifiedcheckout/', $res->json('redirect'));
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ قبل أن يصل المال');
        $this->assertSame(5, (int) $this->rose->refresh()->quantity, 'خُصم المخزونُ قبل الدفع');

        $intent = StorePaymentIntent::firstOrFail();
        $this->assertSame(StorePaymentIntent::PENDING, $intent->status);
        $this->assertSame('20.000', (string) $intent->amount);
    }

    /** والمبلغُ يُرسَل بالبيسة لا بالريال — ألفٌ في الريال العُمانيّ */
    public function test_the_amount_leaves_in_the_smallest_unit(): void
    {
        $this->gateway();
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);

        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        Http::assertSent(fn ($r) => $r['amount'] === 20000 && $r['currency'] === 'OMR');
    }

    /** وحمولةٌ لا تصحّ تُردّ قبل أن تُفتح لها دفعة — لا بعد أن يدفع */
    public function test_a_broken_order_never_reaches_the_bank(): void
    {
        $this->gateway();
        Http::fake();

        $this->postJson('/s/ribbon/checkout', $this->order(['phone' => '']))->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count());
    }

    /* ═══════════ والإشعارُ هو الحقيقة ═══════════ */

    private function notice(StorePaymentIntent $intent, array $over = []): array
    {
        return array_merge([
            'amount_cents' => 20000,
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

    private function fire(array $obj, ?string $hmac = null): \Illuminate\Testing\TestResponse
    {
        $hmac ??= Paymob::signature($obj, 'hmac_secret_value');

        return $this->postJson('/webhooks/paymob?hmac='.$hmac, ['obj' => $obj]);
    }

    private function intent(): StorePaymentIntent
    {
        $this->gateway();
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);
        $this->postJson('/s/ribbon/checkout', $this->order())->assertOk();

        return StorePaymentIntent::firstOrFail();
    }

    /**
     * ═══ إشعارٌ بتوقيعٍ لا يطابق لا يُنشئ طلبًا ═══
     *
     * وهو البابُ الذي لو فُتح لَصار كلُّ من يعرف مرجعَ طلبٍ يأخذ بضاعةً بلا
     * دفع: يُرسل «نجح» إلى بابنا فيُنشأ الطلبُ ويُخصم المخزون.
     */
    public function test_a_forged_notice_buys_nothing(): void
    {
        $intent = $this->intent();

        $this->fire($this->notice($intent), 'deadbeef')->assertOk();

        $this->assertSame(0, Order::count(), 'أُنشئ طلبٌ بتوقيعٍ مزوَّر');
        $this->assertSame(StorePaymentIntent::PENDING, $intent->refresh()->status);
    }

    /** وحرفٌ واحدٌ يتبدّل في الحمولة يُبطل التوقيع */
    public function test_one_changed_field_breaks_the_signature(): void
    {
        $intent = $this->intent();
        $obj = $this->notice($intent);
        $signed = Paymob::signature($obj, 'hmac_secret_value');

        $this->fire(array_merge($obj, ['amount_cents' => 1]), $signed)->assertOk();

        $this->assertSame(0, Order::count());
    }

    /** والإشعارُ الصحيح يُنشئ الطلبَ مدفوعًا ويخصم مخزونَه */
    public function test_a_signed_notice_writes_the_order(): void
    {
        $intent = $this->intent();

        $this->fire($this->notice($intent))->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('بطاقة', $order->payment_method);
        // والمالُ وصل — فلا يُدان به العميل في الذمم
        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame(4, (int) $this->rose->refresh()->quantity);

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::PAID, $intent->status);
        $this->assertSame((int) $order->id, (int) $intent->order_id);
    }

    /**
     * ولا يُنشأ طلبان لإشعارٍ أُعيد إرسالُه.
     *
     * Paymob تُعيد الإرسال إن تأخّر الجواب. وبلا حارسٍ يُخصم المخزونُ
     * مرّتين ويُقيَّد البيعُ مرّتين عن دفعةٍ واحدة.
     */
    public function test_a_repeated_notice_makes_one_order(): void
    {
        $intent = $this->intent();
        $obj = $this->notice($intent);

        $this->fire($obj)->assertOk();
        $this->fire($obj)->assertOk();
        $this->fire($obj)->assertOk();

        $this->assertSame(1, Order::count(), 'تكرّر الطلبُ بتكرار الإشعار');
        $this->assertSame(4, (int) $this->rose->refresh()->quantity);
    }

    /** ودفعةٌ ردّها البنكُ لا تُنشئ شيئًا */
    public function test_a_declined_payment_writes_nothing(): void
    {
        $intent = $this->intent();

        $this->fire($this->notice($intent, ['success' => false]))->assertOk();

        $this->assertSame(0, Order::count());
        $this->assertSame(StorePaymentIntent::PENDING, $intent->refresh()->status);
    }

    /* ═══════════ وصفحةُ العودة تقرأ ولا تكتب ═══════════ */

    /**
     * يعود الزائرُ قبل الإشعار فيُقال له «ننتظر» — ولا يُدَّعى فشل.
     *
     * وصفحةٌ تقول «فشل» على مالٍ خرج من حسابه أسوأُ من انتظارٍ صادق.
     */
    public function test_coming_back_before_the_notice_says_we_are_waiting(): void
    {
        $intent = $this->intent();

        $this->get('/s/ribbon/paying/'.$intent->reference)
            ->assertOk()
            ->assertSee('rb-paying', false)
            ->assertDontSee('rb-done', false);

        $this->assertSame(0, Order::count(), 'كتبت صفحةُ العودة طلبًا');
    }

    /** وبعد الإشعار تفتح فاتورتَه */
    public function test_coming_back_after_the_notice_shows_the_receipt(): void
    {
        $intent = $this->intent();
        $this->fire($this->notice($intent))->assertOk();

        $this->get('/s/ribbon/paying/'.$intent->reference)
            ->assertOk()
            ->assertSee('rb-order-number', false);
    }

    /** ومرجعٌ لا وجود له يُردّ — ولا يُقال «ننتظر» لمن لم يدفع */
    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->get('/s/ribbon/paying/WEB-9-nothing')->assertNotFound();
    }

    /* ═══════════ والمالُ الضائع يُقال ═══════════ */

    /**
     * دفعةٌ وصلت ونفدت بضاعتُها: تُحفظ ولا تُهمَل.
     *
     * ولا تُحلّ في كود — يردُّ صاحبُ المحلّ المالَ أو يجهّز بديلًا. لكنّ
     * ما لا يُقال له لا يُحلّ أصلًا: لا طلبَ في القائمة، ولا زبونَ يعرف
     * بمن يتّصل، ومالٌ في حسابه لا يقابله شيء.
     */
    public function test_money_that_found_no_order_is_kept_and_told(): void
    {
        $intent = $this->intent();
        // نفد الصنفُ بين لحظةِ الدفع ولحظةِ التصديق
        $this->rose->update(['quantity' => 0]);

        $this->fire($this->notice($intent))->assertOk();

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::PAID, $intent->status);
        $this->assertNull($intent->order_id);
        $this->assertTrue($intent->strayPayment());
        $this->assertNotNull($intent->error, 'ضاع سببُ تعذّر إنشاء الطلب');
    }

    /* ═══════════ والسرُّ لا يخرج ═══════════ */

    /** مفاتيحُ القبض لا تصل المتصفّح — ولو في `toArray` سهوًا */
    public function test_the_secrets_never_leave_the_server(): void
    {
        $json = json_encode($this->gateway()->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('sk_test_abc', (string) $json);
        $this->assertStringNotContainsString('hmac_secret_value', (string) $json);
    }

    /** وفي القاعدة مشفَّران — من نسخها لا ينسخ مفتاحَ القبض */
    public function test_the_secrets_are_encrypted_in_the_column(): void
    {
        $this->gateway();

        $raw = \Illuminate\Support\Facades\DB::table('payment_gateways')->first();

        $this->assertNotSame('sk_test_abc', $raw->secret_key);
        $this->assertNotSame('hmac_secret_value', $raw->hmac_secret);
        $this->assertSame('sk_test_abc', PaymentGateway::firstOrFail()->secret_key);
    }

    /* ═══════════ والتوقيعُ يُوازَن بما وثّقته Paymob ═══════════ */

    /**
     * ═══ ولمَ مثالُهم لا مثالُنا ═══
     *
     * كان الحارسُ يحسب المتوقَّعَ بـ`Paymob::signature` نفسِها ثمّ يوازنه
     * بها — فيقول «توقيعُنا يطابق توقيعَنا»، وهو صحيحٌ دائمًا. ونجا من
     * طفرتين: قُلب ترتيبُ الحقول فلم يسقط، وتُرك المنطقيُّ لـPHP
     * (`(string) false` فراغ) فلم يسقط.
     *
     * والمثالُ الموثَّق في وثائقهم يقطع ذلك: النصُّ الموصولُ مكتوبٌ عندهم
     * بحروفه، فيُحسب عليه HMAC بسرٍّ نعرفه ويُوازَن بما تُخرجه دالّتُنا من
     * الحمولة نفسِها. فترتيبُ الحقول وصورةُ المنطقيّ مشدودان إلى ما ينتظره
     * البنك لا إلى ما نكتبه نحن.
     *
     * وحرفٌ في غير موضعه يجعل كلَّ إشعارٍ صحيحٍ يُردّ — فيُقبض المالُ ولا
     * يُنشأ طلبٌ أبدًا، ولا يظهر العطبُ إلّا من زبونٍ دفع ولم يصله شيء.
     */
    public function test_the_signature_matches_what_paymob_documents(): void
    {
        // حمولةُ المثال في وثائق Paymob — بقيمها كما هي
        $obj = [
            'amount_cents' => 100,
            'created_at' => '2020-03-25T18:39:44.719228',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => 2556706,
            'integration_id' => 6741,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 4778239],
            'owner' => 4705,
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ];

        // والنصُّ الموصولُ كما تكتبه وثائقُهم — منسوخًا لا محسوبًا
        $documented = '1002020-03-25T18:39:44.719228EGPfalsefalse25567066741true'
            .'falsefalsefalsetruefalse47782394705false2346MasterCardcardtrue';

        $this->assertSame(
            hash_hmac('sha512', $documented, 'a-known-secret'),
            Paymob::signature($obj, 'a-known-secret'),
            'وصلُ الحقول لا يوافق ما توقّعه Paymob — فكلُّ إشعارٍ صحيحٍ سيُردّ',
        );
    }

    /** وعشرون حقلًا لا تسعةَ عشر — حقلٌ يسقط من القائمة يكسر كلّ توقيع */
    public function test_the_signature_reads_twenty_fields(): void
    {
        $this->assertCount(20, Paymob::HMAC_FIELDS);
    }
}

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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Paymob لمتجر سعود وحده — بقائمةٍ في المستودع لا بالمصادفة.
 *
 * ═══ ما كان قبلها ═══
 *
 * البطاقةُ تُعرض لكلّ متجرٍ اكتملت مفاتيحُه، وبابُ حفظ المفاتيح مفتوحٌ لكلّ
 * متجر. وكان «سعود وحده» صحيحًا لأنّه وحده يلبس واجهةً فيها سلّة — فمتجرٌ
 * يُعطى واجهةً غدًا يرث بابَ المال بلا قرار.
 *
 * ═══ وما يُحرس هنا ═══
 *
 * متجرٌ ثانٍ **بالواجهة نفسِها وبمفاتيحَ مكتملة** لا يُعرض له الدفعُ
 * بالبطاقة، ولا تُفتح له دفعة، ولا تُحفظ مفاتيحُه، ولا تُرسم له البطاقة.
 * وما فُتح من دفعاتٍ قبل أن يُرفع متجرٌ من القائمة يُصدَّق إشعارُه ويُكتب
 * طلبُه ويُردّ مالُه إن لزم — فالقائمةُ بابٌ لبدء الدفع لا لإتمامه.
 */
class PaymobIsOpenedForTheOwnersListOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Business $saud;

    private Business $other;

    private User $saudOwner;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->saud = $this->shop('RIBBON', 'ribbon', 'saud@abaad.om');
        $this->other = $this->shop('BLOOM', 'bloom', 'bloom@abaad.om');
        $this->saudOwner = User::where('business_id', $this->saud->id)->firstOrFail();
        $this->otherOwner = User::where('business_id', $this->other->id)->firstOrFail();

        // القائمةُ في الإنتاج `[5]` — وفي الاختبار متجرُ سعود بمعرّفه هنا
        config(['storefront.paymob_businesses' => [$this->saud->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** متجرٌ بواجهةٍ خاصّةٍ فيها سلّة — المتجران متطابقان إلّا في القائمة */
    private function shop(string $name, string $slug, string $email, ?int $id = null): Business
    {
        $shop = (new Business)->forceFill(array_filter([
            'id' => $id,
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ], fn ($v) => $v !== null));
        $shop->save();
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $shop;
    }

    /** بوّابةٌ مكتملةٌ مفعّلة — كلُّ ما يلزم ليقبل متجرٌ البطاقة لولا القائمة */
    private function gateway(Business $shop): PaymentGateway
    {
        return PaymentGateway::create([
            'business_id' => $shop->id,
            'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc',
            'secret_key' => 'sk_test_abc',
            'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876',
            'active' => true,
        ]);
    }

    private function order(Business $shop, string $pay = 'card'): array
    {
        return [
            'items' => [['id' => Product::where('business_id', $shop->id)->value('id'), 'qty' => 1]],
            'name' => 'زبون', 'phone' => '95259066', 'fulfil' => 'pickup',
            'date' => '2027-02-12', 'pay' => $pay,
        ];
    }

    private function keys(array $over = []): array
    {
        return array_merge([
            'active' => true,
            'public_key' => 'pk_live_new',
            'card_integration_id' => '111222',
            'secret_key' => 'sk_live_new',
            'hmac_secret' => 'hmac_new',
        ], $over);
    }

    /* ═══════════════ ١ · القائمة ═══════════════ */

    public function test_the_list_in_the_repository_is_saud_alone(): void
    {
        // القيمةُ كما كُتبت في الملفّ — لا كما ضبطها هذا الاختبار
        $config = require base_path('config/storefront.php');

        $this->assertSame([5], $config['paymob_businesses']);
    }

    public function test_only_a_listed_shop_is_allowed(): void
    {
        $this->assertTrue(Paymob::allowed($this->saud->id));
        $this->assertFalse(Paymob::allowed($this->other->id));
        $this->assertFalse(Paymob::allowed(999999));

        // والقائمةُ الفارغة تُغلق الجميع
        config(['storefront.paymob_businesses' => []]);
        $this->assertFalse(Paymob::allowed($this->saud->id));
    }

    public function test_an_id_written_as_text_in_the_list_still_reads(): void
    {
        config(['storefront.paymob_businesses' => [(string) $this->saud->id]]);

        $this->assertTrue(Paymob::allowed($this->saud->id));
        $this->assertFalse(Paymob::allowed($this->other->id));
    }

    /* ═══════════════ ٢ · الزبون ═══════════════ */

    public function test_the_listed_shop_offers_the_card_when_its_keys_are_whole(): void
    {
        $this->gateway($this->saud);

        $this->assertTrue(Paymob::enabled($this->saud->id));
        $this->assertSame('بطاقة', WebCheckout::payments($this->saud->id)['card'] ?? null);
    }

    public function test_the_listed_shop_still_needs_whole_keys(): void
    {
        // القائمةُ بابٌ قبل البوّابة لا بديلٌ عنها
        $this->assertFalse(Paymob::enabled($this->saud->id));
        $this->assertArrayNotHasKey('card', WebCheckout::payments($this->saud->id));
    }

    public function test_another_shop_with_whole_keys_offers_no_card(): void
    {
        $this->gateway($this->other);

        $this->assertFalse(Paymob::enabled($this->other->id));
        $this->assertArrayNotHasKey('card', WebCheckout::payments($this->other->id));
        // ويبقى له ما كان: الدفعُ عند الاستلام
        $this->assertArrayHasKey('cod', WebCheckout::payments($this->other->id));
    }

    public function test_another_shop_cannot_open_a_card_payment_from_its_checkout(): void
    {
        $this->gateway($this->other);
        Http::fake();

        $this->postJson('/s/bloom/checkout', $this->order($this->other))->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count());
        $this->assertSame(0, Order::count());
    }

    public function test_and_it_still_sells_cash_on_delivery(): void
    {
        $this->gateway($this->other);

        $this->postJson('/s/bloom/checkout', $this->order($this->other, 'cod'))->assertOk();

        $this->assertSame(1, Order::where('business_id', $this->other->id)->count());
    }

    public function test_the_listed_shop_opens_its_card_payment(): void
    {
        $this->gateway($this->saud);
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x'], 201)]);

        $res = $this->postJson('/s/ribbon/checkout', $this->order($this->saud))->assertOk();

        $this->assertStringStartsWith('https://oman.paymob.com/unifiedcheckout/', $res->json('redirect'));
        $this->assertSame(1, StorePaymentIntent::where('business_id', $this->saud->id)->count());
    }

    public function test_opening_a_payment_directly_for_another_shop_is_refused(): void
    {
        $this->gateway($this->other);
        Http::fake();

        try {
            Paymob::open($this->other, $this->order($this->other), ['total' => 20, 'lines' => []]);
            $this->fail('فُتحت دفعةٌ لمتجرٍ ليس في القائمة');
        } catch (\RuntimeException) {
            // المتوقَّع
        }

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count());
    }

    /* ═══════════════ ٣ · صاحب المتجر ═══════════════ */

    public function test_another_shop_cannot_save_paymob_keys(): void
    {
        $this->actingAs($this->otherOwner)
            ->post(route('admin.marketing.store.gateway'), $this->keys())
            ->assertForbidden();

        $this->assertSame(0, PaymentGateway::count(), 'كُتبت مفاتيحُ متجرٍ ليس في القائمة');
    }

    public function test_the_refusal_comes_before_any_write_even_to_an_old_row(): void
    {
        $old = $this->gateway($this->other);

        $this->actingAs($this->otherOwner)
            ->post(route('admin.marketing.store.gateway'), $this->keys(['active' => false, 'public_key' => 'pk_changed']))
            ->assertForbidden();

        $fresh = $old->fresh();
        $this->assertSame('pk_test_abc', $fresh->public_key);
        $this->assertTrue($fresh->active);
        $this->assertSame('sk_test_abc', $fresh->secret_key);
    }

    public function test_the_listed_shop_saves_its_keys(): void
    {
        $this->actingAs($this->saudOwner)
            ->post(route('admin.marketing.store.gateway'), $this->keys())
            ->assertRedirect();

        $row = PaymentGateway::where('business_id', $this->saud->id)->firstOrFail();
        $this->assertSame('pk_live_new', $row->public_key);
        $this->assertSame('sk_live_new', $row->secret_key);
        $this->assertTrue($row->ready());
        $this->assertTrue(Paymob::enabled($this->saud->id));
    }

    public function test_the_store_screen_draws_the_card_for_the_listed_shop_only(): void
    {
        $this->gateway($this->saud);
        $this->gateway($this->other);

        $saud = $this->actingAs($this->saudOwner)->get(route('admin.website.shop'))->assertOk()->viewData('page')['props']['gateway'];
        $other = $this->actingAs($this->otherOwner)->get(route('admin.website.shop'))->assertOk()->viewData('page')['props']['gateway'];

        $this->assertTrue($saud['allowed']);
        $this->assertTrue($saud['ready']);
        $this->assertFalse($other['allowed']);
        // «جاهزة» لا تُقال لمن لا يقبل البطاقة فعلًا
        $this->assertFalse($other['ready']);
        // والسرّان لا يخرجان لأحد
        $this->assertArrayNotHasKey('secret_key', $saud);
        $this->assertArrayNotHasKey('hmac_secret', $saud);
    }

    public function test_the_settings_screen_tells_the_same_story(): void
    {
        $this->gateway($this->other);

        $store = $this->actingAs($this->otherOwner)->get(route('admin.settings.index', ['section' => 'website']))
            ->assertOk()->viewData('page')['props']['store'];

        $this->assertFalse($store['gateway']['allowed']);
        $this->assertFalse($store['gateway']['ready']);
    }

    public function test_the_general_card_does_not_list_card_payments_for_another_shop(): void
    {
        $this->gateway($this->other);

        $cards = $this->actingAs($this->otherOwner)->get(route('admin.website.site'))
            ->assertOk()->viewData('page')['props']['cards'];

        $this->assertNotContains('بطاقة', $cards['ways']);
        $this->assertFalse($cards['gatewayReady']);
    }

    /* ═══════════════ ٤ · ولا يضيع مالٌ قُبض ═══════════════ */

    /**
     * متجرُ سعود **برقمه على الإنتاج** (٥)، والقائمةُ كما كُتبت في الملفّ.
     *
     * لا يُضبط فيه `paymob_businesses` بيد الاختبار: ما يمرّ هنا هو ما يمرّ
     * يومَ يُنشر. والرقمُ حرٌّ في كلّ تشغيل — القاعدةُ تُفرَّغ بين الاختبارات،
     * ومتجرا `setUp` يأخذان أوّلَ المعرّفات.
     */
    private function five(bool $cod = true): Business
    {
        config(['storefront.paymob_businesses' => (require base_path('config/storefront.php'))['paymob_businesses']]);
        $this->assertNull(Business::find(5), 'المعرّفُ ٥ مأخوذٌ قبل أن يُنشأ متجرُ سعود');

        $five = $this->shop('RIBBON', 'ribbon5', 'five@abaad.om', id: 5);
        $this->gateway($five);

        if (! $cod) {
            // البطاقةُ وحدَها: لا نقدَ ولا تحويلَ يُبقي المتجرَ «يستقبل طلبات» بعد الرفع
            MarketingSettings::save($five->id, 'website', ['store_on' => '1', 'store_pay_cod' => '0', 'store_pay_transfer' => '0']);
        }

        return $five;
    }

    /** يفتح دفعةً من صفحة المتجر كما يفتحها الزبون — ويردّ نيّتَها */
    private function openCard(Business $shop): StorePaymentIntent
    {
        Http::fake([
            'oman.paymob.com/v1/intention/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => 777], 201),
            'oman.paymob.com/api/acceptance/void_refund/refund' => Http::response(['id' => 555001], 200),
        ]);

        $res = $this->postJson('/s/'.$shop->site_slug.'/checkout', $this->order($shop))->assertOk();
        $this->assertStringStartsWith('https://oman.paymob.com/unifiedcheckout/', $res->json('redirect'));

        return StorePaymentIntent::where('business_id', $shop->id)->latest('id')->firstOrFail();
    }

    /** إشعارٌ موقَّعٌ بسرّ المتجر كما ترسله Paymob — والتوقيعُ صحيحٌ دائمًا هنا */
    private function notify(StorePaymentIntent $intent, array $over = []): void
    {
        $obj = array_replace_recursive([
            'amount_cents' => (int) round(((float) $intent->amount) * 1000), 'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR', 'error_occured' => false, 'has_parent_transaction' => false, 'id' => 998877,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference], 'owner' => 4705,
            'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true,
        ], $over);

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();
    }

    /* ── أ · متجرُ سعود يفتح دفعتَه بالقائمة كما في الملفّ ── */

    public function test_business_five_under_the_repository_list_opens_a_card_payment(): void
    {
        $five = $this->five();

        $this->assertTrue(Paymob::allowed(5));
        $this->assertTrue(Paymob::enabled(5));

        $intent = $this->openCard($five);

        $this->assertSame(5, (int) $intent->business_id);
        $this->assertSame(StorePaymentIntent::PENDING, $intent->status);
        $this->assertSame(20.0, (float) $intent->amount);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/intention/'));
    }

    /* ── ب · فُتحت والمتجرُ مسموح، ثمّ رُفع قبل الإشعار ── */

    /**
     * الزبونُ على صفحة البنك، والقائمةُ تُفرَّغ، ثمّ يصل الإشعارُ موقَّعًا.
     *
     * المالُ قُبض والتوقيعُ صحيح — فيُكتب الطلبُ ويُربط بنيّته كما لو لم
     * يتبدّل شيء. وإشعارٌ مكرَّرٌ لا يكتب طلبًا ثانيًا.
     */
    public function test_a_payment_opened_while_allowed_becomes_an_order_after_removal(): void
    {
        $five = $this->five();
        $intent = $this->openCard($five);

        config(['storefront.paymob_businesses' => []]);
        $this->assertFalse(Paymob::allowed(5));

        $this->notify($intent);

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::PAID, $intent->status, 'إشعارٌ صادقٌ قُرئ مزوَّرًا بعد الرفع');
        $this->assertSame('998877', (string) $intent->provider_transaction_id);
        $this->assertNull($intent->error, 'دُفع ولم يُنشأ طلب: '.$intent->error);
        $this->assertFalse($intent->strayPayment());

        $order = Order::where('business_id', 5)->sole();
        $this->assertSame($order->id, (int) $intent->order_id);
        $this->assertSame('بطاقة', $order->payment_method);
        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame(20.0, (float) $order->total);

        // وPaymob تُعيد الإرسال — فلا طلبَ ثانٍ ولا خصمَ ثانٍ
        $this->notify($intent);
        $this->assertSame(1, Order::where('business_id', 5)->count());
        $this->assertSame(4.0, (float) Product::where('business_id', 5)->value('quantity'));
    }

    /**
     * والمتجرُ الذي لا يقبل إلّا البطاقة: بعد الرفع لا وسيلةَ دفعٍ له لطلبٍ جديد
     * — فـ«لا يستقبل طلبات» — والدفعةُ التي قُبضت قبلها يُكتب طلبُها مع ذلك.
     */
    public function test_a_card_only_shop_still_completes_the_paid_order_after_removal(): void
    {
        $five = $this->five(cod: false);
        $intent = $this->openCard($five);

        config(['storefront.paymob_businesses' => []]);
        $this->assertFalse(WebCheckout::accepts($five), 'متجرٌ رُفع من القائمة بلا وسيلةٍ أخرى ما زال يستقبل طلبات');

        $this->notify($intent);

        $intent->refresh();
        $this->assertNull($intent->error, 'دُفع ولم يُنشأ طلب: '.$intent->error);
        $this->assertSame(Order::where('business_id', 5)->sole()->id, (int) $intent->order_id);
    }

    /* ── ج · وبعد الرفع لا تُفتح دفعةٌ جديدة ── */

    public function test_after_removal_no_new_card_payment_opens(): void
    {
        $five = $this->five();
        $this->openCard($five);
        config(['storefront.paymob_businesses' => []]);

        $this->assertArrayNotHasKey('card', WebCheckout::payments(5));

        Http::fake();
        $this->postJson('/s/ribbon5/checkout', $this->order($five))->assertStatus(422);

        try {
            Paymob::open($five, $this->order($five), ['total' => 20, 'lines' => []]);
            $this->fail('فُتحت دفعةٌ لمتجرٍ رُفع من القائمة');
        } catch (\RuntimeException) {
            // المتوقَّع
        }

        Http::assertNothingSent();
        $this->assertSame(1, StorePaymentIntent::where('business_id', 5)->count(), 'نيّةٌ جديدةٌ بعد الرفع');
        $this->assertSame(0, Order::count());
    }

    /* ── د · ومتجرٌ لم يُسمح له قطّ لا يصنع طلبَ بطاقةٍ بيده ── */

    /**
     * `place` تقبل البطاقةَ لنيّةٍ قُبض مالُها فعلًا — فكلُّ ما دون ذلك يُردّ:
     * طلبٌ بلا نيّة، ونيّةٌ لم تُدفع، ونيّةٌ بلا رقم عمليّة، ونيّةُ متجرٍ آخر،
     * ونيّةٌ لم تُحفظ، ونيّةٌ صحيحةٌ بلا `paid`.
     */
    public function test_another_shop_cannot_make_a_card_order_by_hand(): void
    {
        $this->gateway($this->other);
        $this->gateway($this->saud);
        $order = $this->order($this->other);

        $intent = fn (array $over) => StorePaymentIntent::create(array_merge([
            'business_id' => $this->other->id, 'reference' => 'web-x-'.uniqid(), 'payload' => $order,
            'lang' => 'ar', 'amount' => 20, 'currency' => 'OMR', 'status' => StorePaymentIntent::PAID,
            'provider_transaction_id' => (string) random_int(1, 999999), 'expires_at' => now()->addHour(),
        ], $over));

        $saudPaid = $intent(['business_id' => $this->saud->id]);

        $attempts = [
            'بلا نيّة' => fn () => WebCheckout::place($this->other, $order),
            'بلا نيّة ومدفوع' => fn () => WebCheckout::place($this->other, $order, paid: true),
            'نيّةٌ معلَّقة' => fn () => WebCheckout::place($this->other, $order, paid: true, intent: $intent(['status' => StorePaymentIntent::PENDING])),
            'مدفوعةٌ بلا رقم عمليّة' => fn () => WebCheckout::place($this->other, $order, paid: true, intent: $intent(['provider_transaction_id' => null])),
            'نيّةُ متجرٍ آخر' => fn () => WebCheckout::place($this->other, $order, paid: true, intent: $saudPaid),
            'نيّةٌ لم تُحفظ' => fn () => WebCheckout::place($this->other, $order, paid: true, intent: new StorePaymentIntent([
                'business_id' => $this->other->id, 'status' => StorePaymentIntent::PAID, 'provider_transaction_id' => '1',
            ])),
            'نيّةٌ مدفوعةٌ بلا paid' => fn () => WebCheckout::place($this->other, $order, intent: $intent([])),
        ];

        foreach ($attempts as $what => $try) {
            try {
                $try();
                $this->fail('كُتب طلبُ بطاقةٍ يدويًّا لمتجرٍ ليس في القائمة: '.$what);
            } catch (ValidationException) {
                // المتوقَّع
            }
        }

        $this->assertSame(0, Order::count());
        $this->assertArrayNotHasKey('card', WebCheckout::payments($this->other->id));
    }

    /* ── هـ · وردُّ المال لا تُعطّله القائمة ── */

    public function test_an_old_payment_is_still_refunded_after_removal(): void
    {
        config(['storefront.paymob_auto_refund' => true]);
        $five = $this->five();
        $intent = $this->openCard($five);
        $this->notify($intent);

        config(['storefront.paymob_businesses' => []]);

        $this->assertTrue(Paymob::refund($intent->refresh(), 'ردٌّ بعد الرفع'));

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::REFUND_SENT, $intent->refund_status);
        $this->assertSame('555001', (string) $intent->provider_refund_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/void_refund/refund') && $r['transaction_id'] === 998877);
    }

    /**
     * سعرُ الصنف تبدّل والزبونُ على صفحة البنك، والمتجرُ رُفع من القائمة معًا.
     *
     * الإتمامُ يصل إلى مقارنة المبلغ — لا يُردّ قبلها بـ«البطاقة غير مقبولة»
     * — فيُعلَّق المالُ للردّ كما يُعلَّق لأيّ متجرٍ تبدّل سعرُه.
     */
    public function test_a_price_moved_after_removal_still_reaches_the_refund(): void
    {
        $five = $this->five();
        $intent = $this->openCard($five);

        config(['storefront.paymob_businesses' => []]);
        Product::where('business_id', 5)->update(['price' => 25]);

        $this->notify($intent);

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::PAID, $intent->status);
        $this->assertSame(0, Order::count());
        $this->assertSame(StorePaymentIntent::REFUND_PENDING, $intent->refund_status, 'المالُ لم يُعلَّق للردّ: '.$intent->error);
    }

    public function test_a_refund_confirmed_by_paymob_after_removal_is_recorded(): void
    {
        $five = $this->five();
        $intent = $this->openCard($five);
        $this->notify($intent);

        config(['storefront.paymob_businesses' => []]);

        // ردّه صاحبُ المحلّ من لوحة Paymob — فيصل إشعارُ الاسترجاع موقَّعًا
        $this->notify($intent->refresh(), ['id' => 998878, 'is_refunded' => true]);

        $this->assertSame(StorePaymentIntent::REFUND_SENT, $intent->refresh()->refund_status);
    }
}

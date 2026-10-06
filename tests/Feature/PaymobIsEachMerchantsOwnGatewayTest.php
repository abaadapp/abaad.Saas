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
use App\Support\Integrations;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\PaymentMethods;
use App\Support\Store\EnglishCheckout;
use App\Support\Store\Paymob;
use App\Support\Store\PaymobSettings;
use App\Support\Store\WebCheckout;
use App\Support\Website\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Paymob بوّابةُ كلّ تاجرٍ بحسابه — تُربط من «التطبيقات التكاملية» وتُستعمل حيث في الموقع سلّة.
 *
 * ═══ ما كان ═══
 *
 * قائمةٌ بمعرّفات المتاجر (`storefront.paymob_businesses = [5]`) تفتح Paymob
 * لسعود وحده، ومفاتيحُه تُحرَّر في شاشتين من شاشات الموقع، ورقمُ تكاملٍ واحدٌ
 * للبطاقة يُرسَل في `payment_methods`. وفي دليل التكاملات بطاقةُ AmwalPay
 * لم تُبنَ.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - كلُّ متجرٍ يربط حسابَه — بلا قائمةٍ ولا معرّف.
 *   - والدفعُ على الموقع يلزمه سلّةٌ حقيقيّة (`Commerce::checkout`) وبوّابةٌ جاهزة.
 *   - و`payment_methods` من صفّ المتجر نفسِه: البطاقةُ ثمّ Apple Pay إن كُتب —
 *     ولا يعبر رقمُ متجرٍ إلى آخر.
 *   - والسرّان لا يخرجان إلى الشاشة، والفارغُ لا يمحوهما.
 *   - وشاشاتُ الموقع تعرض الحالَ وتُحيل إلى بيت المفاتيح ولا تحرّرها.
 *   - والإشعارُ الموقَّع وحده يكتب الطلب — ولو تبدّل الإعدادُ بعد فتح الدفعة.
 *
 * والمعرّفاتُ اصطناعيّة (١١١ و٢٢٢ و٣٣٣ و٤٤٤): لا رقمَ تكاملٍ حقيقيّ ولا
 * مفتاحَ حقيقيّ في هذا الملفّ.
 */
class PaymobIsEachMerchantsOwnGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    /** متجرٌ بلا سلّة — موقعُه واتساب */
    private Business $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        // معرّفاتٌ صريحة: تسلسلُ PostgreSQL لا يرجع بين الاختبارات، فقد يقع
        // أحدُها على ٥ — والمعرّفُ ٥ لاختبار متجر سعود وحده
        $this->a = $this->shop('BLOOM A', 'bloom-a', 'a@shop.test', id: 101);
        $this->b = $this->shop('BLOOM B', 'bloom-b', 'b@shop.test', id: 102);
        $this->plain = $this->shop('PLAIN', 'plain', 'plain@shop.test', themed: false, id: 103);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function shop(string $name, string $slug, string $email, bool $themed = true, ?int $id = null): Business
    {
        $shop = (new Business)->forceFill(array_filter([
            'id' => $id,
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000000',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold',
            'storefront_theme' => $themed ? 'ribbon' : null,
        ], fn ($v) => $v !== null));
        $shop->save();
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $shop;
    }

    private function owner(Business $shop): User
    {
        return User::where('business_id', $shop->id)->firstOrFail();
    }

    private function gateway(Business $shop, string $card, ?string $apple = null, string $hmac = 'hmac-default', string $secret = 'secret-default', ?string $omannet = null): PaymentGateway
    {
        return PaymentGateway::create([
            'business_id' => $shop->id,
            'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'public-'.$shop->id,
            'secret_key' => $secret,
            'hmac_secret' => $hmac,
            'card_integration_id' => $card,
            'omannet_integration_id' => $omannet,
            'apple_pay_integration_id' => $apple,
            'active' => true,
        ]);
    }

    private function order(Business $shop, string $pay = 'card'): array
    {
        return [
            'items' => [['id' => Product::where('business_id', $shop->id)->value('id'), 'qty' => 1]],
            // ومتجرُ سعود (المعرّف ٥) يأخذ الاسمَ بالإنجليزيّة — انظر `EnglishCheckout`
            'name' => EnglishCheckout::on((int) $shop->id) ? 'Test Customer' : 'زبون تجربة',
            'phone' => '95259066', 'fulfil' => 'pickup',
            'date' => '2027-02-12', 'pay' => $pay,
        ];
    }

    private function fakePaymob(): void
    {
        Http::fake([
            'oman.paymob.com/v1/intention/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => 777], 201),
            'oman.paymob.com/api/acceptance/void_refund/refund' => Http::response(['id' => 555001], 200),
        ]);
    }

    /** يفتح دفعةً من صفحة المتجر كما يفتحها الزبون — ويردّ نيّتَها */
    private function openCard(Business $shop): StorePaymentIntent
    {
        $this->fakePaymob();

        $res = $this->postJson('/s/'.$shop->site_slug.'/checkout', $this->order($shop))->assertOk();
        $this->assertStringStartsWith('https://oman.paymob.com/unifiedcheckout/', (string) $res->json('redirect'));

        return StorePaymentIntent::where('business_id', $shop->id)->latest('id')->firstOrFail();
    }

    /** طلبُ الفتح كما خرج إلى Paymob */
    private function intention(): Sent
    {
        $sent = Http::recorded(fn (Sent $r) => str_contains($r->url(), '/v1/intention/'));
        $this->assertCount(1, $sent);

        return $sent[0][0];
    }

    /** إشعارٌ موقَّعٌ بسرٍّ يُعطى — كما ترسله Paymob */
    private function notify(StorePaymentIntent $intent, string $hmac, array $over = [])
    {
        $obj = array_replace_recursive([
            'amount_cents' => (int) round(((float) $intent->amount) * 1000), 'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR', 'error_occured' => false, 'has_parent_transaction' => false, 'id' => 998877,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference], 'owner' => 4705,
            'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true,
        ], $over);

        return $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, $hmac), ['obj' => $obj])->assertOk();
    }

    /* ═══════════════ ١ · لا قائمةَ بالمعرّفات ═══════════════ */

    public function test_there_is_no_business_id_list_for_paymob_anymore(): void
    {
        $config = require base_path('config/storefront.php');

        $this->assertArrayNotHasKey('paymob_businesses', $config);
        $this->assertFalse(method_exists(Paymob::class, 'allowed'));
        $this->assertStringNotContainsString('paymob_businesses', file_get_contents(app_path('Support/Store/Paymob.php')));
    }

    /**
     * متجرُ سعود برقمه (٥) لا يصير خاصًّا برقمه: يدفع زبونُه لأنّ في واجهته
     * سلّةً وبوّابتَه جاهزة — ولا Apple Pay حتّى يُكتب رقمُه الحيّ بيده.
     */
    public function test_a_shop_numbered_five_pays_because_it_has_a_cart_and_a_whole_gateway(): void
    {
        $this->assertNull(Business::find(5), 'المعرّفُ ٥ مأخوذٌ قبل أن يُنشأ');
        $five = $this->shop('RIBBON', 'ribbon5', 'five@shop.test', id: 5);
        $this->gateway($five, '111');

        $this->assertTrue(Paymob::enabled(5));
        $this->openCard($five);

        $this->assertSame([111], $this->intention()['payment_methods']);
    }

    /* ═══════════════ ٢ · أرقامُ التكامل ═══════════════ */

    public function test_payment_method_ids_are_card_then_apple_pay_and_nothing_else(): void
    {
        $g = new PaymentGateway;

        $cases = [
            [['111', null], [111]],
            [['111', '222'], [111, 222]],
            [[' 111 ', ' 222 '], [111, 222]],
            [['111', ''], [111]],
            [['111', '0'], [111]],
            [['111', 'abc'], [111]],
            // ورقمٌ خالطه حرفٌ لا يُقصّ إلى أوّله: «22x» ليس ٢٢
            [['111', '22x'], [111]],
            [['111', '111'], [111]],
            [['', '222'], [222]],
            [[null, null], []],
        ];

        foreach ($cases as [[$card, $apple], $want]) {
            $g->card_integration_id = $card;
            $g->apple_pay_integration_id = $apple;
            $this->assertSame($want, $g->paymentMethodIds(), json_encode([$card, $apple]));
        }
    }

    public function test_apple_pay_is_optional_for_a_ready_gateway(): void
    {
        $this->assertTrue($this->gateway($this->a, '111')->ready());
        $this->assertTrue(Paymob::enabled($this->a->id));
    }

    /* ═══════════════ ٣ · طلبُ الفتح ═══════════════ */

    public function test_a_card_only_shop_sends_exactly_its_card_id(): void
    {
        $this->gateway($this->a, '111');
        $intent = $this->openCard($this->a);

        $sent = $this->intention();
        $this->assertSame([111], $sent['payment_methods']);

        // وما سواه كما كان
        $this->assertSame(20000, $sent['amount']);
        $this->assertSame('OMR', $sent['currency']);
        $this->assertSame([['name' => 'باقة ورد', 'amount' => 20000, 'quantity' => 1]], $sent['items']);
        $this->assertSame('95259066', $sent['billing_data']['phone_number']);
        $this->assertSame($intent->reference, $sent['special_reference']);
        $this->assertSame(Paymob::EXPIRES, $sent['expiration']);
        $this->assertSame(rtrim(config('app.url'), '/').'/webhooks/paymob', $sent['notification_url']);
        $this->assertStringEndsWith('/paying/'.$intent->reference, $sent['redirection_url']);
        $this->assertSame('Token secret-default', $sent->header('Authorization')[0]);
    }

    public function test_a_shop_with_apple_pay_sends_card_then_apple_pay(): void
    {
        $this->gateway($this->a, '111', '222');
        $this->openCard($this->a);

        $this->assertSame([111, 222], $this->intention()['payment_methods']);
    }

    /**
     * متجران بحسابين — ولا يعبر رقمٌ ولا سرٌّ من أحدهما إلى الآخر.
     */
    public function test_two_merchants_each_send_their_own_ids_and_their_own_secret(): void
    {
        $this->gateway($this->a, '111', '222', 'hmac-a', 'secret-a');
        $this->gateway($this->b, '333', '444', 'hmac-b', 'secret-b');

        $this->openCard($this->a);
        $a = $this->intention();
        $this->assertSame([111, 222], $a['payment_methods']);
        $this->assertSame('Token secret-a', $a->header('Authorization')[0]);

        $this->openCard($this->b);
        $b = $this->intention();
        $this->assertSame([333, 444], $b['payment_methods']);
        $this->assertSame('Token secret-b', $b->header('Authorization')[0]);

        $this->assertEmpty(array_intersect([333, 444], $a['payment_methods']));
        $this->assertEmpty(array_intersect([111, 222], $b['payment_methods']));
    }

    public function test_the_browser_cannot_choose_the_integration_ids(): void
    {
        $this->gateway($this->a, '111');
        $this->fakePaymob();

        $this->postJson('/s/bloom-a/checkout', $this->order($this->a) + [
            'payment_methods' => [999], 'apple_pay_integration_id' => '999', 'integration_id' => 999,
        ])->assertOk();

        $this->assertSame([111], $this->intention()['payment_methods']);
    }

    public function test_paymob_refusing_the_intention_is_a_safe_failure(): void
    {
        // مفاتيحُ تجريبيّةٌ مع رقم Apple Pay حيّ — Paymob تردّ، ولا طلبَ ولا دفعة
        $this->gateway($this->a, '111', '222');
        Http::fake(['oman.paymob.com/v1/intention/*' => Http::response(['detail' => 'Integration ID does not exist'], 400)]);

        $this->postJson('/s/bloom-a/checkout', $this->order($this->a))->assertStatus(422);

        $intent = StorePaymentIntent::where('business_id', $this->a->id)->sole();
        $this->assertSame(StorePaymentIntent::FAILED, $intent->status);
        $this->assertStringContainsString('Integration ID does not exist', (string) $intent->error);
        $this->assertSame(0, Order::count());
    }

    /* ═══════════════ ٤ · الربطُ غيرُ الاستعمال ═══════════════ */

    public function test_the_integration_status_reads_this_shops_row_alone(): void
    {
        $status = fn (Business $shop) => Integrations::status(Integrations::PAYMOB, $shop)['state'];

        $this->assertSame('off', $status($this->a));

        PaymentGateway::create(['business_id' => $this->a->id, 'provider' => PaymentGateway::PAYMOB, 'active' => false]);
        $this->assertSame('off', $status($this->a), 'صفٌّ فارغٌ يُقرأ ربطًا');

        PaymentGateway::where('business_id', $this->a->id)->update(['public_key' => 'public-a']);
        $this->assertSame('partial', $status($this->a));

        PaymentGateway::where('business_id', $this->a->id)->delete();
        $g = $this->gateway($this->a, '111');
        $this->assertSame('ready', $status($this->a));

        $g->update(['active' => false]);
        $this->assertSame('partial', $status($this->a), 'مطفأةٌ تُقرأ جاهزة');

        // والجارُ لا يتأثّر
        $this->assertSame('off', $status($this->b));
    }

    public function test_a_shop_without_a_cart_may_be_connected_but_takes_no_card_online(): void
    {
        $this->gateway($this->plain, '111', '222');

        $this->assertFalse(Commerce::checkout($this->plain->id));
        $this->assertSame('ready', Integrations::status(Integrations::PAYMOB, $this->plain)['state']);
        $this->assertSame([PaymentMethods::CARD], Commerce::gateways($this->plain->id));

        $this->assertFalse(Paymob::enabled($this->plain->id));
        $this->assertArrayNotHasKey(WebCheckout::PAY_CARD, WebCheckout::payments($this->plain->id));

        $card = collect(Commerce::payments($this->plain->id))->firstWhere('label', __(PaymentMethods::CARD));
        $this->assertFalse($card['online']);

        $summary = PaymobSettings::summary($this->plain->id);
        $this->assertSame('ready', $summary['state']);
        $this->assertFalse($summary['online']);

        Http::fake();
        try {
            Paymob::open($this->plain, $this->order($this->plain), ['total' => 20, 'lines' => []]);
            $this->fail('فُتحت دفعةٌ لموقعٍ بلا سلّة');
        } catch (\RuntimeException) {
            // المتوقَّع
        }
        Http::assertNothingSent();
    }

    public function test_a_shop_with_a_cart_and_a_whole_gateway_takes_the_card_online(): void
    {
        $this->gateway($this->a, '111');

        $this->assertTrue(Commerce::checkout($this->a->id));
        $this->assertTrue(Paymob::enabled($this->a->id));
        $this->assertSame(PaymentMethods::CARD, WebCheckout::payments($this->a->id)[WebCheckout::PAY_CARD]);
        $this->assertTrue(PaymobSettings::summary($this->a->id)['online']);
    }

    public function test_a_half_built_gateway_takes_no_card_even_with_a_cart(): void
    {
        PaymentGateway::create([
            'business_id' => $this->a->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'public-a', 'card_integration_id' => '111', 'active' => true,
        ]);

        $this->assertFalse(Paymob::enabled($this->a->id));
        $this->assertSame([], Commerce::gateways($this->a->id));
        $this->assertSame('partial', PaymobSettings::state($this->a->id));
    }

    /* ═══════════════ ٥ · بيتُ المفاتيح ═══════════════ */

    private function keys(array $over = []): array
    {
        return array_merge([
            'active' => true,
            'public_key' => 'public-new',
            'card_integration_id' => '111',
            'apple_pay_integration_id' => '222',
            'secret_key' => 'secret-new',
            'hmac_secret' => 'hmac-new',
        ], $over);
    }

    public function test_every_merchant_can_open_and_save_its_paymob_page_without_a_list(): void
    {
        // متجرٌ بلا سلّةٍ وليس سعود — يربط حسابَه
        $this->actingAs($this->owner($this->plain))
            ->get(route('admin.integrations.paymob'))->assertOk();

        $this->actingAs($this->owner($this->plain))
            ->post(route('admin.integrations.paymob.save'), $this->keys())
            ->assertSessionHasNoErrors();

        $row = PaymobSettings::row($this->plain->id);
        $this->assertTrue($row->ready());
        $this->assertSame('111', $row->card_integration_id);
        $this->assertSame('222', $row->apple_pay_integration_id);
        $this->assertSame('secret-new', $row->secret_key);
        $this->assertSame('hmac-new', $row->hmac_secret);
    }

    public function test_the_page_shows_ids_and_state_but_never_the_secrets(): void
    {
        $this->gateway($this->a, '111', '222', 'hmac-a-private', 'secret-a-private');

        $res = $this->actingAs($this->owner($this->a))->get(route('admin.integrations.paymob'))->assertOk();
        $gateway = $res->viewData('page')['props']['gateway'];

        $this->assertSame('public-'.$this->a->id, $gateway['public_key']);
        $this->assertSame('111', $gateway['card_integration_id']);
        $this->assertSame('222', $gateway['apple_pay_integration_id']);
        $this->assertTrue($gateway['has_secret']);
        $this->assertTrue($gateway['has_hmac']);
        $this->assertTrue($gateway['apple_pay']);
        $this->assertSame(rtrim(config('app.url'), '/').'/webhooks/paymob', $gateway['webhook']);
        $this->assertArrayNotHasKey('secret_key', $gateway);
        $this->assertArrayNotHasKey('hmac_secret', $gateway);

        $html = $res->getContent();
        $this->assertStringNotContainsString('secret-a-private', $html);
        $this->assertStringNotContainsString('hmac-a-private', $html);
    }

    public function test_blank_secrets_keep_the_stored_ones(): void
    {
        $this->gateway($this->a, '111', null, 'hmac-kept', 'secret-kept');

        $this->actingAs($this->owner($this->a))
            ->post(route('admin.integrations.paymob.save'), $this->keys(['secret_key' => '', 'hmac_secret' => '', 'apple_pay_integration_id' => '222']))
            ->assertSessionHasNoErrors();

        $row = PaymobSettings::row($this->a->id);
        $this->assertSame('secret-kept', $row->secret_key);
        $this->assertSame('hmac-kept', $row->hmac_secret);
        $this->assertSame('222', $row->apple_pay_integration_id);
    }

    public function test_clearing_apple_pay_returns_to_card_only(): void
    {
        $this->gateway($this->a, '111', '222');

        $this->actingAs($this->owner($this->a))
            ->post(route('admin.integrations.paymob.save'), $this->keys(['apple_pay_integration_id' => '', 'secret_key' => '', 'hmac_secret' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(PaymobSettings::row($this->a->id)->apple_pay_integration_id);
        $this->assertSame([111], PaymobSettings::row($this->a->id)->paymentMethodIds());
    }

    public function test_integration_ids_are_digits_and_apple_pay_is_not_the_card_id(): void
    {
        $owner = $this->owner($this->a);

        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys(['apple_pay_integration_id' => '111']))
            ->assertSessionHasErrors('apple_pay_integration_id');
        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys(['apple_pay_integration_id' => 'ap-12']))
            ->assertSessionHasErrors('apple_pay_integration_id');
        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys(['card_integration_id' => '11 1']))
            ->assertSessionHasErrors('card_integration_id');

        $this->assertNull(PaymobSettings::row($this->a->id));
    }

    public function test_an_incomplete_gateway_is_saved_but_not_switched_on(): void
    {
        $this->actingAs($this->owner($this->a))
            ->post(route('admin.integrations.paymob.save'), $this->keys(['hmac_secret' => '']))
            ->assertSessionHasErrors('active');

        $row = PaymobSettings::row($this->a->id);
        $this->assertFalse($row->active);
        $this->assertSame('111', $row->card_integration_id);
        $this->assertFalse(Paymob::enabled($this->a->id));
    }

    /* ═══════════════ ٦ · كلُّ متجرٍ بصفّه ═══════════════ */

    public function test_a_merchant_sees_and_saves_only_its_own_row(): void
    {
        $this->gateway($this->b, '333', '444', 'hmac-b', 'secret-b');

        // A يفتح صفحتَه فلا يرى شيئًا من B
        $gateway = $this->actingAs($this->owner($this->a))->get(route('admin.integrations.paymob'))
            ->viewData('page')['props']['gateway'];
        $this->assertSame('', $gateway['card_integration_id']);
        $this->assertSame('', $gateway['apple_pay_integration_id']);
        $this->assertFalse($gateway['has_secret']);

        // ويحفظ — ولو كتب معرّفَ B في الطلب
        $this->actingAs($this->owner($this->a))
            ->post(route('admin.integrations.paymob.save'), $this->keys(['business_id' => $this->b->id]))
            ->assertSessionHasNoErrors();

        $b = PaymobSettings::row($this->b->id);
        $this->assertSame('333', $b->card_integration_id);
        $this->assertSame('444', $b->apple_pay_integration_id);
        $this->assertSame('secret-b', $b->secret_key);
        $this->assertSame('hmac-b', $b->hmac_secret);

        $this->assertSame('111', PaymobSettings::row($this->a->id)->card_integration_id);
        $this->assertSame(2, PaymentGateway::count());
    }

    public function test_a_cashier_cannot_open_or_save_the_paymob_page(): void
    {
        $cashier = User::create([
            'business_id' => $this->a->id, 'name' => 'كاشير', 'email' => 'c@shop.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->get(route('admin.integrations.paymob'))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.integrations.paymob.save'), $this->keys())->assertForbidden();
        $this->assertNull(PaymobSettings::row($this->a->id));
    }

    /* ═══════════════ ٧ · شاشاتُ الموقع تعرض ولا تحرّر ═══════════════ */

    public function test_the_old_website_save_route_is_gone(): void
    {
        $this->assertFalse(app('router')->has('admin.marketing.store.gateway'));
        $this->assertTrue(app('router')->has('admin.integrations.paymob.save'));
    }

    /** @return iterable<string, array{string}> */
    public static function websiteScreens(): iterable
    {
        yield 'متجرُ الواجهة الخاصّة' => ['shop'];
        yield 'الإعداداتُ العامّة' => ['settings'];
    }

    private function screenGateway(Business $shop, string $screen): array
    {
        $this->actingAs($this->owner($shop));

        return $screen === 'shop'
            ? $this->get(route('admin.website.shop'))->assertOk()->viewData('page')['props']['gateway']
            : $this->get(route('admin.settings.index', ['section' => 'website']))->assertOk()->viewData('page')['props']['store']['gateway'];
    }

    #[DataProvider('websiteScreens')]
    public function test_the_website_screens_carry_the_state_and_no_keys(string $screen): void
    {
        $this->gateway($this->a, '111', '222', 'hmac-a-private', 'secret-a-private');

        $g = $this->screenGateway($this->a, $screen);

        $this->assertSame(['state', 'card_ready', 'apple_pay', 'checkout', 'online'], array_keys($g));
        $this->assertSame('ready', $g['state']);
        $this->assertTrue($g['apple_pay']);
        $this->assertTrue($g['online']);

        $blob = json_encode($g);
        foreach (['public-'.$this->a->id, '111', '222', 'secret-a-private', 'hmac-a-private'] as $value) {
            $this->assertStringNotContainsString($value, $blob, $screen);
        }
    }

    #[DataProvider('websiteScreens')]
    public function test_another_merchants_screen_tells_its_own_state(string $screen): void
    {
        $this->gateway($this->a, '111', '222');
        $this->gateway($this->b, '333');

        $g = $this->screenGateway($this->b, $screen);
        $this->assertSame('ready', $g['state']);
        $this->assertFalse($g['apple_pay']);

        PaymentGateway::where('business_id', $this->b->id)->delete();
        $this->assertSame('off', $this->screenGateway($this->b, $screen)['state']);
    }

    /* ═══════════════ ٨ · الإشعارُ مصدرُ الحقيقة ═══════════════ */

    public function test_a_signed_notice_writes_one_order_and_a_repeat_writes_none(): void
    {
        $this->gateway($this->a, '111', '222', 'hmac-a');
        $intent = $this->openCard($this->a);

        $this->notify($intent, 'hmac-a');
        $this->notify($intent, 'hmac-a');

        $this->assertSame(1, Order::where('business_id', $this->a->id)->count());
        $this->assertSame(StorePaymentIntent::PAID, $intent->refresh()->status);
    }

    /** ودفعةُ Apple Pay تصل برقم تكاملها هو — والتوقيعُ يحمله فيُقبل */
    public function test_a_notice_from_the_apple_pay_integration_settles_too(): void
    {
        $this->gateway($this->a, '111', '222', 'hmac-a');
        $intent = $this->openCard($this->a);

        $this->notify($intent, 'hmac-a', [
            'integration_id' => 222,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'apple-pay'],
        ]);

        $this->assertSame(1, Order::where('business_id', $this->a->id)->count());
    }

    /**
     * والإعدادُ يتبدّل والزبونُ على صفحة البنك — فالإشعارُ لا يُقاس برقم التكامل
     * المحفوظ الآن. المرجعُ والتوقيعُ والمبلغُ والعملةُ هي ما يُصدَّق.
     */
    public function test_changing_the_integration_ids_mid_payment_does_not_lose_the_order(): void
    {
        $this->gateway($this->a, '111', '222', 'hmac-a');
        $intent = $this->openCard($this->a);

        PaymentGateway::where('business_id', $this->a->id)->update(['card_integration_id' => '555', 'apple_pay_integration_id' => null]);

        $this->notify($intent, 'hmac-a', ['integration_id' => 222]);

        $this->assertSame(1, Order::where('business_id', $this->a->id)->count());
    }

    public function test_forged_short_foreign_or_pending_notices_write_nothing(): void
    {
        $this->gateway($this->a, '111', null, 'hmac-a');
        $intent = $this->openCard($this->a);

        $this->notify($intent, 'wrong-secret');
        $this->notify($intent, 'hmac-a', ['amount_cents' => 1000]);
        $this->notify($intent, 'hmac-a', ['currency' => 'EGP']);
        $this->notify($intent, 'hmac-a', ['pending' => true]);
        $this->notify($intent, 'hmac-a', ['is_voided' => true]);
        $this->notify($intent, 'hmac-a', ['is_refunded' => true]);

        $this->assertSame(0, Order::count());
    }

    /** توقيعُ متجر A لا يُتمّ دفعةَ متجر B */
    public function test_one_merchants_secret_cannot_settle_anothers_intent(): void
    {
        $this->gateway($this->a, '111', null, 'hmac-a');
        $this->gateway($this->b, '333', null, 'hmac-b');
        $intentB = $this->openCard($this->b);

        $this->notify($intentB, 'hmac-a', ['integration_id' => 111]);

        $this->assertSame(0, Order::count());
        $this->assertSame(StorePaymentIntent::PENDING, $intentB->refresh()->status);
    }

    /* ═══════════════ ٩ · وما قُبض لا يضيع إن تبدّل الموقع ═══════════════ */

    /**
     * فُتحت الدفعةُ والموقعُ بسلّة، ثمّ نُزعت الواجهة قبل الإشعار.
     *
     * لا يُكتب طلبٌ لموقعٍ لم يعد يستقبل طلبات (`WebCheckout::accepts` تسأل
     * عن الواجهة كما كانت تسأل قبل هذا التغيير) — لكنّ المالَ لا يضيع: الدفعةُ
     * تُقرأ مدفوعة، ويُكتب سببُ غياب الطلب، ويُنبَّه صاحبُ المحلّ ليردّها.
     */
    public function test_money_paid_before_the_cart_went_away_is_kept_and_told(): void
    {
        $this->gateway($this->a, '111', null, 'hmac-a');
        $intent = $this->openCard($this->a);

        DB::table('businesses')->where('id', $this->a->id)->update(['storefront_theme' => null]);
        $this->assertFalse(Paymob::enabled($this->a->id));

        $this->notify($intent, 'hmac-a');

        $intent->refresh();
        $this->assertSame(StorePaymentIntent::PAID, $intent->status, 'إشعارٌ صادقٌ قُرئ مزوَّرًا');
        $this->assertSame('998877', (string) $intent->provider_transaction_id);
        $this->assertNotNull($intent->error);
        $this->assertTrue($intent->strayPayment());
        $this->assertSame(0, Order::count());
    }

    public function test_after_the_cart_goes_away_no_new_card_payment_opens_but_refunds_still_work(): void
    {
        config(['storefront.paymob_auto_refund' => true]);
        $this->gateway($this->a, '111', null, 'hmac-a');
        $intent = $this->openCard($this->a);
        $this->notify($intent, 'hmac-a');

        DB::table('businesses')->where('id', $this->a->id)->update(['storefront_theme' => null]);

        $this->assertArrayNotHasKey(WebCheckout::PAY_CARD, WebCheckout::payments($this->a->id));
        $this->assertTrue(Paymob::refund($intent->refresh(), 'ردٌّ بعد نزع الواجهة'));
        $this->assertSame(StorePaymentIntent::REFUND_SENT, $intent->refresh()->refund_status);
    }

    /* ═══════════════ ١٠ · العمود ═══════════════ */

    public function test_the_new_column_is_nullable_and_starts_empty(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_gateways', 'apple_pay_integration_id'));

        $row = PaymentGateway::create([
            'business_id' => $this->a->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'public-a', 'secret_key' => 's', 'hmac_secret' => 'h',
            'card_integration_id' => '111', 'active' => true,
        ]);

        $this->assertNull($row->fresh()->apple_pay_integration_id);
        $this->assertTrue($row->fresh()->ready());
    }

    /* ═══════════════ ١١ · OmanNet — تكاملٌ ثالثٌ لكلّ متجرٍ برقمه ═══════════════ */

    public function test_omannet_sits_between_the_card_and_apple_pay_and_changes_nothing_when_empty(): void
    {
        $g = new PaymentGateway;

        $cases = [
            // بلا OmanNet: كما كان قبله حرفًا بحرف
            [['111', null, null], [111]],
            [['111', '', '222'], [111, 222]],
            [['111', null, '222'], [111, 222]],
            // وبه: البطاقةُ ثمّ OmanNet ثمّ Apple Pay
            [['111', '333', null], [111, 333]],
            [['111', '333', '222'], [111, 333, 222]],
            [[' 111 ', ' 333 ', ' 222 '], [111, 333, 222]],
            // والصفرُ والنصُّ يسقطان، والمكرَّرُ يُرسَل مرّة
            [['111', '0', '222'], [111, 222]],
            [['111', 'on-1', '222'], [111, 222]],
            [['111', '111', '222'], [111, 222]],
            [['111', '222', '222'], [111, 222]],
            [['', '333', ''], [333]],
        ];

        foreach ($cases as [[$card, $omannet, $apple], $want]) {
            $g->card_integration_id = $card;
            $g->omannet_integration_id = $omannet;
            $g->apple_pay_integration_id = $apple;
            $this->assertSame($want, $g->paymentMethodIds(), json_encode([$card, $omannet, $apple]));
        }
    }

    public function test_omannet_is_not_needed_for_a_ready_gateway(): void
    {
        $this->assertTrue($this->gateway($this->a, '111')->ready());
        $this->assertNull(PaymobSettings::row($this->a->id)->omannet_integration_id);
    }

    public function test_a_shop_with_omannet_sends_card_omannet_then_apple_pay(): void
    {
        $this->gateway($this->a, '111', '222', omannet: '333');
        $intent = $this->openCard($this->a);

        $sent = $this->intention();
        $this->assertSame([111, 333, 222], $sent['payment_methods']);
        // ودفعةٌ واحدةٌ وصفحةُ Paymob الموحّدة — لا بابَ ثانٍ لـOmanNet
        $this->assertSame(1, StorePaymentIntent::where('business_id', $this->a->id)->count());
        $this->assertSame($intent->reference, $sent['special_reference']);
    }

    public function test_two_merchants_send_their_own_three_ids_and_nothing_of_each_other(): void
    {
        $this->gateway($this->a, '1001', '1003', 'hmac-a', 'secret-a', omannet: '1002');
        $this->gateway($this->b, '2001', '2003', 'hmac-b', 'secret-b', omannet: '2002');

        $this->openCard($this->a);
        $a = $this->intention();
        $this->assertSame([1001, 1002, 1003], $a['payment_methods']);
        $this->assertSame('Token secret-a', $a->header('Authorization')[0]);

        $this->openCard($this->b);
        $b = $this->intention();
        $this->assertSame([2001, 2002, 2003], $b['payment_methods']);
        $this->assertSame('Token secret-b', $b->header('Authorization')[0]);
    }

    public function test_a_shop_without_omannet_never_receives_anothers(): void
    {
        $this->gateway($this->a, '111', null, omannet: '333');
        $this->gateway($this->b, '444');

        $this->openCard($this->b);

        $this->assertSame([444], $this->intention()['payment_methods']);
    }

    public function test_the_browser_cannot_add_an_omannet_id(): void
    {
        $this->gateway($this->a, '111');
        $this->fakePaymob();

        $this->postJson('/s/bloom-a/checkout', $this->order($this->a) + [
            'omannet_integration_id' => '999', 'payment_methods' => [999],
        ])->assertOk();

        $this->assertSame([111], $this->intention()['payment_methods']);
    }

    public function test_omannet_is_saved_shown_and_cleared_for_the_merchant_itself(): void
    {
        $owner = $this->owner($this->a);

        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys(['omannet_integration_id' => ' 333 ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('333', PaymobSettings::row($this->a->id)->omannet_integration_id);

        $gateway = $this->actingAs($owner)->get(route('admin.integrations.paymob'))->viewData('page')['props']['gateway'];
        $this->assertSame('333', $gateway['omannet_integration_id']);
        $this->assertTrue($gateway['omannet']);

        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys([
            'omannet_integration_id' => '', 'secret_key' => '', 'hmac_secret' => '',
        ]))->assertSessionHasNoErrors();

        $row = PaymobSettings::row($this->a->id);
        $this->assertNull($row->omannet_integration_id);
        $this->assertSame([111, 222], $row->paymentMethodIds());
        // والسرّان الفارغان لا يمحوان المحفوظ
        $this->assertSame('secret-new', $row->secret_key);
        $this->assertSame('hmac-new', $row->hmac_secret);
    }

    public function test_a_save_without_the_omannet_field_leaves_it_empty_as_before(): void
    {
        $this->actingAs($this->owner($this->a))->post(route('admin.integrations.paymob.save'), $this->keys())
            ->assertSessionHasNoErrors();

        $this->assertNull(PaymobSettings::row($this->a->id)->omannet_integration_id);
        $this->assertSame([111, 222], PaymobSettings::row($this->a->id)->paymentMethodIds());
    }

    public function test_omannet_must_be_a_positive_number_of_its_own(): void
    {
        $owner = $this->owner($this->a);

        foreach (['abc', '33x', '3 3', '0', '000', '-5'] as $bad) {
            $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys(['omannet_integration_id' => $bad]))
                ->assertSessionHasErrors('omannet_integration_id');
        }

        // ولا يكون رقمَ البطاقة، ولا يكون رقمُ Apple Pay رقمَه
        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys(['omannet_integration_id' => '111']))
            ->assertSessionHasErrors('omannet_integration_id');
        $this->actingAs($owner)->post(route('admin.integrations.paymob.save'), $this->keys([
            'omannet_integration_id' => '333', 'apple_pay_integration_id' => '333',
        ]))->assertSessionHasErrors('apple_pay_integration_id');

        $this->assertNull(PaymobSettings::row($this->a->id));
    }

    public function test_a_merchant_never_sees_or_edits_anothers_omannet(): void
    {
        $this->gateway($this->b, '333', '444', 'hmac-b', 'secret-b', omannet: '555');

        $gateway = $this->actingAs($this->owner($this->a))->get(route('admin.integrations.paymob'))
            ->viewData('page')['props']['gateway'];
        $this->assertSame('', $gateway['omannet_integration_id']);
        $this->assertFalse($gateway['omannet']);

        $this->actingAs($this->owner($this->a))->post(route('admin.integrations.paymob.save'), $this->keys([
            'business_id' => $this->b->id, 'omannet_integration_id' => '666',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('555', PaymobSettings::row($this->b->id)->omannet_integration_id);
        $this->assertSame('666', PaymobSettings::row($this->a->id)->omannet_integration_id);
    }

    /** وإشعارُ دفعةِ OmanNet يُصدَّق بسرّ متجرها هو — كالبطاقة وApple Pay */
    public function test_a_notice_from_the_omannet_integration_settles_with_its_shops_secret_only(): void
    {
        $this->gateway($this->a, '111', null, 'hmac-a', omannet: '333');
        $this->gateway($this->b, '444', null, 'hmac-b', omannet: '555');
        $intent = $this->openCard($this->a);

        $omannet = ['integration_id' => 333, 'source_data' => ['pan' => '4321', 'sub_type' => 'OmanNet', 'type' => 'omannet']];

        // بسرّ الجار: لا شيء
        $this->notify($intent, 'hmac-b', $omannet);
        $this->assertSame(0, Order::count());

        // ومبلغٌ أو عملةٌ غيرُ المطلوبة: لا شيء
        $this->notify($intent, 'hmac-a', $omannet + ['amount_cents' => 1]);
        $this->notify($intent, 'hmac-a', $omannet + ['currency' => 'EGP']);
        $this->assertSame(0, Order::count());

        // وبسرّه هو: طلبٌ واحد
        $this->notify($intent, 'hmac-a', $omannet);
        $this->notify($intent, 'hmac-a', $omannet);
        $this->assertSame(1, Order::where('business_id', $this->a->id)->count());
        $this->assertSame(StorePaymentIntent::PAID, $intent->refresh()->status);
    }

    public function test_the_omannet_column_is_nullable_and_existing_rows_keep_their_methods(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_gateways', 'omannet_integration_id'));

        $row = $this->gateway($this->a, '111', '222');

        $this->assertNull($row->fresh()->omannet_integration_id);
        $this->assertTrue($row->fresh()->ready());
        $this->assertSame([111, 222], $row->fresh()->paymentMethodIds());
    }

    /** ومن كتب رقمَ OmanNet وحده بدأ الربطَ ولم يُكمله — لا «غير مربوط» */
    public function test_an_omannet_id_alone_is_a_started_link_not_an_empty_one(): void
    {
        PaymentGateway::create([
            'business_id' => $this->a->id, 'provider' => PaymentGateway::PAYMOB,
            'omannet_integration_id' => '333', 'active' => false,
        ]);

        $this->assertSame('partial', PaymobSettings::state($this->a->id));
        $this->assertSame('off', PaymobSettings::state($this->b->id));
    }

    public function test_no_merchant_or_integration_number_is_written_into_the_omannet_code(): void
    {
        foreach ([
            'app/Models/PaymentGateway.php',
            'app/Support/Store/PaymobSettings.php',
            'app/Support/Store/Paymob.php',
            'resources/js/Pages/Admin/Integrations/Paymob.tsx',
            'database/migrations/2026_10_06_120000_a_gateway_may_carry_an_omannet_integration.php',
        ] as $file) {
            $code = file_get_contents(base_path($file));

            $this->assertDoesNotMatchRegularExpression('/\b7160[45]\b|\b7152[57]\b/', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/RIBBON|\bsaud\b|سعود/iu', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/business_id\s*(===?|==)\s*\d+/', $code, $file);
        }
    }
}

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
use App\Support\DeliveryPaper;
use App\Support\FlowerOrder;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\CardIdentity;
use App\Support\Store\GiftCard;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «لا تذكر اسمي» ورسالةُ بطاقةٍ تقول الاسم — يُسأل الزبون قبل أن يُنشأ طلبُه.
 *
 * ═══ القاعدة ═══
 *
 * هديّةٌ أخفى صاحبُها اسمَه، في متجرٍ فُتح له الطرح، ورسالةُ بطاقتها تبدو
 * حاملةً اسمَه أو رقمَه: يُردّ الإتمامُ بلا طلبٍ ولا دفعةٍ ولا خصمِ رفّ،
 * ومعه رمزٌ. فإن أعاده الزبونُ («إرسال الرسالة كما هي») مضى الطلبُ والرسالةُ
 * كما كتبها حرفًا حرفًا — والإخفاءُ باقٍ. والرمزُ مربوطٌ بالنصّ والمشتري
 * والمستلِم: إن تبدّل شيءٌ سُئل ثانيةً. انظر `CardIdentity`.
 *
 * والبطاقةُ تُقرأ من حيث يقرؤها المستلِم: خانةُ الإتمام، أو بنودُ «كرت هدية»
 * في السلّة. والدفعةُ المقبوضة لا تُسأل ثانيةً — لا مالَ بلا طلب.
 */
class AnAnonymousGiftAsksBeforeItsCardNamesTheSenderTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $rose;

    private const CARD = "كل عام وأنتِ بخير يا مريم 🌹\nمع حبّي،\nAhmed Al Harthi";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        config(['storefront.ribbon_english_checkout_businesses' => [], 'storefront.ribbon_gift_card_product_businesses' => [], 'storefront.ribbon_free_gift_card_message_businesses' => []]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            // الإهداءُ والطرحُ — يفتحهما مديرُ المنصّة
            'gift_orders_enabled' => true, 'thermal_delivery_note_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_pay_transfer' => '1', 'store_bank' => 'Bank Muscat 0123',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م', 'store_delivery_fee' => '2',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gift(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'Ahmed Al Harthi', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'Way 3021, villa 12', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
            'is_gift' => true, 'hide_sender' => true, 'recipient_name' => 'مريم', 'recipient_phone' => '96899220002',
            'occasion' => 'birthday', 'card' => self::CARD,
        ];
    }

    private int $ordersBefore = 0;

    private int $stockBefore = 0;

    private function place(array $payload)
    {
        $this->ordersBefore = Order::count();
        $this->stockBefore = (int) $this->rose->refresh()->quantity;

        return $this->postJson('/s/ribbon/checkout', $payload);
    }

    /** يُسأل — ولا طلبَ ولا دفعةَ ولا خصمَ رفّ — ويُعطى الرمزُ والمصدر */
    private function asked($res, string $source = CardIdentity::FROM_CARD): string
    {
        $res->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('card_identity.source', $source)
            ->assertJsonValidationErrors([CardIdentity::FIELD]);
        $this->assertSame($this->ordersBefore, Order::count(), 'كُتب طلبٌ قبل الجواب');
        $this->assertSame(0, StorePaymentIntent::count(), 'فُتحت دفعةٌ قبل الجواب');
        $this->assertSame($this->stockBefore, (int) $this->rose->refresh()->quantity, 'خُصم الرفُّ قبل الجواب');

        $token = (string) $res->json('card_identity.token');
        $this->assertSame(40, strlen($token));
        // والردُّ لا يحمل شيئًا من الرسالة
        $this->assertStringNotContainsString('Ahmed', (string) json_encode($res->json('card_identity')));

        return $token;
    }

    /* ═════════════ الكشف ═════════════ */

    public function test_an_anonymous_gift_without_the_sender_in_its_card_goes_straight_through(): void
    {
        $this->place($this->gift(['card' => "كل عام وأنتِ بخير يا مريم\nمع خالص المحبة"]))->assertOk();

        $order = Order::sole();
        $this->assertSame("كل عام وأنتِ بخير يا مريم\nمع خالص المحبة", $order->card_message);
        $this->assertTrue((bool) $order->hide_sender);
    }

    public function test_the_senders_full_name_in_the_card_asks_first(): void
    {
        $this->asked($this->place($this->gift()));
    }

    public function test_the_buyers_phone_in_the_card_asks_first(): void
    {
        foreach (['اتصلي بي ٩٩١١٠٠٠١ إن احتجتِ شيئًا', 'Call me: +968 9911 0001', 'رقمي 00968-9911-0001'] as $card) {
            $this->asked($this->place($this->gift(['card' => $card])));
        }
    }

    /** وبحرفين مختلفين: اسمٌ لاتينيّ ورسالةٌ عربيّة، والعكس — من قاموس الأسماء */
    public function test_a_name_written_in_the_other_script_is_recognised(): void
    {
        $this->asked($this->place($this->gift(['card' => 'كل عام وأنتِ بخير — أحمد'])));
        $this->asked($this->place($this->gift(['name' => 'سارة البلوشي', 'card' => 'Happy birthday dear! Love, Sarah'])));
        // وهمزةٌ وتاءٌ مربوطة وتشكيلٌ وحالةُ حروف لا تُخفي الاسم
        $this->asked($this->place($this->gift(['name' => 'سارة البلوشي', 'card' => 'مع حبّي، سارَه'])));
        $this->asked($this->place($this->gift(['card' => 'with love, AHMED'])));
    }

    /** واسمٌ يشبه ولا يطابق، واسمُ المستلِم إليه — لا يُسأل عنهما */
    public function test_a_similar_name_or_the_recipients_own_name_is_not_flagged(): void
    {
        $this->place($this->gift(['name' => 'Ali Salem', 'card' => 'Happy birthday Alia, with love']))->assertOk();
        $this->place($this->gift(['name' => 'Sara Ahmed', 'recipient_name' => 'Sara Khalid', 'phone' => '96899110003', 'card' => 'عيد ميلاد سعيد يا سارة']))->assertOk();
        // والرقمُ يُطابَق بخاناته كلّها لا بجزءٍ منها
        $this->place($this->gift(['card' => 'Your table is 1100 at 9 pm', 'name' => 'Noura Hamed', 'phone' => '96899110004']))->assertOk();

        $this->assertSame(3, Order::count());
    }

    /* ═════════════ الإقرار ═════════════ */

    public function test_confirming_sends_the_message_exactly_as_written(): void
    {
        $token = $this->asked($this->place($this->gift()));

        $this->place($this->gift([CardIdentity::FIELD => $token]))->assertOk();

        $order = Order::sole();
        $this->assertSame(self::CARD, $order->card_message, 'تبدّلت الرسالة');
        $this->assertSame(self::CARD, DB::table('orders')->where('id', $order->id)->value('card_message'));
        $this->assertTrue((bool) $order->hide_sender, 'سقط الإخفاء');
        // والمُهدي محجوبٌ عن المستلِم كما كان — الإقرارُ للرسالة وحدها
        $this->assertNull(FlowerOrder::cardForRecipient($order)['sender']);
        $this->assertSame(9, (int) $this->rose->refresh()->quantity);
    }

    /** والإقرارُ لا يمتدّ إلى غير الرسالة: السندُ يحجب المشتري في غيرها */
    public function test_consent_to_the_card_reveals_nothing_else_on_the_delivery_note(): void
    {
        $payload = $this->gift(['address' => 'Way 3021 — ask for Ahmed Al Harthi']);
        $token = $this->asked($this->place($payload));
        $this->place($payload + [CardIdentity::FIELD => $token])->assertOk();

        $note = DeliveryPaper::forRecipient(Order::with('items')->sole());

        $this->assertSame(self::CARD, $note['gift']['message']);
        $this->assertNull($note['gift']['sender']);
        $this->assertStringNotContainsString('Ahmed', (string) $note['recipient']['address']);
        $this->assertStringContainsString('***', (string) $note['recipient']['address']);
    }

    /** والرمزُ لما أُقرّ عليه وحده — نصٌّ آخر أو مشترٍ آخر أو مستلِمٌ آخر يُسأل ثانيةً */
    public function test_a_confirmation_does_not_cover_what_changed_after_it(): void
    {
        $token = $this->asked($this->place($this->gift()));

        foreach ([
            ['card' => self::CARD.' 💐'],
            ['card' => 'من أحمد'],
            ['name' => 'Ahmed Al-Harthi'],
            ['phone' => '96899110009'],
            ['recipient_name' => 'مريم البلوشي'],
        ] as $changed) {
            $again = $this->asked($this->place($this->gift($changed + [CardIdentity::FIELD => $token])));
            $this->assertNotSame($token, $again, json_encode($changed, JSON_UNESCAPED_UNICODE));
        }

        // ورمزٌ مختلَق لا يمرّ
        $this->asked($this->place($this->gift([CardIdentity::FIELD => str_repeat('a', 40)])));
    }

    /** و«تعديل الرسالة» لا يُرسل شيئًا: الرسالةُ المعدَّلةُ تمضي، وطلبٌ واحدٌ لا اثنان */
    public function test_editing_the_message_places_one_order_with_the_new_text(): void
    {
        $this->asked($this->place($this->gift()));

        $this->place($this->gift(['card' => "كل عام وأنتِ بخير يا مريم 🌹\nمع حبّي"]))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame("كل عام وأنتِ بخير يا مريم 🌹\nمع حبّي", Order::sole()->card_message);
    }

    public function test_nothing_is_asked_without_hide_sender_or_outside_the_rollout(): void
    {
        // يذكر اسمَه ولم يطلب إخفاءه
        $this->place($this->gift(['hide_sender' => false]))->assertOk();
        // وطلبٌ ليس هديّة لا إخفاءَ فيه
        $this->place($this->gift(['is_gift' => false, 'phone' => '96899110005']))->assertOk();

        // ومتجرٌ لم يُفتح له الطرحُ كما كان
        $this->shop->forceFill(['thermal_delivery_note_enabled' => false])->save();
        $this->place($this->gift(['phone' => '96899110006']))->assertOk();

        $this->assertSame(3, Order::count());
        $this->assertSame(self::CARD, Order::latest('id')->first()->card_message);
    }

    /** وطلبٌ قديمٌ كُتب قبل هذا لا يُمسّ — ولا يُعاد كتابةُ رسالته */
    public function test_existing_orders_are_not_rewritten(): void
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'WEB-OLD-1', 'channel' => 'website', 'status' => 'جديد',
            'customer_name' => 'Ahmed Al Harthi', 'payment_method' => 'نقدي', 'payment_status' => 'غير مدفوع',
            'subtotal' => 20, 'total' => 20, 'is_gift' => true, 'hide_sender' => true, 'card_message' => self::CARD,
            'ordered_at' => now(),
        ]);
        $before = DB::table('orders')->where('id', $order->id)->first();

        $this->place($this->gift(['phone' => '96899110007', 'card' => 'تهنئة']))->assertOk();
        DeliveryPaper::forRecipient($order->load('items'));

        $this->assertEquals($before, DB::table('orders')->where('id', $order->id)->first());
    }

    /* ═════════════ «كرت هدية» صنفًا في السلّة ═════════════ */

    public function test_a_gift_card_products_message_is_checked_where_it_lives(): void
    {
        $card = Product::create([
            'business_id' => $this->shop->id, 'name' => GiftCard::PRODUCT_NAME, 'name_en' => 'Gift card',
            'price' => 1.5, 'cost' => 0, 'quantity' => 0, 'alert_qty' => 0, 'tracks_stock' => false,
            'is_gift_card' => true, 'active' => true, 'published' => true,
        ]);
        config(['storefront.ribbon_gift_card_product_businesses' => [$this->shop->id]]);
        $note = "Happy birthday Maryam 🎂\nFrom Ahmed";
        $items = [['id' => $this->rose->id, 'qty' => 1], ['id' => $card->id, 'qty' => 1, 'note' => $note]];

        // وخانةُ الإتمام لا شأنَ لها هنا — النصُّ من بند الكرت
        $token = $this->asked($this->place($this->gift(['items' => $items, 'card' => ''])), CardIdentity::FROM_ITEMS);
        $this->place($this->gift(['items' => $items, 'card' => '', CardIdentity::FIELD => $token]))->assertOk();

        $order = Order::with('items')->sole();
        $this->assertSame($note, $order->card_message);
        $this->assertSame($note, $order->items->firstWhere('product_id', $card->id)->note);
    }

    /* ═════════════ الدفع ═════════════ */

    public function test_cash_and_bank_transfer_ask_before_the_order(): void
    {
        foreach (['cod', 'transfer'] as $i => $pay) {
            $payload = $this->gift(['pay' => $pay, 'phone' => '9689911000'.($i + 1)]);
            $token = $this->asked($this->place($payload));
            $this->place($payload + [CardIdentity::FIELD => $token])->assertOk();
            $this->assertSame($i + 1, Order::count());
        }

        $this->assertSame([__('نقدي'), __('تحويل بنكي')], Order::orderBy('id')->pluck('payment_method')->all());
    }

    private function gateway(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '4569876', 'active' => true,
        ]);
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);
    }

    /** إشعارُ البوّابة الموقَّع — كما يصل */
    private function paid(StorePaymentIntent $intent): void
    {
        $obj = [
            'amount_cents' => (int) round((float) $intent->amount * 1000), 'created_at' => '2027-02-01T10:00:10.100000',
            'currency' => 'OMR', 'error_occured' => false, 'has_parent_transaction' => false, 'id' => 998877,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference], 'owner' => 4705, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();
    }

    public function test_the_card_page_does_not_open_before_consent(): void
    {
        $this->gateway();

        $this->asked($this->place($this->gift(['pay' => 'card'])));

        Http::assertNothingSent();
    }

    /** والإقرارُ يمضي مع نيّة الدفع، والإشعارُ يُتمّ الطلبَ بالرسالة كما هي */
    public function test_the_consent_rides_the_payment_intent_to_the_order(): void
    {
        $this->gateway();
        $token = $this->asked($this->place($this->gift(['pay' => 'card'])));

        $this->place($this->gift(['pay' => 'card', CardIdentity::FIELD => $token]))->assertOk()->assertJsonPath('ok', true);

        $intent = StorePaymentIntent::sole();
        $this->assertSame($token, $intent->payload[CardIdentity::FIELD]);
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ قبل أن يصل المال');

        $this->paid($intent);

        $order = Order::sole();
        $this->assertSame(self::CARD, $order->card_message);
        $this->assertTrue((bool) $order->hide_sender);
        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame((int) $order->id, (int) $intent->refresh()->order_id);
        $this->assertNull($intent->error);
    }

    /**
     * ودفعةٌ قُبضت لا تُسأل ثانيةً — لا مالَ بلا طلب.
     *
     * نيّةٌ فُتحت ولا سؤال (الطرحُ مغلق)، ثمّ فُتح الطرحُ قبل أن يصل إشعارُها:
     * الطلبُ يُكتب، ولا يُردّ لإقرارٍ لم يُطلب يومَ الدفع.
     */
    public function test_a_paid_callback_is_never_refused_for_consent(): void
    {
        $this->gateway();
        $this->shop->forceFill(['thermal_delivery_note_enabled' => false])->save();
        $this->place($this->gift(['pay' => 'card']))->assertOk();
        $intent = StorePaymentIntent::sole();
        $this->assertArrayNotHasKey(CardIdentity::FIELD, $intent->payload);

        $this->shop->forceFill(['thermal_delivery_note_enabled' => true])->save();
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'دُفع ولم يُكتب طلب');
        $this->assertSame(self::CARD, Order::sole()->card_message);
        $this->assertNull($intent->refresh()->error);
    }

    /* ═════════════ الصفحة ═════════════ */

    public function test_the_checkout_page_carries_the_question_only_in_the_rollout(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_gift_card' => '1', 'store_gift_card_price' => '0.500']);

        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        foreach (['data-testid="rb-card-identity"', 'data-testid="rb-card-file-privacy"', 'window.RBCardConsent = ', 'card_identity_ack',
            'رسالة البطاقة قد تحتوي على اسمك أو رقمك', 'تعديل الرسالة', 'إرسال الرسالة كما هي'] as $piece) {
            $this->assertStringContainsString($piece, $html);
        }

        $this->shop->forceFill(['thermal_delivery_note_enabled' => false])->save();
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        // والسكربتُ يسأل عن الصندوق فلا يجده — لا صندوقَ ولا وحدةَ تُضمَّن
        foreach (['data-testid="rb-card-identity"', 'data-testid="rb-card-file-privacy"', 'window.RBCardConsent = '] as $piece) {
            $this->assertStringNotContainsString($piece, $html);
        }
    }

    /** وملفُّ الكرت لا يُقرأ — ولا يُردّ بسببه طلب */
    public function test_a_card_file_is_never_read(): void
    {
        $form = ['is_gift' => true, 'hide_sender' => true, 'name' => 'Ahmed Al Harthi', 'phone' => '96899110001', 'card' => '', 'card_file' => 'abc'];

        CardIdentity::check($this->shop->id, $form, []);

        $this->assertSame(['', CardIdentity::FROM_CARD], CardIdentity::message($this->shop->id, $form, []));
    }
}

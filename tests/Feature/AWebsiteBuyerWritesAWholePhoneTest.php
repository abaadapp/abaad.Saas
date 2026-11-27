<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * زبونُ الموقع يكتب رقمه كاملًا — بالقاعدة التي يُقرأ بها الرقمُ في كلّ باب.
 *
 * ═══ ما يُحرس ═══
 *
 *   · الرقمُ العُمانيّ ثماني خانات: بلا مفتاح، أو بـ+968 أو 00968، وبأرقامٍ
 *     عربيّة أو مسافات — يُكتب به الطلب.
 *   · وسبعُ خاناتٍ أو تسعٌ تُردّ برسالةٍ تقول السبب، تحت حقل الهاتف في الصفحة.
 *   · والرقمُ الدوليّ بمفتاح دولته لا يُردّ لشرط عُمان.
 *   · والطلبُ المباشر إلى الخادم بلا صفحةٍ يُسأل السؤالَ نفسَه، بالنقد وبالبطاقة.
 *   · وما رُدّ لا يترك أثرًا: لا طلب، ولا عميل، ولا نيّةَ دفع، ولا نداءَ للبوّابة،
 *     ولا خصمَ من الرفّ، ولا قيد.
 *   · والقديمُ باقٍ: عميلٌ برقمٍ قديم لا يُمسّ، ودفعةٌ فُتحت صفحتُها قبل القاعدة
 *     تُكتب طلبًا حين يصل مالُها.
 *
 * والقاعدةُ نفسُها وحالاتُها في `AnOmaniCustomerPhoneIsEightDigitsTest`.
 */
class AWebsiteBuyerWritesAWholePhoneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'Flowers', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'Muscat', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'Khuwair']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc',
            'hmac_secret' => 'hmac_ribbon', 'card_integration_id' => '4569876', 'active' => true,
        ]);
        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'Rose Bouquet', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkout(string $phone, string $pay = 'cod', string $lang = 'ar')
    {
        return $this->postJson('/s/ribbon/checkout?lang='.$lang, [
            'items' => [['id' => $this->rose->id, 'qty' => 2]],
            'name' => 'Salim', 'phone' => $phone, 'fulfil' => 'pickup', 'date' => '2027-02-12', 'pay' => $pay,
        ]);
    }

    /** كلُّ ما يكتبه طلبٌ أو يبدأه — يُعدّ قبل المحاولة وبعدها */
    private function traces(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'customers' => DB::table('customers')->count(),
            'intents' => DB::table('store_payment_intents')->count(),
            'journal' => DB::table('journal_entries')->count(),
            'stock' => (float) DB::table('products')->where('id', $this->rose->id)->value('quantity'),
        ];
    }

    public function test_the_page_sends_the_phone_as_written_and_shows_the_reason_under_it(): void
    {
        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        // الحقلُ ومكانُ خطئه تحته
        $this->assertMatchesRegularExpression('#<input[^>]*name="phone"#', $html);
        $this->assertStringContainsString('data-err="phone"', $html);
        // يُرسَل كما كُتب — والخادمُ يقرؤه، فلا قاعدةَ ثانية في الصفحة تفترق عنه
        $this->assertStringContainsString("phone: \$('[name=phone]').value", $html);
        // وكلُّ خطأٍ يعود يُكتب تحت حقله باسمه
        $this->assertStringContainsString("form.querySelector('[data-err=\"' + k + '\"]')", $html);
    }

    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return [
            'ثماني خانات' => ['91234567'],
            '+968 وثماني' => ['+96891234567'],
            '00968 وثماني' => ['0096891234567'],
            'بمسافاتٍ وشرطة' => ['+968 9123-4567'],
            'أرقامٌ عربيّة' => ['٩١٢٣٤٥٦٧'],
            'الإمارات بمفتاحها' => ['+971501234567'],
            'السعودية بـ00' => ['00966501234567'],
            'بريطانيا' => ['+44 20 7946 0958'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_a_whole_phone_places_the_order(string $phone): void
    {
        $this->checkout($phone)->assertOk()->assertJsonPath('ok', true);

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame((int) $this->shop->id, (int) $order->business_id);
        $this->assertSame(3.0, (float) $this->rose->refresh()->quantity, 'خرج من الرفّ ما بيع');
        $this->assertNotNull($order->customer_id);
        $this->assertTrue(Customer::whereKey($order->customer_id)->where('business_id', $this->shop->id)->exists());
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        $eight = 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.';

        return [
            'سبع خانات' => ['9123456', $eight],
            'سبعٌ بمسافة' => ['9123 456', $eight],
            'تسع خانات' => ['912345678', '+ أو 00'],
            '+968 وسبع' => ['+9689123456', $eight],
            '+968 وتسع' => ['+968912345678', $eight],
            '00968 وتسع' => ['00968912345678', $eight],
            'مفتاحٌ مكرّر' => ['+968 968 91234567', $eight],
            '+ بلا مفتاح' => ['+91234567', '+968'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_short_or_long_omani_phone_is_refused_with_its_reason_and_leaves_nothing(string $phone, string $reason): void
    {
        $before = $this->traces();

        $res = $this->checkout($phone)->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertStringContainsString($reason, implode(' ', $res->json('errors.phone') ?? []));
        $this->assertSame(['phone'], array_keys($res->json('errors')), 'السببُ تحت حقل الهاتف وحده');
        $this->assertSame($before, $this->traces(), 'لا طلب ولا عميل ولا خصم ولا قيد');
    }

    public function test_a_card_payment_is_asked_the_same_and_never_reaches_the_gateway(): void
    {
        Http::fake(['*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);
        $before = $this->traces();

        $this->checkout('9123456', 'card')->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.');

        Http::assertNothingSent();
        $this->assertSame($before, $this->traces(), 'لا نيّةَ دفعٍ ولا صفحةَ بوّابة');
    }

    public function test_the_english_page_gives_the_reason_in_english(): void
    {
        $this->checkout('9123456', 'cod', 'en')->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Omani phone numbers must contain exactly 8 digits.');
    }

    public function test_old_customers_and_a_payment_opened_before_the_rule_are_left_as_they_were(): void
    {
        // عميلٌ قديمٌ برقمٍ ناقص — لا يُصحَّح ولا يُحذف بطلب غيره
        $old = Customer::withoutEvents(fn () => Customer::create(['business_id' => $this->shop->id, 'name' => 'Old Buyer', 'phone' => '9123456']));
        $this->checkout('92345678')->assertOk();
        $this->assertSame('9123456', DB::table('customers')->where('id', $old->id)->value('phone'));
        $this->assertNull(DB::table('customers')->where('id', $old->id)->value('deleted_at'));

        // ودفعةٌ فُتحت صفحتُها برقمٍ قديمِ الشكل ثمّ وصل مالُها — تصير طلبًا
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);
        $this->checkout('93456789', 'card')->assertOk();
        $intent = StorePaymentIntent::latest('id')->firstOrFail();
        $payload = (array) $intent->payload;
        $payload['phone'] = '912345678';
        DB::table('store_payment_intents')->where('id', $intent->id)->update(['payload' => json_encode($payload)]);

        $obj = [
            'amount_cents' => (int) round(((float) $intent->amount) * 1000), 'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR', 'error_occured' => false, 'has_parent_transaction' => false,
            'id' => 990000 + $intent->id, 'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false,
            'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference], 'owner' => 4705, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_ribbon'), ['obj' => $obj])->assertOk();

        $order = Order::findOrFail($intent->refresh()->order_id);
        $this->assertSame('912345678', DB::table('customers')->where('id', $order->customer_id)->value('phone'), 'المالُ قُبض — فلا يُردّ طلبُه لرقمه');
    }
}

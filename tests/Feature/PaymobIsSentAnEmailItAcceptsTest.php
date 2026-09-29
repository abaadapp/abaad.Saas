<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paymob عُمان تُعطى بريدًا تقبله — وإلّا لا تُفتح دفعةٌ لأحد.
 *
 * ═══ ما وقع ═══
 *
 * صفحةُ الإتمام لا تسأل الزبونَ عن بريده، فكان يُرسَل `NA` مكانَه. وأوّلُ
 * دفعتين على الإنتاج (بمفاتيح تجريبيّة) ردّتهما البوّابةُ بـ:
 *
 *     {"billing_data":{"email":["Enter a valid email address."]}}
 *
 * فيرى الزبونُ «تعذّر فتحُ صفحة الدفع» — لكلّ زبون، ولكلّ دفعة. واختباراتُ
 * البطاقة كلُّها كانت تمرّ لأنّ Paymob فيها وهميّةٌ تقبل أيَّ شيء.
 *
 * فالبوّابةُ هنا وهميّةٌ **تفحص البريدَ كما تفحصه الحقيقيّة**، وتردّ بجوابها
 * نفسِه حرفًا.
 */
class PaymobIsSentAnEmailItAcceptsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        // Paymob لمن في قائمة المالك وحده (`storefront.paymob_businesses`) — ومتجرُ هذا الاختبار منها
        config(['storefront.paymob_businesses' => [$this->shop->id]]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        // كما هو متجرُ سعود: البطاقةُ وحدها
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '0', 'store_pay_transfer' => '0']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'Gift card', 'price' => 0.2,
            'cost' => 0, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'omn_pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '71525', 'active' => true,
        ]);

        // بوّابةٌ تفحص البريد كما تفحصه Paymob عُمان — وتردّ بجوابها نفسِه
        Http::fake(function (Request $request) {
            $email = (string) data_get($request->data(), 'billing_data.email', '');

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Http::response(['billing_data' => ['email' => ['Enter a valid email address.']]], 400);
            }

            return Http::response(['client_secret' => 'csk_x', 'intention_order_id' => 777], 201);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkout(array $over = []): TestResponse
    {
        return $this->postJson('/s/ribbon/checkout', array_merge([
            'items' => [['id' => Product::where('business_id', $this->shop->id)->value('id'), 'qty' => 1]],
            'name' => 'عدي الذهلي', 'phone' => '90683111', 'fulfil' => 'pickup',
            'date' => '2027-02-11', 'pay' => 'card',
            'recipient_name' => 'Oday Althehli', 'recipient_phone' => '90683111',
        ], $over));
    }

    private function sentEmail(): string
    {
        $sent = Http::recorded()->last();
        $this->assertNotNull($sent, 'لم يُرسَل شيءٌ إلى Paymob');

        return (string) data_get($sent[0]->data(), 'billing_data.email');
    }

    /** ما وقع على الإنتاج: زبونٌ بلا بريد — والصفحةُ لا تسأل عنه */
    public function test_a_buyer_without_an_email_still_reaches_the_payment_page(): void
    {
        $res = $this->checkout()->assertOk();

        $this->assertStringStartsWith('https://oman.paymob.com/unifiedcheckout/', $res->json('redirect'));
        $this->assertSame(Paymob::NO_EMAIL, $this->sentEmail());

        $intent = StorePaymentIntent::sole();
        $this->assertSame(StorePaymentIntent::PENDING, $intent->status);
        $this->assertNull($intent->error);
    }

    public function test_a_buyers_own_valid_email_is_passed_as_is(): void
    {
        $this->checkout(['email' => ' buyer@ribbon.om '])->assertOk();

        $this->assertSame('buyer@ribbon.om', $this->sentEmail());
    }

    /**
     * والمسافةُ حولَ البريد لا تُسقطه — ولو وصل `open` من غير صفحة الإتمام.
     *
     * صفحةُ الإتمام تقصّ ما حولَ الحقول قبل أن تبلغ الكود، فيُسأل `open`
     * هنا مباشرةً: هو بابُ البوّابة، ولا يفترض أنّ كلَّ طارقٍ يقصّ.
     */
    public function test_an_email_with_spaces_around_it_is_trimmed_by_open_itself(): void
    {
        Paymob::open($this->shop, ['name' => 'عدي', 'phone' => '90683111', 'email' => "  buyer@ribbon.om\n"], ['total' => 0.2, 'lines' => []]);

        $this->assertSame('buyer@ribbon.om', $this->sentEmail());
    }

    /** وبريدٌ مكتوبٌ خطأً لا يُسقط الدفعة — يُستبدل كما يُستبدل الغائب */
    public function test_a_malformed_email_is_replaced_not_sent(): void
    {
        foreach (['NA', 'abc', 'x@', '@y.om'] as $bad) {
            $this->checkout(['email' => $bad])->assertOk();

            $this->assertSame(Paymob::NO_EMAIL, $this->sentEmail(), "أُرسل «{$bad}» إلى Paymob");
        }
    }

    /** والبديلُ نفسُه يقبله الفحص — ونطاقُه محجوزٌ لا يستقبل رسالة */
    public function test_the_stand_in_is_valid_and_reaches_nobody(): void
    {
        $this->assertNotFalse(filter_var(Paymob::NO_EMAIL, FILTER_VALIDATE_EMAIL));
        $this->assertStringEndsWith('@example.com', Paymob::NO_EMAIL);
    }

    /** وبقيّةُ بيانات المشتري لا تُرسَل فارغة — الفراغُ تردّه البوّابةُ كذلك */
    public function test_no_billing_field_is_sent_empty(): void
    {
        $this->checkout()->assertOk();

        $billing = (array) data_get(Http::recorded()->last()[0]->data(), 'billing_data');

        foreach ($billing as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "حقلُ «{$key}» أُرسل فارغًا");
        }
    }
}

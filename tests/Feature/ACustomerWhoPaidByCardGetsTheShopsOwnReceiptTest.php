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
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\DocumentTemplates;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\Pdf;
use App\Support\Permissions;
use App\Support\Store\PaidReceipt;
use App\Support\Store\Paymob;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * من دفع ببطاقته على موقع المتجر يأخذ إيصالَه — إيصالَ المحلّ نفسَه.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) بعد إشعار Paymob الموقَّع تعرض صفحةُ الشكر «عرض الفاتورة»، ورابطُه
 *    يُخرج PDF حراريًّا — ورقةَ الصندوق بعينها (HTML واحدٌ حرفًا حرفًا)
 *    بقالب البيع وعرض شريطه ورقمه الضريبيّ، ولو فُتح بلا جلسة.
 * ٢) مغلقٌ افتراضًا لكلّ متجر: لا زرّ ولا باب حتّى يفتحه صاحبُه.
 * ٣) لا يفتحه رقمُ الطلب وحده، ولا رمزٌ خاطئ، ولا متجرٌ آخر.
 * ٤) «مدفوع» من نيّة الدفع لا من الرابط: المعلَّقُ والمرفوضُ والملغى
 *    والمسترجَعُ والطلبُ الملغى والدفعُ عند الاستلام — لا إيصال.
 * ٥) المقبضُ في الإعدادات لمن يضبط الموقع وحده.
 * ٦) إيصالُ الصندوق في اللوحة يعمل كما كان.
 */
class ACustomerWhoPaidByCardGetsTheShopsOwnReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = $this->storefront('RIBBON', 'ribbon');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
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

    private function storefront(string $name, string $slug): Business
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc',
            'hmac_secret' => 'hmac_'.$slug, 'card_integration_id' => '4569876', 'active' => true,
        ]);

        return $shop;
    }

    private function enable(Business $shop): void
    {
        MarketingSettings::save($shop->id, 'website', [PaidReceipt::KEY => '1']);
    }

    /** يفتح صفحةَ البطاقة — فتُكتب نيّةٌ معلَّقةٌ ولا طلب */
    private function intent(string $slug = 'ribbon', ?Product $product = null, string $pay = 'card'): ?StorePaymentIntent
    {
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);

        $this->postJson('/s/'.$slug.'/checkout', [
            'items' => [['id' => ($product ?? $this->rose)->id, 'qty' => 1]],
            // واسمٌ لاتينيّ: متجرٌ رقمُه في `ribbon_english_checkout_businesses` يردّ غيرَه،
            // وأرقامُ المتاجر على PostgreSQL لا تعود إلى الصفر بين الاختبارات
            'name' => 'Saud Alharthi', 'phone' => '95259066', 'fulfil' => 'pickup', 'date' => '2027-02-12', 'pay' => $pay,
        ])->assertOk();

        return StorePaymentIntent::latest('id')->first();
    }

    private function notice(StorePaymentIntent $intent, array $over = []): array
    {
        return array_merge([
            'amount_cents' => (int) round(((float) $intent->amount) * 1000), 'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR', 'error_occured' => false, 'has_parent_transaction' => false,
            'id' => 990000 + $intent->id, 'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false,
            'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference], 'owner' => 4705, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ], $over);
    }

    private function fire(StorePaymentIntent $intent, array $over = []): void
    {
        $gateway = PaymentGateway::where('business_id', $intent->business_id)->firstOrFail();
        $obj = $this->notice($intent, $over);

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, (string) $gateway->hmac_secret), ['obj' => $obj])->assertOk();
    }

    /** دفعٌ بالبطاقة ثبّته الإشعارُ الموقَّع — والطلبُ الذي كُتب له */
    private function paid(string $slug = 'ribbon', ?Product $product = null): Order
    {
        $intent = $this->intent($slug, $product);
        $this->fire($intent);

        $order = Order::findOrFail($intent->refresh()->order_id);
        $this->assertSame(StorePaymentIntent::PAID, $intent->status);

        return $order;
    }

    private function done(Order $order, string $slug = 'ribbon', string $query = ''): TestResponse
    {
        return $this->get('/s/'.$slug.'/done/'.$order->id.'?t='.WebCheckout::token($order).$query)->assertOk();
    }

    private function receiptUrl(Order $order, string $slug = 'ribbon', ?string $token = null): string
    {
        return '/s/'.$slug.'/receipt/'.$order->id.'?t='.($token ?? WebCheckout::token($order));
    }

    /** محرّكٌ يحفظ ما أُعطي ولا يرسم — ليُقارَن ما كان سيُطبع */
    private function capture(callable $fn): object
    {
        $fake = new class implements PdfDriver
        {
            public ?string $html = null;

            public ?int $width = null;

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                return response('SHEET');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                [$this->html, $this->width] = [$html, $widthMm];

                return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 100.0;
            }
        };

        $was = Pdf::swap($fake);

        try {
            $fn();
        } finally {
            Pdf::swap($was);
        }

        return $fake;
    }

    /* ═══════════ ١) دُفع وفُتح — الزرُّ والورقة ═══════════ */

    public function test_a_paid_order_shows_the_invoice_button_and_it_opens_a_thermal_pdf(): void
    {
        $this->enable($this->shop);
        $order = $this->paid();

        $html = $this->done($order)->getContent();
        $this->assertStringContainsString('data-testid="rb-receipt"', $html);
        $this->assertStringContainsString('/s/ribbon/receipt/'.$order->id.'?t='.WebCheckout::token($order), $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('عرض الفاتورة', $html);

        // والمحرّكُ الحقيقيّ: ملفُّ PDF يُعرض في المتصفّح لا يُنزَّل قسرًا
        $res = $this->get($this->receiptUrl($order))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringStartsWith('inline;', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));
    }

    /** وعائدٌ من البوّابة يرى الزرَّ نفسَه — صفحةُ العودة ترسم صفحةَ الشكر */
    public function test_the_return_page_after_the_gateway_offers_it_too(): void
    {
        $this->enable($this->shop);
        $order = $this->paid();
        $intent = StorePaymentIntent::where('order_id', $order->id)->firstOrFail();

        $html = $this->get('/s/ribbon/paying/'.$intent->reference)->assertOk()->getContent();

        $this->assertStringContainsString('/s/ribbon/receipt/'.$order->id.'?t='.WebCheckout::token($order), $html);
    }

    /**
     * والورقةُ ورقةُ الصندوق بعينها — لا نسخةٌ ثانية.
     *
     * HTML الزبون بلا جلسة هو HTML إيصال اللوحة للطلب نفسِه حرفًا حرفًا،
     * بعرض الشريط المضبوط في قالب البيع (٥٨) وبرقم المتجر الضريبيّ — وكان
     * الرقمُ يُقرأ من الجلسة فيغيب عن ورقةٍ تُرسم بلا جلسة.
     */
    public function test_the_customer_gets_the_very_receipt_the_till_prints(): void
    {
        $this->enable($this->shop);
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_number', 'value' => 'OM1100223344']);
        DocumentTemplates::save($this->shop->id, 'sale', ['strip' => '58mm']);
        $order = $this->paid();

        $public = $this->capture(fn () => $this->get($this->receiptUrl($order))->assertOk());
        $this->assertGuest();

        $till = $this->capture(fn () => $this->actingAs($this->owner)
            ->get(route('admin.orders.receipt', $order->number))->assertOk());

        $this->assertNotNull($public->html);
        $this->assertSame($till->html, $public->html, 'إيصالُ الزبون افترق عن إيصال الصندوق');
        $this->assertSame(58, $public->width);
        $this->assertStringContainsString($order->number, $public->html);
    }

    /* ═══════════ ٢) مغلقٌ افتراضًا ═══════════ */

    public function test_it_is_off_for_every_shop_until_its_owner_turns_it_on(): void
    {
        $this->assertFalse(PaidReceipt::enabled($this->shop->id));
        $this->assertSame('0', MarketingSettings::group($this->shop->id, 'website')[PaidReceipt::KEY]);

        $order = $this->paid();

        $this->assertStringNotContainsString('rb-receipt', $this->done($order)->getContent());
        $this->get($this->receiptUrl($order))->assertNotFound();
    }

    /* ═══════════ ٣) الرقمُ وحده لا يفتح ═══════════ */

    public function test_a_wrong_or_missing_token_opens_nothing(): void
    {
        $this->enable($this->shop);
        $order = $this->paid();

        $this->get($this->receiptUrl($order, token: 'wrong'))->assertNotFound();
        $this->get($this->receiptUrl($order, token: ''))->assertNotFound();
        $this->get('/s/ribbon/receipt/'.$order->id)->assertNotFound();
        // ولا رمزُ طلبٍ آخر من المتجر نفسِه
        $other = $this->paid();
        $this->get($this->receiptUrl($order, token: WebCheckout::token($other)))->assertNotFound();
        // ولا مقطعٌ ليس رقمًا
        $this->get('/s/ribbon/receipt/'.$order->id.'x?t='.WebCheckout::token($order))->assertNotFound();
    }

    /**
     * ولا يفتح عميلُ متجرٍ فاتورةَ متجرٍ آخر — ولو حمل رمزَها الصحيح.
     *
     * المتجرُ يُعرف من العنوان، والطلبُ يُطلب فيه وحده.
     */
    public function test_another_shops_order_is_not_found_here_even_with_its_own_token(): void
    {
        $other = $this->storefront('BLOOM', 'bloom');
        $tulip = Product::create([
            'business_id' => $other->id, 'name' => 'توليب', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        $this->enable($this->shop);
        $this->enable($other);

        $theirs = $this->paid('bloom', $tulip);
        $this->assertSame($other->id, (int) $theirs->business_id);

        // على عنوانها يفتح — فالمنعُ على عنوان غيرها منعُ متجرٍ لا عطب
        $this->get($this->receiptUrl($theirs, 'bloom'))->assertOk();
        $this->get($this->receiptUrl($theirs, 'ribbon'))->assertNotFound();
        $this->get('/s/ribbon/done/'.$theirs->id.'?t='.WebCheckout::token($theirs))->assertNotFound();
    }

    /* ═══════════ ٤) «مدفوع» من النيّة لا من الرابط ═══════════ */

    /** معلَّقٌ بانتظار التأكيد: لا طلبَ بعدُ، وصفحةُ العودة تنتظر بلا زرّ */
    public function test_a_pending_payment_gets_no_receipt(): void
    {
        $this->enable($this->shop);
        $intent = $this->intent();
        $this->fire($intent, ['pending' => true]);

        $this->assertNull($intent->refresh()->order_id);
        $this->assertSame(StorePaymentIntent::PENDING, $intent->status);

        $html = $this->get('/s/ribbon/paying/'.$intent->reference)->assertOk()->getContent();
        $this->assertStringNotContainsString('rb-receipt', $html);
        $this->assertStringNotContainsString('/receipt/', $html);
    }

    /** ومرفوضٌ أو ملغًى قبل التسوية: لا طلبَ ولا إيصال */
    public function test_a_declined_or_voided_payment_gets_no_receipt(): void
    {
        $this->enable($this->shop);

        foreach ([['success' => false], ['is_voided' => true]] as $flags) {
            $intent = $this->intent();
            $this->fire($intent, $flags);

            $this->assertNull($intent->refresh()->order_id);
            $this->assertNotSame(StorePaymentIntent::PAID, $intent->status);
            $this->assertStringNotContainsString('rb-receipt', $this->get('/s/ribbon/paying/'.$intent->reference)->getContent());
        }
    }

    /** واسترجاعٌ أكّدته البوّابةُ بعد الطلب يسحب الزرَّ ويغلق الباب */
    public function test_a_refunded_payment_loses_its_receipt(): void
    {
        $this->enable($this->shop);
        $order = $this->paid();
        $intent = StorePaymentIntent::where('order_id', $order->id)->firstOrFail();
        $this->get($this->receiptUrl($order))->assertOk();

        $this->fire($intent, ['is_refunded' => true, 'id' => 5550001]);

        $this->assertNotNull($intent->refresh()->refund_status);
        $this->assertFalse(PaidReceipt::settled($order->refresh()));
        $this->assertStringNotContainsString('rb-receipt', $this->done($order)->getContent());
        $this->get($this->receiptUrl($order))->assertNotFound();
    }

    /** وردٌّ طُلب ولم تؤكّده البوّابةُ بعد يكفي لسحبه — لا يُقال «مدفوع» لمالٍ في طريق عودته */
    public function test_a_refund_on_its_way_back_withdraws_the_receipt(): void
    {
        $this->enable($this->shop);
        $order = $this->paid();
        $this->get($this->receiptUrl($order))->assertOk();

        StorePaymentIntent::where('order_id', $order->id)->update(['refund_status' => StorePaymentIntent::REFUND_PENDING]);

        $this->assertStringNotContainsString('rb-receipt', $this->done($order)->getContent());
        $this->get($this->receiptUrl($order))->assertNotFound();
    }

    /** والطلبُ الملغى لا إيصالَ «مدفوع» له وإن دُفع */
    public function test_a_cancelled_order_gets_no_receipt(): void
    {
        $this->enable($this->shop);
        $order = $this->paid();

        $order->forceFill(['status' => OrderStatus::CANCELLED])->save();

        $this->assertStringNotContainsString('rb-receipt', $this->done($order)->getContent());
        $this->get($this->receiptUrl($order))->assertNotFound();
    }

    /**
     * وما في الرابط لا يصنع دفعًا.
     *
     * طلبُ «الدفع عند الاستلام» برمزه الصحيح، ومعه `success=true` وما شابهها:
     * لا زرّ ولا ورقة. ونيّةٌ كُتب عليها `paid` بلا معرّف عمليّةٍ من البوّابة
     * لا تشهد أيضًا — الإشعارُ الموقَّع وحده يكتبهما معًا.
     */
    public function test_nothing_in_the_link_makes_an_order_paid(): void
    {
        $this->enable($this->shop);
        $this->intent(pay: 'cod');
        $cod = Order::latest('id')->firstOrFail();
        $this->assertSame('غير مدفوع', $cod->payment_status);

        $tricks = '&success=true&paid=1&status=paid&pending=false';
        $this->assertStringNotContainsString('rb-receipt', $this->done($cod, query: $tricks)->getContent());
        $this->get($this->receiptUrl($cod).$tricks)->assertNotFound();

        // ونيّةٌ تزعم الدفعَ بلا عمليّةٍ ثبتت
        StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'order_id' => $cod->id, 'reference' => 'WEB-forged',
            'status' => StorePaymentIntent::PAID, 'amount' => 20, 'currency' => 'OMR', 'payload' => [], 'lang' => 'ar',
        ]);
        $this->get($this->receiptUrl($cod))->assertNotFound();
    }

    /** ونيّةُ متجرٍ آخر بمعرّف هذا الطلب لا تشهد له */
    public function test_another_shops_intent_does_not_vouch_for_this_order(): void
    {
        $other = $this->storefront('BLOOM', 'bloom');
        $this->enable($this->shop);
        $this->intent(pay: 'cod');
        $cod = Order::latest('id')->firstOrFail();

        StorePaymentIntent::create([
            'business_id' => $other->id, 'order_id' => $cod->id, 'reference' => 'WEB-elsewhere',
            'status' => StorePaymentIntent::PAID, 'provider_transaction_id' => 'tx-elsewhere',
            'amount' => 20, 'currency' => 'OMR', 'payload' => [], 'lang' => 'ar',
        ]);

        $this->assertFalse(PaidReceipt::settled($cod));
        $this->get($this->receiptUrl($cod))->assertNotFound();
    }

    /* ═══════════ ٥) المقبضُ لمن يضبط الموقع ═══════════ */

    public function test_the_owner_turns_it_on_from_the_store_settings(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), [PaidReceipt::KEY => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue(PaidReceipt::enabled($this->shop->id));
        // ولمتجره وحده
        $other = $this->storefront('BLOOM', 'bloom');
        $this->assertFalse(PaidReceipt::enabled($other->id));
    }

    public function test_a_marketer_without_website_configure_cannot_turn_it_on(): void
    {
        $marketer = User::create([
            'business_id' => $this->shop->id, 'name' => 'مسوّق', 'email' => 'm@abaad.om', 'password' => bcrypt('x'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'marketing'],
        ]);
        $this->assertFalse($marketer->may(Permissions::WEBSITE_CONFIGURE));

        $this->actingAs($marketer)
            ->post(route('admin.marketing.store.save'), [PaidReceipt::KEY => true])
            ->assertForbidden();

        $this->assertFalse(PaidReceipt::enabled($this->shop->id));
    }

    /* ═══════════ ٦) إيصالُ اللوحة كما كان ═══════════ */

    public function test_the_tills_own_receipt_still_prints(): void
    {
        $order = $this->paid();

        $res = $this->actingAs($this->owner)->get(route('admin.orders.receipt', $order->number))->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}

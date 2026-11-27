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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الدفعُ بالبطاقة يعبر نقلَ المتجر إلى نطاقه الفرعيّ — ولا يضيع منه شيء.
 *
 * RIBBON ينتقل من `app.abaadapp.om/s/ribbon` إلى `ribbon.abaadapp.om`. وما
 * يُحرَس هنا ما بين الزبون والمال:
 *
 *   - دفعةٌ فُتحت **قبل** النقل تعود إلى الرابط القديم الذي كُتب لـPaymob —
 *     فيتحوّل بمعلمات Paymob كما كتبتها حرفًا، وتُفتح النتيجةُ على النطاق.
 *   - النجاحُ والرفضُ والإلغاءُ وانقضاءُ المهلة: كلٌّ يصل صفحةً لا خطأً،
 *     ولا يُكتب طلبٌ إلّا بإشعارٍ موقَّع.
 *   - Visa وMastercard سواء: الإشعارُ الموقَّع يُنشئ طلبًا واحدًا مهما تكرّر،
 *     والمزوَّرُ لا يُنشئ شيئًا.
 *   - صفحةُ العودة لا تحتاج جلسةً: تُفتح على نطاقٍ لم يرَ الزبونَ من قبل.
 *   - ومتجرٌ آخر: يعود زبونُه إلى `/s/اسمه` كما كان، ولا يُحوَّل.
 *
 * وهذه محاكاةٌ لـPaymob (`Http::fake` وإشعاراتٌ موقَّعةٌ بسرٍّ تجريبيّ) — لا
 * بوّابةَ حقيقيّةً ولا بطاقة.
 */
class APaymentSurvivesTheShopsMoveToItsSubdomainTest extends TestCase
{
    use RefreshDatabase;

    private Business $ribbon;

    private Business $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        // قوائمُ متجر سعود تُفرَّغ: المعرّفُ ٥ قد يقع على أحد متجرَي الاختبار
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false]);

        // معرّفاتٌ صريحة: تسلسلُ PostgreSQL لا يرجع بين الاختبارات فيقع أحدُها على ٥
        $this->ribbon = $this->shop('RIBBON', 'ribbon', 'ribbon@shop.test', 'hmac-ribbon', id: 301);
        $this->other = $this->shop('BLOOM', 'bloom', 'bloom@shop.test', 'hmac-bloom', id: 302);

        Http::fake(['oman.paymob.com/v1/intention/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => 777], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(string $name, string $slug, string $email, string $hmac, int $id): Business
    {
        $shop = (new Business)->forceFill([
            'id' => $id, 'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000000',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
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
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_'.$slug, 'secret_key' => 'sk_test_'.$slug, 'hmac_secret' => $hmac,
            'card_integration_id' => '4569876', 'active' => true,
        ]);

        return $shop;
    }

    /** النقلُ كما يقع بالنشر: المتجرُ يدخل القائمة */
    private function move(): void
    {
        config(['storefront.subdomain_businesses' => [$this->ribbon->id => 'ribbon']]);
    }

    /** دفعةٌ بالبطاقة تُفتح من صفحة الإتمام على هذا العنوان — ويُردّ ما كُتب لـPaymob */
    private function pay(Business $shop, string $checkout): array
    {
        $this->postJson($checkout, [
            'items' => [['id' => (int) Product::where('business_id', $shop->id)->value('id'), 'qty' => 1]],
            'name' => 'زبون تجربة', 'phone' => '95259066', 'fulfil' => 'pickup',
            'date' => '2027-02-12', 'pay' => 'card',
        ])->assertOk();

        $intent = StorePaymentIntent::where('business_id', $shop->id)->latest('id')->firstOrFail();
        $sent = Http::recorded(fn (Sent $r) => str_contains($r->url(), '/v1/intention/'))->last()[0];

        return [$intent, $sent];
    }

    /** إشعارُ Paymob كما يصل — موقَّعًا بسرّ المتجر */
    private function notice(StorePaymentIntent $intent, int $transaction, array $over = []): array
    {
        return array_merge([
            'amount_cents' => 20000, 'created_at' => '2027-02-10T10:00:10.100000', 'currency' => 'OMR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => $transaction,
            'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference],
            'owner' => 4705, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true,
        ], $over);
    }

    private function fire(array $obj, string $secret, ?string $hmac = null): void
    {
        $this->postJson('https://app.abaadapp.om/webhooks/paymob?hmac='.($hmac ?? Paymob::signature($obj, $secret)), ['type' => 'TRANSACTION', 'obj' => $obj])
            ->assertOk();
    }

    /** ما تُلحقه Paymob بعنوان العودة — بنقاطه وترميزه، كما يصل حرفًا */
    private function paymobReturnQuery(string $reference, string $success): string
    {
        return 'id=998877&pending=false&amount_cents=20000&success='.$success
            .'&is_voided=false&is_refunded=false&created_at=2027-02-10T10%3A00%3A10.100000'
            .'&data.message=Approved&source_data.sub_type=MasterCard&source_data.pan=2346'
            .'&merchant_order_id='.$reference.'&hmac=9f2c1a7e0b';
    }

    /**
     * وصفحةُ الدفع نفسُها لا تتغيّر — ما يُرسَل إلى Paymob قبل النقل وبعده سواء
     * إلّا عنوانَ العودة.
     *
     * صفحةُ Paymob المستضافة نفسُها، بالمفتاح العامّ نفسِه، وطرقُ الدفع نفسُها
     * (البطاقة وOmanNet وApple Pay)، والإشعارُ إلى النظام نفسِه.
     */
    public function test_the_payment_page_and_all_sent_to_paymob_stay_the_same_except_the_return_address(): void
    {
        PaymentGateway::where('business_id', $this->ribbon->id)->update(['omannet_integration_id' => '5550001', 'apple_pay_integration_id' => '5550002']);

        $page = fn (string $checkout) => (string) $this->postJson($checkout, [
            'items' => [['id' => (int) Product::where('business_id', $this->ribbon->id)->value('id'), 'qty' => 1]],
            'name' => 'زبون تجربة', 'phone' => '95259066', 'fulfil' => 'pickup', 'date' => '2027-02-12', 'pay' => 'card',
        ])->assertOk()->json('redirect');
        $sent = fn () => Http::recorded(fn (Sent $r) => str_contains($r->url(), '/v1/intention/'))->last()[0];

        $beforePage = $page('https://app.abaadapp.om/s/ribbon/checkout');
        $before = $sent()->data();
        $beforeHeaders = $sent()->headers();

        $this->move();

        $afterPage = $page('https://ribbon.abaadapp.om/checkout');
        $after = $sent()->data();

        $this->assertSame('https://oman.paymob.com/unifiedcheckout/?publicKey=pk_test_ribbon&clientSecret=csk_x', $beforePage);
        $this->assertSame($beforePage, $afterPage, 'تغيّرت صفحةُ الدفع');
        $this->assertSame([4569876, 5550001, 5550002], $after['payment_methods']);
        $this->assertSame($beforeHeaders['Authorization'], $sent()->headers()['Authorization']);

        $this->assertStringStartsWith('https://app.abaadapp.om/s/ribbon/paying/web-', $before['redirection_url']);
        $this->assertStringStartsWith('https://ribbon.abaadapp.om/paying/web-', $after['redirection_url']);

        $same = fn (array $d) => array_diff_key($d, array_flip(['redirection_url', 'special_reference']));
        $this->assertSame($same($before), $same($after), 'تغيّر ما يُرسَل إلى Paymob غيرَ عنوان العودة');
    }

    /**
     * دفعةٌ فُتحت قبل النشر، ونجحت، وعاد صاحبُها بعده.
     *
     * عنوانُ العودة كُتب لـPaymob على `/s/ribbon` لأنّ النقلَ لم يقع بعد. فيصل
     * الزائرُ إليه بعد النشر — فيُحوَّل بمعلمات Paymob كما هي، ويرى فاتورتَه على
     * النطاق الجديد بلا جلسةٍ من هناك.
     */
    public function test_a_payment_opened_before_the_move_comes_back_paid_on_the_new_address(): void
    {
        [$intent, $sent] = $this->pay($this->ribbon, 'https://app.abaadapp.om/s/ribbon/checkout');
        $this->assertSame('https://app.abaadapp.om/s/ribbon/paying/'.$intent->reference, $sent['redirection_url']);

        $this->move();

        // والإشعارُ إلى النظام كما كُتب — لا يمرّ بالنطاق
        $this->fire($this->notice($intent, 998877, ['source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card']]), 'hmac-ribbon');
        $this->assertSame(1, Order::where('business_id', $this->ribbon->id)->count());

        $query = $this->paymobReturnQuery($intent->reference, 'true');
        $to = (string) $this->get($sent['redirection_url'].'?'.$query)
            ->assertStatus(302)->headers->get('Location');
        $this->assertSame('https://ribbon.abaadapp.om/paying/'.$intent->reference.'?'.$query, $to);

        $this->get($to)->assertOk()->assertSee('rb-order-number', false);
        $this->assertSame(1, Order::where('business_id', $this->ribbon->id)->count(), 'كتبت صفحةُ العودة طلبًا');
    }

    /** وعاد قبل أن يصل الإشعار: «نؤكّد دفعتك» على النطاق — لا خطأ ولا طلب */
    public function test_coming_back_before_the_notice_waits_on_the_new_address(): void
    {
        [$intent, $sent] = $this->pay($this->ribbon, 'https://app.abaadapp.om/s/ribbon/checkout');
        $this->move();

        $to = (string) $this->get($sent['redirection_url'].'?'.$this->paymobReturnQuery($intent->reference, 'true'))
            ->assertStatus(302)->headers->get('Location');

        $this->get($to)->assertOk()->assertSee('rb-paying', false)->assertDontSee('rb-order-number', false);
        $this->assertSame(0, Order::count());
        $this->assertSame(StorePaymentIntent::PENDING, $intent->refresh()->status);
    }

    /**
     * ورفضٌ أو إلغاء: صفحةٌ على النطاق، ولا طلب، ولا مخزونٌ يُمسّ.
     *
     * «نجح» في رابط العودة لا تُصدَّق — والإشعارُ المرفوضُ يُكتب سببُه ولا يُغلق النيّة.
     */
    public function test_a_declined_or_cancelled_payment_comes_back_to_a_page_and_writes_nothing(): void
    {
        [$intent, $sent] = $this->pay($this->ribbon, 'https://app.abaadapp.om/s/ribbon/checkout');
        $this->move();

        // ألغاه الزائرُ من صفحة Paymob: يعود ولا إشعارَ نجاح — ولو كتب «نجح» بيده
        foreach (['false', 'true'] as $claimed) {
            $to = (string) $this->get($sent['redirection_url'].'?'.$this->paymobReturnQuery($intent->reference, $claimed))
                ->assertStatus(302)->headers->get('Location');
            $this->get($to)->assertOk()->assertSee('rb-paying', false);
        }

        // ورفضه البنك: إشعارٌ موقَّعٌ بـ`success = false`
        $this->fire($this->notice($intent, 998878, ['success' => false, 'data' => ['message' => 'Do not honour']]), 'hmac-ribbon');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, (int) Product::where('business_id', $this->ribbon->id)->value('quantity'));
        $this->assertSame(StorePaymentIntent::PENDING, $intent->refresh()->status);
        $this->assertNotNull($intent->error);
        $this->get('https://ribbon.abaadapp.om/paying/'.$intent->reference)->assertOk()->assertSee('rb-paying', false);
    }

    /** وانقضت مهلتُها (ساعة) ثمّ عاد: صفحةٌ لا خطأ، ولا طلب */
    public function test_an_expired_payment_comes_back_to_a_page_not_an_error(): void
    {
        [$intent, $sent] = $this->pay($this->ribbon, 'https://app.abaadapp.om/s/ribbon/checkout');
        $this->assertSame(Paymob::EXPIRES, $sent['expiration']);
        $this->move();

        Carbon::setTestNow(now()->addSeconds(Paymob::EXPIRES + 60));
        $this->assertTrue($intent->refresh()->expires_at->isPast());

        $to = (string) $this->get($sent['redirection_url'])->assertStatus(302)->headers->get('Location');
        $this->assertSame('https://ribbon.abaadapp.om/paying/'.$intent->reference, $to);
        $this->get($to)->assertOk()->assertSee('rb-paying', false);
        $this->assertSame(0, Order::count());
    }

    /**
     * Visa وMastercard بعد النقل: دفعتان من النطاق، كلٌّ بطلبٍ واحد.
     *
     * والإشعارُ يُعاد ثلاثًا فلا يتكرّر الطلب، والموقَّعُ بغير سرّ المتجر لا يُنشئ شيئًا.
     */
    public function test_visa_and_mastercard_each_make_one_order_after_the_move_and_a_forgery_makes_none(): void
    {
        $this->move();

        [$visa, $sentVisa] = $this->pay($this->ribbon, 'https://ribbon.abaadapp.om/checkout');
        [$master, $sentMaster] = $this->pay($this->ribbon, 'https://ribbon.abaadapp.om/checkout');
        [$forged] = $this->pay($this->ribbon, 'https://ribbon.abaadapp.om/checkout');

        foreach ([$sentVisa, $sentMaster] as $sent) {
            $this->assertStringStartsWith('https://ribbon.abaadapp.om/paying/web-', $sent['redirection_url']);
            $this->assertSame(rtrim(config('app.url'), '/').'/webhooks/paymob', $sent['notification_url']);
        }

        $visaNotice = $this->notice($visa, 5001);
        $masterNotice = $this->notice($master, 5002, ['source_data' => ['pan' => '5100', 'sub_type' => 'MasterCard', 'type' => 'card']]);

        foreach (range(1, 3) as $_) {
            $this->fire($visaNotice, 'hmac-ribbon');
            $this->fire($masterNotice, 'hmac-ribbon');
        }

        // مزوَّر: توقيعٌ باطل، ثمّ توقيعٌ بسرّ متجرٍ آخر
        $this->fire($this->notice($forged, 5003), 'hmac-ribbon', 'deadbeef');
        $this->fire($this->notice($forged, 5004), 'hmac-bloom');

        $this->assertSame(2, Order::where('business_id', $this->ribbon->id)->count());
        $this->assertSame(3, (int) Product::where('business_id', $this->ribbon->id)->value('quantity'));
        $this->assertSame(StorePaymentIntent::PAID, $visa->refresh()->status);
        $this->assertSame(StorePaymentIntent::PAID, $master->refresh()->status);
        $this->assertSame(StorePaymentIntent::PENDING, $forged->refresh()->status);

        $this->get($sentVisa['redirection_url'])->assertOk()->assertSee('rb-order-number', false);
        $this->get($sentMaster['redirection_url'])->assertOk()->assertSee('rb-order-number', false);
    }

    /** ودفعةُ متجرٍ آخر لا تُفتح على نطاق RIBBON — ولو عُرف مرجعُها */
    public function test_another_shops_payment_is_not_found_on_this_shops_address(): void
    {
        $this->move();
        [$bloom] = $this->pay($this->other, 'https://app.abaadapp.om/s/bloom/checkout');

        $this->get('https://ribbon.abaadapp.om/paying/'.$bloom->reference)->assertNotFound();
    }

    /** وتاجرٌ آخر: يعود زبونُه إلى `/s/اسمه` كما كان، بلا تحويل، ويُثبَّت دفعُه */
    public function test_another_merchants_card_payment_is_exactly_as_before(): void
    {
        $this->move();
        [$intent, $sent] = $this->pay($this->other, 'https://app.abaadapp.om/s/bloom/checkout');

        $this->assertSame('https://app.abaadapp.om/s/bloom/paying/'.$intent->reference, $sent['redirection_url']);
        $this->assertSame(rtrim(config('app.url'), '/').'/webhooks/paymob', $sent['notification_url']);

        $back = $sent['redirection_url'].'?'.$this->paymobReturnQuery($intent->reference, 'true');
        $this->get($back)->assertOk()->assertSee('rb-paying', false);

        $this->fire($this->notice($intent, 6001), 'hmac-bloom');
        $this->get($back)->assertOk()->assertSee('rb-order-number', false);
        $this->assertSame(1, Order::where('business_id', $this->other->id)->count());
        $this->assertSame(0, Order::where('business_id', $this->ribbon->id)->count());
    }

    /**
     * والجلسةُ على كلّ نطاقٍ له وحده — لا كعكةَ تُكتب لـ`abaadapp.om` كلِّه.
     *
     * فلا يُشارك متجرٌ جلسةَ اللوحة، والدفعُ لا يتّكئ على جلسة: صفحةُ العودة
     * أعلاه تُفتح على نطاقٍ لم يرَ الزبون.
     */
    public function test_the_new_address_keeps_its_cookies_to_itself(): void
    {
        $this->move();

        $cookies = $this->get('https://ribbon.abaadapp.om/')->assertOk()->headers->getCookies();

        $this->assertNotEmpty($cookies);
        foreach ($cookies as $cookie) {
            $this->assertNull($cookie->getDomain(), $cookie->getName().' كُتبت لنطاقٍ أوسع');
        }
    }

    /**
     * وغيّر سعودٌ اسمَه بعد النقل: يعود زبونُه من Paymob إلى نطاقه المثبَّت —
     * من الإتمام على النطاق، أو على بابه الجديد، أو من صفحةٍ قديمةٍ على `/s/ribbon`.
     */
    public function test_after_a_rename_paymob_still_returns_the_customer_to_the_pinned_domain(): void
    {
        $this->move();
        $this->ribbon->forceFill(['site_slug' => 'ribbon-flowers'])->save();

        foreach (['https://ribbon.abaadapp.om/checkout', 'https://app.abaadapp.om/s/ribbon-flowers/checkout', 'https://app.abaadapp.om/s/ribbon/checkout'] as $checkout) {
            [$intent, $sent] = $this->pay($this->ribbon, $checkout);

            $this->assertSame('https://ribbon.abaadapp.om/paying/'.$intent->reference, $sent['redirection_url'], $checkout);
            $this->assertSame(rtrim(config('app.url'), '/').'/webhooks/paymob', $sent['notification_url']);
            $this->get($sent['redirection_url'])->assertOk()->assertSee('rb-paying', false);
        }

        $this->fire($this->notice($intent, 7001), 'hmac-ribbon');
        $this->get($sent['redirection_url'])->assertOk()->assertSee('rb-order-number', false);
    }
}

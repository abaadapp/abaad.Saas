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
use App\Support\Pdf;
use App\Support\Store\PaidReceipt;
use App\Support\Store\Paymob;
use App\Support\Store\ThankYouPage;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صفحةُ الشكر تقول ما كتبه صاحبُ المتجر — بلغة الصفحة، وفوق طلبٍ لا يكتبه أحد.
 *
 * ═══ ما يُحرس ═══
 *
 *   · العنوانُ والرسالةُ والزرّان بما كتبه التاجر لكلّ لغة، والفارغُ نصُّ
 *     النظام بلغة الصفحة — ولا يقع نصُّ لغةٍ على الأخرى.
 *   · ومتجرٌ لم يكتب شيئًا يرى صفحتَه كما كانت قبلها: العنوانُ نفسُه و«عرض
 *     الفاتورة» نفسُه، ولا رسالةَ جديدة. فالقدرةُ لكلّ متجر، ولا يُفتح بها
 *     شيءٌ لمتجرٍ لم يطلبه.
 *   · رقمُ الطلب وأصنافُه ومبالغُه وطريقةُ دفعه باقيةٌ أيًّا كان ما كُتب.
 *   · زرُّ الإيصال لمن دفع دفعًا ثبت ومتجرُه فتحه، وحده — والنصوصُ لا تفتحه.
 *   · والنصُّ نصٌّ: لا وسمَ يُرسم منه، ولا متجرَ يقرأ نصوصَ غيره.
 *   · وإيصالُ الصفحة الإنجليزيّة بترويسة القالب وتذييله الإنجليزيّين.
 *
 * وأمانُ الإيصال وأهليّتُه في `ACustomerWhoPaidByCardGetsTheShopsOwnReceiptTest`.
 */
class AThankYouPageSpeaksTheShopsWordsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = $this->storefront('RIBBON', 'ribbon');
        User::create(['business_id' => $this->shop->id, 'name' => 'Saud', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->rose = $this->product($this->shop);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function storefront(string $name, string $slug): Business
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'Flowers', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'Muscat', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'Khuwair']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc',
            'hmac_secret' => 'hmac_'.$slug, 'card_integration_id' => '4569876', 'active' => true,
        ]);

        return $shop;
    }

    private function product(Business $shop): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => 'Rose Bouquet', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** طلبٌ من الإتمام — بالبطاقة يمرّ بالبوّابة، وما سواها يُكتب فورًا */
    private function order(string $slug = 'ribbon', ?Product $product = null, string $pay = 'card'): Order
    {
        Http::fake(['oman.paymob.com/*' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);

        $res = $this->postJson('/s/'.$slug.'/checkout', [
            'items' => [['id' => ($product ?? $this->rose)->id, 'qty' => 2]],
            'name' => 'Saud Alharthi', 'phone' => '95259066', 'fulfil' => 'pickup', 'date' => '2027-02-12', 'pay' => $pay,
        ])->assertOk();

        if ($pay !== 'card') {
            return Order::latest('id')->firstOrFail();
        }

        $intent = StorePaymentIntent::latest('id')->firstOrFail();
        $gateway = PaymentGateway::where('business_id', $intent->business_id)->firstOrFail();
        $obj = [
            'amount_cents' => (int) round(((float) $intent->amount) * 1000), 'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR', 'error_occured' => false, 'has_parent_transaction' => false,
            'id' => 990000 + $intent->id, 'integration_id' => 4569876, 'is_3d_secure' => true, 'is_auth' => false,
            'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => $intent->reference], 'owner' => 4705, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'], 'success' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, (string) $gateway->hmac_secret), ['obj' => $obj])->assertOk();

        return Order::findOrFail($intent->refresh()->order_id);
    }

    private function done(Order $order, string $lang = 'ar', string $slug = 'ribbon'): string
    {
        return $this->get('/s/'.$slug.'/done/'.$order->id.'?t='.WebCheckout::token($order).'&lang='.$lang)
            ->assertOk()->getContent();
    }

    /** نصُّ عنصرٍ بمعرّف اختباره — مفكوكَ الترميز */
    private function text(string $html, string $testId): ?string
    {
        if (! preg_match('#data-testid="'.$testId.'"[^>]*>(.*?)</#su', $html, $m)) {
            return null;
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    private function words(Business $shop, array $values): void
    {
        MarketingSettings::save($shop->id, 'website', $values);
    }

    /** محرّكٌ يحفظ ما أُعطي ولا يرسم */
    private function capture(callable $fn): object
    {
        $fake = new class implements PdfDriver
        {
            public ?string $html = null;

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                return response('SHEET');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                $this->html = $html;

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

    /* ═════════════ ١ · ٢ · بما كتبه التاجر لكلّ لغة ═════════════ */

    public function test_the_arabic_page_shows_the_arabic_words_and_the_english_page_the_english(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [PaidReceipt::KEY => '1']);
        $this->words($this->shop, [
            'store_thanks_title' => 'شكرًا من ريبون',
            'store_thanks_message' => 'نجهّز باقتك الآن',
            'store_thanks_receipt' => 'إيصالك',
            'store_thanks_continue' => 'تسوّق المزيد',
            'store_thanks_title_en' => 'Thanks from Ribbon',
            'store_thanks_message_en' => 'We are preparing your bouquet',
            'store_thanks_receipt_en' => 'Your receipt',
            'store_thanks_continue_en' => 'Shop more',
        ]);
        $order = $this->order();

        $ar = $this->done($order, 'ar');
        $this->assertSame('شكرًا من ريبون', $this->text($ar, 'rb-thanks-title'));
        $this->assertSame('نجهّز باقتك الآن', $this->text($ar, 'rb-thanks-message'));
        $this->assertSame('إيصالك', $this->text($ar, 'rb-receipt'));
        $this->assertSame('تسوّق المزيد', $this->text($ar, 'rb-continue'));
        $this->assertStringNotContainsString('Thanks from Ribbon', $ar);

        $en = $this->done($order, 'en');
        $this->assertSame('Thanks from Ribbon', $this->text($en, 'rb-thanks-title'));
        $this->assertSame('We are preparing your bouquet', $this->text($en, 'rb-thanks-message'));
        $this->assertSame('Your receipt', $this->text($en, 'rb-receipt'));
        $this->assertSame('Shop more', $this->text($en, 'rb-continue'));
        $this->assertStringNotContainsString('شكرًا من ريبون', $en);
    }

    /* ═════════════ ٣ · ٤ · والفارغُ نصُّ النظام بلغة الصفحة ═════════════ */

    public function test_empty_words_fall_back_to_the_system_text_of_the_same_language(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [PaidReceipt::KEY => '1']);
        // كتب العربيّةَ وحدها — فلا تقع على الإنجليزيّة
        $this->words($this->shop, ['store_thanks_title' => 'شكرًا من ريبون']);
        $order = $this->order();

        $ar = $this->done($order, 'ar');
        $this->assertSame('شكرًا من ريبون', $this->text($ar, 'rb-thanks-title'));
        $this->assertNull($this->text($ar, 'rb-thanks-message'), 'رسالةٌ فارغةٌ لا تُعرض');
        $this->assertSame('عرض الفاتورة', $this->text($ar, 'rb-receipt'));
        $this->assertSame('متابعة التسوّق', $this->text($ar, 'rb-continue'));

        $en = $this->done($order, 'en');
        $this->assertSame('Thank you, your order is received', $this->text($en, 'rb-thanks-title'));
        $this->assertNull($this->text($en, 'rb-thanks-message'));
        $this->assertSame('View invoice', $this->text($en, 'rb-receipt'));
        $this->assertSame('Continue shopping', $this->text($en, 'rb-continue'));
    }

    /**
     * ومتجرٌ لم يكتب شيئًا يرى صفحتَه كما كانت — بعنوانها وزرّيها، ولا سطرَ جديدًا.
     *
     * ونصوصُ النظام هي ما كان يُعرض بعينه — لا «عرض الإيصال الحراري»: تلك
     * عبارةُ من يختارها لمتجره، لا عبارةٌ تُفرض على كلّ متجر.
     */
    public function test_a_shop_that_wrote_nothing_keeps_its_page(): void
    {
        $this->assertSame([
            'title' => 'شكراً لك، تم استلام طلبك',
            'message' => '',
            'receipt' => 'عرض الفاتورة',
            'continue' => 'متابعة التسوّق',
        ], ThankYouPage::texts($this->shop->id, 'ar'));

        $this->assertSame([
            'title' => 'Thank you, your order is received',
            'message' => '',
            'receipt' => 'View invoice',
            'continue' => 'Continue shopping',
        ], ThankYouPage::texts($this->shop->id, 'en'));

        MarketingSettings::save($this->shop->id, 'website', [PaidReceipt::KEY => '1']);
        $html = $this->done($this->order());
        $this->assertNull($this->text($html, 'rb-thanks-message'));
        $this->assertSame('عرض الفاتورة', $this->text($html, 'rb-receipt'));
    }

    /**
     * ومتجرٌ يسمّي زرَّه لنفسه — ولا يبلغ اسمُه متجرًا آخر، ولا لغتَه الأخرى.
     *
     * وهي الحالُ التي بُنيت لها القدرة: متجرٌ يريد «عرض الإيصال الحراري»
     * و«View thermal receipt»، وغيرُه يبقى على «عرض الفاتورة».
     */
    public function test_one_shop_names_its_receipt_button_and_no_other_shop_hears_it(): void
    {
        $bloom = $this->storefront('BLOOM', 'bloom');
        MarketingSettings::save($this->shop->id, 'website', [PaidReceipt::KEY => '1']);
        MarketingSettings::save($bloom->id, 'website', [PaidReceipt::KEY => '1']);
        $this->words($this->shop, [
            'store_thanks_receipt' => 'عرض الإيصال الحراري',
            'store_thanks_receipt_en' => 'View thermal receipt',
        ]);

        $mine = $this->order();
        $theirs = $this->order('bloom', $this->product($bloom));

        $this->assertSame('عرض الإيصال الحراري', $this->text($this->done($mine), 'rb-receipt'));
        $this->assertSame('View thermal receipt', $this->text($this->done($mine, 'en'), 'rb-receipt'));

        $this->assertSame('عرض الفاتورة', $this->text($this->done($theirs, 'ar', 'bloom'), 'rb-receipt'));
        $this->assertSame('View invoice', $this->text($this->done($theirs, 'en', 'bloom'), 'rb-receipt'));

        // ولا تقع لغةٌ على الأخرى: كتب العربيّةَ وحدها فبقيت الإنجليزيّةُ نصَّ النظام
        $this->words($this->shop, ['store_thanks_receipt_en' => '']);
        $this->assertSame('View invoice', $this->text($this->done($mine, 'en'), 'rb-receipt'));
        $this->assertSame('عرض الإيصال الحراري', $this->text($this->done($mine), 'rb-receipt'));
    }

    /* ═════════════ ٥ · الطلبُ باقٍ أيًّا كان النصّ ═════════════ */

    public function test_the_order_itself_stays_on_the_page_whatever_the_words(): void
    {
        $this->words($this->shop, [
            'store_thanks_title' => 'عنوان', 'store_thanks_message' => 'رسالة',
            'store_thanks_receipt' => 'زر', 'store_thanks_continue' => 'تابع',
        ]);
        $order = $this->order(pay: 'cod');

        $html = $this->done($order);

        $this->assertSame($order->number, $this->text($html, 'rb-order-number'));
        $this->assertStringContainsString('Rose Bouquet × 2', $html);
        $this->assertStringContainsString('40.000', $html, 'الإجماليّ من الطلب');
        $this->assertStringContainsString('عند الاستلام', $html, 'وطريقةُ الدفع');
    }

    /* ═════════════ ٦ · ٧ · ٨ · الزرُّ لمن دفع وفتح متجرُه ═════════════ */

    public function test_the_receipt_button_follows_payment_and_the_shops_switch_not_the_words(): void
    {
        $this->words($this->shop, ['store_thanks_receipt' => 'إيصالك']);

        // مُطفأٌ في المتجر: لا زرّ ولو دُفع — والنصُّ المكتوبُ لا يفتحه
        $paid = $this->order();
        $this->assertNull($this->text($this->done($paid), 'rb-receipt'));

        MarketingSettings::save($this->shop->id, 'website', [PaidReceipt::KEY => '1']);
        $this->assertSame('إيصالك', $this->text($this->done($paid), 'rb-receipt'));

        // ودفعٌ عند الاستلام: لا زرّ
        $cod = $this->order(pay: 'cod');
        $this->assertNull($this->text($this->done($cod), 'rb-receipt'));
    }

    /* ═════════════ ٩ · النصُّ نصٌّ ═════════════ */

    public function test_the_words_are_printed_as_text_never_as_markup(): void
    {
        $this->words($this->shop, ['store_thanks_message' => '<script>alert(1)</script><b>x</b>']);
        $order = $this->order(pay: 'cod');

        $html = $this->done($order);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_words_have_limits(): void
    {
        $owner = User::where('business_id', $this->shop->id)->firstOrFail();

        $this->actingAs($owner)->post(route('admin.marketing.store.save'), [
            'store_thanks_title' => str_repeat('a', 121),
            'store_thanks_receipt_en' => str_repeat('a', 41),
        ])->assertSessionHasErrors(['store_thanks_title', 'store_thanks_receipt_en']);
    }

    /* ═════════════ ١٠ · ولا متجرَ يقرأ نصوصَ غيره ═════════════ */

    public function test_another_shops_words_never_reach_this_page(): void
    {
        $bloom = $this->storefront('BLOOM', 'bloom');
        $this->words($bloom, ['store_thanks_title' => 'شكرًا من بلوم', 'store_thanks_title_en' => 'Thanks from Bloom']);
        $this->words($this->shop, ['store_thanks_title' => 'شكرًا من ريبون']);

        $mine = $this->order(pay: 'cod');
        $theirs = $this->order('bloom', $this->product($bloom), 'cod');

        $this->assertSame('شكرًا من ريبون', $this->text($this->done($mine), 'rb-thanks-title'));
        $this->assertSame('شكرًا من بلوم', $this->text($this->done($theirs, 'ar', 'bloom'), 'rb-thanks-title'));
        $this->assertSame('Thank you, your order is received', $this->text($this->done($mine, 'en'), 'rb-thanks-title'));

        // وطلبُ متجرٍ لا يُفتح من عنوان غيره — ولو برمزه
        $this->get('/s/ribbon/done/'.$theirs->id.'?t='.WebCheckout::token($theirs))->assertNotFound();
    }

    /* ═════════════ ١٣ · ١٤ · وإيصالُ الصفحة الإنجليزيّة بنصّه الإنجليزيّ ═════════════ */

    public function test_the_english_page_receipt_carries_the_english_header_and_footer(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [PaidReceipt::KEY => '1']);
        DocumentTemplates::save($this->shop->id, 'sale', [
            'header' => 'ورد بعناية', 'footer' => 'شكرًا لاختياركم ريبون',
            'header_en' => 'Flowers with care', 'footer_en' => 'Thank you for choosing Ribbon',
        ]);
        $order = $this->order();
        $url = '/s/ribbon/receipt/'.$order->id.'?t='.WebCheckout::token($order);

        // والتذييلُ يُلفّ كلمةً كلمةً باتّجاهها (`ReceiptTemplate::printableHtml`) — فيُقرأ نصًّا
        $plain = fn (object $c) => trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $c->html)));

        $en = $plain($this->capture(fn () => $this->get($url.'&lang=en')->assertOk()));
        $this->assertStringContainsString('Flowers with care', $en);
        $this->assertStringContainsString('Thank you for choosing Ribbon', $en);
        $this->assertStringNotContainsString('ورد بعناية', $en);

        $ar = $plain($this->capture(fn () => $this->get($url.'&lang=ar')->assertOk()));
        $this->assertStringContainsString('ورد بعناية', $ar);
        $this->assertStringContainsString('شكرًا لاختياركم ريبون', $ar);
        $this->assertStringNotContainsString('Flowers with care', $ar);

        // والمبالغُ واحدةٌ في اللغتين — المسمّياتُ وحدَها تتبع اللغة
        $this->assertStringContainsString('40.000', $en);
        $this->assertStringContainsString('40.000', $ar);
    }
}

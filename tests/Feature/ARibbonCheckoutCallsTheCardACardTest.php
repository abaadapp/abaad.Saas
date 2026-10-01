<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\PaymentMethods;
use App\Support\Store\RibbonTexts;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البطاقةُ في صفحة الإتمام تُسمّى بطاقة — لا «الدفع عند الاستلام».
 *
 * ═══ ما وقع ═══
 *
 * كان القالبُ يعرف وسيلتين: «تحويل» وما سواه «نقد عند الاستلام». فلمّا
 * فُتحت البطاقةُ لمتجر سعود ظهرت للزبون باسم «الدفع عند الاستلام» — والنقدُ
 * مطفأٌ عنده أصلًا. فيختارها ظانًّا أنّه يدفع عند الباب، ثمّ تفتح له صفحةُ
 * البنك. والخادمُ كان صادقًا (`payments` = تحويل وبطاقة)، والكذبُ في الاسم.
 *
 * فيُقرأ هنا كلُّ زرٍّ بمفتاحه (`data-v`) واسمِه معًا.
 */
class ARibbonCheckoutCallsTheCardACardTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function pays(bool $cod, bool $transfer): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => $cod ? '1' : '0', 'store_pay_transfer' => $transfer ? '1' : '0',
        ]);
    }

    private function gateway(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'omn_pk_test_abc', 'secret_key' => 'sk_test_abc', 'hmac_secret' => 'hmac_secret_value',
            'card_integration_id' => '71525', 'active' => true,
        ]);
    }

    /**
     * أزرارُ الدفع في الصفحة: مفتاحُ كلٍّ ونصُّه.
     *
     * @return array<string, string> [cod|transfer|card => نصُّ الزرّ بلا وسوم]
     */
    private function buttons(string $lang = 'ar'): array
    {
        $html = $this->get('/s/ribbon/checkout'.($lang === 'en' ? '?lang=en' : ''))->assertOk()->getContent();

        preg_match_all('/<button[^>]*class="rb-payopt[^"]*"[^>]*data-v="([^"]+)"[^>]*>(.*?)<\/button>/su', $html, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as [, $key, $inner]) {
            $out[$key] = trim(preg_replace('/\s+/u', ' ', strip_tags($inner)));
        }

        return $out;
    }

    /* ═══════════════ ما رآه الزبون ═══════════════ */

    /** متجرُ سعود كما هو على الإنتاج: تحويلٌ وبطاقة، والنقدُ مطفأ */
    public function test_transfer_and_card_without_cash_show_no_cash_on_delivery(): void
    {
        $this->pays(cod: false, transfer: true);
        $this->gateway();

        $options = $this->buttons();

        $this->assertSame(['transfer', 'card'], array_keys($options));
        $this->assertStringContainsString('تحويل بنكي', $options['transfer']);
        $this->assertStringContainsString('الدفع بالبطاقة', $options['card']);
        $this->assertStringContainsString('فيزا أو ماستركارد', $options['card']);

        foreach ($options as $key => $text) {
            $this->assertStringNotContainsString('الدفع عند الاستلام', $text, "زرُّ «{$key}» يعد بالدفع عند الاستلام والنقدُ مطفأ");
            $this->assertStringNotContainsString('نقدًا عند التسليم', $text);
        }
    }

    public function test_each_of_the_three_ways_carries_its_own_name(): void
    {
        $this->pays(cod: true, transfer: true);
        $this->gateway();

        $options = $this->buttons();

        $this->assertSame(['cod', 'transfer', 'card'], array_keys($options));
        $this->assertStringContainsString('الدفع عند الاستلام', $options['cod']);
        $this->assertStringContainsString('نقدًا عند التسليم', $options['cod']);
        $this->assertStringContainsString('تحويل بنكي', $options['transfer']);
        $this->assertStringContainsString('الدفع بالبطاقة', $options['card']);
        $this->assertStringNotContainsString('الاستلام', $options['card']);
    }

    public function test_and_in_english_too(): void
    {
        $this->pays(cod: true, transfer: false);
        $this->gateway();

        $options = $this->buttons('en');

        $this->assertSame(['cod', 'card'], array_keys($options));
        $this->assertStringContainsString('Cash on delivery', $options['cod']);
        $this->assertStringContainsString('Pay by card', $options['card']);
        $this->assertStringContainsString('Visa or Mastercard', $options['card']);
        $this->assertStringNotContainsString('delivery', $options['card']);
    }

    /** وبلا بوّابةٍ جاهزة لا زرَّ بطاقة — والأسماءُ الباقية كما كانت */
    public function test_without_a_ready_gateway_there_is_no_card_button(): void
    {
        $this->pays(cod: true, transfer: true);

        $options = $this->buttons();

        $this->assertSame(['cod', 'transfer'], array_keys($options));
        $this->assertStringContainsString('الدفع عند الاستلام', $options['cod']);
        $this->assertStringContainsString('تحويل بنكي', $options['transfer']);
    }

    /** والنصّان بلغتيهما — مفتاحٌ ناقصٌ في إحداهما يُسقط الصفحةَ بخطأ */
    public function test_the_card_texts_exist_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $t = RibbonTexts::for($lang);

            foreach (['payCod', 'payCodNote', 'payBank', 'payBankNote', 'payCard', 'payCardNote'] as $key) {
                $this->assertArrayHasKey($key, $t, "{$key} ناقصٌ في {$lang}");
                $this->assertNotSame('', trim($t[$key]));
            }
        }
    }

    /* ═══════════════ وصفحةُ الشكر تقول ما دفع به ═══════════════ */

    /** طلبٌ من الموقع بطريقة دفعٍ بعينها — كما يكتبه `WebCheckout` */
    private function placed(string $method): Order
    {
        static $n = 0;

        return Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-DONE-'.(++$n), 'customer_name' => 'زبون',
            'subtotal' => 0.2, 'tax' => 0, 'discount' => 0, 'total' => 0.2,
            'payment_method' => $method, 'status' => 'جديد', 'is_held' => false,
            'channel' => 'website', 'ordered_at' => now(),
        ]);
    }

    /** سطرُ «الدفع» في صفحة الشكر وحده — لا كلمةٌ في مكانٍ آخر من الصفحة */
    private function paidWith(Order $order, string $lang = 'ar'): string
    {
        $this->pays(cod: true, transfer: true);

        return $this->get('/s/ribbon/done/'.$order->id.'?t='.WebCheckout::token($order).($lang === 'en' ? '&lang=en' : ''))
            ->assertOk()->viewData('order')['pay'];
    }

    /**
     * ومن دفع ببطاقته لا يُقال له «الدفع عند الاستلام».
     *
     * كانت الصفحةُ تعرف طريقتين: التحويلُ تحويلٌ وما سواه «عند الاستلام».
     * فدفع أوّلُ زبونٍ ببطاقته عبر Paymob وقرأ تحته «الدفع عند الاستلام» —
     * والطلبُ مكتوبٌ «بطاقة» ومالُه مقبوض.
     */
    public function test_the_thank_you_page_names_each_way_he_paid(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $t = RibbonTexts::for($lang);

            $this->assertSame($t['payCard'], $this->paidWith($this->placed(PaymentMethods::CARD), $lang), "البطاقة في «{$lang}» قيلت غيرَ بطاقة");
            $this->assertSame($t['payBank'], $this->paidWith($this->placed(PaymentMethods::TRANSFER), $lang));
            $this->assertSame($t['payCod'], $this->paidWith($this->placed(PaymentMethods::CASH), $lang));
        }
    }

    /** وما يُقال يُكتب في الصفحة نفسِها — لا في الحمولة وحدها */
    public function test_the_card_line_is_printed_on_the_page(): void
    {
        $this->pays(cod: true, transfer: true);
        $order = $this->placed(PaymentMethods::CARD);

        $html = $this->get('/s/ribbon/done/'.$order->id.'?t='.WebCheckout::token($order))->assertOk()->getContent();

        $this->assertStringContainsString(RibbonTexts::for('ar')['payCard'], $html);
        $this->assertStringNotContainsString(RibbonTexts::for('ar')['payCod'], $html, 'بطاقةٌ مدفوعة قيل عنها «الدفع عند الاستلام»');
    }
}

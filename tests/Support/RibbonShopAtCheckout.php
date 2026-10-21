<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\Paymob;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * متجرُ RIBBON عند صفحة الإتمام — بصنفٍ وبوّابةٍ تجريبيّةٍ وصاحبٍ يحفظ إعداداته.
 *
 * يقرؤه ملفّاتُ استقبال الطلبات والشروط ومواعيد التسليم وتسويةُ الدفعة بعد
 * الإغلاق: أربعةُ أبوابٍ لإتمامٍ واحد، فمتجرُها واحد.
 *
 * ومتجرُ سعود في الإنتاج رقمُه ٥ وله قواعدُ خاصّة، ورقمُ الصفّ هنا يتبع تسلسلَ
 * القاعدة — فقد يصير ٥. فتُفرَّغ قوائمُه في `setUp` من يستعمل هذا.
 */
trait RibbonShopAtCheckout
{
    private Business $shop;

    private Product $rose;

    private User $owner;

    private function openRibbon(string $slug = 'ribbon', string $email = 'ribbon@checkout.local'): Business
    {
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);

        $shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'سعود', 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        MarketingSettings::save($shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        // مفاتيحُ تجريبيّةٌ — لا مفتاحَ حقيقيٍّ في اختبار، ولا دفعةَ حقيقيّة
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876',
            'active' => true,
        ]);

        $this->shop = $shop;
        $this->owner = $owner;
        $this->rose = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $shop;
    }

    /** ما يرسله المتصفّح من صفحة الإتمام */
    private function order(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001',
            'date' => now()->addDays(2)->toDateString(), 'slot' => '9 ص – 12 م',
        ];
    }

    private function settings(array $values): void
    {
        MarketingSettings::save($this->shop->id, 'website', $values);
    }

    private function checkout(array $over = [], string $lang = 'ar')
    {
        return $this->postJson('/s/'.$this->shop->site_slug.'/checkout'.($lang === 'en' ? '?lang=en' : ''), $this->order($over));
    }

    /* ═══════════ البطاقة ═══════════ */

    private function bankOpens(): void
    {
        Http::fake(['oman.paymob.com/v1/intention/' => $this->opened()]);
    }

    private function opened(): PromiseInterface
    {
        return Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201);
    }

    private function notice(StorePaymentIntent $intent, int $transaction = 998877): array
    {
        return [
            'amount_cents' => (int) round(((float) $intent->amount) * 1000),
            'created_at' => '2027-02-10T10:00:10.100000',
            'currency' => 'OMR',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => $transaction,
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
        ];
    }

    /** إشعارُ «دُفعت» الموقَّعُ من البوّابة */
    private function paid(StorePaymentIntent $intent, int $transaction = 998877): void
    {
        $obj = $this->notice($intent, $transaction);

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac_secret_value'), ['obj' => $obj])->assertOk();
    }
}

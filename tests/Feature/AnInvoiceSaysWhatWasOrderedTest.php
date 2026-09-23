<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentPaper;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الفاتورةُ تقول ما طُلب — ورقمُ العميل رقمُه هو.
 *
 * ═══ ما كانت تسكت عنه ═══
 *
 * الورقةُ تحمل الرقمَ والتاريخَ والأصنافَ والمجموع، وتسكت عن **ما طُلب**:
 * أتوصيلٌ هو أم استلام، ومتى، وإلى من، ولأيّ مناسبة. فمن يحملها لا يعرف
 * من قراءتها ما اتُّفق عليه — ويعود يسأل صاحبَ المحلّ بالهاتف عمّا كان
 * يجب أن يكون مكتوبًا.
 *
 * ═══ والعطبُ الذي كان فيها ═══
 *
 * تحت عنوان «العميل» كان يُطبع `recipient_phone` — **هاتفُ المستلِم**.
 * وفي طلب هديّةٍ هما شخصان دائمًا: تخرج الورقةُ باسم مريم ورقمِ من أُرسلت
 * إليه. ومن يتّصل بالرقم ليسأل عن فاتورةٍ يقع على المهدى إليه فيكشف له
 * هديّةً لم تصله بعد.
 *
 * فصار رقمُ العميل من بطاقته (`customers.phone`)، والمستلِمُ طرفًا أدناه.
 *
 * ═══ ولا سطرَ فارغًا ═══
 *
 * بيعةُ صندوقٍ بلا موعدٍ ولا مستلِم لا تطبع حقولًا خاوية — وورقةٌ فيها
 * فراغاتٌ تُقرأ نموذجًا لم يُملأ.
 */
class AnInvoiceSaysWhatWasOrderedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');

        $this->business = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        User::create(['business_id' => $this->business->id, 'name' => 'سعود',
            'email' => 'ribbon@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '1',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        $this->product = Product::create(['business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 20, 'cost' => 8, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** طلبُ هديّةٍ من الموقع: مريم تشتري، وسارةُ تستلم */
    private function giftOrder(): Order
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001',
            'recipient_name' => 'سارة', 'recipient_phone' => '96899220002',
            'area' => 'الخوير', 'address' => 'بيت ١٢، شارع ٨',
            'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        return Order::where('channel', SalesChannel::WEBSITE)->orderByDesc('id')->firstOrFail();
    }

    /** ما تحمله الورقةُ من أسطرٍ وخانات، نصًّا واحدًا يُبحث فيه */
    private function paperText(Order $order): string
    {
        $doc = DocumentPaper::forSale($order);

        $out = [];
        foreach ($doc['meta'] as $row) {
            $out[] = $row['label'].': '.$row['value'];
        }
        foreach ($doc['parties'] as $party) {
            $out[] = '['.$party['cap'].'] '.implode(' | ', $party['lines']);
        }

        return implode("\n", $out);
    }

    /* ═════════════ رقمُ العميل رقمُه هو ═════════════ */

    public function test_the_customer_block_carries_the_customers_own_phone(): void
    {
        $doc = DocumentPaper::forSale($this->giftOrder());
        $customer = collect($doc['parties'])->firstWhere('cap', 'العميل');

        $this->assertNotNull($customer);
        $this->assertContains('96899110001', $customer['lines'], 'رقمُ العميل ليس على ورقته');
        $this->assertNotContains('96899220002', $customer['lines'],
            'هاتفُ المستلِم مطبوعٌ تحت «العميل» — ومن يتّصل به يكشف هديّةً لم تصل');
    }

    /** والمستلِمُ طرفٌ بذاته — اسمُه ورقمُه وعنوانُه */
    public function test_the_recipient_is_a_party_of_his_own(): void
    {
        $doc = DocumentPaper::forSale($this->giftOrder());
        $recipient = collect($doc['parties'])->firstWhere('cap', 'المستلِم');

        $this->assertNotNull($recipient, 'لا طرفَ للمستلِم على ورقة هديّة');
        $this->assertContains('سارة', $recipient['lines']);
        $this->assertContains('96899220002', $recipient['lines']);
        // والعنوانُ يُحفظ منطقةً وتفصيلًا معًا: «الخوير — بيت ١٢، شارع ٨»
        $this->assertStringContainsString('بيت 12، شارع 8', implode(' | ', $recipient['lines']),
            'العنوانُ ليس في طرف المستلِم');
        $this->assertStringContainsString('الخوير', implode(' | ', $recipient['lines']));
    }

    /* ═════════════ وما طُلب مكتوبٌ عليها ═════════════ */

    public function test_the_paper_says_how_and_when_it_is_delivered(): void
    {
        $text = $this->paperText($this->giftOrder());

        $this->assertStringContainsString('نوع التسليم: توصيل', $text);
        $this->assertStringContainsString('موعد التسليم: 2027-02-03', $text);
    }

    /* ═════════════ ولا سطرَ فارغًا ═════════════ */

    /** بيعةُ صندوقٍ بلا موعدٍ ولا مستلِم لا تطبع حقولًا خاوية */
    public function test_a_plain_till_sale_prints_no_empty_fields(): void
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'customer_name' => 'عميل نقدي',
            'branch' => 'الخوير', 'status' => 'مكتمل', 'payment_method' => 'نقدي',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 20,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $doc = DocumentPaper::forSale($order);

        foreach ($doc['meta'] as $row) {
            $this->assertNotSame('', trim((string) $row['value']), 'خانةٌ خاوية على الورقة: '.$row['label']);
        }

        $this->assertNull(collect($doc['parties'])->firstWhere('cap', 'المستلِم'),
            'طرفُ مستلِمٍ على بيعةِ صندوقٍ لا مستلِمَ لها');
    }

    /** وطلبُ استلامٍ يقول إنّه استلام */
    public function test_a_pickup_order_says_pickup(): void
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        $order = Order::where('channel', SalesChannel::WEBSITE)->orderByDesc('id')->firstOrFail();

        $this->assertStringContainsString('نوع التسليم: استلام من المحل', $this->paperText($order));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * إيصالُ طلب الموقع «عند الاستلام» يقول ذلك — لا «نقدي».
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * طلبُ الموقع بالدفع عند الاستلام يُكتب `نقدي` و«غير مدفوع». والإيصالُ الحراريّ
 * يطبع الوسيلةَ وحدها، فقرأ موظّفُ سعود «وسيلة الدفع: نقدي» على طلبٍ لم يُقبض
 * منه شيء — والأقربُ أن يسلّمه بلا تحصيل.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - طلبُ الموقع من السلّة نفسِها: «الدفع عند الاستلام»، وبالإنجليزيّة «Cash on delivery».
 *   - والمخزَّنُ باقٍ `نقدي`: الدفترُ والتقاريرُ لا تتغيّر.
 *   - وما سواه كما كان: بيعُ الصندوق، وآجلُه النقديّ غيرُ المدفوع، والتحويلُ،
 *     والبطاقةُ، وطلبُ موقعٍ نقديٌّ مدفوع.
 */
class ACashOnDeliveryReceiptSaysSoTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
    }

    /** الإيصالُ كما يُطبع — نصًّا بلا وسوم */
    private function strip(Order $order, string $lang = 'ar'): string
    {
        $was = app()->getLocale();
        app()->setLocale($lang);

        try {
            $html = PdfController::saleHtml($this->shop->id, $order->load('items'), thermal: true)['html'];
        } finally {
            app()->setLocale($was);
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html))));
    }

    private function sale(array $over): Order
    {
        $order = Order::create($over + [
            'business_id' => $this->shop->id, 'number' => 'INV-'.random_int(100000, 999999), 'status' => 'مكتمل',
            'customer_name' => 'أحمد', 'employee_name' => 'الكاشير', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'total' => 20, 'ordered_at' => now(), 'channel' => SalesChannel::POS,
        ]);
        OrderItem::create(['order_id' => $order->id, 'name' => 'باقة ورد', 'price' => 20, 'quantity' => 1, 'total' => 20]);

        return $order;
    }

    public function test_a_website_order_paid_on_delivery_says_so_on_its_receipt(): void
    {
        $rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد أحمر', 'price' => 20,
            'cost' => 8, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $rose->id, 'qty' => 1]],
            'name' => 'Ahmed', 'phone' => '95259066', 'fulfil' => 'pickup', 'date' => now()->addDay()->toDateString(), 'pay' => 'cod',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();

        $this->assertStringContainsString('وسيلة الدفعالدفع عند الاستلام', $this->strip($order));
        $this->assertStringNotContainsString('نقدي', $this->strip($order));
        $this->assertStringContainsString('Payment methodCash on delivery', $this->strip($order, 'en'));

        // والمخزَّنُ كما كان — الدفترُ والتقاريرُ ودرجُ الصندوق تقرأ «نقدي»
        $this->assertSame('نقدي', DB::table('orders')->where('id', $order->id)->value('payment_method'));
        $this->assertSame('غير مدفوع', DB::table('orders')->where('id', $order->id)->value('payment_status'));
    }

    /**
     * وما سواه يُطبع كما كان.
     *
     * والآجلُ النقديُّ في الصندوق «غير مدفوع» مثلُه — ولا استلامَ فيه.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function others(): array
    {
        return [
            'بيعُ الصندوق نقدًا' => [[], 'نقدي'],
            'آجلُ الصندوق' => [['payment_status' => 'غير مدفوع'], 'نقدي'],
            'تحويلُ الموقع' => [['channel' => SalesChannel::WEBSITE, 'payment_method' => 'تحويل بنكي', 'payment_status' => 'غير مدفوع'], 'تحويل بنكي'],
            'بطاقةُ الموقع' => [['channel' => SalesChannel::WEBSITE, 'payment_method' => 'بطاقة'], 'فيزا'],
            'موقعٌ نقديٌّ مدفوع' => [['channel' => SalesChannel::WEBSITE], 'نقدي'],
            'قبل عمود القناة' => [['channel' => null, 'payment_status' => 'غير مدفوع'], 'نقدي'],
        ];
    }

    #[DataProvider('others')]
    public function test_every_other_payment_prints_as_before(array $over, string $printed): void
    {
        $text = $this->strip($this->sale($over));

        $this->assertStringContainsString('وسيلة الدفع'.$printed, $text);
        $this->assertStringNotContainsString('الدفع عند الاستلام', $text);
    }
}

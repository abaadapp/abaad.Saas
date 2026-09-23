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
 * ورقةُ الفاتورة تجمع إلى إجماليّها — ولا بابَ يكسر جمعَها.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * `OrderDetailController::update` — شاشةُ «تفاصيل الطلب»: المستلِم والموعد
 * والعنوان — كانت تقبل `delivery_fee` وتكتبه على الفاتورة **بلا إعادة حسبةٍ
 * للإجمالي**. والامتناعُ عن إعادة الحسبة كان مقصودًا ومكتوبًا: الإجماليّ
 * رقمٌ قُيّد في معاملةٍ ماليّة وفي الدفتر.
 *
 * لكنّ كتابةَ الرسم وحدَه تفعل ما مُنع منه — وأسوأ: **الفاتورةُ تناقض
 * نفسَها.** رسمٌ يُرفع من ٢ إلى ٩ على فاتورةِ عشرين يطبع ورقةً تقول:
 *
 *     المجموع الفرعي  ٢٠٫٠٠٠
 *     رسوم التوصيل     ٩٫٠٠٠
 *     الإجمالي        ٢٢٫٠٠٠   ← والجمعُ ٢٩
 *
 * وتقاريرُ التوصيل تقرأ الرسمَ الجديد، والدفترُ يحمل القديم.
 *
 * ═══ وبابُه غيرُ بابِ المال ═══
 *
 * هذه الشاشةُ يفتحها قسمُ «المبيعات» بلا صلاحيةٍ باسمها وبلا سببٍ يُكتب —
 * بينما تعديلُ كميّةٍ بفلسٍ واحد يمرّ بـ`order.edit` وبيوم البيع وبسببٍ
 * يُنسب إلى صاحبه. فأرخصُ الأبواب كان يكتب مالًا لا يكتبه أغلاها.
 *
 * ولا شاشةَ ترسله: الحقل بلا مقبضٍ في الواجهة، فهو ثغرةٌ يفتحها طلبٌ
 * يُكتب بيده. وإغلاقُه لا يُفقد أحدًا شيئًا يستعمله.
 */
class AnInvoiceNeverContradictsItselfTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

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

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'سعود',
            'email' => 'ribbon@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '2',
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

    /** طلبُ توصيلٍ من الموقع برسمٍ قدرُه ٢ — وإجماليّه ٢٢ */
    private function deliveredWebOrder(): Order
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'بيت ١٢', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        return Order::where('channel', SalesChannel::WEBSITE)->orderByDesc('id')->firstOrFail();
    }

    /** حفظُ تفاصيل التنفيذ — ومعها ما يُدسّ من مال */
    private function saveDetails(Order $order, array $extra = [])
    {
        return $this->actingAs($this->owner)->put(route('admin.orders.details.update', $order->number), array_merge([
            'fulfillment_type' => 'delivery',
            'recipient_name' => 'مريم', 'recipient_phone' => '96899110001',
            'delivery_address' => 'بيت ١٢', 'scheduled_for' => '2027-02-03 09:00',
        ], $extra));
    }

    /* ═════════════ لا مالَ من هذا الباب ═════════════ */

    public function test_the_details_screen_does_not_write_money_on_the_invoice(): void
    {
        $order = $this->deliveredWebOrder();

        $this->saveDetails($order, ['delivery_fee' => 9])->assertSessionHasNoErrors();

        $this->assertEquals(2, (float) $order->fresh()->delivery_fee, 'رسمُ التوصيل كُتب من شاشةٍ لا تكتب مالًا');
    }

    /** والورقةُ تجمع إلى إجماليّها — وهو المقياس الذي يقرؤه الزبون */
    public function test_the_printed_paper_adds_up(): void
    {
        $order = $this->deliveredWebOrder();
        $this->saveDetails($order, ['delivery_fee' => 9]);

        $totals = collect(DocumentPaper::forSale($order->fresh())['totals']);
        // وسطرٌ لا يُطبع صفرٌ لا غياب: الخصمُ لا يُذكر حين لا خصم
        $value = fn (string $label) => (float) str_replace(['ر.ع', ' ', '−'], '',
            (string) ($totals->firstWhere('label', __($label))['value'] ?? '0'));

        $this->assertEqualsWithDelta(
            $value('المجموع الفرعي') - $value('الخصم') + $value('رسوم التوصيل'),
            $value('الإجمالي'),
            0.001,
            'ورقةٌ تُطبع لا تجمع إلى إجماليّها — يقرؤها الزبون فيجد رقمًا ثالثًا',
        );
    }

    /** والتفاصيلُ نفسُها تُحفظ كما كانت — الإغلاقُ على المال وحده */
    public function test_the_details_themselves_still_save(): void
    {
        $order = $this->deliveredWebOrder();

        $this->saveDetails($order, ['recipient_name' => 'سارة', 'delivery_notes' => 'الباب الأزرق'])
            ->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame('سارة', $fresh->recipient_name);
        $this->assertSame('الباب الأزرق', $fresh->delivery_notes);
    }

    /** ورسمُ التوصيل يُكتب ساعةَ البيع كما كان — الحدُّ على ما بعدها */
    public function test_the_fee_is_still_written_when_the_sale_happens(): void
    {
        $this->assertEquals(2, (float) $this->deliveredWebOrder()->delivery_fee);
    }
}

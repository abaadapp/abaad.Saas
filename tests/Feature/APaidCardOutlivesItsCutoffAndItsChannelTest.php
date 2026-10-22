<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorePaymentIntent;
use App\Models\Transaction;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * دفعةٌ بالبطاقة فُتحت قبل آخر حجز فترتها، والزبونُ عند البنك حين انقضى الحدّ —
 * وتبدّلت قناةُ صنفها في الأثناء. لا مالَ مقبوضًا بلا طلب، ولا طلبَ مرّتين.
 *
 * يجمع #137 (آخرُ الحجز) و#138 (قنواتُ البيع) على مسار التسوية القائم
 * (`PaymobController::settle` ← `WebCheckout::place(paid: true)`). وبمحاكاةٍ
 * لا نداءٍ حقيقيّ: `Http::fake` وتوقيعُ `RibbonShopAtCheckout`.
 *
 * ═══ ما يُحرس ═══
 *
 *   1. دفعٌ يبدأ قبل الحدّ ← 2. ينقضي الحدُّ والزبونُ عند البنك ← 3. تؤكّد
 *      البوّابةُ القبض ← 4. طلبٌ واحدٌ بالمبلغ المقبوض وبالفترة المحجوزة،
 *      5. والرفُّ يُخصم مرّةً، 6. وإعادةُ الإشعار لا تكتب طلبًا ولا دفعةً ولا قيدًا.
 *   7. ولا تُفتح دفعةٌ جديدة بعد الحدّ، ولا لصنفٍ أُغلقت عنه قناةُ الموقع —
 *      ولا نداءَ إلى البوّابة.
 *   8. وصنفٌ أُخفي أو أُغلقت قناتُه أو أُوقف والزبونُ عند البنك — مع انقضاء الحدّ
 *      معًا — يُكتب طلبُه. ونيّةٌ لم يُقبض مالُها لا تتجاوز شيئًا من هذا.
 */
class APaidCardOutlivesItsCutoffAndItsChannelTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private Product $site;

    private const WED = '2027-02-10';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 07:00:00');
        $this->openRibbon();

        // صنفُ «الموقع فقط» — يُحجز بالبطاقة ولا يبيعه الصندوق
        $this->site = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة الموقع', 'price' => 10, 'cost' => 4,
            'quantity' => 20, 'alert_qty' => 0, 'active' => true, 'pos_enabled' => false, 'published' => true,
        ]);

        $week = array_fill(0, 7, [
            ['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning', 'cutoff' => ['time' => '08:00', 'day' => 'same']],
            ['id' => 'pm01', 'from' => '16:00', 'to' => '21:00', 'ar' => 'المساء', 'en' => 'Evening', 'cutoff' => ['time' => '14:00', 'day' => 'same']],
        ]);
        $this->settings([
            'store_max_days' => '7', 'store_delivery_fee' => '1.5',
            DeliverySchedule::ON => '1',
            DeliverySchedule::KEY => DeliverySchedule::validated(['delivery' => $week, 'pickup' => null, 'closed' => [], 'lead' => 0]),
        ]);
        $this->bankOpens();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(self::WED.' '.$time);
    }

    private function openCard(string $slot = 'am01', int $qty = 2)
    {
        return $this->checkout([
            'items' => [['id' => $this->site->id, 'qty' => $qty]], 'pay' => 'card',
            'fulfil' => 'delivery', 'address' => 'الخوير، شارع ٣٣', 'date' => self::WED, 'slot' => $slot,
        ]);
    }

    /** @return list<string> فتراتُ الأربعاء المعروضة الآن */
    private function offered(): array
    {
        $day = collect(DeliverySchedule::days($this->shop->id, 'delivery'))->firstWhere('date', self::WED);

        return $day === null ? [] : array_column($day['slots'], 'id');
    }

    /** ما كتبته التسويةُ: الطلباتُ وصفوفُ المال والقيودُ ومخزونُ الصنف */
    private function written(): array
    {
        return [
            'orders' => Order::count(),
            'money' => Transaction::count(),
            'entries' => JournalEntry::count(),
            'shelf' => (int) $this->site->fresh()->quantity,
        ];
    }

    private function assertOneOrderAtTheWindow(StorePaymentIntent $intent): Order
    {
        $order = Order::sole();
        $this->assertSame('am01', $order->delivery_slot, 'كُتب الطلبُ بغير الفترة المحجوزة');
        $this->assertSame('2027-02-10 09:00:00', $order->scheduled_for->toDateTimeString());
        $this->assertSame('2027-02-10 12:00:00', $order->scheduled_until->toDateTimeString());
        $this->assertEquals((float) $intent->amount, (float) $order->total, 'الطلبُ بغير المبلغ المقبوض');
        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame(18, (int) $this->site->fresh()->quantity, 'الرفُّ لم يُخصم مرّةً واحدة');
        $this->assertFalse($intent->fresh()->strayPayment(), 'بقي المالُ مقبوضًا بلا طلب');
        $this->assertSame($order->id, (int) $intent->fresh()->order_id);

        return $order;
    }

    /* ═════════════ ١–٦: قبل الحدّ، والقبضُ بعده ═════════════ */

    public function test_a_card_opened_before_the_cutoff_and_paid_after_is_one_order_at_the_paid_amount_and_window(): void
    {
        $this->at('07:59:00');
        $this->openCard()->assertOk()->assertJsonPath('ok', true);
        $intent = StorePaymentIntent::sole();
        // باقتان بعشرة ورسمُ التوصيل
        $this->assertEquals(21.5, (float) $intent->amount);
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ قبل القبض');

        $this->at('08:05:00');
        $this->assertSame(['pm01'], $this->offered(), 'بقي الصباحُ يُحجز بعد آخر حجزه');

        $this->paid($intent);
        $order = $this->assertOneOrderAtTheWindow($intent);
        $this->assertEquals(21.5, (float) Transaction::where('order_id', $order->id)->sum('amount'));

        // والإشعارُ يُعاد — ثمّ يُعاد: لا طلبَ ولا مالَ ولا قيدَ ولا قطعةَ ثانية
        $once = $this->written();
        $this->paid($intent);
        $this->paid($intent);
        $this->assertSame($once, $this->written(), 'أعاد الإشعارُ المكرَّر كتابةَ شيء');

        // والبوّابةُ نوديت مرّةً واحدة — لفتح الدفع
        Http::assertSentCount(1);
    }

    /* ═════════════ ٧: لا دفعةَ جديدة ═════════════ */

    public function test_no_card_opens_after_the_cutoff_or_for_a_product_off_the_site_and_the_bank_is_never_called(): void
    {
        $this->at('08:00:00');
        $this->openCard()->assertStatus(422)->assertJsonValidationErrors('slot');

        // والمساءُ متاح — لكنّ الصنفَ أُغلق عن الموقع
        $this->site->update(['published' => false]);
        $refused = $this->openCard('pm01')->assertStatus(422)->json('errors');
        $this->assertNotEmpty(array_filter(array_keys($refused), fn ($k) => str_starts_with($k, 'items')));

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count());
        $this->assertSame(['orders' => 0, 'money' => 0, 'entries' => 0, 'shelf' => 20], $this->written());
    }

    /* ═════════════ ٨: الحدُّ انقضى والقناةُ تبدّلت والزبونُ عند البنك ═════════════ */

    /** @return array<string, array{array<string, bool>}> */
    public static function changedWhileAtTheBank(): array
    {
        return [
            'بلا قناة' => [['active' => true, 'pos_enabled' => false, 'published' => false]],
            'الصندوق وحده' => [['active' => true, 'pos_enabled' => true, 'published' => false]],
            'موقوف' => [['active' => false, 'pos_enabled' => false, 'published' => true]],
        ];
    }

    #[DataProvider('changedWhileAtTheBank')]
    public function test_a_card_paid_after_its_cutoff_and_after_its_product_changed_is_still_one_order(array $state): void
    {
        $this->at('07:59:00');
        $this->openCard()->assertOk();
        $intent = StorePaymentIntent::sole();

        $this->at('08:05:00');
        $this->site->fresh()->update($state);

        $this->paid($intent);
        $this->paid($intent);

        $this->assertOneOrderAtTheWindow($intent);
        // وما تبدّل يبقى كما قاله صاحبُه — التسويةُ لا تفتح قناةً ولا تُحيي صنفًا
        $this->assertSame($state, $this->site->fresh()->only(array_keys($state)));
    }

    public function test_an_unpaid_intent_cannot_force_a_window_past_its_cutoff_or_a_closed_channel(): void
    {
        $this->at('07:59:00');
        $this->openCard()->assertOk();
        $intent = StorePaymentIntent::sole();

        $this->at('08:05:00');
        try {
            WebCheckout::place($this->shop->fresh(), (array) $intent->payload, 'ar', paid: true, intent: $intent);
            $this->fail('كُتب طلبٌ لفترةٍ انتهى حجزُها بنيّةٍ لم يُقبض مالُها');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slot', $e->errors());
        }

        $this->site->update(['published' => false]);
        $this->expectException(ValidationException::class);
        try {
            WebCheckout::place($this->shop->fresh(), (array) $intent->payload, 'ar', paid: true, intent: $intent);
        } finally {
            $this->assertSame(['orders' => 0, 'money' => 0, 'entries' => 0, 'shelf' => 20], $this->written());
        }
    }
}

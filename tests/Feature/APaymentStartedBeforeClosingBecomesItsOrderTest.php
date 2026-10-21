<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\OrderWindow;
use App\Support\Store\StoreTerms;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * دفعةٌ بدأت والمتجرُ مفتوح تصير طلبَها — ولو أُغلق الاستقبالُ والزبونُ عند البنك.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * يفتح الزبونُ صفحةَ البنك في الثامنة وتسعٍ وخمسين والمتجرُ يستقبل، ثمّ يُغلق
 * الاستقبالُ في التاسعة، ثمّ يُكمل الدفعَ في التاسعة ودقيقة. والإشعارُ يُتمّ
 * الطلبَ بـ`WebCheckout::place`، وكانت تسأل «أيستقبل المتجرُ الآن؟» — فتردّه،
 * ويبقى المالُ مقبوضًا بلا طلب (`StorePaymentIntent::strayPayment`).
 *
 * وكذلك ما تبدّل والزبونُ عند البنك: نصُّ الشروط، وفترةُ التسليم، واليومُ نفسُه
 * حين يعبر منتصفُ الليل بين الدفع والإشعار.
 *
 * ═══ والقاعدة ═══
 *
 * - المغلقُ قبل الدفع لا يفتح صفحةَ بنكٍ أصلًا (`TheShopDecidesWhenItTakesOrdersTest`).
 * - ودفعةٌ صدّقت القاعدةُ أنّها قُبضت لهذا المتجر بإشعارٍ موقَّع تُتمّ طلبَها، بما
 *   وافق عليه زبونُها واختاره يومَ فُتحت صفحتُه.
 * - ولا يفتح وجودُ النيّة وحدَه متجرًا مغلقًا: نيّةٌ لم يُقبض مالُها تُردّ.
 */
class APaymentStartedBeforeClosingBecomesItsOrderTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // الأربعاء ١٠ فبراير، الثامنة وتسعٌ وخمسون مساءً
        Carbon::setTestNow('2027-02-10 20:59:00');
        $this->openRibbon();
        $this->bankOpens();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** يفتح الزبونُ صفحةَ البنك — والنيّةُ التي كُتبت لها */
    private function atTheBank(array $over = []): StorePaymentIntent
    {
        $this->checkout(['pay' => 'card'] + $over)->assertOk()->assertJsonPath('ok', true);

        return StorePaymentIntent::latest('id')->firstOrFail();
    }

    /* ═════════════ الاستقبالُ أُغلق والزبونُ عند البنك ═════════════ */

    public function test_closing_by_hand_while_the_buyer_pays_does_not_strand_the_money(): void
    {
        $intent = $this->atTheBank();

        $this->settings([OrderWindow::MANUAL => '0']);
        Carbon::setTestNow('2027-02-10 21:01:00');
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'دُفع ثمنُ الطلب ثمّ رُدّ لأنّ المتجرَ أُغلق بعد الدفع');
        $this->assertSame((int) Order::firstOrFail()->id, (int) $intent->fresh()->order_id);
        $this->assertFalse($intent->fresh()->strayPayment());

        // والإغلاقُ ما زال يردّ الطلبَ الجديد
        $this->checkout()->assertStatus(422);
        $this->assertSame(1, Order::count());
    }

    public function test_a_schedule_that_closes_while_the_buyer_pays_does_not_strand_the_money(): void
    {
        $this->settings([
            OrderWindow::ON => '1',
            OrderWindow::KEY => OrderWindow::validated(['week' => array_fill(0, 7, [['from' => '09:00', 'to' => '21:00']])]),
        ]);
        $intent = $this->atTheBank();

        Carbon::setTestNow('2027-02-10 21:05:00');
        $this->assertFalse(OrderWindow::open($this->shop->id));
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'انتهت ساعاتُ الاستقبال والزبونُ عند البنك فرُدّ طلبُه المدفوع');
    }

    public function test_the_same_notice_twice_writes_one_order(): void
    {
        $intent = $this->atTheBank();
        $this->settings([OrderWindow::MANUAL => '0']);

        $this->paid($intent);
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'إشعارٌ أُعيد إرسالُه كتب طلبًا ثانيًا');
    }

    public function test_an_intent_that_was_never_paid_does_not_open_a_closed_shop(): void
    {
        $intent = $this->atTheBank();
        $this->settings([OrderWindow::MANUAL => '0']);

        try {
            WebCheckout::place($this->shop->fresh(), (array) $intent->payload, 'ar', paid: true, intent: $intent);
            $this->fail('فُتح متجرٌ مغلقٌ بنيّةٍ لم يُقبض مالُها');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        $this->assertSame(0, Order::count());
    }

    /* ═════════════ وما تبدّل والزبونُ عند البنك ═════════════ */

    public function test_terms_rewritten_while_the_buyer_pays_keep_the_version_he_agreed_to(): void
    {
        $this->settings([StoreTerms::ON => '1']);
        $v1 = StoreTerms::revise($this->shop->id, 'الشروطُ الأولى.', 'First terms.', null);

        $intent = $this->atTheBank([StoreTerms::ACCEPT => true, StoreTerms::VERSION => $v1->id]);

        $v2 = StoreTerms::revise($this->shop->id, 'الشروطُ الثانية.', 'Second terms.', null);
        $this->paid($intent);

        $order = Order::firstOrFail();
        $this->assertSame((int) $v1->id, (int) $order->terms_version_id, 'كُتبت على الطلب شروطٌ لم يرها صاحبُه');
        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame('ar', $order->terms_lang);
        $this->assertSame(hash('sha256', 'الشروطُ الأولى.'), $order->terms_hash);
        $this->assertSame('2027-02-10 20:59:00', $order->terms_accepted_at->toDateTimeString(), 'وقتُ الموافقة وقتُ الإشعار لا وقتُ الإقرار');
    }

    public function test_a_window_removed_while_the_buyer_pays_keeps_the_window_he_chose(): void
    {
        $saturday = array_fill(0, 7, []);
        $saturday[6] = [['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning']];
        $this->settings([
            DeliverySchedule::ON => '1',
            DeliverySchedule::KEY => DeliverySchedule::validated(['delivery' => $saturday]),
        ]);

        $intent = $this->atTheBank(['date' => '2027-02-13', 'slot' => 'am01']);

        // ويحذف صاحبُ المتجر الفترةَ والزبونُ عند البنك
        $saturday[6] = [['id' => 'pm01', 'from' => '16:00', 'to' => '20:00', 'ar' => '', 'en' => '']];
        $this->settings([DeliverySchedule::KEY => DeliverySchedule::validated(['delivery' => $saturday])]);
        $this->paid($intent);

        $order = Order::firstOrFail();
        $this->assertSame('2027-02-13 09:00:00', $order->scheduled_for->toDateTimeString(), 'رُدّ الطلبُ المدفوع أو كُتب بغير فترته');
        $this->assertSame('2027-02-13 12:00:00', $order->scheduled_until->toDateTimeString());
        $this->assertSame('am01', $order->delivery_slot);
    }

    public function test_a_payment_whose_notice_arrives_after_midnight_still_becomes_its_order(): void
    {
        // يدفع لموعد اليوم قبل منتصف الليل، ويصل إشعارُه بعده — واليومُ صار «أمس»
        Carbon::setTestNow('2027-02-10 23:55:00');
        $intent = $this->atTheBank(['date' => '2027-02-10']);

        Carbon::setTestNow('2027-02-11 00:05:00');
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'رُدّ طلبٌ مدفوعٌ لأنّ منتصفَ الليل مرّ بين الدفع والإشعار');
        $this->assertSame('2027-02-10', Order::firstOrFail()->scheduled_for->toDateString());
    }
}

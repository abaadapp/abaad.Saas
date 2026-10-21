<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\Store\OrderWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * المتجرُ يقرّر متى يستقبل طلباتِ موقعه — بيده، وبجدولٍ يضبطه، وبرسالةٍ يكتبها هو.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الجدولُ بأيّامه وساعاته وفتراته الكثيرة، وفترةٌ تعبر منتصف الليل، والإجازات،
 *     وأوّلُ يومٍ وآخرُه — بساعة مسقط لا بساعة الخادم ولا المتصفّح.
 *   - الإغلاقُ اليدويُّ أعلى من الجدول، ولا موعدَ فتحٍ يُخترع له.
 *   - المغلقُ لا يكتب طلبًا ولا يفتح صفحةَ دفعٍ ولو أُرسل الطلبُ بيده.
 *   - رسالةُ صاحبه بلغة الصفحة وحدها، تُملأ بموعد الفتح أو يُسقط سطرُه، وتُطبع نصًّا.
 *   - متجرٌ لم يلمس شيئًا يستقبل كما كان، ومتجرٌ لا يُغلق غيرَه.
 *
 * ودفعةٌ بدأت والمتجرُ مفتوح ثمّ أُغلق: `APaymentStartedBeforeClosingBecomesItsOrderTest`.
 */
class TheShopDecidesWhenItTakesOrdersTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    /** الأربعاء — 0 الأحد … 6 السبت */
    private const WED = 3;

    private const THU = 4;

    private const FRI = 5;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // الأربعاء ١٠ فبراير، العاشرة صباحًا بتوقيت مسقط
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->openRibbon();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<int, list<array{0: string, 1: string}>>  $days */
    private function schedule(array $days, array $extra = []): array
    {
        $week = array_fill(0, 7, []);
        foreach ($days as $dow => $windows) {
            $week[$dow] = array_map(fn ($w) => ['from' => $w[0], 'to' => $w[1]], $windows);
        }

        return $extra + ['week' => $week, 'starts' => null, 'ends' => null, 'closed' => []];
    }

    private function useSchedule(array $schedule): void
    {
        $this->settings([
            OrderWindow::ON => '1',
            OrderWindow::KEY => OrderWindow::validated($schedule),
        ]);
    }

    private function at(string $when): array
    {
        Carbon::setTestNow($when);

        return OrderWindow::status($this->shop->id);
    }

    private function custom(string $ar, string $en): void
    {
        $this->settings([OrderWindow::MESSAGE_ON => '1', OrderWindow::MESSAGE => $ar, OrderWindow::MESSAGE_EN => $en]);
    }

    /* ═════════════ ما كان يبقى كما كان ═════════════ */

    public function test_a_shop_that_touched_nothing_keeps_taking_orders(): void
    {
        $this->assertTrue(OrderWindow::open($this->shop->id));
        $this->checkout()->assertOk();
        $this->assertSame(1, Order::count());
    }

    /* ═════════════ الجدول ═════════════ */

    public function test_orders_open_and_close_with_the_hours_of_each_day(): void
    {
        $this->useSchedule($this->schedule([
            self::WED => [['09:00', '21:00']],
            self::FRI => [['10:00', '14:00']],
        ]));

        $before = $this->at('2027-02-10 08:59:00');
        $this->assertFalse($before['open']);
        $this->assertSame('2027-02-10 09:00:00', $before['next']->toDateTimeString());

        $this->assertTrue($this->at('2027-02-10 09:00:00')['open']);
        $this->assertTrue($this->at('2027-02-10 20:59:00')['open']);

        // والخميسُ بلا فترة: مغلق، والفتحُ التالي الجمعة
        $after = $this->at('2027-02-10 21:00:00');
        $this->assertFalse($after['open'], 'نهايةُ الفترة لحظةُ إغلاقٍ لا لحظةُ فتح');
        $this->assertSame('schedule', $after['why']);
        $this->assertSame('2027-02-12 10:00:00', $after['next']->toDateTimeString());
        $this->assertFalse($this->at('2027-02-11 12:00:00')['open']);
    }

    public function test_a_day_may_have_more_than_one_window(): void
    {
        $this->useSchedule($this->schedule([self::WED => [['16:00', '21:00'], ['09:00', '12:00']]]));

        $gap = $this->at('2027-02-10 13:00:00');
        $this->assertFalse($gap['open']);
        $this->assertSame('2027-02-10 16:00:00', $gap['next']->toDateTimeString());
        $this->assertTrue($this->at('2027-02-10 11:59:00')['open']);
        $this->assertTrue($this->at('2027-02-10 16:30:00')['open']);
    }

    public function test_a_window_past_midnight_belongs_to_the_day_it_starts(): void
    {
        $this->useSchedule($this->schedule(
            [self::WED => [['20:00', '02:00']]],
            // والخميسُ إجازة — ولا يقطع ذيلَ مناوبة الأربعاء بعد منتصف الليل
            ['closed' => [['from' => '2027-02-11', 'to' => '2027-02-11']]],
        ));

        $this->assertTrue($this->at('2027-02-10 23:30:00')['open']);
        $this->assertTrue($this->at('2027-02-11 01:30:00')['open'], 'ذيلُ فترة الأربعاء بعد منتصف الليل أُغلق');
        $this->assertFalse($this->at('2027-02-11 02:00:00')['open']);

        // وإجازةُ الأربعاء نفسِه تُغلق فترتَه وذيلَها معًا
        $this->useSchedule($this->schedule(
            [self::WED => [['20:00', '02:00']]],
            ['closed' => [['from' => '2027-02-10', 'to' => '2027-02-10']]],
        ));
        $this->assertFalse($this->at('2027-02-10 21:00:00')['open']);
        $this->assertFalse($this->at('2027-02-11 01:30:00')['open']);
    }

    public function test_holidays_and_the_first_and_last_day_close_the_shop(): void
    {
        $everyDay = array_fill(0, 7, [['00:00', '00:00']]);

        $this->useSchedule($this->schedule($everyDay, ['starts' => '2027-02-12', 'ends' => '2027-02-20']));
        $early = $this->at('2027-02-10 10:00:00');
        $this->assertFalse($early['open'], 'فُتح الاستقبالُ قبل أوّل يومٍ له');
        $this->assertSame('2027-02-12 00:00:00', $early['next']->toDateTimeString());
        $this->assertTrue($this->at('2027-02-15 03:00:00')['open'], 'اليومُ الكامل لم يُقرأ يومًا كاملًا');
        $late = $this->at('2027-02-21 10:00:00');
        $this->assertFalse($late['open']);
        $this->assertNull($late['next'], 'موعدُ فتحٍ بعد آخر يوم');

        $this->useSchedule($this->schedule($everyDay, ['closed' => [['from' => '2027-02-10', 'to' => '2027-02-11']]]));
        $holiday = $this->at('2027-02-10 10:00:00');
        $this->assertFalse($holiday['open']);
        $this->assertSame('2027-02-12 00:00:00', $holiday['next']->toDateTimeString());
    }

    public function test_the_clock_is_muscats_not_the_servers_utc(): void
    {
        $this->useSchedule($this->schedule([self::WED => [['09:00', '21:00']]]));

        // ٥:٠٠ بتوقيت غرينتش هي ٩:٠٠ في مسقط
        Carbon::setTestNow(Carbon::parse('2027-02-10T05:00:00Z'));
        $this->assertTrue(OrderWindow::open($this->shop->id), 'حُكم بساعةٍ غير ساعة مسقط');

        Carbon::setTestNow(Carbon::parse('2027-02-10T04:59:00Z'));
        $this->assertFalse(OrderWindow::open($this->shop->id));
    }

    /* ═════════════ اليدُ أعلى من الجدول ═════════════ */

    public function test_closing_by_hand_wins_over_the_schedule_and_promises_no_reopening(): void
    {
        $this->useSchedule($this->schedule(array_fill(0, 7, [['00:00', '00:00']])));
        $this->settings([OrderWindow::MANUAL => '0']);

        $status = OrderWindow::status($this->shop->id);
        $this->assertFalse($status['open'], 'فتح الجدولُ متجرًا أغلقه صاحبُه بيده');
        $this->assertSame('manual', $status['why']);
        $this->assertNull($status['next'], 'وُعد الزبونُ بموعد فتحٍ لا يعرفه أحد');

        $this->custom("نعتذر عن استقبال الطلبات حاليًا.\nنعود {next_open_date} الساعة {next_open_time}.", "We are closed for now.\nWe reopen {next_open_date} at {next_open_time}.");
        $this->assertSame('نعتذر عن استقبال الطلبات حاليًا.', OrderWindow::message($this->shop->id, 'ar'));
        $this->assertSame('We are closed for now.', OrderWindow::message($this->shop->id, 'en'));
    }

    /* ═════════════ والمغلقُ لا يكتب شيئًا ═════════════ */

    public function test_a_closed_shop_refuses_a_new_order_with_its_own_words(): void
    {
        $this->useSchedule($this->schedule([self::THU => [['09:00', '21:00']]]));
        $this->custom('نعتذر، نعود {next_open_date} الساعة {next_open_time}.', 'Sorry — we reopen {next_open_date} at {next_open_time}.');

        $ar = $this->checkout();
        $ar->assertStatus(422);
        $this->assertSame(['نعتذر، نعود الخميس 11 فبراير الساعة 9:00 ص.'], $ar->json('errors.items'));

        $en = $this->checkout([], 'en');
        $en->assertStatus(422);
        $this->assertSame(['Sorry — we reopen Thursday 11 February at 9:00 AM.'], $en->json('errors.items'));

        $this->assertSame(0, Order::count(), 'كُتب طلبٌ والمتجرُ مغلق');
    }

    public function test_a_closed_shop_opens_no_payment_page(): void
    {
        $this->settings([OrderWindow::MANUAL => '0']);
        $this->bankOpens();

        $this->checkout(['pay' => 'card'])->assertStatus(422)->assertJsonValidationErrors('items');

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count(), 'كُتبت نيّةُ دفعٍ والمتجرُ مغلق');
    }

    /* ═════════════ والرسالةُ بلغة الصفحة وحدها ═════════════ */

    public function test_the_cart_and_the_checkout_say_the_message_in_their_language_only(): void
    {
        $this->settings([OrderWindow::MANUAL => '0']);
        $this->custom('عملاءنا الأعزاء، نعتذر عن استقبال الطلبات حاليًا.', 'Dear customers, we are not accepting online orders right now.');

        foreach (['cart', 'checkout'] as $page) {
            $ar = $this->get('/s/ribbon/'.$page)->assertOk()->getContent();
            $en = $this->get('/s/ribbon/'.$page.'?lang=en')->assertOk()->getContent();

            $this->assertStringContainsString('عملاءنا الأعزاء، نعتذر عن استقبال الطلبات حاليًا.', $ar, $page.': لم تُكتب الرسالةُ العربيّة');
            $this->assertStringNotContainsString('Dear customers', $ar, $page.': وقعت الإنجليزيّةُ على الصفحة العربيّة');
            $this->assertStringContainsString('Dear customers, we are not accepting online orders right now.', $en, $page.': لم تُكتب الرسالةُ الإنجليزيّة');
            $this->assertStringNotContainsString('عملاءنا الأعزاء', $en, $page.': وقعت العربيّةُ على الصفحة الإنجليزيّة');
        }

        // ولا زرَّ إتمامٍ في السلّة — والمتجرُ يبقى يُتصفَّح بأسعاره
        $this->assertStringNotContainsString('data-testid="rb-to-checkout"', $this->get('/s/ribbon/cart')->getContent());
        $this->get('/s/ribbon/shop')->assertOk();
        $this->postJson('/s/ribbon/quote', ['items' => [['id' => $this->rose->id, 'qty' => 1]]])->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_language_with_no_message_takes_the_systems_words_in_that_language(): void
    {
        $this->settings([OrderWindow::MANUAL => '0', OrderWindow::MESSAGE_ON => '1', OrderWindow::MESSAGE => 'مغلقون اليوم.', OrderWindow::MESSAGE_EN => '']);

        $this->assertSame('مغلقون اليوم.', OrderWindow::message($this->shop->id, 'ar'));
        $this->assertSame('The store is not taking online orders right now — contact us.', OrderWindow::message($this->shop->id, 'en'));
    }

    public function test_the_message_is_printed_as_text_not_markup(): void
    {
        $this->settings([OrderWindow::MANUAL => '0']);
        $this->custom('<script>alert(1)</script> مغلقون', '<img src=x onerror=alert(1)> closed');

        $ar = $this->get('/s/ribbon/cart')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $ar);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; مغلقون', $ar);
        $this->assertStringNotContainsString('<img src=x', $this->get('/s/ribbon/cart?lang=en')->getContent());
    }

    /* ═════════════ والحفظ ═════════════ */

    public function test_saving_asks_for_both_messages_known_placeholders_and_a_window(): void
    {
        $save = fn (array $data) => $this->actingAs($this->owner)->from(route('admin.website.shop'))->post(route('admin.marketing.store.save'), $data);

        $save([OrderWindow::MESSAGE_ON => true, OrderWindow::MESSAGE => 'مغلقون.', OrderWindow::MESSAGE_EN => ''])
            ->assertSessionHasErrors(OrderWindow::MESSAGE_EN);

        $save([OrderWindow::MESSAGE => 'نعود {next_day}'])->assertSessionHasErrors(OrderWindow::MESSAGE);

        $save([OrderWindow::ON => true, OrderWindow::KEY => json_encode($this->schedule([]))])
            ->assertSessionHasErrors(OrderWindow::KEY);

        $save([OrderWindow::KEY => json_encode($this->schedule([self::WED => [['9:00', '21:00']]]))])
            ->assertSessionHasErrors(OrderWindow::KEY);

        $this->assertTrue(OrderWindow::open($this->shop->id), 'حفظٌ مردودٌ غيّر حالَ المتجر');

        $save([
            OrderWindow::ON => true,
            OrderWindow::KEY => json_encode($this->schedule([self::THU => [['09:00', '21:00']]])),
            OrderWindow::MESSAGE_ON => true,
            OrderWindow::MESSAGE => "مغلقون.\r\nنعود {next_open_date}.",
            OrderWindow::MESSAGE_EN => 'Closed.',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(OrderWindow::open($this->shop->id), 'الجدولُ يسري عند الحفظ لا عند النشر');
        $this->assertSame("مغلقون.\nنعود الخميس 11 فبراير.", OrderWindow::message($this->shop->id, 'ar'));
    }

    /* ═════════════ ولكلّ متجرٍ جدولُه ═════════════ */

    public function test_one_shop_closing_does_not_close_another(): void
    {
        $first = $this->shop;
        $this->settings([OrderWindow::MANUAL => '0']);
        $this->useSchedule($this->schedule([]));

        $this->openRibbon('other', 'other@checkout.local');

        $this->assertFalse(OrderWindow::open($first->id));
        $this->assertTrue(OrderWindow::open($this->shop->id), 'إغلاقُ متجرٍ أغلق جارَه');
        $this->checkout()->assertOk();
    }
}

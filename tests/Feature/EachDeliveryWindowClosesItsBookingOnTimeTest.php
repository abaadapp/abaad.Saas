<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\MarketingSettings;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\OrderWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * لكلّ فترة توصيلٍ آخرُ وقتٍ لحجزها — وحين لا موعدَ للتوصيل يقول صاحبُ المتجر ذلك بكلماته.
 *
 * ═══ ما يُحرس ═══
 *
 *   - عند آخر حجز الفترة — لا بعده بدقيقة — تختفي من خيارات الزبون وتبقى التاليةُ
 *     لها، بتوقيت مسقط، في يومها أو في اليوم السابق. ولا يُغلق بهذا الاستقبال.
 *   - والخادمُ يردّها ولو أُرسلت بيد، ولا يفتح لها صفحةَ دفع.
 *   - ويجتمع الحدُّ مع مدّة التجهيز: تُعرض الفترةُ ما لم يبلغ أيٌّ منهما.
 *   - وفترةٌ بلا حدٍّ كما كانت، والجدولُ المحفوظ قبله يُقرأ ويُحفظ نصُّه كما هو.
 *   - ودفعةٌ فُتحت قبل الحدّ وقُبضت بعده تصير طلبَها، مرّةً واحدة.
 *   - ورسالةُ «لا مواعيد للتوصيل» بلغة الصفحة حين لا موعدَ في نطاق الحجز كلِّه،
 *     والاستلامُ يبقى يُطلب، والمتجرُ المغلق يقول رسالةَ إغلاقه هو.
 *
 * وما يُرسم في الصفحة من القائمة: `the-ribbon-checkout-offers-only-open-times.test.ts`.
 */
class EachDeliveryWindowClosesItsBookingOnTimeTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private const WED = '2027-02-10';

    private const THU = '2027-02-11';

    private const SAT = 6;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // الأربعاء ١٠ فبراير، السابعة صباحًا بتوقيت مسقط
        Carbon::setTestNow('2027-02-10 07:00:00');
        $this->openRibbon();
        $this->settings(['store_max_days' => '7']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * كلَّ يوم: الصباحُ 9 – 12 وآخرُ حجزه الثامنة، والمساءُ 4 – 9 وآخرُ حجزه
     * الثانية ظهرًا. والاستلامُ بجدوله، بلا حدّ. ولا تجهيز.
     */
    private function plan(?array $morningCutoff = ['time' => '08:00', 'day' => 'same'], int $lead = 0): array
    {
        $week = array_fill(0, 7, [
            ['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning', 'cutoff' => $morningCutoff],
            ['id' => 'pm01', 'from' => '16:00', 'to' => '21:00', 'ar' => 'المساء', 'en' => 'Evening', 'cutoff' => ['time' => '14:00', 'day' => 'same']],
        ]);

        return [
            'delivery' => $week,
            'pickup' => array_fill(0, 7, [['id' => 'pk01', 'from' => '10:00', 'to' => '13:00', 'ar' => '', 'en' => '']]),
            'closed' => [],
            'lead' => $lead,
        ];
    }

    private function usePlan(array $plan): void
    {
        $this->settings([DeliverySchedule::ON => '1', DeliverySchedule::KEY => DeliverySchedule::validated($plan)]);
    }

    /** فتراتُ يومٍ كما تُعرض للزبون الآن — أو `null` إن لم يُعرض اليومُ أصلًا */
    private function offered(string $date, string $fulfil = 'delivery'): ?array
    {
        $day = collect(DeliverySchedule::days($this->shop->id, $fulfil))->firstWhere('date', $date);

        return $day === null ? null : array_column($day['slots'], 'id');
    }

    private function at(string $when): void
    {
        Carbon::setTestNow($when);
    }

    private function deliver(array $over = [], string $lang = 'ar')
    {
        return $this->checkout($over + ['fulfil' => 'delivery', 'address' => 'الخوير، شارع ٣٣'], $lang);
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->from(route('admin.website.shop'))->post(route('admin.marketing.store.save'), $data);
    }

    /* ═════════════ آخرُ الحجز ═════════════ */

    public function test_a_window_leaves_the_choices_at_its_cutoff_and_the_later_ones_stay(): void
    {
        $this->usePlan($this->plan());

        $this->at('2027-02-10 07:59:00');
        $this->assertSame(['am01', 'pm01'], $this->offered(self::WED));

        $this->at('2027-02-10 08:00:00');
        $this->assertSame(['pm01'], $this->offered(self::WED), 'بقيت فترةُ الصباح تُحجز عند آخر حجزها');
        $this->assertSame(['am01', 'pm01'], $this->offered(self::THU), 'أغلق حدُّ اليوم صباحَ الغد');

        $this->at('2027-02-10 13:59:00');
        $this->assertSame(['pm01'], $this->offered(self::WED));

        // ولا فترةَ بقيت اليوم: يغيب اليومُ ويبقى الغد
        $this->at('2027-02-10 14:00:00');
        $this->assertNull($this->offered(self::WED));
        $this->assertSame(['am01', 'pm01'], $this->offered(self::THU));

        // ولا يُغلق بهذا الاستقبال: طلبٌ لصباح الغد يُكتب
        $this->assertTrue(OrderWindow::open($this->shop->id), 'أغلق انتهاءُ فترات اليوم استقبالَ الطلبات');
        $this->deliver(['date' => self::THU, 'slot' => 'am01'])->assertOk();
        $this->assertSame('2027-02-11 09:00:00', Order::firstOrFail()->scheduled_for->toDateTimeString());
    }

    public function test_the_server_refuses_a_window_past_its_cutoff_even_sent_by_hand(): void
    {
        $this->usePlan($this->plan());

        $this->at('2027-02-10 08:00:00');
        $this->deliver(['date' => self::WED, 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('slot');
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ لفترةٍ انتهى حجزُها');

        $this->at('2027-02-10 07:59:59');
        $this->deliver(['date' => self::WED, 'slot' => 'am01'])->assertOk();
        $this->assertSame('am01', Order::firstOrFail()->delivery_slot);
    }

    public function test_a_cutoff_on_the_day_before_closes_the_window_the_evening_before(): void
    {
        $this->usePlan($this->plan(['time' => '21:00', 'day' => 'before']));

        $this->at('2027-02-09 20:59:00');
        $this->assertSame(['am01', 'pm01'], $this->offered(self::WED));

        $this->at('2027-02-09 21:00:00');
        $this->assertSame(['pm01'], $this->offered(self::WED), 'بقي صباحُ الأربعاء يُحجز بعد التاسعة مساءَ الثلاثاء');
        $this->assertSame(['am01', 'pm01'], $this->offered(self::THU), 'حدُّ الأربعاء أغلق الخميس');

        // وبعد منتصف الليل ما زال مغلقًا — حدُّه مضى أمس
        $this->at('2027-02-10 00:30:00');
        $this->assertSame(['pm01'], $this->offered(self::WED));
    }

    public function test_the_cutoff_and_the_preparation_time_both_hold(): void
    {
        // تجهيزٌ ساعتان: الصباحُ تُغلقه ساعةُ التجهيز قبل حدّه، والمساءُ يُغلقه حدُّه قبل التجهيز
        $this->usePlan($this->plan(lead: 120));

        $this->at('2027-02-10 06:59:00');
        $this->assertSame(['am01', 'pm01'], $this->offered(self::WED));

        $this->at('2027-02-10 07:01:00');
        $this->assertSame(['pm01'], $this->offered(self::WED), 'عُرضت فترةٌ لا يُجهَّز طلبُها قبلها');

        $this->at('2027-02-10 13:59:00');
        $this->assertSame(['pm01'], $this->offered(self::WED));
        $this->at('2027-02-10 14:00:00');
        $this->assertNull($this->offered(self::WED), 'التجهيزُ يسمح والحدُّ مضى — فعُرضت');
    }

    public function test_the_clock_is_muscats_not_utc(): void
    {
        $this->usePlan($this->plan());

        // الرابعةُ فجرًا بتوقيت غرينتش هي الثامنةُ بمسقط
        Carbon::setTestNow(Carbon::parse('2027-02-10 03:59:00', 'UTC'));
        $this->assertSame(['am01', 'pm01'], $this->offered(self::WED));
        Carbon::setTestNow(Carbon::parse('2027-02-10 04:00:00', 'UTC'));
        $this->assertSame(['pm01'], $this->offered(self::WED), 'حُسب الحدُّ بساعة غرينتش');
    }

    public function test_a_window_without_a_cutoff_works_as_before_and_an_old_schedule_keeps_its_text(): void
    {
        // جدولٌ حُفظ قبل الحدّ — بلا مفتاح `cutoff` في فتراته
        $old = '{"delivery":[[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}],[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}],[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}],[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}],[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}],[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}],[{"id":"am01","from":"09:00","to":"12:00","ar":"","en":""}]],"pickup":null,"closed":[],"lead":0}';
        $this->settings([DeliverySchedule::ON => '1', DeliverySchedule::KEY => $old]);

        // تُعرض حتّى تبدأ، كما كانت
        $this->at('2027-02-10 08:59:00');
        $this->assertSame(['am01'], $this->offered(self::WED));
        $this->at('2027-02-10 09:00:00');
        $this->assertSame(['am01'], $this->offered(self::WED));
        $this->at('2027-02-10 09:01:00');
        $this->assertNull($this->offered(self::WED));

        // وحفظُه ثانيةً يكتب النصَّ نفسَه — لا مفتاحَ يُضاف لفترةٍ بلا حدّ
        $this->assertSame($old, DeliverySchedule::validated($old));
    }

    public function test_saving_a_cutoff_keeps_the_window_id_and_refuses_one_after_the_start(): void
    {
        $plan = $this->plan(null);
        $this->save([DeliverySchedule::ON => true, DeliverySchedule::KEY => json_encode($plan)])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('cutoff', DeliverySchedule::config($this->shop->id)['delivery'][self::SAT][0]);

        // يُضاف الحدُّ لفترةٍ قائمة — ومعرّفُها كما هو
        $plan['delivery'][self::SAT][0]['cutoff'] = ['time' => '22:30', 'day' => 'before'];
        $this->save([DeliverySchedule::KEY => json_encode($plan)])->assertSessionHasNoErrors();
        $morning = DeliverySchedule::config($this->shop->id)['delivery'][self::SAT][0];
        $this->assertSame('am01', $morning['id']);
        $this->assertSame(['time' => '22:30', 'day' => 'before'], $morning['cutoff']);

        // وحدٌّ في يوم الفترة بعد بدايتها وعدٌ لا يُوفى
        $late = $plan;
        $late['delivery'][self::SAT][0]['cutoff'] = ['time' => '09:30', 'day' => 'same'];
        $this->save([DeliverySchedule::KEY => json_encode($late)])
            ->assertSessionHasErrors([DeliverySchedule::KEY => 'آخر حجز لفترة 9:00 ص – 12:00 م بعد بدايتها — اجعله 9:00 ص أو قبلها، أو في اليوم السابق.']);

        $bad = $plan;
        $bad['delivery'][self::SAT][0]['cutoff'] = ['time' => '08:00', 'day' => 'tomorrow'];
        $this->save([DeliverySchedule::KEY => json_encode($bad)])->assertSessionHasErrors(DeliverySchedule::KEY);
        // وساعةٌ لا تُقرأ — في اليوم السابق، فلا يردّها فحصُ البداية بدلًا منها
        $bad['delivery'][self::SAT][0]['cutoff'] = ['time' => '8 ص', 'day' => 'before'];
        $this->save([DeliverySchedule::KEY => json_encode($bad)])
            ->assertSessionHasErrors([DeliverySchedule::KEY => 'اكتب آخر وقت للحجز بالساعة والدقيقة.']);

        // وعند بدايتها تمامًا مقبول، وساعةٌ فارغةٌ تعني بلا حدّ
        $edge = $plan;
        $edge['delivery'][self::SAT][0]['cutoff'] = ['time' => '09:00', 'day' => 'same'];
        $edge['delivery'][self::SAT][1]['cutoff'] = ['time' => '', 'day' => 'same'];
        $this->save([DeliverySchedule::KEY => json_encode($edge)])->assertSessionHasNoErrors();
        $saturday = DeliverySchedule::config($this->shop->id)['delivery'][self::SAT];
        $this->assertSame(['time' => '09:00', 'day' => 'same'], $saturday[0]['cutoff']);
        $this->assertArrayNotHasKey('cutoff', $saturday[1]);
    }

    /* ═════════════ والدفعُ بالبطاقة ═════════════ */

    public function test_a_payment_opened_before_the_cutoff_and_paid_after_becomes_its_order_once(): void
    {
        $this->usePlan($this->plan());
        $this->bankOpens();

        $this->at('2027-02-10 07:59:00');
        $this->deliver(['pay' => 'card', 'date' => self::WED, 'slot' => 'am01'])->assertOk()->assertJsonPath('ok', true);
        $intent = StorePaymentIntent::latest('id')->firstOrFail();

        // والزبونُ عند البنك حين بلغت الثامنة
        $this->at('2027-02-10 08:05:00');
        $this->assertSame(['pm01'], $this->offered(self::WED));
        $this->paid($intent);
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'رُدّ طلبٌ مدفوعٌ لأنّ حجزَ فترته انتهى والزبونُ عند البنك — أو كُتب مرّتين');
        $order = Order::firstOrFail();
        $this->assertSame('am01', $order->delivery_slot);
        $this->assertSame('2027-02-10 09:00:00', $order->scheduled_for->toDateTimeString());
        $this->assertFalse($intent->fresh()->strayPayment());
    }

    public function test_no_new_payment_opens_for_a_window_past_its_cutoff(): void
    {
        $this->usePlan($this->plan());
        $this->bankOpens();

        $this->at('2027-02-10 08:00:00');
        $this->deliver(['pay' => 'card', 'date' => self::WED, 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('slot');

        Http::assertNothingSent();
        $this->assertSame(0, StorePaymentIntent::count());
    }

    /* ═════════════ وحين لا موعدَ للتوصيل ═════════════ */

    /**
     * التوصيلُ السبتَ وحده والحجزُ يومٌ واحدٌ مقدّمًا — فلا موعدَ توصيلٍ في
     * النطاق يومَ الأربعاء. والاستلامُ كلَّ يوم.
     */
    private function noDeliveryInRange(): void
    {
        $plan = $this->plan(null);
        foreach ([0, 1, 2, 3, 4, 5] as $dow) {
            $plan['delivery'][$dow] = [];
        }
        $this->usePlan($plan);
        $this->settings([
            'store_max_days' => '1',
            DeliverySchedule::UNAVAILABLE => "لا مواعيد توصيل متاحة حاليًا.\nيمكنك الاستلام من المحل.",
            DeliverySchedule::UNAVAILABLE_EN => "No delivery times are available right now.\nYou can pick up from the shop.",
        ]);
    }

    public function test_the_no_delivery_message_is_the_shops_own_in_the_pages_language(): void
    {
        $this->noDeliveryInRange();

        $this->assertNull($this->offered(self::WED));
        $this->assertSame([], DeliverySchedule::days($this->shop->id, 'delivery'));
        $this->assertNotSame([], DeliverySchedule::days($this->shop->id, 'pickup'), 'أُغلق الاستلامُ مع التوصيل');

        $ar = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-delivery="لا مواعيد توصيل متاحة حاليًا.'."\n".'يمكنك الاستلام من المحل."', $ar);
        $this->assertStringNotContainsString('No delivery times are available', $ar);

        $en = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString('data-delivery="No delivery times are available right now.', $en);
        $this->assertStringNotContainsString('لا مواعيد توصيل متاحة حاليًا', $en, 'وقعت رسالةُ العربيّة على الصفحة الإنجليزيّة');

        // والاستلامُ يُطلب، والتوصيلُ يُردّ بيومه
        $this->checkout(['date' => self::WED, 'slot' => 'pk01'])->assertOk();
        $this->deliver(['date' => self::WED, 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->assertSame(1, Order::count());
    }

    public function test_the_message_waits_while_a_later_day_still_has_a_delivery_time(): void
    {
        // انتهت فتراتُ اليوم وفتراتُ الغد متاحة — فالقائمةُ ليست فارغة
        $this->usePlan($this->plan());
        $this->settings([DeliverySchedule::UNAVAILABLE => 'لا مواعيد.']);
        $this->at('2027-02-10 14:00:00');

        $this->assertNull($this->offered(self::WED));
        $this->assertNotSame([], DeliverySchedule::days($this->shop->id, 'delivery'));
    }

    public function test_without_a_written_message_the_system_text_stays(): void
    {
        $this->noDeliveryInRange();
        $this->settings([DeliverySchedule::UNAVAILABLE => '', DeliverySchedule::UNAVAILABLE_EN => '']);

        $page = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-delivery=', $page);
        $this->assertStringContainsString('لا مواعيد متاحة الآن لهذه الطريقة — تواصل معنا.', $page);
    }

    public function test_a_closed_shop_says_its_closing_words_not_the_delivery_ones(): void
    {
        $this->noDeliveryInRange();
        $this->settings([OrderWindow::MANUAL => '0']);

        $page = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-closed"', $page);
        $this->assertStringContainsString('المتجر لا يستقبل طلبات من الموقع الآن — تواصل معنا.', $page);
        $this->assertStringNotContainsString('لا مواعيد توصيل متاحة حاليًا', $page, 'قيلت رسالةُ التوصيل لمتجرٍ أُغلق استقبالُه');
    }

    public function test_the_message_is_printed_as_text_not_markup(): void
    {
        $this->noDeliveryInRange();
        $this->settings([DeliverySchedule::UNAVAILABLE => '<script>alert(1)</script> لا توصيل']);

        $page = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; لا توصيل', $page);
    }

    public function test_saving_the_messages_keeps_their_lines_and_each_language_alone(): void
    {
        $this->save([
            DeliverySchedule::UNAVAILABLE => "  لا مواعيد اليوم.\r\nجرّب الاستلام.  ",
            DeliverySchedule::UNAVAILABLE_EN => '',
        ])->assertSessionHasNoErrors();

        $site = MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame("لا مواعيد اليوم.\nجرّب الاستلام.", $site[DeliverySchedule::UNAVAILABLE]);
        $this->assertSame('لا مواعيد اليوم.'."\n".'جرّب الاستلام.', DeliverySchedule::unavailableSays($this->shop->id, 'ar'));
        $this->assertNull(DeliverySchedule::unavailableSays($this->shop->id, 'en'), 'وقعت العربيّةُ على الصفحة الإنجليزيّة');

        $this->save([DeliverySchedule::UNAVAILABLE => str_repeat('ن', DeliverySchedule::UNAVAILABLE_MAX + 1)])
            ->assertSessionHasErrors(DeliverySchedule::UNAVAILABLE);
    }

    public function test_one_shops_cutoff_and_message_do_not_reach_another(): void
    {
        $this->usePlan($this->plan());
        $this->settings([DeliverySchedule::UNAVAILABLE => 'رسالةُ المتجر الأوّل.']);
        $first = $this->shop;

        $this->openRibbon('ribbon-two', 'two@checkout.local');
        $this->settings([DeliverySchedule::ON => '1', DeliverySchedule::KEY => DeliverySchedule::validated($this->plan(null))]);

        $this->at('2027-02-10 08:30:00');
        $this->assertSame(['am01', 'pm01'], $this->offered(self::WED), 'حدُّ المتجر الأوّل أغلق فترةَ الثاني');
        $this->assertNull(DeliverySchedule::unavailableSays($this->shop->id, 'ar'));
        $this->assertSame('رسالةُ المتجر الأوّل.', DeliverySchedule::unavailableSays($first->id, 'ar'));
    }
}

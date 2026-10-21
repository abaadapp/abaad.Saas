<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\MarketingSettings;
use App\Support\Store\DeliverySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * المتجرُ يضبط أيّامَ التسليم والاستلام وفتراتِها — والزبونُ يختار من المتاح وحدَه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا يُعرض يومٌ لا تسليمَ فيه، ولا يومٌ أُغلق، ولا فترةٌ مضت أو تبدأ قبل مدّة
 *     التجهيز، ولا ما بعد آخر يومٍ يُحجز.
 *   - والخادمُ يردّ ذلك كلَّه ولو أُرسل بيده، ومعرّفًا مصنوعًا.
 *   - الفترةُ المختارة تُكتب بدايةً ونهايةً ومعرّفًا — لا ساعةً تُستخرج من نصّ.
 *   - الاستلامُ بجدوله إن كان له جدول، وإلّا بجدول التوصيل.
 *   - معرّفُ الفترة يُعطى مرّةً ويبقى، وفترتان متداخلتان في يومٍ لا تُحفظان.
 *   - متجرٌ لم يفتح الجدولَ يعمل بسطوره كما كان، ومتجرٌ لا يمسّ غيرَه.
 *
 * والحكمُ بساعة مسقط. ودفعةٌ تبدّلت فترتُها والزبونُ عند البنك:
 * `APaymentStartedBeforeClosingBecomesItsOrderTest`.
 */
class TheShopSetsItsDeliveryAndPickupTimesTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private const SAT = '2027-02-13';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // الأربعاء ١٠ فبراير، العاشرة صباحًا
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->openRibbon();
        $this->settings(['store_max_days' => '7']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * السبتُ إلى الخميس صباحٌ ومساء، والجمعةُ بلا توصيل. والاستلامُ كلَّ يومٍ
     * بفترته، والخميسُ إجازة، والتجهيزُ ساعتان.
     */
    private function plan(bool $ownPickup = true): array
    {
        $week = array_fill(0, 7, [
            ['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning'],
            ['id' => 'pm01', 'from' => '16:00', 'to' => '21:00', 'ar' => '', 'en' => ''],
        ]);
        $week[5] = [];

        return [
            'delivery' => $week,
            'pickup' => $ownPickup ? array_fill(0, 7, [['id' => 'pk01', 'from' => '10:00', 'to' => '13:00', 'ar' => '', 'en' => '']]) : null,
            'closed' => [['from' => '2027-02-11', 'to' => '2027-02-11']],
            'lead' => 120,
        ];
    }

    private function usePlan(array $plan): void
    {
        $this->settings([DeliverySchedule::ON => '1', DeliverySchedule::KEY => DeliverySchedule::validated($plan)]);
    }

    private function deliver(array $over = [], string $lang = 'ar')
    {
        return $this->checkout($over + ['fulfil' => 'delivery', 'address' => 'الخوير، شارع ٣٣'], $lang);
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->from(route('admin.website.shop'))->post(route('admin.marketing.store.save'), $data);
    }

    /* ═════════════ ما يُعرض ═════════════ */

    public function test_only_open_days_and_windows_still_ahead_are_offered(): void
    {
        $this->usePlan($this->plan());

        $days = DeliverySchedule::days($this->shop->id, 'delivery');

        // الخميسُ إجازة، والجمعةُ بلا توصيل، وآخرُ يومٍ بعد سبعة أيّام
        $this->assertSame(
            ['2027-02-10', '2027-02-13', '2027-02-14', '2027-02-15', '2027-02-16', '2027-02-17'],
            array_column($days, 'date'),
        );
        // وصباحُ اليوم مضى — والمساءُ بعد التجهيز
        $this->assertSame(['pm01'], array_column($days[0]['slots'], 'id'));
        $this->assertSame(['am01', 'pm01'], array_column($days[1]['slots'], 'id'));
    }

    public function test_the_preparation_time_hides_a_window_that_starts_too_soon(): void
    {
        $this->usePlan($this->plan());
        Carbon::setTestNow('2027-02-10 14:30:00');

        // الجاهزيّةُ ٤:٣٠ — ومساءُ اليوم يبدأ ٤:٠٠
        $this->assertSame(self::SAT, DeliverySchedule::days($this->shop->id, 'delivery')[0]['date']);
    }

    /* ═════════════ وما يردّه الخادم ═════════════ */

    public function test_the_server_refuses_a_closed_day_a_past_window_and_a_made_up_one(): void
    {
        $this->usePlan($this->plan());

        $this->deliver(['date' => '2027-02-11', 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->deliver(['date' => '2027-02-12', 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->deliver(['date' => '2027-02-10', 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('slot');
        $this->deliver(['date' => self::SAT, 'slot' => 'zzzz'])->assertStatus(422)->assertJsonValidationErrors('slot');
        $this->deliver(['date' => self::SAT, 'slot' => 'pk01'])->assertStatus(422)->assertJsonValidationErrors('slot');
        $this->deliver(['date' => '2027-02-20', 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('date');
        // ولا تُتجاوز بحقلٍ أطفأه صاحبُه: الجدولُ يشترط اليومَ والفترة
        $this->settings(['store_field_date' => 'off', 'store_field_slot' => 'off']);
        $this->deliver(['date' => '', 'slot' => ''])->assertStatus(422)->assertJsonValidationErrors(['date', 'slot']);

        $this->assertSame(0, Order::count(), 'كُتب طلبٌ بموعدٍ لا يُسلَّم فيه');
    }

    /* ═════════════ وما يُكتب ═════════════ */

    public function test_a_chosen_window_writes_its_exact_start_end_and_id(): void
    {
        $this->usePlan($this->plan());

        $this->deliver(['date' => self::SAT, 'slot' => 'am01'])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('2027-02-13 09:00:00', $order->scheduled_for->toDateTimeString());
        $this->assertSame('2027-02-13 12:00:00', $order->scheduled_until->toDateTimeString());
        $this->assertSame('am01', $order->delivery_slot);
        $this->assertSame('الصباح (9:00 ص – 12:00 م)', $order->delivery_notes, 'لم يُكتب للمحلّ اسمُ الفترة وساعتاها');
    }

    public function test_pickup_reads_its_own_schedule_or_the_delivery_one(): void
    {
        $this->usePlan($this->plan());

        // الجمعةُ لا توصيلَ فيها — والاستلامُ فيها مفتوح
        $this->checkout(['fulfil' => 'pickup', 'date' => '2027-02-12', 'slot' => 'pk01'])->assertOk();
        $this->assertSame('2027-02-12 10:00:00', Order::firstOrFail()->scheduled_for->toDateTimeString());
        $this->checkout(['fulfil' => 'pickup', 'date' => self::SAT, 'slot' => 'am01'])->assertStatus(422)->assertJsonValidationErrors('slot');

        $this->usePlan($this->plan(ownPickup: false));
        $this->checkout(['fulfil' => 'pickup', 'date' => self::SAT, 'slot' => 'am01', 'phone' => '96899220002'])->assertOk();
        $this->assertSame(2, Order::count());
    }

    public function test_a_shop_without_the_schedule_keeps_its_free_text_slots(): void
    {
        $this->checkout(['date' => '2027-02-12', 'slot' => '4 م – 8 م'])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('2027-02-12 16:00:00', $order->scheduled_for->toDateTimeString());
        $this->assertSame('4 م – 8 م', $order->delivery_notes);
        $this->assertNull($order->delivery_slot);
        $this->assertNull($order->scheduled_until);
    }

    /* ═════════════ والحفظ ═════════════ */

    public function test_a_window_gets_its_id_once_and_keeps_it(): void
    {
        $plan = $this->plan();
        $plan['delivery'][6][] = ['id' => '', 'from' => '13:00', 'to' => '15:00', 'ar' => 'الظهر', 'en' => 'Noon'];

        $this->save([DeliverySchedule::ON => true, DeliverySchedule::KEY => json_encode($plan)])->assertSessionHasNoErrors();

        $saved = DeliverySchedule::config($this->shop->id)['delivery'][6];
        $noon = collect($saved)->firstWhere('ar', 'الظهر');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{4,12}$/', $noon['id'], 'فترةٌ جديدةٌ بلا معرّف');

        // ويبقى معرّفُها وقد تبدّلت ساعتاها
        $plan['delivery'][6] = array_map(fn ($s) => $s['ar'] === 'الظهر' ? ['id' => $noon['id'], 'from' => '13:30', 'to' => '15:30'] + $s : $s, $saved);
        $this->save([DeliverySchedule::KEY => json_encode($plan)])->assertSessionHasNoErrors();

        $again = collect(DeliverySchedule::config($this->shop->id)['delivery'][6])->firstWhere('id', $noon['id']);
        $this->assertSame('13:30', $again['from'], 'تبدّل معرّفُ الفترة بتعديل ساعتها');
    }

    public function test_saving_refuses_a_schedule_a_buyer_could_not_use(): void
    {
        $empty = ['delivery' => array_fill(0, 7, []), 'pickup' => null, 'closed' => [], 'lead' => 0];
        $this->save([DeliverySchedule::ON => true, DeliverySchedule::KEY => json_encode($empty)])
            ->assertSessionHasErrors(DeliverySchedule::KEY);

        $backwards = $this->plan();
        $backwards['delivery'][6][0]['to'] = '08:00';
        $this->save([DeliverySchedule::KEY => json_encode($backwards)])->assertSessionHasErrors(DeliverySchedule::KEY);

        $this->assertFalse(DeliverySchedule::on($this->shop->id));
    }

    public function test_saving_refuses_two_windows_that_overlap_in_one_day_and_says_which(): void
    {
        $overlap = $this->plan();
        $overlap['delivery'][6][] = ['id' => 'nn01', 'from' => '11:00', 'to' => '14:00', 'ar' => '', 'en' => ''];

        $this->save([DeliverySchedule::ON => true, DeliverySchedule::KEY => json_encode($overlap)])
            ->assertSessionHasErrors([DeliverySchedule::KEY => 'فترتا توصيلٍ يوم السبت متداخلتان: 9:00 ص – 12:00 م و11:00 ص – 2:00 م — ابدأ كل فترة عند نهاية التي قبلها أو بعدها.']);

        // وفترةٌ في جوف أخرى تداخلٌ أيضًا — ولو جاءت بعد فترةٍ لا تمسّها
        $inside = $this->plan();
        $inside['pickup'][0] = [
            ['id' => 'pk01', 'from' => '08:00', 'to' => '18:00', 'ar' => '', 'en' => ''],
            ['id' => 'pk02', 'from' => '12:00', 'to' => '13:00', 'ar' => '', 'en' => ''],
        ];
        $this->save([DeliverySchedule::ON => true, DeliverySchedule::KEY => json_encode($inside)])
            ->assertSessionHasErrors([DeliverySchedule::KEY => 'فترتا استلامٍ يوم الأحد متداخلتان: 8:00 ص – 6:00 م و12:00 م – 1:00 م — ابدأ كل فترة عند نهاية التي قبلها أو بعدها.']);

        $this->assertFalse(DeliverySchedule::on($this->shop->id), 'حُفظ جدولٌ بفترتين متداخلتين');
        $this->assertSame('', (string) (MarketingSettings::group($this->shop->id, 'website')[DeliverySchedule::KEY] ?? ''));

        // وبلغة شاشة صاحبه
        app()->setLocale('en');
        try {
            DeliverySchedule::validated($overlap);
            $this->fail('قُبل جدولٌ بفترتين متداخلتين');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Two delivery windows on Saturday overlap: 9:00 AM – 12:00 PM and 11:00 AM – 2:00 PM — start each window when the one before it ends, or later.',
                $e->errors()[DeliverySchedule::KEY][0],
            );
        }
    }

    public function test_windows_that_only_touch_and_the_old_ways_still_save(): void
    {
        // «9 – 12» ثمّ «12 – 3» متتاليتان، والتوصيلُ والاستلامُ يجتمعان في الساعة نفسِها
        $touching = $this->plan();
        $touching['delivery'][6][] = ['id' => 'nn01', 'from' => '12:00', 'to' => '15:00', 'ar' => '', 'en' => ''];
        $this->save([DeliverySchedule::ON => true, DeliverySchedule::KEY => json_encode($touching)])->assertSessionHasNoErrors();
        $this->assertCount(3, DeliverySchedule::config($this->shop->id)['delivery'][6]);

        // والسطورُ الحرّةُ القديمة تُحفظ كما كانت ولو تداخلت ساعاتُها — لا يُفحص فيها شيء
        $this->save([DeliverySchedule::ON => false, 'store_delivery_slots' => "9 ص – 12 م\n11 ص – 2 م"])->assertSessionHasNoErrors();
        $this->assertSame("9 ص – 12 م\n11 ص – 2 م", MarketingSettings::group($this->shop->id, 'website')['store_delivery_slots']);

        // وجدولٌ محفوظٌ يُقرأ كما هو — الفحصُ عند الحفظ لا عند العرض
        $stored = $this->plan();
        $stored['delivery'][6][] = ['id' => 'nn01', 'from' => '11:00', 'to' => '14:00', 'ar' => '', 'en' => ''];
        $this->settings([DeliverySchedule::ON => '1', DeliverySchedule::KEY => json_encode($stored)]);
        $saturday = collect(DeliverySchedule::days($this->shop->id, 'delivery'))->firstWhere('date', self::SAT);
        $this->assertSame(['am01', 'nn01', 'pm01'], array_column($saturday['slots'], 'id'));
    }

    /* ═════════════ وما يقرؤه الزبون ═════════════ */

    public function test_the_checkout_offers_the_days_and_windows_in_its_language(): void
    {
        $this->usePlan($this->plan());

        $ar = $this->get('/s/ribbon/checkout')->assertOk();
        $schedule = $ar->viewData('schedule');
        $this->assertSame('السبت 13 فبراير', $schedule['delivery'][1]['label']);
        $this->assertSame('الصباح (9:00 ص – 12:00 م)', $schedule['delivery'][1]['slots'][0]['label']);
        $this->assertStringContainsString('data-testid="rb-schedule"', $ar->getContent());
        $this->assertStringNotContainsString('type="date"', $ar->getContent(), 'بقي التقويمُ الحرّ بجانب الجدول');

        $en = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->viewData('schedule');
        $this->assertSame('Saturday 13 February', $en['delivery'][1]['label']);
        $this->assertSame('Morning (9:00 AM – 12:00 PM)', $en['delivery'][1]['slots'][0]['label']);
        $this->assertSame('4:00 PM – 9:00 PM', $en['delivery'][1]['slots'][1]['label'], 'فترةٌ بلا اسمٍ إنجليزيٍّ أخذت اسمها العربيّ');
    }

    public function test_the_thank_you_page_says_the_window_in_the_buyers_language(): void
    {
        $this->usePlan($this->plan());

        $done = $this->deliver(['date' => self::SAT, 'slot' => 'am01'], 'en')->assertOk()->json('redirect');

        $this->get($done.'&lang=en')->assertOk()->assertSee('Saturday 13 February · Morning (9:00 AM – 12:00 PM)');
    }

    public function test_moving_the_time_by_hand_forgets_the_chosen_window(): void
    {
        $this->usePlan($this->plan());
        $this->deliver(['date' => self::SAT, 'slot' => 'am01'])->assertOk();
        $order = Order::firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('admin.orders.details.update', $order->number), ['scheduled_for' => '2027-02-13T17:00'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('2027-02-13 17:00:00', $order->scheduled_for->toDateTimeString());
        $this->assertNull($order->delivery_slot, 'بقيت «الصباح» على موعدٍ صار مساءً');
        $this->assertNull($order->scheduled_until);
    }

    public function test_one_shops_schedule_does_not_reach_another(): void
    {
        $this->usePlan($this->plan());
        $first = $this->shop;

        $this->openRibbon('other', 'other@checkout.local');

        $this->assertTrue(DeliverySchedule::on($first->id));
        $this->assertFalse(DeliverySchedule::on($this->shop->id), 'جدولُ متجرٍ وقع على جاره');
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')[DeliverySchedule::KEY]);
        $this->checkout(['date' => '2027-02-12', 'slot' => '9 ص – 12 م'])->assertOk();
    }
}

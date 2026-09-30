<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DashboardMetrics;
use App\Support\Demo;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * رسومُ التاجر تقرأ فترتَها الحقيقيّة — وتتحدّث مع البطاقات فوقها.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * نبضةُ اللوحة (`admin.dashboard.stats`) كانت تحمل البطاقات وحدها، والرسمان
 * خصائصُ الصفحة لحظةَ فتحها. فبيعةٌ بعد الفتح ترفع «مبيعات اليوم» ولا تمسّ
 * عمودَ الشهر ولا توزيعَ الدفع تحتها — شاشةٌ واحدة بأرقامٍ من لحظتين.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * - «مبيعات هذه السنة حسب الشهر»: يناير … ديسمبر من هذه السنة، ما مضى بلا
 *   بيعٍ صفر وما لم يأتِ `null`، ولا ديسمبرُ الماضي في ديسمبر هذه السنة.
 * - «طرق الدفع»: الشهرُ الجاري وحده، في الفرع المختار.
 * - النبضةُ تحمل البطاقات والرسمين معًا، في الفرع المختار، لهذا المتجر وحده.
 * - تقرير المبيعات: كلُّ فترةٍ بدقّتها (ساعة/يوم/شهر)، والنبضةُ تحفظ الفترة
 *   والقناة والفرع والبوتيك.
 *
 * واليومُ ثابت: الثلاثاء ١٥ سبتمبر ٢٠٢٦ الساعة ١٠ صباحًا.
 */
class EveryChartReadsItsRealPeriodAndStaysCurrentTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $muscat;

    private Branch $salalah;

    private int $number = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->shop = Business::create(['name' => 'متجر الرسوم', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'charts@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $at, float $total, array $over = []): Order
    {
        return Order::create($over + [
            'business_id' => $this->shop->id,
            'branch_id' => $this->muscat->id,
            'number' => ++$this->number,
            'customer_name' => 'عميل',
            'subtotal' => $total, 'tax' => 0, 'discount' => 0, 'total' => $total,
            'payment_method' => 'نقدي',
            'status' => 'مكتمل',
            'is_held' => false,
            'channel' => SalesChannel::POS,
            'ordered_at' => Carbon::parse($at),
        ]);
    }

    /* ═══════════ «مبيعات هذه السنة حسب الشهر» ═══════════ */

    public function test_the_yearly_chart_is_this_calendar_year_january_to_december(): void
    {
        $this->sale('2025-12-20 12:00', 100);   // السنة الماضية — لا يتسرّب إلى ديسمبر
        $this->sale('2026-01-10 12:00', 7);
        $this->sale('2026-09-14 12:00', 5);
        $this->sale('2026-09-15 09:00', 3);     // الشهر الجاري ينمو بما بيع حتى الآن
        $this->sale('2026-09-10 12:00', 1000, ['status' => Order::CANCELLED]);
        $this->sale('2026-09-10 12:00', 500, ['is_held' => true]);

        $s = DashboardMetrics::salesYear();

        $this->assertCount(12, $s['data']);
        $this->assertSame(Carbon::create(2026, 1, 1)->translatedFormat('F Y'), $s['full'][0], 'أوّل عمودٍ ليس يناير هذه السنة');
        $this->assertSame(Carbon::create(2026, 12, 1)->translatedFormat('F Y'), $s['full'][11], 'آخر عمودٍ ليس ديسمبر هذه السنة');

        $this->assertSame(7.0, $s['data'][0]);
        $this->assertSame(0.0, $s['data'][1], 'شهرٌ مضى بلا بيعٍ صفرٌ حقيقيّ لا فراغ');
        $this->assertSame(8.0, $s['data'][8], 'الملغى أو المعلّق دخل، أو بيعُ اليوم لم يدخل');
        $this->assertSame(2, $s['counts'][8]);

        foreach ([9, 10, 11] as $future) {
            $this->assertNull($s['data'][$future], 'شهرٌ لم يأتِ رُسم صفرًا');
            $this->assertNull($s['counts'][$future]);
        }
    }

    public function test_the_yearly_chart_reads_the_chosen_branch_and_this_shop_only(): void
    {
        $this->sale('2026-09-14 12:00', 10);
        $this->sale('2026-09-14 12:00', 90, ['branch_id' => $this->salalah->id]);

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->sale('2026-09-14 12:00', 999, ['business_id' => $other->id, 'branch_id' => null]);

        $this->assertSame(100.0, DashboardMetrics::salesYear()['data'][8], 'بيعُ متجرٍ آخر دخل رسمَ هذا');

        session(['current_branch' => $this->muscat->id]);
        $this->assertSame(10.0, DashboardMetrics::salesYear()['data'][8], 'الفرعُ المختار لم يُحصَر');
    }

    /* ═══════════ «مبيعات الموظف هذه السنة» ═══════════ */

    public function test_the_employee_chart_is_this_year_with_the_future_left_empty(): void
    {
        $noura = User::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'email' => 'noura-charts@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->sale('2025-12-20 12:00', 100, ['user_id' => $noura->id]);
        $this->sale('2026-02-10 12:00', 6, ['user_id' => $noura->id]);
        $this->sale('2026-09-10 12:00', 4, ['user_id' => $noura->id]);
        $this->sale('2026-09-10 12:00', 50, ['user_id' => $this->owner->id]);

        $data = Demo::employeeSalesSeries($noura->id)['data'];

        $this->assertCount(12, $data);
        $this->assertSame([0.0, 6.0], array_slice($data, 0, 2));
        $this->assertSame(4.0, $data[8], 'بيعُ موظّفٍ آخر دخل رسمَها');
        $this->assertSame([null, null, null], array_slice($data, 9), 'شهرٌ لم يأتِ رُسم صفرًا — أو ديسمبرُ الماضي تسرّب');
    }

    /* ═══════════ «طرق الدفع» — الشهر الجاري ═══════════ */

    public function test_the_payment_split_is_this_month_only_and_grows_with_a_new_sale(): void
    {
        $this->sale('2026-08-31 23:00', 700, ['payment_method' => 'بطاقة']);
        $this->sale('2026-09-02 12:00', 20);

        $this->assertSame([Demo::methodLabel('نقدي')], DashboardMetrics::paymentDistribution()['labels'], 'بيعُ الشهر الماضي دخل توزيعَ هذا الشهر');

        $this->sale('2026-09-15 09:30', 30, ['payment_method' => 'بطاقة']);
        $after = DashboardMetrics::paymentDistribution();

        $this->assertSame([Demo::methodLabel('بطاقة'), Demo::methodLabel('نقدي')], $after['labels']);
        $this->assertSame([30.0, 20.0], $after['series']);
    }

    public function test_the_payment_split_follows_the_chosen_branch(): void
    {
        $this->sale('2026-09-10 12:00', 10);
        $this->sale('2026-09-10 12:00', 90, ['branch_id' => $this->salalah->id, 'payment_method' => 'بطاقة']);
        session(['current_branch' => $this->salalah->id]);

        $this->assertSame([90.0], DashboardMetrics::paymentDistribution()['series']);
    }

    /* ═══════════ والنبضةُ تحمل الرسمين مع البطاقات ═══════════ */

    public function test_the_dashboard_pulse_carries_the_charts_with_the_cards(): void
    {
        $this->sale('2026-09-10 12:00', 40);

        $page = $this->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertSame(40.0, $page['salesSeries']['data'][8]);

        // بيعةٌ والصفحةُ مفتوحة
        $this->sale('2026-09-15 09:45', 25, ['payment_method' => 'بطاقة']);

        $pulse = $this->getJson(route('admin.dashboard.stats'))->assertOk()->json();

        $this->assertSame(['stats', 'salesSeries', 'paymentDistribution', 'updated_at'], array_keys($pulse), 'النبضةُ تحمل غيرَ لقطة اللوحة');
        $this->assertNotEmpty($pulse['stats']);
        $this->assertEquals(65, $pulse['salesSeries']['data'][8], 'عمودُ الشهر لم يتحدّث بالبيعة الجديدة');
        $this->assertNull($pulse['salesSeries']['data'][9]);
        $this->assertContains(Demo::methodLabel('بطاقة'), $pulse['paymentDistribution']['labels'], 'توزيعُ الدفع لم يتحدّث');
    }

    /** والصفحةُ ونبضتُها من دالّةٍ واحدة — فلا يفترق ما يُرسم أوّلًا عمّا يُحدَّث به */
    public function test_the_page_and_its_pulse_say_the_same_thing(): void
    {
        $this->sale('2026-03-10 12:00', 12);
        $this->sale('2026-09-10 12:00', 8, ['payment_method' => 'بطاقة']);

        $page = $this->get(route('admin.dashboard'))->viewData('page')['props'];
        $pulse = $this->getJson(route('admin.dashboard.stats'))->json();

        $this->assertEquals($page['salesSeries'], $pulse['salesSeries']);
        $this->assertEquals($page['paymentDistribution'], $pulse['paymentDistribution']);
    }

    public function test_the_pulse_keeps_the_chosen_branch(): void
    {
        $this->sale('2026-09-10 12:00', 10);
        $this->sale('2026-09-10 12:00', 90, ['branch_id' => $this->salalah->id, 'payment_method' => 'بطاقة']);

        $pulse = $this->withSession(['current_branch' => $this->muscat->id])
            ->getJson(route('admin.dashboard.stats'))->json();

        $this->assertEquals(10, $pulse['salesSeries']['data'][8], 'النبضةُ رسمت المتجرَ كلَّه والفرعُ مختار');
        $this->assertSame([Demo::methodLabel('نقدي')], $pulse['paymentDistribution']['labels']);
    }

    public function test_the_pulse_never_reads_another_shop(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->sale('2026-09-10 12:00', 999, ['business_id' => $other->id, 'branch_id' => null, 'payment_method' => 'بطاقة']);
        $this->sale('2026-09-10 12:00', 5);

        $pulse = $this->getJson(route('admin.dashboard.stats'))->json();

        $this->assertEquals(5, $pulse['salesSeries']['data'][8]);
        $this->assertSame([5.0], array_map('floatval', $pulse['paymentDistribution']['series']));
    }

    /* ═══════════ والأبوابُ لأصحابها ═══════════ */

    public function test_the_pulses_open_only_to_their_own_side(): void
    {
        // التاجرُ لا يقرأ نبضةَ المنصّة ولا تقاريرَها — فيها فواتيرُ المتاجر كلِّها
        $this->getJson(route('super-admin.dashboard.stats'))->assertForbidden();
        $this->getJson(route('super-admin.reports.feed'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.dashboard.stats'))->assertRedirect(route('login'));
        $this->get(route('super-admin.dashboard.stats'))->assertRedirect(route('login'));
        $this->get(route('super-admin.reports.feed'))->assertRedirect(route('login'));
    }

    /* ═══════════ تقرير المبيعات: كلُّ فترةٍ بدقّتها ═══════════ */

    /** @return array<string, array{string, string, int, int, list<int>}> */
    public static function periods(): array
    {
        // [الفترة، الوحدة، عدد الأعمدة، موضع البيعة، مواضع ما لم يأتِ]
        return [
            'اليوم بالساعة' => ['today', 'hour', 24, 9, range(11, 23)],
            'الأسبوع باليوم' => ['week', 'day', 7, 1, [3, 4, 5, 6]],
            'الشهر باليوم' => ['month', 'day', 30, 13, range(15, 29)],
            'السنة بالشهر' => ['year', 'month', 12, 8, [9, 10, 11]],
            'الكلّ: آخر اثني عشر شهرًا' => ['all', 'month', 12, 11, []],
        ];
    }

    #[DataProvider('periods')]
    public function test_each_report_period_draws_its_own_granularity(string $range, string $unit, int $buckets, int $at, array $future): void
    {
        // بيعةٌ اليوم ٩ صباحًا للفترة القصيرة، وأمس للأسبوع والشهر والسنة
        $this->sale($range === 'today' ? '2026-09-15 09:00' : '2026-09-14 12:00', 11);

        $s = $this->getJson(route('admin.reports.feed', ['range' => $range]))->assertOk()->json('salesSeries');

        $this->assertSame([$range, $unit], [$s['range'], $s['unit']], "«{$range}» لم يُرسم بدقّته");
        $this->assertCount($buckets, $s['data']);
        $this->assertEquals(11, $s['data'][$at], "البيعةُ ليست في عمودها في «{$range}»");

        foreach ($future as $i) {
            $this->assertNull($s['data'][$i], "عمودٌ لم يأتِ في «{$range}» رُسم رقمًا");
        }
    }

    /** و«الكلّ» آخرُ اثني عشر شهرًا: أكتوبر الماضي أوّلُه، وسبتمبر الماضي خارجه */
    public function test_all_is_the_trailing_twelve_months(): void
    {
        $this->sale('2025-09-20 12:00', 900);
        $this->sale('2025-10-05 12:00', 4);

        $s = $this->getJson(route('admin.reports.feed', ['range' => 'all']))->json('salesSeries');

        $this->assertEquals(4, $s['data'][0]);
        $this->assertEquals(4, array_sum(array_filter($s['data'], fn ($v) => $v !== null)), 'شهرٌ قبل النافذة دخلها');
    }

    /** والنبضةُ تحفظ الفترةَ والقناةَ والفرعَ والبوتيك — لا تقلب التقريرَ إلى المتجر كلِّه */
    public function test_the_report_pulse_keeps_period_channel_branch_and_boutique(): void
    {
        $lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 10, 'active' => true]);

        $mixed = $this->sale('2026-09-14 12:00', 70);
        OrderItem::create(['order_id' => $mixed->id, 'name' => 'عطر لمى', 'price' => 50, 'quantity' => 1, 'total' => 50, 'boutique_id' => $lama->id, 'boutique_rate' => 10]);
        OrderItem::create(['order_id' => $mixed->id, 'name' => 'باقة', 'price' => 20, 'quantity' => 1, 'total' => 20]);

        $this->sale('2026-09-14 12:00', 300, ['channel' => SalesChannel::WEBSITE]);
        $this->sale('2026-09-14 12:00', 80, ['branch_id' => $this->salalah->id]);

        $feed = $this->withSession(['current_branch' => $this->muscat->id])
            ->getJson(route('admin.reports.feed', ['range' => 'year', 'channel' => SalesChannel::POS]))->json();

        $this->assertSame(['year', 'month'], [$feed['salesSeries']['range'], $feed['salesSeries']['unit']]);
        $this->assertEquals(70, $feed['salesSeries']['data'][8], 'القناةُ أو الفرعُ سقط من النبضة');

        $scoped = $this->withSession(['current_branch' => $this->muscat->id])
            ->getJson(route('admin.reports.feed', ['range' => 'year', 'channel' => SalesChannel::POS, 'boutique' => $lama->id]))->json();

        $this->assertSame($lama->id, $scoped['boutique']['id']);
        $this->assertEquals(50, $scoped['salesSeries']['data'][8], 'البوتيكُ سقط من النبضة');
    }
}

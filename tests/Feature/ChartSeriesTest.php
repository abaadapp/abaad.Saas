<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * كل سلسلة شهرية تعطي سنة التقويم كاملة: يناير … ديسمبر.
 *
 * كانت نافذةً متدحرجة تبدأ من شهر اليوم (سبتمبر · أكتوبر … أغسطس) — صحيحة
 * حسابيًّا، لكن العين تقرأ محور الأشهر بترتيبه المعروف فيظنّ الناظر العمود
 * الأول يناير.
 *
 * والبناء بـsubMonths كان يفيض في أيام ٢٩–٣١: ٣٠ يوليو ناقص ٥ أشهر يقصد
 * ٣٠ فبراير — وهو غير موجود — فتنتقل Carbon إلى ٢ مارس، فيظهر شهر مرّتين
 * ويختفي شهر حقيقي ببياناته. هذه الاختبارات تثبّت الأمرين معًا.
 */
class ChartSeriesTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = [
        'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
        'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
    ];

    /** أيام كان الطرح يفيض فيها، ويوم آمن للمقارنة */
    public static function riskyDates(): array
    {
        return [
            '٣٠ يوليو → فبراير غير موجود' => ['2026-07-30'],
            '٣١ مايو → لا ٣١ في أبريل ولا فبراير' => ['2026-05-31'],
            '٢٩ مارس → لا ٢٩ فبراير في سنة عادية' => ['2027-03-29'],
            '٣١ ديسمبر → آخر يوم في السنة' => ['2026-12-31'],
            '١ يناير → أول يوم في السنة' => ['2026-01-01'],
            '١٥ يونيو (يوم آمن)' => ['2026-06-15'],
        ];
    }

    #[DataProvider('riskyDates')]
    public function test_every_series_runs_january_to_december(string $today): void
    {
        Carbon::setTestNow(Carbon::parse($today));
        app()->setLocale('ar');

        foreach ([
            'businessesGrowthSeries' => fn () => Demo::businessesGrowthSeries(),
            'revenueSeries' => fn () => Demo::revenueSeries(),
            'businessSalesSeries' => fn () => Demo::businessSalesSeries(1),
        ] as $name => $call) {
            $series = $call();

            $this->assertSame(self::YEAR, $series['labels'], "{$name} في {$today}");
            // القيم بعدد التسميات: عمودٌ بلا رقمه يزيح المنحنى كلّه
            $this->assertCount(12, $series['data'], "{$name} في {$today}");
        }

        Carbon::setTestNow();
    }

    public function test_the_year_does_not_depend_on_todays_month(): void
    {
        /*
         * الفحص الحقيقي للنافذة المتدحرجة: في نافذةٍ متدحرجة يتبدّل أول عمود
         * كل شهر. هنا يبقى يناير أوّلًا في أي يوم من السنة.
         */
        foreach (['2026-01-01', '2026-06-15', '2026-12-31'] as $day) {
            Carbon::setTestNow(Carbon::parse($day));
            app()->setLocale('ar');

            $labels = Demo::revenueSeries()['labels'];
            $this->assertSame('يناير', $labels[0], "أول عمود تبدّل في {$day}");
            $this->assertSame('ديسمبر', end($labels), "آخر عمود تبدّل في {$day}");
        }

        Carbon::setTestNow();
    }

    public function test_the_months_belong_to_the_current_year_not_the_previous_one(): void
    {
        /*
         * التسمية وحدها لا تكفي: «ديسمبر» قد تُقرأ من ديسمبر السنة الماضية
         * فتُحسب مبيعاتها في رسم هذه السنة. نُثبت الحدّ ببيعٍ في كل جانب.
         */
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $business = \App\Models\Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);

        foreach ([['2025-12-20', 100], ['2026-01-20', 7]] as [$date, $total]) {
            \App\Models\Order::create([
                'business_id' => $business->id, 'number' => 'INV-' . $total,
                'total' => $total, 'status' => 'مكتمل', 'is_held' => false,
                'ordered_at' => Carbon::parse($date),
            ]);
        }

        $series = Demo::businessSalesSeries($business->id);

        $this->assertSame(7.0, $series['data'][0], 'يناير هذه السنة يجب أن يقرأ بيع يناير');
        $this->assertSame(0.0, $series['data'][11], 'ديسمبر الماضي تسرّب إلى ديسمبر هذه السنة');

        Carbon::setTestNow();
    }

    /* ═══════════ إيراداتُ المنصّة ونموُّها: بياناتٌ لا تسمياتٌ فقط ═══════════ */

    private function shop(string $name, string $starts, bool $demo = false): Business
    {
        return Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'starts_at' => $starts, 'is_demo' => $demo]);
    }

    private function invoice(Business $b, string $issued, float $amount, string $status = Billing::PAID): Invoice
    {
        static $n = 0;

        return Invoice::create(['number' => 'INV-C'.(++$n), 'business_id' => $b->id, 'amount' => $amount, 'issued_at' => $issued, 'status' => $status]);
    }

    private function platformAdmin(): User
    {
        return User::create(['name' => 'المنصّة', 'email' => 'platform-charts@abaadapp.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
    }

    /**
     * «الإيرادات» ما دُفع — كبطاقتَي الإيرادات فوقه وفي التقارير.
     *
     * كانت تجمع الفواتير كلَّها بتاريخ إصدارها، فيقرأ الرسمُ «إيرادًا» فيه ما
     * لم يُسدَّد، والبطاقةُ فوقه تقرأ المدفوعَ وحده.
     */
    public function test_platform_revenue_reads_paid_invoices_in_their_month_of_this_year(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $b = $this->shop('متجر', '2025-06-01');

        $this->invoice($b, '2025-12-20', 900);                        // السنة الماضية
        $this->invoice($b, '2026-01-05', 10);
        $this->invoice($b, '2026-09-01', 20);
        $this->invoice($b, '2026-09-30', 5);                          // آخر الشهر الجاري من شهره
        $this->invoice($b, '2026-09-02', 700, Billing::UNPAID);       // لم يُسدَّد — ليس إيرادًا

        $data = Demo::revenueSeries()['data'];

        $this->assertCount(12, $data);
        $this->assertSame(10.0, $data[0]);
        $this->assertSame(0.0, $data[1], 'شهرٌ مضى بلا إيرادٍ صفرٌ لا فراغ');
        $this->assertSame(25.0, $data[8], 'فاتورةٌ غيرُ مدفوعة دخلت «الإيرادات»، أو مدفوعةٌ غابت');
        $this->assertSame([null, null, null], array_slice($data, 9), 'شهرٌ لم يأتِ رُسم صفرًا — أو ديسمبرُ الماضي تسرّب');

        Carbon::setTestNow();
    }

    /** ونموُّ الشركات: الحقيقيّةُ وحدها في شهر بدئها من هذه السنة */
    public function test_platform_growth_counts_real_businesses_in_their_month_of_this_year(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->shop('قديم', '2025-12-10');
        $this->shop('يناير', '2026-01-03');
        $this->shop('سبتمبر ١', '2026-09-01');
        $this->shop('سبتمبر ٢', '2026-09-14');
        $this->shop('تجريبيّ', '2026-09-10', demo: true);

        $data = Demo::businessesGrowthSeries()['data'];

        $this->assertCount(12, $data);
        $this->assertSame(1, $data[0]);
        $this->assertSame(0, $data[1]);
        $this->assertSame(2, $data[8], 'متجرٌ تجريبيّ عُدّ، أو حقيقيٌّ غاب');
        $this->assertSame([null, null, null], array_slice($data, 9), 'شهرٌ لم يأتِ رُسم صفرًا — أو ديسمبرُ الماضي تسرّب');

        Carbon::setTestNow();
    }

    /** والرسمان في نبضة اللوحة — فاتورةٌ تُسدَّد وشركةٌ تُسجَّل والصفحةُ مفتوحة تظهران */
    public function test_the_platform_pulse_carries_both_charts_with_the_cards(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $b = $this->shop('متجر', '2026-02-01');
        $this->actingAs($this->platformAdmin());

        $page = $this->get(route('super-admin.dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertSame(0.0, $page['revenueSeries']['data'][8]);

        $this->invoice($b, '2026-09-15', 42);
        $this->shop('جديد', '2026-09-15');

        $pulse = $this->getJson(route('super-admin.dashboard.stats'))->assertOk()->json();

        $this->assertSame(['stats', 'revenueSeries', 'growthSeries', 'updated_at'], array_keys($pulse));
        $this->assertNotEmpty($pulse['stats']);
        $this->assertEquals(42, $pulse['revenueSeries']['data'][8], '«الإيرادات الشهرية» لم تتحدّث');
        $this->assertSame(1, $pulse['growthSeries']['data'][8], '«نمو الشركات» لم يتحدّث');
        $this->assertNull($pulse['growthSeries']['data'][9]);

        Carbon::setTestNow();
    }

    /** والتقاريرُ تقرأ الإيراداتِ بتعريف اللوحة نفسِه — وتتحدّث وهي مفتوحة */
    public function test_the_reports_read_the_same_revenue_as_the_dashboard_and_stay_current(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $b = $this->shop('متجر', '2026-02-01');
        $this->invoice($b, '2026-03-03', 15);
        $this->invoice($b, '2026-03-04', 99, Billing::UNPAID);
        $this->actingAs($this->platformAdmin());

        $page = $this->get(route('super-admin.reports.index'))->assertOk()->viewData('page')['props'];
        $this->invoice($b, '2026-09-10', 30);

        $feed = $this->getJson(route('super-admin.reports.feed'))->assertOk()->json();
        $pulse = $this->getJson(route('super-admin.dashboard.stats'))->json();

        $report = ['cards', 'revenueSeries', 'planDistribution', 'planSummary', 'currency'];
        $this->assertSame([...$report, 'updated_at'], array_keys($feed), 'النبضةُ تحمل غيرَ حمولة الصفحة');
        foreach ($report as $key) {
            $this->assertArrayHasKey($key, $page, "الصفحةُ بلا {$key} والنبضةُ تحمله");
        }
        $this->assertEquals($pulse['revenueSeries'], $feed['revenueSeries'], 'تعريفان للإيرادات الشهرية');
        $this->assertEquals(15, $feed['revenueSeries']['data'][2]);
        $this->assertEquals(30, $feed['revenueSeries']['data'][8], 'التقرير لم يتحدّث');
        $this->assertSame(Demo::money(45), $feed['cards'][0]['value'], 'البطاقةُ فوق الرسم لم تتحدّث معه');
        // والتوزيعُ فئويّ لا شهريّ: اسمُ الباقة لا اسمُ الشهر
        $this->assertSame(['labels', 'series'], array_keys($feed['planDistribution']));

        Carbon::setTestNow();
    }

    /** وملفُّ PDF لا يطبع الأشهر التي لم تأتِ أصفارًا، ولا يسمّي السنةَ «آخر ستّة أشهر» */
    public function test_the_platform_pdf_prints_only_the_months_that_happened(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        app()->setLocale('ar');
        $b = $this->shop('متجر', '2026-02-01');
        $this->invoice($b, '2026-09-10', 30);

        $html = view('pdf.platform-report', [
            'stats' => [],
            'revenueSeries' => Demo::revenueSeries(),
            'growthSeries' => Demo::businessesGrowthSeries(),
            'planDistribution' => ['labels' => [], 'series' => []],
            'topBusinesses' => [],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString('سبتمبر', $html);
        $this->assertStringNotContainsString('أكتوبر', $html, 'شهرٌ لم يأتِ طُبع');
        $this->assertStringNotContainsString('آخر 6 أشهر', $html);

        Carbon::setTestNow();
    }

    /**
     * «توزيع الباقات» يعدّ الشركات الحقيقيّة وحدها — كبطاقة «الشركات المسجّلة» بجانبه.
     *
     * ويبقى حالةً حاليّة فئويّة: اسمُ الباقة لا اسمُ الشهر.
     */
    public function test_plan_distribution_counts_real_businesses_only_and_the_feed_says_the_same(): void
    {
        $gold = \App\Models\Plan::create(['name' => 'ذهبيّة', 'monthly_price' => 10, 'yearly_price' => 100]);
        $basic = \App\Models\Plan::create(['name' => 'أساسيّة', 'monthly_price' => 5, 'yearly_price' => 50]);

        foreach ([['أ', $gold], ['ب', $gold], ['ج', $basic]] as [$name, $plan]) {
            $this->shop($name, '2026-01-01')->forceFill(['plan_id' => $plan->id])->save();
        }
        foreach ([['تجريبيّ ١', $gold], ['تجريبيّ ٢', $gold], ['تجريبيّ ٣', $basic]] as [$name, $plan]) {
            $this->shop($name, '2026-01-01', demo: true)->forceFill(['plan_id' => $plan->id])->save();
        }
        $this->shop('تجريبيّ بلا باقة', '2026-01-01', demo: true);

        $counts = fn (array $d) => array_combine($d['labels'], $d['series']);

        $this->assertSame(['ذهبيّة' => 2, 'أساسيّة' => 1], $counts(Demo::planDistribution()), 'متجرٌ تجريبيّ عُدّ في باقته');

        $this->actingAs($this->platformAdmin());
        $page = $this->get(route('super-admin.reports.index'))->assertOk()->viewData('page')['props'];
        $feed = $this->getJson(route('super-admin.reports.feed'))->assertOk()->json();

        $this->assertSame(['ذهبيّة' => 2, 'أساسيّة' => 1], $counts($feed['planDistribution']), 'النبضةُ تعدّ غيرَ ما تعدّه الصفحة');
        $this->assertEquals($page['planDistribution'], $feed['planDistribution']);
    }
}

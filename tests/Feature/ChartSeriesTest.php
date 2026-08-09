<?php

namespace Tests\Feature;

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
}

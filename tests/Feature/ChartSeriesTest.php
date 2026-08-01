<?php

namespace Tests\Feature;

use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * سلاسل المخططات الشهرية يجب أن تعطي شهورًا متتالية بلا تكرار ولا سقوط.
 *
 * كانت تبني الشهر بـnow()->subMonths($i)، وهي تفيض في أيام 29–31:
 * ٣٠ يوليو ناقص ٥ أشهر يقصد ٣٠ فبراير — وهو غير موجود — فتنتقل Carbon
 * إلى ٢ مارس. النتيجة شهر يظهر مرّتين وشهر حقيقي يختفي ببياناته، ومعه
 * تحذير React عن مفتاح مكرّر.
 */
class ChartSeriesTest extends TestCase
{
    use RefreshDatabase;

    /** أيام يفيض فيها الطرح: لا شهر لها في فبراير، وبعضها لا في الأشهر ذوات ٣٠ يومًا */
    public static function riskyDates(): array
    {
        return [
            '٣٠ يوليو → فبراير غير موجود' => ['2026-07-30'],
            '٣١ مايو → لا ٣١ في أبريل ولا فبراير' => ['2026-05-31'],
            '٢٩ مارس → لا ٢٩ فبراير في سنة عادية' => ['2027-03-29'],
            '٣١ ديسمبر → عبور رأس السنة' => ['2026-12-31'],
            '١٥ يونيو (يوم آمن)' => ['2026-06-15'],
        ];
    }

    #[DataProvider('riskyDates')]
    public function test_month_labels_are_consecutive_and_unique(string $today): void
    {
        Carbon::setTestNow(Carbon::parse($today));

        foreach ([
            'businessesGrowthSeries' => 6,
            'revenueSeries' => 6,
        ] as $method => $expected) {
            $labels = Demo::$method()['labels'];

            $this->assertCount($expected, $labels, $method);
            $this->assertSame(
                $expected,
                count(array_unique($labels)),
                "{$method} كرّر شهرًا في {$today}: " . implode('، ', $labels)
            );
        }

        // سلسلة الشركة اثنا عشر شهرًا
        $labels = Demo::businessSalesSeries(1)['labels'];
        $this->assertCount(12, $labels);
        $this->assertSame(12, count(array_unique($labels)), 'سلسلة الشركة كرّرت شهرًا في ' . $today);

        Carbon::setTestNow();
    }

    public function test_the_last_label_is_the_current_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30'));

        $labels = Demo::businessesGrowthSeries()['labels'];
        $this->assertSame('يوليو', end($labels));
        $this->assertSame('فبراير', $labels[0], 'أول شهر في نافذة الستة يجب أن يكون فبراير لا مارس');

        Carbon::setTestNow();
    }
}

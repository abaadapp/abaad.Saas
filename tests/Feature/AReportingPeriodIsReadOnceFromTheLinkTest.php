<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Support\Demo;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * الفترةُ تُقرأ من الرابط في مكانٍ واحد — `ReportingPeriod`.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الحدّان نصفٌ مفتوح [البداية، النهاية): سبتمبر ٢٠٢٥ يبدأ في أوّله
 *     ويقف قبل أوّل أكتوبر، وفبراير الكبيسة تسعةٌ وعشرون يومًا.
 *   - الأزرارُ السريعة كما كانت حرفًا بحرف: البدايةُ نفسُها بلا نهاية،
 *     والاسمُ نفسُه — فلا يتغيّر رقمٌ ولا رأسُ ملفٍّ في رابطٍ قديم.
 *   - الفترةُ السابقة بشكلها: شهرٌ بالشهر قبله، وثلاثةُ أشهرٍ بالثلاثة قبلها،
 *     وسنةٌ بالسنة قبلها، وعشرةُ أيّامٍ بالعشرة قبلها.
 *   - الخطأُ يُقال (٤٢٢) — لا تبديلَ صامتًا للحدّين ولا سقوطَ إلى الشهر الجاري.
 *   - الأسبقيّة: `period` ثمّ من/إلى ثمّ `month` ثمّ `range` — والمفاتيحُ
 *     القديمةُ التي تبقى في الرابط لا تتغلّب على المختار.
 */
class AReportingPeriodIsReadOnceFromTheLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2026-10-10 14:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function read(array $query, string|ReportingPeriod $default = 'month', array $legacy = []): ReportingPeriod
    {
        return ReportingPeriod::fromQuery($query, $default, $legacy);
    }

    /** @return array{0: string, 1: ?string} */
    private function bounds(ReportingPeriod $p): array
    {
        return [$p->start?->toDateTimeString(), $p->end?->toDateTimeString()];
    }

    private function refused(array $query, array $legacy = []): string
    {
        try {
            $this->read($query, 'month', $legacy);
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());

            return $e->getMessage();
        }

        $this->fail('قُبلت فترةٌ لا تصحّ: '.json_encode($query, JSON_UNESCAPED_UNICODE));
    }

    /* ═══════════ الأزرارُ السريعة — كما كانت ═══════════ */

    public function test_every_quick_range_reads_exactly_as_before(): void
    {
        foreach (Demo::RANGES as $range) {
            $p = $this->read(['range' => $range]);

            $this->assertTrue($p->preset);
            $this->assertSame($range, $p->range());
            $this->assertEquals(Demo::rangeStart($range), $p->start, "بدايةُ {$range} تغيّرت");
            $this->assertNull($p->end, "صار لـ{$range} نهاية — والرابطُ القديم لا نهاية له");
            $this->assertSame(Demo::rangeLabel($range), $p->label());
            $this->assertSame([$range], $p->fileParts());
            $this->assertEquals(Demo::rangePrev($range), $p->previous());
        }
    }

    public function test_the_current_month_is_the_default_and_an_unknown_range_falls_to_it(): void
    {
        $this->assertSame('month', $this->read([])->range());
        // والمجهولةُ تسقط كما كانت (`Demo::range`) — زرٌّ لا تاريخَ فيه
        $this->assertSame('month', $this->read(['range' => 'day'])->range());
        $this->assertSame('all', $this->read([], 'all')->range());
    }

    /* ═══════════ الأنواعُ الجديدة ═══════════ */

    public function test_a_specific_month_is_its_first_day_until_the_next_month(): void
    {
        $p = $this->read(['period' => 'month', 'month' => '2025-09']);

        $this->assertSame(['2025-09-01 00:00:00', '2025-10-01 00:00:00'], $this->bounds($p));
        $this->assertSame('سبتمبر 2025', $p->label());
        $this->assertSame(['2025-09'], $p->fileParts());
        $this->assertSame(['period' => 'month', 'month' => '2025-09'], $p->params());
        $this->assertSame(['2025-09-01', '2025-09-30'], [$p->fromDate(), $p->toDate()]);
        $this->assertNull($p->range(), 'شهرٌ بعينه يُضيء زرَّ «الشهر»');
        $this->assertSame(['2025-08-01 00:00:00', '2025-09-01 00:00:00'], array_map(fn ($c) => $c->toDateTimeString(), $p->previous()));
    }

    public function test_a_leap_february_has_twenty_nine_days(): void
    {
        $p = $this->read(['period' => 'month', 'month' => '2024-02']);

        $this->assertSame(['2024-02-01 00:00:00', '2024-03-01 00:00:00'], $this->bounds($p));
        $this->assertSame('2024-02-29', $p->toDate());
        $this->assertSame('2024-01-01', $p->previous()[0]->toDateString());
    }

    public function test_the_previous_month_crosses_the_year(): void
    {
        Carbon::setTestNow('2026-01-15 09:00:00');

        $p = $this->read(['period' => 'previous_month']);

        $this->assertSame(['2025-12-01 00:00:00', '2026-01-01 00:00:00'], $this->bounds($p));
        $this->assertSame('ديسمبر 2025', $p->label());
        $this->assertSame(['2025-12'], $p->fileParts());
        $this->assertSame('2025-11-01', $p->previous()[0]->toDateString());
    }

    public function test_a_month_range_includes_both_months_and_compares_with_as_many_before(): void
    {
        $p = $this->read(['period' => 'month_range', 'month_from' => '2025-03', 'month_to' => '2025-08']);

        $this->assertSame(['2025-03-01 00:00:00', '2025-09-01 00:00:00'], $this->bounds($p));
        $this->assertSame('مارس 2025 – أغسطس 2025', $p->label());
        $this->assertSame(['2025-03', 'to', '2025-08'], $p->fileParts());
        // ستّةُ أشهرٍ بالستّة قبلها
        $this->assertSame(['2024-09-01', '2025-03-01'], array_map(fn ($c) => $c->toDateString(), $p->previous()));
    }

    public function test_a_month_range_may_cross_the_year(): void
    {
        $p = $this->read(['period' => 'month_range', 'month_from' => '2024-11', 'month_to' => '2025-02']);

        $this->assertSame(['2024-11-01 00:00:00', '2025-03-01 00:00:00'], $this->bounds($p));
        $this->assertSame('نوفمبر 2024 – فبراير 2025', $p->label());
        $this->assertSame(['2024-11', 'to', '2025-02'], $p->fileParts());
        $this->assertSame(['2024-07-01', '2024-11-01'], array_map(fn ($c) => $c->toDateString(), $p->previous()));
    }

    public function test_a_historical_year_is_all_of_it_and_compares_with_the_year_before(): void
    {
        $p = $this->read(['period' => 'year', 'year' => '2024']);

        $this->assertSame(['2024-01-01 00:00:00', '2025-01-01 00:00:00'], $this->bounds($p));
        $this->assertSame('2024', $p->label());
        $this->assertSame(['2024'], $p->fileParts());
        $this->assertSame(['2023-01-01', '2024-01-01'], array_map(fn ($c) => $c->toDateString(), $p->previous()));
    }

    public function test_custom_dates_include_both_days_and_compare_with_as_many_days_before(): void
    {
        $p = $this->read(['period' => 'custom', 'from' => '2025-12-28', 'to' => '2026-01-03']);

        $this->assertSame(['2025-12-28 00:00:00', '2026-01-04 00:00:00'], $this->bounds($p));
        $this->assertSame(['2025-12-28', 'to', '2026-01-03'], $p->fileParts());
        $this->assertSame('28 ديسمبر 2025 – 3 يناير 2026', $p->label());
        // سبعةُ أيّامٍ بالسبعة قبلها
        $this->assertSame(['2025-12-21', '2025-12-28'], array_map(fn ($c) => $c->toDateString(), $p->previous()));
    }

    public function test_one_custom_day_is_that_day(): void
    {
        $p = $this->read(['period' => 'custom', 'from' => '2025-09-10', 'to' => '2025-09-10']);

        $this->assertSame(['2025-09-10 00:00:00', '2025-09-11 00:00:00'], $this->bounds($p));
        $this->assertSame('10 سبتمبر 2025', $p->label());
        $this->assertSame('2025-09-09', $p->previous()[0]->toDateString());
    }

    public function test_all_periods_has_no_bound_and_no_comparison(): void
    {
        $p = $this->read(['period' => 'all']);

        $this->assertSame([null, null], $this->bounds($p));
        $this->assertNull($p->previous());
        $this->assertSame(['all'], $p->fileParts());
        $this->assertSame('كل الفترات', $p->label());
    }

    /* ═══════════ الخطأُ يُقال ═══════════ */

    public function test_a_wrong_period_is_refused_not_turned_into_the_current_month(): void
    {
        $this->assertStringContainsString('بعد', $this->refused(['period' => 'custom', 'from' => '2025-10-17', 'to' => '2025-09-10']));
        $this->assertStringContainsString('بعد', $this->refused(['period' => 'month_range', 'month_from' => '2025-08', 'month_to' => '2025-03']));
        foreach (['2025-13', '2025-9', 'abcd-ef', '2025-00', '25-09'] as $month) {
            $this->refused(['period' => 'month', 'month' => $month]);
        }
        $this->refused(['period' => 'custom', 'from' => '2025-02-30', 'to' => '2025-03-01']);
        $this->refused(['period' => 'custom', 'from' => '2025-09-10']);
        $this->refused(['period' => 'year', 'year' => '24']);
        $this->refused(['period' => 'month']);
        $this->refused(['period' => 'quarter']);
        // والروابطُ القديمة كذلك: «من» بعد «إلى» لا تُبدَّل صامتة
        $this->refused(['from' => '2025-10-01', 'to' => '2025-09-01'], ['dates']);
        $this->refused(['month' => '2025-13'], ['month']);
    }

    /* ═══════════ الأسبقيّة والمفاتيح القديمة ═══════════ */

    public function test_the_chosen_period_wins_over_stale_keys(): void
    {
        $p = $this->read(
            ['period' => 'month', 'month' => '2025-09', 'from' => '2024-01-01', 'to' => '2024-12-31', 'range' => 'year', 'year' => '2023'],
            'month',
            ['dates', 'month'],
        );

        $this->assertSame(['2025-09-01 00:00:00', '2025-10-01 00:00:00'], $this->bounds($p));
        // والرابطُ منقّى: ما يخصّ الشهرَ وحده
        $this->assertSame(['period' => 'month', 'month' => '2025-09'], $p->params());
    }

    public function test_old_links_keep_their_meaning(): void
    {
        // من/إلى قبل `range` — كما كانت شاشةُ الحركة تقرؤهما
        $dates = $this->read(['from' => '2026-03-01', 'to' => '2026-03-31', 'range' => 'year'], ReportingPeriod::all(), ['dates']);
        $this->assertSame(['2026-03-01 00:00:00', '2026-04-01 00:00:00'], $this->bounds($dates));
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-31'], $dates->params(), 'صار الرابطُ القديمُ رابطًا جديدًا');
        $this->assertSame(['2026-03-01', 'to', '2026-03-31'], $dates->fileParts());

        // و«من» وحدها تبقى مفتوحة — والطلباتُ كانت تقبلها
        $open = $this->read(['from' => '2026-09-01'], ReportingPeriod::all(), ['dates']);
        $this->assertSame(['2026-09-01 00:00:00', null], $this->bounds($open));
        $this->assertSame(['from' => '2026-09-01'], $open->params());

        // وشهرُ المصروفات وكلُّ شهورها
        $this->assertSame('2026-09-01', $this->read(['month' => '2026-09'], 'month', ['month'])->fromDate());
        $this->assertSame('all', $this->read(['month' => 'all'], 'month', ['month'])->kind);

        // ومن لا يقبلها لا يقرؤها: من/إلى على تقريرٍ بـ`range` تُترك
        $this->assertSame('month', $this->read(['from' => '2020-01-01', 'to' => '2020-01-31'])->range());
    }

    /* ═══════════ على العمود ═══════════ */

    public function test_the_bounds_hold_at_midnight_on_both_ends(): void
    {
        $shop = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        $at = fn (string $when) => Order::create([
            'business_id' => $shop->id, 'number' => 'N-'.str_replace([' ', ':', '-'], '', $when),
            'status' => 'مكتمل', 'total' => 1, 'ordered_at' => $when, 'is_held' => false,
        ]);

        foreach (['2025-08-31 23:59:59', '2025-09-01 00:00:00', '2025-09-30 23:59:59', '2025-10-01 00:00:00'] as $when) {
            $at($when);
        }

        $p = $this->read(['period' => 'month', 'month' => '2025-09']);
        $inside = $p->bound(Order::where('business_id', $shop->id), 'ordered_at')->orderBy('ordered_at')->pluck('ordered_at')
            ->map(fn ($d) => Carbon::parse($d)->toDateTimeString())->all();

        $this->assertSame(['2025-09-01 00:00:00', '2025-09-30 23:59:59'], $inside);
    }

    public function test_the_years_reach_back_to_the_first_record(): void
    {
        $shop = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        Order::create(['business_id' => $shop->id, 'number' => 'OLD-1', 'status' => 'مكتمل', 'total' => 1,
            'ordered_at' => '2022-05-01 10:00:00', 'is_held' => false]);

        $this->assertSame([2026, 2025, 2024, 2023, 2022], ReportingPeriod::years($shop->id));
        // ومتجرٌ بلا شيءٍ يختار سنتَه
        $empty = Business::create(['name' => 'جديد', 'status' => 'نشط']);
        $this->assertSame([2026], ReportingPeriod::years($empty->id));
    }
}

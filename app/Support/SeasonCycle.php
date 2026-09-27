<?php

namespace App\Support;

use App\Models\Season;
use Illuminate\Support\Carbon;

/**
 * دورةُ الموسم — مصدرُ الحساب الواحد الذي تقرأ منه كلُّ شاشة.
 *
 * ═══ المرساةُ والدورة ═══
 *
 * `starts_at` و`ends_at` في الصفّ **مرساةٌ**: أوّلُ مرّةٍ وقع فيها الموسم.
 * ولا تُعاد كتابتُهما حين تدور السنة — فالتقاريرُ القديمة تبقى على تواريخها،
 * والأصنافُ والتذكيراتُ ومفاتيحُ الصندوق والموقع تبقى على صاحبها.
 *
 * والدورةُ الجاريةُ تُحسب من المرساة في كلّ قراءة: أيُّ نافذةٍ تحتوي اليومَ،
 * فإن لم تكن فأقربُ نافذةٍ قادمة. ولذلك لا يكون الموسمُ المتكرّر «منتهيًا»
 * أبدًا — ينتهي هذا العامُ ليبدأ العدُّ للعام القادم.
 *
 * ═══ وما يقع على حدود السنة ═══
 *
 * موسمٌ من ٢٥ ديسمبر إلى ٥ يناير يعبر رأسَ السنة. فالنهايةُ إن سبقت البدايةَ
 * في ترتيب الشهر واليوم فهي في السنة التالية لها — لا في سنتها.
 *
 * ═══ وما يقع على ٢٩ فبراير ═══
 *
 * موسمٌ أُرّخ في سنةٍ كبيسة يُقصّ إلى ٢٨ في السنوات التي لا كبيسَ فيها. وكذا
 * الهجريُّ: يومُ ٣٠ في شهرٍ جاء ٢٩ يومًا يُقصّ إلى آخره ولا ينزلق إلى الشهر
 * الذي بعده — انظر `Hijri::toGregorian`.
 */
final class SeasonCycle
{
    public const GREGORIAN = 'gregorian';

    public const HIJRI = 'hijri';

    public const CALENDARS = [self::GREGORIAN, self::HIJRI];

    /**
     * دورةُ الموسم التي تخصّ هذا اليوم.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, key: string, cycle: string, overridden: bool}
     */
    public static function current(Season $season, ?Carbon $today = null): array
    {
        $day = ($today ?? today())->copy()->startOfDay();

        if (! $season->repeats) {
            return self::anchor($season);
        }

        $windows = self::windows($season, $day);

        foreach ($windows as $w) {
            if ($day->betweenIncluded($w['starts_at'], $w['ends_at'])) {
                return $w;
            }
        }

        foreach ($windows as $w) {
            if ($w['starts_at']->gt($day)) {
                return $w;
            }
        }

        /* ولا يقع: النوافذُ تمتدّ إلى السنة التالية. وإن وقع فالمرساةُ جوابٌ صادق */
        return self::anchor($season);
    }

    /** المرساةُ نفسُها — موسمٌ لمرّةٍ واحدة، أو مَخرجٌ أخير */
    private static function anchor(Season $season): array
    {
        return [
            'starts_at' => $season->starts_at->copy()->startOfDay(),
            'ends_at' => $season->ends_at->copy()->startOfDay(),
            'key' => $season->starts_at->toDateString(),
            'cycle' => (string) $season->starts_at->year,
            'overridden' => false,
        ];
    }

    /**
     * نوافذُ ثلاث: السنةُ الماضيةُ وهذه والقادمة.
     *
     * والماضيةُ تُحسب لأنّ موسمًا يعبر رأسَ السنة قد يكون جاريًا اليومَ وقد
     * بدأ في السنة الفائتة.
     *
     * @return list<array{starts_at: Carbon, ends_at: Carbon, key: string, cycle: string, overridden: bool}>
     */
    private static function windows(Season $season, Carbon $day): array
    {
        $years = self::isHijri($season)
            ? self::hijriYearsAround($day)
            : [$day->year - 1, $day->year, $day->year + 1];

        $out = [];

        foreach ($years as $year) {
            $w = self::forCycle($season, (string) $year);

            if ($w !== null) {
                $out[] = $w;
            }
        }

        usort($out, fn ($a, $b) => $a['starts_at']->timestamp <=> $b['starts_at']->timestamp);

        return $out;
    }

    /** @return list<int> */
    private static function hijriYearsAround(Carbon $day): array
    {
        $h = Hijri::fromGregorian($day)['year'];

        return [$h - 1, $h, $h + 1];
    }

    /**
     * نافذةُ دورةٍ بعينها — بسنتها كما تُكتب في `cycle_overrides`.
     *
     * والتصحيحُ اليدويُّ يعلو على الحساب: «أمّ القرى» تقول يومًا وتقول الدولةُ
     * يومًا آخر، فيكتب التاجرُ ما أُعلن ولا يُجادَل.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, key: string, cycle: string, overridden: bool}|null
     */
    public static function forCycle(Season $season, string $cycle): ?array
    {
        $fix = ($season->cycle_overrides ?? [])[$cycle] ?? null;

        if (is_array($fix) && isset($fix['starts_at'], $fix['ends_at'])) {
            $s = Carbon::parse($fix['starts_at'])->startOfDay();
            $e = Carbon::parse($fix['ends_at'])->startOfDay();

            if ($e->gte($s)) {
                return ['starts_at' => $s, 'ends_at' => $e, 'key' => $s->toDateString(), 'cycle' => $cycle, 'overridden' => true];
            }
        }

        $window = self::isHijri($season)
            ? self::hijriWindow($season, (int) $cycle)
            : self::gregorianWindow($season, (int) $cycle);

        if ($window === null) {
            return null;
        }

        [$s, $e] = $window;

        return ['starts_at' => $s, 'ends_at' => $e, 'key' => $s->toDateString(), 'cycle' => $cycle, 'overridden' => false];
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private static function gregorianWindow(Season $season, int $year): array
    {
        $a = $season->starts_at;
        $b = $season->ends_at;

        $start = self::gregorianDay($year, $a->month, $a->day);

        // النهايةُ إن سبقت البدايةَ في الشهر واليوم فهي في السنة التالية — موسمٌ يعبر رأسَ السنة
        $crosses = [$b->month, $b->day] < [$a->month, $a->day];
        $end = self::gregorianDay($year + ($crosses ? 1 : 0), $b->month, $b->day);

        return [$start, $end];
    }

    /** ويومُ ٢٩ فبراير يُقصّ إلى ٢٨ في السنوات التي لا كبيسَ فيها */
    private static function gregorianDay(int $year, int $month, int $day): Carbon
    {
        $last = (int) Carbon::create($year, $month, 1)->endOfMonth()->day;

        return Carbon::create($year, $month, min($day, $last))->startOfDay();
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private static function hijriWindow(Season $season, int $year): ?array
    {
        if (! Hijri::available()) {
            return null;
        }

        $a = Hijri::fromGregorian($season->starts_at);
        $b = Hijri::fromGregorian($season->ends_at);

        $start = Hijri::toGregorian($year, $a['month'], $a['day']);

        $crosses = [$b['month'], $b['day']] < [$a['month'], $a['day']];
        $end = Hijri::toGregorian($year + ($crosses ? 1 : 0), $b['month'], $b['day']);

        return [$start, $end];
    }

    public static function isHijri(Season $season): bool
    {
        return $season->repeats && $season->calendar === self::HIJRI;
    }

    /**
     * كلُّ دورةٍ مضت أو تجري — الأحدثُ أوّلًا، للتقارير.
     *
     * ولا تُعدّ دورةٌ قبل المرساة: الموسمُ لم يكن قبلها، وتقريرُها فراغ.
     *
     * @return list<array{starts_at: Carbon, ends_at: Carbon, key: string, cycle: string, overridden: bool}>
     */
    public static function past(Season $season, ?Carbon $today = null): array
    {
        $day = ($today ?? today())->copy()->startOfDay();

        if (! $season->repeats) {
            return [self::anchor($season)];
        }

        $first = self::isHijri($season)
            ? Hijri::fromGregorian($season->starts_at)['year']
            : $season->starts_at->year;

        $now = self::isHijri($season) ? Hijri::fromGregorian($day)['year'] : $day->year;

        $out = [];

        for ($y = $first; $y <= $now + 1; $y++) {
            $w = self::forCycle($season, (string) $y);

            if ($w !== null && $w['starts_at']->lte($day)) {
                $out[] = $w;
            }
        }

        return array_reverse($out);
    }
}

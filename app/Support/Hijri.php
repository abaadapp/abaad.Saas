<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use IntlCalendar;

/**
 * التقويم الهجريّ — من تقويم أمّ القرى، لا من حسابٍ باليد.
 *
 * ═══ ولمَ لا يُضاف ٣٥٤ يومًا ═══
 *
 * السنةُ الهجريّة ٣٥٤ يومًا أو ٣٥٥، والشهرُ ٢٩ أو ٣٠ — والترتيبُ ليس دوريًّا
 * يُحفظ. فمن أضاف عددًا ثابتًا انزلق يومًا أو يومين في كلّ سنة، وبعد خمس
 * سنواتٍ يبدأ «رمضان» في متجره قبل رمضان أو بعده بأسبوع. وهذا ليس خطأً
 * يُرى في شاشة: هو موسمٌ يفتح في اليوم الخطأ ويُغلق في اليوم الخطأ.
 *
 * فيُسأل ICU — تقويم `islamic-umalqura`، وهو المعتمَد في الجزيرة ومنه تُطبع
 * الرزنامات. و«أمّ القرى» تقويمٌ حسابيٌّ مضبوطٌ سلفًا، لا رؤيةُ هلال؛ فقد
 * يفترق يومًا عن الإعلان الرسميّ. ولذلك يبقى للتاجر أن يصحّح دورةً بعينها
 * بيده (`SeasonCycle` وتصحيحاتُ الدورات) — فالحسابُ يقترح، والإعلانُ يحكم.
 *
 * ═══ وامتدادُ `intl` شرطٌ يُسأل عنه لا يُفترض ═══
 *
 * موجودٌ على الإنتاج وفي CI. وحيث لا يكون، يُقال ذلك صريحًا ويُمنع اختيارُ
 * التقويم الهجريّ — ولا يُخترع حسابٌ تقريبيٌّ يمشي سنةً ويضلّ في الثانية.
 */
final class Hijri
{
    /** تقويمُ أمّ القرى بعينه — لا «islamic» المطلقة، فهي حسابٌ آخر يفترق يومًا */
    private const CALENDAR = 'en@calendar=islamic-umalqura';

    /** والتوقيتُ توقيتُ التطبيق: اليومُ يبدأ بمنتصف ليله هنا لا في غرينتش */
    public static function available(): bool
    {
        return class_exists(IntlCalendar::class);
    }

    private static function calendar(): IntlCalendar
    {
        $c = IntlCalendar::createInstance(
            new \DateTimeZone(config('app.timezone', 'UTC')),
            self::CALENDAR,
        );

        /* والحسابُ متساهلٌ بطبعه: يومٌ ٣٠ في شهرٍ من ٢٩ ينزلق إلى الشهر التالي صامتًا */
        $c->setLenient(false);

        return $c;
    }

    /**
     * تاريخٌ ميلاديّ → هجريّ.
     *
     * @return array{year:int, month:int, day:int}
     */
    public static function fromGregorian(Carbon $date): array
    {
        $c = self::calendar();
        $c->setTime($date->copy()->startOfDay()->getTimestampMs());

        return [
            'year' => (int) $c->get(IntlCalendar::FIELD_YEAR),
            // ICU يعدّ الشهور من صفر
            'month' => (int) $c->get(IntlCalendar::FIELD_MONTH) + 1,
            'day' => (int) $c->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /**
     * هجريّ → ميلاديّ، واليومُ يُقصّ إلى آخر الشهر إن تجاوزه.
     *
     * ═══ ولمَ يُقصّ ولا ينزلق ═══
     *
     * موسمٌ أُرّخ في ٣٠ من شهرٍ، ثمّ جاءت سنةٌ شهرُها ٢٩ يومًا. فلو تُرك
     * الحسابُ متساهلًا لَوقع في أوّل الشهر التالي — «آخرُ رمضان» يصير «أوّل
     * شوّال»، وهو يومُ العيد لا آخرُ الصيام. والقصُّ يُبقيه في شهره.
     */
    public static function toGregorian(int $year, int $month, int $day): Carbon
    {
        $c = self::calendar();
        $c->clear();
        $c->set(IntlCalendar::FIELD_YEAR, $year);
        $c->set(IntlCalendar::FIELD_MONTH, $month - 1);
        $c->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        $last = (int) $c->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH);
        $c->set(IntlCalendar::FIELD_DAY_OF_MONTH, min($day, $last));

        return Carbon::createFromTimestampMs($c->getTime(), config('app.timezone', 'UTC'))->startOfDay();
    }

    /** كم يومًا في هذا الشهر الهجريّ — ٢٩ أو ٣٠، تُقرأ ولا تُفترض */
    public static function daysInMonth(int $year, int $month): int
    {
        $c = self::calendar();
        $c->clear();
        $c->set(IntlCalendar::FIELD_YEAR, $year);
        $c->set(IntlCalendar::FIELD_MONTH, $month - 1);
        $c->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        return (int) $c->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH);
    }
}

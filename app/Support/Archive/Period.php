<?php

namespace App\Support\Archive;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * شهرٌ مُغلَق — حدّاه محسوبان مرّةً في موضعٍ واحد.
 *
 * ═══ ولمَ صنفٌ لِما يبدو سطرين ═══
 *
 * حدُّ الشهر يُكتب في ستّة مواضع: الاستعلامُ عن الطلبات، وعن الفواتير، وعن
 * المصروفات، وعن الحركات، وميزانُ المراجعة، وسطرُ الفترة في الورقة. وستّةُ
 * حساباتٍ للحدّ نفسِه تفترق يومًا: خمسةٌ تقرأ `< 2026-09-01` وسادسٌ يقرأ
 * `<= 2026-08-31` — فتدخل الورقةَ فاتورةٌ كُتبت الساعةَ العاشرةَ ليلًا في
 * آخر يومٍ من الشهر، أو تخرج منها.
 *
 * ═══ والمنطقة الزمنيّة ═══
 *
 * لا عمودَ منطقةٍ زمنيّة للمتجر في هذا النظام — وليست نقصًا يُسدّ بهجرة:
 * متاجرُ أبعاد كلُّها في عُمان، و`config/app.php` تقول `Asia/Muscat`،
 * وLaravel يضبط منطقةَ PHP عليها فتُكتب الطوابعُ في القاعدة بها وتُقرأ بها.
 * فالحدُّ محسوبٌ بالمنطقة التي كُتبت بها الصفوف — وهو المطلوب.
 *
 * ويُقرأ الحدُّ من `config('app.timezone')` لا من ثابتٍ مكتوب: يومَ يُضبط
 * `APP_TIMEZONE` لخادمٍ في بلدٍ آخر تتبعه الحدودُ بلا أن يُمسّ هذا الملفّ.
 * ويومَ يصير للمتجر منطقتُه، فهذا هو الموضعُ الواحدُ الذي يُبدَّل.
 */
final class Period
{
    private function __construct(
        public readonly int $year,
        public readonly int $month,
    ) {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("شهرٌ لا وجود له: {$month}");
        }
    }

    public static function of(int $year, int $month): self
    {
        return new self($year, $month);
    }

    /**
     * الشهرُ المُغلَق الذي يسبق هذه اللحظة.
     *
     * ═══ و`startOfMonth()` قبل الطرح هي الحارس ═══
     *
     * `subMonth()` على ٣١ مارس تردّ **٣ مارس** — لأنّ فبراير لا ٣١ فيه
     * فتتدحرج. فيُؤرشَف مارسُ مرّتين ولا يُؤرشَف فبراير أبدًا، ولا يقع ذلك
     * إلّا في يومٍ بعينه من السنة فيمرّ صامتًا.
     *
     * والنزولُ إلى أوّل الشهر أوّلًا يمنعه من أصله: الطرحُ يقع على اليوم
     * الأوّل، وكلُّ شهرٍ فيه أوّل. ولا حاجة معه إلى `NoOverflow` — وكانت
     * مكتوبةً هنا، فأثبتت الطفرةُ أنّها لا تحرس شيئًا خلف `startOfMonth`،
     * فحُذفت. وحارسان لشيءٍ واحد يجعلان القارئ يظنّ الحمايةَ في الخاطئ
     * منهما، فيحذفه غدًا ويبقى العطب.
     */
    public static function previous(?Carbon $now = null): self
    {
        $at = ($now ?? Carbon::now(self::timezone()))->copy()
            ->setTimezone(self::timezone())
            ->startOfMonth()
            ->subMonth();

        return new self((int) $at->year, (int) $at->month);
    }

    /** الشهرُ الجاري — يُسأل عنه ليُرفض، لا ليُؤرشَف */
    public static function current(?Carbon $now = null): self
    {
        $at = ($now ?? Carbon::now(self::timezone()))->copy()->setTimezone(self::timezone());

        return new self((int) $at->year, (int) $at->month);
    }

    /** أوّلُ لحظةٍ فيه — شاملة */
    public function start(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1, 0, 0, 0, self::timezone());
    }

    /**
     * أوّلُ لحظةٍ **بعده** — وهي الحدُّ الذي تُقارَن به الاستعلامات.
     *
     * والمقارنةُ `>= start && < next` لا `between start and end`: الثانيةُ
     * تحتاج «آخرَ لحظة»، وآخرُ لحظةٍ لا تُكتب بدقّة — `23:59:59` تُسقط ما
     * وقع في الكسر بعدها، والقواعدُ تخزّن الكسور. فالحدُّ المفتوح من أعلى
     * لا يُسقط شيئًا ولا يزيد.
     */
    public function next(): Carbon
    {
        /* والبدايةُ أوّلُ الشهر دائمًا، فلا تدحرجَ يُخشى — انظر `previous` */
        return $this->start()->addMonth();
    }

    /** آخرُ لحظةٍ فيه — للعرض وللميزان الذي يأخذ تاريخًا لا مدًى */
    public function end(): Carbon
    {
        return $this->next()->subSecond();
    }

    /** أمضى؟ — شهرٌ لم ينتهِ بعدُ لا يُسمّى أرشيفًا */
    public function isClosed(?Carbon $now = null): bool
    {
        return $this->next()->lessThanOrEqualTo($now ?? Carbon::now(self::timezone()));
    }

    /** «2026-08» */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** «أغسطس 2026» — بلغة القارئ الحاليّة */
    public function label(): string
    {
        return $this->start()->translatedFormat('F Y');
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}

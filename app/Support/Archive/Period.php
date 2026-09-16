<?php

namespace App\Support\Archive;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * مدًى مُغلَق من الزمن — أسبوعٌ أو شهر، بحدّين محسوبين في موضعٍ واحد.
 *
 * ═══ ولمَ صنفٌ لِما يبدو سطرين ═══
 *
 * حدُّ المدى يُكتب في ستّة عشر موضعًا في `Sheets` وحدها: الطلباتُ والفواتيرُ
 * والمصروفاتُ والحركاتُ وميزانُ المراجعة وسطرُ الفترة في الورقة. وستّةَ عشرَ
 * حسابًا للحدّ نفسِه تفترق يومًا: خمسةَ عشرَ تقرأ `< 2026-09-01` وسادسَ عشرَ
 * يقرأ `<= 2026-08-31` — فتدخل الورقةَ فاتورةٌ كُتبت الساعةَ العاشرةَ ليلًا
 * في آخر يومٍ، أو تخرج منها.
 *
 * ═══ ونوعان في صنفٍ واحد لا صنفان ═══
 *
 * الأسبوعيُّ والشهريُّ يختلفان في شيئين اثنين: طولُ القفزة، وشكلُ العنوان.
 * وكلُّ ما عداهما واحد — الحدُّ المفتوح من أعلى، والمنطقةُ الزمنيّة، وسؤالُ
 * «أمضى؟». فصنفان يجعلان أربعةَ عشرَ سطرًا مكرّرًا ينتظر أن يُصلَح أحدُهما
 * ويُنسى الآخر.
 *
 * ═══ والمنطقة الزمنيّة ═══
 *
 * لا عمودَ منطقةٍ زمنيّة للمتجر في هذا النظام — وليست نقصًا يُسدّ بهجرة:
 * متاجرُ أبعاد كلُّها في عُمان، و`config/app.php` تقول `Asia/Muscat`،
 * وLaravel يضبط منطقةَ PHP عليها فتُكتب الطوابعُ في القاعدة بها وتُقرأ بها.
 *
 * ويُقرأ الحدُّ من `config('app.timezone')` لا من ثابتٍ مكتوب: يومَ يُضبط
 * `APP_TIMEZONE` لخادمٍ في بلدٍ آخر تتبعه الحدودُ بلا أن يُمسّ هذا الملفّ.
 */
final class Period
{
    /** من الاثنين إلى الأحد — انظر `weekStart` */
    public const WEEKLY = 'weekly';

    /** من أوّل الشهر إلى آخره */
    public const MONTHLY = 'monthly';

    /** ما يقبله النظام — يُقرأ في التحقّق من المدخلات وفي الشاشة */
    public const TYPES = [self::WEEKLY, self::MONTHLY];

    private function __construct(
        public readonly string $type,
        public readonly Carbon $start,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("نوعُ أرشيفٍ لا وجود له: {$type}");
        }
    }

    /* ═══════════════════ بناء ═══════════════════ */

    public static function month(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("شهرٌ لا وجود له: {$month}");
        }

        return new self(self::MONTHLY, Carbon::create($year, $month, 1, 0, 0, 0, self::timezone()));
    }

    /** الأسبوعُ الذي يقع فيه هذا اليوم — أيًّا كان اليوم */
    public static function week(Carbon $day): self
    {
        return new self(self::WEEKLY, self::weekStart($day));
    }

    /**
     * المدى المُغلَق الذي يسبق هذه اللحظة، من نوعه.
     *
     * ═══ والنزولُ إلى أوّل المدى قبل الطرح هو الحارس ═══
     *
     * `subMonth()` على ٣١ مارس تردّ **٣ مارس** — لأنّ فبراير لا ٣١ فيه
     * فتتدحرج. فيُؤرشَف مارسُ مرّتين ولا يُؤرشَف فبراير أبدًا، ولا يقع ذلك
     * إلّا في يومٍ بعينه من السنة فيمرّ صامتًا.
     *
     * والنزولُ أوّلًا يمنعه من أصله: الطرحُ يقع على اليوم الأوّل، وكلُّ
     * شهرٍ فيه أوّل. والأسبوعُ سبعةٌ دائمًا فلا تدحرجَ فيه أصلًا.
     */
    public static function previous(string $type, ?Carbon $now = null): self
    {
        $at = ($now ?? Carbon::now(self::timezone()))->copy()->setTimezone(self::timezone());

        return $type === self::WEEKLY
            ? new self(self::WEEKLY, self::weekStart($at)->subWeek())
            : new self(self::MONTHLY, $at->startOfMonth()->subMonth());
    }

    /** المدى الجاري — يُسأل عنه ليُرفض، لا ليُؤرشَف */
    public static function current(string $type, ?Carbon $now = null): self
    {
        $at = ($now ?? Carbon::now(self::timezone()))->copy()->setTimezone(self::timezone());

        return $type === self::WEEKLY
            ? new self(self::WEEKLY, self::weekStart($at))
            : new self(self::MONTHLY, $at->startOfMonth());
    }

    /** مدًى كما هو مكتوبٌ في صفّ الأرشيف */
    public static function stored(string $type, Carbon|string $start): self
    {
        $at = $start instanceof Carbon ? $start->copy() : Carbon::parse($start);

        return new self($type, $at->setTimezone(self::timezone())->startOfDay());
    }

    /* ═══════════════════ حدود ═══════════════════ */

    /** أوّلُ لحظةٍ فيه — شاملة */
    public function start(): Carbon
    {
        return $this->start->copy();
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
        return $this->type === self::WEEKLY
            ? $this->start()->addWeek()
            : $this->start()->addMonth();
    }

    /** آخرُ لحظةٍ فيه — للعرض وللميزان الذي يأخذ تاريخًا لا مدًى */
    public function end(): Carbon
    {
        return $this->next()->subSecond();
    }

    /** آخرُ **يومٍ** فيه — وهو ما يُخزَّن في العمود */
    public function endDate(): Carbon
    {
        return $this->next()->subDay()->startOfDay();
    }

    /** أمضى؟ — مدًى لم ينتهِ بعدُ لا يُسمّى أرشيفًا */
    public function isClosed(?Carbon $now = null): bool
    {
        return $this->next()->lessThanOrEqualTo($now ?? Carbon::now(self::timezone()));
    }

    /* ═══════════════════ أسماء ═══════════════════ */

    /**
     * مفتاحٌ ثابتٌ لا يتغيّر بلغة القارئ — للمسارات وأسماء الملفّات والبيان.
     *
     * «2026-08» للشهر، و«2026-W37» للأسبوع. ولا يُترجَم: اسمُ ملفٍّ يتبدّل
     * بلغة من فتح الشاشة يجعل الملفَّ الواحد يُنزَّل باسمين.
     */
    public function key(): string
    {
        return $this->type === self::WEEKLY
            ? $this->start->isoFormat('GGGG-[W]WW')
            : $this->start->format('Y-m');
    }

    /**
     * عنوانٌ يقرؤه التاجر بلغته — «أغسطس 2026» أو «7 – 13 سبتمبر 2026».
     *
     * ═══ ولا يُخزَّن مترجَمًا ═══
     *
     * الصفُّ يحمل تاريخين، وهذا يبنيهما نصًّا وقتَ العرض. ونصٌّ مترجَمٌ في
     * القاعدة يُكتب بلغة من أنشأه لا بلغة من يقرؤه — فيرى الإنجليزيُّ
     * «أغسطس» في شاشةٍ إنجليزيّة، ولا يتغيّر أبدًا لأنّه بيانٌ لا عرض.
     *
     * والأسبوعُ ثلاثةُ أشكال: داخلَ شهرٍ واحد، وعابرًا شهرين، وعابرًا سنتين.
     * وشكلٌ واحدٌ لثلاثتها يكتب «7 سبتمبر 2026 – 13 سبتمبر 2026» — صحيحٌ
     * وثقيل، ويُقرأ في بطاقةٍ ضيّقة سطرين.
     */
    public function label(): string
    {
        if ($this->type === self::MONTHLY) {
            return $this->start->translatedFormat('F Y');
        }

        $from = $this->start;
        $to = $this->endDate();

        if ($from->year !== $to->year) {
            return $from->translatedFormat('j F Y').' – '.$to->translatedFormat('j F Y');
        }

        if ($from->month !== $to->month) {
            return $from->translatedFormat('j F').' – '.$to->translatedFormat('j F Y');
        }

        return $from->translatedFormat('j').' – '.$to->translatedFormat('j F Y');
    }

    /** اسمُ النوع بلغة القارئ — للبطاقات وللبيان */
    public function typeLabel(): string
    {
        return $this->type === self::WEEKLY ? __('أسبوعي') : __('شهري');
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * أوّلُ يومٍ في أسبوع هذا اليوم — الاثنين، مكتوبًا لا موروثًا.
     *
     * `startOfWeek()` بلا وسيط تتبع إعدادَ اللغة: الإنجليزيّةُ تبدأ الأحد
     * والعربيّةُ قد تبدأ السبت. فأسبوعُ التاجر يتبدّل بلغة من فتح الشاشة،
     * ويُطلب أرشيفٌ لمدًى يخالف المدى الذي بُني — والفهرسُ الفريد يردّه
     * «موجودًا» وهو ليس هو.
     */
    private static function weekStart(Carbon $day): Carbon
    {
        return $day->copy()->setTimezone(self::timezone())
            ->startOfWeek(Carbon::MONDAY)->startOfDay();
    }
}

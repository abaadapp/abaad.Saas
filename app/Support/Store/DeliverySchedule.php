<?php

namespace App\Support\Store;

use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * أيّامُ التسليم وفتراتُه — جدولٌ يضبطه صاحبُ المتجر، لا سطورٌ تُقرأ بظنّ.
 *
 * ═══ ما كان ═══
 *
 * فتراتُ التسليم سطورٌ حرّة («9 ص – 12 م»)، والتاريخُ أيُّ يومٍ في المدّة.
 * فيختار الزبونُ الجمعةَ والمحلُّ لا يوصّل فيها، أو فترةَ الصباح بعد الظهر.
 * وساعةُ الطلب تُستخرج من أوّل رقمٍ في السطر (`WebCheckout::scheduledFor`)،
 * فـ«٤ م» بأرقامٍ عربيّة تُقرأ التاسعةَ صباحًا.
 *
 * ═══ وما يُضاف — مفتاحٌ مطفأٌ افتراضًا ═══
 *
 * `store_delivery_schedule_on`: من فتحه صار جدولُه هو الحكم، ومن لم يفتحه
 * يعمل متجرُه بالسطور كما كان، حرفًا. وفي الجدول:
 *
 *   - لكلّ يومٍ من الأسبوع فتراتُه: ساعةُ بدايةٍ ونهاية، واسمٌ اختياريٌّ بكلّ
 *     لغة، و**معرّفٌ ثابت** لا يتبدّل بتعديل الاسم أو الساعة — به تُعرف
 *     الفترةُ في الطلب لا بنصّها.
 *   - الاستلامُ من المحلّ بجدول التوصيل نفسِه، أو بجدولٍ له (`pickup`).
 *   - أيّامُ الإغلاق والإجازات — للطريقتين معًا.
 *   - مدّةُ التجهيز (`lead`، بالدقائق): لا تُعرض فترةٌ تبدأ قبل أن يُجهَّز الطلب.
 *   - والحجزُ مقدّمًا هو `store_max_days` القائم، لا مفتاحٌ ثانٍ له.
 *
 * ═══ ومتى تُعرض الفترة ═══
 *
 * ما دامت **بدايتُها** بعد الآن ومدّةِ التجهيز. ففترةٌ بدأت لا تُختار: لو
 * قُبلت لَكتب الطلبُ موعدًا مضى، ووقف في لوحة التجهيز متأخّرًا لحظةَ دخوله.
 *
 * ═══ وآخرُ وقتٍ لحجزها — لكلّ فترةٍ وحدها، واختياريّ ═══
 *
 * «الصباح 9 – 12، آخرُ حجزٍ الثامنة»، و«المساء 4 – 9، آخرُ حجزٍ الثانية ظهرًا»،
 * أو «آخرُ حجزٍ التاسعةَ مساءَ اليوم السابق» لمن يجهّز ليلًا (`cutoff`:
 * `{time, day: same|before}`). فإذا بلغته الساعةُ — بتوقيت مسقط — اختفت الفترةُ
 * من خيارات الزبون وبقيت التاليةُ لها، ولا يُغلق بهذا استقبالُ الطلبات
 * (`OrderWindow`). ويجتمع مع مدّة التجهيز: تُعرض الفترةُ ما لم يبلغ أيٌّ منهما.
 *
 * وفترةٌ بلا حدٍّ تعمل كما كانت حرفًا، والجدولُ المحفوظ قبل الحدّ يُقرأ كما هو.
 *
 * ═══ وحين لا موعدَ للتوصيل أصلًا ═══
 *
 * يكتب صاحبُ المتجر ما يقرؤه زبونُه بلغتيه (`store_delivery_unavailable`).
 * ويظهر حين لا فترةَ توصيلٍ في نطاق الحجز كلِّه — لا حين تنتهي فتراتُ
 * اليوم وفتراتُ الغد متاحة — ويبقى الاستلامُ يُختار إن كانت له مواعيد.
 * والمتجرُ المغلق يقول رسالةَ إغلاقه هو (`OrderWindow::message`)، لا هذه.
 */
final class DeliverySchedule
{
    public const ON = 'store_delivery_schedule_on';

    public const KEY = 'store_delivery_schedule';

    public const MAX_SLOTS = 8;

    public const LABEL_MAX = 40;

    /** أطولُ تجهيزٍ يُضبط — أسبوع */
    public const LEAD_MAX = 10080;

    public const MAX_CLOSURES = 60;

    /** ما يقرؤه الزبونُ حين لا موعدَ توصيلٍ في نطاق الحجز — بالعربيّة وبالإنجليزيّة */
    public const UNAVAILABLE = 'store_delivery_unavailable';

    public const UNAVAILABLE_EN = 'store_delivery_unavailable_en';

    public const UNAVAILABLE_MAX = 300;

    /** آخرُ الحجز في يوم الفترة نفسه، أو في اليوم الذي قبله */
    public const SAME_DAY = 'same';

    public const DAY_BEFORE = 'before';

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const ID = '/^[a-z0-9]{4,12}$/';

    /** أفُتح الجدولُ لهذا المتجر؟ */
    public static function on(int $bid): bool
    {
        return (MarketingSettings::group($bid, 'website')[self::ON] ?? '0') === '1';
    }

    /**
     * @return array{delivery: list<list<array>>, pickup: list<list<array>>|null, closed: list<array{from: string, to: string}>, lead: int}
     */
    public static function config(int $bid): array
    {
        return self::fromJson((string) (MarketingSettings::group($bid, 'website')[self::KEY] ?? ''));
    }

    /* ═══════════ ما يُعرض للزبون ═══════════ */

    /**
     * الأيّامُ المتاحة لطريقة الاستلام وفتراتُ كلٍّ منها — من اليوم إلى آخر المدّة.
     *
     * يومٌ أُغلق، أو لا فترةَ فيه، أو مضت فتراتُه كلُّها، لا يُذكر أصلًا: لا
     * يُعرض للزبون يومٌ لا يستطيع اختيارَه.
     *
     * @return list<array{date: string, slots: list<array{id: string, from: string, to: string, ar: string, en: string, starts: CarbonImmutable, ends: CarbonImmutable}>}>
     */
    public static function days(int $bid, string $fulfil, ?CarbonInterface $now = null): array
    {
        return self::daysFor(self::config($bid), $fulfil, CheckoutFields::maxDays($bid), $now ?? ShopClock::now());
    }

    /**
     * حكمُ الجدول وحدَه — بلا إعدادٍ ولا قاعدة، فيُختبر بنفسه.
     */
    public static function daysFor(array $cfg, string $fulfil, int $maxDays, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->setTimezone(ShopClock::zone());
        $week = self::weekFor($cfg, $fulfil);
        $ready = $now->addMinutes((int) $cfg['lead']);
        $out = [];

        for ($i = 0, $day = $now->startOfDay(); $i <= $maxDays; $i++, $day = $day->addDay()) {
            $ymd = $day->toDateString();
            foreach ($cfg['closed'] as $r) {
                if ($ymd >= $r['from'] && $ymd <= $r['to']) {
                    continue 2;
                }
            }

            $slots = [];
            foreach ($week[$day->dayOfWeek] ?? [] as $s) {
                $starts = $day->setTimeFromTimeString($s['from']);
                if ($starts->lessThan($ready)) {
                    continue;
                }
                // وآخرُ حجزها إن ضُبط: عنده تُغلق، لا بعده بدقيقة
                if (isset($s['cutoff']) && ! $now->lessThan(self::cutoffAt($s['cutoff'], $day))) {
                    continue;
                }
                $slots[] = $s + ['starts' => $starts, 'ends' => $day->setTimeFromTimeString($s['to'])];
            }

            if ($slots !== []) {
                $out[] = ['date' => $ymd, 'slots' => $slots];
            }
        }

        return $out;
    }

    /** لحظةُ إغلاق الحجز لفترةٍ في يومها — بساعة المتجر */
    public static function cutoffAt(array $cutoff, CarbonImmutable $day): CarbonImmutable
    {
        return ($cutoff['day'] === self::DAY_BEFORE ? $day->subDay() : $day)->setTimeFromTimeString($cutoff['time']);
    }

    /**
     * ما يقرؤه الزبونُ حين لا موعدَ توصيل — نصُّ صاحب المتجر بلغة الصفحة، أو
     * `null` فيُقال نصُّ النظام. ولا تقع لغةٌ على الأخرى.
     */
    public static function unavailableSays(int $bid, string $lang): ?string
    {
        $text = trim((string) (MarketingSettings::group($bid, 'website')[$lang === 'en' ? self::UNAVAILABLE_EN : self::UNAVAILABLE] ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * الفترةُ التي اختارها الزبون — إن كانت متاحةً الآن لطريقته وفي يومها.
     *
     * والحكمُ هنا لا في المتصفّح: يومٌ مُغلق أو فترةٌ مضت أو معرّفٌ مصنوعٌ
     * يُردّ ولو أرسلته أدواتُ المتصفّح بيدها.
     *
     * @return array{id: string, date: string, from: string, to: string, ar: string, en: string}|null
     */
    public static function pick(int $bid, string $fulfil, ?string $date, ?string $slotId, ?CarbonInterface $now = null): ?array
    {
        if (! filled($date) || ! filled($slotId)) {
            return null;
        }

        foreach (self::days($bid, $fulfil, $now) as $day) {
            if ($day['date'] !== $date) {
                continue;
            }
            foreach ($day['slots'] as $s) {
                if ($s['id'] === $slotId) {
                    return self::snapshot($s, $date);
                }
            }
        }

        return null;
    }

    /**
     * الفترةُ بمعرّفها في الجدول القائم — بلا حكم الوقت ولا الإغلاق.
     *
     * لدفعةٍ قُبضت وحدها (`WebCheckout::chosenSlot`): المالُ خرج، فيُكتب الطلبُ
     * بما اختاره زبونُه ولو مضى وقتُه — ولا تُعرض بهذا فترةٌ لطلبٍ جديد أبدًا.
     */
    public static function lookup(int $bid, string $fulfil, mixed $date, mixed $slotId): ?array
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! filled($slotId)) {
            return null;
        }

        $week = self::weekFor(self::config($bid), $fulfil);
        $dow = ShopClock::date($date)->dayOfWeek;

        // يومُ الموعد أوّلًا — والمعرّفُ نفسُه قد يكون في أيّامٍ أخرى بساعاتٍ أخرى
        foreach ([$week[$dow] ?? [], ...$week] as $slots) {
            foreach ($slots as $s) {
                if ($s['id'] === (string) $slotId) {
                    return self::snapshot($s, $date);
                }
            }
        }

        return null;
    }

    /**
     * لقطةُ الفترة يومَ اختيرت — تمضي مع نيّة الدفع وتُكتب في الطلب.
     *
     * فلو عدّل صاحبُ المتجر جدولَه والزبونُ عند البنك، لا يُردّ طلبٌ مدفوع
     * لأنّ فترتَه تبدّلت: يُكتب بما اختاره ودفع عليه.
     *
     * @return array{id: string, date: string, from: string, to: string, ar: string, en: string}
     */
    public static function snapshot(array $slot, string $date): array
    {
        return [
            'id' => (string) $slot['id'],
            'date' => $date,
            'from' => (string) $slot['from'],
            'to' => (string) $slot['to'],
            'ar' => (string) ($slot['ar'] ?? ''),
            'en' => (string) ($slot['en'] ?? ''),
        ];
    }

    /**
     * لقطةٌ عادت من نيّة الدفع — تُقبل إن كان شكلُها شكلَ لقطة، وإلّا لا شيء.
     */
    public static function fromSnapshot(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $date = (string) ($raw['date'] ?? '');
        $from = (string) ($raw['from'] ?? '');
        $to = (string) ($raw['to'] ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match(self::TIME, $from) || ! preg_match(self::TIME, $to)) {
            return null;
        }

        return self::snapshot($raw, $date);
    }

    /** بدايةُ الفترة ونهايتُها في يومها، بساعة المتجر */
    public static function bounds(array $snapshot): array
    {
        $day = ShopClock::date($snapshot['date']);

        return [$day->setTimeFromTimeString($snapshot['from']), $day->setTimeFromTimeString($snapshot['to'])];
    }

    /**
     * اسمُ الفترة بلغة الصفحة — اسمُها إن كُتب ومعه ساعتاها، وإلّا ساعتاها.
     *
     * «الصباح (9:00 ص – 12:00 م)» أو «9:00 ص – 12:00 م». ولا يقع اسمُ لغةٍ
     * على الأخرى: فترةٌ بلا اسمٍ إنجليزيٍّ تُكتب بساعتيها في الصفحة الإنجليزيّة.
     */
    public static function label(array $slot, string $lang): string
    {
        $range = ShopClock::range((string) $slot['from'], (string) $slot['to'], $lang);
        $name = trim((string) ($slot[$lang === 'en' ? 'en' : 'ar'] ?? ''));

        return $name === '' ? $range : $name.' ('.$range.')';
    }

    /**
     * ما تقرؤه صفحةُ الإتمام: لكلّ طريقةٍ أيّامُها، ولكلّ يومٍ فتراتُه — بلغتها.
     *
     * @param  list<string>  $fulfilments
     * @return array<string, list<array{date: string, label: string, slots: list<array{id: string, label: string}>}>>
     */
    public static function forPage(int $bid, array $fulfilments, string $lang): array
    {
        $out = [];

        foreach ($fulfilments as $fulfil) {
            $out[$fulfil] = array_map(fn ($day) => [
                'date' => $day['date'],
                'label' => ShopClock::day(ShopClock::date($day['date']), $lang),
                'slots' => array_map(fn ($s) => ['id' => $s['id'], 'label' => self::label($s, $lang)], $day['slots']),
            ], self::days($bid, $fulfil));
        }

        return $out;
    }

    /* ═══════════ القراءةُ والحفظ ═══════════ */

    /**
     * @return array{delivery: list<list<array>>, pickup: list<list<array>>|null, closed: list<array{from: string, to: string}>, lead: int}
     */
    public static function fromJson(string $raw): array
    {
        $empty = ['delivery' => array_fill(0, 7, []), 'pickup' => null, 'closed' => [], 'lead' => 0];
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return $empty;
        }

        try {
            return self::normalize($data, assignIds: false);
        } catch (ValidationException) {
            return $empty;
        }
    }

    /**
     * ما أرسلته الشاشةُ مفحوصًا بصيغةٍ واحدة — وللفترة الجديدة معرّفُها.
     */
    public static function validated(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($data)) {
            throw ValidationException::withMessages([self::KEY => __('تعذّرت قراءة جدول التسليم — أعد ضبطه.')]);
        }

        $cfg = self::normalize($data, assignIds: true);
        self::assertNoOverlap($cfg['delivery'], FlowerOrder::DELIVERY);
        if ($cfg['pickup'] !== null) {
            self::assertNoOverlap($cfg['pickup'], FlowerOrder::PICKUP);
        }

        return json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * فترتان في يومٍ واحدٍ لا تتداخلان — «9 – 12» و«11 – 2» موعدان لا يُعرف
     * أيُّهما وعد به المحلّ، وطلباتُ الساعة المشتركة تُحسب على الاثنين.
     *
     * وتلاصقُهما ليس تداخلًا: «9 – 12» ثمّ «12 – 3» فترتان متتاليتان. ويُفحص
     * عند الحفظ وحده — `fromJson` لا يردّ جدولًا محفوظًا، فلا يُطفأ جدولُ
     * متجرٍ قائمٍ بقراءته — وكلُّ طريقةٍ في أسبوعها: التوصيلُ والاستلامُ
     * يجتمعان في الساعة نفسِها بلا تعارض.
     *
     * @param  list<list<array{id: string, from: string, to: string, ar: string, en: string}>>  $week
     */
    private static function assertNoOverlap(array $week, string $fulfil): void
    {
        $lang = app()->getLocale() === 'en' ? 'en' : 'ar';

        foreach ($week as $dow => $slots) {
            /*
             * مرتّبةٌ ببدايتها (`normalize`)، فيكفي أن تُقارن كلُّ فترةٍ بالتي قبلها:
             * فترةٌ تمسّ ما قبل سابقتها تمسّ سابقتَها أوّلًا.
             */
            foreach (array_slice($slots, 1) as $i => $slot) {
                $prev = $slots[$i];
                if ($slot['from'] < $prev['to']) {
                    $words = [
                        'day' => ShopClock::weekday($dow, $lang),
                        'a' => ShopClock::range($prev['from'], $prev['to'], $lang),
                        'b' => ShopClock::range($slot['from'], $slot['to'], $lang),
                    ];

                    throw ValidationException::withMessages([self::KEY => $fulfil === FlowerOrder::PICKUP
                        ? __('فترتا استلامٍ يوم :day متداخلتان: :a و:b — ابدأ كل فترة عند نهاية التي قبلها أو بعدها.', $words)
                        : __('فترتا توصيلٍ يوم :day متداخلتان: :a و:b — ابدأ كل فترة عند نهاية التي قبلها أو بعدها.', $words)]);
                }
            }
        }
    }

    /**
     * جدولٌ مفتوحٌ لا فترةَ فيه لطريقةٍ معروضة يعني زبونًا لا يستطيع أن يطلب.
     * فيُقال لصاحبه حين يحفظ، لا يكتشفه زبونٌ عند الإتمام.
     *
     * @param  list<string>  $fulfilments
     */
    public static function assertUsable(string $json, array $fulfilments): void
    {
        $cfg = self::fromJson($json);
        $has = fn (array $week) => array_filter($week) !== [];

        if (in_array(FlowerOrder::DELIVERY, $fulfilments, true) && ! $has($cfg['delivery'])) {
            throw ValidationException::withMessages([self::KEY => __('أضف فترة توصيلٍ واحدةً على الأقل، أو أطفئ جدول المواعيد.')]);
        }

        if (in_array(FlowerOrder::PICKUP, $fulfilments, true) && ! $has(self::weekFor($cfg, FlowerOrder::PICKUP))) {
            throw ValidationException::withMessages([self::KEY => __('أضف فترة استلامٍ واحدةً على الأقل، أو اجعل الاستلام بجدول التوصيل.')]);
        }
    }

    /** أسبوعُ هذه الطريقة: الاستلامُ بجدوله إن كان له جدول، وإلّا بجدول التوصيل */
    private static function weekFor(array $cfg, string $fulfil): array
    {
        return $fulfil === FlowerOrder::PICKUP && $cfg['pickup'] !== null ? $cfg['pickup'] : $cfg['delivery'];
    }

    private static function normalize(array $data, bool $assignIds): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([self::KEY => $msg]);

        /*
         * آخرُ الحجز: ساعةٌ، وفي يوم الفترة أو اليوم السابق. وفي يومها لا يأتي
         * بعد بدايتها — فالفترةُ تختفي عند بدايتها على أيّ حال، وحدٌّ بعدها
         * وعدٌ لا يُوفى.
         */
        $readCutoff = function (mixed $raw, string $from, string $to) use ($fail): ?array {
            if ($raw === null || $raw === '' || (is_array($raw) && ($raw['time'] ?? '') === '')) {
                return null;
            }

            $time = is_array($raw) ? (string) ($raw['time'] ?? '') : '';
            $day = is_array($raw) ? (string) ($raw['day'] ?? self::SAME_DAY) : '';
            if (! preg_match(self::TIME, $time)) {
                $fail(__('اكتب آخر وقت للحجز بالساعة والدقيقة.'));
            }
            if (! in_array($day, [self::SAME_DAY, self::DAY_BEFORE], true)) {
                $fail(__('آخر وقت للحجز في يوم الفترة نفسه أو في اليوم السابق.'));
            }
            if ($day === self::SAME_DAY && $time > $from) {
                $lang = app()->getLocale() === 'en' ? 'en' : 'ar';
                $fail(__('آخر حجز لفترة :range بعد بدايتها — اجعله :from أو قبلها، أو في اليوم السابق.', [
                    'range' => ShopClock::range($from, $to, $lang),
                    'from' => ShopClock::hm($from, $lang),
                ]));
            }

            return ['time' => $time, 'day' => $day];
        };

        $week = function (mixed $raw) use ($fail, $assignIds, $readCutoff): array {
            $out = [];
            for ($d = 0; $d < 7; $d++) {
                /*
                 * والمعرّفُ فريدٌ في يومه لا في أسبوعه: «الصباح» في السبت والأحد
                 * فترةٌ واحدةٌ بمعرّفٍ واحد. والطلبُ يُعرف بيومه ومعرّفِ فترته معًا.
                 */
                $seen = [];
                $slots = is_array($raw) ? ($raw[$d] ?? []) : [];
                if (! is_array($slots) || count($slots) > self::MAX_SLOTS) {
                    $fail(__('لكل يومٍ :n فترات تسليمٍ على الأكثر.', ['n' => self::MAX_SLOTS]));
                }

                $clean = [];
                foreach (array_values($slots) as $s) {
                    $s = is_array($s) ? $s : [];
                    $from = (string) ($s['from'] ?? '');
                    $to = (string) ($s['to'] ?? '');
                    if (! preg_match(self::TIME, $from) || ! preg_match(self::TIME, $to)) {
                        $fail(__('اكتب ساعتي البداية والنهاية لكل فترة تسليم.'));
                    }
                    // وفترةُ التسليم في يومها — لا تعبر منتصف الليل كفترات الاستقبال
                    if ($to <= $from) {
                        $fail(__('نهاية فترة التسليم بعد بدايتها، في اليوم نفسه.'));
                    }

                    $ar = trim((string) ($s['ar'] ?? ''));
                    $en = trim((string) ($s['en'] ?? ''));
                    if (mb_strlen($ar) > self::LABEL_MAX || mb_strlen($en) > self::LABEL_MAX) {
                        $fail(__('اسم الفترة :n حرفًا على الأكثر.', ['n' => self::LABEL_MAX]));
                    }

                    /*
                     * والمعرّفُ ثابتٌ ما بقيت الفترة: يُحفظ كما وصل، ولا يُعطى إلّا
                     * لجديدةٍ بلا معرّف — أو لمكرَّرٍ نُسخ من فترةٍ أخرى.
                     */
                    $id = (string) ($s['id'] ?? '');
                    if (! preg_match(self::ID, $id) || isset($seen[$id])) {
                        if (! $assignIds) {
                            $fail(__('فترةٌ بلا معرّف.'));
                        }
                        do {
                            $id = 's'.bin2hex(random_bytes(3));
                        } while (isset($seen[$id]));
                    }
                    $seen[$id] = true;

                    $row = ['id' => $id, 'from' => $from, 'to' => $to, 'ar' => $ar, 'en' => $en];
                    // وآخرُ الحجز لا يُكتب لفترةٍ بلا حدّ — فيبقى نصُّ الجدول القديم كما كان
                    $cutoff = $readCutoff($s['cutoff'] ?? null, $from, $to);
                    if ($cutoff !== null) {
                        $row['cutoff'] = $cutoff;
                    }
                    $clean[] = $row;
                }
                usort($clean, fn ($a, $b) => strcmp($a['from'], $b['from']));
                $out[] = $clean;
            }

            return $out;
        };

        $delivery = $week($data['delivery'] ?? []);
        $pickup = array_key_exists('pickup', $data) && $data['pickup'] !== null ? $week($data['pickup']) : null;

        $date = function (mixed $v) use ($fail): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            $v = (string) $v;
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $v) {
                $fail(__('تاريخٌ غير صحيح في أيام الإغلاق.'));
            }

            return $v;
        };

        $ranges = $data['closed'] ?? [];
        if (! is_array($ranges) || count($ranges) > self::MAX_CLOSURES) {
            $fail(__('أيام الإغلاق :n على الأكثر.', ['n' => self::MAX_CLOSURES]));
        }
        $closed = [];
        foreach (array_values($ranges) as $r) {
            $from = $date(is_array($r) ? ($r['from'] ?? null) : null);
            $to = $date(is_array($r) ? ($r['to'] ?? null) : null) ?? $from;
            if ($from === null) {
                $fail(__('اختر تاريخ كل إغلاقٍ استثنائيّ.'));
            }
            if ($to < $from) {
                $fail(__('نهاية الإغلاق قبل بدايته.'));
            }
            $closed[] = ['from' => $from, 'to' => $to];
        }
        usort($closed, fn ($a, $b) => strcmp($a['from'], $b['from']));

        $lead = $data['lead'] ?? 0;
        if (! is_numeric($lead) || (int) $lead < 0 || (int) $lead > self::LEAD_MAX || (int) $lead != $lead) {
            $fail(__('مدة التجهيز بالدقائق، من صفرٍ إلى أسبوع.'));
        }

        return ['delivery' => $delivery, 'pickup' => $pickup, 'closed' => $closed, 'lead' => (int) $lead];
    }
}

<?php

namespace App\Support\Store;

use App\Support\MarketingSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * متى يستقبل المتجرُ طلباتِ موقعه — بيد صاحبه، وبجدولٍ يضبطه.
 *
 * ═══ مفتاحان لا واحد، والأوّلُ أعلى ═══
 *
 *   - **الإغلاقُ اليدويّ** (`store_allow_orders`، وهو مفتاحُ «قبول الطلبات»
 *     القائم): من أطفأه أُغلق متجرُه حتّى يفتحه هو. والجدولُ لا يفتحه خلافًا
 *     لقراره، ولا يُعرف له موعدُ فتح — فلا يُكتب للزبون موعدٌ مخترَع.
 *   - **الجدول** (`store_order_schedule_on` و`store_order_schedule`): أيّامُ
 *     الأسبوع وساعاتُها، وأوّلُ يومٍ وآخرُه، وأيّامُ الإجازة. مطفأٌ افتراضًا:
 *     متجرٌ قائمٌ يعمل كما كان حتّى يضبطه صاحبُه.
 *
 * ═══ وفترةٌ تعبر منتصفَ الليل ═══
 *
 * «8 مساءً – 2 فجرًا» تُكتب `20:00 → 02:00`، وما كانت نهايتُه قبل بدايتها
 * عبرت إلى اليوم التالي. و`00:00 → 00:00` يومٌ كامل.
 *
 * ═══ ويومُ الإغلاق مغلقٌ كلُّه ═══
 *
 * يومٌ أغلقه صاحبُه — إجازةً، أو قبل أوّل يومٍ للاستقبال، أو بعد آخره — لا
 * يُستقبل فيه طلبٌ في أيّ ساعةٍ منه، من منتصف ليله إلى منتصف الليل الذي
 * يليه بتوقيت المتجر. فلا يُفاجأ بطلبٍ في يومٍ قال إنّه لا يعمل فيه:
 *
 *   - فترةُ اليوم المغلق لا تُفتح، ولا ما عبر منها إلى غده.
 *   - وفترةٌ من يومٍ مفتوحٍ تعبر إلى يومٍ مغلق تقف عند منتصف الليل: إجازةُ
 *     الجمعة تقطع مناوبةَ الخميس `20:00 → 02:00` عند الثانية عشرة.
 *
 * فالحكمُ للتاريخ الذي تقع فيه اللحظة، لا لليوم الذي بدأت فيه الفترة.
 *
 * ═══ وهذا لا يمسّ التوصيل ═══
 *
 * استقبالُ الطلب غيرُ موعد تسليمه: من طلب في الثامنة والنصف مساءً والمتجرُ
 * يستقبل حتّى التاسعة يحجز توصيلًا للغد. وجدولُ التسليم في `DeliverySchedule`.
 *
 * والحكمُ بساعة المتجر (`ShopClock`) لا بساعة المتصفّح.
 */
final class OrderWindow
{
    public const MANUAL = 'store_allow_orders';

    public const ON = 'store_order_schedule_on';

    public const KEY = 'store_order_schedule';

    public const MESSAGE_ON = 'store_closed_message_on';

    public const MESSAGE = 'store_closed_message';

    public const MESSAGE_EN = 'store_closed_message_en';

    /** حدُّ رسالة الإغلاق بكلّ لغة — يُقرأ في الخادم والشاشة معًا */
    public const MESSAGE_MAX = 600;

    /** المتغيّران المعروفان في الرسالة — وغيرُهما يُردّ عند الحفظ */
    public const PLACEHOLDERS = ['{next_open_date}', '{next_open_time}'];

    public const MAX_WINDOWS = 4;

    public const MAX_CLOSURES = 60;

    /** إلى أين يُبحث عن موعد الفتح التالي — سنةٌ وزيادة */
    public const HORIZON_DAYS = 400;

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /* ═══════════ الحكم ═══════════ */

    /**
     * أيستقبل المتجرُ طلبًا جديدًا الآن؟
     */
    public static function open(int $bid): bool
    {
        return self::status($bid)['open'];
    }

    /**
     * حالُ الاستقبال الآن: مفتوحٌ أم مغلق، ولماذا، ومتى يُفتح إن عُرف.
     *
     * @return array{open: bool, why: 'manual'|'schedule'|null, next: CarbonImmutable|null}
     */
    public static function status(int $bid, ?CarbonInterface $at = null): array
    {
        $site = MarketingSettings::group($bid, 'website');

        if (($site[self::MANUAL] ?? '1') !== '1') {
            return ['open' => false, 'why' => 'manual', 'next' => null];
        }

        if (($site[self::ON] ?? '0') !== '1') {
            return ['open' => true, 'why' => null, 'next' => null];
        }

        return self::evaluate(self::fromJson((string) ($site[self::KEY] ?? '')), $at ?? ShopClock::now());
    }

    /**
     * حكمُ الجدول وحدَه على لحظة — بلا إعدادٍ ولا قاعدة، فيُختبر بنفسه.
     *
     * @param  array{week: list<list<array{from: string, to: string}>>, starts: ?string, ends: ?string, closed: list<array{from: string, to: string}>}  $schedule
     * @return array{open: bool, why: 'schedule'|null, next: CarbonImmutable|null}
     */
    public static function evaluate(array $schedule, CarbonInterface $at): array
    {
        $at = CarbonImmutable::instance($at)->setTimezone(ShopClock::zone());
        $today = $at->startOfDay();

        // أمسِ قبل اليوم: فترةُ أمسِ قد تعبر منتصف الليل إلى هذه اللحظة
        foreach ([$today->subDay(), $today] as $day) {
            foreach (self::windowsOn($schedule, $day) as [$start, $end]) {
                if ($start->lessThanOrEqualTo($at) && $at->lessThan($end)) {
                    return ['open' => true, 'why' => null, 'next' => null];
                }
            }
        }

        return ['open' => false, 'why' => 'schedule', 'next' => self::nextOpening($schedule, $at)];
    }

    /**
     * أوّلُ لحظةٍ يُفتح فيها الاستقبالُ بعد `$at` — أو لا شيء في السنة القادمة.
     */
    private static function nextOpening(array $schedule, CarbonImmutable $at): ?CarbonImmutable
    {
        $day = $at->startOfDay();

        for ($i = 0; $i <= self::HORIZON_DAYS; $i++, $day = $day->addDay()) {
            $starts = array_filter(
                array_map(fn ($w) => $w[0], self::windowsOn($schedule, $day)),
                fn (CarbonImmutable $start) => $start->greaterThan($at),
            );

            if ($starts !== []) {
                usort($starts, fn ($a, $b) => $a <=> $b);

                return $starts[0];
            }
        }

        return null;
    }

    /**
     * فتراتُ يومٍ بعينه بحدودها الفعليّة — لا شيء ليومٍ أُغلق أو خارج المدّة،
     * وما عبر منها إلى غدٍ مغلقٍ يقف عند منتصف الليل.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function windowsOn(array $schedule, CarbonImmutable $day): array
    {
        if (! self::takesOrdersOn($schedule, $day->toDateString())) {
            return [];
        }

        $midnight = $day->addDay();
        $tomorrowOpen = self::takesOrdersOn($schedule, $midnight->toDateString());

        $out = [];
        foreach ($schedule['week'][$day->dayOfWeek] ?? [] as $w) {
            $start = $day->setTimeFromTimeString($w['from']);
            $end = $day->setTimeFromTimeString($w['to']);
            // نهايةٌ لا تتجاوز البدايةَ عبرت منتصف الليل — و`00:00 → 00:00` يومٌ كامل
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }
            if (! $tomorrowOpen && $end->greaterThan($midnight)) {
                $end = $midnight;
            }
            $out[] = [$start, $end];
        }

        return $out;
    }

    /** أيُستقبل في هذا التاريخ طلبٌ أصلًا؟ — داخلَ المدّة، وليس إجازة */
    private static function takesOrdersOn(array $schedule, string $ymd): bool
    {
        if (($schedule['starts'] ?? null) !== null && $ymd < $schedule['starts']) {
            return false;
        }
        if (($schedule['ends'] ?? null) !== null && $ymd > $schedule['ends']) {
            return false;
        }
        foreach ($schedule['closed'] ?? [] as $range) {
            if ($ymd >= $range['from'] && $ymd <= $range['to']) {
                return false;
            }
        }

        return true;
    }

    /* ═══════════ رسالةُ الإغلاق ═══════════ */

    /**
     * ما يُقال للزبون حين لا يُستقبل طلبُه — بلغة صفحته وحدها.
     *
     * رسالةُ صاحب المتجر إن كتبها وفتحها، وإلّا نصُّ النظام بلغة الصفحة
     * (`RibbonTexts['closed']`). ولا تقع رسالةُ لغةٍ على الأخرى أبدًا: صفحةٌ
     * إنجليزيّةٌ بلا رسالةٍ إنجليزيّة تأخذ نصَّ النظام الإنجليزيّ.
     *
     * @param  array{open: bool, why: ?string, next: ?CarbonImmutable}|null  $status
     */
    public static function message(int $bid, string $lang, ?array $status = null): string
    {
        $status ??= self::status($bid);
        $site = MarketingSettings::group($bid, 'website');
        $fallback = RibbonTexts::for($lang)['closed'];

        if (($site[self::MESSAGE_ON] ?? '0') !== '1') {
            return $fallback;
        }

        $text = (string) ($site[$lang === 'en' ? self::MESSAGE_EN : self::MESSAGE] ?? '');

        return self::fill($text, $status['next'], $lang) ?? $fallback;
    }

    /**
     * يملأ المتغيّرين بموعد الفتح — أو يُسقط السطرَ الذي يذكرهما.
     *
     * ═══ ولا موعدَ مخترَعًا ═══
     *
     * متجرٌ أُغلق بيد صاحبه لا موعدَ لفتحه. فلو بقي «نعود {next_open_date}»
     * لَقرأ الزبونُ متغيّرًا خامًا أو تاريخًا لا أصلَ له. فيُسقط كلُّ سطرٍ يذكر
     * موعدًا لا يُعرف، ويبقى ما سواه — ومن كتب رسالتَه سطرين («نعتذر…» ثمّ
     * «نعود…») يقرأ زبونُه الأوّلَ وحده. وإن لم يبقَ شيءٌ فنصُّ النظام.
     */
    public static function fill(string $text, ?CarbonInterface $next, string $lang): ?string
    {
        $lines = preg_split('/\R/u', trim(str_replace("\r\n", "\n", $text))) ?: [];
        $out = [];

        foreach ($lines as $line) {
            if (str_contains($line, '{next_open_date}') || str_contains($line, '{next_open_time}')) {
                if ($next === null) {
                    continue;
                }
                $line = strtr($line, [
                    '{next_open_date}' => ShopClock::day($next, $lang),
                    '{next_open_time}' => ShopClock::time($next, $lang),
                ]);
            }
            $out[] = rtrim($line);
        }

        $result = trim(implode("\n", $out));

        return $result === '' ? null : $result;
    }

    /* ═══════════ القراءةُ والحفظ ═══════════ */

    /**
     * الجدولُ المحفوظ مقروءًا — وما لا يُفهم منه يُقرأ فراغًا لا خطأ.
     *
     * @return array{week: list<list<array{from: string, to: string}>>, starts: ?string, ends: ?string, closed: list<array{from: string, to: string}>}
     */
    public static function fromJson(string $raw): array
    {
        $empty = ['week' => array_fill(0, 7, []), 'starts' => null, 'ends' => null, 'closed' => []];
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return $empty;
        }

        try {
            return self::normalize($data);
        } catch (ValidationException) {
            return $empty;
        }
    }

    /**
     * ما أرسلته الشاشةُ مفحوصًا ومكتوبًا بصيغةٍ واحدة — أو سببُ ردّه.
     *
     * والصيغةُ الواحدة (ترتيبُ المفاتيح، والأيامُ السبعة كلُّها) تجعل الحفظَ
     * الثانيَ لما لم يتغيّر نصًّا مطابقًا للأوّل.
     */
    public static function validated(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($data)) {
            throw ValidationException::withMessages([self::KEY => __('تعذّرت قراءة جدول الاستقبال — أعد ضبطه.')]);
        }

        return json_encode(self::normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** أفيه فترةُ استقبالٍ واحدةٌ على الأقلّ؟ — جدولٌ بلا فترةٍ متجرٌ مغلقٌ أبدًا */
    public static function hasWindows(string $json): bool
    {
        foreach (self::fromJson($json)['week'] as $day) {
            if ($day !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * رسالةُ الإغلاق مفحوصة: متغيّرٌ لا يُعرف يُردّ ولا يُعرض خامًا للزبون.
     */
    public static function assertMessage(string $field, ?string $text): void
    {
        preg_match_all('/\{[^{}\s]*\}/u', (string) $text, $m);

        foreach (array_unique($m[0]) as $token) {
            if (! in_array($token, self::PLACEHOLDERS, true)) {
                throw ValidationException::withMessages([
                    $field => __('المتغيّر :token غير معروف — المتاح: {next_open_date} و{next_open_time}.', ['token' => $token]),
                ]);
            }
        }
    }

    private static function normalize(array $data): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([self::KEY => $msg]);

        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $windows = $data['week'][$d] ?? [];
            if (! is_array($windows) || count($windows) > self::MAX_WINDOWS) {
                $fail(__('لكل يومٍ :n فترات استقبالٍ على الأكثر.', ['n' => self::MAX_WINDOWS]));
            }

            $clean = [];
            foreach (array_values($windows) as $w) {
                $from = is_array($w) ? (string) ($w['from'] ?? '') : '';
                $to = is_array($w) ? (string) ($w['to'] ?? '') : '';
                if (! preg_match(self::TIME, $from) || ! preg_match(self::TIME, $to)) {
                    $fail(__('اكتب ساعتي البداية والنهاية لكل فترة استقبال.'));
                }
                $clean[] = ['from' => $from, 'to' => $to];
            }
            usort($clean, fn ($a, $b) => strcmp($a['from'], $b['from']));
            $week[] = $clean;
        }

        $date = function (mixed $v) use ($fail): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            $v = (string) $v;
            $parsed = preg_match(self::DATE, $v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $v) {
                $fail(__('تاريخٌ غير صحيح في جدول الاستقبال.'));
            }

            return $v;
        };

        $starts = $date($data['starts'] ?? null);
        $ends = $date($data['ends'] ?? null);
        if ($starts !== null && $ends !== null && $ends < $starts) {
            $fail(__('تاريخ نهاية الاستقبال قبل تاريخ بدايته.'));
        }

        $closed = [];
        $ranges = $data['closed'] ?? [];
        if (! is_array($ranges) || count($ranges) > self::MAX_CLOSURES) {
            $fail(__('أيام الإغلاق :n على الأكثر.', ['n' => self::MAX_CLOSURES]));
        }
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

        return ['week' => $week, 'starts' => $starts, 'ends' => $ends, 'closed' => $closed];
    }
}

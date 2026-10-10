<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BankStatementLine;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * الفترةُ التي تقرؤها الشاشةُ ويحملها ملفُّها — قراءةٌ واحدة لرابطٍ واحد.
 *
 * ═══ ما كان ═══
 *
 * كلُّ تقريرٍ يقرأ `range` بنفسه: اليوم والأسبوع والشهر والسنة الجارية
 * و«الكل». فمن أراد سبتمبر الماضي أو سنة ٢٠٢٤ لم يجد إليهما بابًا — والملفُّ
 * يتبع الشاشة، فلا يخرج منه ما لا تعرضه. وشاشاتُ القوائم لكلٍّ منها لغتُها:
 * الطلباتُ من/إلى، والمصروفاتُ شهرٌ واحد، والحركةُ من/إلى أو `range`.
 *
 * ═══ ما هنا ═══
 *
 * رابطٌ واحد يُقرأ في مكانٍ واحد، فيعطي:
 *
 *   - `start` و`end` — النصف المفتوح [البداية، النهاية): «سبتمبر ٢٠٢٥» هو
 *     ٢٠٢٥-٠٩-٠١ ٠٠:٠٠ حتى ما قبل ٢٠٢٥-١٠-٠١ ٠٠:٠٠. لا «نهاية اليوم ٢٣:٥٩:٥٩»
 *     تُسقط الثانيةَ الأخيرة.
 *   - `label()` — ما يُكتب فوق الشاشة وفي رأس الملفّ: «سبتمبر 2025».
 *   - `fileParts()` — ما يُكتب في اسم الملفّ: `2025-09`.
 *   - `previous()` — الفترةُ السابقة المكافئة للمقارنة.
 *   - `params()` — الرابطُ منقّى: ما يخصّ هذه الفترة وحده.
 *
 * ═══ الرابط ═══
 *
 *   ?range=today|week|month|year|all          أزرارُ الفترات السريعة — كما كانت
 *   ?period=previous_month                     الشهر السابق
 *   ?period=month&month=2025-09                شهرٌ بعينه
 *   ?period=month_range&month_from=2025-03&month_to=2025-08
 *   ?period=year&year=2024                     سنةٌ كاملة
 *   ?period=custom&from=2025-09-10&to=2025-10-17   (الحدّان داخلان)
 *   ?period=all                                كل الفترات
 *
 * وتُفهم الروابطُ القديمة كما كانت: `from`/`to` وحدهما (الطلبات، الحركة،
 * التكاليف، الهالك) فترةٌ مخصّصة، و`month=YYYY-MM|all` (المصروفات) شهرٌ أو
 * الكلّ — لمن يقبلها من الشاشات (`$legacy`).
 *
 * ═══ الأسبقيّة ═══
 *
 *   `period` ← ثمّ `from`/`to` ← ثمّ `month` ← ثمّ `range` ← ثمّ الافتراضيّ.
 *
 * فرابطٌ قديمٌ فيه `range` ومن/إلى معًا يُقرأ بمن/إلى — كما كانت شاشةُ
 * الحركة تقرؤه. والواجهةُ لا تُبقي مفتاحًا من فترةٍ سابقة (`KEYS`).
 *
 * ═══ والخطأ يُقال ═══
 *
 * شهرٌ لا يُفهم، أو تاريخٌ لا يوجد، أو بدايةٌ بعد نهاية — يُردّ بـ٤٢٢ ورسالة.
 * لا يُبدَّل الحدّان صامتين، ولا يسقط الطلبُ إلى الشهر الجاري: ملفٌّ يقول
 * «سبتمبر» ويحمل أكتوبر أسوأ من رفضٍ واضح.
 *
 * و`range` المجهولة وحدها تسقط إلى الافتراضيّ كما كانت (`Demo::range`):
 * زرٌّ سريعٌ لا تاريخ فيه، وروابطُه محفوظةٌ عند الناس.
 *
 * ═══ ولا تغيير في الحساب ═══
 *
 * الأزرارُ السريعة تعطي البدايةَ نفسَها (`Demo::rangeStart`) بلا نهاية — كما
 * كانت حرفًا بحرف، فلا يتغيّر رقمٌ في رابطٍ قديم. والفتراتُ الجديدة تمرّر
 * حدّيها إلى الحساب نفسه (`Ledger`، `Profitability`…) — الحدودُ وحدها تتغيّر.
 */
final class ReportingPeriod
{
    /** كلُّ مفتاحٍ في الرابط يخصّ الفترة — يُمسح كلُّه عند اختيار غيرها */
    public const KEYS = ['range', 'period', 'month', 'month_from', 'month_to', 'year', 'from', 'to'];

    /** أنواعُ `period=` */
    public const KINDS = ['previous_month', 'month', 'month_range', 'year', 'custom', 'all'];

    /** أقصى عددِ أشهرٍ في النطاق — قرنٌ لا يُطلب من شاشة */
    private const MAX_MONTHS = 1200;

    private function __construct(
        /** `today|week|month|year|all` للأزرار السريعة، وإلّا أحدُ `KINDS` */
        public readonly string $kind,
        public readonly bool $preset,
        public readonly ?Carbon $start,
        /** النهايةُ مستثناة — `null` بلا حدّ */
        public readonly ?Carbon $end,
        /** ما يُكتب في الرابط لهذه الفترة وحدها */
        private readonly array $params,
    ) {}

    /* ============================== القراءة ============================== */

    /**
     * زرٌّ سريع — `today|week|month|year|all` — والمجهولُ إلى `$fallback`.
     *
     * البدايةُ من `Demo::rangeStart` ولا نهاية: ما كانت تقرؤه التقاريرُ قبل
     * هذا الملفّ بالضبط.
     */
    public static function preset(?string $range, string $fallback = 'month'): self
    {
        $range = Demo::range($range, $fallback);

        return new self($range, true, Demo::rangeStart($range), null, ['range' => $range]);
    }

    /**
     * من الرابط.
     *
     * @param  array<string, mixed>  $query
     * @param  string|self  $default  زرٌّ سريع أو فترةٌ جاهزة حين لا يُقال شيء
     * @param  list<'dates'|'month'>  $legacy  الروابطُ القديمة التي تقبلها الشاشة
     */
    public static function fromQuery(array $query, string|self $default = 'month', array $legacy = []): self
    {
        $get = fn (string $key): ?string => is_string($query[$key] ?? null) && trim($query[$key]) !== ''
            ? trim($query[$key])
            : null;

        if (($kind = $get('period')) !== null) {
            return self::canonical($kind, $get);
        }

        if (in_array('dates', $legacy, true) && ($get('from') !== null || $get('to') !== null)) {
            return self::custom($get('from'), $get('to'), open: true);
        }

        if (in_array('month', $legacy, true) && ($month = $get('month')) !== null) {
            return $month === 'all' ? self::all() : self::month($month);
        }

        if (($range = $get('range')) !== null) {
            return self::preset($range, $default instanceof self ? 'month' : $default);
        }

        return $default instanceof self ? $default : self::preset($default);
    }

    /** `period=…` وما يتبعه */
    private static function canonical(string $kind, callable $get): self
    {
        return match ($kind) {
            'previous_month' => self::previousMonth(),
            'month' => self::month($get('month') ?? self::refuse(__('اختر الشهر.'))),
            'month_range' => self::monthRange(
                $get('month_from') ?? self::refuse(__('اختر شهر البداية.')),
                $get('month_to') ?? self::refuse(__('اختر شهر النهاية.')),
            ),
            'year' => self::year($get('year') ?? self::refuse(__('اختر السنة.'))),
            'custom' => self::custom(
                $get('from') ?? self::refuse(__('اختر تاريخ البداية.')),
                $get('to') ?? self::refuse(__('اختر تاريخ النهاية.')),
            ),
            'all' => self::all(),
            default => self::refuse(__('نوع الفترة غير معروف.')),
        };
    }

    public static function all(): self
    {
        return new self('all', false, null, null, ['period' => 'all']);
    }

    public static function previousMonth(): self
    {
        $start = now()->startOfMonth()->subMonthNoOverflow();

        return new self('previous_month', false, $start, $start->copy()->addMonthNoOverflow(), ['period' => 'previous_month']);
    }

    /** شهرٌ بعينه — `YYYY-MM` */
    public static function month(string $month): self
    {
        $start = self::parseMonth($month);

        return new self('month', false, $start, $start->copy()->addMonthNoOverflow(), ['period' => 'month', 'month' => $start->format('Y-m')]);
    }

    /** نطاقُ أشهرٍ متّصل — والحدّان داخلان */
    public static function monthRange(string $from, string $to): self
    {
        $start = self::parseMonth($from);
        $last = self::parseMonth($to);

        if ($start->gt($last)) {
            self::refuse(__('شهر البداية بعد شهر النهاية.'));
        }

        if ($start->diffInMonths($last) >= self::MAX_MONTHS) {
            self::refuse(__('نطاق الأشهر أطول من المسموح.'));
        }

        return new self('month_range', false, $start, $last->copy()->addMonthNoOverflow(), [
            'period' => 'month_range', 'month_from' => $start->format('Y-m'), 'month_to' => $last->format('Y-m'),
        ]);
    }

    /** سنةٌ كاملة — `YYYY` */
    public static function year(string $year): self
    {
        if (! preg_match('/^\d{4}$/', $year) || (int) $year < 1970 || (int) $year > 2999) {
            self::refuse(__('السنة غير صالحة.'));
        }

        $start = Carbon::create((int) $year, 1, 1)->startOfDay();

        return new self('year', false, $start, $start->copy()->addYear(), ['period' => 'year', 'year' => $year]);
    }

    /**
     * فترةٌ بتاريخين — الحدّان داخلان للتاجر، والنهايةُ مستثناةٌ داخليًّا.
     *
     * و`$open` للروابط القديمة وحدها: «من» بلا «إلى» كانت تُقبل في الطلبات.
     */
    public static function custom(?string $from, ?string $to, bool $open = false): self
    {
        if (! $open && ($from === null || $to === null)) {
            self::refuse(__('اختر تاريخي البداية والنهاية.'));
        }

        $start = $from === null ? null : self::parseDate($from);
        $last = $to === null ? null : self::parseDate($to);

        if ($start !== null && $last !== null && $start->gt($last)) {
            self::refuse(__('تاريخ البداية بعد تاريخ النهاية.'));
        }

        /*
         * والرابطُ القديم يبقى بشكله: «من» وحدها لا تصير `period=custom` —
         * تلك تطلب الحدّين فتُرفض حين يعود الرابطُ إلى الخادم.
         */
        return new self('custom', false, $start, $last?->copy()->addDay(), array_filter([
            'period' => $open ? null : 'custom',
            'from' => $start?->toDateString(),
            'to' => $last?->toDateString(),
        ]));
    }

    private static function parseMonth(string $month): Carbon
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) || (int) $m[1] < 1970 || (int) $m[1] > 2999) {
            self::refuse(__('الشهر غير صالح — المطلوب سنة وشهر مثل 2025-09.'));
        }

        return Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
    }

    private static function parseDate(string $date): Carbon
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[1] < 1970 || (int) $m[1] > 2999) {
            self::refuse(__('التاريخ غير صالح: :date', ['date' => mb_substr($date, 0, 20)]));
        }

        return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
    }

    /** رفضٌ واضح — لا سقوطٌ صامتٌ إلى الشهر الجاري */
    private static function refuse(string $message): never
    {
        throw new HttpException(422, $message);
    }

    /* ============================== الاستعمال ============================== */

    /**
     * حدّا الفترة على عمود.
     *
     * و`$date` لعمودٍ بلا ساعة (`spent_at`، `entry_date`، `issued_at`،
     * `date`): يُقارن بالتاريخ نصًّا — `< '2025-10-01'` — كما يقارن `Ledger`.
     * فيصحّ على SQLite وهي تخزّن التاريخَ بساعته أو بدونها، وعلى PostgreSQL.
     *
     * @template T of QueryContract
     *
     * @param  T  $query
     * @return T
     */
    public function bound($query, string $column, bool $date = false)
    {
        /*
         * وحدّا الفترة الجديدة منتصفُ ليلٍ دائمًا، فيُكتبان تاريخًا على كلّ
         * عمود: `>= '2025-09-01'` و`< '2025-10-01'` تصدقان على عمودٍ بساعته
         * وبدونها، وعلى صفٍّ كُتب تاريخًا وحده في SQLite — كما كانت
         * `whereDate` تصدق في القوائم. والزرُّ السريع بقيمته كما كان.
         */
        $asDate = $date || ! $this->preset;

        if ($this->start !== null) {
            $query->where($column, '>=', $asDate ? $this->start->toDateString() : $this->start);
        }

        if ($this->end !== null) {
            $query->where($column, '<', $asDate ? $this->end->toDateString() : $this->end);
        }

        return $query;
    }

    /** أكان للفترة حدٌّ في الماضي يُغني عن التحديث الحيّ؟ — الأزرارُ السريعة وحدها حيّة */
    public function isLive(): bool
    {
        return $this->preset;
    }

    /** الزرُّ السريع المختار — أو `null` لفترةٍ غيره */
    public function range(): ?string
    {
        return $this->preset ? $this->kind : null;
    }

    /** أوّلُ يومٍ داخل الفترة — `Y-m-d` */
    public function fromDate(): ?string
    {
        return $this->start?->toDateString();
    }

    /** آخرُ يومٍ داخل الفترة — `Y-m-d` — ولزرٍّ سريعٍ اليوم */
    public function toDate(): ?string
    {
        if ($this->end !== null) {
            return $this->end->copy()->subDay()->toDateString();
        }

        return $this->start !== null ? now()->toDateString() : null;
    }

    /**
     * الفترةُ السابقة المكافئة — [البداية، النهاية) — أو `null` بلا مقارنة.
     *
     *   الأزرار السريعة   كما كانت (`Demo::rangePrev`)
     *   شهرٌ أو الشهر السابق   الشهرُ قبله
     *   نطاقُ ن أشهر       الأشهرُ الـن قبله
     *   سنة               السنةُ قبلها
     *   مخصّصة            الأيّامُ نفسُها عددًا، قبلها مباشرة
     *   الكل              لا مقارنة
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function previous(): ?array
    {
        if ($this->preset) {
            return Demo::rangePrev($this->kind);
        }

        if ($this->start === null || $this->end === null) {
            return null;
        }

        return self::before($this->start, $this->end);
    }

    /**
     * ما قبل [start, end) بالشكل نفسه: أشهرٌ كاملة → الأشهر قبلها، سنواتٌ كاملة
     * → السنوات قبلها، وإلّا الأيّامُ نفسُها عددًا.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function before(Carbon $start, Carbon $end): array
    {
        $wholeMonths = $start->day === 1 && $end->day === 1 && $start->isStartOfDay() && $end->isStartOfDay();

        if ($wholeMonths) {
            $months = (int) round($start->diffInMonths($end));

            return [$start->copy()->subMonthsNoOverflow($months), $start->copy()];
        }

        $days = (int) round($start->diffInDays($end));

        return [$start->copy()->subDays($days), $start->copy()];
    }

    /**
     * ما يُكتب فوق الشاشة وفي رأس الملفّ.
     *
     * والأزرارُ السريعة بنصّها القديم (`Demo::rangeLabel`): «هذا الشهر
     * (2026-10-01 — 2026-10-10)» — فلا يتغيّر رأسُ ملفٍّ كان يُصدَّر.
     */
    public function label(): string
    {
        // والزرُّ السريع أوّلًا: «month» و«year» و«all» أسماءُ أزرارٍ هنا لا أنواعُ `period`
        if ($this->preset) {
            return Demo::rangeLabel($this->kind);
        }

        $month = fn (Carbon $c) => $c->translatedFormat('F Y');
        $day = fn (Carbon $c) => $c->translatedFormat('j F Y');

        return match ($this->kind) {
            'previous_month', 'month' => $month($this->start),
            'month_range' => $this->start->format('Y-m') === $this->end->copy()->subMonthNoOverflow()->format('Y-m')
                ? $month($this->start)
                : $month($this->start).' – '.$month($this->end->copy()->subMonthNoOverflow()),
            'year' => (string) $this->start->year,
            'custom' => match (true) {
                $this->start !== null && $this->end !== null => $this->start->equalTo($this->end->copy()->subDay())
                    ? $day($this->start)
                    : $day($this->start).' – '.$day($this->end->copy()->subDay()),
                $this->start !== null => __('من :date', ['date' => $day($this->start)]),
                $this->end !== null => __('حتى :date', ['date' => $day($this->end->copy()->subDay())]),
                default => __('كل الفترات'),
            },
            'all' => __('كل الفترات'),
            default => Demo::rangeLabel($this->kind),
        };
    }

    /**
     * ما يُكتب في اسم الملفّ — إنجليزيٌّ آمن.
     *
     *   2025-09 · 2025-03-to-2025-08 · 2024 · 2025-09-10-to-2025-10-17 · all
     *
     * والزرُّ السريع باسمه (`month`، `year`…) كما كانت الملفّات تُسمّى.
     *
     * @return list<string>
     */
    public function fileParts(): array
    {
        if ($this->preset) {
            return [$this->kind];
        }

        return match ($this->kind) {
            'previous_month', 'month' => [$this->start->format('Y-m')],
            'month_range' => $this->params['month_from'] === $this->params['month_to']
                ? [$this->params['month_from']]
                : [$this->params['month_from'], 'to', $this->params['month_to']],
            'year' => [$this->params['year']],
            'custom' => [$this->fromDate() ?? 'start', 'to', $this->end?->copy()->subDay()->toDateString() ?? now()->toDateString()],
            default => [$this->kind],
        };
    }

    /** @return array<string, string> ما يخصّ هذه الفترة من الرابط */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * ما تقرؤه الواجهة.
     *
     * @return array{kind: string, range: ?string, label: string, params: array<string, string>, from: ?string, to: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'range' => $this->range(),
            'label' => $this->label(),
            'params' => $this->params,
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
        ];
    }

    /** كلُّ ما يختاره المنتقي — الأزرارُ الخمسة والأنواعُ كلُّها */
    public const EVERYTHING = [
        'presets' => Demo::RANGES, 'previous_month' => true, 'month' => true,
        'month_range' => true, 'year' => true, 'custom' => true, 'all' => true,
    ];

    /**
     * الفترةُ كما تقرؤها شاشة — ومعها ما يُختار منه والسنواتُ المتاحة.
     *
     * @param  array<string, mixed>|null  $capabilities  ما يظهر في المنتقي — وبلا قيدٍ كلُّه
     */
    public function screen(int $bid, ?array $capabilities = null): array
    {
        return $this->toArray() + [
            'capabilities' => $capabilities ?? self::EVERYTHING,
            'years' => self::years($bid, $this),
        ];
    }

    /**
     * كلُّ عمودِ تاريخٍ تُرشِّحه فترةُ تقريرٍ أو قائمة — ومن يقرؤه.
     *
     * فالمنتقي يصل إلى أقدم سنةٍ في أيٍّ منها: نشاطٌ بدأ بجردٍ أو أمرِ شراءٍ
     * أو كشفِ بنكٍ قبل أوّل طلب لا تختفي سنتُه من تقريرها. وتقريرٌ جديدٌ
     * يُرشِّح عمودًا غيرَها يُضاف هنا (`AnyMonthOrYearIsTheSameOnScreenAndInTheFileTest`).
     *
     * @var array<class-string, string>
     */
    public const SOURCES = [
        // المبيعات والطلبات والعملاء والموظفون والإضافات والمنتجات والتسويق والضريبة
        Order::class => 'ordered_at',
        // الربح والمبيعات (الدفتر) والتكاليف والخسائر
        JournalEntry::class => 'entry_date',
        // المصروفات — والتكاليف (المطابقة)
        Expense::class => 'spent_at',
        // الحركة المالية ووسائل الدفع
        Transaction::class => 'occurred_at',
        // الضريبة (المدخلات)
        SupplierInvoice::class => 'issued_at',
        // البنك
        BankStatementLine::class => 'date',
        // المشتريات والموردون
        PurchaseOrder::class => 'ordered_at',
        // الجرد والهالك
        StockAdjustment::class => 'adjusted_at',
        // سجلّ النشاط
        ActivityLog::class => 'created_at',
        // الهالك مقابل الاستهلاك
        InventoryMovement::class => 'created_at',
    ];

    /**
     * السنواتُ التي يُختار منها — من أوّل حركةٍ في النشاط حتى هذه السنة.
     *
     * لا قائمةٌ قصيرةٌ مكتوبة: متجرٌ عنده بيعٌ في ٢٠٢٢ يجب أن يختار ٢٠٢٢. وتُقرأ
     * من أقدم تاريخٍ في كلّ مصدرٍ تقرؤه التقارير (`SOURCES`) — استعلامُ `MIN`
     * لكلٍّ مقيّدًا بالنشاط.
     *
     * والفترةُ المفتوحةُ نفسُها داخلةٌ فيها: سنةٌ تُفتح بالرابط تُرى في
     * المنتقي ولا تختفي منه — ولو لم يكن فيها شيء.
     *
     * @return list<int>
     */
    public static function years(int $bid, ?self $period = null): array
    {
        $earliest = [];

        foreach (self::SOURCES as $model => $column) {
            $earliest[] = $model::where('business_id', $bid)->min($column);
        }

        $earliest = array_filter($earliest);

        $first = $earliest === []
            ? now()->year
            : min(array_map(fn ($d) => (int) substr((string) $d, 0, 4), $earliest));

        $first = max(1970, min($first, now()->year));
        $last = now()->year;

        if ($period?->start !== null) {
            $first = min($first, $period->start->year);
            // والنهايةُ مستثناة: سنةُ ٢٠٢٤ تنتهي في أوّل ٢٠٢٥ ولا تُدخلها
            $last = max($last, $period->end?->copy()->subSecond()->year ?? $last);
        }

        return range($last, $first);
    }
}

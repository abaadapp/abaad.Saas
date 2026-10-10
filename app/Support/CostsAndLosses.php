<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * التكاليفُ والخسائر — ما أنقص ربحَ النشاط في مدّةٍ، كما قيّده دفترُ الأستاذ.
 *
 * ═══ والدفترُ وحده المصدر ═══
 *
 * المجموعُ صافي سطور القيود المرحَّلة على حسابات «مصروف» — لا جدولُ
 * المصروفات ولا المسيرةُ ولا سنداتُ المورّدين ولا الأصول. فكلُّ حدثٍ يُعدّ
 * مرّةً واحدة: بقيده. ولو جُمعت الجداولُ فوقه لَعُدّ الهالكُ مرّتين (له قيدٌ
 * وصفُّ مصروف — `StockLosses::record`)، وعُدّ الراتبُ مرّةً عند الاعتماد
 * ومرّةً عند الصرف.
 *
 * ═══ وما يُقرأ من الدفتر ═══
 *
 *   - القيدُ المرحَّل (`posted`) — والترحيلُ يقع في `Ledger::post` نفسِها، فلا
 *     مسوّدةَ في الدفتر اليوم. والشرطُ يحرس من بابٍ يُفتح غدًا.
 *   - والإلغاءُ قيدٌ عكسيّ (`Ledger::reverse`): الأصلُ وعكسُه يبقيان، فيُجمعان
 *     فيصيران صفرًا. لا يُستثنى المعكوس ولا يُمحى.
 *   - والتاريخُ تاريخُ القيد (`entry_date`)، والفرعُ فرعُه (`branch_id`).
 *   - والمبلغُ مدينٌ ناقصَ دائن: المصروفُ يزيد بالمدين، ودائنُه (مردودٌ، أو
 *     زيادةُ جرد، أو عكس) يُنقصه.
 *   - والسطرُ على ورقةٍ لا على أب (`Account::isPostable`)، فيُقرأ بحسابه هو
 *     ولا يُجمع الأبُ فوقه.
 *
 * ═══ والتصنيفُ بمفتاح الحساب لا باسمه ═══
 *
 * `system_key` الذي يُرحّل إليه النظامُ نفسُه — والاسمُ يكتبه التاجر ويغيّره.
 * والخسارتان تُعرفان بقيدهما لا بحسابهما — كلتاهما تُرحَّل إلى «مصروفات
 * أخرى» مع غيرها:
 *
 *   - الهالكُ بمصدر قيده (`StockLosses::SOURCE`).
 *   - وخسارةُ استبعاد الأصل بمستند قيده (`sourceable_type = FixedAsset`): لا
 *     بابَ يُرحّل سطرَ مصروفٍ على قيدٍ مستندُه أصلٌ إلّا الاستبعاد
 *     (`FixedAssetController::dispose`). شراءُ الأصل أصلٌ مقابلَ نقدٍ أو بنكٍ أو
 *     ذمّة، والإهلاكُ قيدٌ بلا مستند — ويُستثنى حسابُه صراحةً على كلّ حال.
 *
 * ومصدرُ العكس ومستندُه مصدرُ أصله ومستندُه — العكسُ يكتب مصدرَه مترجَمًا
 * («عكس …»)، فيُقرأ أصلُه لا نصُّه. وما لا يُعرف مفتاحُه يقع في «مصروفات
 * وخسائر أخرى» ولا يسقط.
 *
 * ═══ وما لا يُختلق ═══
 *
 * إهلاكٌ لم يُرحَّل لا يُحسب من `FixedAsset` (الترحيلُ بزرّ «إهلاك» شهرًا
 * بشهر)، وخسارةُ استبعاد أصلٍ مبلغُها المرحَّل — لا تُحسب من قيمته
 * الدفتريّة. والقراءةُ لا تكتب شيئًا.
 */
final class CostsAndLosses
{
    public const COST_OF_SALES = 'cost_of_sales';

    public const EMPLOYEE = 'employee';

    public const OPERATING = 'operating';

    public const DEPRECIATION = 'depreciation';

    public const INVENTORY_LOSSES = 'inventory_losses';

    public const ASSET_DISPOSAL_LOSSES = 'asset_disposal_losses';

    public const OTHER = 'other';

    /** الفئاتُ بترتيب عرضها — وأسماؤها مفاتيحُ ترجمة */
    public const CATEGORIES = [
        self::COST_OF_SALES => 'تكلفة المبيعات',
        self::EMPLOYEE => 'تكاليف الموظفين',
        self::OPERATING => 'مصروفات التشغيل',
        self::DEPRECIATION => 'الإهلاك',
        self::INVENTORY_LOSSES => 'خسائر المخزون',
        self::ASSET_DISPOSAL_LOSSES => 'خسائر استبعاد الأصول',
        self::OTHER => 'مصروفات وخسائر أخرى',
    ];

    /**
     * المفتاحُ النظاميّ ← الفئة. وما ليس هنا «أخرى».
     *
     * و«مشتريات مباشرة» تشغيلٌ لا تكلفةُ مبيعات: تلك تُرحَّل من البيع بلقطة
     * التكلفة (`Books::recordSale`)، و«المباشرةُ» مصروفٌ يكتبه التاجر.
     */
    private const BY_KEY = [
        'cogs' => self::COST_OF_SALES,
        'salaries' => self::EMPLOYEE,
        'depreciation' => self::DEPRECIATION,
        'rent' => self::OPERATING,
        'utilities' => self::OPERATING,
        'marketing' => self::OPERATING,
        'maintenance' => self::OPERATING,
        'transport' => self::OPERATING,
        'direct_purchases' => self::OPERATING,
    ];

    /** بطاقةُ «مصروفات التشغيل»: الموظفون والتشغيلُ والإهلاكُ المرحَّل */
    public const OPERATING_GROUP = [self::EMPLOYEE, self::OPERATING, self::DEPRECIATION];

    /** بطاقةُ «الخسائر»: ما يُعرف خسارةً بقيده — الهالكُ واستبعادُ الأصل */
    public const LOSS_GROUP = [self::INVENTORY_LOSSES, self::ASSET_DISPOSAL_LOSSES];

    /** نوعُ الحساب كما يُكتب في الشجرة (`Account::TYPES`) */
    private const EXPENSE_TYPE = 'مصروف';

    /** أسطرُ التفصيل في الصفحة الواحدة */
    public const PER_PAGE = 50;

    /* ═══════════ النطاق ═══════════ */

    /**
     * المرشّحاتُ كما تُقرأ — مدّةٌ بحدّين، وفرعٌ، وفئة.
     *
     * المدّةُ الافتراضيّة شهرٌ إلى اليوم — كتحليلات الهالك، وهي المدّةُ ذاتُ
     * الحدّين الوحيدة في التقارير. وتاريخٌ لا يُقرأ يسقط إلى الافتراض، ومدّةٌ
     * مقلوبةٌ تُعدَل. والفرعُ يُسأل عنه هنا: من متجرٍ آخر ⇒ ٤٠٤، ومن قُيِّد
     * بفروعٍ (`User::branches`) لا يُعطى غيرَها ⇒ ٤٠٣، ولا يرى بلا فرعٍ إلّا
     * فروعَه — فلا قيودُ النشاط العامّة (بلا فرع) ولا قيودُ غيرها.
     *
     * و«كل الفترات» (`_period` بلا بداية — `Reports::period`) حدّاها
     * `null`: العمرُ كلُّه، لا شهرٌ إلى اليوم.
     *
     * @return array{from: ?string, to: ?string, branch_id: ?int, category: ?string, branch_ids: ?list<int>}
     */
    public static function scope(int $bid, array $filters, ?User $user): array
    {
        $period = $filters['_period'] ?? null;

        if ($period instanceof ReportingPeriod && $period->start === null) {
            $from = $to = null;
        } else {
            $from = self::date($filters['from'] ?? null) ?? now()->startOfMonth()->toDateString();
            $to = self::date($filters['to'] ?? null) ?? now()->toDateString();

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
        }

        $allowed = self::allowedBranches($bid, $user);
        $branchId = null;
        $raw = $filters['branch_id'] ?? null;

        if ($raw !== null && $raw !== '') {
            $id = filter_var($raw, FILTER_VALIDATE_INT);
            $exists = $id !== false
                && Branch::withTrashed()->where('business_id', $bid)->whereKey($id)->exists();
            abort_unless($exists, 404);
            abort_if($allowed !== null && ! in_array($id, $allowed, true), 403);
            $branchId = (int) $id;
        }

        $category = $filters['category'] ?? null;

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'category' => is_string($category) && array_key_exists($category, self::CATEGORIES) ? $category : null,
            // `null` بلا قيد — ومن قُيِّد يُحصر في فروعه حين لا يختار فرعًا
            'branch_ids' => $branchId === null ? $allowed : null,
        ];
    }

    /**
     * فروعُ من قُيِّد بها — أو `null` لمن لم يُقيَّد.
     *
     * القيدُ يُقرأ من صفوفه الخام بالمحذوف فيها، كما يقرؤه `User::worksAt`:
     * من قُيِّد بفرعٍ زال لا يصير بلا قيد.
     *
     * @return list<int>|null
     */
    public static function allowedBranches(int $bid, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $ids = $user->branches()->withTrashed()->where('branches.business_id', $bid)
            ->pluck('branches.id')->map(fn ($id) => (int) $id)->all();

        $any = $user->branches()->withTrashed()->exists();

        return $any ? $ids : null;
    }

    private static function date(mixed $raw): ?string
    {
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
    }

    /**
     * المدّةُ السابقة المكافئة — بطولها، وتنتهي يومًا قبل بداية هذه.
     *
     * و«كل الفترات» لا سابقَ لها: `null`، لا مدّةٌ مختلَقةٌ تُقارَن بها.
     */
    public static function previous(array $scope): ?array
    {
        if ($scope['from'] === null || $scope['to'] === null) {
            return null;
        }

        return array_merge($scope, Waste::previousWindow($scope['from'], $scope['to']));
    }

    /* ═══════════ السطور ═══════════ */

    /**
     * سطورُ المدّة والنطاق، كلٌّ بفئته وصافيه — الاستعلامُ الذي يقرؤه الجميع.
     *
     * الملخّصُ والجدولُ والتفصيلُ والملفّاتُ تقرأ من هنا، فلا يفترق رقمٌ عن
     * سطوره. والتاريخُ يُقارن بنهايةٍ مفتوحة (`< اليوم التالي`): SQLite تحفظ
     * عمود التاريخ بساعته، فـ`<= to` يُسقط آخرَ يوم.
     */
    public static function lines(int $bid, array $scope): Builder
    {
        $pdo = DB::getPdo();
        $category = 'CASE'
            .' WHEN COALESCE(orig.source, je.source) = '.$pdo->quote(StockLosses::SOURCE)." THEN '".self::INVENTORY_LOSSES."'"
            .' WHEN COALESCE(orig.sourceable_type, je.sourceable_type) = '.$pdo->quote(FixedAsset::class)
            ." AND (a.system_key IS NULL OR a.system_key <> 'depreciation') THEN '".self::ASSET_DISPOSAL_LOSSES."'"
            .self::keyCases()
            ." ELSE '".self::OTHER."' END";

        $inner = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->leftJoin('journal_entries as orig', 'orig.id', '=', 'je.reverses_id')
            ->leftJoin('branches as b', 'b.id', '=', 'je.branch_id')
            ->where('je.business_id', $bid)
            ->where('a.business_id', $bid)
            ->where('je.posted', true)
            ->where('a.type', self::EXPENSE_TYPE)
            ->when($scope['from'] !== null, fn ($q) => $q->where('je.entry_date', '>=', $scope['from']))
            ->when($scope['to'] !== null, fn ($q) => $q->where('je.entry_date', '<', Carbon::parse($scope['to'])->addDay()->toDateString()))
            ->when($scope['branch_id'] !== null, fn ($q) => $q->where('je.branch_id', $scope['branch_id']))
            ->when($scope['branch_ids'] !== null, fn ($q) => $q->whereIn('je.branch_id', $scope['branch_ids']))
            ->select([
                'jl.id as line_id', 'je.id as entry_id', 'je.number', 'je.entry_date', 'je.description',
                'je.source', 'je.sourceable_type', 'je.sourceable_id', 'je.reverses_id',
                'b.name as branch', 'jl.account_id', 'a.code', 'a.name', 'a.name_en', 'jl.memo',
            ])
            ->selectRaw('(jl.debit - jl.credit) as net')
            ->selectRaw($category.' as category');

        return DB::query()->fromSub($inner, 'x')
            ->when($scope['category'] !== null, fn ($q) => $q->where('x.category', $scope['category']));
    }

    /** `WHEN a.system_key IN (...) THEN 'فئة'` لكلّ فئةٍ بمفتاح */
    private static function keyCases(): string
    {
        $sql = '';

        foreach (array_unique(self::BY_KEY) as $category) {
            $keys = array_keys(array_filter(self::BY_KEY, fn ($c) => $c === $category));
            $sql .= ' WHEN a.system_key IN ('.implode(',', array_map(fn ($k) => "'".$k."'", $keys)).") THEN '".$category."'";
        }

        return $sql;
    }

    /**
     * صافي كلّ حسابٍ في كلّ فئة — استعلامٌ مجمَّعٌ واحد.
     *
     * @return array<string, array{category: string, account_id: int, code: string, name: string, name_en: ?string, amount: float}>
     */
    public static function amounts(int $bid, array $scope): array
    {
        $rows = self::lines($bid, $scope)
            ->groupBy('x.category', 'x.account_id', 'x.code', 'x.name', 'x.name_en')
            ->get(['x.category', 'x.account_id', 'x.code', 'x.name', 'x.name_en', DB::raw('SUM(x.net) as amount')]);

        $out = [];

        foreach ($rows as $r) {
            $out[$r->category.':'.$r->account_id] = [
                'category' => (string) $r->category,
                'account_id' => (int) $r->account_id,
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'name_en' => $r->name_en,
                'amount' => round((float) $r->amount, 3),
            ];
        }

        return $out;
    }

    /* ═══════════ التقرير ═══════════ */

    /**
     * الملخّصُ والفئاتُ وحساباتُها، مع المدّة السابقة — كلُّ رقمٍ يُجمع من صفوفه.
     *
     * الحسابُ صافيه المقرَّب، والفئةُ مجموعُ حساباتها، والإجماليُّ مجموعُ
     * الفئات. فلا رقمَ في الصفحة لا يُطابق ما تحته.
     */
    public static function report(int $bid, array $scope): array
    {
        $current = self::amounts($bid, $scope);
        // و«كل الفترات» بلا سابق: المقارنةُ كلُّها `null` لا أصفارٌ تُقرأ أرقامًا
        $window = self::previous($scope);
        $compared = $window !== null;
        $previous = $compared ? self::amounts($bid, $window) : [];

        $categories = [];
        $sums = ['current' => [], 'previous' => []];

        foreach (self::CATEGORIES as $key => $label) {
            $rows = [];

            foreach (array_unique(array_merge(array_keys($current), array_keys($previous))) as $id) {
                $row = $current[$id] ?? $previous[$id];

                if ($row['category'] !== $key) {
                    continue;
                }

                $rows[] = [
                    'category' => $key,
                    'account_id' => $row['account_id'],
                    'code' => $row['code'],
                    'account' => self::accountName($row['name'], $row['name_en']),
                    'current' => $current[$id]['amount'] ?? 0.0,
                    'previous' => $compared ? ($previous[$id]['amount'] ?? 0.0) : null,
                ];
            }

            usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

            $sums['current'][$key] = round(array_sum(array_column($rows, 'current')), 3);
            $sums['previous'][$key] = round(array_sum(array_column($rows, 'previous')), 3);

            $categories[] = [
                'key' => $key,
                'label' => __($label),
                'current' => $sums['current'][$key],
                'previous' => $compared ? $sums['previous'][$key] : null,
                'rows' => $rows,
            ];
        }

        $summary = self::summary($sums['current']);
        $before = $compared ? self::summary($sums['previous']) : null;
        $total = $summary['total'];

        foreach ($categories as &$category) {
            $category += self::change($category['current'], $category['previous'], $total);

            foreach ($category['rows'] as &$row) {
                $row += self::change($row['current'], $row['previous'], $total);
            }
            unset($row);
        }
        unset($category);

        return [
            'summary' => $summary,
            'previousSummary' => $before,
            'comparison' => array_map(fn (string $key) => [
                'key' => $key,
                'current' => $summary[$key],
                'previous' => $before[$key] ?? null,
            ] + self::change($summary[$key], $before[$key] ?? null, null), array_keys($summary)),
            'categories' => $categories,
        ];
    }

    /**
     * البطاقات — كلٌّ مجموعُ فئاتٍ بعينها، والإجماليُّ مجموعُها كلِّها.
     *
     * و«الخسائر» ما يُعرف خسارةً بقيده بيقين: الهالكُ بمصدره، واستبعادُ الأصل
     * بمستنده. وما سواهما في «مصروفات أخرى» يبقى في «الأخرى» ولا يُخمَّن.
     *
     * @param  array<string, float>  $byCategory
     * @return array{total: float, cost_of_sales: float, operating: float, losses: float, other: float}
     */
    private static function summary(array $byCategory): array
    {
        $sum = fn (array $keys) => round(array_sum(array_map(fn ($k) => $byCategory[$k] ?? 0.0, $keys)), 3);

        return [
            'total' => $sum(array_keys(self::CATEGORIES)),
            'cost_of_sales' => $sum([self::COST_OF_SALES]),
            'operating' => $sum(self::OPERATING_GROUP),
            'losses' => $sum(self::LOSS_GROUP),
            'other' => $sum([self::OTHER]),
        ];
    }

    /**
     * الفرقُ ونسبتُه، وحصّةُ الإجماليّ.
     *
     * والنسبةُ من سابقٍ موجبٍ وحده: من صفرٍ لا نهاية لها، ومن سالبٍ تنقلب
     * إشارتُها — فـ`null` تُكتب «—». والحصّةُ من إجماليٍّ موجبٍ كذلك.
     * وبلا سابقٍ أصلًا («كل الفترات») لا فرقَ ولا نسبة.
     */
    private static function change(float $current, ?float $previous, ?float $total): array
    {
        $out = [
            'delta' => $previous === null ? null : round($current - $previous, 3),
            'change_pct' => $previous !== null && $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];

        if ($total !== null) {
            $out['share'] = $total > 0 ? round($current / $total * 100, 1) : null;
        }

        return $out;
    }

    /** اسمُ الحساب بلغة الصفحة — الإنجليزيُّ المكتوبُ إن وُجد، وإلّا ترجمةُ الاسم */
    public static function accountName(string $name, ?string $nameEn): string
    {
        return app()->getLocale() === 'en' && filled($nameEn) ? (string) $nameEn : __($name);
    }

    /* ═══════════ التفصيل ═══════════ */

    /**
     * سطورُ حسابٍ في فئة — صفحةً صفحة، ومجموعُها كلِّها معها.
     *
     * والمجموعُ من الاستعلام نفسِه بلا تقسيم، فيُطابق صفَّ الحساب في الجدول
     * بالمرشّحات نفسِها. والحسابُ من متجرٍ آخر لا يجد سطرًا: الاستعلامُ مقيّدٌ
     * بالمتجر في القيد وفي الحساب.
     *
     * @return array{total: float, count: int, page: int, last_page: int, lines: list<array<string, mixed>>}
     */
    public static function drill(int $bid, array $scope, ?int $accountId, int $page = 1): array
    {
        $query = self::lines($bid, $scope)
            ->when($accountId !== null, fn ($q) => $q->where('x.account_id', $accountId));

        $total = round((float) (clone $query)->sum('x.net'), 3);
        $count = (clone $query)->count();
        $last = max(1, (int) ceil($count / self::PER_PAGE));
        $page = min(max(1, $page), $last);

        $lines = (clone $query)
            ->orderBy('x.entry_date')->orderBy('x.entry_id')->orderBy('x.line_id')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn ($l) => [
                'id' => (int) $l->line_id,
                'date' => substr((string) $l->entry_date, 0, 10),
                'number' => $l->number,
                'description' => $l->description,
                'memo' => $l->memo,
                'branch' => $l->branch,
                'account' => self::accountName((string) $l->name, $l->name_en),
                'code' => $l->code,
                'category' => __(self::CATEGORIES[$l->category] ?? self::CATEGORIES[self::OTHER]),
                'net' => round((float) $l->net, 3),
                'source' => __((string) $l->source),
                'reference' => $l->sourceable_type
                    ? class_basename((string) $l->sourceable_type).' #'.$l->sourceable_id
                    : null,
                'reversal' => $l->reverses_id !== null,
            ])->all();

        return ['total' => $total, 'count' => $count, 'page' => $page, 'last_page' => $last, 'lines' => $lines];
    }

    /* ═══════════ المطابقة ═══════════ */

    /**
     * مصروفاتٌ في جدولها خارجَ هذا التقرير — تُقال ولا تُضاف.
     *
     *   - مدفوعةٌ بلا قيدٍ حيّ: سُجّلت قبل أن يصير المصروفُ يُرحَّل في
     *     معاملته (`ExpenseController::postToLedger`) أو أخفق ترحيلُها يومها.
     *   - غيرُ مدفوعة: المصروفُ يدخل الدفترَ يومَ يُدفع لا يومَ يُسجَّل.
     *
     * والتقريرُ لا يجمعها: الدفترُ مصدرُه. لكنّ صاحبَ النشاط يقارنه بشاشة
     * «المصروفات» — فيُقال له ما الفرق ومن أين.
     *
     * @return array{unposted_count: int, unposted_amount: float, unpaid_count: int, unpaid_amount: float}
     */
    public static function reconciliation(int $bid, array $scope): array
    {
        $base = Expense::where('expenses.business_id', $bid)
            ->when($scope['from'] !== null, fn ($q) => $q->where('spent_at', '>=', $scope['from']))
            ->when($scope['to'] !== null, fn ($q) => $q->where('spent_at', '<', Carbon::parse($scope['to'])->addDay()->toDateString()))
            ->when($scope['branch_id'] !== null, fn ($q) => $q->where('expenses.branch_id', $scope['branch_id']))
            ->when($scope['branch_ids'] !== null, fn ($q) => $q->whereIn('expenses.branch_id', $scope['branch_ids']));

        $live = JournalEntry::query()->select('sourceable_id')
            ->where('business_id', $bid)
            ->where('sourceable_type', Expense::class)
            ->whereNull('reversed_at')->whereNull('reverses_id');

        $unposted = (clone $base)->paid()->whereNotIn('expenses.id', $live);
        $unpaid = (clone $base)->where('status', '!=', Expense::PAID);

        return [
            'unposted_count' => (clone $unposted)->count(),
            'unposted_amount' => round((float) (clone $unposted)->sum('amount'), 3),
            'unpaid_count' => (clone $unpaid)->count(),
            'unpaid_amount' => round((float) (clone $unpaid)->sum('amount'), 3),
        ];
    }
}

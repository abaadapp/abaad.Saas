<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * صافي الربح — للنشاط كلِّه أو لفرعٍ بعينه، من موضعٍ واحد.
 *
 * ═══ التعريفُ نفسُه في النظام كلّه ═══
 *
 *   صافي الإيرادات = المبيعات − الضريبة
 *   مجمل الربح     = صافي الإيرادات − تكلفة البضاعة المباعة
 *   صافي الربح     = مجمل الربح − المصروفات التشغيليّة
 *   هامش صافي الربح = صافي الربح ÷ صافي الإيرادات × ١٠٠
 *
 * والمبيعاتُ ما بيع فعلًا (`Order::sold`)، والتكلفةُ من `Demo::cogsFor` —
 * لقطةُ التكلفة يوم البيع لا بطاقةُ اليوم — والمصروفُ للنشاط كلِّه من الدفتر
 * (`Ledger::operatingExpenses`). فصافي ربح النشاط كلِّه هنا هو
 * `Demo::reportSummary` حرفًا، وهو رقمُ اللوحة والمالية.
 *
 * ═══ ومصروفاتُ الفرع ما نُسب إليه وحده ═══
 *
 * المباشرُ عليه، وحصّتُه من الموزَّع. أمّا المصروفُ العامّ الذي لم يُوزَّع
 * فلا يُطرح منه — ولا يُقسَم بالتخمين، لا بالتساوي ولا بنسبة المبيعات. يُقال
 * إلى جانبه «مصروفاتٌ عامّة غير موزّعة» بمبلغها وعددها، فلا يُقرأ ربحُ
 * الفرع على أنّه يحمل كلفةَ المتجر كلِّها وهو لا يحملها.
 *
 * والمصروفُ الموزَّع يُعدّ للنشاط مرّةً واحدة: صفوفُ توزيعه نسبةٌ تحليليّة
 * تُقرأ في الفرع وحده، لا مبلغٌ يُضاف إلى المجموع.
 */
final class Profitability
{
    /**
     * أرقامُ فترةٍ لنطاقٍ — `[$start, $end)`، و`null` بلا حدّ.
     *
     * @return array{sales: float, tax: float, net_revenue: float, cogs: float, gross_profit: float, expenses: float, net_profit: float, margin: ?float, unallocated: float, unallocated_count: int}
     */
    public static function summary(int $bid, ?Carbon $start, ?Carbon $end = null, ?int $branchId = null): array
    {
        $orders = self::orders($bid, $start, $end, $branchId);
        $sales = (float) (clone $orders)->sum('total');
        $tax = (float) (clone $orders)->sum('tax');
        $cogs = Demo::cogsFor($bid, $start, $end, $branchId);

        /*
         * والنشاطُ كلُّه من الدفتر: المصروفاتُ التشغيليّة (`Ledger::operatingExpenses`)
         * — اليدويُّ المدفوع والرواتبُ والإهلاك — لا جدولُ المصروفات وحده.
         *
         * والفرعُ على ما كان: المباشرُ وحصّتُه من الموزَّع. الدفترُ يحمل فرعًا
         * واحدًا للقيد ولا حصصَ فيه (الموزَّعُ قيدُه على النشاط —
         * `ExpenseScope::journalBranch`)، فقراءةُ الفرع منه تُسقط حصصَه.
         */
        $expenses = $branchId === null
            ? Ledger::operatingExpenses($bid, $start, $end)
            : self::attributed($bid, $start, $end, $branchId);

        // والعامُّ غيرُ الموزّع يُقال للفرع ولا يُطرح منه — وللنشاط هو من مصروفاته أصلًا
        $unallocated = $branchId === null ? null : self::unallocated($bid, $start, $end);

        return self::derive($sales, $tax, $cogs, $expenses) + [
            'unallocated' => round((float) ($unallocated['amount'] ?? 0), 3),
            'unallocated_count' => (int) ($unallocated['count'] ?? 0),
        ];
    }

    /**
     * الأرقامُ المشتقّة من الأربعة — موضعٌ واحد للصفّ والجملة والمقارنة.
     *
     * والهامشُ يُشتقّ لا يُجمع: هامشُ الشهر صافيه على صافي إيراداته، لا
     * متوسّطُ هوامش أيّامه. وبلا إيرادٍ موجب لا هامش (`null`) — نسبةٌ من صفر
     * لا تعني شيئًا، ومن سالبٍ تقلب إشارتها.
     *
     * @return array{sales: float, tax: float, net_revenue: float, cogs: float, gross_profit: float, expenses: float, net_profit: float, margin: ?float}
     */
    public static function derive(float $sales, float $tax, float $cogs, float $expenses): array
    {
        $netRevenue = round($sales - $tax, 3);
        $gross = round($netRevenue - $cogs, 3);
        $net = round($gross - $expenses, 3);

        return [
            'sales' => round($sales, 3),
            'tax' => round($tax, 3),
            'net_revenue' => $netRevenue,
            'cogs' => round($cogs, 3),
            'gross_profit' => $gross,
            'expenses' => round($expenses, 3),
            'net_profit' => $net,
            'margin' => $netRevenue > 0 ? round($net / $netRevenue * 100, 1) : null,
        ];
    }

    /** الطلباتُ المباعة في الفترة والنطاق */
    public static function orders(int $bid, ?Carbon $start, ?Carbon $end = null, ?int $branchId = null)
    {
        return Order::where('business_id', $bid)->sold()
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('ordered_at', '<', $end))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    /**
     * المصروفاتُ المدفوعة في الفترة — كلُّ مصروفٍ مرّةً واحدة.
     *
     * والتاريخُ يُقارَن تاريخًا: `spent_at` عمودُ يوم، وقياسُه ببداية الفترة
     * ساعةً (`00:00:00`) يُسقط على SQLite مصروفَ اليوم الأوّل — نصٌّ أقصرُ من
     * نصّ — وعلى PostgreSQL لا يُسقطه. فالمقارنةُ بالتاريخ وحده تصدق على الاثنين.
     */
    public static function expenses(int $bid, ?Carbon $start, ?Carbon $end = null)
    {
        return Expense::where('expenses.business_id', $bid)->paid()
            ->when($start, fn ($q) => $q->where('spent_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->where('spent_at', '<', $end->toDateString()));
    }

    /** مصروفاتُ الفرع: المباشرُ عليه وحصّتُه من الموزَّع */
    public static function attributed(int $bid, ?Carbon $start, ?Carbon $end, int $branchId): float
    {
        $direct = (float) self::expenses($bid, $start, $end)->where('expenses.branch_id', $branchId)->sum('amount');

        return round($direct + (float) self::shares($bid, $start, $end, $branchId)->sum('a.amount'), 3);
    }

    /**
     * حصصُ فرعٍ من المصروفات الموزَّعة المدفوعة — والمحذوفُ لا حصّةَ له.
     *
     * الحصّةُ لا تُحسب وحدها: تُقرأ من مصروفها الأب، فما حُذف منه (`deleted_at`)
     * أو لم يُدفع بعد لا يُقرأ له شيء.
     */
    public static function shares(int $bid, ?Carbon $start, ?Carbon $end, int $branchId)
    {
        return self::expenses($bid, $start, $end)
            ->join('expense_branch_allocations as a', 'a.expense_id', '=', 'expenses.id')
            ->where('a.branch_id', $branchId);
    }

    /**
     * المصروفاتُ العامّة غير الموزّعة — لا فرعَ لها ولا توزيع.
     *
     * @return array{amount: float, count: int}
     */
    public static function unallocated(int $bid, ?Carbon $start, ?Carbon $end = null): array
    {
        $q = self::expenses($bid, $start, $end)->whereNull('expenses.branch_id')->whereDoesntHave('allocations');

        return ['amount' => round((float) (clone $q)->sum('amount'), 3), 'count' => (clone $q)->count()];
    }

    /**
     * صفوفُ الزمن — ساعاتُ اليوم، أو أيّامُ الأسبوع والشهر، أو أشهرُ السنة والعمر.
     *
     * والمصروفُ يقع في عمود يومه (`spent_at`) كاملًا، وحصصُه كذلك: لا يُفرَش
     * إيجارُ الشهر على أيّامه. ويومُ المصروف لا ساعةَ فيه، فيقع في «اليوم» على
     * أوّل ساعاته.
     *
     * واستعلامٌ واحد لكلّ رقمٍ لا لكلّ عمود — والتكلفةُ من `Demo::cogsByBucket`،
     * وهي `cogsFor` نفسُها مقسومة.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(int $bid, string $range, ?int $branchId = null): array
    {
        [$unit, $axisStart, $axisEnd] = self::axis($bid, $range, $branchId);
        $start = Demo::rangeStart($range);

        $orderBucket = Demo::bucketSql('ordered_at', $unit);
        $orders = self::orders($bid, $start, null, $branchId)
            ->selectRaw("{$orderBucket} as bucket, SUM(total) as s, SUM(tax) as t")
            ->groupBy('bucket')->get()->keyBy('bucket');

        $cogs = Demo::cogsByBucket($bid, $start, null, $branchId, null, $unit);

        // ويومُ المصروف لا ساعةَ فيه: يقع في أوّل ساعات اليوم
        $expenseBucket = $unit === 'hour' ? "'00'" : Demo::bucketSql('spent_at', $unit);
        // والنشاطُ كلُّه من الدفتر بتاريخ القيد — كجملته في `summary`، فيساوي مجموعُ الصفوف جملتَها
        $expenses = $branchId === null
            ? Ledger::operatingExpensesByBucket($bid, $unit === 'hour' ? "'00'" : Demo::bucketSql('journal_entries.entry_date', $unit), $start)
            : self::bucketSums(
                self::expenses($bid, $start)->where('expenses.branch_id', $branchId)
                    ->selectRaw("{$expenseBucket} as bucket, SUM(amount) as v"),
            );

        if ($branchId !== null) {
            foreach (self::bucketSums(self::shares($bid, $start, null, $branchId)->selectRaw("{$expenseBucket} as bucket, SUM(a.amount) as v")) as $key => $v) {
                $expenses[$key] = ($expenses[$key] ?? 0.0) + $v;
            }
        }

        $rows = [];
        $cursor = $axisStart->copy();
        $first = true;

        while ($cursor->lte($axisEnd)) {
            [$key, $label] = match ($unit) {
                'hour' => [$cursor->format('H'), $cursor->format('H').':00'],
                'month' => [$cursor->format('Y-m'), $cursor->translatedFormat('F Y')],
                default => [$cursor->format('Y-m-d'), $cursor->translatedFormat('l j F')],
            };

            $o = $orders[$key] ?? null;
            $spent = (float) ($expenses[$key] ?? 0);

            /*
             * والمصروفُ بلا تاريخ يقع في أوّل أعمدة «كل الفترات» — هو فيها
             * ولا يومَ له. وبلا ذلك يُعدّ في الجملة ويغيب عن الصفوف، فلا يساوي
             * مجموعُها جملتَها.
             */
            if ($first && $range === 'all') {
                $spent += (float) ($expenses[''] ?? 0);
            }

            $rows[] = ['key' => $key, 'period' => $label] + self::derive(
                (float) ($o->s ?? 0),
                (float) ($o->t ?? 0),
                (float) ($cogs[$key] ?? 0),
                $spent,
            );

            $first = false;
            $cursor = match ($unit) {
                'hour' => $cursor->addHour(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $rows;
    }

    /**
     * محورُ الزمن ودقّتُه — والعمرُ كلُّه من أوّل حركةٍ في النطاق.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    public static function axis(int $bid, string $range, ?int $branchId = null): array
    {
        return match ($range) {
            'today' => ['hour', now()->startOfDay(), now()->startOfDay()->setTime(23, 0)],
            'week' => ['day', now()->startOfWeek(Demo::WEEK_START)->startOfDay(), now()->endOfWeek(Demo::WEEK_END)->startOfDay()],
            'year' => ['month', now()->startOfYear(), now()->endOfYear()->startOfMonth()],
            'all' => ['month', self::firstMonth($bid, $branchId), now()->startOfMonth()],
            default => ['day', now()->startOfMonth(), now()->endOfMonth()->startOfDay()],
        };
    }

    /** أوّلُ شهرٍ فيه بيعٌ أو مصروفٌ في النطاق — أو هذا الشهر */
    private static function firstMonth(int $bid, ?int $branchId): Carbon
    {
        $order = self::orders($bid, null, null, $branchId)->min('ordered_at');
        // وأوّلُ مصروفٍ من مصدره: الدفترُ للنشاط كلِّه، والجدولُ للفرع
        $expense = $branchId === null
            ? Ledger::operatingExpenseLines($bid)->min('journal_entries.entry_date')
            : self::expenses($bid, null)->min('spent_at');

        $dates = array_filter([$order, $expense]);
        $first = $dates === [] ? now() : Carbon::parse(min(array_map(fn ($d) => Carbon::parse($d)->toDateTimeString(), $dates)));

        return $first->copy()->startOfMonth()->min(now()->startOfMonth());
    }

    /** @return array<string, float> */
    private static function bucketSums($query): array
    {
        return $query->groupBy('bucket')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->bucket => (float) $r->v])->all();
    }
}

<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * بيانات لوحة التاجر التي يجب أن تتبع الفرع المختار.
 *
 * التقارير العامة قد تقرأ النشاط كله عمدًا، أما لوحة التحكم فتجمع بطاقات
 * وأحدث طلبات ومخططات في شاشة واحدة؛ لذلك يجب أن تشترك كلها في نطاق الفرع.
 */
class DashboardMetrics
{
    /** مخطط مبيعات السنة الجارية، مع فراغ الأشهر المستقبلية كما في Demo::salesTrend. */
    public static function salesYear(): array
    {
        $bid = Demo::bid();
        $branch = Demo::currentBranchId();
        $start = now()->startOfYear();
        $end = now()->endOfYear();
        $cutoff = now()->copy();
        $driver = DB::connection()->getDriverName();

        $format = match ($driver) {
            'pgsql' => "to_char(ordered_at, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT(ordered_at, '%Y-%m')",
            default => "strftime('%Y-%m', ordered_at)",
        };

        $rows = Order::where('business_id', $bid)
            ->sold()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->whereBetween('ordered_at', [$start, $cutoff])
            ->selectRaw("{$format} as bucket, SUM(total) as s, COUNT(*) as c")
            ->groupBy('bucket')
            ->get();

        $sums = $rows->pluck('s', 'bucket');
        $counts = $rows->pluck('c', 'bucket');
        $labels = [];
        $full = [];
        $data = [];
        $orders = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $future = $cursor->startOfMonth()->gt($cutoff);
            $labels[] = $cursor->translatedFormat('M');
            $full[] = $cursor->translatedFormat('F Y');
            $data[] = $future ? null : round((float) ($sums[$key] ?? 0), 3);
            $orders[] = $future ? null : (int) ($counts[$key] ?? 0);
            $cursor->addMonthNoOverflow();
        }

        return [
            'labels' => $labels,
            'full' => $full,
            'data' => $data,
            'counts' => $orders,
            'range' => 'year',
            'unit' => 'month',
        ];
    }

    /** توزيع طرق الدفع للشهر الجاري داخل الفرع المختار. */
    public static function paymentDistribution(): array
    {
        $branch = Demo::currentBranchId();
        $rows = Order::where('business_id', Demo::bid())
            ->sold()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->where('ordered_at', '>=', now()->startOfMonth())
            ->selectRaw('payment_method, SUM(total) as s')
            ->groupBy('payment_method')
            ->orderByDesc('s')
            ->get();

        return [
            'labels' => $rows->map(fn ($row) => Demo::methodLabel((string) $row->payment_method))->all(),
            'series' => $rows->map(fn ($row) => round((float) $row->s, 3))->all(),
        ];
    }
}

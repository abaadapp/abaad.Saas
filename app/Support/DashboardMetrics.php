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
    /**
     * ما يتبدّل في اللوحة وهي مفتوحة — لقطةٌ واحدة تقرؤها الصفحةُ ونبضُها.
     *
     * ═══ العطب الذي وُضعت له ═══
     *
     * كانت نبضةُ اللوحة (`admin.dashboard.stats`) تردّ البطاقات وحدها،
     * والرسمان خصائصُ الصفحة لحظةَ فتحها. فبيعةٌ بعد الفتح ترفع «مبيعات
     * اليوم» في البطاقة ولا تمسّ عمودَ الشهر ولا توزيعَ الدفع تحتها: شاشةٌ
     * واحدة تقول رقمين عن اللحظة نفسِها.
     *
     * فالبطاقاتُ والرسمان من دالّةٍ واحدة، تنادي بها الصفحةُ عند الفتح
     * والنبضةُ في كلّ دورة — ولا يفترق ما يُرسم أوّلًا عمّا يُحدَّث به.
     *
     * @return array{stats: array, salesSeries: array, paymentDistribution: array}
     */
    public static function snapshot(): array
    {
        return [
            'stats' => Demo::adminStats(),
            'salesSeries' => self::salesYear(),
            'paymentDistribution' => self::paymentDistribution(),
        ];
    }

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

        /*
         * وفواتيرُ العملاء التي لا طلبَ لها في شهر صدورها — كبطاقة «مبيعات
         * الشهر» فوقها (`StandaloneInvoiceSales`). وعددُ العمود طلباتُه كما
         * كان: الفاتورةُ اليدويّة مبلغٌ لا طلب.
         */
        $invoices = StandaloneInvoiceSales::byBucket($bid, 'month', $start, $cutoff->copy()->startOfDay()->addDay(), $branch);

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
            $data[] = $future ? null : round((float) ($sums[$key] ?? 0) + ($invoices[$key] ?? 0), 3);
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

    /**
     * توزيع طرق الدفع للشهر الجاري داخل الفرع المختار.
     *
     * وفواتيرُ العملاء التي لا طلبَ لها شريطٌ واحدٌ باسمها، لا وسيلةٌ تُخمَّن:
     * الفاتورةُ تصدر آجلةً وتُحصَّل بعدُ بوسيلةٍ أو بوسائل، أو لا تُحصَّل. وقيمتُها
     * قيمةُ صدورها — فلا يصير تحصيلٌ جزئيٌّ أو كاملٌ مبيعاتٍ ثانية. وبه يساوي
     * مجموعُ الأشرطة بطاقةَ «مبيعات الشهر».
     */
    public static function paymentDistribution(): array
    {
        $branch = Demo::currentBranchId();
        $month = now()->startOfMonth();
        $rows = Order::where('business_id', Demo::bid())
            ->sold()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->where('ordered_at', '>=', $month)
            ->selectRaw('payment_method, SUM(total) as s')
            ->groupBy('payment_method')
            ->orderByDesc('s')
            ->get()
            ->map(fn ($row) => [Demo::methodLabel((string) $row->payment_method), round((float) $row->s, 3)]);

        // وشهرٌ أنقصته إشعاراتُ الدائن إلى الصفر أو دونه لا يُرسم شريطًا سالبًا
        $invoices = StandaloneInvoiceSales::total(Demo::bid(), $month, $month->copy()->addMonthNoOverflow(), $branch);
        if ($invoices > 0) {
            $rows = $rows->push([__('فواتير العملاء'), $invoices])->sortByDesc(1)->values();
        }

        return [
            'labels' => $rows->pluck(0)->all(),
            'series' => $rows->pluck(1)->all(),
        ];
    }
}

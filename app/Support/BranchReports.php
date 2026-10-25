<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\BranchSupplyRequest;
use App\Models\BranchTransfer;
use App\Models\BranchTransferLine;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تقاريرُ المؤسسات عن الفروع — أداؤها، وأسبوعُها، وسجلُّ ما انتقل بينها.
 *
 * ═══ ولا معادلةَ ربحٍ هنا ═══
 *
 * المبيعاتُ وصافي الإيرادات والتكلفةُ ومجملُ الربح وصافيه والهامشُ لكلّ فرعٍ
 * من `Profitability::summary` — التقريرُ المعتمد لصافي الربح نفسُه. ومصروفاتُ
 * الفرع ما نُسب إليه وحده (المباشرُ وحصّتُه من الموزَّع)؛ والعامُّ الذي لم
 * يُوزَّع يُقال سطرًا وحده ولا يُقسَم على الفروع بالتخمين.
 *
 * وما لم يُنسب إلى فرعٍ من الإيراد والتكلفة — بيعةٌ بلا فرع في متجرٍ أُضيفت
 * فروعُه لاحقًا — يُقال «غير منسوب إلى فرع» بفرقه الدقيق عن مجموع النشاط، فيساوي
 * مجموعُ الصفوف رقمَ النشاط ولا يُخترع رقمٌ ليس في الدفتر.
 */
final class BranchReports
{
    /** بعد كم يومٍ يُعدّ سندٌ مُرسَل لم يُستلم متأخّرًا */
    public const LATE_DAYS = 2;

    // ═══ أداء الفروع ═══

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: null, summary: array<string, mixed>, unassigned: ?array<string, mixed>}
     */
    public static function branches(int $bid, ReportingPeriod $period): array
    {
        $start = $period->start;
        $end = $period->end;
        $stock = self::stockByBranch($bid);
        $moving = BranchTransfers::movingLines($bid);

        $rows = [];
        foreach (Branch::where('business_id', $bid)->orderBy('id')->get(['id', 'name']) as $branch) {
            $money = Profitability::summary($bid, $start, $end, $branch->id);
            $here = $stock[$branch->id] ?? ['quantity' => 0, 'value' => 0.0, 'low' => 0];

            $rows[] = [
                'branch_id' => $branch->id,
                'branch' => $branch->name,
                'sales' => $money['sales'],
                'orders' => Profitability::orders($bid, $start, $end, $branch->id)->count(),
                'net_revenue' => $money['net_revenue'],
                'cogs' => $money['cogs'],
                'gross_profit' => $money['gross_profit'],
                'expenses' => $money['expenses'],
                'net_profit' => $money['net_profit'],
                'margin' => $money['margin'],
                'stock_quantity' => $here['quantity'],
                'stock_value' => $here['value'],
                'low_stock' => $here['low'],
                'open_supply' => BranchSupply::openCount($bid, $branch->id),
                'transfers_open' => BranchTransfer::where('business_id', $bid)->whereIn('status', BranchTransfer::OPEN)
                    ->where(fn ($q) => $q->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))->count(),
                'incoming' => (int) $moving->where('transfer.to_branch_id', $branch->id)->sum(fn ($l) => $l->outstanding()),
            ];
        }

        $whole = Profitability::summary($bid, $start, $end);
        $orders = Profitability::orders($bid, $start, $end)->count();

        /*
         * وما لم يُنسب إلى فرع — الفرقُ بين النشاط ومجموع فروعه، بلا تقريب.
         * يظهر صفًّا حين يكون له ما يقوله، ويُترك حين يتطابق المجموعان.
         */
        $residual = [
            'sales' => round($whole['sales'] - array_sum(array_column($rows, 'sales')), 3),
            'net_revenue' => round($whole['net_revenue'] - array_sum(array_column($rows, 'net_revenue')), 3),
            'cogs' => round($whole['cogs'] - array_sum(array_column($rows, 'cogs')), 3),
            'orders' => $orders - array_sum(array_column($rows, 'orders')),
        ];
        $unassigned = abs($residual['sales']) >= 0.001 || abs($residual['cogs']) >= 0.001 || $residual['orders'] !== 0
            ? $residual + ['gross_profit' => round($residual['net_revenue'] - $residual['cogs'], 3)]
            : null;

        $attributed = round(array_sum(array_column($rows, 'expenses')), 3);
        $ranked = collect($rows)->filter(fn ($r) => $r['sales'] > 0 || $r['net_profit'] != 0)->sortByDesc('net_profit')->values();
        $inTransit = BranchTransfers::inTransit($bid);

        return [
            'rows' => $rows,
            'truncated' => null,
            'unassigned' => $unassigned,
            'summary' => [
                'sales' => $whole['sales'],
                'orders' => $orders,
                'net_revenue' => $whole['net_revenue'],
                'cogs' => $whole['cogs'],
                'gross_profit' => $whole['gross_profit'],
                // صافي ربح النشاط كما في تقرير صافي الربح — بالمصروفات كلّها
                'expenses' => $whole['expenses'],
                'net_profit' => $whole['net_profit'],
                'margin' => $whole['margin'],
                'branch_expenses' => $attributed,
                // ما في الدفتر من مصروفاتٍ تشغيليّة ولم يُنسب إلى فرع: العامُّ والرواتبُ والإهلاك
                'unallocated' => round($whole['expenses'] - $attributed, 3),
                'stock_quantity' => (int) array_sum(array_column($rows, 'stock_quantity')),
                'stock_value' => round(array_sum(array_column($rows, 'stock_value')), 3),
                'in_transit_quantity' => $inTransit['quantity'],
                'in_transit_value' => $inTransit['value'],
                'branches' => count($rows),
                // والأعلى والأقلُّ مقارنةٌ: فرعٌ واحدٌ له نشاطٌ لا يُسمّى «أعلى» ولو خسر
                'best' => $ranked->count() > 1 ? $ranked->first()['branch'] : null,
                'worst' => $ranked->count() > 1 ? $ranked->last()['branch'] : null,
            ],
        ];
    }

    /**
     * مخزونُ كلّ فرع — كميّتُه وقيمتُه وما تحت حدّ التنبيه.
     *
     * بالقاعدة نفسها في الشاشات كلّها: الرصيدُ من `BranchStock::books`، والقيمةُ
     * لما على رفٍّ وحده ومملوكٍ للمحلّ (`Demo::inventory`)، و«تحت الحدّ» أقلُّ
     * من حدّه لا يساويه (`Product::statusFor`).
     *
     * @return array<int, array{quantity: int, value: float, low: int, low_items: list<array{id: int, name: string, quantity: int, alert: int}>}>
     */
    public static function stockByBranch(int $bid): array
    {
        $books = BranchStock::books($bid);
        $products = Product::where('business_id', $bid)->where('tracks_stock', true)
            ->get(['id', 'name', 'cost', 'alert_qty', 'boutique_id']);
        $branches = Branch::where('business_id', $bid)->pluck('id');

        $out = [];
        foreach ($branches as $branchId) {
            $out[(int) $branchId] = ['quantity' => 0, 'value' => 0.0, 'low' => 0, 'low_items' => []];
        }

        foreach ($products as $p) {
            foreach ($branches as $branchId) {
                $qty = (int) ($books[$p->id][$branchId] ?? 0);
                $row = &$out[(int) $branchId];
                $row['quantity'] += $qty;
                if (! $p->boutique_id) {
                    $row['value'] = round($row['value'] + (float) $p->cost * $qty, 3);
                }
                if ($qty < (int) $p->alert_qty) {
                    $row['low']++;
                    $row['low_items'][] = ['id' => (int) $p->id, 'name' => $p->name, 'quantity' => $qty, 'alert' => (int) $p->alert_qty];
                }
                unset($row);
            }
        }

        return $out;
    }

    // ═══ التقرير الأسبوعيّ ═══

    /** الأسبوعُ الذي فيه هذا اليوم — من الأحد إلى السبت (`Demo::WEEK_START`) */
    public static function week(?string $day): array
    {
        $date = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? Carbon::parse($day) : now();
        $start = $date->copy()->startOfWeek(Demo::WEEK_START)->startOfDay();

        return [$start, $start->copy()->addWeek()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function weekly(int $bid, ?string $day): array
    {
        [$start, $end] = self::week($day);
        $performance = self::branches($bid, ReportingPeriod::custom($start->toDateString(), $end->copy()->subDay()->toDateString()));
        $branches = $performance['rows'];
        $stock = self::stockByBranch($bid);
        $names = Branch::where('business_id', $bid)->pluck('name', 'id');

        $top = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->sold()
            ->where('ordered_at', '>=', $start)->where('ordered_at', '<', $end))
            ->selectRaw('name, SUM(quantity) as sold, SUM(total) as revenue')
            ->groupBy('name')->orderByDesc('revenue')->orderBy('name')->limit(10)->get()
            ->map(fn ($r) => ['product' => $r->name, 'sold' => (int) $r->sold, 'revenue' => round((float) $r->revenue, 3)])->all();

        $low = [];
        foreach ($stock as $branchId => $s) {
            foreach ($s['low_items'] as $item) {
                $low[] = ['branch' => $names[$branchId] ?? '—', 'product' => $item['name'], 'quantity' => $item['quantity'], 'alert' => $item['alert']];
            }
        }

        $supply = BranchSupplyRequest::where('business_id', $bid)
            ->where(fn ($q) => $q->where('status', BranchSupplyRequest::PENDING)
                ->orWhere(fn ($w) => $w->where('status', BranchSupplyRequest::APPROVED)
                    ->whereHas('transfers', fn ($t) => $t->whereNotIn('status', BranchTransfer::FINAL))))
            ->withSum('lines', 'requested_qty')->orderBy('id')->get()
            ->map(fn ($r) => [
                'number' => $r->number, 'branch' => $r->branch_name, 'status' => $r->status,
                'quantity' => (int) $r->lines_sum_requested_qty, 'requested_at' => optional($r->created_at)->format('Y-m-d'),
            ])->all();

        $completed = BranchTransfer::where('business_id', $bid)->where('status', BranchTransfer::RECEIVED)
            ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)
            ->withSum('lines', 'received_qty')->orderBy('closed_at')->get()
            ->map(fn ($t) => self::transferRow($t) + ['quantity' => (int) $t->lines_sum_received_qty])->all();

        $late = self::late($bid)->map(fn ($t) => self::transferRow($t) + ['quantity' => (int) $t->lines->sum(fn ($l) => $l->outstanding())])->all();

        $discrepancies = BranchTransfer::where('business_id', $bid)->where('status', BranchTransfer::DISCREPANCY)
            ->with('lines')->orderBy('received_at')->get()
            ->map(fn ($t) => self::transferRow($t) + ['quantity' => (int) $t->lines->sum(fn ($l) => $l->outstanding())])->all();

        $ranked = collect($branches)->filter(fn ($r) => $r['sales'] > 0)->sortByDesc('sales')->values();

        return [
            'week' => ['start' => $start->toDateString(), 'end' => $end->copy()->subDay()->toDateString()],
            'periodLabel' => $start->toDateString().' → '.$end->copy()->subDay()->toDateString(),
            'summary' => [
                'sales' => $performance['summary']['sales'],
                'net_profit' => $performance['summary']['net_profit'],
                'orders' => $performance['summary']['orders'],
                'top_branch' => $ranked->first()['branch'] ?? null,
                'bottom_branch' => $ranked->count() > 1 ? $ranked->last()['branch'] : null,
                'low_stock' => count($low),
                'open_supply' => count($supply),
                'completed' => count($completed),
                'late' => count($late),
                'discrepancies' => count($discrepancies),
                'unallocated' => $performance['summary']['unallocated'],
            ],
            'unassigned' => $performance['unassigned'],
            'sections' => [
                'الفروع' => $branches,
                'الأكثر مبيعًا' => $top,
                'منخفض المخزون' => $low,
                'طلبات التزويد المفتوحة' => $supply,
                'التحويلات المكتملة' => $completed,
                'التحويلات المتأخرة' => $late,
                'فروقات الاستلام' => $discrepancies,
            ],
        ];
    }

    /** سنداتٌ أُرسلت ولم تُستلم بعد مضيّ `LATE_DAYS` */
    public static function late(int $bid): Collection
    {
        return BranchTransfer::where('business_id', $bid)->where('status', BranchTransfer::IN_TRANSIT)
            ->where('dispatched_at', '<', now()->subDays(self::LATE_DAYS))
            ->with('lines')->orderBy('dispatched_at')->get();
    }

    /** @return array<string, mixed> */
    private static function transferRow(BranchTransfer $t): array
    {
        return [
            'number' => $t->number,
            'from' => $t->from_branch_name,
            'to' => $t->to_branch_name,
            'status' => $t->status,
            'dispatched_at' => optional($t->dispatched_at)->format('Y-m-d'),
            'received_at' => optional($t->received_at)->format('Y-m-d'),
        ];
    }

    // ═══ سجلّ نقل المخزون ═══

    /**
     * سطورُ السندات في الفترة — ومعها سِجلُّ النقل القديم كما كُتب.
     *
     * والسندُ يُقاس بتاريخ طلبه: ما طُلب في سبتمبر يبقى في سبتمبر ولو استُلم
     * في أكتوبر — ومراحلُه كلُّها بتواريخها في السطر.
     *
     * @return array{rows: list<array<string, mixed>>, truncated: ?array, summary: array<string, mixed>, options: array<string, mixed>}
     */
    public static function transfers(int $bid, ReportingPeriod $period, ?string $branch, ?string $status, int $cap): array
    {
        $branchId = $branch !== null ? (int) $branch : null;

        $lines = BranchTransferLine::where('branch_transfer_lines.business_id', $bid)
            ->whereHas('transfer', function ($q) use ($period, $branchId, $status) {
                $period->bound($q, 'created_at');
                if ($branchId) {
                    $q->where(fn ($w) => $w->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId));
                }
                if ($status !== null && $status !== 'legacy') {
                    $q->where('status', $status);
                }
            })
            ->with('transfer')->orderByDesc('branch_transfer_id')->orderBy('id')->get();

        $rows = $status === 'legacy' ? collect() : $lines->map(fn (BranchTransferLine $l) => [
            'number' => $l->transfer->number,
            'at' => optional($l->transfer->created_at)->format('Y-m-d'),
            'from' => $l->transfer->from_branch_name,
            'to' => $l->transfer->to_branch_name,
            'product' => $l->product_name,
            'requested' => (int) $l->requested_qty,
            'sent' => (int) $l->sent_qty,
            'received' => (int) $l->received_qty,
            'outstanding' => $l->outstanding(),
            'lost' => (int) $l->lost_qty,
            'returned' => (int) $l->returned_qty,
            'late' => (int) $l->late_qty,
            'status' => $l->transfer->status,
            'status_label' => __(BranchTransfer::LABELS[$l->transfer->status] ?? $l->transfer->status),
            'requested_by' => $l->transfer->requested_by_name,
            'approved_by' => $l->transfer->approved_by_name,
            'dispatched_by' => $l->transfer->dispatched_by_name,
            'received_by' => $l->transfer->received_by_name,
            'dispatched_at' => optional($l->transfer->dispatched_at)->format('Y-m-d H:i'),
            'received_at' => optional($l->transfer->received_at)->format('Y-m-d H:i'),
        ]);

        // والقديمُ يُقرأ كما وقع: نقلٌ فوريّ، أُرسل واستُلم في لحظته
        $legacy = $status !== null && $status !== 'legacy' ? collect() : $period
            ->bound(StockTransfer::where('business_id', $bid), 'transferred_at')
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId)))
            ->with('product:id,name')->orderByDesc('transferred_at')->get()
            ->map(fn (StockTransfer $t) => [
                'number' => $t->number,
                'at' => optional($t->transferred_at)->format('Y-m-d'),
                'from' => $t->from_branch_name,
                'to' => $t->to_branch_name,
                'product' => $t->product?->name ?? '—',
                'requested' => (int) $t->quantity,
                'sent' => (int) $t->quantity,
                'received' => (int) $t->quantity,
                'outstanding' => 0,
                'lost' => 0, 'returned' => 0, 'late' => 0,
                'status' => 'legacy',
                'status_label' => __(BranchTransfer::LABELS['legacy']),
                'requested_by' => null, 'approved_by' => null, 'dispatched_by' => null, 'received_by' => null,
                'dispatched_at' => optional($t->transferred_at)->format('Y-m-d H:i'),
                'received_at' => optional($t->transferred_at)->format('Y-m-d H:i'),
            ]);

        $all = $rows->concat($legacy)->values();

        return [
            'rows' => $all->take($cap)->values()->all(),
            'truncated' => $all->count() > $cap ? ['shown' => min($cap, $all->count()), 'total' => $all->count()] : null,
            'summary' => [
                'transfers' => $all->pluck('number')->unique()->count(),
                'lines' => $all->count(),
                'sent' => (int) $all->sum('sent'),
                'received' => (int) $all->sum('received'),
                'outstanding' => (int) $all->sum('outstanding'),
                'lost' => (int) $all->sum('lost'),
            ],
            'options' => [
                'branches' => Branch::where('business_id', $bid)->orderBy('id')->get(['id', 'name'])
                    ->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])->all(),
            ],
        ];
    }
}

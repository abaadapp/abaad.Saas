<?php

namespace App\Support;

use App\Models\StockAdjustment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الهالك — أيّ تعديلٍ يعني بضاعةً ضاعت، وكم كلّفت.
 *
 * أسباب تعديل المخزون خمسة، وليست كلّها خسارة: «جرد» تصحيحُ عدٍّ قد يزيد
 * وقد ينقص، و«إهداء» بضاعةٌ خرجت بقرارٍ لا بحادث، و«تصحيح» إصلاحُ إدخال.
 * والخسارة الحقيقية اثنان: ما تلف وما فُقد.
 *
 * وهذا التمييز يُكتب هنا مرّةً واحدة. تركُه مقارنةَ نصٍّ في كلّ شاشةٍ يعني
 * أنّ إضافة سببٍ سادس يومًا تُطبَّق في ثلاثة مواضع وتُنسى في الرابع — فيقول
 * تقريران رقمين مختلفين عن الشهر نفسه، ولا يُعرف أيّهما الصادق.
 */
class Waste
{
    /**
     * ما يُعدّ خسارة.
     *
     * «جرد» ليس منها عمدًا: عدٌّ أظهر نقصًا قد يكون خطأ إدخالٍ سابقًا لا
     * بضاعةً تلفت، وحسبانُه هالكًا يضخّم الرقم ويُفقده معناه. ومن أراد
     * تسجيل تلفٍ فله سببُه الصريح.
     */
    public const REASONS = ['تلف', 'فقد'];

    public static function isWaste(?string $reason): bool
    {
        return in_array((string) $reason, self::REASONS, true);
    }

    /**
     * الهالك ينقص ولا يزيد.
     *
     * الشاشة تسأل «الكمية التالفة: ٦» — رقمًا موجبًا كما ينطق به الإنسان —
     * والخادم هو من يجعله ‎−٦. وقبولُ «+٦ تلف» كان يزيد المخزون بحجّة أنّ
     * بضاعةً تلفت، وهو ما لا معنى له في أيّ قراءة.
     *
     * ولا تُمسّ الصفوف القديمة: ما كُتب كُتب، وتصحيحُه بأثرٍ رجعيّ بلا دليل
     * يُفسد سجلًّا قد يكون صحيحًا لسببٍ لا نعرفه. تُقرأ وتُبلَّغ فقط.
     */
    public static function normalizeDelta(?string $reason, float $delta): float
    {
        return self::isWaste($reason) ? -abs($delta) : $delta;
    }

    /**
     * صفوف الهالك في مدّة — أساسُ كلّ رقمٍ في الشاشة.
     *
     * القيمة من `cost_at_time` لا من تكلفة المنتج اليوم: تلفٌ وقع قبل سنة
     * كلّف ما كلّف يومها، وقراءتُه بسعر اليوم تجعل تاريخ الخسائر يتحرّك مع
     * كلّ شحنةٍ تصل.
     */
    public static function query(int $businessId, array $filters = [])
    {
        $q = StockAdjustment::where('stock_adjustments.business_id', $businessId)
            ->whereIn('stock_adjustments.reason', self::REASONS)
            ->where('stock_adjustments.quantity_delta', '<', 0);

        if (! empty($filters['from'])) {
            $q->where('stock_adjustments.adjusted_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $q->where('stock_adjustments.adjusted_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
        if (! empty($filters['branch_id'])) {
            $q->where('stock_adjustments.branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['product_id'])) {
            $q->where('stock_adjustments.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['reason'])) {
            $q->where('stock_adjustments.reason', $filters['reason']);
        }
        if (! empty($filters['category_id'])) {
            $q->whereHas('product', fn ($p) => $p->where('category_id', (int) $filters['category_id']));
        }

        return $q;
    }

    /**
     * المجموعان اللذان يُقرآن أوّلًا: كم قطعةً وكم ريالًا.
     *
     * @return array{quantity: float, value: float, count: int}
     */
    public static function totals(int $businessId, array $filters = []): array
    {
        $row = self::query($businessId, $filters)
            ->selectRaw('count(*) as n, coalesce(sum(abs(quantity_delta)), 0) as q, coalesce(sum(abs(quantity_delta) * cost_at_time), 0) as v')
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'quantity' => round((float) ($row->q ?? 0), 3),
            'value' => round((float) ($row->v ?? 0), 3),
        ];
    }

    /**
     * الهالك مجمَّعًا على بُعدٍ واحد — منتجًا أو قسمًا أو فرعًا أو سببًا.
     *
     * دالّةٌ واحدة لأربع شاشات: أربع دوالٍّ متشابهة تفترق يومًا في تقريب
     * أو في ترتيب، فيُقرأ الرقم نفسه بشكلين.
     *
     * @return array<int, array{label: string, quantity: float, value: float}>
     */
    public static function groupedBy(int $businessId, string $dimension, array $filters = [], int $limit = 20): array
    {
        $q = self::query($businessId, $filters);

        [$column, $labels] = match ($dimension) {
            'product' => ['product_id', fn ($ids) => \App\Models\Product::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all()],
            'branch' => ['branch_id', fn ($ids) => \App\Models\Branch::whereIn('id', $ids)->pluck('name', 'id')->all()],
            'reason' => ['reason', fn ($ids) => array_combine($ids, $ids)],
            'category' => ['product_id', fn ($ids) => []],
            default => ['product_id', fn ($ids) => []],
        };

        // القسم ليس عمودًا في التعديل بل في المنتج — فيُجمَّع بعد الضمّ
        if ($dimension === 'category') {
            // الأعمدة مؤهَّلة باسم جدولها: `business_id` موجودٌ في الجدولين،
            // وتركُه مجرَّدًا بعد الضمّ يُسقط الاستعلام بـ«عمودٌ ملتبس»
            $rows = $q->join('products', 'products.id', '=', 'stock_adjustments.product_id')
                ->selectRaw('products.category_id as k, sum(abs(stock_adjustments.quantity_delta)) as q, sum(abs(stock_adjustments.quantity_delta) * stock_adjustments.cost_at_time) as v')
                ->groupBy('products.category_id')->get();

            $names = \App\Models\Category::whereIn('id', $rows->pluck('k')->filter())->pluck('name', 'id')->all();

            return $rows->map(fn ($r) => [
                'label' => $names[$r->k] ?? __('بلا قسم'),
                'quantity' => round((float) $r->q, 3),
                'value' => round((float) $r->v, 3),
            ])->sortByDesc('value')->take($limit)->values()->all();
        }

        $rows = $q->selectRaw($column.' as k, sum(abs(quantity_delta)) as q, sum(abs(quantity_delta) * cost_at_time) as v')
            ->groupBy($column)->get();

        $names = $labels($rows->pluck('k')->filter()->all());

        return $rows->map(fn ($r) => [
            'label' => $names[$r->k] ?? __('—'),
            'quantity' => round((float) $r->q, 3),
            'value' => round((float) $r->v, 3),
        ])->sortByDesc('value')->take($limit)->values()->all();
    }

    /**
     * الهالك شهرًا بشهر — منحنى يقرأ منه صاحب المحلّ اتّجاهه لا رقمه.
     *
     * @return array<int, array{label: string, value: float, quantity: float}>
     */
    public static function overTime(int $businessId, array $filters = [], int $months = 6): array
    {
        $end = ! empty($filters['to']) ? Carbon::parse($filters['to']) : now();
        $out = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $end->copy()->startOfMonth()->subMonths($i);
            $t = self::totals($businessId, array_merge($filters, [
                'from' => $month->toDateString(),
                'to' => $month->copy()->endOfMonth()->toDateString(),
            ]));
            $out[] = [
                'label' => $month->format('Y-m'),
                'value' => $t['value'],
                'quantity' => $t['quantity'],
            ];
        }

        return $out;
    }

    /**
     * المدّة السابقة بطولها نفسه — للمقارنة لا للعرض.
     *
     * «ارتفع ٣٨٪» جملةٌ لا معنى لها إن قُورن شهرٌ كامل بأسبوع. فتُحسب
     * المدّة السابقة بعدد أيّام المدّة الحالية بالضبط.
     *
     * @return array{from: string, to: string}
     */
    public static function previousWindow(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        // صحيحٌ لا كسر: `diffInDays` على نهاية اليوم يعود بـ9.999 لا 10،
        // فيصير الطرح يومًا زائدًا وتزحف المدّة السابقة عن موضعها
        $days = max(1, (int) $start->diffInDays($end) + 1);

        return [
            'from' => $start->copy()->subDays($days)->toDateString(),
            'to' => $start->copy()->subDay()->toDateString(),
        ];
    }

    /**
     * صفوفٌ قديمة تناقض القاعدة الجديدة — تُبلَّغ ولا تُصلَح.
     *
     * سببُ هالكٍ بفرقٍ موجب: بضاعةٌ «تلفت» فزاد المخزون. لا نعرف أكانت خطأ
     * إدخالٍ أم عكسَ قيدٍ مقصودًا، والتخمين يفسد سجلًّا قد يكون له تفسير.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function suspiciousRows(int $businessId): array
    {
        return StockAdjustment::where('business_id', $businessId)
            ->whereIn('reason', self::REASONS)
            ->where('quantity_delta', '>', 0)
            ->with('product')
            ->orderByDesc('adjusted_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'number' => $a->number,
                'product' => $a->product?->name ?? '—',
                'reason' => $a->reason,
                'delta' => (float) $a->quantity_delta,
                'at' => optional($a->adjusted_at)->format('Y-m-d'),
            ])->all();
    }

    /**
     * كم بلغ الهالك من المستهلَك — النسبة الوحيدة الصادقة بعد الوصفات.
     *
     * ولا تُقاس بكميّة الباقات المباعة: بيعُ باقةٍ ليس بيعَ وردة، وقسمةُ
     * ورودٍ هالكة على باقاتٍ مباعة تخلط وحدتين لا تُخلطان. فالمقام هو ما
     * استُهلك من هذا الصنف نفسه — من حركات المخزون.
     *
     * @return array<int, array{label: string, waste: float, consumed: float, rate: float, value: float}>
     */
    public static function versusConsumption(int $businessId, array $filters = [], int $minConsumed = 10): array
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $consumed = StockLedger::consumedBetween(
            $businessId,
            ! empty($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            Carbon::parse($from)->startOfDay()->toDateTimeString(),
            Carbon::parse($to)->endOfDay()->toDateTimeString(),
        );

        if (! $consumed) {
            return [];
        }

        $waste = self::query($businessId, $filters)
            ->whereIn('product_id', array_keys($consumed))
            ->selectRaw('product_id, sum(abs(quantity_delta)) as q, sum(abs(quantity_delta) * cost_at_time) as v')
            ->groupBy('product_id')->get()->keyBy('product_id');

        $names = \App\Models\Product::withTrashed()->whereIn('id', array_keys($consumed))->pluck('name', 'id')->all();

        $out = [];
        foreach ($consumed as $pid => $used) {
            // مقامٌ صغير يُنتج نسبًا مذهلة بلا معنى: استُهلكت قطعة وهلكت
            // قطعة فالنسبة مئة بالمئة — وهي جملةٌ صحيحة حسابيًّا وكاذبة عمليًّا
            if ($used < $minConsumed) {
                continue;
            }
            $row = $waste->get($pid);
            $w = (float) ($row->q ?? 0);
            if ($w <= 0) {
                continue;
            }
            $out[] = [
                'label' => $names[$pid] ?? '—',
                'waste' => round($w, 3),
                'consumed' => round($used, 3),
                'rate' => round($w / $used * 100, 1),
                'value' => round((float) ($row->v ?? 0), 3),
            ];
        }

        usort($out, fn ($a, $b) => $b['rate'] <=> $a['rate']);

        return array_slice($out, 0, 10);
    }
}

<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * مُرشِّحات الشاشة — تُطبَّق على الجدول وعلى الملفّ بالقاعدة نفسها.
 *
 * زرّ «تصدير» يقف بجانب المُرشِّحات، فمن ضغطه ينتظر ما ينظر إليه. وكانت
 * الملفّات تُبنى من استعلامٍ آخر لا يقرأ من الطلب شيئًا: يُرشِّح التاجر
 * مصروفات سبتمبر ويصدّر، فيفتح ملفًّا فيه ثلاث سنوات. ويُرشِّح الفواتير
 * الملغاة ويصدّر، فلا يجد ملغاةً واحدة — لأنّ مصدر التصدير كان يستثنيها
 * أصلًا.
 *
 * والخطأ من هذا النوع لا يُكتشف عند التصدير: يُكتشف عند المحاسب.
 *
 * فموضعٌ واحد للقاعدة، تناديه الشاشة ويناديه الملفّ. ولو بقيت منسوخةً في
 * الاثنين لافترقتا عند أوّل مُرشِّحٍ يُضاف إلى واحدةٍ منهما.
 */
class ListFilters
{
    /**
     * فواتير المبيعات — كما ترشّحها شاشة «المبيعات».
     *
     * ولا `sold()` هنا: الشاشة تعرض الملغى وتعدّه، فالملفّ مثلها. ومن
     * أراد المباع وحده رشّح بالحالة.
     */
    public static function orders(Builder $q, Request $request): Builder
    {
        if ($s = trim((string) $request->query('q'))) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('number', $like, "%{$s}%")
                ->orWhere('customer_name', $like, "%{$s}%")
                ->orWhere('employee_name', $like, "%{$s}%"));
        }

        if ($pm = $request->query('payment')) {
            $q->where('payment_method', $pm);
        }

        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }

        if ($from = $request->query('from')) {
            $q->whereDate('ordered_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $q->whereDate('ordered_at', '<=', $to);
        }

        /*
         * مُرشِّح الموعد — على `scheduled_for` لا على `ordered_at`.
         *
         * «ما الذي يُسلَّم اليوم؟» غير «ما الذي سُجّل اليوم». وطلبٌ سُجّل
         * الاثنين لتسليمه الجمعة يقع في يومين مختلفين بحسب أيّ عمودٍ يُقرأ.
         */
        if ($when = $request->query('when')) {
            match ($when) {
                'today' => $q->whereBetween('scheduled_for', [now()->startOfDay(), now()->endOfDay()]),
                'tomorrow' => $q->whereBetween('scheduled_for', [
                    now()->addDay()->startOfDay(), now()->addDay()->endOfDay(),
                ]),
                'upcoming' => $q->where('scheduled_for', '>', now()->addDay()->endOfDay()),
                // و«المتأخّر» يستثني المغلق: طلبٌ سُلّم أمس ليس متأخّرًا اليوم
                'overdue' => $q->where('scheduled_for', '<', now())
                    ->whereNotIn('status', OrderStatus::CLOSED),
                default => null,
            };
        }

        return $q;
    }

    /** المنتجات — كما ترشّحها شاشة «المنتجات» */
    public static function products(Builder $q, Request $request): Builder
    {
        if ($s = trim((string) $request->query('q'))) {
            $like = Search::like();
            // والباركود من البحث: من اعتاد الماسح يمرّره هنا
            $q->where(fn ($w) => $w->where('name', $like, "%{$s}%")
                ->orWhere('sku', $like, "%{$s}%")
                ->orWhere('barcode', $like, "%{$s}%"));
        }

        if ($c = $request->query('category')) {
            $q->whereHas('category', fn ($w) => $w->where('name', $c));
        }

        if (($st = $request->query('status')) !== null && $st !== '') {
            $q->where('active', $st === 'active');
        }

        if ($stock = $request->query('stock')) {
            match ($stock) {
                'نفد المخزون' => $q->where('quantity', '<=', 0),
                'منخفض' => $q->whereColumn('quantity', '<', 'alert_qty')->where('quantity', '>', 0),
                'متوفر' => $q->whereColumn('quantity', '>=', 'alert_qty'),
                // الراكد: ما لم يُبَع منذ تسعين يومًا وفي المخزن منه بضاعة
                'راكد' => $q->where('quantity', '>', 0)->whereDoesntHave('orderItems', fn ($w) => $w
                    ->whereHas('order', fn ($o) => $o->where('ordered_at', '>=', now()->subDays(90)))),
                default => null,
            };
        }

        return $q;
    }

    /** العملاء — كما ترشّحهم شاشة «العملاء» */
    public static function customers(Builder $q, Request $request): Builder
    {
        if ($s = trim((string) $request->query('q'))) {
            $like = Search::like();
            // والاسم الإنجليزيّ معه: من كتبه بيده يبحث به
            $q->where(fn ($w) => $w->where('name', $like, "%{$s}%")
                ->orWhere('name_en', $like, "%{$s}%")
                ->orWhere('phone', $like, "%{$s}%")
                ->orWhere('email', $like, "%{$s}%"));
        }

        return $q;
    }

    /**
     * الشهر الذي تنظر إليه شاشة المصروفات — وهو الشهر الجاري ما لم يُقل غيره.
     *
     * والفراغ صريحًا يعني «كل الشهور»: من اختارها في الشاشة يجب أن يصدّرها.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function expenseSpan(Request $request): ?array
    {
        $month = (string) $request->query('month', now()->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        $first = Carbon::createFromFormat('Y-m-d', $month.'-01');

        return [$first->copy()->startOfMonth(), $first->copy()->endOfMonth()];
    }

    /** المصروفات — كما ترشّحها شاشة «المصروفات»، بشهرها */
    public static function expenses(Builder $q, Request $request): Builder
    {
        if ($span = self::expenseSpan($request)) {
            $q->whereBetween('spent_at', $span);
        }

        if ($s = trim((string) $request->query('q'))) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('reference', $like, "%{$s}%")
                ->orWhere('description', $like, "%{$s}%")
                ->orWhere('type', $like, "%{$s}%"));
        }

        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $q;
    }
}

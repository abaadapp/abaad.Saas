<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
        if ($s = Search::term($request)) {
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

        /*
         * البابُ الذي دخل منه الطلب.
         *
         * وهو السؤالُ الذي كان يُسأل بلا جواب: «كم يبيع الموقعُ مقابل
         * المحلّ؟» — والقناةُ مكتوبةٌ في كلّ صفٍّ منذ أُنشئ ولا تُقرأ في
         * القائمة. والقاعدةُ من `SalesChannel::scope` لا مكتوبةً هنا:
         * «غير محدّدة» فراغٌ في العمود لا قيمةٌ فيه، ومن كتبها ثانيةً
         * نسيها.
         */
        SalesChannel::scope($q, $request->query('channel'));

        /*
         * والمدّةُ من `ReportingPeriod`: من/إلى كما كانت، وشهرٌ أو سنةٌ أو
         * نطاقُ أشهرٍ من منتقي الفترة — على تاريخ الطلب (`ordered_at`) وحده.
         * وبلا شيءٍ يُقال: الطلباتُ كلُّها، كما كانت.
         */
        self::orderPeriod($request)->bound($q, 'ordered_at');

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
        if ($s = Search::term($request)) {
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

        /*
         * ولمن الصنف: المحلُّ أم بوتيكٌ بعينه — بـ`Boutiques::scope` وحدها.
         *
         * وبوتيكُ متجرٍ آخر يُقرأ «الكلّ» هناك، فلا يُرشِّح هنا شيئًا ولا
         * يكشف أنّه موجود.
         */
        if ($request->filled('boutique')) {
            $scope = Boutiques::scope(
                Business::find(Demo::bid()),
                $request->query('boutique'),
            );

            if ($scope === Boutiques::OWN) {
                $q->whereNull('boutique_id');
            } elseif ($scope !== null) {
                $q->where('boutique_id', $scope->id);
            }
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
        if ($s = Search::term($request)) {
            $like = Search::like();
            // والاسم الإنجليزيّ معه: من كتبه بيده يبحث به
            $q->where(fn ($w) => $w->where('name', $like, "%{$s}%")
                ->orWhere('name_en', $like, "%{$s}%")
                ->orWhere('phone', $like, "%{$s}%")
                ->orWhere('email', $like, "%{$s}%"));
        }

        // من لم تُحدَّد لغةُ رسائله بعد — جولةُ اللغة في شاشة العملاء
        if ((string) $request->query('missing') === 'language') {
            $q->whereNull('language');
        }

        return $q;
    }

    /** فترةُ شاشة الطلبات — من/إلى القديمة أو منتقي الفترة، وبلا شيءٍ الكلّ */
    public static function orderPeriod(Request $request): ReportingPeriod
    {
        return ReportingPeriod::fromQuery($request->query(), ReportingPeriod::all(), ['dates']);
    }

    /**
     * فترةُ شاشة المصروفات — وهي الشهر الجاري ما لم يُقل غيره.
     *
     * و`month=YYYY-MM` و`month=all` القديمتان تُفهمان كما كانتا، ومعهما منتقي
     * الفترة: شهرٌ أو نطاقُ أشهرٍ أو سنةٌ أو فترةٌ مخصّصة أو الكلّ. وشهرٌ
     * لا يُفهم يُردّ بخطأ — كان يُقرأ «كل الشهور» صامتًا فيُصدَّر العمرُ كلُّه.
     */
    public static function expensePeriod(Request $request): ReportingPeriod
    {
        return ReportingPeriod::fromQuery(
            $request->query(),
            ReportingPeriod::month(now()->format('Y-m')),
            ['dates', 'month'],
        );
    }

    /** المصروفات — كما ترشّحها شاشة «المصروفات»، بفترتها */
    public static function expenses(Builder $q, Request $request): Builder
    {
        // `spent_at` تاريخٌ بلا ساعة
        self::expensePeriod($request)->bound($q, 'spent_at', date: true);

        if ($s = Search::term($request)) {
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

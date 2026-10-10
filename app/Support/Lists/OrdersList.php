<?php

namespace App\Support\Lists;

use App\Models\Order;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\FlowerOrder;
use App\Support\ListFilters;
use App\Support\OrderStatus;
use App\Support\SalesChannel;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * قائمةُ المبيعات — استعلامٌ واحد تقرؤه الشاشةُ وملفّاتُها.
 *
 * الشاشةُ ترقّمه (`paginate(10)`) والتصديرُ يقرؤه كلَّه (`cursor`) — ولا
 * فرقَ بينهما غيرُ ذلك: المرشِّحاتُ (`ListFilters::orders`) والفرعُ
 * والترتيبُ (`SORTS`) في موضعٍ واحد. فما تقول الشاشةُ «٣٤٧ نتيجة» يخرج
 * ملفًّا من ٣٤٧ صفًّا.
 */
final class OrdersList
{
    /**
     * ما يُرتَّب في قائمة المبيعات.
     *
     * و«رقم الطلب» يُرتَّب بالرقم المتسلسل لا بنصّه: النصّ يرتّب #١٠ قبل #٩.
     */
    public const SORTS = [
        'id' => 'number',
        'customer' => 'customer_name',
        'employee' => 'employee_name',
        'items_count' => 'items_count',
        'total' => 'total',
        'payment' => 'payment_method',
        'date' => 'ordered_at',
        'scheduled' => 'scheduled_for',
    ];

    /** المرشِّحاتُ بلا ترتيب — للمجاميع التي تُحسب على ما رُشّح كلِّه */
    public static function filtered(Request $request): Builder
    {
        $q = Order::where('business_id', auth()->user()->business_id ?? Demo::bid())
            ->where('is_held', false)->withCount('items')
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()));

        ListFilters::orders($q, $request);

        return $q;
    }

    /** وبترتيب الشاشة — ما يرقّمه الجدول وما يُصدَّر */
    public static function query(Request $request): Builder
    {
        $q = self::filtered($request);
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('ordered_at'));
        // وفاصلٌ ثابت: ترتيبٌ بالعميل يجمع صفوفًا متساوية، فلا تتبدّل بين صفحة وصفحة
        $q->orderByDesc('id');

        return $q;
    }

    /** صفُّ الشاشة — وصفُّ الملفّ منه */
    public static function row(Order $o): array
    {
        return [
            'id' => $o->number,
            // والاسمُ بلغته كما يقرؤه كلُّ قارئٍ آخر: صفحةُ الطلب والورقة
            // والتصدير تمرّ كلُّها بالاسمين
            'customer' => Demo::customerLabel($o->customer_name, $o->customer_name_en),
            'employee' => $o->employee_name ?? '—',
            'branch' => $o->branch,
            'items_count' => $o->items_count,
            'total' => (float) $o->total,
            'payment' => $o->payment_method,
            'status' => $o->status,
            /*
             * والقناةُ باسمها ورمزِها معًا.
             *
             * الرمزُ ليُرسَم الوسمُ به (لونًا وأيقونة) والاسمُ ليُقرأ —
             * ولو أُرسل الرمزُ وحده لَترجمته الشاشةُ بقائمةٍ ثانية تفترق
             * عن `SalesChannel::label` يومَ تُضاف قناة.
             */
            'channel' => $o->channel ?: SalesChannel::UNKNOWN,
            'channel_label' => SalesChannel::label($o->channel),
            'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            // الموعد يُقرأ في العمود، و'—' لبيعة المنضدة التي لا موعد لها
            'scheduled' => optional($o->scheduled_for)->format('Y-m-d H:i') ?? '—',
            /*
             * والتأخّر يُقاس في الخادم لا في الشاشة.
             *
             * `Order::isLate` هي قاعدةُ مُرشِّح «متأخّر» نفسُها — فلا يقع
             * صفٌّ في ترشيحٍ ولا يُوسَم بوسمه، ولا يُوسَم صفٌّ لا يقع فيه.
             */
            'late' => $o->isLate(),
            // ونوعُ التنفيذ باسمه لا برمزه: 'delivery' ليست كلمةً تُقرأ
            'fulfillment' => $o->fulfillment_type ? FlowerOrder::fulfillmentLabel($o->fulfillment_type) : null,
        ];
    }

    /** صفوفُ الشاشة كلُّها — بلا ترقيم — لورقة PDF */
    public static function rows(Request $request): array
    {
        return self::query($request)->get()->map(fn (Order $o) => self::row($o))->all();
    }

    /**
     * ما يُصدَّر: صفوفُ الشاشة كلُّها، والمجاميعُ على ما رُشّح.
     *
     * والملغى في الصفوف وخارج المبلغ — كما في الشاشة: تعرضه في الجدول وتحسب
     * إجماليّها بلا قيمته.
     */
    public static function export(Request $request): Dataset
    {
        $filtered = self::filtered($request);
        $count = (clone $filtered)->count();
        $amount = (float) (clone $filtered)->sold()->sum('total');
        $cancelled = (clone $filtered)->where('status', Order::CANCELLED)->count();

        return new Dataset(
            title: __('الطلبات'),
            file: 'orders',
            fileParts: self::fileParts($request),
            filters: self::filterLines($request),
            perBranch: true,
            columns: [
                __('رقم الطلب') => Workbook::CODE,
                __('العميل') => Workbook::TEXT,
                __('المصدر') => Workbook::TEXT,
                __('الموظف') => Workbook::TEXT,
                __('الفرع') => Workbook::TEXT,
                __('عدد الأصناف') => Workbook::INT,
                Workbook::money('الإجمالي') => Workbook::MONEY,
                __('الدفع') => Workbook::TEXT,
                __('الحالة') => Workbook::TEXT,
                __('التاريخ') => Workbook::DATETIME,
                __('موعد التسليم') => Workbook::DATETIME,
                __('نوع التنفيذ') => Workbook::TEXT,
            ],
            rows: function () use ($request) {
                foreach (self::query($request)->cursor() as $o) {
                    $r = self::row($o);
                    yield [
                        'cells' => [
                            $r['id'], $r['customer'], __($r['channel_label']), $r['employee'], $r['branch'],
                            $r['items_count'], $r['total'],
                            $r['payment'] === 'بطاقة' ? __('فيزا') : __((string) $r['payment']),
                            __((string) $r['status']), $r['date'], $r['scheduled'], $r['fulfillment'] ?? '—',
                        ],
                        'fill' => $r['status'] === OrderStatus::CANCELLED ? 'FEF3C7' : null,
                    ];
                }
            },
            totals: [
                [__('عدد الطلبات'), $count, Workbook::INT],
                [Workbook::money('إجمالي القيمة'), $amount],
                [__('منها ملغاة'), $cancelled, Workbook::INT],
            ],
        );
    }

    /** المرشِّحاتُ الفعّالة كما يقرؤها التاجر — لا «الحالة: الكل» */
    private static function filterLines(Request $request): array
    {
        $when = [
            'overdue' => __('متأخّر'), 'today' => __('اليوم'), 'tomorrow' => __('غدًا'), 'upcoming' => __('قادم'),
        ];

        return [
            __('البحث') => Search::term($request),
            __('الحالة') => $request->filled('status') ? __((string) $request->query('status')) : null,
            __('الدفع') => $request->filled('payment') ? __((string) $request->query('payment')) : null,
            __('المصدر') => $request->filled('channel') ? __(SalesChannel::label((string) $request->query('channel'))) : null,
            // الفترةُ باسمها — «سبتمبر 2025» — ولا تُكتب حين لا فترة
            __('الفترة') => ($p = ListFilters::orderPeriod($request))->kind === 'all' ? null : $p->label(),
            __('موعد التسليم') => $when[(string) $request->query('when')] ?? null,
        ];
    }

    /**
     * `orders-2026-10-01-to-2026-10-31` · `orders-2025-09` · `orders-2024` حين تُختار
     * فترة، وإلا تاريخُ اليوم.
     */
    private static function fileParts(Request $request): array
    {
        $period = ListFilters::orderPeriod($request);

        return match (true) {
            $period->kind === 'all' => [now()->format('Y-m-d')],
            // «من» وحدها كما كانت: إلى اليوم
            $period->kind === 'custom' && $period->end === null => [$period->fromDate(), 'to', 'today'],
            default => $period->fileParts(),
        };
    }
}

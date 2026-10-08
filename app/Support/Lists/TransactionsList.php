<?php

namespace App\Support\Lists;

use App\Models\Transaction;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * الحركةُ الماليّة — استعلامٌ واحد تقرؤه الشاشةُ وملفّاتُها.
 *
 * كانت الشاشةُ ترشّح بالبحث والنوع ومن/إلى، وملفّاتُها الثلاثة تقرأ `range`
 * وحده — وهو ليس في الشاشة — فتسقط على «هذا الشهر» مهما رأى التاجر. فمن
 * رشّح حركات مارس وصدّر خرج بأكتوبر.
 */
final class TransactionsList
{
    public const SORTS = [
        'reference' => 'reference',
        'date' => 'occurred_at',
        'description' => 'description',
        'amount' => 'amount',
    ];

    /** المرشِّحاتُ بلا ترتيب: البحثُ والنوعُ ومن/إلى */
    public static function filtered(Request $request): Builder
    {
        $q = Transaction::where('business_id', auth()->user()->business_id ?? Demo::bid())
            ->with('order:id,status,number');

        // والمعامل من المحرّك لا مكتوبًا بيدٍ: `like` تفرّق في PostgreSQL وحدها
        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('reference', $like, "%{$s}%")
                ->orWhere('description', $like, "%{$s}%"));
        }
        if ($kind = $request->query('kind')) {
            $q->where('kind', $kind);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('occurred_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('occurred_at', '<=', $to);
        }

        /*
         * و`range` — بابُ الروابط القديمة وشاشة الحسابات — حين لا من/إلى.
         *
         * شاشةُ الحركة ترشّح بالتاريخين، وشاشةُ الحسابات تصدّر «هذا الشهر»
         * بـ`range`، ورابطٌ محفوظٌ قبل اليوم يحمله. فيبقى مفهومًا بمعناه.
         */
        if (! $request->filled('from') && ! $request->filled('to') && $request->filled('range')
            && ($start = Demo::rangeStart(Demo::range($request->query('range'))))) {
            $q->where('occurred_at', '>=', $start);
        }

        return $q;
    }

    public static function query(Request $request): Builder
    {
        $q = self::filtered($request);
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('occurred_at'));
        $q->orderByDesc('id');

        return $q;
    }

    /**
     * المجاميعُ على ما رُشّح كلِّه — الدخلُ والخارجُ والتحويلُ منفصلة.
     *
     * والتحويلُ لا يُجمع مع الدخل: انتقالٌ بين جيبين. والملغاةُ خارج المجموع
     * وداخل الجدول — انظر `Transaction::scopeNotCancelled`.
     *
     * @return array{in: float, out: float, transfers: float}
     */
    public static function summary(Builder $filtered): array
    {
        $totals = (clone $filtered)->reorder()->notCancelled()
            ->selectRaw('type, COALESCE(SUM(amount),0) total')->groupBy('type')
            ->pluck('total', 'type');

        return [
            'in' => round((float) ($totals['دخل'] ?? 0), 3),
            'out' => round((float) ($totals['مصروف'] ?? 0), 3),
            'transfers' => round((float) ($totals['تحويل'] ?? 0), 3),
        ];
    }

    public static function row(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'date' => optional($t->occurred_at)->format('Y-m-d H:i'),
            'description' => $t->description,
            'method' => $t->method,
            'type' => $t->type,
            'kind' => $t->kind,
            'kind_label' => Books::label($t->kind),
            'amount' => (float) $t->amount,
            'employee' => $t->employee_name,
            // ما رُحّل إلى الأستاذ وما لم يُرحَّل — يُقرأ ولا يُخفى
            'posted' => $t->journal_entry_id !== null,
            // والملغاة تُوسم ولا تُحذف: خرجت من المجموع وبقيت في السجلّ
            'cancelled' => $t->isCancelled(),
            /*
             * رقمُ الفاتورة إن كان للحركة فاتورة — وبه يصير المرجع بابًا.
             *
             * ومصروفٌ أو تحويلٌ لا فاتورةَ له، فيبقى نصًّا — ولا يُعرض بابٌ لا يُفتح.
             */
            'invoice' => $t->order?->number,
        ];
    }

    /** صفوفُ الشاشة كلُّها بترتيبها — لورقة PDF */
    public static function rows(Request $request): array
    {
        return self::query($request)->get()->map(fn (Transaction $t) => self::row($t))->all();
    }

    public static function export(Request $request): Dataset
    {
        $filtered = self::filtered($request);
        $summary = self::summary($filtered);
        $count = (clone $filtered)->count();
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        return new Dataset(
            title: __('المعاملات المالية'),
            file: 'transactions',
            fileParts: $from !== '' || $to !== ''
                ? [$from ?: 'start', 'to', $to ?: now()->format('Y-m-d')]
                : [$request->filled('range') ? Demo::range($request->query('range')) : 'all'],
            filters: [
                __('الفترة') => $from === '' && $to === '' && $request->filled('range')
                    ? Demo::rangeLabel(Demo::range($request->query('range'))) : null,
                __('من') => $from ?: null,
                __('إلى') => $to ?: null,
                __('النوع') => $request->filled('kind') ? Books::label((string) $request->query('kind')) : null,
                __('البحث') => Search::term($request),
            ],
            // لا يرشّح بالفرع — فيقول «كل الفروع» ولا ينسب نفسه إلى فرع الشريط
            perBranch: false,
            columns: [
                __('المرجع') => Workbook::CODE,
                __('التاريخ') => Workbook::DATETIME,
                __('البيان') => Workbook::TEXT,
                __('النوع') => Workbook::TEXT,
                __('الوسيلة') => Workbook::TEXT,
                Workbook::money('المبلغ') => Workbook::MONEY,
                __('الموظف') => Workbook::TEXT,
                __('الحالة') => Workbook::TEXT,
            ],
            rows: function () use ($request) {
                foreach (self::query($request)->lazy(1000) as $t) {
                    $r = self::row($t);
                    yield [
                        'cells' => [
                            $r['reference'], $r['date'], $r['description'], $r['kind_label'],
                            __((string) $r['method']), $r['amount'], $r['employee'], $r['cancelled'] ? __('ملغاة') : '—',
                        ],
                        // والملغاة أوّلًا: صفٌّ لا يُجمع لا يُقرأ كصفٍّ يُجمع
                        'fill' => $r['cancelled'] ? 'FEF3C7' : ($r['type'] !== 'دخل' ? 'FDF0F0' : null),
                    ];
                }
            },
            total: $count,
            totals: [
                [Workbook::money('الدخل'), $summary['in']],
                [Workbook::money('المصروف'), $summary['out']],
                [Workbook::money('التحويلات'), $summary['transfers']],
                [__('عدد الحركات'), $count, Workbook::INT],
            ],
            totalsColumn: 5,
        );
    }
}

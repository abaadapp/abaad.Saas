<?php

namespace App\Support\Lists;

use App\Models\CustomerPaymentLink;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\ListFilters;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * روابطُ الدفع المدفوعة في «المبيعات» — تحصيلٌ يُقرأ، لا بيعٌ يُضاف.
 *
 * ═══ ولمَ ليست طلبًا ═══
 *
 * الرابطُ يطلب مالًا على ذمّةٍ قائمة (`PaymentLinks`): فاتورةٌ رُحّل بيعُها
 * يومَ صدرت، أو رصيدٌ للعميل. ودفعُه يكتب تحصيلًا واحدًا لا طلبًا ولا
 * إيرادًا. فلو دخل قائمةَ الطلبات صفًّا بمبلغه لَعُدّ البيعُ مرّتين: في
 * فاتورته (أو طلبها) وفي تحصيلها.
 *
 * فيُعرض بمُرشِّح المصدر «رابط دفع» وحده، من جدوله وجدول تحصيله، ولا يمسّ
 * استعلامَ الطلبات ولا مجاميعَه. ولا تُكتب `payment_link` في `orders.channel`
 * أبدًا: هي قيمةُ مُرشِّحٍ لا بابُ بيع (`SalesChannel`).
 *
 * ═══ وما «مدفوع» ═══
 *
 * ما أغلقه إشعارُ Paymob الموقَّع وكُتب تحصيلُه (`PaymentLinks::settle`):
 * حالُه `paid`، ورقمُ عمليّته محفوظ، وتحصيلُه قائمٌ لم يُلغَ. والمعلَّقُ
 * والفاشلُ والملغى والمنتهي لا يُعرض. ودفعةُ بيئة التجربة ليست مالًا فلا
 * تُخلط بالحقيقيّ (`is_live`). ولا تُنتظر التسويةُ البنكيّة: تأكيدُ الدفع غيرُ
 * وصول المال إلى البنك.
 *
 * ═══ والفرع ═══
 *
 * الرابطُ لا فرعَ له. فحين يُختار فرعٌ يُعرض ما كانت فاتورتُه في ذلك الفرع،
 * والرابطُ بلا فاتورةٍ (أو فاتورتُه بلا فرع) في «كل الفروع» وحدها — كما
 * تُقرأ فواتيرُ العملاء في اللوحة.
 */
final class PaidLinksList
{
    /** قيمةُ مُرشِّح المصدر — لا قناةُ طلب */
    public const SOURCE = 'payment_link';

    public const INVOICE = 'تحصيل فاتورة';

    public const STANDALONE = 'تحصيل عبر رابط دفع';

    /** ما يُرتَّب — بمفاتيح أعمدة الشاشة نفسِها */
    public const SORTS = [
        'id' => 'p.number',
        'customer' => 'c.name',
        'total' => 'l.amount',
        'date' => 'l.paid_at',
    ];

    public static function chosen(Request $request): bool
    {
        return $request->query('channel') === self::SOURCE;
    }

    /** خيارُ المُرشِّح — يُلحق بقنوات الطلبات ولا يُضاف إليها */
    public static function option(): array
    {
        return ['value' => self::SOURCE, 'label' => __('رابط دفع')];
    }

    /**
     * الروابطُ المدفوعة حقًّا في هذا المتجر — بالبحث والفترة والفرع.
     *
     * ومُرشِّحاتُ الطلب (الحالة ووسيلة الدفع والموعد) لا تُسأل هنا: التحصيلُ
     * لا حالةَ طلبٍ له ولا موعدَ تسليم.
     */
    public static function filtered(Request $request): Builder
    {
        $q = self::paid((int) (auth()->user()->business_id ?? Demo::bid()));

        if ($branch = Demo::currentBranchId()) {
            $q->where('i.branch_id', $branch);
        }

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('p.number', $like, "%{$s}%")
                ->orWhere('c.name', $like, "%{$s}%")
                ->orWhere('i.number', $like, "%{$s}%")
                ->orWhere('l.provider_transaction_id', $like, "%{$s}%")
                ->orWhere('l.reference', $like, "%{$s}%"));
        }

        // الفترةُ نفسُها التي تُقرأ بها الطلبات — على يوم الدفع
        ListFilters::orderPeriod($request)->bound($q, 'l.paid_at');

        return $q;
    }

    /**
     * الروابطُ المدفوعة حقًّا في متجرٍ — بلا بحثٍ ولا فترةٍ ولا فرع.
     *
     * وهي قاعدةُ «مدفوع» في موضعٍ واحد: القائمةُ تُرشّحها، وصفحةُ التحصيل
     * (`detail`) تقرأ منها صفًّا — فلا يُفتح بالعنوان ما لا تعرضه القائمة.
     */
    private static function paid(int $bid): Builder
    {
        return DB::table('customer_payment_links as l')
            ->join('customer_payments as p', fn ($j) => $j->on('p.id', '=', 'l.customer_payment_id')
                ->on('p.business_id', '=', 'l.business_id'))
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'l.customer_id')
                ->on('c.business_id', '=', 'l.business_id'))
            ->leftJoin('customer_invoices as i', fn ($j) => $j->on('i.id', '=', 'l.customer_invoice_id')
                ->on('i.business_id', '=', 'l.business_id'))
            ->where('l.business_id', $bid)
            ->where('l.status', CustomerPaymentLink::PAID)
            ->where('l.is_live', true)
            ->whereNotNull('l.provider_transaction_id')
            ->whereNotNull('l.paid_at')
            ->whereNull('p.cancelled_at');
    }

    /** وبترتيب الشاشة — ما يرقّمه الجدول وما يُصدَّر */
    public static function query(Request $request): Builder
    {
        $q = self::columns(self::filtered($request));

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('l.paid_at'));
        // وفاصلٌ ثابت: مبلغان متساويان لا يتبدّلان بين صفحةٍ وصفحة
        $q->orderByDesc('l.id');

        return $q;
    }

    /** ما يُقرأ من صفّ التحصيل — للقائمة ولصفحته */
    private static function columns(Builder $q): Builder
    {
        return $q->leftJoin('branches as b', fn ($j) => $j->on('b.id', '=', 'i.branch_id')->on('b.business_id', '=', 'l.business_id'))
            ->select([
                'l.id', 'l.reference', 'l.provider_transaction_id', 'l.amount', 'l.paid_at', 'l.meta', 'l.description',
                'l.customer_invoice_id', 'i.number as invoice_number', 'b.name as branch_name',
                'p.id as payment_id', 'p.number as payment_number',
                'c.name as customer_name',
            ]);
    }

    public static function page(Request $request, int $per = 10): LengthAwarePaginator
    {
        $page = self::query($request)->paginate($per)->withQueryString();
        $orders = self::ordersOf(collect($page->items()));

        return $page->through(fn ($r) => self::row($r, $orders));
    }

    /** صفوفُ الشاشة كلُّها — بلا ترقيم — لورقة PDF */
    public static function rows(Request $request): array
    {
        $rows = self::query($request)->get();
        $orders = self::ordersOf($rows);

        return $rows->map(fn ($r) => self::row($r, $orders))->all();
    }

    /**
     * صفُّ تحصيل — يقول ما دُفع، وعن أيّ بيعٍ دُفع.
     *
     * والفاتورةُ التي صدرت عن طلبٍ تُذكر طلباتُها: البيعُ هناك في «المبيعات»
     * صفًّا واحدًا، وهذا سدادُه لا بيعٌ ثانٍ.
     *
     * @param  array<int, list<string>>  $orders
     */
    public static function row(object $r, array $orders = []): array
    {
        $meta = is_string($r->meta) ? (json_decode($r->meta, true) ?: []) : (array) ($r->meta ?? []);
        $invoice = $r->customer_invoice_id ? (int) $r->customer_invoice_id : null;

        return [
            'key' => 'link:'.$r->id,
            // الرابطُ نفسُه — به يُفتح تحصيلُه من «عرض»
            'link_id' => (int) $r->id,
            'id' => $r->payment_number,
            // مرجعُ العمليّة عند Paymob — ما يطابقه صاحبُ المحلّ في لوحتها
            'reference' => $r->provider_transaction_id,
            'customer' => Demo::customerLabel($r->customer_name),
            'type' => $invoice ? self::INVOICE : self::STANDALONE,
            'invoice' => $invoice ? ['id' => $invoice, 'number' => $r->invoice_number] : null,
            'orders' => $invoice ? ($orders[$invoice] ?? []) : [],
            // فرعُ فاتورته — والرابطُ بلا فاتورةٍ لا فرعَ له
            'branch' => $r->branch_name ?? null,
            'amount' => round((float) $r->amount, 3),
            'date' => Carbon::parse($r->paid_at)->format('Y-m-d H:i'),
            'status' => 'مدفوع',
            // دفعةٌ ثانيةٌ على الرابط نفسه أو استرجاعٌ أبلغت به Paymob — كما في لوحة الروابط
            'review' => ((array) ($meta['extra_transactions'] ?? [])) !== [] || isset($meta['provider_refund']),
            'payment' => ['id' => (int) $r->payment_id, 'number' => $r->payment_number],
        ];
    }

    /**
     * المجاميعُ على ما رُشّح — تحصيلٌ كلُّها، ولا شيءَ منها مبيعات.
     *
     * @return array{count: int, amount: float, on_invoices: array{count: int, amount: float}, standalone: array{count: int, amount: float}}
     */
    public static function totals(Request $request): array
    {
        $sum = function (callable $narrow) use ($request): array {
            $q = self::filtered($request);
            $narrow($q);
            $r = $q->selectRaw('COUNT(*) AS n, COALESCE(SUM(l.amount), 0) AS amount')->first();

            return ['count' => (int) ($r->n ?? 0), 'amount' => round((float) ($r->amount ?? 0), 3)];
        };

        $all = $sum(fn () => null);

        return $all + [
            'on_invoices' => $sum(fn (Builder $q) => $q->whereNotNull('l.customer_invoice_id')),
            'standalone' => $sum(fn (Builder $q) => $q->whereNull('l.customer_invoice_id')),
        ];
    }

    /**
     * ما يُصدَّر: صفوفُ الشاشة كلُّها، والمجاميعُ على ما رُشّح.
     */
    public static function export(Request $request): Dataset
    {
        $totals = self::totals($request);

        return new Dataset(
            title: __('تحصيلات روابط الدفع'),
            file: 'payment-link-collections',
            fileParts: [now()->format('Y-m-d')],
            filters: [
                __('البحث') => Search::term($request),
                __('الفترة') => ($p = ListFilters::orderPeriod($request))->kind === 'all' ? null : $p->label(),
            ],
            perBranch: true,
            columns: [
                __('رقم السند') => Workbook::CODE,
                __('مرجع Paymob') => Workbook::CODE,
                __('العميل') => Workbook::TEXT,
                __('النوع') => Workbook::TEXT,
                __('الفاتورة') => Workbook::CODE,
                Workbook::money('المبلغ') => Workbook::MONEY,
                __('تاريخ الدفع') => Workbook::DATETIME,
                __('الحالة') => Workbook::TEXT,
            ],
            rows: function () use ($request) {
                foreach (self::query($request)->cursor() as $r) {
                    $row = self::row($r);
                    yield [
                        'cells' => [
                            $row['id'], $row['reference'], $row['customer'], __($row['type']),
                            $row['invoice']['number'] ?? '—', $row['amount'], $row['date'],
                            $row['review'] ? __('مدفوع — يحتاج مراجعة') : __($row['status']),
                        ],
                        'fill' => null,
                    ];
                }
            },
            totals: [
                [__('عدد التحصيلات'), $totals['count'], Workbook::INT],
                [Workbook::money('المحصّل'), $totals['amount']],
                [Workbook::money('منه على فواتير'), $totals['on_invoices']['amount']],
                [Workbook::money('منه دون فاتورة'), $totals['standalone']['amount']],
            ],
        );
    }

    /**
     * تحصيلٌ واحد كما تفتحه «عرض» — أو `null` حين لا يكون في هذا المتجر
     * مدفوعًا حقًّا (معلَّقًا أو تجربةً أو ملغًى أو لمتجرٍ آخر).
     *
     * صفُّ القائمة نفسُه، ومعه وصفُ الرابط ومرجعُه عندنا.
     */
    public static function detail(int $id): ?array
    {
        $r = self::columns(self::paid((int) (auth()->user()->business_id ?? Demo::bid())))->where('l.id', $id)->first();

        if ($r === null) {
            return null;
        }

        return self::row($r, self::ordersOf(collect([$r]))) + [
            'link_reference' => $r->reference,
            'description' => $r->description,
        ];
    }

    /**
     * أرقامُ الطلبات التي صدرت عنها كلُّ فاتورة — دفعةً واحدة للصفحة.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, list<string>>
     */
    public static function ordersOf($rows): array
    {
        $ids = $rows->pluck('customer_invoice_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $bid = (int) (auth()->user()->business_id ?? Demo::bid());

        return DB::table('customer_invoice_orders as x')
            ->join('orders as o', 'o.id', '=', 'x.order_id')
            ->whereIn('x.customer_invoice_id', $ids)
            ->where('o.business_id', $bid)
            ->orderBy('o.id')
            ->get(['x.customer_invoice_id', 'o.number'])
            ->groupBy('customer_invoice_id')
            ->map(fn ($g) => $g->pluck('number')->map(fn ($n) => (string) $n)->values()->all())
            ->all();
    }
}

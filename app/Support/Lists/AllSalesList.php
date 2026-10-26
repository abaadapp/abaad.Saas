<?php

namespace App\Support\Lists;

use App\Models\Order;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\OrderStatus;
use App\Support\PaymentLinks;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «المبيعات ← كل المصادر» — الطلباتُ وتحصيلاتُ روابط الدفع في جدولٍ واحد.
 *
 * ═══ ولمَ جدولٌ واحد ═══
 *
 * دفع العميلُ عبر رابطٍ فبحث صاحبُ المحلّ عن المال في «المبيعات»: كان
 * التحصيلُ سطرًا تحت الإجمالي يُفتح بمُرشِّحه وحده. فصار صفًّا بين الطلبات
 * بيوم دفعه، يُبحث ويُرتَّب ويُرقَّم معها على الخادم.
 *
 * ═══ ولا بيعَ ثانٍ ═══
 *
 * الصفُّ تحصيلٌ لا طلب (`PaidLinksList`): لا يُكتب في `orders` ولا يدخل
 * إجماليَّ المبيعات ولا عددَ الطلبات — تلك من `OrdersList::filtered` كما
 * كانت. ومجموعُه يُقال وحدَه («مجموع التحصيلات»). والقائمةُ تقرأ ولا تكتب:
 * لا طلبَ ولا فاتورةَ ولا قيد.
 *
 * ═══ ومتى ═══
 *
 * لمتجرٍ فُتحت فيه روابطُ الدفع (`PaymentLinks::ready`) — لا متجرٍ بعينه ولا
 * شرطَ موقعٍ إلكترونيّ. وحين لا يُختار مصدر: مُرشِّحاتُ الصندوق والموقع و«رابط
 * دفع» كما هي. ومُرشِّحاتُ الطلب (الحالة والوسيلة والموعد) لا تُسأل عن تحصيل،
 * فمن اختار أحدَها سأل عن طلبات — فتُعرض الطلباتُ وحدها كما كانت.
 *
 * ═══ وكيف يُرقَّم صفّان من جدولين ═══
 *
 * اتّحادٌ (`UNION ALL`) يحمل نوعَ الصفّ ومعرّفَه ومفاتيحَ الترتيب وحدها، فيُرتَّب
 * ويُعدّ ويُقطع صفحةً في القاعدة. ثمّ تُقرأ صفوفُ الصفحة من صاحبَيها
 * (`OrdersList::row` و`PaidLinksList::row`) — فلا يفترق صفُّ الطلب هنا عن صفّه
 * في «نقطة البيع»، ولا صفُّ التحصيل عن صفّه في «رابط دفع».
 */
final class AllSalesList
{
    public const ORDER = 'order';

    public const LINK = 'link';

    /**
     * مفاتيحُ الترتيب — مفاتيحُ `OrdersList::SORTS` نفسُها على أعمدة الاتّحاد.
     *
     * وما لا معنى له لتحصيلٍ (الموظفُ والأصنافُ والموعد) يُرتَّب به التحصيلُ
     * آخرًا في الاتّجاهين: لا قيمةَ له فلا يتقدّم ما له قيمة.
     */
    public const SORTS = [
        'id' => 'code',
        'customer' => 'customer',
        'employee' => 'employee',
        'items_count' => 'items',
        'total' => 'amount',
        'payment' => 'payment',
        'date' => 'sold_at',
        'scheduled' => 'scheduled',
    ];

    /** أتُعرض التحصيلاتُ بين الطلبات في هذا الطلب؟ */
    public static function merges(Request $request): bool
    {
        foreach (['channel', 'status', 'payment', 'when'] as $only) {
            if ($request->filled($only)) {
                return false;
            }
        }

        return PaymentLinks::ready((int) (auth()->user()->business_id ?? Demo::bid()));
    }

    /** الاتّحادُ مرتَّبًا — نوعُ كلّ صفٍّ ومعرّفُه */
    public static function sorted(Request $request): Builder
    {
        $q = DB::query()->fromSub(self::union($request), 'u')->select(['u.kind', 'u.rid']);

        $key = (string) $request->query('sort', '');
        if (isset(self::SORTS[$key])) {
            $column = 'u.'.self::SORTS[$key];
            $q->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
                ->orderBy($column, $request->query('dir') === 'asc' ? 'asc' : 'desc');
        }

        // ثمّ الأحدثُ — وفاصلٌ ثابت: صفّان بيومٍ واحد لا يتبدّلان بين صفحةٍ وصفحة
        return $q->orderByDesc('u.sold_at')->orderBy('u.kind')->orderByDesc('u.rid');
    }

    public static function page(Request $request, int $per = 10): LengthAwarePaginator
    {
        $page = self::sorted($request)->paginate($per)->withQueryString();

        return $page->setCollection(collect(self::hydrate(collect($page->items()))));
    }

    /** صفوفُ الشاشة كلُّها — بلا ترقيم — لورقة PDF */
    public static function rows(Request $request): array
    {
        return self::hydrate(self::sorted($request)->get());
    }

    public static function count(Request $request): int
    {
        return DB::query()->fromSub(self::union($request), 'u')->count();
    }

    /**
     * ما يُصدَّر: صفوفُ الشاشة كلُّها بترتيبها — طلباتٍ وتحصيلات.
     *
     * والمبلغان في عمودين: «الإجمالي» للطلب و«المحصّل عبر رابط دفع» للتحصيل،
     * فلا يُجمع عمودٌ واحدٌ فيُعدّ البيعُ مرّتين. والمجاميعُ كذلك: مجاميعُ
     * الطلبات كما كانت (`OrdersList::export`)، ومجموعُ التحصيلات وحده.
     */
    public static function export(Request $request): Dataset
    {
        $orders = OrdersList::export($request);
        $links = PaidLinksList::totals($request);

        return new Dataset(
            title: $orders->title,
            file: $orders->file,
            fileParts: $orders->fileParts,
            filters: $orders->filters,
            perBranch: true,
            columns: [
                __('رقم الطلب أو السند') => Workbook::CODE,
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
                Workbook::money('المحصّل عبر رابط دفع') => Workbook::MONEY,
                __('مرجع Paymob') => Workbook::CODE,
                __('الفاتورة') => Workbook::CODE,
            ],
            rows: function () use ($request) {
                foreach (self::sorted($request)->lazy(500)->chunk(500) as $chunk) {
                    foreach (self::hydrate(collect($chunk->all())) as $r) {
                        yield $r['kind'] === self::LINK
                            ? [
                                'cells' => [
                                    $r['id'], $r['customer'], __('رابط دفع'), '—', $r['branch'] ?? '—', null, null,
                                    'Paymob', $r['review'] ? __('مدفوع — يحتاج مراجعة') : __($r['status']), $r['date'], '—',
                                    '—', $r['amount'], $r['reference'], $r['invoice']['number'] ?? __('دون فاتورة'),
                                ],
                                'fill' => null,
                            ]
                            : [
                                'cells' => [
                                    $r['id'], $r['customer'], __($r['channel_label']), $r['employee'], $r['branch'],
                                    $r['items_count'], $r['total'],
                                    $r['payment'] === 'بطاقة' ? __('فيزا') : __((string) $r['payment']),
                                    __((string) $r['status']), $r['date'], $r['scheduled'], $r['fulfillment'] ?? '—',
                                    null, null, null,
                                ],
                                'fill' => $r['status'] === OrderStatus::CANCELLED ? 'FEF3C7' : null,
                            ];
                    }
                }
            },
            totals: [
                ...$orders->totals,
                [__('عدد التحصيلات عبر روابط الدفع'), $links['count'], Workbook::INT],
                [Workbook::money('مجموع التحصيلات عبر روابط الدفع (ليس من المبيعات)'), $links['amount']],
            ],
        );
    }

    /**
     * الطلباتُ والتحصيلاتُ في اتّحادٍ واحد — بالمُرشِّحات التي تقرأ بها كلٌّ قائمتَها.
     *
     * وأعمدةُ الفرعين بالترتيب نفسِه: النوعُ والمعرّفُ ثمّ مفاتيحُ الترتيب.
     */
    private static function union(Request $request): Builder
    {
        $items = DB::table('order_items')->selectRaw('COUNT(*)')->whereColumn('order_items.order_id', 'orders.id');

        $orders = OrdersList::filtered($request)->toBase()->select([
            DB::raw("'".self::ORDER."' AS kind"),
            'orders.id AS rid',
            'orders.ordered_at AS sold_at',
            'orders.total AS amount',
            'orders.customer_name AS customer',
            'orders.number AS code',
            'orders.employee_name AS employee',
            'orders.payment_method AS payment',
            'orders.scheduled_for AS scheduled',
        ])->selectSub($items, 'items');

        $links = PaidLinksList::filtered($request)->select([
            DB::raw("'".self::LINK."' AS kind"),
            'l.id AS rid',
            'l.paid_at AS sold_at',
            'l.amount AS amount',
            'c.name AS customer',
            'p.number AS code',
            DB::raw('NULL AS employee'),
            DB::raw("'Paymob' AS payment"),
            DB::raw('NULL AS scheduled'),
            DB::raw('NULL AS items'),
        ]);

        return $orders->unionAll($links);
    }

    /**
     * صفوفُ صفحةٍ من صاحبَيها — بترتيب الاتّحاد.
     *
     * والطلبُ يُقرأ من متجره ولو جاء معرّفُه من استعلامٍ مُرشَّحٍ به: بابٌ ثانٍ
     * لا يُبنى على أنّ الأوّل لم يُخطئ.
     *
     * @param  Collection<int, object>  $keys
     */
    private static function hydrate(Collection $keys): array
    {
        $ids = fn (string $kind) => $keys->where('kind', $kind)->pluck('rid')->map(fn ($id) => (int) $id)->values()->all();
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());

        $orders = ($o = $ids(self::ORDER)) === [] ? collect()
            : Order::where('business_id', $bid)->withCount('items')->whereIn('id', $o)->get()->keyBy('id');

        $links = collect();
        if (($l = $ids(self::LINK)) !== []) {
            $links = PaidLinksList::query(request())->whereIn('l.id', $l)->get()->keyBy('id');
        }
        $covered = PaidLinksList::ordersOf($links->values());

        return $keys
            ->map(function ($k) use ($orders, $links, $covered) {
                $id = (int) $k->rid;

                return match (true) {
                    $k->kind === self::ORDER && $orders->has($id) => ['kind' => self::ORDER] + OrdersList::row($orders[$id]),
                    $k->kind === self::LINK && $links->has($id) => ['kind' => self::LINK] + PaidLinksList::row($links[$id], $covered),
                    default => null,
                };
            })
            ->filter()
            ->values()
            ->all();
    }
}

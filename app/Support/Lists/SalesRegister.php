<?php

namespace App\Support\Lists;

use App\Models\CustomerInvoice;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\OrderStatus;
use App\Support\ReportingPeriod;
use App\Support\SalesChannel;
use App\Support\Search;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * سجلُّ عمليات البيع — كلُّ بيعةٍ في المتجر من مكانٍ واحد، للقراءة وحدها.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * البيعةُ تُحفظ في أحد موضعين لا يرى أحدُهما الآخر:
 *
 *   - `orders`: الصندوقُ والموقع. تُقرأ في «المبيعات» وحدها.
 *   - `customer_invoices`: فاتورةُ العميل اليدويّة. تُقرأ في «فواتير العملاء»
 *     وحدها، ومهمّةُ تجهيزها (`customer_invoice_preparations`) على اللوحة.
 *
 * ولوحةُ التجهيز تعرض النوعين معًا. فمن رأى بيعةَ موظّفه على اللوحة ثمّ فتح
 * «فواتير العملاء» لم يجدها: هي طلبٌ لا فاتورة. ومن رأى فاتورةً على اللوحة
 * لم يجدها في «المبيعات». ولا شاشةَ تقول «هذا كلُّ ما بِيع».
 *
 * ═══ ولمَ قراءةٌ لا نقل ═══
 *
 * لا تُكتب فاتورةٌ لطلب، ولا طلبٌ لفاتورة، ولا قيد: الصفوفُ تُقرأ من موضعها
 * باتّحادٍ واحد (`UNION ALL`) بمفتاحَي النوع والمعرّف، ويُرشَّح ويُرقَّم في
 * القاعدة.
 *
 * ═══ وكلُّ بيعةٍ صفٌّ واحد ═══
 *
 *   - الطلب: صفُّه هو، ولو غطّته فاتورةٌ (`customer_invoice_orders`). تلك
 *     الفاتورةُ مستندُ تحصيلٍ له، لا بيعٌ ثانٍ: لا قيدَ لها
 *     (`CustomerInvoices::post`)، فتُذكر في صفّه رقمًا ومنها يُقرأ سدادُه.
 *   - الفاتورةُ المستقلّة: صفُّها، صادرةً أو ملغاة. والمسودّةُ ليست بيعًا
 *     بعد، فتبقى في «فواتير العملاء» وحدها.
 *   - والتجهيزُ حالٌ في الصفّ لا صفٌّ آخر، والسدادُ حالٌ لا بيعٌ ثانٍ.
 *
 * ═══ والموظّف ═══
 *
 * بيعةُ الصندوق منسوبةٌ إلى الكاشير المختار (`orders.user_id` و
 * `orders.employee_name` من `PosCashier`)، والاسمُ المحفوظ يُعرض كما كُتب ولو
 * غادر صاحبُه. والحسابُ الذي كان مسجَّلًا ساعتها من سطر «أتمّ بيعًا» في سجلّ
 * النشاط، ويُذكر حين يختلف. والفاتورةُ لمنشئها (`created_by`). وطلبُ الموقع
 * بلا بائع. وما لا يُعرف بائعُه يبقى «غير محدد» ولا يُنسب إلى أحد.
 *
 * ═══ والفرع ═══
 *
 * الفرعُ المختار في الترويسة كبقيّة الشاشات (`Demo::currentBranchId`). والفاتورةُ
 * بلا فرعٍ في «كل الفروع» وحده، كما في اللوحة والدفتر.
 */
final class SalesRegister
{
    public const ORDER = 'order';

    public const INVOICE = 'invoice';

    /** مصدرُ الفاتورة المستقلّة — بجانب قنوات الطلب (`SalesChannel`) */
    public const SOURCE_INVOICE = 'invoice';

    /** فاتورةٌ طُلب تجهيزُها ولم تُسدَّد بعد — فلم تصل اللوحة */
    public const AWAITING_PAYMENT = 'بانتظار السداد';

    public const PAID = 'مدفوع';

    public const PARTIAL = 'مدفوع جزئيًا';

    public const UNPAID = 'غير مدفوع';

    public const VOID = 'ملغى';

    /** حالُ السداد كما يُرشَّح ويُعرض */
    public const PAYMENT_STATES = [self::PAID, self::PARTIAL, self::UNPAID, self::VOID];

    /** مراحلُ التجهيز — مفتاحٌ يُرشَّح به ← الحالاتُ التي فيه */
    public const PREP_STAGES = [
        'waiting' => [OrderStatus::PENDING, OrderStatus::CONFIRMED, self::AWAITING_PAYMENT],
        'active' => [OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY],
        'done' => [OrderStatus::DELIVERED, OrderStatus::PICKED_UP, OrderStatus::COMPLETED],
        'failed' => [OrderStatus::DELIVERY_FAILED, OrderStatus::CANCELLED],
    ];

    public const PREP_LABELS = [
        'none' => 'بلا تجهيز',
        'waiting' => 'بانتظار التجهيز',
        'active' => 'قيد التجهيز',
        'done' => 'سُلّمت',
        'failed' => 'لم تُسلَّم',
    ];

    /** حالةُ العملية ملغاةً — للطلب وللفاتورة */
    private const CANCELLED = [OrderStatus::CANCELLED, CustomerInvoice::CANCELLED];

    /** فرزٌ واحد ثابت: الأحدث أوّلًا، ثمّ النوع والمعرّف عند تساوي الساعة */
    public static function sorted(Builder $q): Builder
    {
        return $q->orderByDesc('r.sold_at')->orderBy('r.kind')->orderByDesc('r.id');
    }

    /** فترةُ السجلّ — «كل الفترات» ما لم يُقل غيرها، كقائمة المبيعات */
    public static function period(Request $request): ReportingPeriod
    {
        return ReportingPeriod::fromQuery($request->query(), ReportingPeriod::all(), ['dates']);
    }

    /** المصدرُ المطلوب — من التبويب أو المرشِّح، والمجهولُ «الكلّ» */
    public static function source(Request $request, ?string $fixed = null): ?string
    {
        $source = $fixed ?? (string) $request->query('source', '');

        return in_array($source, [SalesChannel::POS, SalesChannel::WEBSITE, SalesChannel::UNKNOWN, self::SOURCE_INVOICE], true)
            ? $source
            : null;
    }

    /**
     * الصفوفُ مرشَّحةً بلا ترتيب — يقرؤها الترقيمُ والمجاميعُ والتصدير.
     *
     * @return Builder على `r`
     */
    public static function filtered(Request $request, ?string $fixedSource = null): Builder
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());
        $branch = Demo::currentBranchId();
        $period = self::period($request);
        $source = self::source($request, $fixedSource);

        $parts = [];
        if ($source !== self::SOURCE_INVOICE) {
            $parts[] = self::orders($bid, $branch, $period, $source);
        }
        if ($source === null || $source === self::SOURCE_INVOICE) {
            $parts[] = self::invoices($bid, $branch, $period);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $q = DB::query()->fromSub($union, 'r');

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('r.number', $like, "%{$s}%")
                ->orWhere('r.customer', $like, "%{$s}%")
                ->orWhere('r.seller', $like, "%{$s}%")
                ->orWhere('r.cover_number', $like, "%{$s}%"));
        }

        self::bySeller($q, (string) $request->query('seller', ''));

        if (in_array($pay = (string) $request->query('payment_state', ''), self::PAYMENT_STATES, true)) {
            $q->where('r.pay_state', $pay);
        }

        $prep = (string) $request->query('prep', '');
        if ($prep === 'none') {
            $q->whereNull('r.prep');
        } elseif (isset(self::PREP_STAGES[$prep])) {
            $q->whereIn('r.prep', self::PREP_STAGES[$prep]);
        }

        match ((string) $request->query('state', '')) {
            'cancelled' => $q->whereIn('r.status', self::CANCELLED),
            'live' => $q->whereNotIn('r.status', self::CANCELLED),
            default => null,
        };

        return $q;
    }

    /** وبترتيب الشاشة — ما يرقّمه الجدول وما يُصدَّر */
    public static function query(Request $request, ?string $fixedSource = null): Builder
    {
        return self::sorted(self::filtered($request, $fixedSource));
    }

    /**
     * «الموظّف»: معرّفُ حساب، أو «الموقع»، أو «غير مرتبط بحساب موظف» — وفيه
     * الطلبُ القديم الذي حُفظ اسمُ بائعه بلا حسابه، فلا يبقى بلا مرشِّحٍ يبلغه.
     *
     * والمعرّفُ من `user_id` للطلب و`created_by` للفاتورة — لا من الاسم: اسمان
     * متشابهان لموظّفَين لا يختلطان، واسمٌ تغيّر لا يقطع تاريخ صاحبه.
     */
    private static function bySeller(Builder $q, string $seller): void
    {
        if ($seller === 'website') {
            $q->where('r.source', SalesChannel::WEBSITE);
        } elseif ($seller === 'none') {
            $q->whereNull('r.seller_id')->where('r.source', '!=', SalesChannel::WEBSITE);
        } elseif (preg_match('/^\d+$/', $seller)) {
            $q->where('r.seller_id', (int) $seller);
        }
    }

    /**
     * الطلبات — كلُّ ما لم يُعلَّق، بأيّ حالٍ كان.
     *
     * والفاتورةُ التي تغطّيه (غيرُ الملغاة) تُقرأ في الصفّ نفسه: رقمُها، ومن
     * الصادرة منها حالُ سداده — فالمالُ يُحصَّل عليها لا على الطلب. وحين تغطّيه
     * فاتورةٌ صادرة يبقى ما حُصِّل منه وما بقي فارغين هنا: يُوزَّعان من الفاتورة
     * في `coveredShares`، لا يُقرآن مرّتين.
     */
    private static function orders(int $bid, ?int $branch, ReportingPeriod $period, ?string $source): Builder
    {
        $issued = "'".CustomerInvoice::ISSUED."'";
        $cancelled = "'".OrderStatus::CANCELLED."'";
        $live = 'customer_invoices.status = '.$issued;
        $unpaid = "o.payment_status = 'غير مدفوع'";
        $out = CustomerInvoice::outstandingSql();
        $paidInv = CustomerInvoice::paidSql();

        $q = DB::table('orders as o')
            ->leftJoin('customer_invoices', fn ($j) => $j->on('customer_invoices.id', '=', DB::raw(
                '(SELECT MIN(l.customer_invoice_id) FROM customer_invoice_orders l'
                .' JOIN customer_invoices x ON x.id = l.customer_invoice_id'
                ." WHERE l.order_id = o.id AND x.status <> '".CustomerInvoice::CANCELLED."')"
            )))
            ->where('o.business_id', $bid)
            ->where('o.is_held', false)
            ->when($branch, fn ($w) => $w->where('o.branch_id', $branch))
            ->selectRaw(implode(', ', [
                "'".self::ORDER."' as kind",
                'o.id as id',
                'o.number as number',
                "COALESCE(o.channel, '".SalesChannel::UNKNOWN."') as source",
                'o.customer_name as customer',
                'o.user_id as seller_id',
                'o.employee_name as seller',
                'o.branch_id as branch_id',
                'o.branch as branch',
                'o.ordered_at as sold_at',
                'o.total as amount',
                'o.status as status',
                'customer_invoices.id as cover_id',
                'customer_invoices.number as cover_number',
                "CASE WHEN o.status = {$cancelled} THEN '".self::VOID."'"
                    ." WHEN {$live} THEN (CASE WHEN {$out} <= 0.0005 THEN '".self::PAID."'"
                    ." WHEN {$paidInv} > 0 THEN '".self::PARTIAL."' ELSE '".self::UNPAID."' END)"
                    ." WHEN {$unpaid} THEN '".self::UNPAID."'"
                    ." WHEN COALESCE(o.balance_due, 0) > 0.0005 THEN '".self::PARTIAL."'"
                    ." ELSE '".self::PAID."' END as pay_state",
                'CASE WHEN o.scheduled_for IS NULL AND o.fulfillment_type IS NULL THEN NULL ELSE o.status END as prep',
                'NULL as prep_id',
                "CASE WHEN o.status = {$cancelled} THEN 0 WHEN {$live} THEN NULL"
                    ." WHEN {$unpaid} THEN 0 ELSE o.total - COALESCE(o.balance_due, 0) END as paid",
                "CASE WHEN o.status = {$cancelled} THEN 0 WHEN {$live} THEN NULL"
                    ." WHEN {$unpaid} THEN o.total ELSE COALESCE(o.balance_due, 0) END as due",
                "CASE WHEN {$live} AND o.status <> {$cancelled} THEN NULL ELSE 0 END as credited",
            ]));

        if ($source !== null) {
            $source === SalesChannel::UNKNOWN
                ? $q->whereNull('o.channel')
                : $q->where('o.channel', $source);
        }

        return $period->bound($q, 'o.ordered_at');
    }

    /**
     * الفواتيرُ المستقلّة — صادرةً أو ملغاة، ولا تغطّي طلبًا.
     *
     * ومنشئُها من `users` بلا شرط الحذف: موظّفٌ غادر يبقى اسمُه على ما كتب.
     */
    private static function invoices(int $bid, ?int $branch, ReportingPeriod $period): Builder
    {
        $issued = "customer_invoices.status = '".CustomerInvoice::ISSUED."'";
        $out = CustomerInvoice::outstandingSql();
        $paid = CustomerInvoice::paidSql();
        $soldAt = DB::connection()->getDriverName() === 'pgsql'
            ? 'CAST(customer_invoices.issued_at AS timestamp)'
            : 'customer_invoices.issued_at';

        $q = DB::table('customer_invoices')
            ->leftJoin('customers as c', 'c.id', '=', 'customer_invoices.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'customer_invoices.created_by')
            ->leftJoin('branches as b', 'b.id', '=', 'customer_invoices.branch_id')
            ->leftJoin('customer_invoice_preparations as p', 'p.customer_invoice_id', '=', 'customer_invoices.id')
            ->where('customer_invoices.business_id', $bid)
            ->where('customer_invoices.status', '!=', CustomerInvoice::DRAFT)
            ->whereNotExists(fn ($w) => $w->selectRaw('1')->from('customer_invoice_orders as l')
                ->whereColumn('l.customer_invoice_id', 'customer_invoices.id'))
            ->when($branch, fn ($w) => $w->where('customer_invoices.branch_id', $branch))
            ->selectRaw(implode(', ', [
                "'".self::INVOICE."' as kind",
                'customer_invoices.id as id',
                'customer_invoices.number as number',
                "'".self::SOURCE_INVOICE."' as source",
                'COALESCE(c.name, customer_invoices.customer_name) as customer',
                'customer_invoices.created_by as seller_id',
                'u.name as seller',
                'customer_invoices.branch_id as branch_id',
                'b.name as branch',
                "{$soldAt} as sold_at",
                'customer_invoices.total as amount',
                'customer_invoices.status as status',
                'customer_invoices.id as cover_id',
                'customer_invoices.number as cover_number',
                "CASE WHEN NOT ({$issued}) THEN '".self::VOID."'"
                    ." WHEN {$out} <= 0.0005 THEN '".self::PAID."'"
                    ." WHEN {$paid} > 0 THEN '".self::PARTIAL."' ELSE '".self::UNPAID."' END as pay_state",
                "CASE WHEN p.id IS NULL THEN NULL WHEN p.activated_at IS NULL THEN '".self::AWAITING_PAYMENT."' ELSE p.status END as prep",
                'p.id as prep_id',
                "CASE WHEN {$issued} THEN {$paid} ELSE 0 END as paid",
                "CASE WHEN {$issued} AND {$out} > 0 THEN {$out} ELSE 0 END as due",
                "CASE WHEN {$issued} THEN ".CustomerInvoice::creditedSql().' ELSE 0 END as credited',
            ]));

        return $period->bound($q, 'customer_invoices.issued_at', date: true);
    }

    /**
     * صفحةٌ من السجلّ — بصفوفها جاهزةً للشاشة.
     *
     * والحسابُ المسجَّل ساعةَ البيع يُقرأ للصفحة كلِّها باستعلامٍ واحد.
     */
    public static function page(Request $request, int $per, ?string $fixedSource = null): LengthAwarePaginator
    {
        $page = self::query($request, $fixedSource)->paginate($per)->withQueryString();
        $accounts = self::accounts(collect($page->items()));

        return $page->through(fn ($r) => self::row($r, $accounts));
    }

    /** الصفوفُ كلُّها بترتيبها — لورقة PDF والتصدير */
    public static function rows(Request $request, ?string $fixedSource = null): array
    {
        $rows = self::query($request, $fixedSource)->get();
        $accounts = self::accounts($rows);

        return $rows->map(fn ($r) => self::row($r, $accounts))->all();
    }

    /**
     * من كان مسجَّلًا حين أتمّ الصندوقُ البيعة — من سطر «أتمّ بيعًا».
     *
     * `PosCashier` يكتب على الطلب الكاشيرَ المختار، والسجلُّ يكتب الحسابَ الداخل.
     * فإن اختلفا قيل الاثنان ولم يُخلط أحدُهما بالآخر. وما لا سطرَ له (طلبُ
     * الموقع، أو بيعةٌ قبل السجلّ) لا يُخمَّن له حساب.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array{id: ?int, name: ?string}>
     */
    private static function accounts(Collection $rows): array
    {
        $ids = $rows->where('kind', self::ORDER)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('activity_logs')
            ->where('business_id', (int) (auth()->user()->business_id ?? Demo::bid()))
            ->where('action', 'checkout')
            ->whereNull('subject_type')
            ->whereIn('subject_id', $ids)
            ->orderBy('id')
            ->get(['subject_id', 'user_id', 'user_name'])
            ->mapWithKeys(fn ($a) => [(int) $a->subject_id => ['id' => $a->user_id ? (int) $a->user_id : null, 'name' => $a->user_name]])
            ->all();
    }

    /** صفُّ الشاشة — وصفُّ الملفّ منه */
    public static function row(object $r, array $accounts = []): array
    {
        $order = $r->kind === self::ORDER;
        $account = $order ? ($accounts[(int) $r->id] ?? null) : null;

        return [
            'key' => $r->kind.':'.$r->id,
            'kind' => $r->kind,
            'id' => (int) $r->id,
            'number' => $r->number,
            'type' => self::typeLabel($r),
            'source' => $r->source,
            'source_label' => $order ? SalesChannel::label($r->source === SalesChannel::UNKNOWN ? null : $r->source) : 'فاتورة عميل',
            'customer' => $order ? Demo::customerLabel($r->customer) : ($r->customer ?: '—'),
            // البائعُ كما حُفظ — وطلبُ الموقع بلا بائع، وما لم يُعرف «غير محدد»
            'seller_kind' => match (true) {
                $r->source === SalesChannel::WEBSITE => 'website',
                filled($r->seller) => 'person',
                default => 'unknown',
            },
            'seller' => $r->source !== SalesChannel::WEBSITE && filled($r->seller) ? (string) $r->seller : null,
            'seller_id' => $r->seller_id ? (int) $r->seller_id : null,
            'account' => $account && $account['id'] !== null && $account['id'] !== ($r->seller_id ? (int) $r->seller_id : null)
                ? $account['name']
                : null,
            'branch' => $r->branch ?: null,
            'date' => self::date($r->sold_at, $order),
            'amount' => round((float) $r->amount, 3),
            'credited' => $r->credited !== null ? round((float) $r->credited, 3) : null,
            'pay_state' => $r->pay_state,
            // والمستندُ الذي يُحصَّل عليه — فاتورةٌ تغطّي الطلب
            'cover' => $order && $r->cover_id ? ['id' => (int) $r->cover_id, 'number' => $r->cover_number] : null,
            'prep' => $r->prep,
            'prep_stage' => self::stage($r->prep),
            'status' => $r->status,
            'cancelled' => in_array($r->status, self::CANCELLED, true),
            'prep_id' => $r->prep_id ? (int) $r->prep_id : null,
        ];
    }

    /** النوعُ الحقيقيّ — والطلبُ لا يُسمّى «فاتورة عميل» ولو غطّته واحدة */
    private static function typeLabel(object $r): string
    {
        if ($r->kind === self::INVOICE) {
            return 'فاتورة عميل';
        }

        return match ($r->source) {
            SalesChannel::POS => 'بيع نقطة البيع',
            SalesChannel::WEBSITE => 'طلب موقع',
            default => 'طلب بيع',
        };
    }

    /** مرحلةُ التجهيز من حالها — أو «بلا تجهيز» */
    public static function stage(?string $prep): string
    {
        if ($prep === null) {
            return 'none';
        }

        foreach (self::PREP_STAGES as $stage => $statuses) {
            if (in_array($prep, $statuses, true)) {
                return $stage;
            }
        }

        return 'waiting';
    }

    /** وساعةُ الطلب تُقال، والفاتورةُ بيومها — لا ساعةَ لها */
    private static function date(mixed $at, bool $withTime): string
    {
        if (! $at) {
            return '—';
        }

        $at = Carbon::parse($at);

        return $withTime ? $at->format('Y-m-d H:i') : $at->format('Y-m-d');
    }

    /**
     * المجاميعُ على ما رُشّح كلِّه — لا على الصفحة.
     *
     *   - المبيعات: قيمةُ غير الملغى ناقصًا إشعاراتِ الدائن عليه.
     *   - المحصَّل والمتبقّي: من موضعهما. الطلبُ غيرُ المفوتَر من أعمدته
     *     (`payment_status` و`balance_due`)، والفاتورةُ من تحصيلاتها، والطلبُ
     *     المفوتَر من فاتورته (`coveredShares`) — كلُّ مالٍ يُعدّ مرّة.
     *
     * @return array{count: int, sales: float, collected: float, outstanding: float, cancelled: int}
     */
    public static function totals(Request $request, ?string $fixedSource = null): array
    {
        $base = self::filtered($request, $fixedSource);
        $void = implode(', ', array_map(fn ($s) => "'{$s}'", self::CANCELLED));

        $agg = (clone $base)->selectRaw(implode(', ', [
            'COUNT(*) as n',
            "SUM(CASE WHEN r.status IN ({$void}) THEN 0 ELSE r.amount END) as gross",
            'SUM(COALESCE(r.credited, 0)) as credited',
            'SUM(COALESCE(r.paid, 0)) as paid',
            'SUM(COALESCE(r.due, 0)) as due',
            "SUM(CASE WHEN r.status IN ({$void}) THEN 1 ELSE 0 END) as voided",
        ]))->first();

        $covered = (clone $base)->where('r.kind', self::ORDER)->whereNull('r.paid')
            ->whereNotIn('r.status', self::CANCELLED)->pluck('r.id')->map(fn ($id) => (int) $id)->flip();
        $shares = $covered->isEmpty() ? [] : self::coveredShares((clone $base)->where('r.kind', self::ORDER)->whereNull('r.paid')->select('r.id'));

        $credited = (float) ($agg->credited ?? 0);
        $paid = (float) ($agg->paid ?? 0);
        $due = (float) ($agg->due ?? 0);
        foreach ($shares as $orderId => $share) {
            if ($covered->has($orderId)) {
                $credited += $share['credited'];
                $paid += $share['paid'];
                $due += $share['due'];
            }
        }

        return [
            'count' => (int) ($agg->n ?? 0),
            'sales' => round((float) ($agg->gross ?? 0) - $credited, 3),
            'collected' => round($paid, 3),
            'outstanding' => round($due, 3),
            'cancelled' => (int) ($agg->voided ?? 0),
        ];
    }

    /**
     * ما يخصّ كلَّ طلبٍ من فاتورته الصادرة — إشعاراتُها اليدويّة ثمّ تحصيلُها.
     *
     * فاتورةٌ تجمع طلبات الشهر يُحصَّل عليها مبلغٌ واحد. فيُوزَّع على طلباتها
     * الحيّة من الأقدم — قاعدةُ التحصيل نفسها (`CustomerPayments::allocate`
     * تبدأ بالأقدم استحقاقًا). فما يُعدّ لطلبٍ لا يُعدّ لغيره، ومجموعُها
     * مالُ الفاتورة بعينه. وإشعارُ إلغاء الطلب لا يُوزَّع: الطلبُ الملغى خرج
     * من المجموع بحاله (`CustomerInvoices::NOTE_ORDER_CANCELLED`).
     *
     * @param  Builder  $orderIds  معرّفاتُ الطلبات المفوترة فيما رُشّح
     * @return array<int, array{paid: float, credited: float, due: float}>
     */
    private static function coveredShares(Builder $orderIds): array
    {
        $invoiceIds = DB::table('customer_invoice_orders as l')
            ->join('customer_invoices', 'customer_invoices.id', '=', 'l.customer_invoice_id')
            ->whereIn('l.order_id', $orderIds)
            ->where('customer_invoices.status', CustomerInvoice::ISSUED)
            ->select('customer_invoices.id');

        $pools = DB::table('customer_invoices')->whereIn('id', $invoiceIds)
            ->selectRaw('id, '.CustomerInvoice::paidSql().' as paid, (SELECT COALESCE(SUM(n.amount), 0) FROM customer_credit_notes n'
                ." WHERE n.customer_invoice_id = customer_invoices.id AND n.source = '".CustomerInvoices::NOTE_MANUAL."') as credited")
            ->get()->keyBy('id');

        $members = DB::table('customer_invoice_orders as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('l.customer_invoice_id', $pools->keys()->all())
            ->where('o.status', '!=', OrderStatus::CANCELLED)
            ->orderBy('o.ordered_at')->orderBy('o.id')
            ->get(['l.customer_invoice_id', 'o.id', 'o.total']);

        $shares = [];
        foreach ($members->groupBy('customer_invoice_id') as $invoiceId => $orders) {
            $credit = (float) ($pools[$invoiceId]->credited ?? 0);
            $paid = (float) ($pools[$invoiceId]->paid ?? 0);

            foreach ($orders as $o) {
                $total = (float) $o->total;
                $c = min($credit, $total);
                $credit -= $c;
                $p = min($paid, $total - $c);
                $paid -= $p;
                $shares[(int) $o->id] = ['paid' => round($p, 3), 'credited' => round($c, 3), 'due' => round($total - $c - $p, 3)];
            }
        }

        return $shares;
    }

    /**
     * من يُختار في «الموظّف» — من باع فعلًا ومن يعمل اليوم.
     *
     * من المتجر وحده، والمحذوفُ بحسابه معهم: مبيعاتُه القديمة باقية، ويُوسَم
     * «غير نشط». ومديرُ المنصّة لا يُعرض.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function sellers(int $bid): array
    {
        $sold = DB::table('orders')->where('business_id', $bid)->whereNotNull('user_id')->distinct()->pluck('user_id')
            ->merge(DB::table('customer_invoices')->where('business_id', $bid)->whereNotNull('created_by')->distinct()->pluck('created_by'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        return User::withTrashed()->where('business_id', $bid)->where('role', '!=', 'super_admin')
            ->where(fn ($w) => $w->whereIn('id', $sold)->orWhere(fn ($a) => $a->whereNull('deleted_at')->where('status', 'نشط')))
            ->orderBy('name')->get(['id', 'name', 'status', 'deleted_at'])
            ->map(fn (User $u) => [
                'value' => (string) $u->id,
                'label' => $u->trashed() || $u->status !== 'نشط' ? $u->name.' ('.__('غير نشط').')' : $u->name,
            ])->all();
    }

    /** ما يُصدَّر: صفوفُ الشاشة كلُّها، والمجاميعُ على ما رُشّح */
    public static function export(Request $request, ?string $fixedSource = null): Dataset
    {
        $totals = self::totals($request, $fixedSource);

        return new Dataset(
            title: __('جميع عمليات البيع'),
            file: 'sales-register',
            fileParts: self::period($request)->kind === 'all' ? [now()->format('Y-m-d')] : self::period($request)->fileParts(),
            filters: self::filterLines($request, $fixedSource),
            perBranch: true,
            columns: [
                __('رقم العملية') => Workbook::CODE,
                __('النوع') => Workbook::TEXT,
                __('المصدر') => Workbook::TEXT,
                __('العميل') => Workbook::TEXT,
                __('الموظف') => Workbook::TEXT,
                __('الفرع') => Workbook::TEXT,
                __('التاريخ') => Workbook::DATETIME,
                Workbook::money('المبلغ') => Workbook::MONEY,
                __('السداد') => Workbook::TEXT,
                __('التجهيز') => Workbook::TEXT,
                __('الحالة') => Workbook::TEXT,
                __('الفاتورة') => Workbook::TEXT,
            ],
            rows: function () use ($request, $fixedSource) {
                foreach (self::query($request, $fixedSource)->cursor() as $r) {
                    $row = self::row($r);
                    yield [
                        'cells' => [
                            $row['number'], __($row['type']), __($row['source_label']), $row['customer'],
                            match ($row['seller_kind']) {
                                'website' => __('الموقع الإلكتروني'),
                                'unknown' => __('غير محدد'),
                                default => $row['seller'],
                            },
                            $row['branch'] ?? '—', $row['date'], $row['amount'], __((string) $row['pay_state']),
                            $row['prep'] !== null ? __($row['prep']) : '—', __((string) $row['status']),
                            $row['cover']['number'] ?? '—',
                        ],
                        'fill' => $row['cancelled'] ? 'FEF3C7' : null,
                    ];
                }
            },
            totals: [
                [__('عدد العمليات'), $totals['count'], Workbook::INT],
                [Workbook::money('قيمة المبيعات'), $totals['sales']],
                [Workbook::money('المحصّل'), $totals['collected']],
                [Workbook::money('المتبقي'), $totals['outstanding']],
                [__('منها ملغاة'), $totals['cancelled'], Workbook::INT],
            ],
        );
    }

    /** المرشِّحاتُ الفعّالة كما يقرؤها التاجر */
    private static function filterLines(Request $request, ?string $fixedSource): array
    {
        $source = self::source($request, $fixedSource);
        $seller = (string) $request->query('seller', '');
        $prep = (string) $request->query('prep', '');

        return [
            __('البحث') => Search::term($request),
            __('المصدر') => match ($source) {
                null => null,
                self::SOURCE_INVOICE => __('فاتورة عميل'),
                default => __(SalesChannel::label($source === SalesChannel::UNKNOWN ? null : $source)),
            },
            __('الموظف') => match (true) {
                $seller === '' => null,
                $seller === 'website' => __('الموقع الإلكتروني'),
                $seller === 'none' => __('غير مرتبط بحساب موظف'),
                default => User::withTrashed()->where('business_id', (int) (auth()->user()->business_id ?? Demo::bid()))->whereKey((int) $seller)->value('name'),
            },
            __('السداد') => in_array($request->query('payment_state'), self::PAYMENT_STATES, true) ? __((string) $request->query('payment_state')) : null,
            __('التجهيز') => isset(self::PREP_LABELS[$prep]) ? __(self::PREP_LABELS[$prep]) : null,
            __('الحالة') => match ((string) $request->query('state', '')) {
                'cancelled' => __('ملغاة'),
                'live' => __('سارية'),
                default => null,
            },
            __('الفترة') => ($p = self::period($request))->kind === 'all' ? null : $p->label(),
        ];
    }
}

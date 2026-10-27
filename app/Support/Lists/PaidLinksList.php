<?php

namespace App\Support\Lists;

use App\Models\CustomerPaymentLink;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Exports\Workbook;
use App\Support\ListFilters;
use App\Support\Search;
use App\Support\Sort;
use Carbon\CarbonInterface;
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
                ->orWhere('l.reference', $like, "%{$s}%")
                // وباسم منشئ الرابط — كما يُبحث الطلبُ باسم موظّفه
                ->orWhere('u.name', $like, "%{$s}%"));
        }

        // الفترةُ نفسُها التي تُقرأ بها الطلبات — على يوم الدفع
        ListFilters::orderPeriod($request)->bound($q, 'l.paid_at');

        return $q;
    }

    /**
     * ما حُصّل عبر روابط الدفع بين لحظتين — بقاعدة «مدفوع» نفسِها (`paid`).
     *
     * يقرؤه سطرُ «روابط الدفع» تحت «مبيعات اليوم» في اللوحة. واليومُ من وقت
     * الدفع المؤكَّد (`paid_at`، بتوقيت المتجر) لا من يوم فُتح الرابط. والفرعُ
     * فرعُ الفاتورة كما في القائمة: رابطٌ بلا فاتورة في «كل الفروع» وحده.
     */
    public static function collected(int $bid, CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): float
    {
        return round((float) self::paid($bid)
            ->where('l.paid_at', '>=', $from)
            ->where('l.paid_at', '<', $to)
            ->when($branchId !== null, fn ($q) => $q->where('i.branch_id', $branchId))
            ->sum('l.amount'), 3);
    }

    /**
     * ما يُنتظر عبر روابط الدفع الآن — رابطٌ حقيقيٌّ معلّقٌ لم تنقضِ مهلتُه.
     *
     * مالٌ طُلب ولم يصل: يُقال بجوار المبيعات ولا يدخلها. ويُقرأ بلا كتابة —
     * المنتهي يُستثنى بمهلته هنا، لا بـ`PaymentLinks::expireDue` الذي يكتب.
     */
    public static function awaiting(int $bid, ?int $branchId = null): float
    {
        return round((float) DB::table('customer_payment_links as l')
            ->leftJoin('customer_invoices as i', fn ($j) => $j->on('i.id', '=', 'l.customer_invoice_id')
                ->on('i.business_id', '=', 'l.business_id'))
            ->where('l.business_id', $bid)
            ->where('l.status', CustomerPaymentLink::PENDING)
            ->where('l.is_live', true)
            ->where(fn ($q) => $q->whereNull('l.expires_at')->orWhere('l.expires_at', '>', now()))
            ->when($branchId !== null, fn ($q) => $q->where('i.branch_id', $branchId))
            ->sum('l.amount'), 3);
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
            // ومنشئُ الرابط من متجره وحده — حسابٌ من متجرٍ آخر لا يُقرأ اسمُه هنا
            ->leftJoin('users as u', fn ($j) => $j->on('u.id', '=', 'l.created_by')->on('u.business_id', '=', 'l.business_id'))
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
                'c.name as customer_name', 'u.name as creator_name',
            ]);
    }

    public static function page(Request $request, int $per = 10): LengthAwarePaginator
    {
        $page = self::query($request)->paginate($per)->withQueryString();
        $context = self::context(collect($page->items()));

        return $page->through(fn ($r) => self::row($r, $context));
    }

    /** صفوفُ الشاشة كلُّها — بلا ترقيم — لورقة PDF */
    public static function rows(Request $request): array
    {
        $rows = self::query($request)->get();
        $context = self::context($rows);

        return $rows->map(fn ($r) => self::row($r, $context))->all();
    }

    /**
     * صفُّ تحصيل — يقول ما دُفع، وعن أيّ بيعٍ دُفع.
     *
     * والفاتورةُ التي صدرت عن طلبٍ تُذكر طلباتُها: البيعُ هناك في «المبيعات»
     * صفًّا واحدًا، وهذا سدادُه لا بيعٌ ثانٍ.
     *
     * ═══ وما حول التحصيل — من مصدره لا ظنًّا ═══
     *
     * - **الموظف:** منشئُ الرابط (`created_by`) — لا من أكّد طلبًا ولا من قبض.
     * - **الفرع:** فرعُ الفاتورة، ثمّ فرعُ مهمّة تجهيزها، ثمّ فرعُ طلبها.
     *   وطلباتٌ من فروعٍ شتّى: «فروع متعددة» وأسماؤها. ولا فرعَ للموظّف يُنسب.
     * - **المنتجات:** بنودُ الفاتورة نفسِها — لا بنودُ طلباتها فوقها، فلا يُعدّ
     *   البيعُ مرّتين.
     * - **التسليم:** موعدُ مهمّة التجهيز، ثمّ موعدُ الطلب، و«مواعيد متعددة».
     *
     * والرابطُ بلا فاتورةٍ لا فرعَ له ولا منتجاتٍ ولا موعد: لا يُخترع.
     *
     * @param  array<int, array<string, mixed>>  $context  `context()` — بمعرّف الفاتورة
     */
    public static function row(object $r, array $context = []): array
    {
        $meta = is_string($r->meta) ? (json_decode($r->meta, true) ?: []) : (array) ($r->meta ?? []);
        $invoice = $r->customer_invoice_id ? (int) $r->customer_invoice_id : null;
        $ctx = $invoice ? ($context[$invoice] ?? []) : [];
        $creator = isset($r->creator_name) && trim((string) $r->creator_name) !== '' ? (string) $r->creator_name : null;
        [$branch, $branches] = self::branchOf($r->branch_name ?? null, $ctx);

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
            'orders' => $ctx['orders'] ?? [],
            // منشئُ الرابط — وهو «الموظف» في صفّ التحصيل
            'creator' => $creator,
            'branch' => $branch,
            // وأسماءُ الفروع حين تعدّدت — تفاصيلُ «فروع متعددة»
            'branches' => $branches,
            // بنودُ فاتورته — والرابطُ بلا فاتورةٍ «لا توجد منتجات مرتبطة» (`null`)
            'products' => isset($ctx['products'])
                ? SaleCells::products($ctx['products']['lines'], $ctx['products']['pieces'], $ctx['products']['addons'])
                : null,
            'handover' => self::handoverOf($ctx),
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
                __('منشئ رابط الدفع') => Workbook::TEXT,
                __('الفرع') => Workbook::TEXT,
                __('المنتجات') => Workbook::TEXT,
                __('التسليم / الاستلام') => Workbook::TEXT,
            ],
            rows: function () use ($request) {
                // دفعاتٍ لا صفًّا صفًّا: ما حول كلّ دفعةٍ ثلاثةُ أسئلة (`context`)
                foreach (self::query($request)->lazy(500)->chunk(500) as $chunk) {
                    $rows = collect($chunk->all());
                    $context = self::context($rows);
                    foreach ($rows as $r) {
                        $row = self::row($r, $context);
                        yield [
                            'cells' => [
                                $row['id'], $row['reference'], $row['customer'], __($row['type']),
                                $row['invoice']['number'] ?? '—', $row['amount'], $row['date'],
                                $row['review'] ? __('مدفوع — يحتاج مراجعة') : __($row['status']),
                                $row['creator'] ?? '—', AllSalesList::branchCell($row),
                                $row['products']['label'] ?? __('لا توجد منتجات مرتبطة'), AllSalesList::handoverCell($row),
                            ],
                            'fill' => null,
                        ];
                    }
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
     *
     * ═══ والمتجرُ قيدُه لا الفرعُ المختار ═══
     *
     * الفرعُ في الشريط (`Demo::currentBranchId`، جلسةٌ يختارها المستخدم)
     * مُرشِّحُ قوائم لا صلاحية — وكلُّ صفحة تفاصيلٍ في النظام تُفتح بمتجرها
     * وحده: الطلبُ (`Demo::orderDetails`) والفاتورةُ (`CustomerInvoiceController::find`)
     * وسندُ القبض (`paymentShow`). وإسنادُ الموظّف إلى فروعٍ (`branch_user` و
     * `User::worksAt`) قيدٌ على البيع في الصندوق ونقل المخزون وتقرير التكاليف،
     * لا على قراءة «المبيعات» ولا تفاصيلها. فيُفتح التحصيلُ كما تُفتح فاتورتُه:
     * بمتجره — ولو حُجب الفرعُ هنا لَفُتحت الفاتورةُ نفسُها بعنوانها وأُغلق تحصيلُها.
     */
    public static function detail(int $id): ?array
    {
        $r = self::columns(self::paid((int) (auth()->user()->business_id ?? Demo::bid())))->where('l.id', $id)->first();

        if ($r === null) {
            return null;
        }

        return self::row($r, self::context(collect([$r]))) + [
            'link_reference' => $r->reference,
            'description' => $r->description,
        ];
    }

    /**
     * ما حول تحصيلات صفحةٍ كاملة — ثلاثةُ أسئلةٍ مهما كثرت الصفوف.
     *
     * لكلّ فاتورة: أرقامُ طلباتها وفروعُها ومواعيدُها، وبنودُها (منتجاتٌ
     * وقطعٌ وإضافات)، ومهمّةُ تجهيزها. وكلُّ سؤالٍ بمتجر المستخدم: فاتورةٌ أو
     * طلبٌ أو فرعٌ من متجرٍ آخر لا يدخل الصفّ ولو حمل الرقمَ نفسَه.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function context($rows): array
    {
        $ids = collect($rows)->pluck('customer_invoice_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $bid = (int) (auth()->user()->business_id ?? Demo::bid());
        $out = array_fill_keys($ids, ['orders' => [], 'order_branches' => [], 'schedules' => []]);

        $orders = DB::table('customer_invoice_orders as x')
            ->join('orders as o', 'o.id', '=', 'x.order_id')
            ->join('customer_invoices as ci', fn ($j) => $j->on('ci.id', '=', 'x.customer_invoice_id')->on('ci.business_id', '=', 'o.business_id'))
            ->whereIn('x.customer_invoice_id', $ids)
            ->where('o.business_id', $bid)
            ->orderBy('o.id')
            ->get(['x.customer_invoice_id', 'o.number', 'o.branch', 'o.fulfillment_type', 'o.scheduled_for', 'o.scheduled_until']);

        foreach ($orders as $o) {
            $at = &$out[(int) $o->customer_invoice_id];
            $at['orders'][] = (string) $o->number;
            if (filled($o->branch) && ! in_array((string) $o->branch, $at['order_branches'], true)) {
                $at['order_branches'][] = (string) $o->branch;
            }
            $handover = SaleCells::handover(
                $o->fulfillment_type,
                $o->scheduled_for !== null ? Carbon::parse($o->scheduled_for) : null,
                $o->scheduled_until !== null ? Carbon::parse($o->scheduled_until) : null,
            );
            if ($handover !== null) {
                $at['schedules'][] = $handover + ['order' => (string) $o->number];
            }
            unset($at);
        }

        // بنودُ الفاتورة نفسِها: المنتجُ بندٌ ليس إضافةً ولا تابعًا لصنف
        $product = 't.addon_id IS NULL AND t.parent_item_id IS NULL';
        $items = DB::table('customer_invoice_items as t')
            ->join('customer_invoices as ci', 'ci.id', '=', 't.customer_invoice_id')
            ->where('ci.business_id', $bid)
            ->whereIn('t.customer_invoice_id', $ids)
            ->groupBy('t.customer_invoice_id')
            ->selectRaw("t.customer_invoice_id, SUM(CASE WHEN {$product} THEN 1 ELSE 0 END) AS product_lines, "
                ."SUM(CASE WHEN {$product} THEN t.quantity ELSE 0 END) AS pieces, "
                ."SUM(CASE WHEN {$product} THEN 0 ELSE 1 END) AS addon_lines")
            ->get();

        foreach ($items as $i) {
            $out[(int) $i->customer_invoice_id]['products'] = [
                'lines' => (int) $i->product_lines, 'pieces' => (float) $i->pieces, 'addons' => (int) $i->addon_lines,
            ];
        }

        $preps = DB::table('customer_invoice_preparations as r')
            ->leftJoin('branches as b', fn ($j) => $j->on('b.id', '=', 'r.branch_id')->on('b.business_id', '=', 'r.business_id'))
            ->where('r.business_id', $bid)
            ->whereIn('r.customer_invoice_id', $ids)
            ->get(['r.customer_invoice_id', 'r.fulfillment_type', 'r.scheduled_for', 'b.name as branch_name']);

        foreach ($preps as $p) {
            $out[(int) $p->customer_invoice_id]['prep'] = [
                'branch' => $p->branch_name,
                'handover' => SaleCells::handover($p->fulfillment_type, $p->scheduled_for !== null ? Carbon::parse($p->scheduled_for) : null),
            ];
        }

        return $out;
    }

    /**
     * فرعُ التحصيل — ومعه أسماءُ الفروع حين تعدّدت.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private static function branchOf(?string $invoiceBranch, array $ctx): array
    {
        $fromOrders = $ctx['order_branches'] ?? [];

        return match (true) {
            count($fromOrders) > 1 => [__('فروع متعددة'), $fromOrders],
            filled($invoiceBranch) => [$invoiceBranch, []],
            filled($ctx['prep']['branch'] ?? null) => [$ctx['prep']['branch'], []],
            count($fromOrders) === 1 => [$fromOrders[0], []],
            default => [null, []],
        };
    }

    /** موعدُ التحصيل — مهمّةُ التجهيز، ثمّ طلبُه، و«مواعيد متعددة» بتفاصيلها */
    private static function handoverOf(array $ctx): ?array
    {
        if (($prep = $ctx['prep']['handover'] ?? null) !== null) {
            return $prep;
        }

        $distinct = collect($ctx['schedules'] ?? [])->unique('label')->values();

        return match (true) {
            $distinct->count() > 1 => [
                'kind' => null, 'type' => null, 'when' => null, 'label' => __('مواعيد متعددة'),
                'details' => $distinct->map(fn ($h) => $h['order'].': '.$h['label'])->all(),
            ],
            $distinct->count() === 1 => collect($distinct->first())->except('order')->all(),
            default => null,
        };
    }
}

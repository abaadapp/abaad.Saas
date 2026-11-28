<?php

namespace App\Support;

use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Transaction;
use App\Support\Lists\SalesRegister;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * حالُ سداد الطلب كما يُعرض — من تحصيلاته، لا من حقلٍ يُكتب يومَ البيع.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * `orders.payment_status` يُكتب مرّةً واحدة ساعةَ البيع (`WebCheckout`،
 * `PosController`): «غير مدفوع» لكلّ ما لم يُقبض عند البيع. ومالُ طلب الدفع عند
 * الاستلام والبيع الآجل يُقبض بعدها على فاتورة العميل، ويُخصَّص للفاتورة ولا يصل
 * الطلب. فكانت صفحةُ الطلب والصندوق ولوحةُ التجهيز والإيصال تقول «غير مدفوع» بعد
 * أن قُبض كلُّه، ولوحةُ التجهيز تطلب من المندوب أن يحصّل ما دُفع.
 *
 * ═══ ولماذا لا يُقلب الحقل ═══
 *
 * الحقلُ نفسُه معناه للدفتر «بيعةٌ آجلة»: يقرؤه `Books::recordSale` ليجعل
 * الذمّةَ مدينةً لا الصندوق، والفوترةُ والذممُ وسجلُّ تحصيلات العميل. وقلبُه إلى
 * «مدفوع» ثمّ أيُّ إعادة ترحيلٍ للبيعة (`OrderCorrection::syncBooks`) يُدين
 * الصندوقَ مرّةً ثانية — ثبت ذلك بالأرقام (الجولة 14). فيبقى الحقلُ كما هو،
 * ويُشتقّ ما يُعرض.
 *
 * ═══ القاعدة: واحدةٌ، وهي قاعدة سجلّ كل المبيعات ═══
 *
 *   - الطلبُ الملغى: «ملغى».
 *   - وإن غطّته فاتورةٌ صادرة: من باقيها — مدفوعٌ إن لم يبقَ شيء، وجزئيٌّ إن
 *     قُبض منها شيء، وإلّا غيرُ مدفوع. والشيكُ «تحت التحصيل» مسدَّد كما في
 *     الدفتر، والمرتجعُ من البنك ليس مسدَّدًا (`CustomerInvoice::paidSql`).
 *   - وإلّا فمن الطلب نفسه: غيرُ مدفوعٍ إن بيع آجلًا ولم يُفوتَر، وجزئيٌّ إن
 *     بقي عليه متبقٍّ بعد تصحيح، وإلّا مدفوع.
 *
 * والتعبيرُ مكتوبٌ هنا مرّةً واحدة، ويقرؤه السجلُّ (`SalesRegister::orders`)
 * للعرض والترشيح معًا. فلا تقول شاشةٌ «مدفوع» والسجلُّ «غير مدفوع».
 *
 * وفوقها علامةُ ما رُدّ — لا حالٌ بديلة: «مرتجع» من إشعارٍ دائنٍ سببُه الإرجاع
 * على فاتورته، و«مسترد» من فرقٍ رُدّ للعميل بعد تصحيح (`Transaction::ORDER_REFUND`).
 * وما رُدّ كلُّه يُسمّى باسمه بدل «مدفوع».
 *
 * للقراءة وحدها: لا تكتب صفًّا، ولا تغيّر قيدًا ولا حقلًا.
 */
final class OrderPaymentState
{
    public const PAID = SalesRegister::PAID;

    public const PARTIAL = SalesRegister::PARTIAL;

    public const UNPAID = SalesRegister::UNPAID;

    public const VOID = SalesRegister::VOID;

    public const RETURNED = 'مرتجع';

    public const PARTLY_RETURNED = 'مرتجع جزئيًا';

    public const REFUNDED = 'مسترد';

    public const PARTLY_REFUNDED = 'مسترد جزئيًا';

    /**
     * فاتورةٌ سُدّت وبعضُ سدادها شيكٌ لم يُصرف — لا «مدفوع».
     *
     * الدفترُ يعدّ الشيكَ «تحت التحصيل» مسدِّدًا (`CustomerInvoice::paidSql`): الذمّةُ
     * خرجت من العميل إلى الورقة. وتلك قاعدةٌ محاسبيّة لا تُمسّ قبل اعتماد المحاسب. لكنّ
     * المال لم يصل، فلا تُكتب الشارةُ «مدفوع» عنه: تُقال الورقةُ باسمها، ولا يُطلب من
     * المندوب أن يحصّل شيئًا (الباقي صفر — الورقةُ في يد التاجر).
     */
    public const CHEQUE_SETTLED = 'مسدَّد بشيك تحت التحصيل';

    /** وفاتورةٌ سُدّ بعضُها، وفي ما سُدّ شيكٌ لم يُصرف — علامةٌ بجانب «مدفوع جزئيًا» */
    public const CHEQUE_PENDING = 'شيك تحت التحصيل';

    /** الفاتورةُ التي تغطّي الطلب — أقدمُ فاتورةٍ غيرِ ملغاة تضمّه */
    public static function joinCover(Builder $q, string $o = 'o'): Builder
    {
        return $q->leftJoin('customer_invoices', fn ($j) => $j->on('customer_invoices.id', '=', DB::raw(
            '(SELECT MIN(l.customer_invoice_id) FROM customer_invoice_orders l'
            .' JOIN customer_invoices x ON x.id = l.customer_invoice_id'
            ." WHERE l.order_id = {$o}.id AND x.status <> '".CustomerInvoice::CANCELLED."')"
        )));
    }

    /** الحالُ — بعد `joinCover` */
    public static function stateSql(string $o = 'o'): string
    {
        [$live, $unpaid, $cancelled] = self::parts($o);
        $out = CustomerInvoice::outstandingSql();
        $paidInv = CustomerInvoice::paidSql();

        return "CASE WHEN {$o}.status = {$cancelled} THEN '".self::VOID."'"
            ." WHEN {$live} THEN (CASE WHEN {$out} <= 0.0005 THEN '".self::PAID."'"
            ." WHEN {$paidInv} > 0 THEN '".self::PARTIAL."' ELSE '".self::UNPAID."' END)"
            ." WHEN {$unpaid} THEN '".self::UNPAID."'"
            ." WHEN COALESCE({$o}.balance_due, 0) > 0.0005 THEN '".self::PARTIAL."'"
            ." ELSE '".self::PAID."' END";
    }

    /** ما قُبض من طلبٍ لا تغطّيه فاتورة — و`NULL` لما تغطّيه: نصيبُه من فاتورته (`coveredShares`) */
    public static function paidSql(string $o = 'o'): string
    {
        [$live, $unpaid, $cancelled] = self::parts($o);

        return "CASE WHEN {$o}.status = {$cancelled} THEN 0 WHEN {$live} THEN NULL"
            ." WHEN {$unpaid} THEN 0 ELSE {$o}.total - COALESCE({$o}.balance_due, 0) END";
    }

    public static function dueSql(string $o = 'o'): string
    {
        [$live, $unpaid, $cancelled] = self::parts($o);

        return "CASE WHEN {$o}.status = {$cancelled} THEN 0 WHEN {$live} THEN NULL"
            ." WHEN {$unpaid} THEN {$o}.total ELSE COALESCE({$o}.balance_due, 0) END";
    }

    public static function creditedSql(string $o = 'o'): string
    {
        [$live, , $cancelled] = self::parts($o);

        return "CASE WHEN {$live} AND {$o}.status <> {$cancelled} THEN NULL ELSE 0 END";
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function parts(string $o): array
    {
        return [
            "customer_invoices.status = '".CustomerInvoice::ISSUED."'",
            "{$o}.payment_status = 'غير مدفوع'",
            "'".OrderStatus::CANCELLED."'",
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
     * و`returned` ما كان من تلك الإشعارات سببُه الإرجاع — جزءٌ من `credited`.
     *
     * @param  Builder|array<int, int>  $orderIds  معرّفاتُ الطلبات المفوترة
     * @return array<int, array{paid: float, credited: float, due: float, returned: float}>
     */
    public static function coveredShares(Builder|array $orderIds): array
    {
        $invoiceIds = DB::table('customer_invoice_orders as l')
            ->join('customer_invoices', 'customer_invoices.id', '=', 'l.customer_invoice_id')
            ->whereIn('l.order_id', $orderIds)
            ->where('customer_invoices.status', CustomerInvoice::ISSUED)
            ->select('customer_invoices.id');

        $manual = "n.customer_invoice_id = customer_invoices.id AND n.source = '".CustomerInvoices::NOTE_MANUAL."'";
        $pools = DB::table('customer_invoices')->whereIn('id', $invoiceIds)
            ->selectRaw('id, '.CustomerInvoice::paidSql().' as paid,'
                ." (SELECT COALESCE(SUM(n.amount), 0) FROM customer_credit_notes n WHERE {$manual}) as credited,"
                ." (SELECT COALESCE(SUM(n.amount), 0) FROM customer_credit_notes n WHERE {$manual} AND n.basis = '".CustomerCreditNote::RETURN."') as returned")
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
            $returns = (float) ($pools[$invoiceId]->returned ?? 0);
            $paid = (float) ($pools[$invoiceId]->paid ?? 0);

            foreach ($orders as $o) {
                $total = (float) $o->total;
                $c = min($credit, $total);
                $credit -= $c;
                $r = min($returns, $c);
                $returns -= $r;
                $p = min($paid, $total - $c);
                $paid -= $p;
                $shares[(int) $o->id] = ['paid' => round($p, 3), 'credited' => round($c, 3), 'due' => round($total - $c - $p, 3), 'returned' => round($r, 3)];
            }
        }

        return $shares;
    }

    /** حالُ طلبٍ واحد */
    public static function of(Order $order): array
    {
        return self::forOrders((int) $order->business_id, [(int) $order->id])[(int) $order->id] ?? self::blank();
    }

    /**
     * حالُ طلباتٍ كثيرة بأسئلةٍ معدودة — للوحة التجهيز والسجلّ وما يعرض قائمة.
     *
     * ═══ وما لا يُعرف لطلبٍ بعينه لا يُقال عنه ═══
     *
     * فاتورةٌ تجمع طلبين يُحصَّل عليها مالٌ واحد، والتخصيصُ للفاتورة لا للطلب. فتوزيعُ
     * «للأقدم أوّلًا» (`coveredShares`) تقديرٌ يُجمَع به السجلّ — لا دليلٌ أنّ هذا الطلب
     * بعينه قُبض. فإن كان التقديرُ يقول شيئًا لا تقوله الفاتورة (`estimated`):
     *
     *   - لا يُقال للطلب مقبوضٌ ولا متبقٍّ (`paid` و`due` وأخواتُهما `null`)؛
     *   - وتُقال الفاتورةُ بأرقامها: المقبوضُ والمتبقّي عليها، وكم طلبًا تجمع (`invoice`)؛
     *   - والحالُ حالُ الفاتورة كما كانت (قاعدةُ السجلّ): «مدفوع جزئيًا» عن الطلبين معًا.
     *
     * وما تقوله الفاتورةُ عن كلّ طلبٍ فيها يُقال: فاتورةٌ لم يُقبض منها شيءٌ ولا إشعارَ
     * عليها — كلُّ طلبٍ إجماليُّه باقٍ. وفاتورةٌ سُدّت بلا إشعار — كلُّ طلبٍ مقبوضٌ كلُّه.
     *
     * ═══ والشيكُ تحت التحصيل ═══
     *
     * مسدِّدٌ كما في الدفتر (لا تتغيّر القاعدة)، لكنّه يُفصل عن المال المقبوض فعلًا:
     * `received` ما وصل، و`cheque_pending` الورقةُ التي لم تُصرف. والفاتورةُ التي سُدّت
     * وفيها ورقةٌ كهذه تُكتب «مسدَّد بشيك تحت التحصيل» لا «مدفوع» (`CHEQUE_SETTLED`)،
     * والتي سُدّ بعضُها تحمل علامة «شيك تحت التحصيل». والمرتجعُ من البنك لا يُعدّ شيئًا.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, array<string, mixed>>
     */
    public static function forOrders(int $businessId, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }

        $rows = self::joinCover(DB::table('orders as o'))
            ->where('o.business_id', $businessId)
            ->whereIn('o.id', $orderIds)
            ->selectRaw(implode(', ', [
                'o.id', 'o.total', 'o.status',
                'customer_invoices.id as cover_id',
                'customer_invoices.number as cover_number',
                self::stateSql().' as pay_state',
                self::paidSql().' as paid',
                self::dueSql().' as due',
            ]))->get();

        $covered = $rows->whereNull('paid')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $shares = $covered === [] ? [] : self::coveredShares($covered);

        // فرقٌ رُدّ للعميل بعد تصحيح طلبٍ مدفوع — صفوفُ الحركة الحيّة وحدها
        $refunds = Transaction::where('business_id', $businessId)->whereIn('order_id', $orderIds)
            ->where('kind', Transaction::ORDER_REFUND)
            ->groupBy('order_id')->selectRaw('order_id, COALESCE(SUM(amount), 0) as s')->pluck('s', 'order_id');

        $invoiceIds = $rows->pluck('cover_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $invoices = self::invoiceFacts($invoiceIds);

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $total = round((float) $r->total, 3);
            $state = (string) $r->pay_state;
            $cover = $r->cover_id ? (int) $r->cover_id : null;
            $inv = $cover ? ($invoices[$cover] ?? null) : null;
            $share = $shares[$id] ?? null;
            $refunded = round((float) ($refunds[$id] ?? 0), 3);

            // فاتورةٌ تجمع أكثرَ من طلبٍ حيّ: ما يخصّ كلَّ طلبٍ منها تقديرٌ — إلّا ما تقوله الفاتورة
            $shared = $inv !== null && $state !== self::VOID && $inv['orders'] > 1;
            $estimated = $shared && ! ($inv['manual_credited'] <= 0.0005 && ($inv['paid'] <= 0.0005 || $inv['due'] <= 0.0005));

            if ($estimated) {
                $paid = $due = $credited = $returned = $cheque = null;
            } else {
                $paid = round($r->paid === null ? (float) ($share['paid'] ?? 0) : (float) $r->paid, 3);
                $due = round(max(0.0, $r->due === null ? (float) ($share['due'] ?? 0) : (float) $r->due), 3);
                $credited = round((float) ($share['credited'] ?? 0), 3);
                $returned = round((float) ($share['returned'] ?? 0), 3);
                // ورقةُ الفاتورة لطلبها وحده — وفي فاتورةٍ لطلبين لا تُنسب لأحدهما
                $cheque = $inv !== null && ! $shared ? $inv['cheque_pending'] : 0.0;
                // ولا يزيد على ما قُبض للطلب: الفاتورةُ قد تحمل مالًا فوق باقيها
                $cheque = round(min($cheque, $paid), 3);
            }

            $marker = null;
            $markerOn = null;
            $label = $state;
            if ($state !== self::VOID) {
                $returnedOn = $shared ? $inv['manual_returned'] : (float) $returned;
                if ($returnedOn > 0.0005) {
                    // ولا يبقى من قيمته شيءٌ مبيع: إشعاراتُه — وفيها إرجاع — بلغت إجماليَّه.
                    // وفي فاتورةٍ لطلبين لا يُسمّى طلبٌ «مرتجعًا» كلُّه إلّا إن رُدّت الفاتورةُ كلُّها
                    $whole = $shared ? $inv['manual_credited'] >= $inv['live_total'] - 0.0005 : $credited >= $total - 0.0005;
                    $marker = $whole ? self::RETURNED : self::PARTLY_RETURNED;
                    $markerOn = $shared && ! $whole ? 'invoice' : 'order';
                } elseif ($refunded > 0.0005) {
                    // الطلبُ نقص بالتصحيح: إجماليُّه اليومَ ما بقي، والمردودُ فوقه
                    $whole = $total <= 0.0005;
                    $marker = $whole ? self::REFUNDED : self::PARTLY_REFUNDED;
                    $markerOn = 'order';
                }
                // وما رُدّ كلُّه يُسمّى باسمه — لا «مدفوع» عن مالٍ لم يبقَ منه شيء
                if ($marker === self::RETURNED || $marker === self::REFUNDED) {
                    $label = $marker;
                    $marker = $markerOn = null;
                }
            }

            // الشيكُ تحت التحصيل: يُقال باسمه — على الشارة إن سُدّ به ما بقي، وعلامةً إن سُدّ بعضُه
            $chequeOn = $inv === null || $state === self::VOID ? 0.0 : ($shared ? $inv['cheque_pending'] : (float) $cheque);
            $chequeMark = null;
            if ($chequeOn > 0.0005) {
                if ($label === self::PAID) {
                    $label = self::CHEQUE_SETTLED;
                } elseif ($state === self::PARTIAL) {
                    $chequeMark = self::CHEQUE_PENDING;
                }
            }

            $out[$id] = [
                'state' => $state,
                'label' => $label,
                'marker' => $marker,
                // على من تقع العلامة: الطلبُ نفسُه، أم فاتورتُه التي تجمعه بغيره
                'marker_on' => $markerOn,
                'cheque_marker' => $chequeMark,
                'estimated' => $estimated,
                'paid' => $paid,
                // ما وصل فعلًا — ما قُبض ناقصًا الورقةَ التي لم تُصرف
                'received' => $paid === null ? null : round($paid - (float) $cheque, 3),
                'due' => $due,
                'credited' => $credited,
                'returned' => $returned,
                'refunded' => $refunded,
                'cheque_pending' => $cheque,
                'customer_credit' => $state === self::VOID && $inv !== null && $inv['members'] === 1 ? $inv['paid'] : 0.0,
                'invoice' => $inv === null ? null : [
                    'id' => $cover,
                    'number' => (string) $r->cover_number,
                    // كم طلبًا حيًّا تجمع — وأرقامُها هي إن جمعت أكثرَ من طلب
                    'orders' => $inv['orders'],
                    'total' => $inv['total'],
                    'paid' => $inv['paid'],
                    'received' => round($inv['paid'] - $inv['cheque_pending'], 3),
                    'cheque_pending' => $inv['cheque_pending'],
                    'credited' => $inv['credited'],
                    'due' => $inv['due'],
                ],
            ];
        }

        return $out;
    }

    /**
     * حالُ فواتيرَ مستقلّة كما يعرضها السجلّ — الشارةُ والمقبوضُ فعلًا والورقةُ والباقي.
     *
     * الحالُ نفسُها من السجلّ (`$state`)، وما يُضاف هنا ما يُفصل به الشيكُ عن المال.
     *
     * @param  array<int, string>  $states  الحالُ لكلّ فاتورة كما حسبها السجلّ
     * @return array<int, array<string, mixed>>
     */
    public static function forInvoices(array $states): array
    {
        $facts = self::invoiceFacts(array_keys($states));
        $out = [];
        foreach ($states as $id => $state) {
            $inv = $facts[(int) $id] ?? null;
            $live = $inv !== null && $state !== self::VOID;
            $cheque = $live ? $inv['cheque_pending'] : 0.0;
            $label = $state;
            $mark = null;
            if ($cheque > 0.0005) {
                if ($state === self::PAID) {
                    $label = self::CHEQUE_SETTLED;
                } elseif ($state === self::PARTIAL) {
                    $mark = self::CHEQUE_PENDING;
                }
            }
            $out[(int) $id] = [
                'state' => $state,
                'label' => $label,
                'marker' => null,
                'marker_on' => null,
                'cheque_marker' => $mark,
                'estimated' => false,
                'paid' => $live ? $inv['paid'] : 0.0,
                'received' => $live ? round($inv['paid'] - $cheque, 3) : 0.0,
                'due' => $live ? $inv['due'] : 0.0,
                'cheque_pending' => $cheque,
            ];
        }

        return $out;
    }

    /**
     * أرقامُ الفاتورة كما يقرؤها الدفتر — سؤالٌ واحدٌ لكلّ الفواتير.
     *
     * @param  array<int, int>  $invoiceIds
     * @return array<int, array{total: float, paid: float, credited: float, manual_credited: float, manual_returned: float, due: float, cheque_pending: float, orders: int, members: int, live_total: float}>
     */
    private static function invoiceFacts(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $cancelled = "'".OrderStatus::CANCELLED."'";
        $manual = "n.customer_invoice_id = customer_invoices.id AND n.source = '".CustomerInvoices::NOTE_MANUAL."'";
        $members = 'FROM customer_invoice_orders l JOIN orders mo ON mo.id = l.order_id WHERE l.customer_invoice_id = customer_invoices.id';

        return DB::table('customer_invoices')->whereIn('id', $invoiceIds)
            ->selectRaw(implode(', ', [
                'id', 'total',
                CustomerInvoice::paidSql().' as paid',
                CustomerInvoice::creditedSql().' as credited',
                CustomerInvoice::outstandingSql().' as outstanding',
                "(SELECT COALESCE(SUM(n.amount), 0) FROM customer_credit_notes n WHERE {$manual}) as manual_credited",
                "(SELECT COALESCE(SUM(n.amount), 0) FROM customer_credit_notes n WHERE {$manual} AND n.basis = '".CustomerCreditNote::RETURN."') as manual_returned",
                // ما قُبض بشيكٍ لم يُصرف بعد — مسدَّدٌ في الدفتر، ويُقال للتاجر باسمه
                '(SELECT COALESCE(SUM(a.amount), 0) FROM customer_payment_allocations a JOIN customer_payments p ON p.id = a.customer_payment_id'
                    ." WHERE a.customer_invoice_id = customer_invoices.id AND p.cancelled_at IS NULL AND p.cheque_status = '".Cheques::PENDING."') as cheque_pending",
                "(SELECT COUNT(*) {$members} AND mo.status <> {$cancelled}) as live_orders",
                "(SELECT COUNT(*) {$members}) as member_orders",
                "(SELECT COALESCE(SUM(mo.total), 0) {$members} AND mo.status <> {$cancelled}) as live_total",
            ]))->get()
            ->mapWithKeys(fn ($i) => [(int) $i->id => [
                'total' => round((float) $i->total, 3),
                'paid' => round((float) $i->paid, 3),
                'credited' => round((float) $i->credited, 3),
                'manual_credited' => round((float) $i->manual_credited, 3),
                'manual_returned' => round((float) $i->manual_returned, 3),
                'due' => round(max(0.0, (float) $i->outstanding), 3),
                'cheque_pending' => round((float) $i->cheque_pending, 3),
                'orders' => (int) $i->live_orders,
                'members' => (int) $i->member_orders,
                'live_total' => round((float) $i->live_total, 3),
            ]])->all();
    }

    private static function blank(): array
    {
        return ['state' => self::PAID, 'label' => self::PAID, 'marker' => null, 'marker_on' => null, 'cheque_marker' => null, 'estimated' => false,
            'paid' => 0.0, 'received' => 0.0, 'due' => 0.0, 'credited' => 0.0, 'returned' => 0.0, 'refunded' => 0.0, 'cheque_pending' => 0.0,
            'customer_credit' => 0.0, 'invoice' => null];
    }
}

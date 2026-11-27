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
     * حالُ طلباتٍ كثيرة بأسئلةٍ معدودة — للوحة التجهيز وما يعرض قائمة.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, array{state: string, label: string, marker: ?string, paid: float, due: float, credited: float, returned: float, refunded: float, cheque_pending: float, customer_credit: float, invoice: ?array{id: int, number: string}}>
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

        // ما قُبض بشيكٍ لم يُصرف بعد — مسدَّدٌ في الدفتر، ويُقال للتاجر
        $cheques = $invoiceIds === [] ? collect() : DB::table('customer_payment_allocations as a')
            ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->whereIn('a.customer_invoice_id', $invoiceIds)
            ->whereNull('p.cancelled_at')->where('p.cheque_status', Cheques::PENDING)
            ->groupBy('a.customer_invoice_id')->selectRaw('a.customer_invoice_id as id, COALESCE(SUM(a.amount), 0) as s')->pluck('s', 'id');

        // وطلبٌ ملغى قُبضت فاتورتُه وحدَه: مالُه بقي رصيدًا للعميل حتى يُردّ بيد التاجر
        $alone = $invoiceIds === [] ? collect() : DB::table('customer_invoice_orders')
            ->whereIn('customer_invoice_id', $invoiceIds)->groupBy('customer_invoice_id')
            ->selectRaw('customer_invoice_id as id, COUNT(*) as n')->pluck('n', 'id');
        $invoicePaid = $invoiceIds === [] ? collect() : DB::table('customer_invoices')->whereIn('id', $invoiceIds)
            ->selectRaw('id, '.CustomerInvoice::paidSql().' as paid')->pluck('paid', 'id');

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $share = $shares[$id] ?? null;
            $total = round((float) $r->total, 3);
            $paid = $r->paid === null ? (float) ($share['paid'] ?? 0) : (float) $r->paid;
            $due = $r->due === null ? (float) ($share['due'] ?? 0) : (float) $r->due;
            $credited = (float) ($share['credited'] ?? 0);
            $returned = (float) ($share['returned'] ?? 0);
            $refunded = round((float) ($refunds[$id] ?? 0), 3);
            $cover = $r->cover_id ? (int) $r->cover_id : null;
            $state = (string) $r->pay_state;

            $marker = null;
            $label = $state;
            if ($state !== self::VOID) {
                if ($returned > 0.0005) {
                    // ولا يبقى من قيمته شيءٌ مبيع: إشعاراتُه — وفيها إرجاع — بلغت إجماليَّه
                    $whole = $credited >= $total - 0.0005;
                    $marker = $whole ? self::RETURNED : self::PARTLY_RETURNED;
                } elseif ($refunded > 0.0005) {
                    // الطلبُ نقص بالتصحيح: إجماليُّه اليومَ ما بقي، والمردودُ فوقه
                    $whole = $total <= 0.0005;
                    $marker = $whole ? self::REFUNDED : self::PARTLY_REFUNDED;
                }
                // وما رُدّ كلُّه يُسمّى باسمه — لا «مدفوع» عن مالٍ لم يبقَ منه شيء
                if ($marker === self::RETURNED || $marker === self::REFUNDED) {
                    $label = $marker;
                    $marker = null;
                }
            }

            $out[$id] = [
                'state' => $state,
                'label' => $label,
                'marker' => $marker,
                'paid' => round($paid, 3),
                'due' => round(max(0.0, $due), 3),
                'credited' => round($credited, 3),
                'returned' => round($returned, 3),
                'refunded' => $refunded,
                'cheque_pending' => $cover ? round((float) ($cheques[$cover] ?? 0), 3) : 0.0,
                'customer_credit' => $state === self::VOID && $cover && (int) ($alone[$cover] ?? 0) === 1
                    ? round((float) ($invoicePaid[$cover] ?? 0), 3) : 0.0,
                'invoice' => $cover ? ['id' => $cover, 'number' => (string) $r->cover_number] : null,
            ];
        }

        return $out;
    }

    private static function blank(): array
    {
        return ['state' => self::PAID, 'label' => self::PAID, 'marker' => null, 'paid' => 0.0, 'due' => 0.0, 'credited' => 0.0,
            'returned' => 0.0, 'refunded' => 0.0, 'cheque_pending' => 0.0, 'customer_credit' => 0.0, 'invoice' => null];
    }
}

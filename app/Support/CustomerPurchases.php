<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * مشترياتُ العميل — تعريفٌ واحد تقرؤه قائمةُ العملاء وملفّاتُها وبطاقاتُها وصفحتُه.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * كانت القائمةُ وصفحةُ العميل تعدّان الطلبات وحدها. فوزارةٌ اشترت بفاتورةٍ
 * يدويّة بخمسمئة تُقرأ في القائمة «٠ طلبات، ٠٫٠٠٠»، وآخرُ شرائها «—»، بينما
 * تقرير «الأكثر إنفاقًا» (`Demo::customerSpend`) يضعها في رأسه بخمسمئة. رقمان
 * لعميلٍ واحد على شاشتين.
 *
 * ═══ والتعريف — تعريفُ التقرير نفسُه ═══
 *
 *   - طلباتُه المبيعة (`Order::sold`): الصندوقُ والموقع، نقدًا وآجلًا، بقيمتها
 *     بعد الخصم والتصحيح. والملغى والسلّةُ المعلّقة لا تُعدّ؛
 *   - وفواتيرُه المستقلّة الصادرة: ما لا يغطّي طلبًا (`customer_invoice_orders`)
 *     — وهي التي رحّلت قيدَها بنفسها (`CustomerInvoices::post`). والفاتورةُ
 *     التي تغطّي طلبًا مستندُ تحصيلٍ له لا شراءٌ ثانٍ؛
 *   - ناقصًا إشعاراتِ الدائن اليدويّة على فواتيره الصادرة. وإشعارُ إلغاء
 *     الطلب لا يُطرح: الطلبُ الملغى خرج من الطلبات بحاله.
 *
 * والبيعُ الآجل شراءٌ يومَ وقع لا يومَ سُدِّد، والتحصيلُ — رابطَ دفعٍ مستقلًّا
 * كان أو سدادَ فاتورةٍ قديمة — ليس شراءً. وهو ما يقوله سجلُّ عمليات البيع
 * (`SalesRegister`) صفًّا صفًّا، فمجموعُ سجلّ العميل هو رقمُه في القائمة.
 *
 * ═══ وآخرُ شراء ═══
 *
 * أحدثُ الاثنين: آخرُ طلبٍ بساعته، وآخرُ فاتورةٍ مستقلّة بيومها — بترتيب السجلّ
 * نفسه (`SalesRegister::sorted`)، فأوّلُ صفٍّ في سجلّ العميل هو آخرُ شرائه.
 */
final class CustomerPurchases
{
    /** طلباتُه المبيعة — من متجره وحده */
    private static function orders(): QueryBuilder
    {
        return Order::query()->sold()
            ->whereColumn('orders.customer_id', 'customers.id')
            ->whereColumn('orders.business_id', 'customers.business_id')
            ->toBase();
    }

    /** فواتيرُه المستقلّة الصادرة — لا تغطّي طلبًا */
    private static function invoices(): QueryBuilder
    {
        return DB::table('customer_invoices as pi')
            ->where('pi.status', CustomerInvoice::ISSUED)
            ->whereColumn('pi.customer_id', 'customers.id')
            ->whereColumn('pi.business_id', 'customers.business_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_invoice_orders as pl')
                ->whereColumn('pl.customer_invoice_id', 'pi.id'));
    }

    /** إشعاراتُ الدائن اليدويّة على فواتيره الصادرة */
    private static function credits(): QueryBuilder
    {
        return DB::table('customer_credit_notes as pn')
            ->join('customer_invoices as pni', 'pni.id', '=', 'pn.customer_invoice_id')
            ->where('pni.status', CustomerInvoice::ISSUED)
            ->where('pn.source', CustomerInvoices::NOTE_MANUAL)
            ->whereColumn('pni.customer_id', 'customers.id')
            ->whereColumn('pni.business_id', 'customers.business_id');
    }

    /** وساعةُ الفاتورة يومُها — كما في `SalesRegister::invoices` */
    private static function invoiceMoment(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'CAST(pi.issued_at AS timestamp)'
            : 'pi.issued_at';
    }

    /**
     * أعمدةُ المشتريات على استعلام العملاء — بلا استعلامٍ لكلّ صفّ.
     *
     *   - `purchases_count` و`purchases_total` و`last_purchase_at`: تُرتَّب بها القائمة؛
     *   - و`last_order_*` و`last_invoice_*`: يُختار منها آخرُ شراءٍ في `last()`.
     */
    public static function apply(Builder $customers): Builder
    {
        if ($customers->getQuery()->columns === null) {
            $customers->select('customers.*');
        }

        $sub = fn (QueryBuilder $q) => ['('.$q->toSql().')', $q->getBindings()];

        [$oCount, $oCountB] = $sub(self::orders()->selectRaw('COUNT(*)'));
        [$iCount, $iCountB] = $sub(self::invoices()->selectRaw('COUNT(*)'));
        [$oSum, $oSumB] = $sub(self::orders()->selectRaw('COALESCE(SUM(orders.total), 0)'));
        [$iSum, $iSumB] = $sub(self::invoices()->selectRaw('COALESCE(SUM(pi.total), 0)'));
        [$nSum, $nSumB] = $sub(self::credits()->selectRaw('COALESCE(SUM(pn.amount), 0)'));
        [$oLast, $oLastB] = $sub(self::orders()->selectRaw('MAX(orders.ordered_at)'));
        [$iLast, $iLastB] = $sub(self::invoices()->selectRaw('MAX('.self::invoiceMoment().')'));

        $lastOrder = fn (string $column) => self::orders()->select("orders.{$column}")
            ->orderByDesc('orders.ordered_at')->orderByDesc('orders.id')->limit(1);
        $lastInvoice = fn (string $column) => self::invoices()->select("pi.{$column}")
            ->orderByDesc('pi.issued_at')->orderByDesc('pi.id')->limit(1);

        return $customers
            ->selectRaw("({$oCount} + {$iCount}) as purchases_count", [...$oCountB, ...$iCountB])
            ->selectRaw("({$oSum} + {$iSum} - {$nSum}) as purchases_total", [...$oSumB, ...$iSumB, ...$nSumB])
            ->selectRaw("CASE WHEN {$iLast} IS NULL THEN {$oLast} WHEN {$oLast} IS NULL THEN {$iLast}"
                ." WHEN {$iLast} >= {$oLast} THEN {$iLast} ELSE {$oLast} END as last_purchase_at",
                [...$iLastB, ...$oLastB, ...$oLastB, ...$iLastB, ...$iLastB, ...$oLastB, ...$iLastB, ...$oLastB])
            ->addSelect([
                'last_order_id' => $lastOrder('id'),
                'last_order_number' => $lastOrder('number'),
                'last_order_total' => $lastOrder('total'),
                'last_order_at' => $lastOrder('ordered_at'),
                'last_invoice_id' => $lastInvoice('id'),
                'last_invoice_number' => $lastInvoice('number'),
                'last_invoice_amount' => $lastInvoice('total'),
                'last_invoice_on' => $lastInvoice('issued_at'),
            ]);
    }

    /**
     * آخرُ شراء — طلبٌ أو فاتورةٌ مستقلّة، برقمه وقيمته — أو `null` لمن لم يشترِ.
     *
     * وعند تساوي اللحظة تسبق الفاتورةُ، كما في السجلّ (`r.kind` تصاعديًّا).
     *
     * @return array{kind: string, id: int, number: ?string, amount: float, date: string}|null
     */
    public static function last(object $c): ?array
    {
        $order = $c->last_order_id !== null ? [
            'kind' => 'order', 'id' => (int) $c->last_order_id, 'number' => $c->last_order_number,
            'amount' => round((float) $c->last_order_total, 3), 'at' => Carbon::parse($c->last_order_at),
        ] : null;
        $invoice = $c->last_invoice_id !== null ? [
            'kind' => 'invoice', 'id' => (int) $c->last_invoice_id, 'number' => $c->last_invoice_number,
            'amount' => round((float) $c->last_invoice_amount, 3), 'at' => Carbon::parse($c->last_invoice_on)->startOfDay(),
        ] : null;

        $last = match (true) {
            $order === null => $invoice,
            $invoice === null => $order,
            default => $invoice['at']->gte($order['at']) ? $invoice : $order,
        };

        if ($last === null) {
            return null;
        }

        $last['date'] = $last['at']->format('Y-m-d');
        unset($last['at']);

        return $last;
    }

    /** المجموعُ والعددُ من صفٍّ قرأ `apply` */
    public static function count(object $c): int
    {
        return (int) ($c->purchases_count ?? 0);
    }

    public static function total(object $c): float
    {
        return round((float) ($c->purchases_total ?? 0), 3);
    }

    /** ومشترياتُ من في القائمة كلِّهم — لا المحذوفين (الحذفُ ناعم) */
    public static function storeTotal(int $businessId): float
    {
        $customers = self::apply(Customer::where('business_id', $businessId)->select('customers.id'));

        return round((float) DB::query()->fromSub($customers->toBase(), 'pc')->sum('pc.purchases_total'), 3);
    }
}

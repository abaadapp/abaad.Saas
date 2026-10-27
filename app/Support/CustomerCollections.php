<?php

namespace App\Support;

use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * سجلُّ تحصيلات العميل — كلُّ مالٍ دفعه، ومن أيّ باب، وعلى أيّ مستند.
 *
 * ═══ ومن موضعين لا يتداخلان ═══
 *
 *   - **التحصيلات** (`customer_payments`): ما سُجّل بيدٍ في «التحصيل» — نقدًا
 *     وتحويلًا وشيكًا — وما دفعه برابط Paymob (`PaymentLinks::settle`)، ودفعةُ
 *     البيعة الآجلة في الصندوق. وكلٌّ بتخصيصاته على فواتيره، والباقي رصيدٌ له.
 *   - **الدفعُ عند البيع**: طلبٌ دُفع ثمنُه لحظةَ بيعه — نقدُ الصندوق وبطاقتُه
 *     ودفعُ الموقع. لا تحصيلَ له في الجدول الأوّل: مالُه في قيد البيعة نفسها.
 *     والطلبُ الآجل (`غير مدفوع`) لا صفَّ له هنا — ما دُفع منه تحصيلٌ في الأوّل.
 *
 * فلا مالَ يُقرأ مرّتين. ورابطُ الدفع المستقلّ تحصيلٌ بلا فاتورة: يظهر هنا
 * رصيدًا للعميل، ولا يظهر في المشتريات (`CustomerPurchases`).
 *
 * والملغى والشيكُ المرتجع صفّان موسومان: لا يُمحى تاريخ، ولا يُعدّ مالًا وصل.
 */
final class CustomerCollections
{
    public const RECEIVED = 'مقبوض';

    public const CANCELLED = 'ملغى';

    /** @return list<array<string, mixed>> الأحدثُ أوّلًا */
    public static function rows(int $businessId, int $customerId): array
    {
        $payments = CustomerPayment::where('business_id', $businessId)->where('customer_id', $customerId)
            ->with(['allocations.invoice:id,number'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->get();

        // رابطُ الدفع الذي جاء منه التحصيل — مرجعُه يُقرأ في الصفّ
        $links = $payments->isEmpty() ? collect() : CustomerPaymentLink::where('business_id', $businessId)
            ->whereIn('customer_payment_id', $payments->pluck('id'))
            ->pluck('reference', 'customer_payment_id');

        // والطلباتُ التي تغطّيها كلُّ فاتورة — الدفعةُ تُربط بها عبر فاتورتها
        $invoiceIds = $payments->flatMap(fn ($p) => $p->allocations->pluck('customer_invoice_id'))->unique()->values();
        $covered = $invoiceIds->isEmpty() ? collect() : DB::table('customer_invoice_orders as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('l.customer_invoice_id', $invoiceIds)
            ->where('o.business_id', $businessId)
            ->orderBy('o.ordered_at')->orderBy('o.id')
            ->get(['l.customer_invoice_id', 'o.id', 'o.number'])
            ->groupBy('customer_invoice_id');

        $rows = $payments->map(function (CustomerPayment $p) use ($links, $covered) {
            $state = match (true) {
                $p->cancelled_at !== null => self::CANCELLED,
                $p->cheque_status !== null => $p->cheque_status,
                default => self::RECEIVED,
            };
            $live = $p->cancelled_at === null && $p->cheque_status !== Cheques::BOUNCED;
            $allocated = round((float) $p->allocations->sum('amount'), 3);

            return [
                'key' => 'payment:'.$p->id,
                'kind' => 'payment',
                'id' => $p->id,
                'number' => $p->number,
                'date' => optional($p->occurred_at)->format('Y-m-d') ?? '—',
                'sort_at' => optional($p->occurred_at)->format('Y-m-d').' 23:59:59',
                'method' => (string) $p->method,
                'amount' => round((float) $p->amount, 3),
                'state' => $state,
                'counted' => $live,
                'reference' => $p->external_reference,
                'link' => $links[$p->id] ?? null,
                'invoices' => $p->allocations->map(fn ($a) => [
                    'id' => (int) $a->customer_invoice_id,
                    'number' => $a->invoice?->number,
                    'amount' => round((float) $a->amount, 3),
                    'orders' => collect($covered[$a->customer_invoice_id] ?? [])
                        ->map(fn ($o) => ['id' => (int) $o->id, 'number' => $o->number])->values()->all(),
                ])->values()->all(),
                'order' => null,
                // ما لم يُخصَّص لفاتورة رصيدٌ للعميل — رابطٌ مستقلّ أو دفعةٌ مقدّمة
                'credit' => $live ? max(0.0, round((float) $p->amount - $allocated, 3)) : 0.0,
            ];
        });

        $atSale = Order::where('business_id', $businessId)->where('customer_id', $customerId)
            ->where('is_held', false)
            ->where('payment_status', '!=', 'غير مدفوع')
            ->orderByDesc('ordered_at')->orderByDesc('id')
            ->get(['id', 'number', 'ordered_at', 'payment_method', 'total', 'balance_due', 'status', 'channel'])
            ->map(function (Order $o) {
                $paid = round((float) $o->total - (float) ($o->balance_due ?? 0), 3);
                $live = $o->status !== Order::CANCELLED;

                return [
                    'key' => 'order:'.$o->id,
                    'kind' => 'sale',
                    'id' => $o->id,
                    'number' => $o->number,
                    'date' => optional($o->ordered_at)->format('Y-m-d') ?? '—',
                    'sort_at' => optional($o->ordered_at)->format('Y-m-d H:i:s'),
                    'method' => (string) $o->payment_method,
                    'amount' => $paid,
                    'state' => $live ? self::RECEIVED : self::CANCELLED,
                    'counted' => $live,
                    'reference' => null,
                    'link' => null,
                    'invoices' => [],
                    'order' => ['id' => $o->id, 'number' => $o->number, 'source' => $o->channel],
                    'credit' => 0.0,
                ];
            })
            ->filter(fn ($r) => $r['amount'] > 0);

        return $rows->concat($atSale)
            ->sortBy([['sort_at', 'desc'], ['key', 'asc']])
            ->map(function ($r) {
                unset($r['sort_at']);

                return $r;
            })->values()->all();
    }

    /**
     * مجموعُ التحصيلات القائمة — بلا الملغى ولا الشيك المرتجع.
     *
     * والشيكُ تحت التحصيل يُعدّ ويُوسَم بحاله، كما يعدّه سدادُ الفاتورة
     * (`CustomerInvoice::paidSql`): فلا يقول السجلُّ غيرَ ما تقوله فاتورتُه.
     */
    public static function total(array $rows): float
    {
        return round(collect($rows)->where('counted', true)->sum('amount'), 3);
    }
}

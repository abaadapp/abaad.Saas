<?php

namespace App\Support;

use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * سجلُّ تحصيلات العميل — كلُّ مالٍ دفعه، ومن أيّ باب، وعلى أيّ مستند.
 *
 * ═══ ومن ثلاثة مواضع لا يتداخل منها شيء ═══
 *
 *   - **التحصيلات** (`customer_payments`): ما سُجّل بيدٍ في «التحصيل» — نقدًا
 *     وتحويلًا وشيكًا — وما دفعه برابط Paymob (`PaymentLinks::settle`)، ودفعةُ
 *     البيعة الآجلة في الصندوق. وكلٌّ بتخصيصاته على فواتيره، والباقي رصيدٌ له.
 *   - **الدفعُ عند البيع**: ما دُفع في البيعة نفسها — نقدُ الصندوق وبطاقتُه
 *     ودفعُ الموقع: الإجماليّ − المتبقّي − ما سُوّي بعدها (`OrderCorrection::paidAtSale`).
 *     والطلبُ الآجل (`غير مدفوع`) لا صفَّ له هنا — ما دُفع منه تحصيلٌ في الأوّل.
 *   - **ما سُوّي على الطلب بعد بيعه** (`transactions` من `SETTLEMENT_KINDS`):
 *     تحصيلُ متبقّيه وردُّ فرقه، كلٌّ بيومه ووسيلته — لا مدموجًا في «عند البيع».
 *
 * فلا مالَ يُقرأ مرّتين: البيعةُ الآجلةُ بدفعتها المقدّمة صفٌّ واحد (التحصيل)،
 * وفاتورةٌ قديمة تُسدَّد برابط تحصيلٌ واحد مهما تكرّر إشعارُه. ورابطُ الدفع
 * المستقلّ تحصيلٌ بلا فاتورة: رصيدٌ للعميل، ولا يظهر في المشتريات.
 *
 * ═══ و«المقبوضُ فعلًا» غيرُ «المُسجَّل» ═══
 *
 * الشيكُ تحت التحصيل يُعرض بحاله ولا يُعدّ مالًا وصل حتى يُصرف — ولا يمسّ هذا
 * الدفترَ ولا رصيدَ العميل: قيدُه نقل الذمّةَ إلى «شيكات تحت التحصيل»، والفاتورةُ
 * تقرؤه سدادًا (`CustomerInvoice::paidSql`). هو تصنيفُ عرضٍ لا حكمٌ محاسبيّ.
 * والملغى والمرتجع صفّان موسومان: لا يُمحى تاريخ، ولا يُعدّ مالًا.
 */
final class CustomerCollections
{
    public const RECEIVED = 'مقبوض';

    public const CANCELLED = 'ملغى';

    public const REFUNDED = 'مردود';

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
            $cancelled = $p->cancelled_at !== null;
            $bounced = $p->cheque_status === Cheques::BOUNCED;
            $pending = ! $cancelled && $p->cheque_status === Cheques::PENDING;
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
                'state' => match (true) {
                    $cancelled => self::CANCELLED,
                    $p->cheque_status !== null => $p->cheque_status,
                    default => self::RECEIVED,
                },
                // مالٌ وصل فعلًا: لا الملغى ولا المرتجع، ولا شيكٌ لم يُصرف بعد
                'counted' => ! $cancelled && ! $bounced && ! $pending,
                'pending' => $pending,
                'void' => $cancelled || $bounced,
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
                'credit' => $cancelled || $bounced ? 0.0 : max(0.0, round((float) $p->amount - $allocated, 3)),
            ];
        });

        $orders = Order::where('business_id', $businessId)->where('customer_id', $customerId)
            ->where('is_held', false)
            ->where('payment_status', '!=', 'غير مدفوع')
            ->orderByDesc('ordered_at')->orderByDesc('id')
            ->get(['id', 'number', 'ordered_at', 'payment_method', 'total', 'balance_due', 'paid_after_sale', 'status', 'channel']);

        $orderRow = fn (Order $o, array $over) => $over + [
            'kind' => 'sale',
            'number' => null,
            'method' => (string) $o->payment_method,
            'pending' => false,
            'reference' => null,
            'link' => null,
            'invoices' => [],
            'order' => ['id' => $o->id, 'number' => $o->number, 'source' => $o->channel],
            'credit' => 0.0,
        ];

        $atSale = $orders->map(function (Order $o) use ($orderRow) {
            $live = $o->status !== Order::CANCELLED;
            $paid = round((float) $o->total - (float) ($o->balance_due ?? 0) - (float) ($o->paid_after_sale ?? 0), 3);

            return $orderRow($o, [
                'key' => 'order:'.$o->id,
                'id' => $o->id,
                'date' => optional($o->ordered_at)->format('Y-m-d') ?? '—',
                'sort_at' => optional($o->ordered_at)->format('Y-m-d H:i:s'),
                'amount' => $paid,
                'state' => $live ? self::RECEIVED : self::CANCELLED,
                'counted' => $live,
                'void' => ! $live,
            ]);
        })->filter(fn ($r) => $r['amount'] > 0);

        // وما سُوّي على تلك الطلبات بعد بيعها — صفٌّ بيومه، والردُّ بالسالب
        $byId = $orders->keyBy('id');
        $settled = $orders->isEmpty() ? collect() : Transaction::where('business_id', $businessId)
            ->whereIn('order_id', $orders->pluck('id'))
            ->whereIn('kind', Transaction::SETTLEMENT_KINDS)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->get(['id', 'order_id', 'kind', 'method', 'amount', 'occurred_at'])
            ->map(function (Transaction $t) use ($byId, $orderRow) {
                $o = $byId[$t->order_id];
                $live = $o->status !== Order::CANCELLED;
                $refund = $t->kind === Transaction::ORDER_REFUND;

                return $orderRow($o, [
                    'key' => 'settlement:'.$t->id,
                    'kind' => $refund ? 'refund' : 'balance',
                    'id' => $t->id,
                    'date' => optional($t->occurred_at)->format('Y-m-d') ?? '—',
                    'sort_at' => optional($t->occurred_at)->format('Y-m-d H:i:s'),
                    'method' => (string) $t->method,
                    'amount' => round(($refund ? -1 : 1) * (float) $t->amount, 3),
                    'state' => ! $live ? self::CANCELLED : ($refund ? self::REFUNDED : self::RECEIVED),
                    'counted' => $live,
                    'void' => ! $live,
                ]);
            });

        return $rows->concat($atSale)->concat($settled)
            ->sortBy([['sort_at', 'desc'], ['key', 'asc']])
            ->map(function ($r) {
                unset($r['sort_at']);

                return $r;
            })->values()->all();
    }

    /** المقبوضُ فعلًا — بلا الملغى ولا المرتجع ولا شيكٍ تحت التحصيل، والردُّ يُنقصه */
    public static function received(array $rows): float
    {
        return round(collect($rows)->where('counted', true)->sum('amount'), 3);
    }

    /** وشيكاتٌ تحت التحصيل — تُقال بجواره ولا تُجمع إليه */
    public static function pending(array $rows): float
    {
        return round(collect($rows)->where('pending', true)->sum('amount'), 3);
    }
}

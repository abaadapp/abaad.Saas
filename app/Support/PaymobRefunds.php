<?php

namespace App\Support;

use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\Paymob\Amount;
use Illuminate\Support\Facades\DB;

/**
 * ردٌّ أبلغت به Paymob على مالٍ قُبض — يُقال ولا يُعكس في الدفتر بلا يد.
 *
 * ═══ ما يصل من Paymob ═══
 *
 * إشعاران، كلاهما موقَّعٌ بالحقول التي يُثبتها التوقيع (`Paymob::HMAC_FIELDS`):
 *
 *   - **عمليّةُ الردّ نفسُها** — جديدةٌ على الطلب نفسِه، أصلُها الدفعة
 *     (`has_parent_transaction`)، ومبلغُها (`amount_cents`) ما رُدّ: كاملًا أو
 *     جزءًا. فمبلغُها يُكتب على الرابط (`record`) ويُجمع إن تعدّدت.
 *   - **الدفعةُ الأصل** وقد صارت «مستردّة» أو «ملغاة» (`is_refunded`/
 *     `is_voided`) — يُكتب وسمُها (`PaymentLinks::notSettled`). والإلغاءُ قبل
 *     التسوية يردّ الدفعةَ كلَّها، أمّا «مستردّة» فلا تقول كم: مبلغُ الردّ
 *     (`refunded_amount_cents`) خارجَ التوقيع، فلا يُقرأ.
 *
 * ═══ ولا مبلغَ يُخمَّن ═══
 *
 * ما عُرف مبلغُه بإشعارٍ موقَّع يُقال بمبلغه — ولا يتجاوز الدفعة. وما لم يُعرف
 * إلّا أنّه استُرجع يُقال «يُراجَع في Paymob» بلا رقم: ردٌّ جزئيٌّ يُقرأ كاملًا
 * يجعل صاحبَ المحلّ يُلغي تحصيلًا بقي أكثرُه.
 *
 * ═══ وينتظر التسوية ═══
 *
 * المالُ في «مقبوضات اليوم» كما يقوله الدفتر حتّى يُسوّيه صاحبُه: الردُّ
 * الكامل يُسوّى بإلغاء التحصيل (`CustomerPayments::cancel`) فيُعكس قيدُه يومَ
 * إلغائه ويخرج من هنا. ولا عكسَ تلقائيًّا — بأمر صاحب المنتج.
 *
 * وطلبُ الموقع الذي استُرجعت دفعتُه وما زال قائمًا (`StorePaymentIntent::
 * REFUND_SENT`) ينتظر مثلَه: مالُه في المقبوض حتّى يُلغى الطلب. ومبلغُ ردّه
 * لا يُعرف من وسمه، فيُعدّ حالةً بلا رقم.
 */
final class PaymobRefunds
{
    /**
     * عمليّةُ ردٍّ موقَّعةٌ على رابطٍ مدفوع — يُكتب مبلغُها مرّةً، ولا يمسّ الدفتر.
     *
     * ردٌّ لم يتمّ (`success` كاذبة أو معلّق) لا يُكتب. والإشعارُ المكرّر لا
     * يُضاعفه: المبلغُ بمعرّف عمليّته، تحت قفل الرابط.
     */
    public static function record(CustomerPaymentLink $link, array $obj, callable $flag): void
    {
        $id = trim((string) data_get($obj, 'id', ''));
        $cents = (int) data_get($obj, 'amount_cents', 0);
        $currency = strtoupper(trim((string) data_get($obj, 'currency', '')));

        if (! $flag('success') || $flag('pending') || $id === '' || $cents <= 0
            || ($currency !== '' && $currency !== strtoupper((string) $link->currency))) {
            return;
        }

        DB::transaction(function () use ($link, $id, $cents) {
            $locked = CustomerPaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $meta = (array) ($locked->meta ?? []);
            $refunds = (array) ($meta['provider_refunds'] ?? []);

            if (array_key_exists($id, $refunds)) {
                return;
            }

            $refunds[$id] = $cents;
            $locked->forceFill(['meta' => ['provider_refunds' => $refunds] + $meta])->save();

            Activity::log('warning', 'أبلغت Paymob بردّ '.number_format(Amount::fromMinor($cents, (string) $locked->currency), 3)
                .' '.$locked->currency.' من دفعة رابطٍ مدفوع — التحصيل باقٍ حتّى يُسوّى بيده', [
                    'subject_id' => $locked->id, 'subject_type' => 'customer_payment_link', 'business_id' => (int) $locked->business_id,
                ]);
        });
    }

    /** أعلى الرابطِ وسمُ ردٍّ من Paymob — بمبلغٍ أو بلا مبلغ */
    public static function flagged(array $meta): bool
    {
        return filled($meta['provider_refund'] ?? null) || ((array) ($meta['provider_refunds'] ?? [])) !== [];
    }

    /**
     * ما رُدّ من دفعة الرابط — بمبلغه الموقَّع، و`null` حين لا يُعرف.
     *
     * والإلغاءُ (`voided`) يردّ الدفعةَ كلَّها. وعملياتُ الردّ تُجمع ولا تتجاوز
     * الدفعة. و«مستردّة» بلا عمليّةٍ موقَّعة لا تقول كم.
     */
    public static function refunded(float $amount, string $currency, array $meta): ?float
    {
        if (($meta['provider_refund'] ?? null) === 'voided') {
            return round($amount, 3);
        }

        $cents = array_sum(array_map('intval', (array) ($meta['provider_refunds'] ?? [])));

        if ($cents > 0) {
            return round(min($amount, Amount::fromMinor($cents, $currency)), 3);
        }

        return filled($meta['provider_refund'] ?? null) ? null : 0.0;
    }

    /**
     * روابطُ مدفوعةٌ أبلغت Paymob بردٍّ عليها وتحصيلُها قائم — الأحدثُ أوّلًا.
     *
     * @return array<int, array{id: int, invoice_id: ?int, invoice: ?string, customer: ?string, payment: ?string, branch_id: ?int, amount: float, refunded: ?float}>
     */
    public static function linkCases(int $businessId, ?int $limit = null): array
    {
        return DB::table('customer_payment_links as l')
            ->join('customer_payments as p', fn ($j) => $j->on('p.id', '=', 'l.customer_payment_id')
                ->on('p.business_id', '=', 'l.business_id'))
            ->leftJoin('customer_invoices as i', fn ($j) => $j->on('i.id', '=', 'l.customer_invoice_id')
                ->on('i.business_id', '=', 'l.business_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('c.id', '=', 'l.customer_id')
                ->on('c.business_id', '=', 'l.business_id'))
            ->where('l.business_id', $businessId)
            ->where('l.status', CustomerPaymentLink::PAID)
            ->where('l.is_live', true)
            ->whereNull('p.cancelled_at')
            ->whereNotNull('l.meta')
            ->orderByDesc('l.paid_at')->orderByDesc('l.id')
            ->get(['l.id', 'l.amount', 'l.currency', 'l.meta', 'l.customer_invoice_id', 'i.number as invoice', 'i.branch_id', 'c.name as customer', 'p.number as payment'])
            ->map(function ($r) {
                $meta = is_string($r->meta) ? (json_decode($r->meta, true) ?: []) : (array) $r->meta;

                return self::flagged($meta) ? [
                    'id' => (int) $r->id,
                    'invoice_id' => $r->customer_invoice_id !== null ? (int) $r->customer_invoice_id : null,
                    'invoice' => $r->invoice,
                    'customer' => $r->customer,
                    'payment' => $r->payment,
                    'branch_id' => $r->branch_id !== null ? (int) $r->branch_id : null,
                    'amount' => round((float) $r->amount, 3),
                    'refunded' => self::refunded((float) $r->amount, (string) $r->currency, $meta),
                ] : null;
            })
            ->filter()->when($limit !== null, fn ($c) => $c->take($limit))->values()->all();
    }

    /**
     * ما ينتظر التسوية — بجوار «مقبوضات اليوم».
     *
     * والفرعُ فرعُ الفاتورة أو الطلب؛ ورابطٌ بلا فاتورةٍ في «كل الفروع» وحده.
     *
     * @return array{amount: float, cases: int, unknown: int}
     */
    public static function awaiting(int $businessId, ?int $branchId = null): array
    {
        $out = ['amount' => 0.0, 'cases' => 0, 'unknown' => 0];

        foreach (self::linkCases($businessId) as $case) {
            if ($branchId !== null && $case['branch_id'] !== $branchId) {
                continue;
            }

            $out['cases']++;

            if ($case['refunded'] === null) {
                $out['unknown']++;
            } else {
                $out['amount'] += $case['refunded'];
            }
        }

        $orders = DB::table('store_payment_intents as s')
            ->join('orders as o', fn ($j) => $j->on('o.id', '=', 's.order_id')->on('o.business_id', '=', 's.business_id'))
            ->where('s.business_id', $businessId)
            ->where('s.refund_status', StorePaymentIntent::REFUND_SENT)
            ->where('o.status', '!=', Order::CANCELLED)
            ->when($branchId !== null, fn ($q) => $q->where('o.branch_id', $branchId))
            ->count();

        $out['cases'] += $orders;
        $out['unknown'] += $orders;
        $out['amount'] = round($out['amount'], 3);

        return $out;
    }
}

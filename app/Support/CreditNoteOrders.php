<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteOrder;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * الإشعارُ الدائنُ اليدويّ يسمّي الطلبَ الذي كان عنه.
 *
 * ═══ لماذا ═══
 *
 * فاتورةٌ تجمع طلبات، وعليها إشعارٌ يدويٌّ لا يقول أيَّ طلبٍ يخصّ: لا يُلغى
 * منها طلبٌ (`BilledOrderCancel`)، فإلغاءُ الطلب يعكس إيرادَه وضريبتَه ولو كان
 * الإشعارُ عنه نقص البيعُ مرّتين. والتسميةُ تقول عن أيّ طلبٍ كان، فيبقى إلغاءُ
 * الطلبات الأخرى ممكنًا — والطلبُ المسمّى لا يُلغى بعدها: ما رُدّ منه رُدّ
 * بالإشعار، وما بقي يُرَدّ بإشعار إرجاعٍ آخرَ يسمّيه.
 *
 * ═══ متى ═══
 *
 *   - عند إصدار إشعار الإرجاع (`AT_ISSUE`): في معاملة الإشعار نفسِها وتحت قفلها.
 *   - أو بعده (`LATER`): يسمّيه من يملك إصدارَ الإشعار — صاحبُ النشاط أو
 *     المحاسبُ المخوَّل — بعد التحقّق، ويكتب لماذا.
 *
 * ═══ ما يُسأل ═══
 *
 *   - الطلبُ من المتجر نفسِه، وعلى هذه الفاتورة نفسِها، وغيرُ ملغى.
 *   - مبلغُ الإشعار وضريبتُه لا يتجاوزان ما بقي من الطلب بعد إشعاراتٍ سُمّيت
 *     عنه قبله — فيُرَدّ الطلبُ جزءًا بعد جزءٍ حتى كلِّه، ولا أكثر.
 *   - وإشعارٌ سُمّي لا يُسمّى ثانيةً، ولا يُسمّى إشعارُ إلغاء الطلب (طلبُه معروف).
 *
 * ═══ وما لا يمسّه ═══
 *
 * لا مبلغَ ولا قيدَ ولا مخزونَ ولا نقاطَ ولا مستحقَّ بوتيك: الإشعارُ كما صدر،
 * وقيدُه كما كُتب. التسميةُ سطرٌ في `customer_credit_note_orders` — يُكتب مرّةً
 * ولا يُعدَّل ولا يُحذف — ومعه سطرٌ في سجلّ النشاط.
 *
 * ═══ والتزامن ═══
 *
 * كلُّ تسميةٍ تأخذ قفلَ المتجر قبل أن تقرأ — القفلَ الذي يأخذه الإشعارُ والإلغاءُ
 * قبل أوّل قيد (`BilledOrderCancel::assertUnderLock`). فتسميةٌ وإلغاءٌ معًا
 * يقعان واحدًا بعد آخر: إن سبق الإلغاءُ قرأت التسميةُ طلبًا ملغى فرُدّت، وإن
 * سبقت التسميةُ قرأها الإلغاءُ فرُدّ. ولا يُقفل صفُّ الطلب هنا: الإلغاءُ يمسكه
 * قبل قفل المتجر، وقفلُه بعده هنا ترتيبٌ معكوسٌ يولّد طريقًا مسدودًا.
 */
final class CreditNoteOrders
{
    /** فرقُ التقريب المقبول في مبلغٍ بثلاث خانات */
    private const ROUNDING = 0.0005;

    /**
     * ما بقي من الطلب لإشعاراتٍ تُسمّى عنه — مبلغًا وضريبة.
     *
     * @return array{amount: float, tax: float}
     */
    public static function room(Order $order): array
    {
        $named = DB::table('customer_credit_note_orders')
            ->where('business_id', $order->business_id)
            ->where('order_id', $order->id)
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(tax_amount), 0) AS tax')
            ->first();

        return [
            'amount' => max(0.0, round((float) $order->total - (float) $named->amount, 3)),
            'tax' => max(0.0, round((float) $order->tax - (float) $named->tax, 3)),
        ];
    }

    /**
     * الطلباتُ التي يُسمّى بها إشعارٌ على هذه الفاتورة — للشاشة.
     *
     * فاتورةٌ صادرةٌ تجمع طلبات وحدها، وطلبٌ غيرُ ملغى بقي منه شيء.
     *
     * @return list<array{id: int, number: string, room: float, room_tax: float}>
     */
    public static function choices(CustomerInvoice $invoice): array
    {
        if ($invoice->status !== CustomerInvoice::ISSUED) {
            return [];
        }

        return $invoice->orders()
            ->where('orders.business_id', $invoice->business_id)
            ->where('orders.status', '<>', Order::CANCELLED)
            ->orderBy('orders.id')
            ->get()
            ->map(fn (Order $o) => ['order' => $o, 'room' => self::room($o)])
            ->filter(fn (array $r) => $r['room']['amount'] > 0)
            ->map(fn (array $r) => [
                'id' => (int) $r['order']->id,
                'number' => (string) $r['order']->number,
                'room' => $r['room']['amount'],
                'room_tax' => $r['room']['tax'],
            ])
            ->values()
            ->all();
    }

    /**
     * الطلبُ الذي يسمّيه إشعارٌ بهذا المبلغ وهذه الضريبة — أو سببُ الرفض.
     *
     * يُسأل تحت قفل المتجر، من الفاتورة والطلب كما هما الآن.
     *
     * @throws CreditNoteOrderRefused
     */
    public static function assertFits(CustomerInvoice $invoice, int $orderId, float $amount, float $tax): Order
    {
        $order = Order::where('business_id', $invoice->business_id)->whereKey($orderId)->first();

        // ومن متجرٍ آخر أو فاتورةٍ أخرى يُقال «ليس في هذه الفاتورة» — لا يُقال إنّه موجود
        if (! $order || ! $invoice->orders()->whereKey($order->id)->exists()) {
            throw new CreditNoteOrderRefused(__('الطلب المختار ليس في الفاتورة :invoice.', ['invoice' => $invoice->number]));
        }

        if ($invoice->status !== CustomerInvoice::ISSUED) {
            throw new CreditNoteOrderRefused(__('الفاتورة :invoice ليست صادرة — لا يُسمّى عليها طلب.', ['invoice' => $invoice->number]));
        }

        if ($order->status === Order::CANCELLED) {
            throw new CreditNoteOrderRefused(__('الطلب :order ملغى — أُرجع بإلغائه، ولا يُسمّى عنه إشعار.', ['order' => $order->number]));
        }

        $room = self::room($order);

        if (round($amount, 3) > $room['amount'] + self::ROUNDING) {
            throw new CreditNoteOrderRefused(__('بقي من الطلب :order :left فقط بعد إشعاراتٍ سُمّيت عنه — لا يُسمّى عنه إشعارٌ أكبر.', [
                'order' => $order->number, 'left' => number_format($room['amount'], 3),
            ]));
        }

        if (round($tax, 3) > $room['tax'] + self::ROUNDING) {
            throw new CreditNoteOrderRefused(__('بقي من ضريبة الطلب :order :left فقط — لا يُسمّى عنه إشعارٌ بضريبةٍ أكبر.', [
                'order' => $order->number, 'left' => number_format($room['tax'], 3),
            ]));
        }

        return $order;
    }

    /** يكتب التسمية — داخل معاملةٍ أخذت قفلَ المتجر وسألت `assertFits` */
    public static function record(CustomerCreditNote $note, Order $order, string $mode, string $reason, ?int $userId): CustomerCreditNoteOrder
    {
        return CustomerCreditNoteOrder::create([
            'business_id' => $note->business_id,
            'customer_credit_note_id' => $note->id,
            'customer_invoice_id' => $note->customer_invoice_id,
            'order_id' => $order->id,
            'mode' => $mode,
            'amount' => round((float) $note->amount, 3),
            'tax_amount' => round((float) $note->tax_amount, 3),
            'reason' => mb_substr($reason, 0, 200),
            'linked_by' => $userId,
            'linked_by_name' => $userId ? mb_substr((string) User::whereKey($userId)->value('name'), 0, 120) : null,
        ]);
    }

    /**
     * يسمّي طلبَ إشعارٍ صدر بلا طلب — بعد التحقّق، ولماذا.
     *
     * @throws CreditNoteOrderRefused
     */
    public static function link(CustomerCreditNote $note, int $orderId, string $reason, ?int $userId): CustomerCreditNoteOrder
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new CreditNoteOrderRefused(__('اكتب لماذا يُسمّى هذا الطلب — يُحفظ في سجل التدقيق.'));
        }

        return DB::transaction(function () use ($note, $orderId, $reason, $userId) {
            Business::whereKey($note->business_id)->lockForUpdate()->first();

            // والإشعارُ وفاتورتُه يُقرآن من جديد تحت القفل — تسميةٌ سبقت بين السؤالين تُرى
            $note = CustomerCreditNote::where('business_id', $note->business_id)->whereKey($note->id)->firstOrFail();
            $invoice = CustomerInvoice::where('business_id', $note->business_id)->findOrFail($note->customer_invoice_id);

            if ($note->source !== CustomerInvoices::NOTE_MANUAL) {
                throw new CreditNoteOrderRefused(__('الإشعار :note صدر بإلغاء طلبه — طلبه معروف.', ['note' => $note->number]));
            }

            if ($named = CustomerCreditNoteOrder::where('customer_credit_note_id', $note->id)->with('order')->first()) {
                throw new CreditNoteOrderRefused(__('الإشعار :note سُمّي عن الطلب :order من قبل — لا يُسمّى مرّتين.', [
                    'note' => $note->number, 'order' => $named->order?->number,
                ]));
            }

            $order = self::assertFits($invoice, $orderId, (float) $note->amount, (float) $note->tax_amount);
            $link = self::record($note, $order, CustomerCreditNoteOrder::LATER, $reason, $userId);

            Activity::log('updated', 'سمّى طلب الإشعار الدائن '.$note->number.': '.$order->number.' على '.$invoice->number.' — '.$reason, [
                'subject_id' => $note->id, 'subject_type' => 'customer_credit_note',
            ]);

            return $link;
        });
    }
}

<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerInvoice;
use App\Models\Order;
use RuntimeException;

/**
 * إلغاءُ طلبٍ تحمله فاتورةُ عميل — لا يُنقص البيعَ مرّةً ثانية.
 *
 * ═══ ما كان يقع ═══
 *
 * إلغاءُ الطلب يعكس قيدَه كلَّه (`Books::unpostSale`): المبيعاتِ والضريبةَ
 * والذمّة. والإشعارُ الدائنُ اليدويّ على فاتورته قيّد مثلَها
 * (`CustomerInvoices::creditNote`): مردوداتٍ وضريبةً وذمّة. فإن كان الإشعارُ
 * عن هذا الطلب نقص البيعُ مرّتين — إيرادُه وضريبتُه:
 *
 *   - غيرُ مدفوعة: إشعارُ الإلغاء الآليّ يُقصّ عند ما بقي، فتقول الفاتورةُ
 *     «لا شيء عليه» ويقول الدفترُ «له مئةٌ وعشرون» — وتفترق المطابقة.
 *   - مدفوعة: لا يُقصّ، فيصير للعميل ثلاثُمئةٍ وعشرون وقد دفع مئتين —
 *     والمطابقةُ «متوازنة»، فالخطأُ لا يُرى.
 *
 * ═══ والإشعارُ اليدويّ لا يقول أيَّ طلبٍ يخصّ ═══
 *
 * فلا يُفترض أنّه عن طلبٍ آخر: فاتورةٌ عليها إشعارٌ لا يُعرف طلبُه لا يُلغى
 * منها طلبٌ تلقائيًّا — تُراجَع التسويةُ أوّلًا. وإشعاراتُ إلغاء الطلبات تقول
 * طلبَها (`order_id`) فتُعدّ فيما رُدّ ولا تمنع. ولا يتجاوز الإلغاءُ ما بقي من
 * الفاتورة مبلغًا وضريبة.
 *
 * والإشعارُ اليدويّ يسمّي طلبَه عند إصداره أو بعده (`CreditNoteOrders`): فلا
 * يمنع إلغاءَ غيره، ويمنع إلغاءَ طلبه هو — ما رُدّ منه رُدّ بالإشعار.
 *
 * وما عاد من البضاعة بعد الرفض لا يعود إلى الرفّ تلقائيًّا: بابُه تعديلُ
 * المخزون — بسببٍ وصلاحيةٍ وسجلّ، وقيدُه مخزونٌ لا إيرادٌ ولا ضريبة.
 *
 * ═══ ومتى يُسأل ═══
 *
 * قبل المعاملة — فلا يتحرّك شيء — ثمّ تحت قفل المتجر قبل أوّل قيد، والورقةُ
 * تُقرأ من جديد: إشعارٌ صدر بين السؤالين يُرى، والرفضُ يُرجع المعاملةَ كلَّها.
 * والقفلُ في موضعه الذي يأخذه الدفتر — بعد أقفال الرفّ — فلا ترتيبَ أقفالٍ
 * جديد (انظر `WebCheckout`). والإشعارُ اليدويّ يأخذ القفلَ نفسَه قبل أن يقرأ.
 */
final class BilledOrderCancel
{
    /** فرقُ التقريب المقبول في مبلغٍ بثلاث خانات */
    private const ROUNDING = 0.0005;

    /** ما يمنع إلغاءَ الطلب لأجل فاتورته — أو لا شيء */
    public static function refusal(Order $order): ?string
    {
        $invoice = CustomerInvoice::where('business_id', $order->business_id)
            ->where('status', CustomerInvoice::ISSUED)
            ->whereHas('orders', fn ($q) => $q->whereKey($order->id))
            ->first();

        if (! $invoice) {
            return null;
        }

        // ما لا يقول طلبَه — يدويٌّ لم يُسمَّ، أو قديمٌ بلا مصدر — لا يُنسب إلى طلبٍ آخر
        $notes = $invoice->creditNotes()->with('orderLink')->orderBy('id')->get();
        $unknown = $notes
            ->filter(fn ($n) => ! ($n->source === CustomerInvoices::NOTE_ORDER_CANCELLED && $n->order_id !== null) && ! $n->orderLink)
            ->pluck('number');

        if ($unknown->isNotEmpty()) {
            return __('الطلب :order في الفاتورة :invoice، وعليها إشعار دائن (:notes) لا يُعرف أي طلب يخص — وإلغاء الطلب يعكس إيراده وضريبته مرة أخرى. راجع التسوية مع المحاسب قبل الإلغاء، وإن عادت البضاعة فسجّلها بتعديل مخزون.', [
                'order' => $order->number,
                'invoice' => $invoice->number,
                'notes' => $unknown->implode('، '),
            ]);
        }

        /*
         * وإشعارٌ سُمّي عن هذا الطلب (`CreditNoteOrders`): ما رُدّ منه رُدّ به،
         * وإلغاؤه يعكس إيرادَه وضريبتَه كاملين فوق ما ردّه الإشعار. وما بقي منه
         * يُرَدّ بإشعار إرجاعٍ آخرَ يسمّيه — جزءًا بعد جزءٍ حتى كلِّه.
         */
        $mine = $notes->filter(fn ($n) => (int) $n->orderLink?->order_id === (int) $order->id)->pluck('number');

        if ($mine->isNotEmpty()) {
            return __('الطلب :order في الفاتورة :invoice رُدّ منه بإشعار دائن سُمّي عنه (:notes) — إلغاؤه يعكس إيراده وضريبته مرة أخرى. ما بقي منه يُرَدّ بإشعار إرجاع آخر يسمّيه، وإن عادت البضاعة فسجّلها بتعديل مخزون.', [
                'order' => $order->number,
                'invoice' => $invoice->number,
                'notes' => $mine->implode('، '),
            ]);
        }

        if ((float) $order->total > $invoice->amountLeft() + self::ROUNDING
            || (float) $order->tax > $invoice->taxLeft() + self::ROUNDING) {
            return __('الطلب :order في الفاتورة :invoice، ولم يبقَ منها ما يغطي مبلغه وضريبته — إلغاؤه ينقص أكثر مما بقي عليها. راجع التسوية مع المحاسب قبل الإلغاء.', [
                'order' => $order->number,
                'invoice' => $invoice->number,
            ]);
        }

        return null;
    }

    /**
     * يُسأل ثانيةً تحت قفل المتجر — داخل معاملة الإلغاء، قبل أوّل قيد.
     *
     * @throws RuntimeException
     */
    public static function assertUnderLock(Order $order): void
    {
        Business::whereKey($order->business_id)->lockForUpdate()->first();

        if ($why = self::refusal($order)) {
            throw new RuntimeException($why);
        }
    }
}

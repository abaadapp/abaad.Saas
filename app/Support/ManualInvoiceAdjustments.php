<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;

/**
 * ما يمنع إلغاءَ فاتورة العميل أو إشعارَها الدائنَ اليدويّ — يُسأل قبل أن يُكتب شيء.
 *
 * ═══ ما يُمنع، وما يبقى ═══
 *
 * الإلغاءُ يعكس قيدَ الفاتورة كلَّه اليومَ ولا يصدر له مستندٌ يشير إليها.
 * فلا يكون لفاتورةٍ في فترةٍ ضريبيّةٍ قُدّم إقرارُها: تنقص ضريبتُها من الفترة
 * الجارية بلا ورقة (م٤٨(٢) وم١٥٥ من اللائحة تشترطان مستندًا يشير إلى الفاتورة).
 * وما أُلغي أو أُرجع بعد الإقرار يُكتب إشعارًا دائنًا — فذاك يبقى مفتوحًا:
 * الإرجاعُ والخصمُ حدثان لاحقان يُعدَّلان يومَهما. وهل يكفي الإشعارُ أم يلزم
 * إقرارٌ معدّل، قرارُ المحاسب الضريبيّ لا هذا الملفّ.
 *
 * ولا يُلغى ولا يُنقص ما يطلبه رابطُ دفعٍ ينتظر: يدفع العميلُ فاتورةً ملغاة
 * أو أكثرَ ممّا عليه، فيصير مالُه رصيدًا لم يقصده. يُلغى الرابطُ أوّلًا.
 *
 * وضريبةُ الإشعار يكتبها التاجر — وقد يكون ما رُدّ بندًا معفًى — لكنّها لا
 * تفوق ما يحمله مبلغُه بأعلى نسبةٍ في الفاتورة، ولا تتجاوز الإشعاراتُ معًا
 * ضريبةَ الفاتورة: ما زاد ضريبةٌ تُنقص من الإقرار ولم تُحصَّل قطّ.
 *
 * والدفاترُ المقفلة يمنعها الدفترُ نفسُه (`BooksPeriod::assertOpen`).
 */
final class ManualInvoiceAdjustments
{
    /** فرقُ التقريب المقبول في مبلغٍ بثلاث خانات */
    private const ROUNDING = 0.001;

    /** ما يمنع إلغاءَ الفاتورة الآن، بسببه — أو لا شيء */
    public static function cancelRefusal(CustomerInvoice $invoice): ?string
    {
        if ($invoice->status !== CustomerInvoice::ISSUED) {
            return null;
        }

        if (self::asked($invoice) > 0) {
            return __('على الفاتورة رابط دفع لم يُدفع بعد أو محاولة لم تُعرف نتيجتها — ألغِ الرابط أولًا من «المالية ← روابط الدفع»، فلا يدفع العميل فاتورة ملغاة.');
        }

        // فاتورةُ الطلبات لا قيدَ لها يُعكس — قيدُها قيدُ طلباتها
        $bid = (int) $invoice->business_id;
        $taxed = Vat::enabled($bid) || (float) $invoice->tax_total > 0;

        if (! $invoice->coversOrders() && $taxed && Vat::isFiled($bid, $invoice->issued_at)) {
            return __('الفاتورة في فترة ضريبية قُدّم إقرارها حتى :d — إلغاؤها يعكس ضريبتها في الفترة الجارية بلا مستند يشير إليها. إن أُلغي التوريد أو أُرجع بعد الإقرار فأصدر إشعارًا دائنًا بالمبلغ، وإن كان خطأً في الفاتورة فالقرار للمحاسب الضريبي.', [
                'd' => Vat::filedThrough($bid)?->toDateString(),
            ]);
        }

        return null;
    }

    /**
     * ما يمنع إشعارًا دائنًا يدويًّا بهذا المبلغ وهذه الضريبة — والحقلُ الذي يُقال عنده.
     *
     * @return array{0: string, 1: string}|null [الحقل، السبب]
     */
    public static function creditNoteRefusal(CustomerInvoice $invoice, float $amount, float $tax): ?array
    {
        $asked = self::asked($invoice);
        if ($asked > 0 && $asked > round($invoice->outstanding() - $amount, 3) + self::ROUNDING) {
            return ['amount', __('على الفاتورة رابط دفع بـ :n لم يُدفع بعد أو محاولة لم تُعرف نتيجتها — والإشعار يُنزل ما عليها تحته. ألغِ الرابط أولًا، فلا يُطلب من العميل أكثر مما عليه.', [
                'n' => number_format($asked, 3),
            ])];
        }

        $left = round(max(0.0, (float) $invoice->tax_total - (float) $invoice->creditNotes()->sum('tax_amount')), 3);
        if ($tax > $left + self::ROUNDING) {
            return ['tax_amount', $left <= 0
                ? __('لم يبقَ على الفاتورة ضريبة — لا يحمل إشعارها ضريبة.')
                : __('لا تتجاوز ضريبة الإشعارات ضريبة الفاتورة — الباقي منها :n.', ['n' => number_format($left, 3)])];
        }

        $rate = $invoice->items()->max('tax_rate');
        if ($rate !== null) {
            $most = round($amount * (float) $rate / (100 + (float) $rate), 3);
            if ($tax > $most + self::ROUNDING) {
                return ['tax_amount', __('ضريبة الإشعار أكبر مما يحمله مبلغه بأعلى نسبة في الفاتورة (:r٪) — أقصاها :n.', [
                    'r' => rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'),
                    'n' => number_format($most, 3),
                ])];
            }
        }

        return null;
    }

    /**
     * ما تطلبه روابطُ الدفع من الفاتورة الآن — المعلَّقةُ التي لم تنقضِ، والمحاولةُ التي لا تُعرف نتيجتُها.
     *
     * ورابطُ التجربة لا يُحصِّل مالًا (`PaymentLinks::settle`) — فلا يُعدّ.
     */
    private static function asked(CustomerInvoice $invoice): float
    {
        $pending = (float) CustomerPaymentLink::where('business_id', $invoice->business_id)
            ->where('customer_invoice_id', $invoice->id)
            ->where('status', CustomerPaymentLink::PENDING)
            ->where('is_live', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('amount');

        $doubt = PaymentLinks::doubt($invoice);

        return round($pending + ($doubt && $doubt->is_live ? (float) $doubt->amount : 0.0), 3);
    }
}

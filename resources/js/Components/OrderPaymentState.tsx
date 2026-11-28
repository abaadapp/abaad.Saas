import { Badge } from '@/Components/ui/badge';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * حالُ سداد الطلب كما يُعرض — يبنيه الخادم من تحصيلاته (`OrderPaymentState`).
 *
 * `payment_status` في الطلب يُكتب يومَ البيع ولا يتغيّر: طلبُ الدفع عند الاستلام
 * يبقى فيه «غير مدفوع» بعد أن قُبضت فاتورتُه كلُّها. فالشارةُ من هنا، والحقلُ
 * لما يسأله التصحيح وحده.
 *
 * ومبلغٌ `null` لا يُعرف للطلب بعينه: فاتورتُه تجمعه بغيره ونصيبُه منها تقدير
 * (`estimated`). فتُقال أرقامُ الفاتورة، ولا يُقال للطلب «قُبض» ولا «يُحصَّل».
 */
export interface PayInvoice {
    id: number;
    number: string;
    /** كم طلبًا حيًّا تجمع */
    orders?: number;
    total?: number;
    paid?: number;
    received?: number;
    cheque_pending?: number;
    credited?: number;
    due?: number;
}

export interface PayState {
    /** مدفوع · مدفوع جزئيًا · غير مدفوع · ملغى — قاعدةُ سجلّ كل المبيعات */
    state: string;
    /** ما يُكتب على الشارة — وما رُدّ كلُّه يُسمّى «مرتجع» أو «مسترد»، وما سُدّ بشيكٍ لم يُصرف يُقال باسمه */
    label: string;
    /** ما رُدّ بعضُه: «مرتجع جزئيًا» أو «مسترد جزئيًا» */
    marker: string | null;
    /** على من تقع العلامة: الطلبُ، أم فاتورتُه التي تجمعه بغيره */
    marker_on?: 'order' | 'invoice' | null;
    /** «شيك تحت التحصيل» بجانب «مدفوع جزئيًا» */
    cheque_marker?: string | null;
    /** نصيبُ الطلب من فاتورته تقدير — فلا مبلغَ له، وأرقامُ الفاتورة في `invoice` */
    estimated?: boolean;
    paid: number | null;
    /** ما وصل فعلًا — المقبوضُ ناقصًا الشيكَ الذي لم يُصرف */
    received?: number | null;
    due: number | null;
    credited: number | null;
    returned: number | null;
    refunded: number;
    /** ما قُبض بشيكٍ لم يُصرف بعد */
    cheque_pending: number | null;
    /** طلبٌ ملغى قُبضت فاتورتُه: مالُه رصيدٌ للعميل حتى يُردّ */
    customer_credit: number;
    invoice: PayInvoice | null;
}

const VARIANT: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = {
    مدفوع: 'success',
    'مدفوع جزئيًا': 'warning',
    'غير مدفوع': 'warning',
    ملغى: 'danger',
    مرتجع: 'neutral',
    مسترد: 'neutral',
    // سُدّ بورقةٍ لم تُصرف: لا أخضرَ «مدفوع» — المالُ لم يصل بعد
    'مسدَّد بشيك تحت التحصيل': 'info',
};

/** الشارةُ وعلامتا ما رُدّ والشيك — سطرٌ واحد */
export function PayStateBadges({ pay, className }: { pay: PayState; className?: string }) {
    const t = useTranslate();

    return (
        <span className={cn('inline-flex flex-wrap items-center gap-1', className)} data-testid="pay-state">
            <Badge variant={VARIANT[pay.label] ?? 'neutral'} data-testid="pay-state-label">
                {t(pay.label)}
            </Badge>
            {pay.marker && (
                <Badge variant="info" data-testid="pay-state-marker">
                    {t(pay.marker)}
                    {pay.marker_on === 'invoice' && <> · {t('على الفاتورة')}</>}
                </Badge>
            )}
            {pay.cheque_marker && (
                <Badge variant="info" data-testid="pay-state-cheque">
                    {t(pay.cheque_marker)}
                </Badge>
            )}
        </span>
    );
}

/** ما يُحصَّل من الطلب عند التسليم — الباقي وحده، ولا شيء عن نصيبٍ مقدَّر */
export function payToCollect(pay: PayState): number {
    return pay.state !== 'ملغى' && pay.due !== null && pay.due > 0 ? pay.due : 0;
}

/** وما بقي على فاتورةٍ تجمعه بغيره — يُقال عليها لا عليه */
export function payInvoiceDue(pay: PayState): number {
    return pay.state !== 'ملغى' && pay.estimated && pay.invoice?.due ? pay.invoice.due : 0;
}

/** ما قُبض وما بقي، ومصدرُه — تحت الشارة حيث يتّسع المكان */
export function PayStateFacts({ pay, m }: { pay: PayState; m: (v: number) => string }) {
    const t = useTranslate();
    const lines: Array<[string, string, string]> = [];
    const inv = pay.invoice;

    if (pay.state !== 'ملغى') {
        if (pay.estimated && inv) {
            // نصيبُ الطلب تقدير: تُقال الفاتورةُ بأرقامها، ولا يُنسب لهذا الطلب مالٌ بعينه
            lines.push(['shared', t('الفاتورة تجمع طلبات'), String(inv.orders ?? '')]);
            if ((inv.received ?? 0) > 0) lines.push(['invoice-received', t('المقبوض فعلًا على الفاتورة'), m(inv.received ?? 0)]);
            if ((inv.cheque_pending ?? 0) > 0) lines.push(['invoice-cheque', t('شيك تحت التحصيل على الفاتورة — لم يُصرف'), m(inv.cheque_pending ?? 0)]);
            if ((inv.due ?? 0) > 0) lines.push(['invoice-due', t('المتبقّي على الفاتورة'), m(inv.due ?? 0)]);
        } else {
            const received = pay.received ?? (pay.paid ?? 0) - (pay.cheque_pending ?? 0);
            if (received > 0) lines.push(['paid', t('المقبوض فعلًا'), m(received)]);
            if ((pay.cheque_pending ?? 0) > 0) lines.push(['cheque', t('شيك تحت التحصيل — لم يُصرف'), m(pay.cheque_pending ?? 0)]);
            // والمتبقّي بعد تصحيح طلبٍ مدفوع له سطرُه وزرُّ تحصيله في الشاشة نفسها (`balance_due`)
            if ((pay.due ?? 0) > 0 && (inv || pay.state === 'غير مدفوع')) lines.push(['due', t('المتبقّي على العميل'), m(pay.due ?? 0)]);
            if ((pay.returned ?? 0) > 0) lines.push(['returned', t('المرتجع بإشعار دائن'), m(pay.returned ?? 0)]);
        }
        if (pay.refunded > 0) lines.push(['refunded', t('المردود للعميل'), m(pay.refunded)]);
    } else if (pay.customer_credit > 0) {
        lines.push(['credit', t('قُبض وبقي رصيدًا للعميل — يُردّ بيد التاجر'), m(pay.customer_credit)]);
    }
    if (inv) lines.push(['invoice', t('يُحصَّل على فاتورة العميل'), inv.number]);

    if (lines.length === 0) return null;

    return (
        <dl className="mt-2 space-y-1 text-[12px]" data-testid="pay-state-facts">
            {lines.map(([key, label, value]) => (
                <div key={key} className="flex items-center justify-between gap-2" data-testid={`pay-state-${key}`}>
                    <dt className="text-[#6b7280]">{label}</dt>
                    <dd className="font-semibold tabular-nums text-[#111]" dir="auto">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** ما يُحصَّل على بطاقة التجهيز — الباقي على الطلب، أو الباقي على فاتورته إن جمعته بغيره */
export function PayCollect({ pay, m, className }: { pay: PayState; m: (v: number) => string; className?: string }) {
    const t = useTranslate();
    const own = payToCollect(pay);
    const onInvoice = payInvoiceDue(pay);

    if (own > 0) {
        return (
            <span className={cn('font-semibold tabular-nums text-[#b45309]', className)} data-testid="prep-collect">
                {t('يُحصَّل')}: {m(own)}
            </span>
        );
    }
    if (onInvoice > 0 && pay.invoice) {
        return (
            <span className={cn('font-semibold tabular-nums text-[#b45309]', className)} data-testid="prep-collect-invoice">
                {t('متبقٍّ على الفاتورة')} {pay.invoice.number}: {m(onInvoice)}
            </span>
        );
    }

    return null;
}

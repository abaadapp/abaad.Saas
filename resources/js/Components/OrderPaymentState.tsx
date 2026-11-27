import { Badge } from '@/Components/ui/badge';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * حالُ سداد الطلب كما يُعرض — يبنيه الخادم من تحصيلاته (`OrderPaymentState`).
 *
 * `payment_status` في الطلب يُكتب يومَ البيع ولا يتغيّر: طلبُ الدفع عند الاستلام
 * يبقى فيه «غير مدفوع» بعد أن قُبضت فاتورتُه كلُّها. فالشارةُ من هنا، والحقلُ
 * لما يسأله التصحيح وحده.
 */
export interface PayState {
    /** مدفوع · مدفوع جزئيًا · غير مدفوع · ملغى — قاعدةُ سجلّ كل المبيعات */
    state: string;
    /** ما يُكتب على الشارة — وما رُدّ كلُّه يُسمّى «مرتجع» أو «مسترد» */
    label: string;
    /** ما رُدّ بعضُه: «مرتجع جزئيًا» أو «مسترد جزئيًا» */
    marker: string | null;
    paid: number;
    due: number;
    credited: number;
    returned: number;
    refunded: number;
    /** ما قُبض بشيكٍ لم يُصرف بعد */
    cheque_pending: number;
    /** طلبٌ ملغى قُبضت فاتورتُه: مالُه رصيدٌ للعميل حتى يُردّ */
    customer_credit: number;
    invoice: { id: number; number: string } | null;
}

const VARIANT: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = {
    مدفوع: 'success',
    'مدفوع جزئيًا': 'warning',
    'غير مدفوع': 'warning',
    ملغى: 'danger',
    مرتجع: 'neutral',
    مسترد: 'neutral',
};

/** الشارةُ وعلامةُ ما رُدّ — سطرٌ واحد */
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
                </Badge>
            )}
        </span>
    );
}

/** ما قُبض وما بقي، ومصدرُه — تحت الشارة حيث يتّسع المكان */
export function PayStateFacts({ pay, m }: { pay: PayState; m: (v: number) => string }) {
    const t = useTranslate();
    const lines: Array<[string, string, string]> = [];

    if (pay.state !== 'ملغى') {
        if (pay.paid > 0) lines.push(['paid', t('المقبوض'), m(pay.paid)]);
        // والمتبقّي بعد تصحيح طلبٍ مدفوع له سطرُه وزرُّ تحصيله في الشاشة نفسها (`balance_due`)
        if (pay.due > 0 && (pay.invoice || pay.state === 'غير مدفوع')) lines.push(['due', t('المتبقّي على العميل'), m(pay.due)]);
        if (pay.returned > 0) lines.push(['returned', t('المرتجع بإشعار دائن'), m(pay.returned)]);
        if (pay.refunded > 0) lines.push(['refunded', t('المردود للعميل'), m(pay.refunded)]);
        if (pay.cheque_pending > 0) lines.push(['cheque', t('منه بشيك تحت التحصيل'), m(pay.cheque_pending)]);
    } else if (pay.customer_credit > 0) {
        lines.push(['credit', t('قُبض وبقي رصيدًا للعميل — يُردّ بيد التاجر'), m(pay.customer_credit)]);
    }
    if (pay.invoice) lines.push(['invoice', t('يُحصَّل على فاتورة العميل'), pay.invoice.number]);

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

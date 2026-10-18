import { Loader2, Printer } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import useInvoicePrintJobs from '@/hooks/useInvoicePrintJobs';
import { failureMessage } from '@/lib/printing/printer-service';
import { useTranslate } from '@/lib/i18n';

/**
 * طابورُ «فاتورة عميل — نسخة داخلية» على الصندوق — يُرى حين لا يطبع وحده.
 *
 * في تطبيق أبعاد بطابعة شبكةٍ معيَّنة لا يُرى شيء: الورقةُ تخرج وحدها،
 * وفشلُها يُقال في إشعار. وفي المتصفّح سطرٌ صغيرٌ يقول كم ينتظر وزرٌّ يطبع —
 * فلا يُوعد بطباعةٍ صامتةٍ لا يقدر عليها متصفّح. ولا شيءَ يُرسم لصندوقٍ لم
 * تُعيَّن طابعتُه.
 */
export default function InvoicePrintQueue() {
    const t = useTranslate();
    const { mode, waiting, busy, printNext } = useInvoicePrintJobs((outcome) => {
        if (!outcome.ok) toast.warning(`${t('فاتورة عميل — نسخة داخلية')}: ${failureMessage(outcome.code, t)}`);
    });

    if (mode !== 'manual' || waiting < 1) return null;

    return (
        <div
            role="status"
            data-testid="invoice-print-queue"
            className="flex flex-wrap items-center justify-between gap-2 border-b border-[#ddd6fe] bg-[#f5f3ff] px-4 py-2 text-[13px] text-[#4c1d95]"
        >
            <span>
                {t('فواتير عملاء مسددة بانتظار الطباعة')}: <b dir="ltr">{waiting}</b>
            </span>
            <Button size="sm" variant="outline" onClick={() => void printNext()} disabled={busy} data-testid="invoice-print-next">
                {busy ? <Loader2 className="animate-spin" /> : <Printer />}
                {t('طباعة الفاتورة الداخلية')}
            </Button>
        </div>
    );
}

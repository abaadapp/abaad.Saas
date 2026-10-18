import { CheckCircle2, FileText, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import type { ReceiptPrintState } from '@/hooks/useReceiptPrint';
import { failureMessage, retryable, stageMessage } from '@/lib/printing/printer-service';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * حالُ الطباعة المباشرة — سطرٌ واحدٌ بسيط، وفي الفشل مخرجان.
 *
 * البيعُ والطباعةُ نتيجتان منفصلتان: بيعٌ حُفظ لا يُلغى لأنّ الطابعة لم ترد.
 * فيقول الفشلُ ما حُفظ أوّلًا (`headline`)، ثمّ سببَه بجملةٍ لا رمزَ فيها، ثمّ
 * «إعادة الطباعة» و«فتح PDF». ولا إعادةَ تلقائيّة: ما بلغ الورقَ بعضُه يُرى
 * قبل أن يُطبع ثانيةً.
 *
 * ولا يُرسم شيءٌ لطباعة المتصفّح: اللسانُ الذي فُتح هو الجواب.
 */
export default function PrintStatus({
    state,
    headline,
    onRetry,
    onOpen,
    className,
}: {
    state: ReceiptPrintState;
    /** ما يُقال قبل السبب حين تفشل — «تم حفظ البيع، لكن…» في شاشة الدفع */
    headline?: string;
    onRetry: () => void;
    onOpen?: () => void;
    className?: string;
}) {
    const t = useTranslate();
    const { stage, outcome } = state;

    if (stage === 'idle' || outcome?.via === 'browser') return null;

    // حوارُ الطباعة في النظام ظاهرٌ بنفسه — لا يُقال «طُبع» عمّا لم يُطبع بعد
    if (stage === 'success' && outcome?.via === 'system') return null;

    if (stage === 'success') {
        return (
            <p
                role="status"
                data-testid="print-status"
                className={cn('flex items-center justify-center gap-1.5 text-[13px] text-[#047857]', className)}
            >
                <CheckCircle2 className="size-4" />
                {stageMessage('success', t)}
            </p>
        );
    }

    if (stage === 'failed' && outcome && !outcome.ok) {
        return (
            <div
                role="alert"
                data-testid="print-status"
                className={cn('space-y-2 rounded-xl border border-[#fecaca] bg-[#fef2f2] p-3 text-start text-[13px]', className)}
            >
                {headline && <p className="font-bold text-[#991b1b]">{headline}</p>}
                <p className="text-[#b91c1c]">{failureMessage(outcome.code, t)}</p>
                <div className="flex flex-wrap gap-2">
                    {/* ومتصفّحٌ لا يطبع من الصفحة لا يُعاد عليه: «فتح PDF» وحده */}
                    {retryable(outcome) && (
                        <Button type="button" size="sm" variant="outline" className="rounded-full" onClick={onRetry}>
                            <RotateCcw />
                            {t('إعادة الطباعة')}
                        </Button>
                    )}
                    {onOpen && (
                        <Button type="button" size="sm" variant="ghost" className="rounded-full" onClick={onOpen}>
                            <FileText />
                            {t('فتح PDF')}
                        </Button>
                    )}
                </div>
            </div>
        );
    }

    return (
        <p
            role="status"
            data-testid="print-status"
            className={cn('flex items-center justify-center gap-1.5 text-[13px] text-gray-500', className)}
        >
            <Loader2 className="size-4 animate-spin" />
            {stageMessage(stage, t)}
        </p>
    );
}

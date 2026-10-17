import type { ComponentProps } from 'react';
import { toast } from 'sonner';
import { Loader2, Printer } from 'lucide-react';
import PrintStatus from '@/Components/PrintStatus';
import { Button } from '@/Components/ui/button';
import useReceiptPrint from '@/hooks/useReceiptPrint';
import { failureMessage, insideAbaadApp, stageMessage } from '@/lib/printing/printer-service';
import type { PrintOutcome } from '@/lib/printing/types';
import { useTranslate } from '@/lib/i18n';

type Translate = (key: string) => string;

/**
 * نتيجةُ طباعةٍ مباشرة في إشعار — لأزرارٍ لا مكانَ تحتها لسطر حالة.
 *
 * ولا شيءَ للمتصفّح: اللسانُ الذي فُتح هو الجواب. ولا «طُبع» لحوار النظام:
 * ظهورُه ليس طباعة.
 */
export function announcePrint(
    outcome: PrintOutcome | null,
    t: Translate,
    retry: () => void,
    openPdf?: () => void,
): void {
    if (!outcome || outcome.via === 'browser') return;

    if (outcome.ok) {
        if (outcome.via === 'native') toast.success(stageMessage('success', t));

        return;
    }

    toast.error(failureMessage(outcome.code, t), {
        duration: 15000,
        action: { label: t('إعادة الطباعة'), onClick: retry },
        ...(openPdf ? { cancel: { label: t('فتح PDF'), onClick: openPdf } } : {}),
    });
}

/**
 * زرُّ «طباعة» لإيصالٍ حراريّ — بابٌ واحدٌ لكلّ شاشة.
 *
 * في تطبيق أبعاد يطبع مباشرةً على طابعة الشبكة (أو بحوار النظام)، وحالُه
 * تحته (`inline`) أو في إشعار.
 *
 * وفي المتصفّح هو الرابطُ نفسُه الذي كان — `<a target="_blank">` إلى الـPDF —
 * لا زرٌّ يحاكيه: الضغطُ بالوسط و«فتح في لسان» و«نسخ الرابط» تبقى كما كانت.
 * فالتاجرُ الذي يطبع من المتصفّح لا يرى فرقًا.
 */
export default function PrintReceiptButton({
    number,
    routeName = 'pos.receipt.pdf',
    label,
    iconOnly = false,
    inline = false,
    variant,
    size,
    className,
}: {
    number: string;
    /** بابُ الإيصال في قسم الشاشة — `admin.orders.receipt` في شاشة الطلب */
    routeName?: string;
    label?: string;
    iconOnly?: boolean;
    /** سطرُ الحال تحت الزرّ بدل الإشعار */
    inline?: boolean;
    variant?: ComponentProps<typeof Button>['variant'];
    size?: ComponentProps<typeof Button>['size'];
    className?: string;
}) {
    const t = useTranslate();
    const printing = useReceiptPrint(routeName);
    const text = label ?? t('طباعة الفاتورة');

    const print = () => {
        void printing.print(number).then((outcome) => {
            if (!inline) announcePrint(outcome, t, print, () => void printing.openPdf(number));
        });
    };

    if (!insideAbaadApp()) {
        return (
            <Button variant={variant} size={size} className={className} asChild>
                <a
                    href={printing.urlOf(number)}
                    target="_blank"
                    rel="noreferrer"
                    title={iconOnly ? text : undefined}
                    aria-label={iconOnly ? text : undefined}
                >
                    <Printer />
                    {!iconOnly && text}
                </a>
            </Button>
        );
    }

    const button = (
        <Button
            type="button"
            variant={variant}
            size={size}
            className={className}
            disabled={printing.busy}
            onClick={print}
            title={iconOnly ? text : undefined}
            aria-label={iconOnly ? text : undefined}
        >
            {printing.busy ? <Loader2 className="animate-spin" /> : <Printer />}
            {!iconOnly && text}
        </Button>
    );

    if (!inline) return button;

    return (
        <>
            {button}
            <PrintStatus
                className="mt-2"
                state={printing.state}
                onRetry={print}
                onOpen={() => void printing.openPdf(number)}
            />
        </>
    );
}

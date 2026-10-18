import { useEffect, type ComponentProps } from 'react';
import { toast } from 'sonner';
import { Loader2, Printer, Share } from 'lucide-react';
import PrintStatus from '@/Components/PrintStatus';
import { Button } from '@/Components/ui/button';
import useReceiptPrint from '@/hooks/useReceiptPrint';
import { failureMessage, insideAbaadApp, retryable, stageMessage } from '@/lib/printing/printer-service';
import type { PrintOutcome } from '@/lib/printing/types';
import { useTranslate } from '@/lib/i18n';

type Translate = (key: string) => string;

/**
 * نتيجةُ طباعةٍ مباشرة في إشعار — لأزرارٍ لا مكانَ تحتها لسطر حالة.
 *
 * ولا شيءَ للمتصفّح: اللسانُ الذي فُتح هو الجواب. ولا «طُبع» لحوار النظام
 * ولا لحوار الطباعة في الصفحة: ظهورُه ليس طباعة.
 *
 * ومتصفّحٌ لا يطبع من الصفحة لا يُعرض له «إعادة الطباعة» — لن تُجدي —
 * بل «فتح PDF» وحده. وقائمةُ مشاركةٍ أغلقها الكاشيرُ بنفسه لا يُقال عنها شيء.
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

    if (outcome.code === 'SHARE_CANCELLED') return;

    if (!retryable(outcome)) {
        toast.error(failureMessage(outcome.code, t), {
            duration: 15000,
            ...(openPdf ? { action: { label: t('فتح PDF'), onClick: openPdf } } : {}),
        });

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
 *
 * إلّا في نقطة البيع (`inPage`): زرٌّ يطلب حوارَ الطباعة على الـPDF نفسِه في
 * إطارٍ مخفيّ، والكاشيرُ في شاشته — انظر `lib/printing/page-print`.
 */
/** «طباعة أو مشاركة PDF» — اسمُ الفعل حيث هو قائمةُ مشاركة، لا «طباعة» يُوعد بها */
export const SHARE_LABEL = 'طباعة أو مشاركة PDF';

/**
 * «الإيصال جاهز» — والضغطةُ في الإشعار هي التي تفتح القائمة.
 *
 * الكاشيرُ ضغط قبل أن يُجهَّز الإيصال، والقائمةُ لا تُفتح بعد انتظار. فيُقال
 * له إنّه جاهز، وزرُّ الإشعار ضغطةٌ جديدة حيّة.
 */
export function announceReady(t: Translate, share: () => void): void {
    toast.info(stageMessage('ready', t), {
        duration: 15000,
        action: { label: t(SHARE_LABEL), onClick: share },
    });
}

export default function PrintReceiptButton({
    number,
    routeName = 'pos.receipt.pdf',
    label,
    iconOnly = false,
    inline = false,
    variant,
    size,
    className,
    inPage = false,
    prefetch = false,
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
    /** نقطةُ البيع: الطباعةُ في مكانها لا في لسانٍ جديد */
    inPage?: boolean;
    /**
     * يُجهَّز الإيصالُ لقائمة المشاركة حين يُعرض الزرّ — لإيصالٍ فُتح عمدًا
     * (شاشةُ النجاح، والدرج، وتفاصيلُ الطلب)، لا لكلّ صفٍّ في جدول.
     */
    prefetch?: boolean;
}) {
    const t = useTranslate();
    const printing = useReceiptPrint(routeName, { inPage });
    const sharing = printing.mode === 'share';
    const text = label ?? t(sharing ? SHARE_LABEL : 'طباعة الفاتورة');

    const print = () => {
        void printing.print(number).then((outcome) => {
            if (!inline) announcePrint(outcome, t, print, () => void printing.openPdf(number));
        });
    };

    // ويُجهَّز بصمتٍ — لا قائمةَ تُفتح ولا سطرَ حال
    useEffect(() => {
        if (sharing && prefetch) void printing.prepare(number, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sharing, prefetch, number]);

    // وجاهزٌ بعد ضغطةٍ سبقته — إشعارٌ بزرّ، حيث لا سطرَ تحت الزرّ
    useEffect(() => {
        if (!inline && printing.state.stage === 'ready' && printing.state.invoice === number) announceReady(t, print);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [printing.state.stage, printing.state.invoice]);

    if (!insideAbaadApp() && !inPage) {
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
            {printing.busy ? <Loader2 className="animate-spin" /> : sharing ? <Share /> : <Printer />}
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

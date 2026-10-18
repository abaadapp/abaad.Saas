import { useEffect, useRef, useState } from 'react';
import { Eye, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import DocumentPreview from '@/Components/DocumentPreview';
import { announcePrint, SHARE_LABEL } from '@/Components/PrintReceiptButton';
import PrintStatus from '@/Components/PrintStatus';
import useReceiptPrint from '@/hooks/useReceiptPrint';
import { insideAbaadApp } from '@/lib/printing/printer-service';
import type { PrintOutcome } from '@/lib/printing/types';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { useTenantLink } from '@/lib/exportLink';

/**
 * «معاينة الفاتورة» — الورقةُ فوق شاشة البيع، لا بدلًا منها.
 *
 * ═══ العطبُ الذي يعالجه ═══
 *
 * لم يكن في نقطة البيع فعلٌ إلّا «طباعة الفاتورة»، وهو يفتح ملفَّ PDF في
 * لسانٍ آخر. وعلى سطح المكتب لسانٌ إلى جانب لسان؛ وعلى الآيباد والهاتف
 * يملأ قارئُ PDF الشاشةَ ولا شريطَ ألسنةٍ يُرى — فتختفي شاشةُ البيع، ويقف
 * الكاشير أمام ورقةٍ لا يعرف كيف يرجع منها والزبون واقف.
 *
 * وهو يريد أن **يرى** ما سيُسلَّم قبل أن يمزّق الشريط: أصحيحٌ الصنف؟ أظهر
 * الخصم؟ أطُبع اسمُ العميل؟ وهذا سؤالُ معاينةٍ لا سؤالُ طباعة.
 *
 * ═══ ولمَ `DocumentPreview` لا قارئٌ جديد ═══
 *
 * النافذةُ نفسُها تستعملها ستُّ شاشاتٍ في اللوحة: فاتورةُ العميل، وأمرُ
 * الشراء، وسندُ الاستلام، وإشعارُ الدائن، وشاشةُ الطلب. وفيها الأفعالُ
 * الثلاثة مفروزةً أصلًا — معاينةٌ تُري ولا تُنزل، وطباعةٌ، وتحميل — ورسمُ
 * الورقة HTML بلا شريط قارئ المتصفّح فوقها. وقارئٌ سابعٌ يُكتب هنا يعني
 * أنّ إصلاحًا في أحدهما لا يبلغ الآخر.
 *
 * ═══ والنصُّ يُطلب عند الفتح لا مع كلّ بيعة ═══
 *
 * لو حُمل مع ردّ الدفع لَحُمل في كلّ بيعةٍ وإن لم يُنظر إليه — ونقطةُ البيع
 * تبيع مئةً في اليوم ويُعاين منها ما يُشكّ فيه. فيُطلب عند الضغط، ويُحفظ
 * بعدها: فتحةٌ ثانيةٌ للإيصال نفسِه لا تسأل الخادمَ من جديد.
 *
 * ولا `Blob` ولا `URL.createObjectURL` في هذا الطريق: النصُّ نصٌّ يُرسَم،
 * فلا رابطَ كائنٍ يُنسى فلا يُحرَّر.
 */
interface Paper {
    html: string;
    size: string;
}

export default function ReceiptPreviewButton({
    number,
    label,
    className,
    variant = 'outline',
}: {
    /** رقمُ الفاتورة — ولا يُعرض الزرّ بدونه */
    number: string;
    label?: string;
    className?: string;
    variant?: 'primary' | 'outline' | 'subtle' | 'ghost';
}) {
    const t = useTranslate();
    // والمعاينةُ تُطلب بنشاط هذه الصفحة — انظر `TenantExportGuard`
    const tenant = useTenantLink();
    // والطباعةُ في مكانها — انظر `lib/printing/page-print`
    const printing = useReceiptPrint('pos.receipt.pdf', { inPage: true });
    const [paper, setPaper] = useState<Paper | null>(null);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);

    /*
     * ما حُمِّل يُحفظ برقمه.
     *
     * والرقمُ معه لأنّ الزرّ نفسَه قد يخدم فاتورةً أخرى بعد إغلاق النافذة
     * (صفُّ جدولٍ يتبدّل)، فورقةٌ محفوظةٌ بلا رقمها تُعرض لفاتورةٍ ليست لها
     * — والكاشير يقرأ إيصالَ الزبون السابق ويظنّه إيصالَ الواقف أمامه.
     */
    const cached = useRef<{ number: string; paper: Paper } | null>(null);

    /*
     * والطلبُ المعلَّق يُلغى عند الخروج.
     *
     * الكاشير يضغط «معاينة» ثمّ «طلب جديد» قبل أن يصل الردّ — فلو تُرك
     * لَفتح النافذةَ فوق سلّةٍ جديدة بعد أن غادرها.
     */
    const flight = useRef<AbortController | null>(null);

    useEffect(() => () => flight.current?.abort(), []);

    /*
     * وفي الآيفون والآيباد يُجهَّز الملفُّ حين تُفتح المعاينة — فتفتح «طباعة أو
     * مشاركة PDF» قائمةَ المشاركة في ضغطتها. وزرُّها ينتظر حتى يُجهَّز.
     * ومن الخادم في كلّ فتح: الطلبُ قد عُدِّل منذ آخر مرّة.
     */
    const sharing = printing.mode === 'share';
    const prepareReceipt = printing.prepare;
    const [preparing, setPreparing] = useState(false);
    const [unprepared, setUnprepared] = useState<PrintOutcome | null>(null);
    useEffect(() => {
        if (!open || !sharing) return;
        setUnprepared(null);
        setPreparing(true);
        void prepareReceipt(number, true).then((failed) => {
            setPreparing(false);
            setUnprepared(failed);
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, sharing, number]);

    /*
     * وحالُ الطباعة في المتصفّح تُقال داخل المعاينة لا في إشعار.
     *
     * النافذةُ المفتوحة تمنع الضغطَ خارجها: «فتح PDF» و«إعادة الطباعة» في
     * إشعارٍ تحتها يُريان ولا يُضغطان. وفي تطبيق أبعاد الإشعارُ كما كان.
     */
    const inApp = insideAbaadApp();
    const status = unprepared ? { stage: 'failed' as const, outcome: unprepared, invoice: number } : printing.state;
    const again = () => {
        setUnprepared(null);
        void printing.print(number);
    };

    const show = async () => {
        if (loading) return;

        if (cached.current?.number === number) {
            setPaper(cached.current.paper);
            setOpen(true);

            return;
        }

        flight.current?.abort();
        const controller = new AbortController();
        flight.current = controller;
        setLoading(true);

        try {
            const res = await fetch(tenant(route('pos.receipt.paper', number)), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!res.ok) {
                throw new Error(String(res.status));
            }

            const data = (await res.json()) as Paper;

            if (controller.signal.aborted) return;

            cached.current = { number, paper: data };
            setPaper(data);
            setOpen(true);
        } catch (e) {
            if (controller.signal.aborted || (e instanceof DOMException && e.name === 'AbortError')) {
                return;
            }

            /*
             * والفشلُ يُقال في مكانه ولا يُغادَر به.
             *
             * لا سببَ تقنيّ ولا رمزُ حالة: الكاشير لا يُصلح خادمًا وزبونُه
             * واقف. والشاشةُ تبقى كما هي — والطباعةُ بابٌ آخر ما زال مفتوحًا.
             */
            toast.error(t('تعذّر عرض الفاتورة الآن — يمكنك طباعتها.'));
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    };

    return (
        <>
            <Button type="button" variant={variant} className={className} disabled={loading} onClick={show}>
                {loading ? <Loader2 className="animate-spin" /> : <Eye />}
                {label ?? t('معاينة الفاتورة')}
            </Button>

            {paper && (
                <DocumentPreview
                    html={paper.html}
                    size={paper.size}
                    /* والطباعةُ والتحميلُ على بابِ الـPDF نفسِه الذي كان — لم يتبدّل */
                    url={route('pos.receipt.pdf', number)}
                    title={`${t('الفاتورة')} ${number}`}
                    filename={`${number}.pdf`}
                    open={open}
                    onOpenChange={setOpen}
                    printLabel={sharing ? t(SHARE_LABEL) : undefined}
                    printPending={sharing && preparing}
                    status={
                        !inApp && status.stage !== 'idle' ? (
                            <PrintStatus state={status} onRetry={again} onOpen={() => void printing.openPdf(number)} />
                        ) : undefined
                    }
                    /*
                     * وفي تطبيق أبعاد تُطبع على طابعة الصندوق مباشرةً، وفي
                     * المتصفّح يُطلب حوارُ الطباعة على الـPDF نفسِه والمعاينةُ
                     * مفتوحةٌ كما هي — انظر `lib/printing`. و«فتح الملف»
                     * و«تحميل» رابطان كما كانا.
                     */
                    onPrint={() => {
                        setUnprepared(null);
                        void printing.print(number).then((outcome) => {
                            if (inApp) announcePrint(outcome, t, () => void printing.print(number), () => void printing.openPdf(number));
                        });
                    }}
                />
            )}
        </>
    );
}

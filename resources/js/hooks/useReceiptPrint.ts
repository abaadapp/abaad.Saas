import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useTenantLink } from '@/lib/exportLink';
import { insideAbaadApp, openReceiptFile, pagePrintMode, printReceipt, quietOutcome, receiptPrinter } from '@/lib/printing/printer-service';
import { preparedReceipt, prepareReceipt } from '@/lib/printing/share-print';
import type { PrintOutcome, PrintStage } from '@/lib/printing/types';
import type { PageProps } from '@/types';

export interface ReceiptPrintState {
    stage: PrintStage;
    outcome: PrintOutcome | null;
    invoice: string | null;
}

const IDLE: ReceiptPrintState = { stage: 'idle', outcome: null, invoice: null };

/**
 * طباعةُ إيصالٍ من شاشة — وحالُها حتى تنتهي.
 *
 * كلُّ شاشةٍ فيها «طباعة» تمرّ من هنا، فلا تعرف أيٌّ منها أين الطابعة ولا
 * كيف يُرسل إليها: `lib/printing/printer-service` يختار الطريق.
 *
 * ═══ وطباعةٌ واحدة في كلّ مرّة ═══
 *
 * ضغطتان سريعتان على «طباعة» كانتا تفتحان لسانين. وفي التطبيق تعنيان إيصالين
 * على الورق — فالضغطةُ الثانية تُهمل ما دامت الأولى تعمل.
 *
 * ═══ وفي نقطة البيع تُطبع في مكانها ═══
 *
 * `inPage`: في المتصفّح يُطلب حوارُ الطباعة على الـPDF نفسِه في إطارٍ مخفيّ،
 * ولا لسانَ يُفتح (`lib/printing/page-print`). وحالُها تُعرض كحال التطبيق:
 * «جارٍ تجهيز الإيصال…» ثمّ لا شيء — ففتحُ الحوار ليس طباعة — أو سببُ
 * الفشل ومعه «فتح PDF». وسائرُ الشاشات (اللوحة) على اللسان كما كانت.
 *
 * ═══ والآيفون والآيباد: قائمةُ المشاركة في ضغطتها ═══
 *
 * `mode === 'share'`: القائمةُ لا تُفتح إلّا في ضغطةٍ حيّة، والتنزيلُ يُطفئها.
 * فالإيصالُ يُجهَّز قبلها — `prepare(invoice, true)` بصمتٍ حين تُفتح الشاشة —
 * والضغطةُ تشاركه بلا انتظار. وما لم يُجهَّز تُجهّزه الضغطةُ الأولى (الحال
 * `ready`)، وتفتح الثانيةُ القائمة. ولا تُفتح القائمةُ وحدها أبدًا.
 *
 * @param routeName بابُ الإيصال الحراريّ في قسم الشاشة — `pos.receipt.pdf` في الصندوق
 */
export default function useReceiptPrint(routeName = 'pos.receipt.pdf', { inPage = false }: { inPage?: boolean } = {}) {
    const { context } = usePage<PageProps>().props;
    const tenant = useTenantLink();
    const printer = receiptPrinter(context?.peripherals);
    const [state, setState] = useState<ReceiptPrintState>(IDLE);
    // يُعاد الرسمُ حين يُجهَّز إيصالٌ — فيُفتح زرُّه
    const [, setPrepared] = useState(0);
    const mode = inPage && !insideAbaadApp() ? pagePrintMode() : null;
    const working = useRef(false);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;

        return () => {
            alive.current = false;
        };
    }, []);

    const urlOf = useCallback((invoice: string) => tenant(route(routeName, invoice)), [tenant, routeName]);

    /**
     * يُنفّذ المهمّةَ ويتابع حالها.
     *
     * والمهمّةُ تُنادى قبل أيّ انتظار — في المتصفّح تفتح لسانًا، ولا يُفتح لسانٌ
     * إلّا في إيماءةٍ حيّة. وحالُ المتصفّح لا تُعرض: اللسانُ نفسُه هو الجواب.
     *
     * و`shows` تُعرض بها الحالُ قبل الجواب — في التطبيق، وفي الطباعة داخل
     * الصفحة (تنزيلٌ يُنتظر). والطباعةُ التلقائيّة تنادي بلا ثالث: كما كانت.
     */
    const run = useCallback(
        async (
            invoice: string,
            job: (onProgress: (stage: PrintStage) => void) => Promise<PrintOutcome | null>,
            shows: boolean = insideAbaadApp(),
        ) => {
            if (working.current) return null;
            working.current = true;

            const onProgress = (stage: PrintStage) => {
                if (alive.current) setState((s) => ({ ...s, stage }));
            };

            try {
                const pending = job(onProgress);
                if (shows) setState({ stage: 'downloading_receipt', outcome: null, invoice });

                const outcome = await pending;
                if (alive.current) {
                    /*
                     * ولا «تمت الطباعة» عن حوارٍ فُتح في الصفحة: المتصفّح لا يقول
                     * أطَبع الكاشيرُ أم ألغى. فيعود السطرُ إلى سكونه.
                     */
                    setState(quietOutcome(outcome) || !outcome ? IDLE : { stage: outcome.ok ? 'success' : 'failed', outcome, invoice });
                }

                return outcome;
            } finally {
                working.current = false;
            }
        },
        [],
    );

    /**
     * يُجهَّز الإيصالُ لقائمة المشاركة — ولا يُفتح شيء.
     *
     * `quietly`: حين تُفتح الشاشة، بلا سطرِ حال. وبغيرها: الكاشيرُ ضغط قبل أن
     * يُجهَّز، فيرى «جارٍ تجهيز الإيصال…» ثمّ «جاهز» ومعه زرُّ المشاركة.
     */
    const prepare = useCallback(
        (invoice: string, quietly = false): Promise<PrintOutcome | null> => {
            if (mode !== 'share') return Promise.resolve(null);

            const url = urlOf(invoice);
            if (preparedReceipt(url)) {
                if (!quietly) setState({ stage: 'ready', outcome: null, invoice });

                return Promise.resolve(null);
            }

            if (!quietly) setState({ stage: 'downloading_receipt', outcome: null, invoice });

            return prepareReceipt(url, invoice).then((result) => {
                if (!alive.current) return null;
                setPrepared((n) => n + 1);

                if (result instanceof File) {
                    if (!quietly) setState({ stage: 'ready', outcome: null, invoice });

                    return null;
                }
                if (!quietly) setState({ stage: 'failed', outcome: result, invoice });

                return result;
            });
        },
        [mode, urlOf],
    );

    /** أجُهّز هذا الإيصالُ لقائمة المشاركة؟ */
    const isReady = useCallback((invoice: string) => mode === 'share' && preparedReceipt(urlOf(invoice)) !== null, [mode, urlOf]);

    const print = useCallback(
        (invoice: string) => {
            // لم يُجهَّز بعد ← تُجهّزه هذه الضغطة، وتفتح القائمةَ التاليةُ
            if (mode === 'share' && preparedReceipt(urlOf(invoice)) === null) return prepare(invoice);

            return run(
                invoice,
                (onProgress) => printReceipt({ invoice, url: urlOf(invoice), printer, onProgress, inPage }),
                // وقائمةُ المشاركة لا «جارٍ تجهيز» تحتها: الملفُّ جاهزٌ والقائمةُ مفتوحة
                insideAbaadApp() || (inPage && mode !== 'share'),
            );
        },
        [run, urlOf, printer, inPage, mode, prepare],
    );

    /** «إعادة الطباعة» — طلبٌ جديدٌ صريح من الكاشير، لا إعادةٌ تلقائيّة */
    const retry = useCallback(() => (state.invoice ? print(state.invoice) : Promise.resolve(null)), [print, state.invoice]);

    /** «فتح PDF» — الملفُّ نفسُه بحوار النظام في التطبيق، وبلسانٍ في المتصفّح */
    const openPdf = useCallback(
        (invoice = state.invoice) => (invoice ? openReceiptFile({ invoice, url: urlOf(invoice) }) : Promise.resolve(null)),
        [state.invoice, urlOf],
    );

    const reset = useCallback(() => setState(IDLE), []);

    return {
        printer,
        state,
        busy: state.stage !== 'idle' && state.stage !== 'success' && state.stage !== 'failed' && state.stage !== 'ready',
        /** طريقُ الطباعة في الصفحة — `null` في التطبيق وخارج نقطة البيع */
        mode,
        urlOf,
        run,
        print,
        prepare,
        isReady,
        retry,
        openPdf,
        reset,
    };
}

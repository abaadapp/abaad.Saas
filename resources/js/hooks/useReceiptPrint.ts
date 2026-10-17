import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useTenantLink } from '@/lib/exportLink';
import { insideAbaadApp, openReceiptFile, printReceipt, receiptPrinter } from '@/lib/printing/printer-service';
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
 * @param routeName بابُ الإيصال الحراريّ في قسم الشاشة — `pos.receipt.pdf` في الصندوق
 */
export default function useReceiptPrint(routeName = 'pos.receipt.pdf') {
    const { context } = usePage<PageProps>().props;
    const tenant = useTenantLink();
    const printer = receiptPrinter(context?.peripherals);
    const [state, setState] = useState<ReceiptPrintState>(IDLE);
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
     */
    const run = useCallback(
        async (invoice: string, job: (onProgress: (stage: PrintStage) => void) => Promise<PrintOutcome | null>) => {
            if (working.current) return null;
            working.current = true;

            const shows = insideAbaadApp();
            const onProgress = (stage: PrintStage) => {
                if (alive.current) setState((s) => ({ ...s, stage }));
            };

            try {
                const pending = job(onProgress);
                if (shows) setState({ stage: 'downloading_receipt', outcome: null, invoice });

                const outcome = await pending;
                if (alive.current) {
                    setState(
                        outcome && outcome.via !== 'browser'
                            ? { stage: outcome.ok ? 'success' : 'failed', outcome, invoice }
                            : IDLE,
                    );
                }

                return outcome;
            } finally {
                working.current = false;
            }
        },
        [],
    );

    const print = useCallback(
        (invoice: string) =>
            run(invoice, (onProgress) => printReceipt({ invoice, url: urlOf(invoice), printer, onProgress })),
        [run, urlOf, printer],
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
        busy: state.stage !== 'idle' && state.stage !== 'success' && state.stage !== 'failed',
        urlOf,
        run,
        print,
        retry,
        openPdf,
        reset,
    };
}

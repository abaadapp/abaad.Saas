import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useTenantLink } from '@/lib/exportLink';
import { acknowledgeJob, claimJob, confirmJob, invoicePrinter, queueState, type AwaitingJob, type ClaimedJob } from '@/lib/printing/print-jobs';
import { insideAbaadApp, nativePrintingAvailable, pagePrintMode, printReceipt } from '@/lib/printing/printer-service';
import type { PrintOutcome } from '@/lib/printing/types';
import type { PageProps } from '@/types';

/** نبضُ السؤال — لا أسرعُ من أن تمرّ ورقةٌ دون أن تُلحظ، ولا أبطأ */
export const PRINT_JOBS_INTERVAL_MS = 20000;

export type InvoicePrintMode = 'off' | 'unattended' | 'manual';

/**
 * «فاتورة عميل — نسخة داخلية» على صندوقها — ما دامت نقطةُ البيع مفتوحة.
 *
 * - **`unattended`** — تطبيقُ أبعاد وطابعةُ شبكةٍ عُيّنت لفواتير العملاء:
 *   كلَّ نبضةٍ يحجز ورقةً ويطبعها مباشرةً ويقول ما جرى. ورقةٌ واحدةٌ في كلّ
 *   مرّة، ولا نبضةَ والصفحةُ مخفيّة.
 * - **`manual`** — المتصفّحُ (أو التطبيقُ بطابعةٍ ليست على الشبكة): لا طباعةَ
 *   صامتة. يُعدّ ما ينتظر ويُرى، وزرٌّ يطبعه بضغطة (`printNext`). وفتحُ الحوار
 *   ليس ورقًا: ما فُتح حوارُه يُسأل عنه «خرجت؟» (`awaiting` و`answer`) —
 *   ولو أُعيد تحميلُ الصفحة، فالسؤالُ من الخادم لا من ذاكرة الصفحة.
 * - **`off`** — لا طابعةَ معيَّنة هنا: لا سؤالَ أصلًا.
 *
 * والفشلُ يُقال ولا يُعاد وحده إلّا ما قالت الطابعةُ إنّه لم يبلغها منه
 * شيء — والخادمُ يقرّر (`PrintJobs::acknowledge`).
 */
export default function useInvoicePrintJobs(onFailure?: (outcome: PrintOutcome) => void) {
    const { context } = usePage<PageProps>().props;
    const tenant = useTenantLink();
    const printer = invoicePrinter(context?.peripherals);
    const mode: InvoicePrintMode = !printer ? 'off' : nativePrintingAvailable(printer) ? 'unattended' : 'manual';

    const [waiting, setWaiting] = useState(0);
    const [awaiting, setAwaiting] = useState<AwaitingJob[]>([]);
    const [busy, setBusy] = useState(false);
    const working = useRef(false);
    const failure = useRef(onFailure);
    failure.current = onFailure;

    /** ما ينتظر هذا الصندوق، وما ينتظر تأكيدَ خروجه — من الخادم */
    const refresh = useCallback(async () => {
        const state = await queueState(route('pos.printJobs.index'));
        setWaiting(state.waiting);
        setAwaiting(state.awaiting);
    }, []);

    /** يطبع ورقةً حُجزت — بالطريق الذي يناسب هذا الجهاز — ويقول ما جرى */
    const print = useCallback(
        async (job: ClaimedJob, sheet: Window | null = null): Promise<PrintOutcome> => {
            const url = tenant(job.url);
            const invoice = job.number ?? String(job.id);
            let outcome: PrintOutcome;

            if (sheet) {
                // نافذةٌ فُتحت في الضغطة نفسِها — والمتصفّحُ لا يقول أطَبع أم لا
                sheet.location.replace(url);
                outcome = { ok: true, via: 'browser' };
            } else {
                outcome = await printReceipt({ invoice, url, printer, inPage: !insideAbaadApp() });
            }

            await acknowledgeJob(route('pos.printJobs.ack', job.id), outcome);
            if (!outcome.ok) failure.current?.(outcome);

            return outcome;
        },
        [tenant, printer],
    );

    // النبضة — تحجز وتطبع في التطبيق، وتعدّ ما ينتظر في المتصفّح
    useEffect(() => {
        if (mode === 'off') return;

        const tick = async () => {
            if (document.hidden || working.current) return;

            if (mode === 'manual') {
                await refresh();
                return;
            }

            working.current = true;
            try {
                const job = await claimJob(route('pos.printJobs.claim'));
                if (job) await print(job);
            } finally {
                working.current = false;
            }
        };

        void tick();
        const id = window.setInterval(() => void tick(), PRINT_JOBS_INTERVAL_MS);

        return () => window.clearInterval(id);
    }, [mode, print, refresh]);

    /**
     * يطبع التاليةَ بضغطة — للمتصفّح وما لا يطبع وحده.
     *
     * وفي Chrome وEdge يُطلب حوارُ الطباعة في الصفحة (`page-print`). وحيث لا
     * يُطبع من الصفحة تُفتح نافذةٌ **في الضغطة نفسِها** ثمّ يُوجَّه إليها
     * الملفّ — فلا يبتلعها مانعُ النوافذ بعد انتظار الحجز.
     */
    const printNext = useCallback(async () => {
        if (working.current) return;
        working.current = true;
        setBusy(true);

        const sheet = !insideAbaadApp() && pagePrintMode() !== 'frame' ? window.open('', '_blank') : null;

        try {
            const job = await claimJob(route('pos.printJobs.claim'));
            if (!job) {
                sheet?.close();
                return;
            }
            await print(job, sheet);
        } finally {
            working.current = false;
            setBusy(false);
            await refresh();
        }
    }, [print, refresh]);

    /**
     * «خرجت» أو «لم تخرج» — عن ورقةٍ فُتح حوارُها هنا.
     *
     * و«لم تخرج» لا تطبع شيئًا: تُعيدها إلى طابور هذا الصندوق، فتُعدّ فيما
     * ينتظر وتُطبع بضغطة «طباعة» أخرى.
     */
    const answer = useCallback(
        async (id: number, printed: boolean) => {
            await confirmJob(route('pos.printJobs.confirm', id), printed);
            await refresh();
        },
        [refresh],
    );

    return { mode, waiting, awaiting, busy, printNext, answer };
}

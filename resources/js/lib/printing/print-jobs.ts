import { csrfHeaders } from '@/lib/csrf';
import { mayHavePrinted, PRINTER } from './printer-service';
import type { PrintOutcome, PrinterLike } from './types';

/**
 * طابورُ الطباعة من جهة الصندوق — يسأل ورقتَه ويقول ما جرى.
 *
 * ═══ لمَ يسأل الصندوقُ ولا يُرسَل إليه ═══
 *
 * فاتورةُ العميل تُسدَّد عبر Paymob، والإشعارُ يصل الخادم — والخادمُ لا يبلغ
 * طابعةً على شبكة المحلّ. فيكتب الورقةَ مهمّةً في طابور (`PrintJobs`)، والصندوقُ
 * المعيَّن يسأل عنها ما دامت صفحتُه مفتوحة.
 *
 * ═══ وما لا يُدّعى ═══
 *
 * - **في تطبيق أبعاد بطابعة شبكة**: يحجز ويطبع بلا ضغطة، ما دامت نقطةُ البيع
 *   مفتوحةً أمامه. والتطبيقُ في الخلفيّة أو مغلقًا لا يسأل — والورقةُ تنتظر.
 * - **في المتصفّح**: لا طباعةَ صامتة. يُرى تنبيهٌ وزرٌّ يطبع بضغطة. وفتحُ
 *   الحوار ليس ورقًا: يُسأل الكاشيرُ بعده «خرجت؟» — ولا يُقال «طُبعت» عنه.
 *
 * والحجزُ والردُّ بجلسة الصفحة وبصمة الصندوق (كوكي `pos_device`) — لا
 * برقمٍ يُرسل.
 */

export interface ClaimedJob {
    id: number;
    kind: string;
    number: string | null;
    url: string;
}

const json = { Accept: 'application/json', 'Content-Type': 'application/json' };

/** الطابعةُ التي تستلم فواتيرَ العملاء على هذا الصندوق — إن عُيّنت */
export function invoicePrinter(peripherals?: PrinterLike[] | null): PrinterLike | null {
    return peripherals?.find((p) => p.type === PRINTER && p.autoPrintCustomerInvoices) ?? null;
}

/** ورقةٌ فتح هذا الصندوقُ حوارَها ولم يُقل أخرجت */
export interface AwaitingJob {
    id: number;
    number: string | null;
}

export interface QueueState {
    waiting: number;
    awaiting: AwaitingJob[];
}

const EMPTY: QueueState = { waiting: 0, awaiting: [] };

/** كم ورقةً تنتظر هذا الصندوق، وما ينتظر تأكيدَ خروجه — ولا يحجز شيئًا */
export async function queueState(url: string): Promise<QueueState> {
    try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) return EMPTY;
        const data = (await res.json()) as { waiting?: number; awaiting?: AwaitingJob[] };

        return { waiting: Number(data.waiting ?? 0), awaiting: Array.isArray(data.awaiting) ? data.awaiting : [] };
    } catch {
        return EMPTY;
    }
}

/** يحجز الورقةَ التالية — أو لا شيء. والفشلُ لا شيء: الورقةُ تبقى في الطابور */
export async function claimJob(url: string): Promise<ClaimedJob | null> {
    try {
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { ...json, ...csrfHeaders() } });
        if (!res.ok) return null;
        const data = (await res.json()) as { job?: ClaimedJob | null };

        return data.job ?? null;
    } catch {
        return null;
    }
}

/**
 * يقول ما جرى للورقة — بلا وعدٍ بما لا يُعرف.
 *
 * و`may_have_printed` من الطابعة نفسِها: ما بلغها بعضُه لا يُعاد وحده.
 * وسقوطُ هذا الطلب لا يُعيد الطباعة: الحجزُ ينقضي في الخادم فيُرى «فشل».
 */
export async function acknowledgeJob(url: string, outcome: PrintOutcome): Promise<void> {
    const body = outcome.ok
        ? { ok: true, via: outcome.via }
        : { ok: false, via: outcome.via, error: outcome.code, may_have_printed: mayHavePrinted(outcome) };

    try {
        await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { ...json, ...csrfHeaders() }, body: JSON.stringify(body) });
    } catch {
        // الحجزُ ينقضي في الخادم — ولا يُعاد وحده (`PrintJobs::expireClaims`)
    }
}

/**
 * «خرجت» أو «لم تخرج» — يقولها الكاشيرُ عن ورقةٍ فتح صندوقُه حوارَها.
 *
 * و«لم تخرج» تُعيدها إلى طابور هذا الصندوق — تُطبع بضغطةٍ أخرى، لا وحدها.
 */
export async function confirmJob(url: string, printed: boolean): Promise<boolean> {
    try {
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { ...json, ...csrfHeaders() }, body: JSON.stringify({ printed }) });
        if (!res.ok) return false;
        const data = (await res.json()) as { recorded?: boolean };

        return data.recorded === true;
    } catch {
        return false;
    }
}

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
 * - **في المتصفّح**: لا طباعةَ صامتة. يُرى تنبيهٌ وزرٌّ يطبع بضغطة.
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

/** كم ورقةً تنتظر هذا الصندوق — ولا يحجز شيئًا */
export async function waitingJobs(url: string): Promise<number> {
    try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) return 0;
        const data = (await res.json()) as { waiting?: number };

        return Number(data.waiting ?? 0);
    } catch {
        return 0;
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

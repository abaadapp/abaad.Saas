import { useEffect } from 'react';
import { router } from '@inertiajs/react';

/** صفُّ مستندٍ من مستندات المنصّة — في القائمة وفي رأس صفحته (`DocumentController::row`) */
export interface DocumentRow {
    id: number;
    url: string;
    pdf_url: string;
    number: string;
    title: string;
    service: string | null;
    client_name: string | null;
    client_biz: string | null;
    business: { id: number; name: string | null } | null;
    plan_name: string | null;
    cycle_label: string | null;
    /** سعرُ دورة الباقة أو مبلغُ الخدمة — نصًّا بثلاث خانات */
    value: string | null;
    value_unit: string | null;
    renewal: boolean;
    /** آخرُ ما فعله المدير بالرابط — «نُسخ» أو «فُتح واتساب»، لا دليلَ وصول */
    shared: string | null;
    shared_at: string | null;
    state: 'sent' | 'signed' | 'cancelled' | 'expired';
    state_label: string;
    created_at: string;
    expires_at: string;
    signed_at: string;
}

/** لونُ كلّ حالة — المنتهي أحمر: رابطٌ أُرسل ولم يُوقَّع ولم يعد يعمل */
export const STATE_VARIANT: Record<DocumentRow['state'], 'warning' | 'success' | 'neutral' | 'danger'> = {
    sent: 'warning',
    signed: 'success',
    cancelled: 'neutral',
    expired: 'danger',
};

/** كلَّ كم يسأل الخادمَ ما ينتظر التوقيع — خفيفٌ، ويهدأ حين يُخفى التبويب */
export const POLL_MS = 10_000;

/**
 * يسأل الخادمَ عن خاصّيّاتٍ بعينها كلَّ حين ما دام `active` — فيظهر توقيعٌ وقع
 * والشاشةُ مفتوحة، دون إعادة تحميلٍ ولا دخولٍ جديد، أيًّا كانت الطريقُ التي وصل
 * بها الرابطُ إلى العميل. وما يُطلب هو ما يتغيّر وحده (`only`)، والتبويبُ المخفيّ
 * لا يسأل — كما يفعل الجرس (`Topbar`).
 */
export function useLiveReload(only: string[], active = true, every = POLL_MS): void {
    const keys = only.join(',');
    useEffect(() => {
        if (!active) return;
        const id = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: keys.split(',') });
        }, every);
        return () => window.clearInterval(id);
    }, [keys, active, every]);
}

/**
 * نهايةُ المدّة من بدايتها — بلا فيضٍ إلى الشهر التالي، كما يحسبها الخادم
 * (`Billing::extend`): شهرٌ من ٣١ يناير ينتهي آخرَ فبراير.
 */
export function addMonths(iso: string, months: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    if (!y || !m || !d) return '';
    const target = new Date(Date.UTC(y, m - 1 + months, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(d, last));
    return target.toISOString().slice(0, 10);
}

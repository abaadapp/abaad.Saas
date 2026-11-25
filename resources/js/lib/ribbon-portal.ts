import { csrfHeaders } from '@/lib/csrf';

/** حالُ دخول العميل إلى بوابة RIBBON */
export interface PortalCustomerState {
    /** بابُ الإيقاف والسماح من الخادم — لا يُذكر في جدول المسارات الذي يصل كلَّ تاجر. و`null` لمن لا يدير */
    manage: string | null;
    /** الرابطُ الواحد لجميع العملاء */
    url: string;
    /** رقمُه المسجَّل كما كُتب — وبلا رقمٍ لا بوابةَ له */
    phone: string | null;
    /** أُوقفت صفحتُه: لا تُفتح برقمه */
    stopped: boolean;
    since: string | null;
    used: string | null;
    /** كم جهازًا داخلٌ الآن */
    devices: number;
}

/** ما يردّه الصندوقُ بعد بيعةِ عميلٍ مسجَّل */
export interface SalePortal {
    /** الرابطُ الواحد لجميع العملاء */
    url: string;
}

/** يسأل الخادمَ ويردّ ما ردّ — أو رسالةَ الخطأ مكتوبةً للموظّف */
export async function askPortal<T>(url: string, method: 'POST' | 'DELETE', failed: string): Promise<{ data: T | null; error: string | null }> {
    try {
        const res = await fetch(url, { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', ...csrfHeaders() } });
        const answer = await res.json().catch(() => ({}));

        return res.ok ? { data: answer as T, error: null } : { data: null, error: (answer as { message?: string }).message || failed };
    } catch {
        return { data: null, error: failed };
    }
}

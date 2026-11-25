import { csrfHeaders } from '@/lib/csrf';

/** حالُ دخول العميل إلى بوابة RIBBON */
export interface PortalCustomerState {
    /** عناوينُها من الخادم — لا تُذكر في جدول المسارات الذي يصل كلَّ تاجر. و`null` لمن لا يدير */
    urls: { code: string; revoke: string | null };
    /** الرابطُ الواحد لجميع العملاء */
    url: string;
    hasPhone: boolean;
    since: string | null;
    used: string | null;
    devices: number;
}

/** يسأل الخادمَ ويردّ ما ردّ — أو رسالةَ الخطأ مكتوبةً للموظّف */
export async function askPortal<T>(url: string, method: 'POST' | 'DELETE', failed: string): Promise<{ data: T | null; error: string | null }> {
    try {
        const res = await fetch(url, { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', ...csrfHeaders() } });
        const body = await res.json().catch(() => ({}));

        return res.ok ? { data: body as T, error: null } : { data: null, error: (body as { message?: string }).message || failed };
    } catch {
        return { data: null, error: failed };
    }
}

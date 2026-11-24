import { csrfHeaders } from '@/lib/csrf';

/** حالُ رابط العميل إلى بوابة RIBBON — بلا الرابط نفسه: يُطلب حين يُضغط الزرّ */
export interface PortalLinkState {
    /** عناوينُها من الخادم — لا تُذكر في جدول المسارات الذي يصل كلَّ تاجر */
    urls: { share: string; reissue: string; revoke: string };
    hasPhone: boolean;
    issued: string | null;
    used: string | null;
}

/** رابطٌ صدر ليُشارَك — ونصُّه ورابطُ واتساب إلى رقم العميل المسجَّل */
export interface SharedLink {
    url: string;
    message: string;
    whatsapp: string;
    state: PortalLinkState;
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

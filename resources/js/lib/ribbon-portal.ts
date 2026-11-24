import { csrfHeaders } from '@/lib/csrf';

/** حالُ رابط العميل إلى بوابة RIBBON — بلا الرابط نفسه: يُطلب حين يُضغط الزرّ */
export interface PortalLinkState {
    /** عناوينُها من الخادم — لا تُذكر في جدول المسارات الذي يصل كلَّ تاجر. و`null` لمن يشارك ولا يدير */
    urls: { share: string; reissue: string | null; revoke: string | null };
    hasPhone: boolean;
    issued: string | null;
    used: string | null;
}

/** رابطُ العميل لينسخه الموظّف، ورمزُه ليمسحه العميل من الشاشة */
export interface SharedLink {
    url: string;
    qr: string;
    state: PortalLinkState;
}

/** وبعد البيع: الرابطُ وحده */
export interface SaleLink {
    url: string;
}

/** نصُّ الرابط وعدًا للحافظة — ويُرفض إن لم يُردّ رابط */
export const linkOf = (asked: Promise<{ data: { url: string } | null }>): Promise<string> =>
    asked.then(({ data }) => data?.url ?? Promise.reject(new Error('no link')));

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

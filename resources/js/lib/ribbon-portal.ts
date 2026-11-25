import { csrfHeaders } from '@/lib/csrf';

/** جهازٌ كُتب فيه رقمُ العميل وينتظر الاعتماد — ورقمُه يُقرأ من شاشته لا من هنا */
export interface WaitingDevice {
    device: string;
    /** حتّى متى يصلح رقمُه */
    until: string;
}

/** جهازٌ اعتُمد: من اعتمده وكيف ومتى — وما دخل قبل الاعتماد بلا اسم */
export interface ApprovedDevice {
    device: string;
    by: string | null;
    how: 'shop' | 'call' | null;
    at: string;
}

export interface WaitingCustomer {
    id: number;
    name: string;
    phone: string;
    /** الرقمُ نفسُه لعميلٍ آخر — يختار الموظّفُ صاحبَه */
    twin: boolean;
    devices: WaitingDevice[];
    url: string;
}

/** حالُ دخول العميل إلى بوابة RIBBON */
export interface PortalCustomerState {
    /** عناوينُها من الخادم — لا تُذكر في جدول المسارات الذي يصل كلَّ تاجر. و`null` لمن لا يدير */
    urls: { device: string; revoke: string | null };
    /** الرابطُ الواحد لجميع العملاء */
    url: string;
    /** رقمُه المسجَّل كما كُتب — وبلا رقمٍ لا بوابةَ له */
    phone: string | null;
    waiting: WaitingDevice[];
    since: string | null;
    used: string | null;
    devices: ApprovedDevice[];
}

/** ما يردّه الصندوقُ بعد بيعةِ عميلٍ مسجَّل */
export interface SalePortal {
    /** الرابطُ الواحد لجميع العملاء */
    url: string;
    /** بابُ اعتماد جهاز عميل هذه البيعة */
    device: string;
    /** رقمُه المسجَّل */
    phone: string;
}

/** كم خانةً في رقم الجهاز (`RibbonPortal\Devices::DIGITS`) */
export const DEVICE_DIGITS = 6;

/** ما كُتب بأرقامٍ عربيّةٍ أو فارسيّة يُقرأ أرقامًا، ولا شيءَ سواها */
export function deviceDigits(raw: string): string {
    return raw
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 1632))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 1776))
        .replace(/\D+/g, '')
        .slice(0, DEVICE_DIGITS);
}

/** يسأل الخادمَ ويردّ ما ردّ — أو رسالةَ الخطأ مكتوبةً للموظّف */
export async function askPortal<T>(url: string, method: 'GET' | 'POST' | 'DELETE', failed: string, body?: object): Promise<{ data: T | null; error: string | null }> {
    try {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...csrfHeaders() },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const answer = await res.json().catch(() => ({}));

        return res.ok ? { data: answer as T, error: null } : { data: null, error: (answer as { message?: string }).message || failed };
    } catch {
        return { data: null, error: failed };
    }
}

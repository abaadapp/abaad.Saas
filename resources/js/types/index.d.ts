import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    avatar: string | null;
    branch: string | null;
    businessId: number | null;
}

export interface Branch {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
}

export interface Currency {
    code: string;
    name: string;
    symbol: string;
    rate: number;
    is_base: boolean;
    active: boolean;
    decimals?: number;
}

export interface Notification {
    id: string | number;
    title: string;
    body?: string;
    icon?: string;
    color?: string;
    time?: string;
    url?: string;
}

export interface Toast {
    msg: string;
    type?: 'success' | 'warning' | 'danger' | 'info';
}

/** البيانات التي يشاركها HandleInertiaRequests مع كل صفحة */
export interface SharedProps {
    auth: {
        user: User;
        abilities: string[];
    } | null;
    context: {
        businessName: string;
        branchId: number | null;
        branchName: string;
        branches: Branch[];
        currency: Currency;
        currencies: Currency[];
    } | null;
    notifications: {
        items: Notification[];
        count: number;
    } | null;
    flash: {
        toast: Toast | null;
        status: string | null;
    };
    locale: string;
    dir: 'rtl' | 'ltr';
    /** قاموس عربي→إنجليزي؛ null في العربية لأن المفتاح هو النص */
    translations: Record<string, string> | null;
}

export type PageProps<T = Record<string, unknown>> = InertiaPageProps & SharedProps & T;

declare global {
    // eslint-disable-next-line no-var
    var route: typeof import('ziggy-js').default;
}

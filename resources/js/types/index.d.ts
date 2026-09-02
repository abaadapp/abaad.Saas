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
    /** الرمز قبل المبلغ لا بعده — إعداد «موضع الرمز» */
    before?: boolean;
}

export interface Notification {
    /** مفتاح ثابت لكل مصدر — يُستخدم في الحذف حتى يبقى مخفيًا بعد إعادة التحميل */
    key: string;
    text: string;
    icon?: string;
    color?: string;
    time?: string;
    url?: string;
}

export interface Toast {
    msg: string;
    type?: 'success' | 'warning' | 'danger' | 'info';
    /**
     * زرّ «تراجع» داخل الإشعار — يبنيه الخادم بعد كل حذفٍ ناعم.
     *
     * `url` مسارُ الاستعادة جاهزًا: بناؤه في الواجهة يعني تكرار معرفة أي
     * قسمٍ يردّ أيّ نوع في مكانين.
     */
    undo?: { url: string; label?: string };
}

/** البيانات التي يشاركها HandleInertiaRequests مع كل صفحة */
export interface SharedProps {
    auth: {
        user: User;
        abilities: string[];
        /**
         * ما تفتحه باقة المتجر — سؤالٌ آخر غير `abilities`.
         *
         * ذاك عن صلاحية الموظّف، وهذا عن المشترَك. والاثنان يقعان على البند
         * الواحد: مالكٌ يملك كلّ الأقسام في متجرٍ على الباقة الأساسية.
         */
        planFeatures: Record<string, boolean>;
        /** إلى أين يدخل اللوحة — أوّل قسمٍ يملكه، أو null فلا يُعرض الزرّ */
        panelUrl?: string | null;
        /** هل يدخل هذا المستخدم لوحة النشاط؟ الكاشير لا يدخلها */
        entersPanel: boolean;
        /** جلسة انتحالٍ من لوحة المنصة — تُعلَن في كل صفحة */
        impersonating: boolean;
    } | null;
    context: {
        businessName: string;
        /** رابط موقع التاجر المُطبَّع — null حين لم يُضبط بعد */
        website: string | null;
        /** صفحة المتجر التي يبنيها النظام — null حين لم تُنشر */
        storeUrl?: string | null;
        branchId: number | null;
        branchName: string;
        /** اسم صندوق نقطة البيع المفعَّل على هذا الجهاز */
        deviceName?: string | null;
        /** ملحقات هذا الصندوق النشطة وحدها — طابعة، ماسح، درج… */
        peripherals?: {
            type: string;
            name: string;
            paperWidth: number | null;
            autoPrint: boolean;
        }[];
        branches: Branch[];
        currency: Currency;
        currencies: Currency[];
        /** اشتراك المتجر — null لمن لا مدّة محدَّدة له */
        subscription: { endsAt: string; daysLeft: number; graceLeft: number | null } | null;
    } | null;
    /** الموظف الواقف على الصندوق — غير الحساب المسجَّل دخوله */
    posCashier: { id: number; name: string } | null;
    notifications: {
        items: Notification[];
        count: number;
    } | null;
    flash: {
        toast: Toast | null;
        status: string | null;
    };
    /** رمز CSRF الخام — يتجدّد مع كل استجابة، بخلاف وسم <meta> */
    csrf: string;
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

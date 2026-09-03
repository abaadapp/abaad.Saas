/**
 * عقد المستند — ما يردّه `GET /site/{host}` من أبعاد.
 *
 * هذا الملف صورةٌ مطابقة لما يبنيه `App\Support\Website\Publisher::snapshot`
 * ثمّ يُكمله `Preview::resolve`. وهو جزءٌ من طبقة الرسم المشتركة: النسخة
 * نفسها تعيش في أبعاد وفي العارض، فلا يفترق فهمُ الطرفين للمستند.
 *
 * @see abaad.Saas/app/Support/Website/Publisher.php
 * @see abaad.Saas/app/Support/Website/Preview.php
 */

/** رموز التصميم — ستّةٌ يختارها التاجر وخمسةٌ يشتقّها النظام */
export interface Tokens {
    primary: string;
    background: string;
    text: string;
    font: string;
    radius: string;
    button: string;
    on_primary: string;
    surface: string;
    border: string;
    muted: string;
    radius_px: number;
}

export interface Currency {
    code: string;
    symbol: string;
    rate: number;
    is_base: boolean;
    decimals?: number;
    /** الرمز قبل المبلغ لا بعده */
    before?: boolean;
}

export interface DocProduct {
    id: number;
    name: string;
    excerpt: string;
    price: number;
    /** السعر قبل الخصم — أو null إن لا خصم */
    was: number | null;
    final: number;
    image: string | null;
    category_id: number | null;
}

export interface DocCategory {
    id: number;
    name: string;
    icon: string;
    color: string;
}

export interface DocReview {
    author: string;
    rating: number;
    comment: string;
}

export type SectionItem = DocProduct | DocCategory | DocReview;

export interface DocSocial {
    network: string;
    value: string;
    url: string;
    label: string;
}

/** هويّة النشاط — تُقرأ عند العرض لا عند النشر، فتبقى حيّة */
export interface DocBrand {
    name: string;
    logo: string | null;
    tagline: string;
    phone: string;
    email: string;
    address: string;
    whatsapp: string;
    social: DocSocial[];
    payments: string[];
}

export interface DocSection {
    type: string;
    visible: boolean;
    source: string | null;
    data: Record<string, unknown>;
    /** ما جلبه الخادم لهذا القسم — منتجات أو تصنيفات أو آراء */
    items?: SectionItem[] | null;
    slot?: string;
}

export interface DocPage {
    key: string;
    title: string;
    slug: string;
    status: string;
    is_home: boolean;
    removable: boolean;
    seo: Record<string, string> | null;
    sections: DocSection[];
}

export interface DocSeo {
    title?: string;
    description?: string;
    image?: string;
    index?: boolean;
}

export interface SiteDocument {
    version: number;
    name: string;
    goal: string;
    template: string;
    theme: Record<string, string>;
    tokens: Tokens;
    seo: DocSeo | null;
    maintenance: boolean;
    maintenance_message: string | null;
    globals: DocSection[];
    pages: DocPage[];
    /* ما يُلحق عند القراءة — قد يغيب في مستندٍ قديم، فكلّه اختياريّ */
    brand?: DocBrand;
    currency?: Currency;
    locale?: string;
    dir?: 'rtl' | 'ltr';
    data?: {
        products?: DocProduct[];
        categories?: DocCategory[];
        reviews?: DocReview[];
        best?: DocProduct[];
    };
}

/**
 * وضع الرسم.
 *
 * `edit` هي المعاينة داخل أبعاد: القسم الفارغ يُقال عنه فارغًا ليعرف التاجر
 * ما ينقصه، والروابط لا تنقل لأنّ المعاينة ليست تصفّحًا.
 *
 * `live` هو موقع الزائر: القسم الفارغ لا يُرسم أصلًا — زبونٌ لا يعنيه أنّ
 * التاجر لم يرفع صورًا بعد — والروابط روابط.
 */
export type Mode = 'edit' | 'live';

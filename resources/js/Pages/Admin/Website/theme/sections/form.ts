import type { InertiaFormProps } from '@inertiajs/react';

/**
 * ما تحفظه صفحةُ إعدادات المتجر — نموذجٌ واحدٌ لأقسامها كلِّها.
 *
 * وكانت أربعَ شاشاتٍ تكتب في هذا الباب نفسِه (`marketing.store.save`)،
 * فالتفريقُ بينها كان تفريقًا في الشاشة لا في الحفظ. وجمعُها هنا يجعل
 * زرَّ الحفظ واحدًا كما هو الحفظُ في الخادم واحد.
 */
export interface ThemeSettingsData {
    /* العنوان والنشر */
    site_slug: string;
    store_on: boolean;

    /* الصفحات */
    store_pages: string;
    store_about_image: string;

    /* الدفع والاستلام */
    store_allow_orders: boolean;
    store_pay_cod: boolean;
    store_pay_transfer: boolean;
    store_bank: string;
    store_delivery_fee: string;
    store_free_delivery_over: string;
    store_delivery_areas: string;
    store_delivery_slots: string;
    store_delivery_note: string;
    store_max_days: string;
    store_fulfil: string;

    /* حقول إتمام الطلب */
    store_field_area: string;
    store_field_address: string;
    store_field_date: string;
    store_field_slot: string;
    store_field_recipient: string;
    store_field_promo: string;
    store_image_note: string;

    /* كرت الهدية */
    store_gift_card: boolean;
    store_gift_card_price: string;

    /* الظهور في البحث */
    store_seo_title: string;
    store_seo_desc: string;
    store_seo_index: boolean;
}

export type ThemeForm = InertiaFormProps<ThemeSettingsData>;

/** ما يُرسَل حين تُطفأ الصفحاتُ الاختيارية كلُّها — انظر `StoreNav::NONE` */
export const NO_PAGES = 'none';

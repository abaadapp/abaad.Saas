import { vi } from 'vitest';

import type { ThemeForm, ThemeSettingsData } from '@/Pages/Admin/Website/theme/sections/form';

/** متجرُ الفحص — الواجهةُ الخاصّة كما يراها صاحبها */
export const THEME_SITE = {
    name: 'RIBBON',
    published: true,
    url: 'https://ribbon.abaadapp.om',
    slug: 'ribbon',
    host: 'abaadapp.om',
};

const BLANK: ThemeSettingsData = {
    site_slug: 'ribbon',
    store_on: true,

    store_pages: 'about,contact',
    store_about_image: '',

    store_allow_orders: true,
    store_pay_cod: true,
    store_pay_transfer: false,
    store_bank: '',
    store_delivery_fee: '',
    store_free_delivery_over: '',
    store_delivery_areas: '',
    store_delivery_slots: '',
    store_delivery_note: '',
    store_max_days: '',
    store_fulfil: 'delivery',

    store_field_area: 'optional',
    store_field_address: 'required',
    store_field_date: 'required',
    store_field_slot: 'optional',
    store_field_recipient: 'optional',
    store_field_promo: 'optional',
    store_image_note: '',

    store_gift_card: false,
    store_gift_card_price: '',

    store_seo_title: '',
    store_seo_desc: '',
    store_seo_index: true,
};

/**
 * نموذجٌ يقوم مقام نموذج Inertia — الأقسامُ تأخذه ولا تقرأ الصفحة.
 *
 * وكانت الأقسامُ شاشاتٍ تقرأ `usePage` بنفسها، فكان كلُّ اختبارٍ يحقن صفحةً
 * كاملة. وصارت أجزاءً تُعطى نموذجَها — فتُفحص وحدَها كما تُفحص أيُّ دالّة.
 */
export function themeForm(over: Partial<ThemeSettingsData> = {}): ThemeForm {
    const data = { ...BLANK, ...over };

    return {
        data,
        errors: {},
        processing: false,
        isDirty: false,
        setData: vi.fn(),
        reset: vi.fn(),
        post: vi.fn(),
    } as unknown as ThemeForm;
}

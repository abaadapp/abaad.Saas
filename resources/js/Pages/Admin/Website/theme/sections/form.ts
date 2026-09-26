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

/* ═════════════════ البذرةُ والحفظُ الجزئيّ ═════════════════ */

/**
 * ما يرسله الخادمُ لكلّ شاشةٍ من شاشات الواجهة الخاصّة الستّ.
 *
 * ويُرسَل كاملًا إلى كلٍّ منها — لا مقسومًا. وقراءتُه صفٌّ واحدٌ من
 * `MarketingSettings::group`، فقسمتُه على ستّ شاشاتٍ تُكلّف ستَّ صيغٍ
 * تفترق يومَ يُضاف مفتاح، ولا توفّر استعلامًا.
 */
export interface ThemeSeed {
    settings: Record<string, string>;
    fieldStates: Record<string, string>;
    fulfilments: string[];
    pages: { allowed: string };
    seo: { title: string; desc: string; index: boolean };
    storeOn: boolean;
    slug: string | null;
}

/** حالُ المتجر الآن، بالشكل الذي يقرؤه النموذج */
export function seed(s: ThemeSeed): ThemeSettingsData {
    const { settings: v, fieldStates: f } = s;

    return {
        site_slug: s.slug ?? '',
        store_on: s.storeOn,

        store_pages: s.pages.allowed,
        store_about_image: v.store_about_image ?? '',

        store_allow_orders: (v.store_allow_orders ?? '1') === '1',
        store_pay_cod: (v.store_pay_cod ?? '1') === '1',
        store_pay_transfer: (v.store_pay_transfer ?? '0') === '1',
        store_bank: v.store_bank ?? '',
        store_delivery_fee: v.store_delivery_fee ?? '',
        store_free_delivery_over: v.store_free_delivery_over ?? '',
        store_delivery_areas: v.store_delivery_areas ?? '',
        store_delivery_slots: v.store_delivery_slots ?? '',
        store_delivery_note: v.store_delivery_note ?? '',
        store_max_days: v.store_max_days ?? '',
        store_fulfil: s.fulfilments.join(','),

        /*
         * وحقولُ الطلب تبدأ بما يعمل به متجره الآن لا بفراغ.
         *
         * `CheckoutFields` تقرأ الفراغَ «ما كان» — فشاشةٌ تعرض فراغًا تقول
         * لصاحبها إنّ حقلًا مطفأٌ وهو يُسأل عنه في متجره.
         */
        store_field_area: f.area ?? 'optional',
        store_field_address: f.address ?? 'required',
        store_field_date: f.date ?? 'required',
        store_field_slot: f.slot ?? 'optional',
        store_field_recipient: f.recipient ?? 'optional',
        store_field_promo: f.promo ?? 'optional',
        store_image_note: v.store_image_note ?? '',

        store_gift_card: (v.store_gift_card ?? '0') === '1',
        store_gift_card_price: v.store_gift_card_price ?? '',

        store_seo_title: s.seo.title,
        store_seo_desc: s.seo.desc,
        store_seo_index: s.seo.index,
    };
}

/**
 * مفاتيحُ كلّ شاشة — وما لم يُذكر فيها لا يُرسَل.
 *
 * ═══ ولمَ الإرسالُ الجزئيُّ آمن ═══
 *
 * `MarketingController::saveStore` مبنيٌّ عليه منذ كُتب: كلُّ مفتاحٍ حسّاسٍ
 * يُسأل عنه بـ`$request->exists()` أو `array_key_exists`، و`validated()`
 * تُسقط الغائبَ ولا تكتبه. فالعنوانُ لا يُمحى بحفظٍ لا يحمله، ومفتاحُ
 * النشر يبقى على حاله، والمنطقيّاتُ لا تنقلب إلى صفرٍ لأنّها لم تُذكر.
 *
 * ولولا ذلك لَكانت كلُّ شاشةٍ تحفظ ما فيها وتمحو ما في أخواتها.
 */
export const SCREEN_KEYS = {
    domain: ['site_slug', 'store_on'],
    pages: ['store_pages', 'store_about_image'],
    store: [
        'store_allow_orders', 'store_pay_cod', 'store_pay_transfer', 'store_bank',
        'store_delivery_fee', 'store_free_delivery_over', 'store_delivery_areas',
        'store_delivery_slots', 'store_delivery_note', 'store_max_days', 'store_fulfil',
        'store_field_area', 'store_field_address', 'store_field_date', 'store_field_slot',
        'store_field_recipient', 'store_field_promo', 'store_image_note',
        'store_gift_card', 'store_gift_card_price',
    ],
    seo: ['store_seo_title', 'store_seo_desc', 'store_seo_index'],
} as const satisfies Record<string, readonly (keyof ThemeSettingsData)[]>;

/** ما تحمله هذه الشاشةُ وحدَها من النموذج */
export function only(
    data: ThemeSettingsData,
    keys: readonly (keyof ThemeSettingsData)[],
): Partial<ThemeSettingsData> {
    const out: Partial<ThemeSettingsData> = {};

    for (const key of keys) {
        // @ts-expect-error — المفتاحُ من النوع نفسِه، والنسخُ بالمفتاح لا يُضيّقه TS
        out[key] = data[key];
    }

    return out;
}

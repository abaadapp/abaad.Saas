/**
 * أنواع المستند — من طبقة الرسم المشتركة لا نسخةً عنها.
 *
 * كانت هنا نسخةٌ ثانية من العقد، وهي أوّل ما يفترق: يُضاف حقلٌ في أبعاد
 * فيُحدَّث نصفُ القراءات. فصار المصدر واحدًا (`renderer/types.ts`) يُنسخ من
 * العارض بأمر `sync-renderer`، ويبقى هنا ما يخصّ المحرّر وحده.
 */

export type {
    DocBrand,
    DocCategory,
    DocPage,
    DocProduct,
    DocReview,
    DocSection,
    DocSeo,
    DocSocial,
    SectionItem,
    SiteDocument,
    Tokens,
} from './renderer/types';

/** المعاينة على ثلاثة أجهزة — وهذا شأن المحرّر لا شأن الموقع */
export type Device = 'desktop' | 'tablet' | 'mobile';

/** عرضُ كلّ جهاز بالبكسل — والمعاينة تُرسم بعرضه ثمّ تُصغَّر لتُرى كاملة */
export const DEVICE_WIDTH: Record<Device, number> = {
    desktop: 1280,
    tablet: 834,
    mobile: 390,
};

/**
 * طبقة الرسم المشتركة — نقطةُ دخولٍ واحدة.
 *
 * هذا المجلّد كلُّه بلا تبعيّات: React وحدها. فهو ينسخ كما هو إلى أبعاد
 * ليكون معاينتَها، ويبقى هنا ليكون الموقع — نسخةٌ واحدة لا نسختان تفترقان
 * عند أوّل إصلاح. انظر `RENDERER.md`.
 */

export { Site, shows, findPage, globalSlot } from './Site';
export type { SiteProps } from './Site';
export { Block, REGISTRY, KNOWN_TYPES } from './blocks';
export type { BlockProps } from './blocks';
export { Header } from './Header';
export { Footer } from './Footer';
export { default as Catalog } from './Catalog';
export { ProductCard, gridOf } from './ProductCard';
export { ProductView } from './ProductView';
export { default as ProductGallery } from './ProductGallery';
export { cssVars, tokensOf, fontHref, fontHrefs, fontStack, layoutAttrs, FONT_STACK, FALLBACK_TOKENS } from './tokens';
export { layoutOf, variant, LAYOUT_DEFAULTS, RATIO, WIDTH_PX } from './layout';
export type { LayoutTokens } from './layout';
export { money, decimalsFor, FALLBACK_CURRENCY } from './money';
export { whatsappUrl, orderUrl, waNumber, sells, showPrices, allowOrders } from './commerce';
export { videoEmbed, mapEmbed, youtubeId, vimeoId } from './embed';
export { hasContent, expired } from './content';
export { TextProvider, useText } from './i18n';
export { Band, Cta, Empty, Grid, Heading, Link, Media, Price, Stars, Tag } from './primitives';
export { str, bool, num, rows, filled } from './read';
export type * from './types';

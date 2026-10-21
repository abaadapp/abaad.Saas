/**
 * أين يُباع الصنفُ فعلًا بمفاتيحه الثلاثة — كما يحكم الخادم (`Product::sellsAtPos`
 * و`Product::sellsOnline`): القناةُ لا تبيع والمنتجُ موقوف.
 *
 * يقرؤها نموذجُ المنتج (سطرُ الخلاصة تحت المفاتيح) وقائمةُ المنتجات (وسمٌ تحت
 * الحالة) — قاعدةٌ واحدة لا اثنتان تفترقان. والغائبُ من المفتاحين يُقرأ «نعم»
 * كما يقرؤه الخادم لما سبق العمود.
 */
export type SalesChannels = 'both' | 'pos' | 'online' | 'none';

export function salesChannels(d: { active: boolean; pos_enabled?: boolean; published?: boolean }): SalesChannels {
    const pos = d.active && d.pos_enabled !== false;
    const online = d.active && d.published !== false;
    if (pos && online) return 'both';
    if (pos) return 'pos';
    if (online) return 'online';
    return 'none';
}

/** الخلاصةُ في النموذج */
export const CHANNEL_SUMMARY: Record<SalesChannels, string> = {
    both: 'يُباع في نقطة البيع والمتجر الإلكتروني.',
    pos: 'يُباع في نقطة البيع فقط — مخفي من المتجر الإلكتروني.',
    online: 'يُباع في المتجر الإلكتروني فقط — مخفي من نقطة البيع.',
    none: 'لا يُباع في أي قناة الآن.',
};

/** والوسمُ في القائمة — لا يُرسم للقناتين معًا، فذاك ما كان عليه كلُّ صنف */
export const CHANNEL_TAG: Record<Exclude<SalesChannels, 'both'>, string> = {
    pos: 'نقطة البيع فقط',
    online: 'المتجر الإلكتروني فقط',
    none: 'بلا قناة بيع',
};

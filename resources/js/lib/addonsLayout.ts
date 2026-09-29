/**
 * كيف تُعرض إضافاتُ المتجر في نقطة البيع — شريطًا أم قسمًا كاملًا.
 *
 * مصدرٌ واحد يقرؤه الصندوقُ وشاشةُ المنتج، كما يقرأ الخادمُ `PosAddonsLayout`.
 */
export type AddonsLayout = 'bar' | 'section';

/** ما وصل من الخادم — وما لا يُفهم «شريط»، فلا تنكسر شاشةٌ بقيمةٍ فاسدة */
export const addonsLayoutOf = (raw: unknown): AddonsLayout => (raw === 'section' ? 'section' : 'bar');

/**
 * ═══ أصنافُ الصندوق كما يرسلها الخادم — وكما يقرؤها الصندوق ═══
 *
 * الخادمُ يرسل للصندوق صفَّ الصنف نفسَه الذي يبنيه لكلّ واجهة، ناقصًا ما لا
 * يقرؤه (`Demo::forTill`):
 *   • `published` و`description_web_enabled` — للموقع.
 *   • `description_pos_enabled` — قرارُه في `pos_description` نفسِه (`null` حين
 *     لم يُفتح للصندوق).
 *   • `cat_label` — أقسامُ الصندوق من `posCategories`، وترشيحُه بـ`cat`.
 *   • `label` حين يساوي `name` حرفًا بحرف — فيُقرأ غائبُه `name` هنا، مرّةً
 *     واحدة عند دخول الصفحة، قبل أن يصل أيَّ مكوّن.
 *
 * فكلُّ ما يقرؤه الصندوق (السعرُ والضريبةُ والمخزونُ والمقاساتُ والإضافاتُ
 * وأهليّةُ البيع والاسمُ المعروض) يصله كما كان، قيمةً بقيمة.
 *
 * ويزيد عليه `sale_price`: سعرُ البيع بعد خصم الصنف كما يبيع به الخادم
 * (`Product::sellingPrice`) — للمخصوم وحده، وغيابُه يُقرأ `price` هنا.
 */
import type { Product } from '@/types/models';

/** ‏ما لا يقرؤه الصندوق — ولا يصله */
type NotForTill = 'published' | 'cat_label' | 'description_pos_enabled' | 'description_web_enabled';

/**
 * ‏الصنفُ كما يقرؤه الصندوق.
 *
 * `sale_price` ما يُباع به الصنفُ نفسُه (البطاقةُ والماسحُ ونافذةُ الإضافات)،
 * و`price` قبل الخصم — لا يُباع به. والمقاسُ بسعره، لا يُخصم.
 */
export type TillProduct = Omit<Product, NotForTill> & { sale_price: number };

/** ‏وكما يرسله الخادم: الاسمُ المعروض غائبٌ حين يساوي الاسم، وسعرُ البيع حين لا خصم */
export type TillProductRow = Omit<TillProduct, 'label' | 'sale_price'> & { label?: string; sale_price?: number };

/**
 * ‏يُكمل الاسمَ المعروض حيث غاب — في الصفوف نفسِها، فلا تُنسخ ألفا صنف.
 *
 * الصفوفُ حمولةٌ جاءت من الخادم للتوّ (صفحةٌ أو إعادةُ تحميلٍ للأصناف)، لا
 * يقرؤها أحدٌ قبل هذا. والاسمُ المعروض الحاضرُ لا يُمسّ ولو كان فارغًا.
 */
export function fromTill(rows: TillProductRow[]): TillProduct[] {
    for (const row of rows) {
        if (row.label === undefined) row.label = row.name;
        // ‏ولا خصمَ على الصنف: يُباع بسعره
        if (row.sale_price === undefined) row.sale_price = row.price;
    }

    return rows as TillProduct[];
}

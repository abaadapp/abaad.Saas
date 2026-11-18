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
 */
import type { Product } from '@/types/models';

/** ‏ما لا يقرؤه الصندوق — ولا يصله */
type NotForTill = 'published' | 'cat_label' | 'description_pos_enabled' | 'description_web_enabled';

/** ‏الصنفُ كما يقرؤه الصندوق */
export type TillProduct = Omit<Product, NotForTill>;

/** ‏وكما يرسله الخادم: الاسمُ المعروض غائبٌ حين يساوي الاسم */
export type TillProductRow = Omit<TillProduct, 'label'> & { label?: string };

/**
 * ‏يُكمل الاسمَ المعروض حيث غاب — في الصفوف نفسِها، فلا تُنسخ ألفا صنف.
 *
 * الصفوفُ حمولةٌ جاءت من الخادم للتوّ (صفحةٌ أو إعادةُ تحميلٍ للأصناف)، لا
 * يقرؤها أحدٌ قبل هذا. والاسمُ المعروض الحاضرُ لا يُمسّ ولو كان فارغًا.
 */
export function fromTill(rows: TillProductRow[]): TillProduct[] {
    for (const row of rows) {
        if (row.label === undefined) row.label = row.name;
    }

    return rows as TillProduct[];
}

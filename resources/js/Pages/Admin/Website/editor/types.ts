import type { FieldSpec } from '../fields/SectionForm';

/**
 * ما يعرفه المحرّر عن قسمٍ واحد — كما يرسله `EditorController::sectionPayload`.
 *
 * ويسكن ملفًّا بذاته لأنّ ثلاثةَ مكوّناتٍ تقرؤه: القائمةُ ولوحةُ الحقول
 * والمعاينة. ونسخةٌ في كلٍّ منها تفترق عند أوّل حقلٍ يُضاف في الخادم.
 */
export interface EditorSection {
    id: number;
    type: string;
    /** `header` أو `footer` لقسمٍ عامّ، و`null` لقسمٍ في صفحة */
    slot: string | null;
    label: string;
    hint: string;
    visible: boolean;
    /** من أين يُقرأ محتواه — `products` أو `categories` أو `reviews` */
    source: string | null;
    data: Record<string, unknown>;
    schema: FieldSpec[];
}

/**
 * مفتاحُ القسم في المعاينة — وهو ما يربط الصندوقَ باللوحة.
 *
 * والقسمُ العامّ يُعرف بخانته لا برقمه: الترويسةُ في كلّ صفحة، فلا موضعَ لها
 * في ترتيب أقسام صفحةٍ بعينها.
 */
export function keyOf(section: EditorSection, index: number): string {
    return section.slot ? `slot:${section.slot}` : `index:${index}`;
}

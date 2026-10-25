/**
 * أسماءُ حالات سند النقل وطلب التزويد وألوانُها — مصدرٌ واحد لشاشاتها.
 *
 * والحالةُ تُخزَّن بمفتاحٍ إنجليزيّ (`BranchTransfer::PENDING`…) وتُقرأ هنا
 * بالعربية، فلا يُكتب اسمُها في كلّ شاشةٍ بيدٍ مختلفة.
 */

export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary';

export const TRANSFER_STATUS: Record<string, { label: string; tone: Tone }> = {
    pending: { label: 'بانتظار الموافقة', tone: 'warning' },
    approved: { label: 'معتمد', tone: 'info' },
    preparing: { label: 'قيد التحضير', tone: 'info' },
    in_transit: { label: 'أُرسل — بانتظار الاستلام', tone: 'primary' },
    discrepancy: { label: 'استُلم بفرق', tone: 'danger' },
    received: { label: 'اكتمل الاستلام', tone: 'success' },
    rejected: { label: 'مرفوض', tone: 'neutral' },
    cancelled: { label: 'أُلغي', tone: 'neutral' },
    legacy: { label: 'نقل قديم', tone: 'neutral' },
};

export const SUPPLY_STATUS: Record<string, { label: string; tone: Tone }> = {
    pending: { label: 'بانتظار المراجعة', tone: 'warning' },
    in_progress: { label: 'معتمد — قيد التنفيذ', tone: 'info' },
    fulfilled: { label: 'مكتمل', tone: 'success' },
    approved: { label: 'معتمد', tone: 'info' },
    rejected: { label: 'مرفوض', tone: 'neutral' },
    cancelled: { label: 'أُلغي', tone: 'neutral' },
};

/** أسماءُ سطور سجلّ المراحل */
export const EVENT_LABEL: Record<string, string> = {
    requested: 'قدّم الطلب',
    approved: 'اعتمد السند',
    rejected: 'رفض الطلب',
    preparing: 'بدأ التحضير',
    dispatched: 'أكّد الإرسال',
    received: 'أكّد الاستلام',
    discrepancy: 'أكّد الاستلام بفرق',
    resolved: 'سوّى فرقًا',
    cancelled: 'ألغى السند',
};

/** وسطورُ طلب التزويد — الطلبُ لا السند */
export const SUPPLY_EVENT_LABEL: Record<string, string> = {
    ...EVENT_LABEL,
    approved: 'اعتمد الطلب',
    cancelled: 'ألغى الطلب',
};

/** أبوابُ تسوية الفرق — كما في `BranchTransfers::RESOLUTIONS` */
export const RESOLUTION_LABEL: Record<string, string> = {
    lost: 'فُقد أو تلف',
    returned: 'أُعيد إلى المصدر',
    late: 'وصل متأخرًا',
};

/** مفتاحٌ لكلّ نموذج — ضغطتان على «إرسال» تكتبان سندًا واحدًا */
export function clientKey(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

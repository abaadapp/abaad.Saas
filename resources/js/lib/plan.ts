import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * هل تفتح باقةُ هذا المتجر هذه القدرة؟
 *
 * سؤالٌ غير `abilities`: ذاك عن صلاحية الموظّف داخل المتجر، وهذا عمّا اشتراه
 * صاحبُ المتجر. والاثنان يقعان على الزرّ الواحد — مالكٌ يملك كلّ الأقسام في
 * متجرٍ على الباقة الأساسية.
 *
 * والغائب مفتوح: صفحةٌ قديمة في المتصفّح لا تحمل الخريطة يجب ألّا تُخفي على
 * صاحبها ما اشتراه — والخادم هو الحارس على كل حال (انظر CheckPlanFeature).
 */
export function usePlanFeature(key: string): boolean {
    const { auth } = usePage<PageProps>().props;

    // مفتاحٌ فارغ يعني «بلا قدرةٍ مطلوبة» — يُبقي المكوّن مفتوحًا كما كان
    if (! key) return true;

    return auth?.planFeatures?.[key] ?? true;
}

import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * هل يملك المستخدمُ هذا الفعل؟ — `auth.mayActions` من `HandleInertiaRequests`.
 *
 * القسمُ يفتح الشاشة ولا يبيح ما فيها (`SectionActions`): زرُّ الإضافة أو
 * الحذف أو التصدير يُخفى عمّن لا يملك فعله، فلا يضغط زرًّا يُردّ بـ٤٠٣.
 * والخادمُ هو الحارس على كلّ حال (`CheckAbility`).
 *
 * والغائبُ مغلق: لا يُرسم زرٌّ لا يُعرف أنّه مأذون.
 */
export function useMay(): (action: string) => boolean {
    const { auth } = usePage<PageProps>().props;
    const actions = auth?.mayActions ?? [];

    return (action: string) => actions.includes(action);
}

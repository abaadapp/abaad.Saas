import { router } from '@inertiajs/react';
import { PhoneOff } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { PHONE_STATUS_LABEL, type PhoneStatus } from '@/lib/customer-phone';

/**
 * تنبيهُ بيانات العملاء — هادئٌ في الصفحة، لا نافذةٌ ولا جرس.
 *
 * يُعرض في لوحة التاجر وفي قائمة العملاء وحدهما، ويختفي حين لا يبقى رقمٌ
 * يحتاج مراجعة. وزرُّه يفتح القائمةَ على المتأثّرين (`missing=phone`).
 * والعددُ من الخادم (`CustomerPhone::reviewCount`) — من متجره وحده.
 */
export function CustomerPhoneReviewBanner({ count, filtering = false }: { count: number; filtering?: boolean }) {
    const t = useTranslate();

    if (count <= 0) return null;

    return (
        <div
            role="status"
            data-testid="customer-phone-review"
            className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]"
        >
            <span className="flex items-start gap-2">
                <PhoneOff className="mt-0.5 size-5 shrink-0" />
                <span>
                    <strong className="block font-semibold">{t(filtering ? 'أرقام تحتاج تحديثًا' : 'تنبيه بيانات العملاء')}</strong>
                    {t('يوجد لديك (:n) عميل يحتاج إلى مراجعة رقم الهاتف أو تحديثه.', { n: number(count) })}
                </span>
            </span>
            {filtering ? (
                <Button variant="outline" size="sm" onClick={() => router.get(route('admin.customers.index'), {}, { preserveState: false })}>
                    {t('عرض الكل')}
                </Button>
            ) : (
                <Button size="sm" onClick={() => router.get(route('admin.customers.index'), { missing: 'phone' }, { preserveState: false })}>
                    {t('مراجعة العملاء')}
                </Button>
            )}
        </div>
    );
}

/** حالةُ الرقم بجانبه — لا شيء لرقمٍ صالح */
export function PhoneStatusBadge({ status }: { status: PhoneStatus | null | undefined }) {
    const t = useTranslate();

    if (!status) return null;

    return (
        <Badge variant={status === 'missing' ? 'neutral' : status === 'incomplete' ? 'warning' : 'danger'} data-testid="phone-status">
            {t(PHONE_STATUS_LABEL[status])}
        </Badge>
    );
}

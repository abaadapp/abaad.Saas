import { usePage } from '@inertiajs/react';

import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import PaidLinkCollectionCard, { type PaidCollection } from '@/Components/PaidLinkCollection';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    collection: PaidCollection;
    /** سندُ القبض صفحةُ «المالية» — رابطُه وطباعتُه لمن يفتحها */
    mayOpenReceipts: boolean;
}

/**
 * «عرض» تحصيلٍ عبر رابط دفعٍ مستقلّ — لا فاتورةَ له.
 *
 * الرابطُ المرتبطُ بفاتورةٍ يُفتح على فاتورته الأصليّة (`OrderController::collection`).
 * وهذا دُفع رصيدًا للعميل بلا فاتورة: يُقال ذلك صراحةً، ولا تُنشأ له فاتورةٌ
 * ولا تُعاد — فاتورةٌ تُصنع هنا بيعٌ لم يقع.
 */
export default function OrderCollection() {
    const { collection, mayOpenReceipts } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title={collection.id}>
            <BackLink routeName="admin.orders.index" href={route('admin.orders.index')} label="المبيعات" />

            <PageHeader title={collection.id} subtitle={`${t('تحصيل عبر رابط دفع')}${collection.customer ? ` — ${collection.customer}` : ''}`} />

            <div className="max-w-3xl space-y-4">
                <Card className="border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] leading-relaxed text-[#92400e]" data-testid="standalone-note">
                    {t('لا توجد فاتورة مرتبطة بهذا التحصيل — دُفع عبر رابط دفعٍ مستقلّ فسُجّل رصيدًا للعميل. ولا تُنشأ له فاتورة.')}
                </Card>

                <PaidLinkCollectionCard collection={collection} mayOpenReceipts={mayOpenReceipts} />
            </div>
        </AdminLayout>
    );
}

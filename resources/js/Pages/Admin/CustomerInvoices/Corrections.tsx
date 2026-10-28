import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import CorrectionRequests, { type CorrectionRequest } from './partials/CorrectionRequests';

interface Props {
    corrections: CorrectionRequest[];
    /** هل أشعل صاحبُ النشاط الاعتمادَ الثنائيّ؟ — الطلباتُ القديمة تبقى تُقرأ بعد إطفائه */
    enabled: boolean;
    mayApprove: boolean;
}

/**
 * طلباتُ التصحيح الماليّ — المعلّقةُ أوّلًا، ثمّ ما بُتّ فيه.
 *
 * لمن يعتمد ولمن يقرأ سجلّ التعديلات، ومن سواهما يرى طلباتِه هو
 * (`FinancialCorrectionController::index`).
 */
export default function CustomerInvoiceCorrections() {
    const { corrections, enabled, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    return (
        <AdminLayout title={t('طلبات التصحيح المالي')}>
            <PageHeader
                title={t('طلبات التصحيح المالي')}
                subtitle={t('تصحيحات الفواتير الصادرة والمدفوعة التي تنتظر اعتماد شخص ثانٍ، وما قُرّر فيها.')}
            />

            {!enabled && (
                <Card className="mb-4 p-4 text-[13px] text-[#6b7280]" data-testid="corrections-off">
                    {t('الاعتماد الثنائي مطفأ — التصحيحات تُحفظ مباشرة. يُشعله صاحب النشاط من الإعدادات ← صلاحيات الموظفين.')}
                </Card>
            )}

            {corrections.length === 0 ? (
                <Card className="p-8 text-center text-[13px] text-[#9ca3af]" data-testid="corrections-empty">
                    {t('لا طلبات تصحيح.')}
                </Card>
            ) : (
                <CorrectionRequests requests={corrections} m={m} showInvoice />
            )}
        </AdminLayout>
    );
}

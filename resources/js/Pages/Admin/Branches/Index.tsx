import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToSettings from '../Settings/partials/BackToSettings';
import BranchesPanel from '../Settings/panels/BranchesPanel';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types/models';

/**
 * صفحة الفروع المستقلّة.
 *
 * الطريق المعتاد إليها صار من داخل الإعدادات — تُفتح هناك مكان اللوحة. وتبقى
 * هذه الصفحة لأن رابطها المباشر منشورٌ في إشعاراتٍ وروابط محفوظة، ولأن
 * كسر رابطٍ يعمل أسوأ من صفحةٍ لا يزورها إلا القليل. والجسم واحد في
 * الموضعين، فلا تفترقان مع أول تعديل.
 */
export default function BranchesIndex() {
    const { branches } = usePage<PageProps<{ branches: Branch[] }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="الفروع">
            <PageHeader
                title="الفروع"
                subtitle={t('أضِف فروع نشاطك وأدرها من مكان واحد')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'الفروع' }]}
            />

            <BackToSettings />
            <BranchesPanel branches={branches} />
        </AdminLayout>
    );
}

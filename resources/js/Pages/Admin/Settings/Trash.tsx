import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToSettings from './partials/BackToSettings';
import TrashPanel, { type TrashData } from './panels/TrashPanel';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * صفحة المحذوفات المستقلّة — الجسم نفسه الذي يُفتح داخل الإعدادات.
 */
export default function Trash() {
    const { products, expenses, trashedBranches, windowDays } = usePage<PageProps<TrashData>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="المحذوفات">
            <PageHeader
                title="المحذوفات"
                subtitle={t('استعادة ما حُذف من المنتجات والمصروفات')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الإعدادات', href: route('admin.settings.index') },
                    { label: 'المحذوفات' },
                ]}
            />

            <BackToSettings />
            <TrashPanel products={products} expenses={expenses} trashedBranches={trashedBranches} windowDays={windowDays} />
        </AdminLayout>
    );
}

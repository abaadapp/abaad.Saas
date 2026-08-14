import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToSettings from './Settings/partials/BackToSettings';
import ActivityPanel, { type ActivityData } from './Settings/panels/ActivityPanel';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * صفحة سجل النشاط المستقلّة — الجسم نفسه الذي يُفتح داخل الإعدادات.
 */
export default function Activity() {
    const { logs, pagination, filters } = usePage<PageProps<ActivityData>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="سجل النشاط">
            <PageHeader
                title="سجل النشاط"
                subtitle={t('سجلّ كامل بكل العمليات التي تمّت على النظام')}
            />

            <BackToSettings />
            <ActivityPanel
                logs={logs}
                pagination={pagination}
                filters={filters}
                endpoint={route('admin.activity.index')}
            />
        </AdminLayout>
    );
}

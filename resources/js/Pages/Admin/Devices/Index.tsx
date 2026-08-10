import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToSettings from '../Settings/partials/BackToSettings';
import DevicesPanel, { type DevicesData } from '../Settings/panels/DevicesPanel';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * صفحة أجهزة نقاط البيع المستقلّة — الجسم نفسه الذي يُفتح داخل الإعدادات.
 */
export default function DevicesIndex() {
    const { devices, branches, peripheralTypes, drivableTypes, paperWidths } =
        usePage<PageProps<DevicesData>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="أجهزة نقاط البيع">
            <PageHeader
                title="أجهزة نقاط البيع"
                subtitle={t('كل جهاز مربوط بفرع واحد — منه تُنسب المبيعات والورديات')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'أجهزة نقاط البيع' },
                ]}
            />

            <BackToSettings />
            <DevicesPanel
                devices={devices}
                branches={branches}
                peripheralTypes={peripheralTypes}
                drivableTypes={drivableTypes}
                paperWidths={paperWidths}
            />
        </AdminLayout>
    );
}

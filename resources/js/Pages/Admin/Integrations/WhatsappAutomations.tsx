import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import { SettingsPage } from '@/Components/Settings';
import { useTranslate } from '@/lib/i18n';
import { AutomationsScreen, type AutomationsView } from '@/Pages/Admin/Integrations/partials/AutomationsScreen';
import type { PageProps } from '@/types';

export { AutomationsScreen, type AutomationsView } from '@/Pages/Admin/Integrations/partials/AutomationsScreen';

export default function IntegrationsWhatsappAutomations() {
    const { view } = usePage<PageProps<{ view: AutomationsView }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="أتمتة واتساب">
            <BackLink routeName="admin.integrations.whatsapp" href={route('admin.integrations.whatsapp')} label="واتساب بزنس" />
            <PageHeader title="أتمتة واتساب" subtitle={t('ردود تخرج وحدها حين يكتب زبونك كلمة تحددها')} />
            <SettingsPage width="wide">
                <AutomationsScreen view={view} />
            </SettingsPage>
        </AdminLayout>
    );
}

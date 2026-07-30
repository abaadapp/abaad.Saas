import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import ShopForm, { type ShopOptions } from './partials/ShopForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function FlowerShopCreate() {
    const { options } = usePage<PageProps<{ options: ShopOptions }>>().props;
    const t = useTranslate();

    return (
        <PlatformLayout title="إضافة محل ورود">
            <PageHeader
                title="إضافة محل ورود"
                subtitle={t('أدخل بيانات محل الورود الجديد')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'محلات الورود', href: route('super-admin.flower-shops.index') },
                    { label: 'إضافة محل' },
                ]}
            />

            <ShopForm
                options={options}
                initial={{
                    name: '',
                    owner: '',
                    city: '',
                    phone: '',
                    email: '',
                    branches: '1',
                    plan: '',
                    status: 'نشط',
                    start: '',
                    end: '',
                }}
                action={route('super-admin.flower-shops.store')}
                method="post"
                submitLabel="حفظ المحل"
            />
        </PlatformLayout>
    );
}

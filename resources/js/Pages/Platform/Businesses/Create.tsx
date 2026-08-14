import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BusinessForm, { type BusinessOptions } from './partials/BusinessForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function BusinessCreate() {
    const { options } = usePage<PageProps<{ options: BusinessOptions }>>().props;
    const t = useTranslate();

    return (
        <PlatformLayout title="إضافة شركة جديدة">
            <PageHeader
                title="إضافة شركة جديدة"
                subtitle={t('أدخل بيانات الشركة الجديدة لتسجيلها في المنصة')}
            />

            <BusinessForm
                options={options}
                initial={{
                    name: '',
                    type: '',
                    country: 'عُمان',
                    city: '',
                    address: '',
                    owner_name: '',
                    phone: '',
                    email: '',
                    plan_id: '',
                    status: 'نشط',
                    starts_at: '',
                    ends_at: '',
                }}
                action={route('super-admin.businesses.store')}
                method="post"
                submitLabel="حفظ الشركة"
                cancelHref={route('super-admin.businesses.index')}
            />
        </PlatformLayout>
    );
}

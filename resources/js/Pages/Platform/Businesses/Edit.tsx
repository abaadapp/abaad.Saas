import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BusinessForm, { type BusinessOptions } from './partials/BusinessForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface EditableBusiness {
    id: number;
    name: string;
    type: string | null;
    country: string | null;
    city: string | null;
    address: string | null;
    owner: string | null;
    phone: string | null;
    email: string | null;
    plan_id: number | null;
    status: string | null;
    starts_at: string | null;
    ends_at: string | null;
    logo_url: string | null;
    owner_email: string | null;
}

export default function BusinessEdit() {
    const { business, options } = usePage<PageProps<{ business: EditableBusiness; options: BusinessOptions }>>().props;
    const t = useTranslate();

    return (
        <PlatformLayout title="تعديل الشركة">
            <PageHeader
                title="تعديل الشركة"
                subtitle={`${t('تعديل بيانات:')} ${business.name}`}
            />

            <BusinessForm
                options={options}
                businessId={business.id}
                // القيم من السجل نفسه؛ القالب القديم كان يطبع العنوان والتاريخين ثابتين
                initial={{
                    name: business.name ?? '',
                    type: business.type ?? '',
                    country: business.country ?? '',
                    city: business.city ?? '',
                    address: business.address ?? '',
                    owner_name: business.owner ?? '',
                    phone: business.phone ?? '',
                    email: business.email ?? '',
                    plan_id: business.plan_id ? String(business.plan_id) : '',
                    status: business.status ?? '',
                    starts_at: business.starts_at ?? '',
                    ends_at: business.ends_at ?? '',
                }}
                logoUrl={business.logo_url}
                ownerEmail={business.owner_email}
                action={route('super-admin.businesses.update', business.id)}
                method="put"
                submitLabel="حفظ التعديلات"
                cancelHref={route('super-admin.businesses.index')}
            />
        </PlatformLayout>
    );
}

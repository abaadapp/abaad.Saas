import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import ShopForm, { type ShopOptions } from './partials/ShopForm';
import type { PageProps } from '@/types';

interface EditableShop {
    id: number;
    name: string;
    owner: string | null;
    city: string | null;
    phone: string | null;
    email: string | null;
    branches: number;
    plan: string;
    status: string;
    start: string | null;
    end: string | null;
    logo_url: string | null;
}

export default function FlowerShopEdit() {
    const { shop, options } = usePage<PageProps<{ shop: EditableShop; options: ShopOptions }>>().props;

    return (
        <PlatformLayout title="تعديل محل الورود">
            <PageHeader
                title="تعديل محل الورود"
                subtitle={shop.name}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'محلات الورود', href: route('super-admin.flower-shops.index') },
                    { label: shop.name },
                ]}
            />

            <ShopForm
                options={options}
                initial={{
                    name: shop.name ?? '',
                    owner: shop.owner ?? '',
                    city: shop.city ?? '',
                    phone: shop.phone ?? '',
                    email: shop.email ?? '',
                    branches: String(shop.branches ?? 1),
                    // «—» هي تسمية «بلا باقة» القادمة من الخادم، لا اسم باقة يُنتقى
                    plan: shop.plan === '—' ? '' : shop.plan,
                    status: shop.status ?? '',
                    start: shop.start ?? '',
                    end: shop.end ?? '',
                }}
                logoUrl={shop.logo_url}
                action={route('super-admin.flower-shops.update', shop.id)}
                method="put"
                submitLabel="حفظ التعديلات"
            />
        </PlatformLayout>
    );
}

import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm from './partials/ProductForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Category } from '@/types/models';

export default function ProductCreate() {
    const { categories, context } = usePage<PageProps<{ categories: Category[] }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="إضافة منتج">
            <PageHeader
                title="إضافة منتج"
                subtitle={t('أضف منتجًا جديدًا إلى متجرك')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المنتجات', href: route('admin.products.index') },
                    { label: 'إضافة منتج' },
                ]}
            />

            <ProductForm categories={categories} currencyLabel={context!.currency.symbol ?? t('ر.ع')} />
        </AdminLayout>
    );
}

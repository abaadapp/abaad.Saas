import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm from './partials/ProductForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CompositionData } from './partials/addons';
import type { Category } from '@/types/models';
import type { BoutiqueOption } from './partials/boutique';

export default function ProductCreate() {
    const { categories, composition, boutiques, context } =
        usePage<PageProps<{ categories: Category[]; composition: CompositionData; boutiques?: BoutiqueOption[] }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="إضافة منتج">
            <PageHeader
                title="إضافة منتج"
                subtitle={t('أضف منتجًا جديدًا إلى متجرك')}
            />

            <ProductForm
                categories={categories}
                currencyLabel={context!.currency.symbol || context!.currency.code}
                composition={composition}
                boutiques={boutiques}
            />
        </AdminLayout>
    );
}

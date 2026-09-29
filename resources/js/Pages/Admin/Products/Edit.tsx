import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm from './partials/ProductForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CompositionData } from './partials/addons';
import type { GalleryImage } from './partials/Gallery';
import type { Category, Product } from '@/types/models';
import type { BoutiqueOption } from './partials/boutique';

interface Props {
    product: Product;
    categories: Category[];
    description: string;
    composition: CompositionData | null;
    /** معرض الصور — الرئيسية أوّلًا ثمّ ما بعدها */
    gallery: GalleryImage[];
    galleryMax: number;
    galleryLimits: { perFile: number; batch: number };
    /** بوتيكاتُ المتجر — فارغةٌ لمن لا بوتيكَ عنده. انظر `Boutiques::options` */
    boutiques?: BoutiqueOption[];
    boutiqueId?: number | null;
    /** طريقةُ عرض الإضافات في نقطة البيع — إعدادٌ للنشاط. انظر `AddonsLayoutSwitch` */
    addonsDisplay?: { layout: string; can_change: boolean };
}

export default function ProductEdit() {
    const { product, categories, description, composition, gallery, galleryMax, galleryLimits, boutiques, boutiqueId, addonsDisplay, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="تعديل المنتج">
            <PageHeader
                title="تعديل المنتج"
                subtitle={`${t('تعديل بيانات')}: ${product.name}`}
            />

            <ProductForm
                product={product}
                categories={categories}
                description={description}
                currencyLabel={context!.currency.symbol || context!.currency.code}
                composition={composition}
                gallery={gallery}
                galleryMax={galleryMax}
                galleryLimits={galleryLimits}
                boutiques={boutiques}
                boutiqueId={boutiqueId}
                addonsDisplay={addonsDisplay}
            />
        </AdminLayout>
    );
}

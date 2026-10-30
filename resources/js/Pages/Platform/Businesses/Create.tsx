import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BusinessForm, { type BusinessOptions } from './partials/BusinessForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function BusinessCreate() {
    // والقطاعُ محدَّدٌ سلفًا — من صفحة القطاع، أو الوحيدُ النشط (`PageController::businessesCreate`)
    const { options, sectorId } = usePage<PageProps<{ options: BusinessOptions; sectorId?: number | null }>>().props;
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
                    sector_id: sectorId ? String(sectorId) : '',
                    tier: '',
                    storefront_theme: '',
                    status: 'نشط',
                    starts_at: '',
                    ends_at: '',
                    // مطفأٌ صراحةً — ومفتاحٌ غائبٌ عن الحمولة لا يُقرأ «أُطفئ»
                    boutiques_enabled: false,
                    boutique_components_enabled: false,
                    gift_orders_enabled: false,
                    order_notes_and_edits_enabled: false,
                    thermal_delivery_note_enabled: false,
                    customer_invoice_preparation_enabled: false,
                    // أضيقُ الطرق افتراضًا: تجربةُ الأساسية — والمدفوعُ والمنحةُ يُختاران صراحةً
                    subscription_mode: 'trial',
                    cycle: '',
                    grant_until: '',
                    grant_reason: '',
                    addons: [],
                }}
                sectorId={sectorId}
                action={route('super-admin.businesses.store')}
                method="post"
                submitLabel="حفظ الشركة"
                cancelHref={route('super-admin.businesses.index')}
            />
        </PlatformLayout>
    );
}

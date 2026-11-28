import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import BusinessForm, { type BusinessOptions } from './partials/BusinessForm';
import { BusinessDocuments, type BusinessDocumentsData } from './partials/BusinessDocuments';
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
    /** قطاعُ الشركة — والباقةُ من قطاعه (`Sectors`) */
    sector_id?: number | null;
    status: string | null;
    starts_at: string | null;
    ends_at: string | null;
    tier: string | null;
    storefront_theme: string | null;
    /** يُؤوي بوتيكات — منطقٌ يُرسله `PageController::businessesEdit` */
    boutiques_enabled: boolean;
    boutique_components_enabled?: boolean;
    /** الإهداءُ في المتجر الإلكترونيّ — يفتحه مديرُ المنصّة وحده */
    gift_orders_enabled: boolean;
    order_notes_and_edits_enabled: boolean;
    thermal_delivery_note_enabled: boolean;
    customer_invoice_preparation_enabled: boolean;
    logo_url: string | null;
    owner_email: string | null;
    /** يُسأل عن منحةٍ إداريّة — `AdminGrants::asked` */
    grantable?: boolean;
}

export default function BusinessEdit() {
    const { business, options, documents } = usePage<PageProps<{ business: EditableBusiness; options: BusinessOptions; documents?: BusinessDocumentsData }>>().props;
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
                    sector_id: business.sector_id ? String(business.sector_id) : '',
                    status: business.status ?? '',
                    starts_at: business.starts_at ?? '',
                    ends_at: business.ends_at ?? '',
                    tier: business.tier ?? '',
                    storefront_theme: business.storefront_theme ?? '',
                    /*
                        ═══ ومفتاحُ البوتيكات يُقرأ من السجلّ ═══

                        كان يسقط من هنا وحدَه، فيُعرض المربّعُ مطفأً مهما
                        كان المحفوظ — ثمّ يُطفئه أوّلُ حفظٍ لاسمٍ أو هاتف.
                        فيفقد التاجرُ تبويبَ «البوتيكات» بلا أن يمسّه أحد،
                        ولا يشي به شيء: الفئةُ والواجهةُ تبقيان فيُقرأ
                        الحفظُ سليمًا.
                    */
                    boutiques_enabled: business.boutiques_enabled ?? false,
                    boutique_components_enabled: business.boutique_components_enabled ?? false,
                    gift_orders_enabled: business.gift_orders_enabled ?? false,
                    order_notes_and_edits_enabled: business.order_notes_and_edits_enabled ?? false,
                    thermal_delivery_note_enabled: business.thermal_delivery_note_enabled ?? false,
                    customer_invoice_preparation_enabled: business.customer_invoice_preparation_enabled ?? false,
                }}
                logoUrl={business.logo_url}
                ownerEmail={business.owner_email}
                grantable={business.grantable ?? false}
                action={route('super-admin.businesses.update', business.id)}
                method="put"
                submitLabel="حفظ التعديلات"
                cancelHref={route('super-admin.businesses.index')}
            />

            {/* مستنداتُ الشركة بعد النموذج وأزراره — روابطُها في تبويبٍ جديد فلا يضيع ما لم يُحفظ */}
            {documents && <BusinessDocuments documents={documents} newTab />}
        </PlatformLayout>
    );
}

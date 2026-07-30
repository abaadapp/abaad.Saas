import { usePage } from '@inertiajs/react';
import { Ban, Plus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import RowActions from '@/Components/RowActions';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface BusinessRow {
    id: number;
    name: string;
    type: string;
    owner: string | null;
    phone: string | null;
    email: string | null;
    plan: string;
    status: string;
    registered: string;
    branches: number;
    logo: string | null;
}

interface Props {
    businesses: BusinessRow[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    options: { types: string[]; statuses: string[]; plans: string[] };
}

export default function BusinessesIndex() {
    const { businesses, pagination, filters, options } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const columns: Column<BusinessRow>[] = [
        {
            key: 'name',
            header: 'الشركة',
            cell: (b) => (
                <div className="flex items-center gap-3">
                    {b.logo ? (
                        <img
                            src={b.logo}
                            alt=""
                            loading="lazy"
                            className="size-9 shrink-0 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                        />
                    ) : (
                        <span className="size-9 shrink-0 rounded-[8px] bg-[#f2f2f0]" />
                    )}
                    <SmartLink
                        routeName="super-admin.businesses.show"
                        href={route('super-admin.businesses.show', b.id)}
                        className="min-w-0 truncate font-medium hover:underline"
                    >
                        {b.name}
                    </SmartLink>
                </div>
            ),
        },
        { key: 'type', header: 'النوع', cell: (b) => t(b.type) },
        { key: 'owner', header: 'المالك', cell: (b) => b.owner || '—' },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (b) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {b.phone || '—'}
                </span>
            ),
        },
        {
            key: 'email',
            header: 'البريد',
            cell: (b) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {b.email || '—'}
                </span>
            ),
        },
        { key: 'plan', header: 'الباقة', cell: (b) => t(b.plan) },
        { key: 'status', header: 'الحالة', cell: (b) => <Badge status={b.status} /> },
        {
            key: 'registered',
            header: 'التسجيل',
            cell: (b) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {b.registered}
                </span>
            ),
        },
        {
            key: 'branches',
            header: 'الفروع',
            align: 'end',
            cell: (b) => <span className="tabular-nums">{number(b.branches)}</span>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (b) => (
                <RowActions
                    show={{ routeName: 'super-admin.businesses.show', href: route('super-admin.businesses.show', b.id) }}
                    edit={{ routeName: 'super-admin.businesses.edit', href: route('super-admin.businesses.edit', b.id) }}
                    destroy={{
                        url: route('super-admin.businesses.destroy', b.id),
                        // التسمية «تعطيل» لا «حذف»: المتحكّم يغيّر الحالة ولا يمحو السجل
                        message: 'سيُنقل هذا النشاط إلى حالة «معطل». هل تريد المتابعة؟',
                    }}
                />
            ),
        },
    ];

    const tableFilters: Filter<BusinessRow>[] = [
        { label: 'كل الأنواع', param: 'type', options: options.types.map((v) => ({ label: v, value: v })) },
        { label: 'كل الباقات', param: 'plan', options: options.plans.map((v) => ({ label: v, value: v })) },
        { label: 'كل الحالات', param: 'status', options: options.statuses.map((v) => ({ label: v, value: v })) },
    ];

    return (
        <PlatformLayout title="الشركات">
            <PageHeader
                title="الشركات"
                subtitle={t('إدارة جميع الشركات المسجلة في المنصة')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'الشركات' },
                ]}
                actions={
                    <>
                        <ExportMenu
                            xlsx={route('super-admin.businesses.xlsx')}
                            pdf={route('super-admin.businesses.exportPdf')}
                            csv={route('super-admin.export.businesses')}
                        />
                        <Button asChild>
                            <SmartLink
                                routeName="super-admin.businesses.create"
                                href={route('super-admin.businesses.create')}
                            >
                                <Plus />
                                {t('إضافة شركة جديدة')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <DataTable
                rows={businesses}
                columns={columns}
                rowKey={(b) => b.id}
                filters={tableFilters}
                searchPlaceholder="ابحث بالاسم أو المالك أو البريد…"
                empty={
                    <span className="flex flex-col items-center gap-2">
                        <Ban className="size-8 text-[#d1d5db]" />
                        {t('لا توجد شركات مسجلة بعد')}
                    </span>
                }
                server={{ pagination, params: filters }}
            />
        </PlatformLayout>
    );
}

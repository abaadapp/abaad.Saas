import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import DataTable, { type Column } from '@/Components/DataTable';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import type { PageProps } from '@/types';
import type { Supplier } from '@/types/models';

export default function SuppliersIndex() {
    const { suppliers } = usePage<PageProps<{ suppliers: Supplier[] }>>().props;

    const columns: Column<Supplier>[] = [
        { key: 'name', header: 'المورّد', sortable: true, value: (s) => s.name },
        { key: 'contact', header: 'مسؤول التواصل', cell: (s) => s.contact || '—' },
        { key: 'phone', header: 'الهاتف', cell: (s) => s.phone || '—' },
        { key: 'email', header: 'البريد', cell: (s) => s.email || '—' },
        {
            key: 'orders_count',
            header: 'أوامر الشراء',
            align: 'end',
            sortable: true,
            value: (s) => s.orders_count,
            cell: (s) => <span className="tabular-nums">{number(s.orders_count)}</span>,
        },
    ];

    return (
        <AdminLayout title="المورّدون">
            <PageHeader
                title="المورّدون"
                subtitle={`${number(suppliers.length)} مورّد`}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المورّدون' },
                ]}
            />

            <Card className="overflow-hidden">
                <DataTable
                    rows={suppliers}
                    columns={columns}
                    rowKey={(s) => s.id}
                    searchPlaceholder="ابحث بالاسم أو الهاتف…"
                    searchable={(s) => `${s.name} ${s.phone} ${s.email} ${s.contact}`}
                    empty="لا يوجد مورّدون بعد"
                />
            </Card>
        </AdminLayout>
    );
}

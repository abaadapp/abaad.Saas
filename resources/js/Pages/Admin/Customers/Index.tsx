import { usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Upload } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column } from '@/Components/DataTable';
import StatCard, { type Stat } from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Customer } from '@/types/models';

interface Props {
    customers: Customer[];
    stats: Stat[];
}

export default function CustomersIndex() {
    const { customers, stats, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const columns: Column<Customer>[] = [
        {
            key: 'name',
            header: 'العميل',
            sortable: true,
            value: (c) => c.label ?? c.name,
            cell: (c) => (
                <SmartLink routeName={'admin.customers.show'} href={route('admin.customers.show', c.id)}
                    className="font-medium hover:underline"
                >
                    {c.label ?? c.name}
                </SmartLink>
            ),
        },
        { key: 'phone', header: 'الهاتف', cell: (c) => c.phone || '—' },
        {
            key: 'orders',
            header: 'الطلبات',
            align: 'end',
            sortable: true,
            value: (c) => c.orders,
            cell: (c) => <span className="tabular-nums">{number(c.orders)}</span>,
        },
        {
            key: 'total_spent',
            header: 'إجمالي الإنفاق',
            align: 'end',
            sortable: true,
            value: (c) => c.total_spent,
            cell: (c) => <span className="tabular-nums">{money(c.total_spent, currency)}</span>,
        },
        {
            key: 'points',
            header: 'النقاط',
            align: 'end',
            sortable: true,
            value: (c) => c.points,
            cell: (c) => <span className="tabular-nums">{number(c.points)}</span>,
        },
        { key: 'last_order', header: 'آخر طلب', cell: (c) => c.last_order || '—' },
    ];

    return (
        <AdminLayout title="العملاء">
            <PageHeader
                title="العملاء"
                subtitle={`${number(customers.length)} عميل`}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'العملاء' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('admin.customers.export.xlsx')}>
                                <FileSpreadsheet />
                                {t('تصدير')}
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink routeName={'admin.customers.import.form'} href={route('admin.customers.import.form')}>
                                <Upload />
                                {t('استيراد')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink routeName={'admin.customers.create'} href={route('admin.customers.create')}>
                                <Plus />
                                {t('عميل جديد')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            {stats.length > 0 && (
                <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {stats.map((stat, i) => (
                        <StatCard key={stat.label} stat={stat} index={i} />
                    ))}
                </div>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={customers}
                    columns={columns}
                    rowKey={(c) => c.id}
                    searchPlaceholder="ابحث بالاسم أو الهاتف أو البريد…"
                    searchable={(c) => `${c.name} ${c.name_en ?? ''} ${c.phone} ${c.email ?? ''}`}
                    empty="لا يوجد عملاء بعد"
                />
            </Card>
        </AdminLayout>
    );
}

import { usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { initials, money, number } from '@/lib/format';
import type { PageProps } from '@/types';
import type { Employee } from '@/types/models';

export default function EmployeesIndex() {
    const { employees, context } = usePage<PageProps<{ employees: Employee[] }>>().props;
    const currency = context!.currency;

    const columns: Column<Employee>[] = [
        {
            key: 'name',
            header: 'الموظف',
            sortable: true,
            value: (e) => e.name,
            cell: (e) => (
                <div className="flex items-center gap-3">
                    <Avatar className="size-9">
                        {e.avatar && <AvatarImage src={e.avatar} alt="" />}
                        <AvatarFallback>{initials(e.name)}</AvatarFallback>
                    </Avatar>
                    <span className="min-w-0">
                        <SmartLink routeName={'admin.employees.show'} href={route('admin.employees.show', e.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {e.name}
                        </SmartLink>
                        <span className="block text-[11px] text-[#9ca3af]">{e.email}</span>
                    </span>
                </div>
            ),
        },
        { key: 'role', header: 'الدور', sortable: true, value: (e) => e.role },
        { key: 'branch', header: 'الفرع', cell: (e) => e.branch || '—' },
        { key: 'phone', header: 'الهاتف', cell: (e) => e.phone || '—' },
        {
            key: 'sales',
            header: 'المبيعات',
            align: 'end',
            sortable: true,
            value: (e) => e.sales,
            cell: (e) => <span className="tabular-nums">{money(e.sales, currency)}</span>,
        },
        {
            key: 'achieved',
            header: 'تحقيق الهدف',
            align: 'end',
            sortable: true,
            value: (e) => e.achieved,
            cell: (e) => <span className="tabular-nums">{number(e.achieved)}%</span>,
        },
        { key: 'status', header: 'الحالة', cell: (e) => <Badge status={e.status} /> },
    ];

    const filters: Filter<Employee>[] = [
        {
            label: 'كل الأدوار',
            options: [...new Set(employees.map((e) => e.role))].map((r) => ({ label: r, value: r })),
            match: (e, value) => e.role === value,
        },
        {
            label: 'كل الحالات',
            options: [
                { label: 'نشط', value: 'نشط' },
                { label: 'متوقف', value: 'متوقف' },
            ],
            match: (e, value) => e.status === value,
        },
    ];

    return (
        <AdminLayout title="الموظفون">
            <PageHeader
                title="الموظفون"
                subtitle={`${number(employees.length)} موظف`}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'الموظفون' }]}
                actions={
                    <Button asChild>
                        <SmartLink routeName={'admin.employees.create'} href={route('admin.employees.create')}>
                            <Plus />
                            موظف جديد
                        </SmartLink>
                    </Button>
                }
            />

            <Card className="overflow-hidden">
                <DataTable
                    rows={employees}
                    columns={columns}
                    rowKey={(e) => e.id}
                    searchPlaceholder="ابحث بالاسم أو البريد أو الهاتف…"
                    searchable={(e) => `${e.name} ${e.email} ${e.phone} ${e.role}`}
                    filters={filters}
                    empty="لا يوجد موظفون بعد"
                />
            </Card>
        </AdminLayout>
    );
}

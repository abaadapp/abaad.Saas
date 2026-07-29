import { Link, usePage } from '@inertiajs/react';
import { ArrowLeftRight, FileSpreadsheet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import type { PageProps } from '@/types';
import type { Branch } from '@/types';
import type { InventoryItem } from '@/types/models';

interface Props {
    inventory: InventoryItem[];
    branches: Branch[];
    currentBranchId: number | null;
}

export default function InventoryIndex() {
    const { inventory, context } = usePage<PageProps<Props>>().props;
    const currency = context!.currency;

    const totalValue = inventory.reduce((sum, item) => sum + item.value, 0);

    const columns: Column<InventoryItem>[] = [
        {
            key: 'name',
            header: 'المنتج',
            sortable: true,
            value: (i) => i.name,
            cell: (i) => (
                <span>
                    <span className="block font-medium">{i.name}</span>
                    <span className="block text-[11px] text-[#9ca3af]">{i.sku}</span>
                </span>
            ),
        },
        {
            key: 'qty',
            header: 'الكمية',
            align: 'end',
            sortable: true,
            value: (i) => i.qty,
            cell: (i) => <span className="tabular-nums">{number(i.qty)}</span>,
        },
        {
            key: 'min',
            header: 'حد التنبيه',
            align: 'end',
            cell: (i) => <span className="tabular-nums text-[#9ca3af]">{number(i.min)}</span>,
        },
        { key: 'status', header: 'الحالة', cell: (i) => <Badge status={i.status} /> },
        {
            key: 'cost',
            header: 'التكلفة',
            align: 'end',
            sortable: true,
            value: (i) => i.cost,
            cell: (i) => <span className="tabular-nums">{money(i.cost, currency)}</span>,
        },
        {
            key: 'value',
            header: 'قيمة المخزون',
            align: 'end',
            sortable: true,
            value: (i) => i.value,
            cell: (i) => <span className="tabular-nums font-medium">{money(i.value, currency)}</span>,
        },
    ];

    const filters: Filter<InventoryItem>[] = [
        {
            label: 'كل الحالات',
            options: [
                { label: 'متوفر', value: 'متوفر' },
                { label: 'منخفض', value: 'منخفض' },
                { label: 'نفد', value: 'نفد' },
            ],
            match: (i, value) => i.status === value,
        },
    ];

    return (
        <AdminLayout title="المخزون">
            <PageHeader
                title="المخزون"
                subtitle={`${number(inventory.length)} صنف · القيمة الإجمالية ${money(totalValue, currency)}`}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'المخزون' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('admin.inventory.xlsx')}>
                                <FileSpreadsheet />
                                تصدير
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={route('admin.inventory.movements')}>
                                <ArrowLeftRight />
                                الحركات
                            </Link>
                        </Button>
                    </>
                }
            />

            <Card className="overflow-hidden">
                <DataTable
                    rows={inventory}
                    columns={columns}
                    rowKey={(i) => i.id}
                    searchPlaceholder="ابحث بالاسم أو SKU…"
                    searchable={(i) => `${i.name} ${i.sku}`}
                    filters={filters}
                    empty="لا توجد أصناف في المخزون بعد"
                />
            </Card>
        </AdminLayout>
    );
}

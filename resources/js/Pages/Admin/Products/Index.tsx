import { usePage } from '@inertiajs/react';
import { Barcode, FileSpreadsheet, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import type { PageProps } from '@/types';
import type { Category, Product } from '@/types/models';

interface Props {
    products: Product[];
    categories: Category[];
}

export default function ProductsIndex() {
    const { products, categories, context } = usePage<PageProps<Props>>().props;
    const currency = context!.currency;

    const columns: Column<Product>[] = [
        {
            key: 'name',
            header: 'المنتج',
            sortable: true,
            value: (p) => p.label ?? p.name,
            cell: (p) => (
                <div className="flex items-center gap-3">
                    {p.image ? (
                        <img
                            src={p.image}
                            alt=""
                            className="size-9 shrink-0 rounded-[8px] object-cover"
                            loading="lazy"
                        />
                    ) : (
                        <span className="size-9 shrink-0 rounded-[8px] bg-[#f2f2f0]" />
                    )}
                    <span className="min-w-0">
                        <SmartLink routeName={'admin.products.show'} href={route('admin.products.show', p.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {p.label ?? p.name}
                        </SmartLink>
                        <span className="block text-[11px] text-[#9ca3af]">{p.sku}</span>
                    </span>
                </div>
            ),
        },
        { key: 'cat', header: 'التصنيف', sortable: true, value: (p) => p.cat },
        {
            key: 'price',
            header: 'السعر',
            align: 'end',
            sortable: true,
            value: (p) => p.price,
            cell: (p) => <span className="tabular-nums">{money(p.price, currency)}</span>,
        },
        {
            key: 'qty',
            header: 'الكمية',
            align: 'end',
            sortable: true,
            value: (p) => p.qty,
            cell: (p) => <span className="tabular-nums">{number(p.qty)}</span>,
        },
        {
            key: 'stock_status',
            header: 'الحالة',
            cell: (p) => <Badge status={p.stock_status} />,
        },
        {
            key: 'active',
            header: 'منشور',
            align: 'center',
            cell: (p) => (
                <Badge variant={p.active ? 'success' : 'neutral'}>{p.active ? 'نعم' : 'لا'}</Badge>
            ),
        },
    ];

    const filters: Filter<Product>[] = [
        {
            label: 'كل التصنيفات',
            options: categories.map((c) => ({ label: c.name, value: c.name })),
            match: (p, value) => p.cat === value,
        },
        {
            label: 'كل الحالات',
            options: [
                { label: 'متوفر', value: 'متوفر' },
                { label: 'منخفض', value: 'منخفض' },
                { label: 'نفد', value: 'نفد' },
            ],
            match: (p, value) => p.stock_status === value,
        },
    ];

    return (
        <AdminLayout title="المنتجات">
            <PageHeader
                title="المنتجات"
                subtitle={`${number(products.length)} منتج`}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'المنتجات' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('admin.products.xlsx')}>
                                <FileSpreadsheet />
                                تصدير
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink routeName={'admin.products.barcodes'} href={route('admin.products.barcodes')}>
                                <Barcode />
                                الباركود
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink routeName={'admin.products.create'} href={route('admin.products.create')}>
                                <Plus />
                                منتج جديد
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <Card className="overflow-hidden">
                <DataTable
                    rows={products}
                    columns={columns}
                    rowKey={(p) => p.id}
                    searchPlaceholder="ابحث بالاسم أو SKU أو الباركود…"
                    searchable={(p) => `${p.name} ${p.name_en ?? ''} ${p.sku} ${p.barcode}`}
                    filters={filters}
                    empty="لا توجد منتجات بعد — أضف أول منتج"
                />
            </Card>
        </AdminLayout>
    );
}

import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import useLiveStock from '@/hooks/useLiveStock';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types';
import type { InventoryItem } from '@/types/models';

interface Props {
    /** صفحةٌ من الأصناف — رشّحها الخادم ورتّبها، والتصديرُ يقرأ ما رُشّح كلَّه */
    inventory: InventoryItem[];
    pagination: ServerPagination;
    /** البحثُ والحالةُ (?stock= — يرسلها تنبيه النظرة العامة) والترتيب */
    filters: Record<string, string | null>;
    sorts: string[];
    /** عددُ الأصناف وقيمتُها للمخزن كلِّه لا للصفحة */
    summary: { count: number; value: number };
    branches: Branch[];
    currentBranchId: number | null;
}

export default function InventoryIndex() {
    const { inventory: serverInventory, pagination, filters: params, sorts, summary, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    /* الجدول كان مجمّدًا على لقطة لحظة الفتح بينما بطاقات اللوحة تتحدّث.
       الحقل هنا اسمه status لا stock_status، فيُترجَم ذهابًا وإيابًا.

       والـuseMemo ليس تحسينًا: useLiveStock يمسح لقطته كلما تغيّر مرجع
       المصفوفة (ليَغلب دائمًا ما جاء من الخادم مع كل تنقّل). مصفوفةٌ جديدة
       مع كل تصيير تعني مسحًا دائمًا فلا يظهر أي تحديث أبدًا. */
    const mapped = useMemo(
        () => serverInventory.map((i) => ({ ...i, stock_status: i.status })),
        [serverInventory],
    );
    const { products: live, updatedAt } = useLiveStock(route('admin.products.stockFeed'), mapped);

    // القيمة تتبع الكمية: تركُها كما جاءت من الخادم يعني صفًّا يقول
    // «الكمية 2» و«القيمة 240» في آن واحد
    const inventory = live.map((i) => ({
        ...i,
        status: i.stock_status,
        value: Math.round(i.cost * i.qty * 1000) / 1000,
    }));


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
            asTabs: true,
            options: [
                { label: 'متوفر', value: 'متوفر' },
                { label: 'منخفض', value: 'منخفض' },
                // القيمة هي ما يُرجعه Product::statusFor حرفيًا — كانت «نفد»
                // فلا تطابق «نفد المخزون» أبدًا، فيُرجع الخيار قائمة فارغة دائمًا
                { label: 'نفد', value: 'نفد المخزون' },
            ],
            // على الخادم (`InventoryList`) — فيبلغ زرَّ التصدير مع البحث
            param: 'stock',
        },
    ];

    return (
        <AdminLayout title="المخزون">
            <PageHeader
                title="المخزون"
                subtitle={t(':n صنف · القيمة الإجمالية :total', {
                    n: number(summary.count),
                    total: money(summary.value, currency),
                })}
                actions={
                    <>
                        <ExportMenu
                            xlsx={route('admin.inventory.xlsx')}
                            pdf={route('admin.inventory.exportPdf')}
                            csv={route('admin.export.inventory')}
                        />
                    </>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.index" />

            {updatedAt && (
                <p className="mb-3 text-[12px] text-[#9ca3af]">
                    {t('الكميات محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={inventory}
                    columns={columns}
                    rowKey={(i) => i.id}
                    searchPlaceholder="ابحث بالاسم أو SKU…"
                    searchable={() => ''}
                    filters={filters}
                    server={{ pagination, params, sorts }}
                    empty="لا توجد أصناف في المخزون بعد"
                />
            </Card>
        </AdminLayout>
    );
}

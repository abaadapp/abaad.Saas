import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Barcode, LayoutGrid, List, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import RowActions from '@/Components/RowActions';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Category, Product } from '@/types/models';

interface Props {
    products: Product[];
    pagination: ServerPagination;
    categories: Category[];
    filters: Record<string, string | null>;
}

export default function ProductsIndex() {
    const { products, pagination, categories, filters, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [view, setView] = useState<'table' | 'grid'>('table');

    const actionsFor = (p: Product) => (
        <RowActions
            show={{ routeName: 'admin.products.show', href: route('admin.products.show', p.id) }}
            edit={{ routeName: 'admin.products.edit', href: route('admin.products.edit', p.id) }}
            destroy={{ url: route('admin.products.destroy', p.id), message: 'حذف المنتج؟' }}
        />
    );

    const columns: Column<Product>[] = [
        {
            key: 'name',
            header: 'المنتج',
            cell: (p) => (
                <div className="flex items-center gap-3">
                    {p.image ? (
                        <img src={p.image} alt="" className="size-11 shrink-0 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] object-cover" loading="lazy" />
                    ) : (
                        <span className="size-11 shrink-0 rounded-[8px] bg-[#f2f2f0]" />
                    )}
                    <span className="min-w-0">
                        <SmartLink
                            routeName="admin.products.show"
                            href={route('admin.products.show', p.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {p.label ?? p.name}
                        </SmartLink>
                        <span className="block text-[11px] text-[#9ca3af]">{p.sku || '—'}</span>
                    </span>
                </div>
            ),
        },
        { key: 'cat', header: 'القسم', cell: (p) => p.cat || '—' },
        {
            key: 'price',
            header: 'السعر',
            align: 'end',
            cell: (p) => <span className="tabular-nums font-medium">{money(p.price, currency)}</span>,
        },
        {
            key: 'cost',
            header: 'سعر التكلفة',
            align: 'end',
            cell: (p) => <span className="tabular-nums text-[#6b7280]">{money(p.cost, currency)}</span>,
        },
        {
            key: 'qty',
            header: 'الكمية',
            align: 'end',
            cell: (p) => <span className="tabular-nums">{number(p.qty)}</span>,
        },
        { key: 'stock_status', header: 'المخزون', cell: (p) => <Badge status={p.stock_status} /> },
        {
            key: 'active',
            header: 'الحالة',
            cell: (p) => (
                <Badge variant={p.active ? 'success' : 'neutral'}>{p.active ? t('مفعّل') : t('غير مفعّل')}</Badge>
            ),
        },
        { key: 'actions', header: 'إجراءات', align: 'end', cell: actionsFor },
    ];

    const tableFilters: Filter<Product>[] = [
        {
            label: 'كل الأقسام',
            param: 'category',
            options: categories.map((c) => ({ label: c.name, value: c.name })),
        },
        {
            label: 'كل الحالات',
            param: 'status',
            options: [
                { label: 'مفعّل', value: 'active' },
                { label: 'غير مفعّل', value: 'inactive' },
            ],
        },
        {
            label: 'حالة المخزون',
            param: 'stock',
            options: [
                { label: 'متوفر', value: 'متوفر' },
                { label: 'منخفض', value: 'منخفض' },
                { label: 'نفد المخزون', value: 'نفد المخزون' },
            ],
        },
    ];

    return (
        <AdminLayout title="المنتجات">
            <PageHeader
                title="المنتجات"
                subtitle={t('إدارة منتجات محل الورود')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'المنتجات' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.products.barcodes" href={route('admin.products.barcodes')}>
                                <Barcode />
                                {t('طباعة الباركود')}
                            </SmartLink>
                        </Button>
                        <ExportMenu
                            xlsx={route('admin.products.xlsx')}
                            pdf={route('admin.products.exportPdf')}
                            csv={route('admin.export.products')}
                        />
                        <Button asChild>
                            <SmartLink routeName="admin.products.create" href={route('admin.products.create')}>
                                <Plus />
                                {t('إضافة منتج')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.products.index" />

            {/* مبدّل العرض: شبكي أو جدول — كما كان في Alpine */}
            <div className="mb-4 flex items-center gap-1 border-b border-[var(--ui-border,#e8e8e8)]">
                {([
                    { key: 'grid', label: 'عرض شبكي', Icon: LayoutGrid },
                    { key: 'table', label: 'عرض جدول', Icon: List },
                ] as const).map(({ key, label, Icon }) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setView(key)}
                        className={cn(
                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                            view === key
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        <Icon className="size-4" />
                        {t(label)}
                    </button>
                ))}
                <span className="ms-auto text-sm text-[#6b7280]">
                    {number(pagination.total)} {t('منتج')}
                </span>
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={products}
                    columns={columns}
                    rowKey={(p) => p.id}
                    searchPlaceholder="ابحث بالاسم أو SKU…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا توجد منتجات بعد — أضف أول منتج"
                    server={{ pagination, params: filters }}
                    // العرض الشبكي يستبدل الجدول ويُبقي البحث والتصفية والترقيم فوقه
                    renderBody={
                        view === 'grid'
                            ? (rows) => (
                                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                      {rows.map((p, i) => (
                                          <motion.div
                                              key={p.id}
                                              initial={{ opacity: 0, y: 6 }}
                                              animate={{ opacity: 1, y: 0 }}
                                              transition={{ duration: 0.2, delay: Math.min(i * 0.02, 0.2) }}
                                          >
                                              <Card className="flex h-full flex-col overflow-hidden">
                                                  <div className="relative aspect-[4/3] bg-[#f7f7f5]">
                                                      {p.image && (
                                                          <img src={p.image} alt="" className="size-full object-cover" loading="lazy" />
                                                      )}
                                                      <span className="absolute start-2 top-2">
                                                          <Badge status={p.stock_status} />
                                                      </span>
                                                      <span className="absolute end-2 top-2">{actionsFor(p)}</span>
                                                  </div>
                                                  <div className="flex flex-1 flex-col gap-1 p-3.5">
                                                      <SmartLink
                                                          routeName="admin.products.show"
                                                          href={route('admin.products.show', p.id)}
                                                          className="truncate font-medium text-[#111] hover:underline"
                                                      >
                                                          {p.label ?? p.name}
                                                      </SmartLink>
                                                      <span className="text-[12px] text-[#9ca3af]">{p.cat || '—'}</span>
                                                      <div className="mt-auto flex items-center justify-between pt-2">
                                                          <span className="font-semibold tabular-nums">
                                                              {money(p.price, currency)}
                                                          </span>
                                                          <span className="text-[12px] text-[#6b7280]">
                                                              {t('الكمية')}: {number(p.qty)}
                                                          </span>
                                                      </div>
                                                  </div>
                                              </Card>
                                          </motion.div>
                                      ))}
                                  </div>
                              )
                            : undefined
                    }
                />
            </Card>
        </AdminLayout>
    );
}

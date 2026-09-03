import { router, usePage } from '@inertiajs/react';
import { Plus, ShoppingBag } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PURCHASE_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import SmartLink from '@/Components/SmartLink';
import { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    key: string;
    date: string | null;
    reference: string;
    supplier: string;
    source: string;
    total: number;
    status: string;
    items: number;
}

interface Props {
    rows: Row[];
    summary: { count: number; total: number; outstanding: number };
    month: string;
    months: string[];
    suppliers: { value: number; label: string }[];
    filters: Record<string, string | null>;
}

export default function PurchaseRegister() {
    const { rows, summary, month, months, suppliers, filters, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const go = (patch: Record<string, string | null>) =>
        router.get(
            route('admin.purchases.index'),
            { month: month || 'all', supplier: filters.supplier ?? '', ...patch },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const columns: Column<Row>[] = [
        {
            key: 'date',
            header: 'التاريخ',
            sortable: true,
            value: (r) => r.date ?? '',
            cell: (r) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {r.date ?? '—'}
                </span>
            ),
        },
        {
            key: 'reference',
            header: 'المرجع',
            cell: (r) => <span className="font-mono text-[12px] text-[#4b4b4b]">{r.reference}</span>,
        },
        { key: 'supplier', header: 'المورّد', cell: (r) => r.supplier, value: (r) => r.supplier },
        {
            key: 'source',
            header: 'المصدر',
            cell: (r) => (
                <Badge variant={r.source === 'أمر شراء' ? 'info' : 'neutral'}>{t(r.source)}</Badge>
            ),
        },
        { key: 'status', header: 'الحالة', cell: (r) => <Badge>{t(r.status)}</Badge> },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            sortable: true,
            value: (r) => r.total,
            cell: (r) => <span className="font-semibold tabular-nums text-[#111]">{m(r.total)}</span>,
        },
    ];

    const tableFilters: Filter<Row>[] = [
        {
            label: 'كل المصادر',
            options: [
                { label: 'أمر شراء', value: 'أمر شراء' },
                { label: 'سند مورّد', value: 'سند مورّد' },
            ],
            match: (r, v) => r.source === v,
        },
    ];

    return (
        <AdminLayout title="قائمة المشتريات">
            <PageHeader
                title="قائمة المشتريات"
                subtitle={t('ما اشتراه المتجر في الشهر المعروض — من أوامر الشراء ومن سندات الموردين معًا')}
                actions={
                    <>
                        <Select
                            value={month}
                            aria-label={t('الشهر')}
                            className="w-40"
                            placeholder="كل الشهور"
                            onChange={(e) => go({ month: e.target.value || 'all' })}
                            options={months.map((x) => ({ value: x, label: x }))}
                        />
                        <Select
                            value={filters.supplier ?? ''}
                            aria-label={t('المورّد')}
                            className="w-44"
                            placeholder="كل الموردين"
                            onChange={(e) => go({ supplier: e.target.value })}
                            options={suppliers}
                        />
                        <Button asChild>
                            <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create')}>
                                <Plus />
                                {t('أمر شراء')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={PURCHASE_TABS} current="admin.purchases.index" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    stat={{ label: t('مشتريات الشهر'), value: m(summary.total), icon: 'shopping-bag', color: 'primary' }}
                    index={0}
                />
                {/*
                    وبلا سطر «اتجاه» تحتها كأختيها.

                    كان تحتها «:o أمرًا · :i سندًا» بسهمٍ صاعدٍ أخضر — وهو تفصيلُ
                    العدد لا اتجاهُه. فالبطاقة تقول «ارتفع» ولا شيء قُورن بشيء،
                    وتبدو مختلفةً عن جارتيها بلا سبب. والتفصيلُ نفسه يُقرأ من
                    مرشّح النوع في الجدول تحتها.
                */}
                <StatCard
                    stat={{
                        label: t('عدد المستندات'),
                        value: number(summary.count),
                        icon: 'clipboard-list',
                        color: 'info',
                    }}
                    index={1}
                />
                {/* ما على المتجر لا يخصّ شهرًا: الدَّين يبقى حتى يُسدَّد */}
                <StatCard
                    stat={{
                        label: t('مستحقّ للموردين'),
                        value: m(summary.outstanding),
                        icon: 'truck',
                        color: summary.outstanding > 0 ? 'warning' : 'success',
                    }}
                    index={2}
                />
            </div>

            {rows.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <ShoppingBag className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا مشتريات في هذا الشهر')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('المشتريات تدخل من بابين: أمر شراء يُستلم، أو سند مورّد يُسجَّل مباشرة.')}
                    </p>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={rows}
                        columns={columns}
                        rowKey={(r) => r.key}
                        searchPlaceholder="ابحث بالمرجع أو المورّد…"
                        searchable={(r) => `${r.reference} ${r.supplier}`}
                        filters={tableFilters}
                        empty="لا مشتريات"
                    />
                    <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm text-[#6b7280]">
                        {month || t('كل الشهور')} — {t('الإجمالي')}:{' '}
                        <span className="font-semibold text-[#111]">{m(summary.total)}</span>
                        <span className="text-[#9ca3af]"> ({number(summary.count)})</span>
                    </div>
                </Card>
            )}
        </AdminLayout>
    );
}

import { usePage } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { REPORTS_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column } from '@/Components/DataTable';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import useLiveFeed from '@/hooks/useLiveFeed';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface ProductProfit {
    name: string;
    qty: number;
    revenue: number;
    cost: number;
    profit: number;
    margin: number;
}

interface CategoryProfit {
    name: string;
    revenue: number;
    profit: number;
    margin: number;
}

interface Summary {
    revenue: number;
    cost: number;
    profit: number;
    margin: number;
    best: ProductProfit | null;
    worst: ProductProfit | null;
    loss_makers: ProductProfit[];
}

interface Props {
    summary: Summary;
    products: ProductProfit[];
    categories: CategoryProfit[];
    range: ReportRange;
}

export default function Profitability() {
    const { context, ...server } = usePage<PageProps<Props>>().props;

    /* تُحتسب لحظة الفتح ثم تتجمّد — وصفحة تُترك مفتوحة تعرض أرقام الصباح */
    const { data: live, updatedAt } = useLiveFeed<Props>(route('admin.profitability.feed', { range: server.range }));
    const { summary, products, categories } = live ?? server;

    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const stats = [
        { label: t('الإيرادات'), value: m(summary.revenue), icon: 'wallet', color: 'primary' },
        { label: t('التكلفة'), value: m(summary.cost), icon: 'arrow-down-circle', color: 'warning' },
        { label: t('صافي الربح'), value: m(summary.profit), icon: 'trending-up', color: 'success' },
        { label: t('هامش الربح'), value: `${summary.margin}%`, icon: 'percent', color: 'info' },
    ];

    const marginCell = (v: number) => (
        <span
            className={cn(
                'font-semibold tabular-nums',
                v >= 40 ? 'text-[#047857]' : v >= 15 ? 'text-[#d97706]' : 'text-[#b91c1c]',
            )}
        >
            {number(v)}%
        </span>
    );

    const productColumns: Column<ProductProfit>[] = [
        { key: 'name', header: 'المنتج', sortable: true, value: (p) => p.name, cell: (p) => (
            <span className="font-medium text-[#111]">{p.name}</span>
        ) },
        { key: 'qty', header: 'المُباع', align: 'end', sortable: true, value: (p) => p.qty,
          cell: (p) => <span className="tabular-nums">{number(p.qty)}</span> },
        { key: 'revenue', header: 'الإيراد', align: 'end', sortable: true, value: (p) => p.revenue,
          cell: (p) => <span className="tabular-nums">{m(p.revenue)}</span> },
        { key: 'cost', header: 'التكلفة', align: 'end', sortable: true, value: (p) => p.cost,
          cell: (p) => <span className="tabular-nums text-[#6b7280]">{m(p.cost)}</span> },
        { key: 'profit', header: 'الربح', align: 'end', sortable: true, value: (p) => p.profit,
          cell: (p) => <span className="tabular-nums font-semibold">{m(p.profit)}</span> },
        { key: 'margin', header: 'الهامش', align: 'end', sortable: true, value: (p) => p.margin,
          cell: (p) => marginCell(p.margin) },
    ];

    const highlight = (title: string, item: ProductProfit | null, up: boolean) => (
        <Card className="p-5">
            <div className="mb-3 flex items-center gap-2">
                {up ? (
                    <TrendingUp className="size-5 text-[#047857]" />
                ) : (
                    <TrendingDown className="size-5 text-[#b91c1c]" />
                )}
                <h3 className="font-bold text-[#111]">{t(title)}</h3>
            </div>
            {item ? (
                <>
                    <p className="text-[17px] font-semibold text-[#111]">{item.name}</p>
                    <dl className="mt-3 space-y-2 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-[#6b7280]">{t('الإيراد')}</dt>
                            <dd className="tabular-nums">{m(item.revenue)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-[#6b7280]">{t('الربح')}</dt>
                            <dd className="tabular-nums font-medium">{m(item.profit)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-[#6b7280]">{t('الهامش')}</dt>
                            <dd>{marginCell(item.margin)}</dd>
                        </div>
                    </dl>
                </>
            ) : (
                <p className="text-sm text-[#9ca3af]">{t('لا توجد بيانات كافية بعد')}</p>
            )}
        </Card>
    );

    return (
        <AdminLayout title="الربحية">
            <PageHeader
                title="الربحية"
                subtitle={t('تحليل الإيرادات والتكاليف وهوامش الربح لكل منتج وقسم')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'التقارير', href: route('admin.reports.index') },
                    { label: 'الربحية' },
                ]}
            />

            <SectionTabs tabs={REPORTS_TABS} current="admin.profitability.index" variant="segmented" />

            <RangeTabs current={server.range} />

            {updatedAt && (
                <p className="mb-3 text-[12px] text-[#9ca3af]">
                    {t('الأرقام محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                {highlight('الأعلى ربحًا', summary.best, true)}
                {highlight('الأقل ربحًا', summary.worst, false)}

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('الربحية حسب القسم')}</h3>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('القسم')}</TableHead>
                                <TableHead className="text-end">{t('الربح')}</TableHead>
                                <TableHead className="text-end">{t('الهامش')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.length === 0 ? (
                                <TableEmpty colSpan={3}>{t('لا توجد بيانات بعد')}</TableEmpty>
                            ) : (
                                categories.map((c) => (
                                    <TableRow key={c.name}>
                                        <TableCell className="font-medium text-[#111]">{c.name}</TableCell>
                                        <TableCell className="text-end tabular-nums">{m(c.profit)}</TableCell>
                                        <TableCell className="text-end">{marginCell(c.margin)}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>

            {summary.loss_makers.length > 0 && (
                <Card className="mb-6 border-[#fecaca] bg-[#fef2f2]/60 p-4">
                    <p className="font-medium text-[#b91c1c]">
                        {t('منتجات تبيع بخسارة')}: {number(summary.loss_makers.length)}
                    </p>
                    <p className="mt-1 text-[13px] text-[#4b4b4b]">
                        {summary.loss_makers.map((p) => p.name).join(' · ')}
                    </p>
                </Card>
            )}

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 pt-5">
                    <h3 className="font-bold text-[#111]">{t('الربحية حسب المنتج')}</h3>
                </div>
                <DataTable
                    rows={products}
                    columns={productColumns}
                    rowKey={(p) => p.name}
                    searchPlaceholder="ابحث باسم المنتج…"
                    searchable={(p) => p.name}
                    empty="لا توجد بيانات ربحية بعد"
                />
            </Card>
        </AdminLayout>
    );
}

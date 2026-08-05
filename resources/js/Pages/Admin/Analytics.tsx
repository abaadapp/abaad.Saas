import { usePage } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { REPORTS_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import AreaChart from '@/Components/charts/AreaChart';
import BarChart from '@/Components/charts/BarChart';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Comparison {
    label: string;
    cur: string;
    prev: string;
    delta: number;
    icon: string;
}

interface Props {
    periodComparison: Comparison[];
    topProducts: { name: string; qty: number; total: number }[];
    topCustomers: { name: string; total: number; orders: number }[];
    salesByWeekday: { labels: string[]; data: number[] };
    salesByHour: { labels: string[]; data: number[] };
    categorySales: { labels: string[]; series: number[] };
}

export default function Analytics() {
    const { context, ...server } = usePage<PageProps<Props>>().props;

    /* تُحتسب لحظة الفتح ثم تتجمّد — وصفحة تُترك مفتوحة تعرض أرقام الصباح */
    const { data: live, updatedAt } = useLiveFeed<Props>(route('admin.analytics.feed'));
    const { periodComparison, topProducts, topCustomers, salesByWeekday, salesByHour, categorySales } =
        live ?? server;

    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    return (
        <AdminLayout title="التحليلات">
            <PageHeader
                title="التحليلات"
                subtitle={t('مقارنات الفترات وأنماط البيع حسب اليوم والساعة والقسم')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'التحليلات' }]}
                actions={
                    <ExportMenu
                        xlsx={route('admin.analytics.xlsx')}
                        pdf={route('admin.analytics.pdf')}
                        csv={route('admin.export.analytics')}
                    />
                }
            />

            <SectionTabs tabs={REPORTS_TABS} current="admin.analytics.index" variant="segmented" />

            {updatedAt && (
                <p className="mb-3 text-[12px] text-[#9ca3af]">
                    {t('الأرقام محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {periodComparison.map((c) => (
                    <Card key={c.label} className="p-4">
                        <p className="truncate text-[13px] text-[#6b7280]">{t(c.label)}</p>
                        <p className="mt-1.5 text-[20px] font-bold tabular-nums text-[#111]">{c.cur}</p>
                        <p
                            className={cn(
                                'mt-2 flex items-center gap-1 text-[12px] font-medium',
                                c.delta >= 0 ? 'text-[#047857]' : 'text-[#b91c1c]',
                            )}
                        >
                            {c.delta >= 0 ? (
                                <ArrowUpRight className="size-3.5" />
                            ) : (
                                <ArrowDownRight className="size-3.5" />
                            )}
                            {number(Math.abs(c.delta))}% · {t('السابق')} {c.prev}
                        </p>
                    </Card>
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('المبيعات حسب يوم الأسبوع')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AreaChart
                            labels={salesByWeekday.labels.map((l) => t(l))}
                            data={salesByWeekday.data}
                            format={m}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('المبيعات حسب الساعة')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AreaChart labels={salesByHour.labels} data={salesByHour.data} format={m} />
                    </CardContent>
                </Card>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('المبيعات حسب القسم')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BarChart labels={categorySales.labels} series={categorySales.series} format={m} />
                    </CardContent>
                </Card>

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('الأكثر مبيعًا')}</h3>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('المنتج')}</TableHead>
                                <TableHead className="text-end">{t('الكمية')}</TableHead>
                                <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {topProducts.length === 0 ? (
                                <TableEmpty colSpan={3}>{t('لا توجد مبيعات بعد')}</TableEmpty>
                            ) : (
                                topProducts.map((p) => (
                                    <TableRow key={p.name}>
                                        <TableCell className="font-medium text-[#111]">{p.name}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(p.qty)}</TableCell>
                                        <TableCell className="text-end tabular-nums font-medium">{m(p.total)}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('أفضل العملاء')}</h3>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('العميل')}</TableHead>
                                <TableHead className="text-end">{t('الطلبات')}</TableHead>
                                <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {topCustomers.length === 0 ? (
                                <TableEmpty colSpan={3}>{t('لا يوجد عملاء بعد')}</TableEmpty>
                            ) : (
                                topCustomers.map((c) => (
                                    <TableRow key={c.name}>
                                        <TableCell className="font-medium text-[#111]">{c.name}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(c.orders)}</TableCell>
                                        <TableCell className="text-end tabular-nums font-medium">{m(c.total)}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </AdminLayout>
    );
}

import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { ChevronRight } from 'lucide-react';
import ExportMenu from '@/Components/ExportMenu';
import StatCard from '@/Components/StatCard';
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
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import { money, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Summary {
    sales: number;
    cogs: number;
    profit: number;
    expenses: number;
    tax: number;
    products: number;
    inventory_alerts: number;
    employees: number;
    customers: number;
    payment_methods: number;
}

interface TopProduct {
    name: string;
    cat: string;
    sold: number;
    revenue: number;
    pct: string;
}

interface Props {
    summary: Summary;
    salesSeries: { labels: string[]; full: string[]; data: (number | null)[]; counts: (number | null)[]; range: ReportRange };
    range: ReportRange;
    paymentDistribution: { labels: string[]; series: number[] };
    topSellingProducts: TopProduct[];
}

/** عنوان المخطّط بحسب دقّة محوره — انظر Demo::salesTrend */
const CHART_TITLE: Record<ReportRange, string> = {
    today: 'مبيعات اليوم حسب الساعة',
    week: 'مبيعات الأسبوع حسب اليوم',
    month: 'مبيعات الشهر حسب اليوم',
    year: 'مبيعات السنة حسب الشهر',
    all: 'المبيعات في آخر ١٢ شهرًا',
};

/**
 * ملخّص المبيعات — كان هو صفحة «التقارير» كلّها قبل أن يصير الفهرس بابها.
 * لم يتغيّر من محتواه شيء: تغيّر مكانه فقط، وصار بطاقةً في الفهرس تقود إليه.
 */
export default function ReportsSales() {
    const { context, ...server } = usePage<PageProps<Props>>().props;

    /* التقرير يُحتسب لحظة الفتح ثم يتجمّد. صفحة تُترك مفتوحة على مكتب التاجر
       كانت تعرض أرقام الصباح بعد يوم بيع كامل — وعليها يُبنى قرار. */
    /* الفترة تُمرَّر إلى التغذية أيضًا، وإلا انقلبت أرقام «اليوم» إلى أرقام
       الشهر بعد أوّل تحديث تلقائيّ بلا أن يلمس التاجر شيئًا */
    const { data: live, updatedAt } = useLiveFeed<Props>(
        route('admin.reports.feed', { range: server.range }),
    );
    const { summary, salesSeries, paymentDistribution, topSellingProducts } = live ?? server;

    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const stats = [
        { label: t('إجمالي المبيعات'), value: m(summary.sales), icon: 'wallet', color: 'primary' },
        // التكلفة بجانب الربح لا في ورقةٍ أخرى: من يقرأ ربحًا يحتاج أن يرى ممّ طُرح
        { label: t('تكلفة البضاعة المباعة'), value: m(summary.cogs), icon: 'package', color: 'info' },
        {
            label: t('صافي الربح'),
            value: m(summary.profit),
            icon: summary.profit >= 0 ? 'trending-up' : 'trending-down',
            color: summary.profit >= 0 ? 'success' : 'danger',
        },
        { label: t('المصروفات'), value: m(summary.expenses), icon: 'arrow-down-circle', color: 'warning' },
        { label: t('الضريبة المحصّلة'), value: m(summary.tax), icon: 'receipt', color: 'info' },
    ];

    const counters = [
        { label: 'المنتجات', value: summary.products },
        { label: 'تنبيهات المخزون', value: summary.inventory_alerts },
        { label: 'الموظفون', value: summary.employees },
        { label: 'العملاء', value: summary.customers },
        { label: 'وسائل الدفع', value: summary.payment_methods },
    ];


    return (
        <AdminLayout title="ملخّص المبيعات">
            <PageHeader
                title="ملخّص المبيعات"
                subtitle={t('ملخّص أداء المتجر: المبيعات والأرباح والمصروفات')}
                actions={
                    /* الملفّ يحمل الفترة المعروضة — لا فترته الخاصّة */
                    <ExportMenu
                        feature="reports_advanced"
                        xlsx={route('admin.reports.xlsx', { range: server.range })}
                        pdf={route('admin.reports.pdf', { range: server.range })}
                        csv={route('admin.export.reports', { range: server.range })}
                    />
                }
            />

            {/* شريط أقسامٍ لتقريرٍ واحد لا معنى له — والعودة إلى الفهرس تُقال زرًّا */}
            <div className="mb-4">
                <Button variant="ghost" size="sm" asChild>
                    <SmartLink routeName="admin.reports.index" href={route('admin.reports.index')}>
                        <ChevronRight />
                        {t('كل التقارير')}
                    </SmartLink>
                </Button>
            </div>

            {updatedAt && (
                <p className="mb-3 text-[12px] text-[#9ca3af]">
                    {t('الأرقام محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            <RangeTabs current={server.range} />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/*
                    لا مخطّط في فترة «الكل» بطلب المالك.
                    الأرقام والجداول تبقى — المحذوف هو المخطّط وحده، وفي هذه
                    الفترة وحدها. وبقيّة الفترات كما هي.
                */}
                {server.range !== 'all' && (
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            {/* عنوان المخطّط يتبع دقّته: ساعات اليوم، أو أيّام الشهر، أو أشهر السنة */}
                            <CardTitle>{t(CHART_TITLE[server.range])}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <AreaChart
                                labels={salesSeries.labels}
                                fullLabels={salesSeries.full}
                                counts={salesSeries.counts}
                                data={salesSeries.data}
                                format={m}
                            />
                        </CardContent>
                    </Card>
                )}

                {/* بلا المخطّط تأخذ البطاقة العرض كلّه بدل ثلثٍ وفراغين */}
                <Card className={cn(server.range === 'all' && 'lg:col-span-3')}>
                    <CardHeader>
                        <CardTitle>{t('توزيع وسائل الدفع')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BarChart
                            labels={paymentDistribution.labels.map((l) => t(l))}
                            series={paymentDistribution.series}
                            format={m}
                        />
                    </CardContent>
                </Card>
            </div>


            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                {counters.map((c) => (
                    <Card key={c.label} className="p-4 text-center">
                        <p className="text-[22px] font-bold tabular-nums text-[#111]">{number(c.value)}</p>
                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(c.label)}</p>
                    </Card>
                ))}
            </div>

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('الأكثر مبيعًا')}</h3>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('المنتج')}</TableHead>
                            <TableHead>{t('القسم')}</TableHead>
                            <TableHead className="text-end">{t('المُباع')}</TableHead>
                            <TableHead className="text-end">{t('الإيراد')}</TableHead>
                            <TableHead className="text-end">{t('النسبة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {topSellingProducts.length === 0 ? (
                            <TableEmpty colSpan={5}>{t('لا توجد مبيعات بعد')}</TableEmpty>
                        ) : (
                            topSellingProducts.map((p) => (
                                <TableRow key={p.name}>
                                    <TableCell className="font-medium text-[#111]">{p.name}</TableCell>
                                    <TableCell className="text-[#6b7280]">{p.cat}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(p.sold)}</TableCell>
                                    <TableCell className="text-end tabular-nums font-medium">{m(p.revenue)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#6d28d9]">{p.pct}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </AdminLayout>
    );
}

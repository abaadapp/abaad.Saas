import { usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { FileText, Sheet } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import AreaChart from '@/Components/charts/AreaChart';
import BarChart from '@/Components/charts/BarChart';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface ReportCard {
    title: string;
    desc: string;
    icon: string;
    color: string;
    value: string;
}

interface PlanSummaryRow {
    plan: string;
    subs: number;
    monthly: number;
    yearly: number;
    pct: number;
}

interface Props {
    cards: ReportCard[];
    revenueSeries: { labels: string[]; data: number[] };
    planDistribution: { labels: string[]; series: number[] };
    planSummary: PlanSummaryRow[];
    currency: Currency;
}

export default function Reports() {
    const { cards, revenueSeries, planDistribution, planSummary, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <PlatformLayout title="التقارير">
            <PageHeader
                title="التقارير"
                subtitle={t('تقارير وتحليلات شاملة لأداء المنصة')}
                actions={
                    <>
                        {/* روابط تنزيل حقيقية: الاستجابة ملف لا صفحة Inertia */}
                        <Button variant="outline" asChild>
                            <a href={route('super-admin.reports.pdf')} target="_blank" rel="noreferrer">
                                <FileText />
                                {t('تصدير PDF')}
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={route('super-admin.export.businesses')}>
                                <Sheet />
                                {t('تصدير Excel')}
                            </a>
                        </Button>
                    </>
                }
            />

            {/* كل رقم هنا محسوب من قاعدة البيانات — كانت خمس قيم ثابتة في القالب */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                {cards.map((card, i) => (
                    <motion.a
                        key={card.title}
                        href={route('super-admin.reports.pdf')}
                        target="_blank"
                        rel="noreferrer"
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3, delay: i * 0.05, ease: [0.22, 1, 0.36, 1] }}
                        className="block rounded-[var(--ui-radius,16px)] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 transition-colors hover:border-[#d1d5db]"
                    >
                        <h3 className="font-bold text-[#111]">{card.title}</h3>
                        <p className="mt-1 text-[12px] text-[#9ca3af]">{card.desc}</p>
                        <p className="mt-3 text-[20px] font-bold tabular-nums text-[#111]">{card.value}</p>
                    </motion.a>
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="p-5 lg:col-span-2">
                    <h3 className="mb-4 font-bold text-[#111]">{t('الإيرادات الشهرية')}</h3>
                    <AreaChart
                        labels={revenueSeries.labels}
                        data={revenueSeries.data}
                        format={(v) => money(v, currency)}
                        height={300}
                    />
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('توزيع الباقات')}</h3>
                    <BarChart labels={planDistribution.labels} series={planDistribution.series} />
                </Card>
            </div>

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('ملخّص الباقات')}</h3>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الباقة')}</TableHead>
                            <TableHead className="text-end">{t('عدد المشتركين')}</TableHead>
                            <TableHead className="text-end">{t('الإيراد الشهري')}</TableHead>
                            <TableHead className="text-end">{t('الإيراد السنوي')}</TableHead>
                            <TableHead className="text-end">{t('النسبة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {planSummary.length === 0 ? (
                            <TableEmpty colSpan={5}>{t('لا توجد اشتراكات نشطة بعد')}</TableEmpty>
                        ) : (
                            planSummary.map((row) => (
                                <TableRow key={row.plan}>
                                    <TableCell className="font-medium text-[#111]">{t(row.plan)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                        {number(row.subs)}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                        {money(row.monthly, currency)}
                                    </TableCell>
                                    <TableCell className="text-end font-semibold tabular-nums text-[#111]">
                                        {money(row.yearly, currency)}
                                    </TableCell>
                                    <TableCell className="text-end">
                                        <Badge variant="primary">{row.pct}%</Badge>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </PlatformLayout>
    );
}

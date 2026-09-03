import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import BackToReports from '@/Components/BackToReports';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import StatCard from '@/Components/StatCard';
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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: string;
    name: string;
    total: number;
    count: number;
    percent: number;
}

interface Props {
    rows: Row[];
    summary: { total: number; count: number; active: number; topName: string | null; topTotal: number };
    range: ReportRange;
    rangeLabel: string;
}

/**
 * وسائل الدفع — على أيّ وسيلةٍ يتحصّل المتجر.
 *
 * كان يُعرض في نافذةٍ بقالبٍ مشتركٍ مع تقريرين آخرين: جدولٌ من ثلاثة أعمدة
 * محسوبٌ على الشهر الجاري وحده، بلا مبدّل فترةٍ يقول ذلك، وبلا نسبةٍ تُقرأ
 * بنظرة. فصار له فترتُه ومؤشّراتُه ومخطّطُه.
 */
export default function ReportsPayments() {
    const { rows, summary, range, rangeLabel, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const stats = [
        { label: t('إجمالي التحصيل'), value: m(summary.total), icon: 'wallet', color: 'primary' },
        { label: t('عدد العمليات'), value: number(summary.count), icon: 'receipt', color: 'info' },
        { label: t('الوسائل النشطة'), value: number(summary.active), icon: 'credit-card', color: 'success' },
        {
            /* الأعلى تحصيلًا اسمًا ومبلغًا: «الأعلى» بلا مبلغٍ لا يقول كم يفصله عمّا بعده */
            label: t('الأعلى تحصيلًا'),
            value: summary.topName ? `${summary.topName} · ${m(summary.topTotal)}` : '—',
            icon: 'trending-up',
            color: 'warning',
        },
    ];

    return (
        <AdminLayout title="وسائل الدفع">
            {/*
                الكشف للطابعة يبقى وإن رُفع زرّ الطباعة: القاعدة العامة تُخفي
                الصفحة كلّها إلا الإيصال الحراري، فبلا هذا يُخرج Ctrl+P ورقةً
                بيضاء. والتصدير هو الطريق المعتمد، وهذا لمن طبع بمتصفّحه.
            */}
            <div className="printable-report">
                <div className="no-print">
                    <BackToReports />
                </div>

                <PageHeader
                    title="وسائل الدفع"
                    subtitle={t('توزيع التحصيل على النقد والبطاقة وبقية الوسائل')}
                    actions={
                        <ExportMenu
                            feature="reports_advanced"
                            xlsx={route('admin.reports.export.xlsx', 'payments')}
                            pdf={route('admin.reports.export.pdf', 'payments')}
                            csv={route('admin.reports.export.csv', 'payments')}
                        />
                    }
                />

                {/* الرجوع فوق العنوان: أوّلُ ما تقع عليه العين عند الخروج */}
                <div className="no-print">
                    <RangeTabs current={range} />
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {stats.map((s, i) => (
                        <StatCard key={s.label} stat={s} index={i} />
                    ))}
                </div>

                {summary.count > 0 && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>{t('التحصيل حسب الوسيلة')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart labels={rows.map((x) => x.name)} series={rows.map((x) => x.total)} format={m} />
                        </CardContent>
                    </Card>
                )}

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('التفصيل')}</h3>
                        {/* الفترة مكتوبةٌ فوق الجدول: رقمٌ بلا مدّته يُقرأ على أنه عمر المتجر */}
                        <p className="mt-0.5 text-[13px] text-[#9ca3af]">{rangeLabel}</p>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('الوسيلة')}</TableHead>
                                <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                <TableHead className="text-end">{t('عدد العمليات')}</TableHead>
                                <TableHead className="text-end">{t('النسبة')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {summary.count === 0 ? (
                                <TableEmpty colSpan={4}>{t('لا تحصيل في هذه الفترة')}</TableEmpty>
                            ) : (
                                rows.map((x) => (
                                    <TableRow key={x.id}>
                                        <TableCell className="font-medium text-[#111]">{x.name}</TableCell>
                                        <TableCell className="text-end tabular-nums">{m(x.total)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(x.count)}</TableCell>
                                        <TableCell className="text-end tabular-nums text-[#6b7280]">{x.percent}%</TableCell>
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

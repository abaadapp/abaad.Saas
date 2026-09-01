import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import BackToReports from '@/Components/BackToReports';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import StatCard from '@/Components/StatCard';
import { Badge } from '@/Components/ui/badge';
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
import type { PageProps } from '@/types';

interface Row {
    id: number;
    name: string;
    role: string;
    branch: string;
    status: string;
    sales: number;
    orders: number;
}

interface Props {
    rows: Row[];
    summary: { total: number; staff: number; sellers: number; average: number; topName: string | null; topSales: number };
    range: ReportRange;
    rangeLabel: string;
}

/**
 * أداء الموظفين — من باع كم، في الفترة التي يختارها التاجر.
 *
 * كان جدولًا في نافذةٍ مشتركة: خمسة أعمدةٍ مرتّبةٍ بمعرّف الموظف لا
 * بمبيعاته — فأوّل سطرٍ أقدمُهم لا أعلاهم — ومحسوبًا على الشهر الجاري وحده
 * بلا ما يقول ذلك.
 */
export default function ReportsStaff() {
    const { rows, summary, range, rangeLabel, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const stats = [
        { label: t('إجمالي المبيعات'), value: m(summary.total), icon: 'wallet', color: 'primary' },
        {
            /* «من باع» لا «كم موظفًا»: العدد الأوّل يقول حجم الفريق، وهذا يقول كم منه يبيع */
            label: t('من باع في الفترة'),
            value: `${number(summary.sellers)} / ${number(summary.staff)}`,
            icon: 'users',
            color: 'info',
        },
        { label: t('متوسّط البائع'), value: m(summary.average), icon: 'calculator', color: 'success' },
        {
            label: t('الأعلى مبيعًا'),
            value: summary.topName ? `${summary.topName} · ${m(summary.topSales)}` : '—',
            icon: 'star',
            color: 'warning',
        },
    ];

    return (
        <AdminLayout title="أداء الموظفين">
            {/* الكشف للطابعة: القاعدة العامة تُخفي الصفحة كلّها إلا الإيصال الحراري */}
            <div className="printable-report">
                <div className="no-print">
                    <BackToReports />
                </div>

                <PageHeader
                    title="أداء الموظفين"
                    subtitle={t('مبيعات كل موظف في الفترة المختارة وفرعه وحالته')}
                    actions={
                        <ExportMenu
                            xlsx={route('admin.reports.export.xlsx', 'staff')}
                            pdf={route('admin.reports.export.pdf', 'staff')}
                            csv={route('admin.reports.export.csv', 'staff')}
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

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('التفصيل')}</h3>
                        <p className="mt-0.5 text-[13px] text-[#9ca3af]">{rangeLabel}</p>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('الموظف')}</TableHead>
                                <TableHead>{t('الوظيفة')}</TableHead>
                                <TableHead>{t('الفرع')}</TableHead>
                                <TableHead className="text-end">{t('الطلبات')}</TableHead>
                                <TableHead className="text-end">{t('المبيعات')}</TableHead>
                                <TableHead>{t('الحالة')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 ? (
                                <TableEmpty colSpan={6}>{t('لا موظفين بعد')}</TableEmpty>
                            ) : (
                                rows.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-medium text-[#111]">{r.name}</TableCell>
                                        <TableCell className="text-[#6b7280]">{r.role}</TableCell>
                                        <TableCell className="text-[#6b7280]">{r.branch}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(r.orders)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{m(r.sales)}</TableCell>
                                        <TableCell>
                                            <Badge status={r.status} />
                                        </TableCell>
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

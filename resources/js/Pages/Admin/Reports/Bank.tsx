import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
import { type ReportTab } from '@/Components/ReportTabs';
import { type ReportRange } from '@/Components/RangeTabs';
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
    description: string | null;
    reference: string | null;
    status: string | null;
    amount: number;
    at: string | null;
}

interface Props {
    rows: Row[];
    summary: { lines: number; matched: number; unmatched: number; total: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
    tabs: ReportTab[];
}

/**
 * كشف الحساب البنكي — قراءةُ المطابقة لا إجراؤها.
 *
 * وشاشةُ المطابقة باقيةٌ في قسم المالية لمن يُطابق؛ وهذه تقول أين وصلت:
 * كم سطرًا، وكم طوبق، وكم بقي.
 */
export default function ReportsBank() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, tabs, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'match_status', label: 'حالة المطابقة', options: options.statuses ?? [] },
    ];

    const stats = [
        { label: t('أسطر الكشف'), value: number(summary.lines), icon: 'file-text', color: 'info' },
        { label: t('المطابَق'), value: number(summary.matched), icon: 'circle-check', color: 'success' },
        { label: t('غير المطابَق'), value: number(summary.unmatched), icon: 'circle-alert', color: summary.unmatched > 0 ? 'warning' : 'success' },
        { label: t('إجمالي الحركة'), value: m(summary.total), icon: 'landmark', color: 'primary' },
    ];

    return (
        <ReportScreen
            title="كشف الحساب البنكي"
            subtitle="مطابقة حركات البنك بحركات النظام، وما لم يُطابَق منها"
            reportKey="bank"
            tabs={tabs}
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            controls={controls}
            stats={stats}
            truncated={truncated}
        >
            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('التاريخ')}</TableHead>
                            <TableHead>{t('البيان')}</TableHead>
                            <TableHead>{t('المرجع')}</TableHead>
                            <TableHead>{t('المطابقة')}</TableHead>
                            <TableHead className="text-end">{t('المبلغ')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={5}>{t('لا أسطر في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.description ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.reference ?? '—'}</TableCell>
                                    <TableCell>{r.status ? <Badge status={r.status} /> : null}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.amount)}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
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
    reference: string;
    description: string;
    method: string;
    type: string;
    amount: number;
    at: string | null;
}

interface Props {
    rows: Row[];
    summary: { income: number; outgo: number; net: number; count: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * الحركة المالية — ما دخل وما خرج.
 *
 * وكانت بطاقتُها تقود إلى شاشة الحسابات البنكية: أرصدةٌ لا حركة، وبلا
 * مبدّل فترة. فمن أراد أن يعرف كم قُبض هذا الشهر لم يجد الرقم أصلًا.
 */
export default function ReportsFinance() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'method', label: 'الوسيلة', options: options.methods ?? [] },
        { kind: 'select', key: 'type', label: 'النوع', options: options.types ?? [] },
    ];

    const stats = [
        { label: t('المقبوضات'), value: m(summary.income), icon: 'arrow-up-circle', color: 'success' },
        { label: t('المدفوعات'), value: m(summary.outgo), icon: 'arrow-down-circle', color: 'danger' },
        { label: t('صافي الحركة'), value: m(summary.net), icon: summary.net >= 0 ? 'trending-up' : 'trending-down', color: summary.net >= 0 ? 'success' : 'danger' },
        { label: t('عدد الحركات'), value: number(summary.count), icon: 'receipt', color: 'info' },
    ];

    return (
        <ReportScreen
            reportKey="finance"
            title="الحركة المالية"
            subtitle="المقبوضات والمدفوعات وصافي الحركة في الفترة المختارة"
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
                            <TableHead>{t('السند')}</TableHead>
                            <TableHead>{t('البيان')}</TableHead>
                            <TableHead>{t('الوسيلة')}</TableHead>
                            <TableHead>{t('النوع')}</TableHead>
                            <TableHead className="text-end">{t('المبلغ')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={6}>{t('لا حركة في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.reference ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.description ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.method ?? '—'}</TableCell>
                                    <TableCell>{r.type ? <Badge status={r.type} /> : null}</TableCell>
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

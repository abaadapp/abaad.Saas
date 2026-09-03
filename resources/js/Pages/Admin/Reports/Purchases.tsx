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
    number: string;
    supplier: string | null;
    status: string;
    total: number;
    at: string | null;
    received: string | null;
}

interface Props {
    rows: Row[];
    summary: { count: number; total: number; received: number; pending: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * أوامر الشراء — قراءةٌ لا إصدار.
 *
 * وشاشةُ المشتريات باقيةٌ لمن يُصدر أمرًا أو يستلمه؛ وهذه تقول كم أُنفق
 * وكم بقي معلّقًا.
 */
export default function ReportsPurchases() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'status', label: 'الحالة', options: options.statuses ?? [] },
        { kind: 'select', key: 'supplier_id', label: 'المورّد', options: options.suppliers ?? [] },
    ];

    const stats = [
        { label: t('عدد الأوامر'), value: number(summary.count), icon: 'truck', color: 'info' },
        { label: t('قيمة المشتريات'), value: m(summary.total), icon: 'wallet', color: 'primary' },
        { label: t('المستلَمة'), value: number(summary.received), icon: 'package-check', color: 'success' },
        { label: t('المعلّقة'), value: number(summary.pending), icon: 'hourglass', color: summary.pending > 0 ? 'warning' : 'success' },
    ];

    return (
        <ReportScreen
            reportKey="purchases"
            title="أوامر الشراء"
            subtitle="أوامر الشراء وقيمتها وحالة استلامها لكل مورّد"
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
                            <TableHead>{t('رقم الأمر')}</TableHead>
                            <TableHead>{t('المورّد')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead>{t('الاستلام')}</TableHead>
                            <TableHead className="text-end">{t('القيمة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={6}>{t('لا أوامر شراء في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.number ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.supplier ?? '—'}</TableCell>
                                    <TableCell>{r.status ? <Badge status={r.status} /> : null}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.received ?? '—'}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.total)}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

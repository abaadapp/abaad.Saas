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
    type: string;
    description: string | null;
    method: string | null;
    status: string | null;
    amount: number;
    at: string | null;
}

interface Props {
    rows: Row[];
    summary: { total: number; count: number; average: number; topType: string | null; topTotal: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * المصروفات — قراءةٌ لا إدارة.
 *
 * وكانت البطاقة تقود إلى شاشة إدارة المصروفات: فيها زرُّ إضافةٍ وتعديلٍ
 * وحذف، ولا مجموعَ فوق الجدول ولا توزيعًا على الأنواع.
 */
export default function ReportsExpenses() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'type', label: 'النوع', options: options.types ?? [] },
    ];

    const stats = [
        { label: t('إجمالي المصروفات'), value: m(summary.total), icon: 'arrow-down-circle', color: 'danger' },
        { label: t('عدد المصروفات'), value: number(summary.count), icon: 'receipt', color: 'info' },
        { label: t('متوسّط المصروف'), value: m(summary.average), icon: 'calculator', color: 'primary' },
        { label: t('أعلى نوع'), value: summary.topType ? `${summary.topType} · ${m(summary.topTotal)}` : '—', icon: 'layers', color: 'warning' },
    ];

    return (
        <ReportScreen
            title="المصروفات"
            subtitle="المصروفات حسب النوع والتاريخ ومن سجّلها"
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
                            <TableHead>{t('النوع')}</TableHead>
                            <TableHead>{t('البيان')}</TableHead>
                            <TableHead>{t('الوسيلة')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead className="text-end">{t('المبلغ')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={6}>{t('لا مصروفات في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.type ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.description ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.method ?? '—'}</TableCell>
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

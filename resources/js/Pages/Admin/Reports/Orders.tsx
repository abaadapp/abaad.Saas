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
    customer: string | null;
    branch: string | null;
    status: string;
    method: string | null;
    total: number;
    at: string | null;
}

interface Props {
    rows: Row[];
    summary: { count: number; total: number; average: number; cancelled: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * تقرير الطلبات — قراءةٌ لا إدارة.
 *
 * وكانت البطاقة تقود إلى شاشة إدارة الطلبات: فيها التعديل والحذف وتغيير
 * الحالة. فمن دخل ليقرأ وجد نفسه في موضع الكتابة، ولا مجموعَ أمامه ولا
 * متوسّط.
 */
export default function ReportsOrders() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'status', label: 'الحالة', options: options.statuses ?? [] },
        { kind: 'select', key: 'branch_id', label: 'الفرع', options: options.branches ?? [] },
        { kind: 'select', key: 'payment_method', label: 'وسيلة الدفع', options: options.methods ?? [] },
    ];

    const stats = [
        { label: t('عدد الطلبات'), value: number(summary.count), icon: 'shopping-cart', color: 'info' },
        { label: t('إجمالي المبيعات'), value: m(summary.total), icon: 'wallet', color: 'primary' },
        { label: t('متوسّط قيمة الطلب'), value: m(summary.average), icon: 'calculator', color: 'success' },
        { label: t('الملغاة'), value: number(summary.cancelled), icon: 'circle-alert', color: summary.cancelled > 0 ? 'warning' : 'success' },
    ];

    return (
        <ReportScreen
            reportKey="orders"
            title="الطلبات"
            subtitle="كل طلبٍ بحالته وفرعه وقيمته ووسيلة دفعه"
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
                            <TableHead>{t('رقم الطلب')}</TableHead>
                            <TableHead>{t('العميل')}</TableHead>
                            <TableHead>{t('الفرع')}</TableHead>
                            <TableHead>{t('وسيلة الدفع')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={7}>{t('لا طلبات في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.number ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.customer ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.branch ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.method ?? '—'}</TableCell>
                                    <TableCell>{r.status ? <Badge status={r.status} /> : null}</TableCell>
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

import { usePage } from '@inertiajs/react';
import ReportScreen from '@/Components/ReportScreen';
import { type ReportTab } from '@/Components/ReportTabs';
import { type ReportRange } from '@/Components/RangeTabs';
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
    code: string;
    type: string;
    value: number;
    active: boolean;
    uses: number;
    discount: number;
    revenue: number;
}

interface Props {
    rows: Row[];
    summary: { coupons: number; used: number; uses: number; discount: number };
    filters: Record<string, string | null>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
    tabs: ReportTab[];
}

/**
 * الكوبونات — ما كلّفت وما جاءت به.
 *
 * والاستخدام يُقرأ من الطلبات لا من عدّاد الكوبون: `used_count` يزيد ولا
 * ينقص، ولا يعرف كم خُصم فعلًا ولا في أيّ فترة. والطلبُ يحمل الرمز
 * والقيمة معًا.
 */
export default function ReportsMarketing() {
    const { rows, summary, filters, truncated, range, rangeLabel, tabs, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const stats = [
        { label: t('عدد الكوبونات'), value: number(summary.coupons), icon: 'ticket-percent', color: 'info' },
        { label: t('استُخدم منها'), value: `${number(summary.used)} / ${number(summary.coupons)}`, icon: 'percent', color: 'primary' },
        { label: t('مرات الاستخدام'), value: number(summary.uses), icon: 'shopping-cart', color: 'success' },
        { label: t('قيمة الخصومات'), value: m(summary.discount), icon: 'arrow-down-circle', color: 'warning' },
    ];

    return (
        <ReportScreen
            title="الكوبونات والتسويق"
            subtitle="استخدام الكوبونات وقيمة الخصومات والإيراد الذي جاءت به"
            reportKey="marketing"
            tabs={tabs}
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            stats={stats}
            truncated={truncated}
        >
            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الرمز')}</TableHead>
                            <TableHead>{t('النوع')}</TableHead>
                            <TableHead className="text-end">{t('القيمة')}</TableHead>
                            <TableHead className="text-end">{t('مرات الاستخدام')}</TableHead>
                            <TableHead className="text-end">{t('الخصم')}</TableHead>
                            <TableHead className="text-end">{t('الإيراد')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={6}>{t('لا كوبونات')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium text-[#111]">{r.code ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.type ?? '—'}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.value)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.uses)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.discount)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.revenue)}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

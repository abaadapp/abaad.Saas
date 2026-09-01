import { usePage } from '@inertiajs/react';
import ReportScreen from '@/Components/ReportScreen';
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
    name: string;
    phone: string | null;
    contact: string | null;
    orders: number;
    total: number;
}

interface Props {
    rows: Row[];
    summary: { suppliers: number; active: number; orders: number; total: number };
    filters: Record<string, string | null>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * المورّدون — من نشتري منه وكم.
 *
 * وكانت البطاقة تقود إلى دفتر العناوين: أسماءٌ وهواتف، وعدُّ أوامرٍ بلا
 * قيمة. ومن يفاوض مورّدًا يحتاج المبلغ لا العدد.
 */
export default function ReportsSuppliers() {
    const { rows, summary, filters, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const stats = [
        { label: t('عدد المورّدين'), value: number(summary.suppliers), icon: 'store', color: 'info' },
        { label: t('اشترينا منهم'), value: `${number(summary.active)} / ${number(summary.suppliers)}`, icon: 'truck', color: 'primary' },
        { label: t('عدد الأوامر'), value: number(summary.orders), icon: 'clipboard-list', color: 'success' },
        { label: t('إجمالي المشتريات'), value: m(summary.total), icon: 'wallet', color: 'warning' },
    ];

    return (
        <ReportScreen
            title="المورّدون"
            subtitle="كل مورّد وعدد أوامره وقيمتها في الفترة المختارة"
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
                            <TableHead>{t('المورّد')}</TableHead>
                            <TableHead>{t('مسؤول التواصل')}</TableHead>
                            <TableHead>{t('الهاتف')}</TableHead>
                            <TableHead className="text-end">{t('عدد الأوامر')}</TableHead>
                            <TableHead className="text-end">{t('إجمالي المشتريات')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={5}>{t('لا مورّدين')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium text-[#111]">{r.name ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.contact ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.phone ?? '—'}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.orders)}</TableCell>
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

import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
import { type ReportTab } from '@/Components/ReportTabs';
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
    sku: string | null;
    category: string | null;
    quantity: number;
    alert: number;
    cost: number;
    value: number;
    below: boolean;
}

interface Props {
    rows: Row[];
    summary: { items: number; quantity: number; value: number; below: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    rangeLabel: string;
    tabs: ReportTab[];
}

/**
 * المخزون والكميات — صورةُ اللحظة.
 *
 * ولا مبدّلَ فترةٍ فوقها عمدًا: الرصيد رقمُ اليوم لا مجموعُ مدّة، ومبدّلٌ
 * لا يغيّر شيئًا أسوأ من غيابه — يظنّه التاجر عاملًا فيبني على فرقٍ لا وجود له.
 */
export default function ReportsInventory() {
    const { rows, summary, filters, options, truncated, rangeLabel, tabs, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'category_id', label: 'القسم', options: options.categories ?? [] },
        { kind: 'toggle', key: 'below', label: 'تحت الحدّ فقط' },
    ];

    const stats = [
        { label: t('الأصناف'), value: number(summary.items), icon: 'package', color: 'info' },
        { label: t('إجمالي الكمية'), value: number(summary.quantity), icon: 'boxes', color: 'primary' },
        { label: t('قيمة المخزون'), value: m(summary.value), icon: 'wallet', color: 'success' },
        { label: t('تحت الحدّ'), value: number(summary.below), icon: 'circle-alert', color: summary.below > 0 ? 'warning' : 'success' },
    ];

    return (
        <ReportScreen
            title="المخزون والكميات"
            subtitle="رصيد كل صنف وحدّه الأدنى وقيمته وما بلغ حدّ إعادة الطلب"
            reportKey="inventory"
            tabs={tabs}
            range={null}
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
                            <TableHead>{t('الصنف')}</TableHead>
                            <TableHead>{t('الرمز')}</TableHead>
                            <TableHead>{t('القسم')}</TableHead>
                            <TableHead className="text-end">{t('الرصيد')}</TableHead>
                            <TableHead className="text-end">{t('الحدّ الأدنى')}</TableHead>
                            <TableHead className="text-end">{t('التكلفة')}</TableHead>
                            <TableHead className="text-end">{t('القيمة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={7}>{t('لا أصناف')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium text-[#111]">{r.name ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.sku ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.category ?? '—'}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.quantity)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.alert)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.cost)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.value)}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

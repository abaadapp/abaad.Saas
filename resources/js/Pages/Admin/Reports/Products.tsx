import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
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
    category: string | null;
    price: number;
    quantity: number;
    units: number;
    revenue: number;
    profit: number;
}

interface Props {
    rows: Row[];
    summary: { products: number; revenue: number; profit: number; sold: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * تقرير المنتجات — أداءُ الصنف لا إدارتُه.
 *
 * وكانت البطاقة تقود إلى شاشة إدارة المنتجات: أسعارٌ وأقسامٌ وأزرارُ
 * تعديل، ولا يُقرأ منها ما بيع ولا كم أربح.
 *
 * والربحُ هنا تقديرٌ بتكلفة المنتج وقت القراءة لا قيدٌ محاسبيّ: التكلفة
 * تتغيّر بالشراء، والقيدُ في الدفتر (انظر Books).
 */
export default function ReportsProducts() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'category_id', label: 'القسم', options: options.categories ?? [] },
    ];

    const stats = [
        { label: t('عدد المنتجات'), value: number(summary.products), icon: 'package', color: 'info' },
        { label: t('باعت منها'), value: `${number(summary.sold)} / ${number(summary.products)}`, icon: 'shopping-bag', color: 'primary' },
        { label: t('الإيراد'), value: m(summary.revenue), icon: 'wallet', color: 'success' },
        { label: t('الربح التقديري'), value: m(summary.profit), icon: summary.profit >= 0 ? 'trending-up' : 'trending-down', color: summary.profit >= 0 ? 'success' : 'danger' },
    ];

    return (
        <ReportScreen
            reportKey="products"
            title="المنتجات"
            subtitle="ما بيع من كل منتج وإيراده وربحه في الفترة المختارة"
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
                            <TableHead>{t('المنتج')}</TableHead>
                            <TableHead>{t('القسم')}</TableHead>
                            <TableHead className="text-end">{t('السعر')}</TableHead>
                            <TableHead className="text-end">{t('الرصيد')}</TableHead>
                            <TableHead className="text-end">{t('المُباع')}</TableHead>
                            <TableHead className="text-end">{t('الإيراد')}</TableHead>
                            <TableHead className="text-end">{t('الربح')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={7}>{t('لا منتجات')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium text-[#111]">{r.name ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.category ?? '—'}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.price)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.quantity)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(r.units)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.revenue)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.profit)}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

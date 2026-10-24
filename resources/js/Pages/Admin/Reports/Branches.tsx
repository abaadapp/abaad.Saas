import { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowDownUp } from 'lucide-react';
import ReportScreen from '@/Components/ReportScreen';
import { type ReportRange } from '@/Components/RangeTabs';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface BranchRow {
    branch_id: number;
    branch: string;
    sales: number;
    orders: number;
    net_revenue: number;
    cogs: number;
    gross_profit: number;
    expenses: number;
    net_profit: number;
    margin: number | null;
    stock_quantity: number;
    stock_value: number;
    low_stock: number;
    open_supply: number;
    transfers_open: number;
    incoming: number;
}

interface Props {
    rows: BranchRow[];
    unassigned: { sales: number; net_revenue: number; cogs: number; gross_profit: number; orders: number } | null;
    summary: {
        sales: number;
        orders: number;
        net_revenue: number;
        gross_profit: number;
        expenses: number;
        net_profit: number;
        margin: number | null;
        branch_expenses: number;
        unallocated: number;
        stock_quantity: number;
        stock_value: number;
        in_transit_quantity: number;
        in_transit_value: number;
        best: string | null;
        worst: string | null;
    };
    filters: Record<string, string | null>;
    range: ReportRange;
    rangeLabel: string;
}

type SortKey = 'sales' | 'net_profit';

/**
 * أداءُ الفروع — كلُّ فرعٍ جنبَ الآخر، والنشاطُ كلُّه فوقها.
 *
 * والأرقامُ من الخادم كلُّها (`BranchReports::branches`) بتعريف تقرير صافي
 * الربح نفسِه: ربحُ الفرع يخصم ما نُسب إليه من مصروفات، والعامُّ غيرُ الموزّع
 * يُقال وحده ولا يُقسَم على الفروع. والشاشةُ ترتّب وتعرض فقط.
 */
export default function ReportsBranches() {
    const { rows, unassigned, summary, filters, range, rangeLabel, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const pct = (v: number | null) => (v === null ? '—' : `${v.toFixed(1)}%`);
    const [sort, setSort] = useState<SortKey>('sales');

    const sorted = useMemo(() => [...rows].sort((a, b) => b[sort] - a[sort]), [rows, sort]);

    const stats = [
        { label: t('مبيعات النشاط'), value: m(summary.sales), icon: 'shopping-bag', color: 'primary' },
        { label: t('صافي ربح النشاط'), value: m(summary.net_profit), icon: summary.net_profit >= 0 ? 'trending-up' : 'trending-down', color: summary.net_profit >= 0 ? 'success' : 'danger' },
        { label: t('مصروفات عامة غير موزعة'), value: m(summary.unallocated), icon: 'alert-triangle', color: 'warning' },
        { label: t('قيمة المخزون في الفروع'), value: m(summary.stock_value), icon: 'boxes', color: 'info' },
        { label: t('قيمة المخزون قيد النقل'), value: m(summary.in_transit_value), icon: 'truck', color: 'secondary' },
        { label: t('أعلى فرع ربحًا'), value: summary.best ?? '—', icon: 'star', color: 'success' },
    ];

    return (
        <ReportScreen
            reportKey="branches"
            title="أداء الفروع"
            subtitle="مبيعات كل فرع وأرباحه ومخزونه وطلباته المفتوحة جنبًا إلى جنب، مع ملخّص النشاط كلّه."
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            stats={stats}
            truncated={null}
        >
            {summary.unallocated > 0 && (
                <p className="mb-4 flex items-start gap-2 rounded-lg bg-[#fffbeb] px-3 py-2 text-[12.5px] leading-relaxed text-[#92400e]" data-testid="branches-unallocated">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {t('ربح كل فرع يخصم مصروفاته المنسوبة إليه فقط. المصروفات العامة غير الموزعة (:amount) تُخصم من صافي ربح النشاط وحده.', {
                            amount: m(summary.unallocated),
                        })}
                    </span>
                </p>
            )}

            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p className="text-[13px] text-[#374151]">
                    {summary.worst && (
                        <>
                            {t('أقل فرع ربحًا')}: <strong>{summary.worst}</strong>
                        </>
                    )}
                </p>
                <div className="flex items-center gap-1" data-testid="branches-sort">
                    <ArrowDownUp className="size-4 text-[#9ca3af]" />
                    {(['sales', 'net_profit'] as SortKey[]).map((k) => (
                        <Button key={k} size="sm" variant={sort === k ? 'primary' : 'outline'} onClick={() => setSort(k)}>
                            {k === 'sales' ? t('حسب المبيعات') : t('حسب صافي الربح')}
                        </Button>
                    ))}
                </div>
            </div>

            <Card className="overflow-x-auto">
                <Table data-testid="branches-table">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الفرع')}</TableHead>
                            <TableHead className="text-end">{t('المبيعات')}</TableHead>
                            <TableHead className="text-end">{t('الطلبات')}</TableHead>
                            <TableHead className="text-end">{t('صافي الإيرادات')}</TableHead>
                            <TableHead className="text-end">{t('صافي الربح')}</TableHead>
                            <TableHead className="text-end">{t('الهامش')}</TableHead>
                            <TableHead className="text-end">{t('كمية المخزون')}</TableHead>
                            <TableHead className="text-end">{t('قيمة المخزون')}</TableHead>
                            <TableHead className="text-end">{t('منخفض المخزون')}</TableHead>
                            <TableHead className="text-end">{t('طلبات تزويد مفتوحة')}</TableHead>
                            <TableHead className="text-end">{t('تحويلات قيد التنفيذ')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sorted.map((r) => (
                            <TableRow key={r.branch_id} data-testid={`branch-row-${r.branch_id}`}>
                                <TableCell>
                                    <SmartLink routeName="admin.reports.profit" href={route('admin.reports.profit', { ...filters, branch_id: String(r.branch_id) })}
                                        className="font-medium text-[#111] hover:underline"
                                    >
                                        {r.branch}
                                    </SmartLink>
                                    {r.branch === summary.best && <Badge variant="success" className="ms-2">{t('الأعلى')}</Badge>}
                                    {r.branch === summary.worst && <Badge variant="warning" className="ms-2">{t('الأقل')}</Badge>}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">{m(r.sales)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.orders)}</TableCell>
                                <TableCell className="text-end tabular-nums">{m(r.net_revenue)}</TableCell>
                                <TableCell className={r.net_profit < 0 ? 'text-end tabular-nums text-[#b91c1c]' : 'text-end tabular-nums'}>
                                    {m(r.net_profit)}
                                </TableCell>
                                <TableCell className="text-end tabular-nums">{pct(r.margin)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.stock_quantity)}</TableCell>
                                <TableCell className="text-end tabular-nums">{m(r.stock_value)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.low_stock)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.open_supply)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.transfers_open)}</TableCell>
                            </TableRow>
                        ))}
                        {unassigned && (
                            <TableRow data-testid="branch-row-unassigned">
                                <TableCell className="text-[#6b7280]">{t('غير منسوب إلى فرع')}</TableCell>
                                <TableCell className="text-end tabular-nums">{m(unassigned.sales)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(unassigned.orders)}</TableCell>
                                <TableCell className="text-end tabular-nums">{m(unassigned.net_revenue)}</TableCell>
                                <TableCell className="text-end text-[#9ca3af]" colSpan={7}>
                                    {t('مجمل الربح')}: {m(unassigned.gross_profit)}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </Card>

            <p className="mt-3 text-[12px] text-[#6b7280]">
                {t('صافي ربح النشاط = مجموع صافي أرباح الفروع + مجمل ربح ما لم يُنسب إلى فرع − المصروفات العامة غير الموزعة. المخزون قيد النقل ليس في أي فرع حتى يُستلم.')}
            </p>
        </ReportScreen>
    );
}

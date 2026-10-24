import { router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import ReportScreen from '@/Components/ReportScreen';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { TRANSFER_STATUS } from '@/lib/branchTransfers';
import type { PageProps } from '@/types';

type Row = Record<string, string | number | null>;

interface Props {
    week: { start: string; end: string };
    summary: {
        sales: number;
        net_profit: number;
        orders: number;
        top_branch: string | null;
        bottom_branch: string | null;
        low_stock: number;
        open_supply: number;
        completed: number;
        late: number;
        discrepancies: number;
        unallocated: number;
    };
    sections: Record<string, Row[]>;
    filters: Record<string, string | null>;
}

/** أعمدةُ كلّ قسم — كما يُصدَّر (`ReportColumns::SECTIONS['branchesWeekly']`) */
const COLUMNS: Record<string, { key: string; label: string; kind?: 'money' | 'number' | 'status' }[]> = {
    الفروع: [
        { key: 'branch', label: 'الفرع' },
        { key: 'sales', label: 'المبيعات', kind: 'money' },
        { key: 'orders', label: 'الطلبات', kind: 'number' },
        { key: 'net_profit', label: 'صافي الربح', kind: 'money' },
        { key: 'low_stock', label: 'منخفض المخزون', kind: 'number' },
    ],
    'الأكثر مبيعًا': [
        { key: 'product', label: 'الصنف' },
        { key: 'sold', label: 'الكمية', kind: 'number' },
        { key: 'revenue', label: 'الإيراد', kind: 'money' },
    ],
    'منخفض المخزون': [
        { key: 'branch', label: 'الفرع' },
        { key: 'product', label: 'الصنف' },
        { key: 'quantity', label: 'الرصيد', kind: 'number' },
        { key: 'alert', label: 'حد التنبيه', kind: 'number' },
    ],
    'طلبات التزويد المفتوحة': [
        { key: 'number', label: 'طلب التزويد' },
        { key: 'branch', label: 'الفرع' },
        { key: 'quantity', label: 'الكمية', kind: 'number' },
        { key: 'requested_at', label: 'التاريخ' },
    ],
    'التحويلات المكتملة': [
        { key: 'number', label: 'السند' },
        { key: 'from', label: 'من' },
        { key: 'to', label: 'إلى' },
        { key: 'quantity', label: 'استُلم', kind: 'number' },
        { key: 'received_at', label: 'وقت الاستلام' },
    ],
    'التحويلات المتأخرة': [
        { key: 'number', label: 'السند' },
        { key: 'from', label: 'من' },
        { key: 'to', label: 'إلى' },
        { key: 'quantity', label: 'قيد النقل', kind: 'number' },
        { key: 'dispatched_at', label: 'وقت الإرسال' },
    ],
    'فروقات الاستلام': [
        { key: 'number', label: 'السند' },
        { key: 'from', label: 'من' },
        { key: 'to', label: 'إلى' },
        { key: 'quantity', label: 'المعلّق', kind: 'number' },
        { key: 'status', label: 'الحالة', kind: 'status' },
    ],
};

/**
 * التقريرُ الأسبوعيّ للفروع — داخل اللوحة، لأيّ أسبوع.
 *
 * يُفتح ويُصدَّر من هنا. ولا يُرسَل بريدًا ولا واتساب: الإرسالُ الدوريّ مرحلةٌ
 * لم تُبنَ بعد، فلا يُوعَد بها.
 */
export default function ReportsBranchesWeekly() {
    const { week, summary, sections, filters, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const shift = (days: number) => {
        const d = new Date(`${week.start}T12:00:00`);
        d.setDate(d.getDate() + days);
        router.get(window.location.pathname, { ...filters, week: d.toISOString().slice(0, 10) }, { preserveScroll: true });
    };

    const stats = [
        { label: t('مبيعات الأسبوع'), value: m(summary.sales), icon: 'shopping-bag', color: 'primary' },
        { label: t('صافي ربح الأسبوع'), value: m(summary.net_profit), icon: 'trending-up', color: summary.net_profit >= 0 ? 'success' : 'danger' },
        { label: t('أعلى فرع مبيعات'), value: summary.top_branch ?? '—', icon: 'star', color: 'success' },
        { label: t('تحويلات متأخرة'), value: number(summary.late), icon: 'clock', color: summary.late > 0 ? 'danger' : 'secondary' },
        { label: t('فروقات لم تُحل'), value: number(summary.discrepancies), icon: 'alert-triangle', color: summary.discrepancies > 0 ? 'danger' : 'secondary' },
    ];

    const cell = (row: Row, col: { key: string; kind?: string }) => {
        const v = row[col.key];
        if (col.kind === 'money') return m(Number(v ?? 0));
        if (col.kind === 'number') return number(Number(v ?? 0));
        if (col.kind === 'status') return t(TRANSFER_STATUS[String(v)]?.label ?? String(v ?? ''));
        return v ?? '—';
    };

    return (
        <ReportScreen
            reportKey="branchesWeekly"
            title="التقرير الأسبوعي للفروع"
            subtitle="ملخّص أسبوعٍ لجميع الفروع: المبيعات والأرباح والأكثر مبيعًا والمخزون المنخفض والتحويلات والفروقات."
            range={null}
            rangeLabel={`${week.start} → ${week.end}`}
            filters={filters}
            stats={stats}
            truncated={null}
        >
            <div className="mb-4 flex flex-wrap items-center gap-2" data-testid="weekly-nav">
                <Button size="sm" variant="outline" onClick={() => shift(-7)}>
                    <ChevronRight />
                    {t('الأسبوع السابق')}
                </Button>
                <span className="text-[13px] tabular-nums text-[#374151]">
                    {week.start} → {week.end}
                </span>
                <Button size="sm" variant="outline" onClick={() => shift(7)}>
                    {t('الأسبوع التالي')}
                    <ChevronLeft />
                </Button>
                <SmartLink routeName="admin.reports.branchesWeekly" href={route('admin.reports.branchesWeekly')} className="text-[13px] underline">
                    {t('هذا الأسبوع')}
                </SmartLink>
            </div>

            {summary.bottom_branch && (
                <p className="mb-4 text-[13px]">
                    {t('أقل فرع أداءً')}: <Badge variant="warning">{summary.bottom_branch}</Badge>
                </p>
            )}

            <div className="space-y-4">
                {Object.entries(COLUMNS).map(([section, cols]) => {
                    const rows = sections[section] ?? [];
                    return (
                        <Card key={section} className="overflow-x-auto" data-testid={`weekly-${section}`}>
                            <h2 className="px-4 pt-4 text-[14px] font-bold text-[#111]">
                                {t(section)} <span className="text-[12px] font-normal text-[#9ca3af]">({number(rows.length)})</span>
                            </h2>
                            {rows.length === 0 ? (
                                <p className="px-4 py-4 text-[13px] text-[#9ca3af]">{t('لا شيء هذا الأسبوع')}</p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            {cols.map((c) => (
                                                <TableHead key={c.key} className={c.kind === 'money' || c.kind === 'number' ? 'text-end' : ''}>
                                                    {t(c.label)}
                                                </TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((row, i) => (
                                            <TableRow key={i}>
                                                {cols.map((c) => (
                                                    <TableCell key={c.key} className={c.kind === 'money' || c.kind === 'number' ? 'text-end tabular-nums' : ''}>
                                                        {cell(row, c)}
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </Card>
                    );
                })}
            </div>
        </ReportScreen>
    );
}

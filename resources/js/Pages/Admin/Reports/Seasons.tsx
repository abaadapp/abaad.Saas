import { Link, usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
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
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { STATUS_TONE, dateRange, type SeasonRow } from '@/Pages/Admin/Seasons/Index';

export interface SeasonReportRow {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string;
    dates: string;
    status: SeasonRow['status'];
    statusLabel: string;
    sales: number;
    cogs: number;
    gross_profit: number;
    margin: number;
    orders: number;
    units: number;
}

interface Props {
    rows: SeasonReportRow[];
    summary: { seasons: number; sold: number; sales: number; gross_profit: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    rangeLabel: string;
}

/**
 * أداءُ المواسم — كلُّ موسمٍ بأرقامه جنبًا إلى جنب.
 *
 * بلا مبدّل فترة: لكلّ موسمٍ مدّتُه، والأرقامُ ممّا نُسب إليه في الصندوق
 * لا ممّا وقع في شهر. والصفُّ يفتح صفحةَ الموسم حيث القنواتُ وأفضلُ منتجاته.
 */
export default function ReportsSeasons() {
    const { rows, summary, filters, options, truncated, context, locale } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [{ kind: 'select', key: 'status', label: 'الحالة', options: options.statuses ?? [] }];

    const stats = [
        { label: t('عدد المواسم'), value: number(summary.seasons), icon: 'layers', color: 'info' },
        { label: t('مواسم لها مبيعات'), value: `${number(summary.sold)} / ${number(summary.seasons)}`, icon: 'shopping-cart', color: 'primary' },
        { label: t('إجمالي المبيعات'), value: m(summary.sales), icon: 'banknote', color: 'success' },
        { label: t('مجمل الربح'), value: m(summary.gross_profit), icon: summary.gross_profit >= 0 ? 'trending-up' : 'trending-down', color: summary.gross_profit >= 0 ? 'success' : 'danger' },
    ];

    return (
        <ReportScreen
            reportKey="seasons"
            title="أداء المواسم"
            subtitle="مبيعات كل موسم وتكلفتها ومجمل ربحها — ممّا نُسب إليه في الصندوق"
            range={null}
            rangeLabel={t('كل المواسم')}
            filters={filters}
            controls={controls}
            stats={stats}
            truncated={truncated}
        >
            <Card className="overflow-hidden">
                <Table data-testid="seasons-report">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الموسم')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead className="text-end">{t('إجمالي المبيعات')}</TableHead>
                            <TableHead className="text-end">{t('تكلفة البضاعة المباعة')}</TableHead>
                            <TableHead className="text-end">{t('مجمل الربح')}</TableHead>
                            <TableHead className="text-end">{t('هامش مجمل الربح')}</TableHead>
                            <TableHead className="text-end">{t('عدد الطلبات')}</TableHead>
                            <TableHead className="text-end">{t('الكمية المباعة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={8}>{t('لا مواسم بعد — أنشئ موسمك الأول من المنتجات.')}</TableEmpty>
                        ) : (
                            rows.map((r) => <SeasonReportLine key={r.id} row={r} locale={locale} money={m} />)
                        )}
                    </TableBody>
                </Table>
            </Card>

            <p className="mt-3 text-[12px] text-[#71717a]">
                {t('تُنسب البيعة للموسم حين يختاره الكاشير في الصندوق. المبالغ بأسعار البنود كما بيعت قبل خصومات الفاتورة، والتكلفة بلقطتها يوم البيع — كما في ربحية المنتجات.')}
            </p>
        </ReportScreen>
    );
}

/** صفُّ الموسم — مُصدَّرٌ ليُقاس وحدَه */
export function SeasonReportLine({ row, locale, money: m }: { row: SeasonReportRow; locale: string; money: (v: number) => string }) {
    const t = useTranslate();

    return (
        <TableRow>
            <TableCell>
                <Link href={route('admin.seasons.show', row.id)} className="font-medium text-[#111] hover:underline">
                    {row.name}
                </Link>
                <span className="block text-[11.5px] text-[#9ca3af]">{dateRange(row.starts_at, row.ends_at, locale)}</span>
            </TableCell>
            <TableCell>
                <Badge variant={STATUS_TONE[row.status]}>{t(row.statusLabel)}</Badge>
            </TableCell>
            <TableCell className="text-end tabular-nums">{m(row.sales)}</TableCell>
            <TableCell className="text-end tabular-nums">{m(row.cogs)}</TableCell>
            <TableCell className={cn('text-end tabular-nums', row.gross_profit < 0 && 'text-[#b91c1c]')}>{m(row.gross_profit)}</TableCell>
            <TableCell className="text-end tabular-nums">{number(row.margin, 1)}%</TableCell>
            <TableCell className="text-end tabular-nums">{number(row.orders)}</TableCell>
            <TableCell className="text-end tabular-nums">{number(row.units)}</TableCell>
        </TableRow>
    );
}

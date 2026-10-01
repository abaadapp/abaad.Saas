import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
import { type ReportRange } from '@/Components/RangeTabs';
import MultiLineChart from '@/Components/charts/MultiLineChart';
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
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** أرقامُ فترة — كما يبنيها `Profitability::derive` */
export interface ProfitFigures {
    sales: number;
    tax: number;
    net_revenue: number;
    cogs: number;
    gross_profit: number;
    expenses: number;
    net_profit: number;
    /** `null` حين لا إيرادَ موجب — لا نسبةَ من صفر */
    margin: number | null;
}

export interface ProfitRow extends ProfitFigures {
    key: string;
    period: string;
}

interface Comparison {
    key: keyof ProfitFigures;
    current: number | null;
    previous: number | null;
    diff: number | null;
}

interface Props {
    summary: ProfitFigures & { scope_name: string; unallocated?: number; unallocated_count: number };
    unallocated: { amount: number; count: number } | null;
    scope: { kind: 'business' | 'branch'; branch_id: number | null; name: string };
    comparison: Comparison[] | null;
    series: { labels: string[]; net_revenue: number[]; gross_profit: number[]; net_profit: number[] };
    rows: ProfitRow[];
    filters: Record<string, string | null>;
    options: { branches: Option[] };
    truncated: null;
    range: ReportRange;
    rangeLabel: string;
}

const COMPARE_LABELS: Record<string, string> = {
    net_revenue: 'صافي الإيرادات',
    cogs: 'تكلفة البضاعة المباعة',
    gross_profit: 'مجمل الربح',
    expenses: 'المصروفات',
    net_profit: 'صافي الربح',
    margin: 'هامش صافي الربح',
};

/**
 * صافي الربح — للنشاط كلِّه أو لفرعٍ بعينه.
 *
 * ═══ والشاشةُ لا تحسب ═══
 *
 * كلُّ رقمٍ هنا من الخادم (`ReportData::profit`): الجمعُ والطرحُ والهامشُ
 * والفرقُ عن الفترة السابقة وجملةُ الجدول. والشاشةُ تنسّق وتعرض — ورقمٌ
 * يُحسب في موضعين يفترق يومًا عن ملفّه المصدَّر.
 *
 * ═══ وربحُ الفرع لا يدّعي ما لم يطرحه ═══
 *
 * يُطرح منه ما نُسب إليه وحده. والمصروفُ العامّ غيرُ الموزّع يُقال بجانبه
 * بمبلغه — لا يُطرح، ولا يُسكت عنه.
 */
export default function ReportsProfit() {
    const { summary, unallocated, scope, comparison, series, rows, filters, options, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const pct = (v: number | null) => (v === null ? '—' : `${v.toFixed(1)}%`);
    const branch = scope.kind === 'branch';
    const overhead = branch && unallocated !== null && unallocated.amount > 0 ? unallocated : null;

    const controls: Filter[] = [
        { kind: 'select', key: 'branch_id', label: 'الفرع', options: options.branches ?? [], placeholder: 'كل الفروع' },
    ];

    const stats = [
        {
            label: t('صافي الربح'),
            value: m(summary.net_profit),
            icon: summary.net_profit >= 0 ? 'trending-up' : 'trending-down',
            color: summary.net_profit >= 0 ? 'success' : 'danger',
        },
        { label: t('هامش صافي الربح'), value: pct(summary.margin), icon: 'percent', color: 'info' },
        { label: t('صافي الإيرادات'), value: m(summary.net_revenue), icon: 'wallet', color: 'primary' },
        { label: t('مجمل الربح'), value: m(summary.gross_profit), icon: 'coins', color: 'success' },
        { label: t('إجمالي المبيعات'), value: m(summary.sales), icon: 'shopping-bag', color: 'primary' },
        { label: t('الضريبة المحصلة'), value: m(summary.tax), icon: 'receipt', color: 'secondary' },
        { label: t('تكلفة البضاعة المباعة'), value: m(summary.cogs), icon: 'package', color: 'warning' },
        {
            label: branch ? t('مصروفات الفرع') : t('المصروفات'),
            value: m(summary.expenses),
            icon: 'arrow-down-circle',
            color: 'danger',
        },
        ...(overhead
            ? [{ label: t('المصروفات العامة غير الموزعة'), value: m(overhead.amount), icon: 'alert-triangle', color: 'warning' }]
            : []),
    ];

    const formula = branch
        ? [t('مبيعات الفرع'), t('ضريبة الفرع'), t('تكلفة البضاعة المباعة للفرع'), t('مصروفات الفرع المنسوبة إليه'), t('صافي ربح الفرع')]
        : [t('المبيعات'), t('الضريبة'), t('تكلفة البضاعة المباعة'), t('المصروفات المدفوعة'), t('صافي الربح')];

    return (
        <ReportScreen
            reportKey="profit"
            title="صافي الربح"
            subtitle="الإيرادات والتكلفة والمصروفات وصافي الربح وهامش الربح للنشاط أو الفرع خلال الفترة المختارة."
            range={range}
            rangeLabel={`${rangeLabel} — ${scope.name}`}
            filters={filters}
            controls={controls}
            stats={stats}
            truncated={null}
        >
            {/* كيف حُسب — من الخادم أسماؤه، ولا رقمَ يُحسب هنا */}
            <Card className="mb-6 p-4" data-testid="profit-formula">
                <h2 className="mb-2 text-[14px] font-bold text-[#111]">{t('كيف حُسب صافي الربح؟')}</h2>
                <p className="text-[13px] leading-loose text-[#374151]">
                    {formula.slice(0, 4).map((part, i) => (
                        <span key={part}>
                            {i > 0 && <span className="mx-1.5 text-[#9ca3af]">−</span>}
                            {part}
                        </span>
                    ))}
                    <span className="mx-1.5 text-[#9ca3af]">=</span>
                    <strong className="text-[#111]">{formula[4]}</strong>
                </p>
                {overhead && (
                    <p
                        data-testid="profit-unallocated-note"
                        className="mt-3 flex items-start gap-2 rounded-lg bg-[#fffbeb] px-3 py-2 text-[12.5px] leading-relaxed text-[#92400e]"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>
                            {t('صافي ربح الفرع يخصم المصروفات المخصصة لهذا الفرع فقط. توجد مصروفات عامة غير موزعة على الفروع بقيمة :amount.', {
                                amount: m(overhead.amount),
                            })}
                        </span>
                    </p>
                )}
            </Card>

            <Card className="mb-6 p-4">
                <h2 className="mb-3 text-[14px] font-bold text-[#111]">{t('صافي الربح عبر الزمن')}</h2>
                <MultiLineChart
                    labels={series.labels}
                    format={m}
                    series={[
                        { key: 'net_revenue', label: t('صافي الإيرادات'), data: series.net_revenue },
                        { key: 'gross_profit', label: t('مجمل الربح'), data: series.gross_profit },
                        { key: 'net_profit', label: t('صافي الربح'), data: series.net_profit },
                    ]}
                />
            </Card>

            {comparison && (
                <Card className="mb-6 overflow-hidden" data-testid="profit-comparison">
                    <h2 className="px-4 pt-4 text-[14px] font-bold text-[#111]">{t('مقارنة بالفترة السابقة')}</h2>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('البند')}</TableHead>
                                <TableHead className="text-end">{t('هذه الفترة')}</TableHead>
                                <TableHead className="text-end">{t('الفترة السابقة')}</TableHead>
                                <TableHead className="text-end">{t('الفرق')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {comparison.map((c) => {
                                const isMargin = c.key === 'margin';
                                const show = (v: number | null) => (isMargin ? pct(v) : v === null ? '—' : m(v));

                                return (
                                    <TableRow key={c.key}>
                                        <TableCell>{t(COMPARE_LABELS[c.key] ?? c.key)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{show(c.current)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{show(c.previous)}</TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-end tabular-nums',
                                                c.diff !== null && c.diff < 0 && 'text-[#b91c1c]',
                                            )}
                                        >
                                            {c.diff === null
                                                ? '—'
                                                : isMargin
                                                  ? `${c.diff > 0 ? '+' : ''}${c.diff.toFixed(1)} ${t('نقطة مئوية')}`
                                                  : `${c.diff > 0 ? '+' : ''}${m(c.diff)}`}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>
            )}

            <Card className="overflow-x-auto">
                <Table data-testid="profit-rows">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الفترة')}</TableHead>
                            <TableHead className="text-end">{t('إجمالي المبيعات')}</TableHead>
                            <TableHead className="text-end">{t('الضريبة المحصلة')}</TableHead>
                            <TableHead className="text-end">{t('صافي الإيرادات')}</TableHead>
                            <TableHead className="text-end">{t('تكلفة البضاعة المباعة')}</TableHead>
                            <TableHead className="text-end">{t('مجمل الربح')}</TableHead>
                            <TableHead className="text-end">{t('المصروفات')}</TableHead>
                            <TableHead className="text-end">{t('صافي الربح')}</TableHead>
                            <TableHead className="text-end">{t('هامش صافي الربح')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={9}>{t('لا حركة في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => <ProfitLine key={r.key} row={r} money={m} pct={pct} />)
                        )}
                        {/* والجملةُ جملةُ الملخّص من الخادم — لا مجموعُ أعمدةٍ في المتصفّح، والهامشُ مشتقّ لا مجموع */}
                        {rows.length > 0 && (
                            <ProfitLine row={{ ...summary, key: 'total', period: t('الإجمالي') }} money={m} pct={pct} strong />
                        )}
                    </TableBody>
                </Table>
            </Card>

            <p className="mt-3 text-[12px] leading-relaxed text-[#71717a]">
                {branch
                    ? t('مصروفات الفرع ما سُجّل عليه مباشرةً وحصّته من المصروفات الموزّعة. المصروفات المدفوعة وحدها تُخصم، وتكلفة البضاعة بلقطتها يوم البيع.')
                    : t('المصروفات المدفوعة وحدها تُخصم، وكلّ مصروفٍ مرّةً واحدة. وتكلفة البضاعة بلقطتها يوم البيع لا بتكلفة اليوم.')}
            </p>
        </ReportScreen>
    );
}

/** صفُّ فترة — أو صفُّ الإجمالي */
export function ProfitLine({
    row,
    money: m,
    pct,
    strong = false,
}: {
    row: ProfitRow;
    money: (v: number) => string;
    pct: (v: number | null) => string;
    strong?: boolean;
}) {
    const cell = (v: number, negative = false) => (
        <TableCell className={cn('text-end tabular-nums', strong && 'font-bold', negative && v < 0 && 'text-[#b91c1c]')}>
            {m(v)}
        </TableCell>
    );

    return (
        <TableRow>
            <TableCell className={cn(strong && 'font-bold')}>{row.period}</TableCell>
            {cell(row.sales)}
            {cell(row.tax)}
            {cell(row.net_revenue)}
            {cell(row.cogs)}
            {cell(row.gross_profit, true)}
            {cell(row.expenses)}
            {cell(row.net_profit, true)}
            <TableCell className={cn('text-end tabular-nums', strong && 'font-bold')}>{pct(row.margin)}</TableCell>
        </TableRow>
    );
}

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
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

export interface AddonReportRow {
    id: string;
    addon_id: number | null;
    name: string;
    /** مجموعٌ بهويّة الإضافة — أو باسمها يومَ البيع وحده */
    linked: boolean;
    orders: number;
    uses: number;
    quantity: number;
    standalone_quantity: number;
    revenue: number;
    cost: number;
    profit: number;
    average: number;
}

export interface AddonReportSummary {
    uses: number;
    quantity: number;
    orders: number;
    revenue: number;
    cost: number;
    profit: number;
    unlinked: number;
    /** إجمالي مبيعات الطلبات نفسِها — الإضافاتُ جزءٌ منه */
    sales: number;
}

interface Props {
    rows: AddonReportRow[];
    summary: AddonReportSummary;
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * الإضافات — ما بيع منها، من لقطة البيع.
 *
 * ═══ وأثقلُ ما تقوله الصفحة ═══
 *
 * أنّ قيمتها **داخل** إجمالي المبيعات لا فوقه: ثمنُ الشوكولاتة دُفع مع
 * البوكيه في فاتورةٍ واحدة، وهو في `orders.total` منذ تلك اللحظة. فتُسمّى
 * البطاقةُ بذلك، ويُقال تحتها كم هي من إجمالي الطلبات نفسِها.
 */
export default function ReportsAddons() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'branch_id', label: 'الفرع', options: options.branches ?? [] },
        { kind: 'select', key: 'channel', label: 'القناة', options: options.channels ?? [] },
    ];

    const stats = [
        { label: t('مرات استخدام الإضافات'), value: number(summary.uses), icon: 'layers', color: 'info' },
        { label: t('طلبات فيها إضافات'), value: number(summary.orders), icon: 'shopping-cart', color: 'primary' },
        { label: t('قيمة الإضافات — ضمن إجمالي المبيعات'), value: m(summary.revenue), icon: 'wallet', color: 'success' },
        {
            label: t('ربح الإضافات'),
            value: m(summary.profit),
            icon: summary.profit >= 0 ? 'trending-up' : 'trending-down',
            color: summary.profit >= 0 ? 'success' : 'danger',
        },
    ];

    return (
        <ReportScreen
            reportKey="addons"
            title="الإضافات"
            subtitle="ما بيع من الإضافات وقيمتُه وتكلفتُه وربحُه — جزءٌ من المبيعات لا فوقها"
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            controls={controls}
            stats={stats}
            truncated={truncated}
        >
            <p data-testid="addons-share" className="mb-3 text-[12.5px] text-[#4b5563]">
                {t('قيمة الإضافات :addons من إجمالي مبيعات الطلبات نفسِها :sales — داخلةٌ فيه لا مضافةٌ إليه.', {
                    addons: m(summary.revenue),
                    sales: m(summary.sales),
                })}
            </p>

            <Card className="overflow-hidden">
                <Table data-testid="addons-report">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الإضافة')}</TableHead>
                            <TableHead className="text-end">{t('عدد الطلبات')}</TableHead>
                            <TableHead className="text-end">{t('مرات الاستخدام')}</TableHead>
                            <TableHead className="text-end">{t('الكمية')}</TableHead>
                            <TableHead className="text-end">{t('قيمة المبيعات')}</TableHead>
                            <TableHead className="text-end">{t('التكلفة')}</TableHead>
                            <TableHead className="text-end">{t('الربح')}</TableHead>
                            <TableHead className="text-end">{t('متوسّط سعر الوحدة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={8}>{t('لم تُبع إضافاتٌ في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => <AddonReportLine key={r.id} row={r} money={m} />)
                        )}
                    </TableBody>
                </Table>
            </Card>

            <p className="mt-3 text-[12px] leading-relaxed text-[#71717a]">
                {t('من الطلبات غير الملغاة وحدها. القيمة بأسعار الإضافات كما بيعت قبل خصم الفاتورة، والتكلفة بلقطتها يوم البيع — فلا يتحرّك الماضي إن تغيّر اسم إضافةٍ أو سعرها أو حُذفت.')}
            </p>
            {summary.unlinked > 0 && (
                <p data-testid="addons-unlinked-note" className="mt-1 text-[12px] leading-relaxed text-[#92400e]">
                    {t('صفوفٌ موسومة «باسمها يوم البيع» (:value): مبيعاتٌ لا تحمل معرّف إضافة — إضافةٌ حُذفت بعد بيعها، أو بندٌ مستقلّ قديم — فتُجمع باسمها كما بيعت ولا تُنسب إلى إضافةٍ حاليّة بالظنّ.', {
                        value: m(summary.unlinked),
                    })}
                </p>
            )}
        </ReportScreen>
    );
}

/** صفُّ الإضافة — مُصدَّرٌ ليُقاس وحدَه */
export function AddonReportLine({ row, money: m }: { row: AddonReportRow; money: (v: number) => string }) {
    const t = useTranslate();

    return (
        <TableRow>
            <TableCell>
                <span className="font-medium text-[#111]">{row.name}</span>
                {!row.linked && (
                    <Badge variant="warning" className="ms-2">
                        {t('باسمها يوم البيع')}
                    </Badge>
                )}
            </TableCell>
            <TableCell className="text-end tabular-nums">{number(row.orders)}</TableCell>
            <TableCell className="text-end tabular-nums">{number(row.uses)}</TableCell>
            <TableCell className="text-end tabular-nums">{number(row.quantity)}</TableCell>
            <TableCell className="text-end tabular-nums">{m(row.revenue)}</TableCell>
            <TableCell className="text-end tabular-nums">{m(row.cost)}</TableCell>
            <TableCell className={cn('text-end tabular-nums', row.profit < 0 && 'text-[#b91c1c]')}>{m(row.profit)}</TableCell>
            <TableCell className="text-end tabular-nums">{m(row.average)}</TableCell>
        </TableRow>
    );
}

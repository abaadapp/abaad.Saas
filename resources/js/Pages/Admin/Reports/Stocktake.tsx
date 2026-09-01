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

interface Row {
    id: number;
    number: string;
    product: string | null;
    branch: string | null;
    reason: string;
    delta: number;
    cost: number;
    value: number;
    notes: string | null;
    at: string | null;
}

interface Props {
    rows: Row[];
    summary: { operations: number; items: number; shortage: number; surplus: number; net: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * عمليات جرد المخزون — ما عُدّ، وأين فارق الدفترُ الواقع.
 *
 * وشاشةُ الجرد تُجري العمليّة ولا تحفظ لها أثرًا يُقرأ: من طبّق جردًا أمس
 * لا يجد اليوم ما يقول كم صنفًا اختلف ولا بكم كلّف الفرق. والأثرُ موجودٌ
 * في صفوف تعديل المخزون، لكنّه مبعثرٌ بين التلف والفقد والإهداء.
 *
 * وهذا التقرير يعزل الجرد وحده: «جرد» زيادةٌ كشفها العدّ، و«فاقد جرد» نقصٌ
 * كشفه — وهما ما يكتبه تطبيق الجرد لا سواه.
 */
export default function ReportsStocktake() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'branch_id', label: 'الفرع', options: options.branches ?? [] },
        { kind: 'select', key: 'reason', label: 'النتيجة', options: options.reasons ?? [] },
    ];

    const stats = [
        { label: t('عمليات الجرد'), value: number(summary.operations), icon: 'clipboard-list', color: 'info' },
        { label: t('أصناف اختلفت'), value: number(summary.items), icon: 'package', color: 'primary' },
        { label: t('قيمة النقص'), value: m(summary.shortage), icon: 'trending-down', color: summary.shortage > 0 ? 'danger' : 'success' },
        {
            /*
             * الصافي لا النقصُ وحده: جردٌ نقصُه يوازي زيادتَه دفترٌ مضطرب لا
             * خسارة — والتاجر يحتاج أن يفرّق بين الاثنين قبل أن يتّهم أحدًا.
             */
            label: t('صافي الفرق'),
            value: m(summary.net),
            icon: summary.net >= 0 ? 'trending-up' : 'trending-down',
            color: summary.net >= 0 ? 'success' : 'danger',
        },
    ];

    return (
        <ReportScreen
            title="عمليات جرد المخزون"
            subtitle="ما عُدّ وأين فارق الدفترُ الواقع: النقص والزيادة وقيمتهما لكل فرع"
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
                            <TableHead>{t('الوقت')}</TableHead>
                            <TableHead>{t('السند')}</TableHead>
                            <TableHead>{t('الصنف')}</TableHead>
                            <TableHead>{t('الفرع')}</TableHead>
                            <TableHead>{t('النتيجة')}</TableHead>
                            <TableHead className="text-end">{t('الفرق')}</TableHead>
                            <TableHead className="text-end">{t('التكلفة')}</TableHead>
                            <TableHead className="text-end">{t('القيمة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={8}>{t('لا عمليات جرد في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.number}</TableCell>
                                    <TableCell className="font-medium text-[#111]" title={r.notes ?? undefined}>
                                        {r.product ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-[#6b7280]">{r.branch ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge status={r.reason} />
                                    </TableCell>
                                    {/* الإشارة تُكتب صراحةً: «٣» و«٣-» يقرآن سواءً في عمودٍ مرقَّم */}
                                    <TableCell className={cn('text-end tabular-nums font-medium', r.delta < 0 ? 'text-[#b91c1c]' : 'text-[#047857]')}>
                                        {r.delta > 0 ? '+' : ''}
                                        {number(r.delta)}
                                    </TableCell>
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

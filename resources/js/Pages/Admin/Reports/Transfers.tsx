import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
import { type ReportRange } from '@/Components/RangeTabs';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { TRANSFER_STATUS } from '@/lib/branchTransfers';
import type { PageProps } from '@/types';

interface Row {
    number: string;
    at: string | null;
    from: string;
    to: string;
    product: string;
    requested: number;
    sent: number;
    received: number;
    outstanding: number;
    lost: number;
    returned: number;
    late: number;
    status: string;
    requested_by: string | null;
    approved_by: string | null;
    dispatched_by: string | null;
    received_by: string | null;
    dispatched_at: string | null;
    received_at: string | null;
}

interface Props {
    rows: Row[];
    truncated: { shown: number; total: number } | null;
    summary: { transfers: number; lines: number; sent: number; received: number; outstanding: number; lost: number };
    options: { branches: Option[] };
    filters: Record<string, string | null>;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * سجلُّ نقل المخزون — كلُّ سطرٍ في كلّ سند خلال الفترة، ومن فعل كلَّ مرحلة.
 *
 * والسجلُّ يُقرأ ولا يُعدَّل: السندُ المكتمل لا يُكتب فوقه، وتسويتُه سطرٌ في
 * سجلّ مراحله. وسِجلُّ النقل القديم (الفوريّ) معه كما وقع.
 */
export default function ReportsTransfers() {
    const { rows, truncated, summary, options, filters, range, rangeLabel } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const controls: Filter[] = [
        { kind: 'select', key: 'branch_id', label: 'الفرع', options: options.branches ?? [], placeholder: 'كل الفروع' },
        {
            kind: 'select',
            key: 'status',
            label: 'الحالة',
            options: Object.entries(TRANSFER_STATUS).map(([value, s]) => ({ value, label: s.label })),
            placeholder: 'كل الحالات',
        },
    ];

    const stats = [
        { label: t('عدد السندات'), value: number(summary.transfers), icon: 'file-text', color: 'primary' },
        { label: t('الكمية المرسلة'), value: number(summary.sent), icon: 'truck', color: 'info' },
        { label: t('الكمية المستلمة'), value: number(summary.received), icon: 'package-check', color: 'success' },
        { label: t('فروقات معلّقة'), value: number(summary.outstanding), icon: 'alert-triangle', color: summary.outstanding > 0 ? 'danger' : 'secondary' },
    ];

    return (
        <ReportScreen
            reportKey="transfers"
            title="سجل نقل المخزون"
            subtitle="سندات النقل بين الفروع خلال الفترة: الكميات المرسلة والمستلمة والفروقات ومن نفّذ كل مرحلة."
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            controls={controls}
            stats={stats}
            truncated={truncated}
        >
            <Card className="overflow-x-auto no-scrollbar">
                <Table data-testid="transfers-log">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('السند')}</TableHead>
                            <TableHead>{t('من ← إلى')}</TableHead>
                            <TableHead>{t('الصنف')}</TableHead>
                            <TableHead className="text-end">{t('المرسل')}</TableHead>
                            <TableHead className="text-end">{t('استُلم')}</TableHead>
                            <TableHead className="text-end">{t('فرق معلّق')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead>{t('المراحل')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 && <TableEmpty colSpan={8}>{t('لا تحويلات في هذه الفترة')}</TableEmpty>}
                        {rows.map((r, i) => (
                            <TableRow key={`${r.number}-${i}`}>
                                <TableCell>
                                    <span className="block font-medium">{r.number}</span>
                                    <span className="text-[11px] tabular-nums text-[#9ca3af]">{r.at}</span>
                                </TableCell>
                                <TableCell className="text-[13px]">
                                    {r.from} ← {r.to}
                                </TableCell>
                                <TableCell className="text-[13px]">{r.product}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.sent)}</TableCell>
                                <TableCell className="text-end tabular-nums">{number(r.received)}</TableCell>
                                <TableCell className={r.outstanding > 0 ? 'text-end font-semibold tabular-nums text-[#b91c1c]' : 'text-end tabular-nums'}>
                                    {number(r.outstanding)}
                                    {(r.lost > 0 || r.returned > 0 || r.late > 0) && (
                                        <span className="block text-[11px] font-normal text-[#6b7280]">
                                            {r.lost > 0 && `${t('فُقد')} ${r.lost} `}
                                            {r.returned > 0 && `${t('أُعيد')} ${r.returned} `}
                                            {r.late > 0 && `${t('متأخر')} ${r.late}`}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Badge variant={TRANSFER_STATUS[r.status]?.tone ?? 'neutral'}>{t(TRANSFER_STATUS[r.status]?.label ?? r.status)}</Badge>
                                </TableCell>
                                <TableCell className="text-[12px] text-[#374151]">
                                    {r.requested_by && <span className="block">{t('طلبه')}: {r.requested_by}</span>}
                                    {r.approved_by && <span className="block">{t('اعتمده')}: {r.approved_by}</span>}
                                    {r.dispatched_by && <span className="block">{t('أرسله')}: {r.dispatched_by} <span className="tabular-nums text-[#9ca3af]">{r.dispatched_at}</span></span>}
                                    {r.received_by && <span className="block">{t('استلمه')}: {r.received_by} <span className="tabular-nums text-[#9ca3af]">{r.received_at}</span></span>}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

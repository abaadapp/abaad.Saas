import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
import { type ReportTab } from '@/Components/ReportTabs';
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
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    user: string | null;
    action: string;
    description: string | null;
    at: string | null;
}

interface Props {
    rows: Row[];
    summary: { count: number; users: number; topAction: string | null; topCount: number };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
    tabs: ReportTab[];
}

/**
 * سجل النشاط — من فعل ماذا.
 *
 * وشاشتُه كانت قائمةً بلا مؤشّرات: من دخل يسأل «من يعدّل الأسعار كثيرًا»
 * قرأ خمسمئة سطرٍ ليعدّها بنفسه.
 */
export default function ReportsActivity() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, tabs } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const controls: Filter[] = [
        { kind: 'select', key: 'user_id', label: 'المستخدم', options: options.users ?? [] },
        { kind: 'select', key: 'action', label: 'الإجراء', options: options.actions ?? [] },
    ];

    const stats = [
        { label: t('عدد الأحداث'), value: number(summary.count), icon: 'history', color: 'info' },
        { label: t('المستخدمون'), value: number(summary.users), icon: 'users', color: 'primary' },
        { label: t('أكثر إجراء'), value: summary.topAction ? `${summary.topAction} · ${number(summary.topCount)}` : '—', icon: 'activity', color: 'warning' },
    ];

    return (
        <ReportScreen
            title="سجل النشاط"
            subtitle="من فعل ماذا ومتى على النظام"
            reportKey="activity"
            tabs={tabs}
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
                            <TableHead>{t('المستخدم')}</TableHead>
                            <TableHead>{t('الإجراء')}</TableHead>
                            <TableHead>{t('التفصيل')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={4}>{t('لا نشاط في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.user ?? '—'}</TableCell>
                                    <TableCell>{r.action ? <Badge status={r.action} /> : null}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.description ?? '—'}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}

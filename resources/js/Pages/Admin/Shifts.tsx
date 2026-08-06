import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column } from '@/Components/DataTable';
import StatCard from '@/Components/StatCard';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    branch: string;
    opened_at: string | null;
    closed_at: string | null;
    opened_by: string;
    closed_by: string;
    opening: number;
    cash: number;
    expected: number;
    counted: number | null;
    difference: number | null;
    note: string | null;
    status: string;
}

export default function AdminShifts() {
    const { shifts, context } = usePage<PageProps<{ shifts: Row[]; openShiftId: number | null }>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const closed = shifts.filter((s) => s.difference !== null);
    const short = closed.filter((s) => (s.difference ?? 0) < 0);
    const totalGap = closed.reduce((sum, s) => sum + (s.difference ?? 0), 0);

    const columns: Column<Row>[] = [
        { key: 'opened_at', header: 'فُتحت', sortable: true, value: (s) => s.opened_at ?? '', cell: (s) => s.opened_at ?? '—' },
        { key: 'branch', header: 'الفرع', cell: (s) => s.branch },
        { key: 'opened_by', header: 'فتحها', cell: (s) => s.opened_by },
        { key: 'closed_by', header: 'أقفلها', cell: (s) => s.closed_by },
        { key: 'opening', header: 'الابتدائي', align: 'end', cell: (s) => <span className="tabular-nums">{m(s.opening)}</span> },
        { key: 'cash', header: 'مبيعات نقدية', align: 'end', cell: (s) => <span className="tabular-nums">{m(s.cash)}</span> },
        { key: 'expected', header: 'المتوقّع', align: 'end', cell: (s) => <span className="tabular-nums">{m(s.expected)}</span> },
        {
            key: 'counted',
            header: 'المعدود',
            align: 'end',
            cell: (s) => (s.counted === null ? '—' : <span className="tabular-nums">{m(s.counted)}</span>),
        },
        {
            key: 'difference',
            header: 'الفرق',
            align: 'end',
            sortable: true,
            value: (s) => s.difference ?? 0,
            cell: (s) =>
                s.difference === null ? (
                    <Badge variant="info">{t('مفتوحة')}</Badge>
                ) : (
                    <span
                        className={cn(
                            'tabular-nums font-medium',
                            s.difference < 0 ? 'text-[#b91c1c]' : s.difference > 0 ? 'text-[#d97706]' : 'text-[#047857]',
                        )}
                    >
                        {s.difference > 0 ? '+' : ''}
                        {m(s.difference)}
                    </span>
                ),
        },
        { key: 'note', header: 'ملاحظة', cell: (s) => s.note || '—' },
    ];

    return (
        <AdminLayout title="الورديات">
            <PageHeader
                title="الورديات"
                subtitle={t('حصيلة كل درج: ما توقّعه النظام، وما وُجد فيه فعلًا')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المالية', href: route('admin.finance.index') },
                    { label: 'الورديات' },
                ]}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.shifts.index" variant="segmented" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard stat={{ label: 'ورديات مُقفلة', value: number(closed.length), icon: 'clipboard-list', color: 'primary' }} />
                <StatCard
                    stat={{
                        label: 'ورديات بنقص',
                        value: number(short.length),
                        icon: 'alert-triangle',
                        color: short.length ? 'danger' : 'success',
                    }}
                />
                {/* المجموع لا المتوسّط: نقصٌ وزيادةٌ يتعادلان في المتوسّط فيبدو الصندوق سليمًا */}
                <StatCard
                    stat={{
                        label: 'صافي الفروق',
                        value: m(totalGap),
                        icon: 'wallet',
                        color: totalGap < 0 ? 'danger' : 'success',
                    }}
                />
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={shifts}
                    columns={columns}
                    rowKey={(s) => s.id}
                    searchPlaceholder="ابحث بالفرع أو الموظف…"
                    searchable={(s) => `${s.branch} ${s.opened_by} ${s.closed_by} ${s.note ?? ''}`}
                    empty="لا ورديات بعد"
                />
            </Card>
        </AdminLayout>
    );
}

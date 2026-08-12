import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, Users, Wallet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import DataTable, { type Column } from '@/Components/DataTable';
import { money as fmtMoney, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    name: string;
    balance: number;
    invoices: number;
    oldest: string | null;
    days: number;
    overdue: boolean;
}

interface Props {
    customers: Row[];
    summary: { total: number; customers: number; overdue: number };
}

/**
 * من عليه لي — الشاشة التي كانت دفترًا على الورق.
 *
 * كلّ بيعةٍ كانت تُكتب «مدفوع» مثبّتًا في الكود، فبائع الجملة الذي يبيع
 * لمقاولٍ أو مطعمٍ بالآجل يمسك دفترًا بجانب نظامه — يبيع في النظام ويحصّل
 * خارجه. وعمرُ الدَّين هنا أهمّ من مبلغه: مئةٌ عمرها تسعون يومًا أخطر من
 * ألفٍ عمرها أسبوع.
 */
export default function Receivables() {
    const { customers, summary, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => fmtMoney(v, context!.currency);

    const columns: Column<Row>[] = [
        {
            key: 'name',
            header: 'العميل',
            cell: (r) => (
                <Link
                    href={route('admin.receivables.show', r.id)}
                    className="font-medium text-[#111] hover:underline"
                >
                    {r.name}
                </Link>
            ),
        },
        {
            key: 'balance',
            header: 'الرصيد المستحق',
            cell: (r) => <span className="font-bold tabular-nums text-[#111]">{m(r.balance)}</span>,
            value: (r) => r.balance,
            sortable: true,
        },
        { key: 'invoices', header: 'فواتير', cell: (r) => number(r.invoices), value: (r) => r.invoices },
        {
            key: 'days',
            header: 'عمر أقدم دَين',
            cell: (r) => (
                <span className={r.days >= 60 ? 'font-bold text-[#b91c1c]' : 'text-[#4b4b4b]'}>
                    {t(':n يوم', { n: number(r.days) })}
                </span>
            ),
            value: (r) => r.days,
            sortable: true,
        },
        {
            key: 'overdue',
            header: 'الحالة',
            cell: (r) =>
                r.overdue ? (
                    <Badge variant="danger">{t('تجاوز الموعد')}</Badge>
                ) : (
                    <Badge variant="info">{t('قائم')}</Badge>
                ),
        },
        {
            key: 'go',
            header: '',
            cell: (r) => (
                <Link href={route('admin.receivables.show', r.id)} className="text-[#9ca3af]">
                    <ChevronLeft className="size-4" />
                </Link>
            ),
        },
    ];

    const cards = [
        { label: 'إجمالي المستحق', value: m(summary.total), icon: Wallet, tone: 'text-[#111]' },
        { label: 'عملاء عليهم دَين', value: number(summary.customers), icon: Users, tone: 'text-[#111]' },
        { label: 'تجاوز موعده', value: m(summary.overdue), icon: AlertTriangle, tone: 'text-[#b91c1c]' },
    ];

    return (
        <AdminLayout title="الذمم">
            <PageHeader
                title="الذمم"
                subtitle={t('ما لك عند عملائك — وعمرُ كلّ دَين')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المالية', href: route('admin.finance.index') },
                    { label: 'الذمم' },
                ]}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.receivables.index" variant="segmented" />

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {cards.map((c) => {
                    const Icon = c.icon;

                    return (
                        <Card key={c.label} className="flex items-center gap-3 p-4">
                            <span className="flex size-10 items-center justify-center rounded-[12px] bg-[#fafafa] text-[#6b7280]">
                                <Icon className="size-5" />
                            </span>
                            <span>
                                <p className={`text-[18px] font-bold tabular-nums ${c.tone}`}>{c.value}</p>
                                <p className="text-[12px] text-[#9ca3af]">{t(c.label)}</p>
                            </span>
                        </Card>
                    );
                })}
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={customers}
                    columns={columns}
                    rowKey={(r) => r.id}
                    searchPlaceholder="ابحث باسم العميل…"
                    searchable={(r) => r.name}
                    empty="لا ديون على أحد — كل الفواتير مسدَّدة"
                />
            </Card>
        </AdminLayout>
    );
}

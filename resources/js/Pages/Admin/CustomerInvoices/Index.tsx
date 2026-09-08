import { Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    /** فارغٌ للمسودّة: الرقمُ يُقطع عند الإصدار */
    number: string | null;
    customer: string;
    customer_id: number | null;
    status: string;
    state: string;
    issued_at: string | null;
    due_at: string | null;
    total: number;
    paid: number;
    outstanding: number;
    days_overdue: number;
    po_number: string | null;
}

interface Props {
    invoices: Row[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    states: string[];
    statuses: string[];
    customers: { id: number; name: string }[];
    totals: { total: number; overdue: number; due_soon: number; credit: number; invoices: number; customers: number };
}

/** حالُ السداد لونًا — والمتأخّرةُ وحدها حمراء */
const tone = (state: string) =>
    state === 'متأخرة' ? 'bg-[#fef2f2] text-[#b91c1c]'
    : state === 'مدفوعة' ? 'bg-[#ecfdf5] text-[#047857]'
    : state === 'ملغاة' ? 'bg-[#f4f4f5] text-[#71717a]'
    : 'bg-[#eff6ff] text-[#1d4ed8]';

/**
 * فواتيرُ العملاء.
 *
 * والفاتورةُ ليست الطلب: الطلبُ تنفيذٌ ومخزون، وهذه التزامٌ ماليٌّ له تاريخُ
 * استحقاقٍ ويُسدَّد على دفعات.
 */
export default function CustomerInvoicesIndex({
    invoices,
    pagination,
    filters,
    states,
    statuses,
    customers,
    totals,
}: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const columns: Column<Row>[] = [
        {
            key: 'number',
            header: 'رقم الفاتورة',
            cell: (i) => (
                <>
                    <Link href={`/admin/customer-invoices/${i.id}`} className="font-medium text-[#1d4ed8]">
                        {/* ومسودّةٌ بلا رقم تُعرف بمعرّفها — لا بفراغ */}
                        {i.number ?? `${t('مسودة')} #${i.id}`}
                    </Link>
                    {i.po_number && <div className="text-[11px] text-[#9ca3af]">{i.po_number}</div>}
                </>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (i) => i.customer },
        {
            key: 'issued_at',
            header: 'تاريخ الإصدار',
            cell: (i) => <span dir="ltr" className="text-[#6b7280]">{i.issued_at ?? '—'}</span>,
        },
        {
            key: 'due_at',
            header: 'تاريخ الاستحقاق',
            cell: (i) => <span dir="ltr" className="text-[#6b7280]">{i.due_at ?? '—'}</span>,
        },
        { key: 'total', header: 'الإجمالي', align: 'end', cell: (i) => <span className="tabular-nums">{m(i.total)}</span> },
        {
            key: 'paid',
            header: 'المدفوع',
            align: 'end',
            cell: (i) => <span className="tabular-nums text-[#047857]">{m(i.paid)}</span>,
        },
        {
            key: 'outstanding',
            header: 'المتبقي',
            align: 'end',
            cell: (i) => <span className="tabular-nums font-semibold">{m(i.outstanding)}</span>,
        },
        {
            key: 'state',
            header: 'الحالة',
            cell: (i) => <span className={'rounded-full px-2 py-1 text-[11px] ' + tone(i.state)}>{t(i.state)}</span>,
        },
        {
            key: 'days_overdue',
            header: 'أيام التأخير',
            align: 'end',
            cell: (i) =>
                i.days_overdue > 0 ? (
                    <span className="tabular-nums font-medium text-[#b91c1c]">{number(i.days_overdue)}</span>
                ) : (
                    <span className="text-[#d4d4d8]">—</span>
                ),
        },
    ];

    /*
     * والمرشِّحاتُ كلُّها خادميّة.
     *
     * ترشيحُ صفحةٍ بعد جلبها يعني ترشيحَ عشرين صفًّا من أربعمئة — فتقول
     * الشاشة «لا متأخّرات» ولها عشرون في الصفحة الثالثة. فكلُّ مرشِّحٍ هنا
     * يحمل `param` يبلغ القاعدة.
     */
    const tableFilters: Filter<Row>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'state',
            options: states.map((s) => ({ label: s, value: s })),
        },
        {
            label: 'حالة المستند',
            param: 'status',
            options: statuses.map((s) => ({ label: s, value: s })),
        },
        {
            label: 'العميل',
            param: 'customer_id',
            options: customers.map((c) => ({ label: c.name, value: String(c.id) })),
        },
        { label: 'صدرت من', type: 'date', param: 'from' },
        { label: 'صدرت إلى', type: 'date', param: 'to' },
        { label: 'تستحق من', type: 'date', param: 'due_from' },
        { label: 'تستحق إلى', type: 'date', param: 'due_to' },
        {
            label: 'عدد الصفوف',
            param: 'per_page',
            options: [20, 50, 100].map((n) => ({ label: String(n), value: String(n) })),
        },
    ];

    return (
        <AdminLayout title={t('فواتير العملاء')}>
            <PageHeader
                title={t('فواتير العملاء')}
                subtitle={t('الفواتير الصادرة على الشركات والجهات، وما بقي منها.')}
                actions={
                    <Button asChild>
                        <SmartLink
                            routeName="admin.customerInvoices.create"
                            href={route('admin.customerInvoices.create')}
                        >
                            <Plus />
                            {t('إنشاء فاتورة')}
                        </SmartLink>
                    </Button>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('إجمالي الذمم'), value: m(totals.total), icon: 'wallet', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('المتأخر'), value: m(totals.overdue), icon: 'alert-triangle', color: 'danger' }} index={1} />
                <StatCard stat={{ label: t('يستحق خلال ٧ أيام'), value: m(totals.due_soon), icon: 'calendar', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('رصيد دائن للعملاء'), value: m(totals.credit), icon: 'coins', color: 'success' }} index={3} />
            </div>

            <DataTable
                rows={invoices}
                columns={columns}
                rowKey={(i) => i.id}
                searchPlaceholder="رقم الفاتورة أو أمر الشراء أو العميل"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا فواتير بعد"
                server={{ pagination, params: filters }}
            />
        </AdminLayout>
    );
}

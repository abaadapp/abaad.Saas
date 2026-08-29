import { usePage } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Order } from '@/types/models';

interface Props {
    orders: Order[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    /** أعمدة يرتّبها الخادم — مصدرها `Sort::keys` في المتحكّم */
    sorts: string[];
    totalAmount: number;
    totalCount: number;
    cancelledCount: number;
    /** الحالات من مصدرها الواحد في الخادم — انظر App\Support\OrderStatus */
    statusOptions: { value: string; label: string }[];
}

export default function OrdersIndex() {
    const { orders, pagination, filters, sorts, totalAmount, totalCount, cancelledCount, statusOptions, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const columns: Column<Order>[] = [
        { key: 'id', header: 'رقم الطلب', cell: (o) => <span className="font-medium text-[#111]">{o.id}</span> },
        { key: 'customer', header: 'العميل', cell: (o) => o.customer || '—' },
        { key: 'employee', header: 'الموظف', cell: (o) => o.employee || '—' },
        { key: 'branch', header: 'الفرع', cell: (o) => o.branch || '—' },
        {
            key: 'items_count',
            header: 'المنتجات',
            align: 'end',
            cell: (o) => (
                <span className="tabular-nums">
                    {number(o.items_count)} {t('منتج')}
                </span>
            ),
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (o) => <span className="tabular-nums font-medium">{money(o.total, currency)}</span>,
        },
        {
            key: 'payment',
            header: 'الدفع',
            // "بطاقة" تُعرض "فيزا" كما في القالب الأصلي
            cell: (o) => <Badge status={o.payment}>{t(o.payment === 'بطاقة' ? 'فيزا' : o.payment)}</Badge>,
        },
        { key: 'date', header: 'التاريخ', cell: (o) => <span className="text-[#6b7280]">{o.date}</span> },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (o) => (
                <Button variant="ghost" size="sm" asChild>
                    <SmartLink routeName="admin.orders.show" href={route('admin.orders.show', o.id)}>
                        <Eye />
                        {t('عرض')}
                    </SmartLink>
                </Button>
            ),
        },
    ];

    const tableFilters: Filter<Order>[] = [
        {
            label: 'كل وسائل الدفع',
            param: 'payment',
            options: [
                { label: 'نقدي', value: 'نقدي' },
                { label: 'فيزا', value: 'بطاقة' },
                { label: 'تحويل بنكي', value: 'تحويل بنكي' },
            ],
        },
        {
            // الملغى كان يجلس بين المكتمل بلا تمييز ولا فرز
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            // كانت خمس حالاتٍ مكتوبةً هنا بينما البذرة تكتب ستًّا: طلبٌ
            // بحالة «جديد» لا يقصده تبويب، فلا يظهر في أيّ ترشيح
            options: statusOptions,
        },
        {
            /*
             * موعد التسليم — على `scheduled_for` لا على `ordered_at`.
             *
             * «ما الذي يُسلَّم اليوم؟» سؤالٌ يُسأل كلّ صباح، وطلبٌ سُجّل الاثنين
             * لتسليمه الجمعة يقع في يومين مختلفين بحسب أيّ عمودٍ يُقرأ. ومُرشِّحا
             * «من/إلى» يبقيان على تاريخ التسجيل لأنّ التقارير المالية عليهما.
             */
            label: 'كل المواعيد',
            param: 'when',
            options: [
                { label: 'متأخّر', value: 'overdue' },
                { label: 'اليوم', value: 'today' },
                { label: 'غدًا', value: 'tomorrow' },
                { label: 'قادم', value: 'upcoming' },
            ],
        },
        // مدًى لا يومًا واحدًا: مبيعات أسبوعٍ كانت تُفتح سبع مرّات.
        // ولا حقل ثالث لليوم الواحد — يُطلب بجعل الطرفين يومًا واحدًا،
        // وحقلان يفعلان الشيء نفسه يجعلان القارئ يسأل عن الفرق بينهما.
        { label: 'من', type: 'date', param: 'from' },
        { label: 'إلى', type: 'date', param: 'to' },
    ];

    return (
        <AdminLayout title="المبيعات">
            <PageHeader
                title="المبيعات"
                subtitle={t('متابعة وإدارة طلبات العملاء')}
                actions={
                    <>
                        <ExportMenu
                            xlsx={route('admin.orders.xlsx')}
                            pdf={route('admin.orders.exportPdf')}
                            csv={route('admin.export.orders')}
                        />
                        <Button asChild>
                            <SmartLink routeName="pos.index" href={route('pos.index')}>
                                <Plus />
                                {t('إنشاء طلب')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <Card className="overflow-hidden">
                <DataTable
                    rows={orders}
                    columns={columns}
                    rowKey={(o) => o.id}
                    searchPlaceholder="ابحث برقم الطلب أو العميل أو الموظف…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا توجد طلبات بعد"
                    server={{ pagination, params: filters, sorts }}
                />
                {/*
                    مجموع ما رُشّح لا مجموع الصفحة — والمبلغ من المُباع وحده،
                    والملغى يُذكر صراحةً كي لا يُقرأ الفرقُ خطأً في الجمع.
                */}
                <div className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm text-[#6b7280]">
                    {t('الفواتير')}: {number(totalCount)} — {t('الإجمالي')}:{' '}
                    <span className="font-semibold text-[#111]">{money(totalAmount, currency)}</span>
                    {cancelledCount > 0 && (
                        <span className="text-[#9ca3af]">
                            {' '}
                            ({t('منها :n ملغاة لا تُحسب', { n: String(cancelledCount) })})
                        </span>
                    )}
                </div>
            </Card>
        </AdminLayout>
    );
}

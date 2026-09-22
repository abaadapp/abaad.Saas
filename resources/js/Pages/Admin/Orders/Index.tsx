import { usePage } from '@inertiajs/react';
import { Eye, Globe, Plus, Store } from 'lucide-react';
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
    /** كم من المُرشَّح جاء من الموقع — ومبلغُه. يُقرأ بجوار الإجمالي */
    websiteCount: number;
    websiteAmount: number;
    /** الحالات من مصدرها الواحد في الخادم — انظر App\Support\OrderStatus */
    statusOptions: { value: string; label: string }[];
    /** القنوات من مصدرها الواحد كذلك — انظر App\Support\SalesChannel */
    channelOptions: { value: string; label: string }[];
}

export default function OrdersIndex() {
    const {
        orders, pagination, filters, sorts, totalAmount, totalCount, cancelledCount,
        websiteCount, websiteAmount, statusOptions, channelOptions, context,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const columns: Column<Order>[] = [
        { key: 'id', header: 'رقم الطلب', cell: (o) => <span className="font-medium text-[#111]">{o.id}</span> },
        { key: 'customer', header: 'العميل', cell: (o) => o.customer || '—' },
        {
            /*
             * ═══ المصدرُ — العمودُ الذي كان مكتوبًا في القاعدة ولا يُقرأ ═══
             *
             * `orders.channel` تُكتب في كلّ صفٍّ منذ أُنشئ العمود، ولم تكن
             * تصل الشاشةَ أصلًا. فالسؤالُ الأوّل الذي يسأله من فتح متجرًا
             * إلكترونيًّا — «أيبيع الموقعُ شيئًا، أم أبيع أنا وحدي؟» — لم يكن
             * له جواب في الشاشة التي فيها الجواب.
             *
             * وعلامةُ الموقع تكفي وحدها: «الموظف» يكتب فيها الخادمُ «الموقع
             * الإلكتروني» لأنّه لا بائعَ لها، فتُقرأ الكلمةُ مرّتين في صفٍّ
             * واحد. فيُترك موضعُ الموظّف خطًّا لطلب الموقع — المصدرُ قاله.
             */
            key: 'channel',
            header: 'المصدر',
            cell: (o) =>
                o.channel === 'website' ? (
                    <Badge variant="primary">
                        <Globe className="size-3" />
                        {t('الموقع الإلكتروني')}
                    </Badge>
                ) : o.channel === 'pos' ? (
                    <Badge variant="neutral">
                        <Store className="size-3" />
                        {t('نقطة البيع')}
                    </Badge>
                ) : (
                    <span className="text-[#9ca3af]">{o.channel_label ?? '—'}</span>
                ),
        },
        {
            key: 'employee',
            header: 'الموظف',
            cell: (o) => (o.channel === 'website' ? <span className="text-[#9ca3af]">—</span> : o.employee || '—'),
        },
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
            /*
             * ═══ موعدُ التسليم — العمودُ الذي كان يُرشَّح به ولا يُعرض ═══
             *
             * كان الموعدُ يُرسَل إلى الشاشة، ويُعرض له مفتاحُ فرزٍ، وله ثلاثةُ
             * مُرشِّحات («متأخّر» و«اليوم» و«غدًا» و«قادم») — ولا عمودَ له.
             * فيُرشِّح صاحبُ المحلّ «متأخّر» صباحًا فيرى صفوفًا لا يُفرّقها عن
             * صفوف أمس شيء: لا متى كان يجب أن تخرج، ولا كم تأخّرت، ولا أيُّها
             * أقدم. والفرزُ بالموعد كان مفتاحًا ميتًا: `DataTable` لا ترتّب إلّا
             * أعمدةً تعرضها.
             *
             * والوسمُ من الخادم لا من مقارنةِ نصٍّ في المتصفّح: `Order::isLate`
             * هي قاعدةُ المُرشِّح نفسُها، فلا يفترق ما يُرشَّح عمّا يُوسَم.
             */
            key: 'scheduled',
            header: 'موعد التسليم',
            cell: (o) =>
                o.scheduled && o.scheduled !== '—' ? (
                    <span className="flex flex-col">
                        <span className={o.late ? 'font-medium text-[#b91c1c]' : 'text-[#4b4b4b]'}>
                            {o.scheduled}
                        </span>
                        <span className="text-[11px] text-[#9ca3af]">
                            {o.late ? t('متأخّر') : o.fulfillment ? t(o.fulfillment) : ''}
                        </span>
                    </span>
                ) : (
                    <span className="text-[#9ca3af]">—</span>
                ),
        },
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
            /*
             * والقناةُ أوّلُ مُرشِّحٍ لا آخرُه: «كم باع الموقع؟» سؤالٌ يُسأل
             * قبل «بأيّ وسيلةٍ دُفع» — وهو سببُ فتح الشاشة عند كثيرين.
             */
            label: 'كل المصادر',
            param: 'channel',
            options: channelOptions,
        },
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
                    {/*
                        وما جاء من الموقع يُقرأ بجوار الإجمالي لا في عمودٍ
                        يُعدّ باليد: الصفحةُ عشرةٌ من مئة، فالعدُّ فيها يكذب.
                        ويُخفى حين لا يكون — سطرٌ يقول «٠ من الموقع» في متجرٍ
                        بلا موقعٍ نصيحةٌ لم يطلبها أحد.
                    */}
                    {websiteCount > 0 && (
                        <span className="mt-1 block text-[#6d28d9]">
                            {t('منها من الموقع الإلكتروني')}: {number(websiteCount)} —{' '}
                            <span className="font-semibold">{money(websiteAmount, currency)}</span>
                        </span>
                    )}
                </div>
            </Card>
        </AdminLayout>
    );
}

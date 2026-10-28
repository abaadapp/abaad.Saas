import { router, usePage } from '@inertiajs/react';
import { Eye, Globe, Link2, Plus, Store, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useConfirm } from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import PeriodControls, { type PeriodState } from '@/Components/PeriodControls';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import SmartLink from '@/Components/SmartLink';
import { collectionHref, type PaidCollection } from '@/Components/PaidLinkCollection';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Order, SaleConfirmation, SaleHandover, SaleProducts } from '@/types/models';

interface Props {
    /** الفترةُ على تاريخ الطلب (`ListFilters::orderPeriod`) — من/إلى القديمة أو شهرٌ أو سنة */
    period?: PeriodState;
    /** صفوفُ الصفحة — طلباتٌ، ومعها تحصيلاتُ الروابط في «كل المصادر» حين `withLinks` */
    orders: SaleRow[];
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
    /** زرُّ «حذف» لصاحب النشاط وحده (`role === 'admin'`) — والخادمُ يردّ غيرَه بـ403 */
    mayDelete: boolean;
    /** تحصيلاتُ روابط الدفع حين يُختار مصدرُها «رابط دفع» — `PaidLinksList` */
    collections?: Collection[];
    collectionTotals?: {
        count: number;
        amount: number;
        on_invoices: { count: number; amount: number };
        standalone: { count: number; amount: number };
    };
    /** سندُ القبض صفحةُ «المالية» — زرُّه لمن يفتحها */
    mayOpenReceipts?: boolean;
    /** عددُ تحصيلات الروابط ومبلغُها تحت إجمالي «كل المصادر» — ليست منه */
    linkCollections?: { count: number; amount: number } | null;
    /** أفي جدول «كل المصادر» تحصيلاتٌ بين الطلبات؟ — `AllSalesList::merges` */
    withLinks?: boolean;
}

/**
 * صفُّ «كل المصادر»: طلبٌ، أو تحصيلٌ عبر رابط دفع بين الطلبات بيوم دفعه
 * (`AllSalesList`). والطلبُ بلا `kind` هو الطلبُ كما كان.
 */
export type SaleRow = (Order & { kind?: 'order' }) | (Collection & { kind: 'link' });

const isLink = (r: SaleRow): r is Collection & { kind: 'link' } => r.kind === 'link';

/** تحصيلٌ عبر رابط دفع — مالٌ قُبض عن بيعٍ محسوبٍ في فاتورته، لا طلب (`PaidLinksList::row`) */
export type Collection = PaidCollection;

const PAYMENT_LINK = 'payment_link';

export default function OrdersIndex() {
    const {
        orders, pagination, filters, sorts, totalAmount, totalCount, cancelledCount, period,
        websiteCount, websiteAmount, statusOptions, channelOptions, mayDelete, context,
        collections, collectionTotals, mayOpenReceipts, linkCollections, withLinks, auth,
    } = usePage<PageProps<Props>>().props;
    // «إنشاء طلب» يفتح نقطةَ البيع — ومتجرٌ لا يملكها لا يُعرض له بابٌ يردّه الخادم (`CheckPlanFeature`)
    const hasPos = auth?.planFeatures?.pos !== false;
    const t = useTranslate();
    const currency = context!.currency;
    const [ask, confirmDialog] = useConfirm();
    // «رابط دفع» — الجدولُ تحصيلاتٌ من الخادم لا طلبات (`PaidLinksList`)
    const onLinks = filters.channel === PAYMENT_LINK && collections !== undefined;

    /*
     * «حذف» البيعة إلغاؤها ماليًّا — `OrderController::destroy` ← `OrderCorrection::cancel`.
     * والصفُّ يبقى بحالة «ملغي»: الصفحةُ تُعاد من الخادم بالترشيح نفسه.
     */
    const remove = async (o: SaleRow) => {
        const ok = await ask({
            title: 'حذف البيعة',
            message: 'هل تريد حذف هذه البيعة؟ سيُلغى أثرها المالي ويُعاد المخزون.',
            action: 'حذف البيعة',
            danger: true,
        });
        if (ok) router.delete(route('admin.orders.destroy', o.id), { preserveScroll: true });
    };

    /*
     * ═══ صفُّ التحصيل بين الطلبات ═══
     *
     * في «كل المصادر» لمتجرٍ فُتحت فيه روابطُ الدفع يقع التحصيلُ بين الطلبات
     * بيوم دفعه (`AllSalesList`). وهو مالٌ قُبض عن بيعٍ محسوبٍ في فاتورته: لا
     * منتجاتَ له ولا موظّفَ ولا موعد، ومبلغُه «محصّل» لا إجماليُّ بيع، ولا «حذف».
     * و«عرض» يفتح فاتورتَه الأصليّة — أو تحصيلَه حين لا فاتورة.
     */
    const columns: Column<SaleRow>[] = [
        {
            key: 'id',
            header: 'رقم الطلب',
            cell: (o) =>
                isLink(o) ? (
                    <span className="flex flex-col">
                        <span className="font-medium text-[#111]">{o.id}</span>
                        <span className="text-[11px] text-[#9ca3af]" dir="ltr">
                            Paymob · {o.reference}
                        </span>
                    </span>
                ) : (
                    <span className="font-medium text-[#111]">{o.id}</span>
                ),
        },
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
                isLink(o) ? (
                    <span className="flex flex-col items-start gap-0.5" data-testid="collection-type">
                        <Badge variant="primary">
                            <Link2 className="size-3" />
                            {t('رابط دفع')}
                        </Badge>
                        <span className="text-[11px] text-[#6b7280]">
                            {o.invoice ? `${t('تحصيل فاتورة')} ${o.invoice.number}` : t('دون فاتورة')}
                        </span>
                    </span>
                ) : o.channel === 'website' ? (
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
            /*
             * ═══ الموظف — من أنشأ، ومن أكّد، ومن أنشأ الرابط ═══
             *
             * بيعةُ الصندوق: الكاشير، وتحته من أكّدها إن انتظرت تأكيدًا. وطلبُ
             * الموقع لا بائعَ له (المصدرُ قاله)، فيُقرأ فيه من أكّده — أو أنّه
             * «بانتظار التأكيد»، أو «مؤكّد — الموظف غير معروف» لطلبٍ أُكّد قبل
             * أن يُحفظ المؤكِّد. والتحصيلُ: منشئُ رابطه، لا مؤكِّدُ طلب.
             */
            key: 'employee',
            header: 'الموظف',
            cell: (o) =>
                isLink(o) ? (
                    o.creator ? (
                        <span className="flex flex-col" data-testid="link-creator">
                            <span className="text-[11px] text-[#9ca3af]">{t('أنشأ الرابط')}</span>
                            <span>{o.creator}</span>
                        </span>
                    ) : (
                        <span className="text-[#9ca3af]">—</span>
                    )
                ) : o.channel === 'website' ? (
                    o.confirmation ? <Confirmed c={o.confirmation} /> : <span className="text-[#9ca3af]">—</span>
                ) : (
                    <span className="flex flex-col">
                        <span>{o.employee || '—'}</span>
                        {o.confirmation && <Confirmed c={o.confirmation} />}
                    </span>
                ),
        },
        {
            key: 'branch',
            header: 'الفرع',
            cell: (o) =>
                isLink(o) && o.branches && o.branches.length > 0 ? (
                    <span className="flex flex-col" data-testid="link-branches" title={o.branches.join('، ')}>
                        <span>{o.branch}</span>
                        <span className="text-[11px] text-[#9ca3af]">{o.branches.join('، ')}</span>
                    </span>
                ) : (
                    o.branch || '—'
                ),
        },
        {
            /*
             * «منتجان — 5 قطع» — بنودُ المنتجات المختلفة وقطعُها، والإضافاتُ
             * المبيعةُ سطرًا في سطرها (`SaleCells::products`). والتحصيلُ: بنودُ
             * فاتورته — لا بنودُ طلباتها فوقها — أو «لا توجد منتجات مرتبطة».
             */
            key: 'items_count',
            header: 'المنتجات',
            cell: (o) =>
                isLink(o) ? (
                    o.products ? (
                        <Bought p={o.products} />
                    ) : (
                        <span className="text-[12px] text-[#9ca3af]" data-testid="no-products">
                            {t('لا توجد منتجات مرتبطة')}
                        </span>
                    )
                ) : o.products ? (
                    <Bought p={o.products} />
                ) : (
                    <span className="tabular-nums">
                        {number(o.items_count)} {t('منتج')}
                    </span>
                ),
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (o) =>
                isLink(o) ? (
                    // محصّلٌ لا إجماليُّ بيع — لا يُجمع مع الطلبات
                    <span className="flex flex-col items-end" data-testid="collection-amount">
                        <span className="tabular-nums font-medium text-[#6d28d9]">{money(o.amount, currency)}</span>
                        <span className="text-[11px] text-[#9ca3af]">{t('محصّل')}</span>
                    </span>
                ) : (
                    <span className="tabular-nums font-medium">{money(o.total, currency)}</span>
                ),
        },
        {
            key: 'payment',
            header: 'الدفع',
            // "بطاقة" تُعرض "فيزا" كما في القالب الأصلي
            cell: (o) =>
                isLink(o) ? (
                    o.review ? (
                        <Badge variant="warning">{t('مدفوع — يحتاج مراجعة')}</Badge>
                    ) : (
                        <Badge variant="neutral">Paymob</Badge>
                    )
                ) : (
                    <Badge status={o.payment}>{t(o.payment === 'بطاقة' ? 'فيزا' : o.payment)}</Badge>
                ),
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
            /*
             * و«التسليم / الاستلام» — نوعُ التنفيذ دائمًا، ثمّ موعدُه بطرفي فترته
             * أو «لم يُحدد موعد». و«متأخّر» وسمٌ بجانب النوع لا بدلَه.
             */
            key: 'scheduled',
            header: 'التسليم / الاستلام',
            cell: (o) => <Handover h={o.handover ?? null} late={!isLink(o) && !!o.late} />,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (o) =>
                isLink(o) ? (
                    <div className="flex items-center justify-end gap-1">
                        {/* «عرض» يفتح الفاتورةَ الأصليّة نفسَها — لا سندَ القبض */}
                        <Button variant="ghost" size="sm" asChild>
                            <SmartLink {...collectionHref(o)} data-testid="collection-view">
                                <Eye />
                                {t('عرض')}
                            </SmartLink>
                        </Button>
                        {mayOpenReceipts && (
                            <Button variant="ghost" size="sm" asChild>
                                <SmartLink routeName="admin.customerPayments.show" href={route('admin.customerPayments.show', o.payment.id)}>
                                    {t('سند القبض')}
                                </SmartLink>
                            </Button>
                        )}
                    </div>
                ) : (
                <div className="flex items-center justify-end gap-1">
                    <Button variant="ghost" size="sm" asChild>
                        <SmartLink routeName="admin.orders.show" href={route('admin.orders.show', o.id)}>
                            <Eye />
                            {t('عرض')}
                        </SmartLink>
                    </Button>
                    {/* والملغاةُ لا تُحذف ثانيةً — لا أثرَ ماليًّا بقي لها */}
                    {mayDelete && o.status !== 'ملغي' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-[#b91c1c] hover:bg-[#fef2f2] hover:text-[#991b1b]"
                            onClick={() => remove(o)}
                            data-testid="order-delete"
                        >
                            <Trash2 />
                            {t('حذف')}
                        </Button>
                    )}
                </div>
                ),
        },
    ];

    const tableFilters: Filter<SaleRow>[] = [
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
        /*
         * ومن/إلى صارتا في منتقي الفترة فوق الجدول (`PeriodControls`): «فترة
         * مخصّصة» هي هما، ومعهما شهرٌ بعينه أو سنةٌ أو نطاقُ أشهر. وحقلا
         * تاريخٍ هنا بجوار المنتقي يكتبان مفتاحين يتنازعان الرابط.
         */
        ...(period
            ? []
            : ([
                  { label: 'من', type: 'date', param: 'from' },
                  { label: 'إلى', type: 'date', param: 'to' },
              ] as Filter<SaleRow>[])),
    ];

    /*
     * ═══ «رابط دفع» — تحصيلٌ لا طلب ═══
     *
     * مالٌ قبضته Paymob عن فاتورةٍ (بيعُها محسوبٌ فيها) أو رصيدًا للعميل. فلا
     * عمودَ منتجاتٍ ولا موظّفَ ولا موعدَ تسليم، ولا «حذف»: إلغاءُ التحصيل
     * بابُه سندُ القبض في «المالية». ومُرشِّحاتُ الطلب (الحالة والوسيلة والموعد)
     * تُرفع هنا — الخادمُ لا يسألها عن تحصيل.
     */
    const collectionColumns: Column<Collection>[] = [
        {
            key: 'id',
            header: 'رقم السند',
            cell: (c) => (
                <span className="flex flex-col">
                    <span className="font-medium text-[#111]">{c.id}</span>
                    <span className="text-[11px] text-[#9ca3af]" dir="ltr">
                        Paymob · {c.reference}
                    </span>
                </span>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (c) => c.customer || '—' },
        { key: 'employee', header: 'أنشأ الرابط', cell: (c) => c.creator || <span className="text-[#9ca3af]">—</span> },
        {
            key: 'branch',
            header: 'الفرع',
            cell: (c) => (c.branches && c.branches.length > 0 ? `${c.branch}: ${c.branches.join('، ')}` : c.branch || '—'),
        },
        {
            key: 'items_count',
            header: 'المنتجات',
            cell: (c) =>
                c.products ? <Bought p={c.products} /> : <span className="text-[12px] text-[#9ca3af]">{t('لا توجد منتجات مرتبطة')}</span>,
        },
        { key: 'scheduled', header: 'التسليم / الاستلام', cell: (c) => <Handover h={c.handover ?? null} late={false} /> },
        {
            key: 'channel',
            header: 'المصدر',
            cell: () => (
                <Badge variant="primary">
                    <Link2 className="size-3" />
                    {t('رابط دفع')}
                </Badge>
            ),
        },
        {
            key: 'type',
            header: 'النوع',
            cell: (c) => (
                <span className="flex flex-col" data-testid="collection-type">
                    <span>{t(c.type)}</span>
                    {c.invoice && (
                        <SmartLink
                            routeName="admin.customerInvoices.show"
                            href={route('admin.customerInvoices.show', c.invoice.id)}
                            className="text-[12px] text-[#6d28d9] hover:underline"
                        >
                            {c.invoice.number}
                        </SmartLink>
                    )}
                    {c.orders.length > 0 && (
                        <span className="text-[11px] text-[#9ca3af]">
                            {t('عن الطلب :n', { n: c.orders.join('، ') })}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'total',
            header: 'المبلغ',
            align: 'end',
            cell: (c) => <span className="tabular-nums font-medium">{money(c.amount, currency)}</span>,
        },
        { key: 'payment', header: 'الدفع', cell: () => <Badge variant="neutral">Paymob</Badge> },
        { key: 'date', header: 'تاريخ الدفع', cell: (c) => <span className="text-[#6b7280]">{c.date}</span> },
        {
            key: 'status',
            header: 'الحالة',
            cell: (c) =>
                c.review ? <Badge variant="warning">{t('مدفوع — يحتاج مراجعة')}</Badge> : <Badge status="مدفوع">{t('مدفوع')}</Badge>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (c) => (
                <div className="flex items-center justify-end gap-1">
                    {/* «عرض» يفتح الفاتورةَ الأصليّة نفسَها — أو تحصيلَه حين لا فاتورة */}
                    <Button variant="ghost" size="sm" asChild>
                        <SmartLink {...collectionHref(c)} data-testid="collection-view">
                            <Eye />
                            {t('عرض')}
                        </SmartLink>
                    </Button>
                    {mayOpenReceipts && (
                        <Button variant="ghost" size="sm" asChild>
                            <SmartLink routeName="admin.customerPayments.show" href={route('admin.customerPayments.show', c.payment.id)}>
                                {t('سند القبض')}
                            </SmartLink>
                        </Button>
                    )}
                </div>
            ),
        },
    ];
    const collectionFilters: Filter<Collection>[] = tableFilters
        .filter((f) => f.param === 'channel' || f.type === 'date')
        .map(({ label, type, options, param }) => ({ label, type, options, param }));

    // الرابطُ من سطر «كل المصادر» إلى مُرشِّحه — بالبحث والفترة نفسَيهما
    const linkParams = Object.fromEntries(
        Object.entries(filters).filter(
            ([k, v]) => v !== null && v !== '' && !['channel', 'status', 'payment', 'when', 'sort', 'dir', 'page'].includes(k),
        ),
    );

    return (
        <AdminLayout title="المبيعات">
            <PageHeader
                title="المبيعات"
                subtitle={t('متابعة وإدارة طلبات العملاء')}
                actions={
                    <>
                        <ExportMenu
                            action="order.export"
                            xlsx={route('admin.orders.xlsx')}
                            pdf={route('admin.orders.exportPdf')}
                            csv={route('admin.export.orders')}
                        />
                        {hasPos && (
                            <Button asChild>
                                <SmartLink routeName="pos.index" href={route('pos.index')} data-testid="orders-new-pos">
                                    <Plus />
                                    {t('إنشاء طلب')}
                                </SmartLink>
                            </Button>
                        )}
                    </>
                }
            />

            {/* الفترةُ على تاريخ الطلب — و«موعد التسليم» مُرشِّحٌ آخر على عموده (`when`) */}
            {period && <PeriodControls period={period} params={filters} />}

            {onLinks ? (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={collections ?? []}
                        columns={collectionColumns}
                        rowKey={(c) => c.key}
                        searchPlaceholder="ابحث برقم السند أو العميل أو الفاتورة أو مرجع Paymob…"
                        searchable={() => ''}
                        filters={collectionFilters}
                        empty="لا توجد تحصيلات ناجحة عبر روابط الدفع"
                        server={{ pagination, params: filters, sorts }}
                    />
                    {/*
                        المحصَّلُ لا «الإجمالي»: هذه أموالٌ قُبضت عن مبيعاتٍ محسوبةٍ
                        في فواتيرها، أو رصيدٌ للعميل — ولا يُضاف شيءٌ منها إلى المبيعات.
                    */}
                    <div
                        className="border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm text-[#6b7280]"
                        data-testid="collection-totals"
                    >
                        {t('تحصيلات عبر روابط الدفع')}: {number(collectionTotals?.count ?? 0)} — {t('المحصّل')}:{' '}
                        <span className="font-semibold text-[#111]">{money(collectionTotals?.amount ?? 0, currency)}</span>
                        <span className="mt-1 block">
                            {t('منه على فواتير')}: {money(collectionTotals?.on_invoices.amount ?? 0, currency)} —{' '}
                            {t('منه دون فاتورة')}: {money(collectionTotals?.standalone.amount ?? 0, currency)}
                        </span>
                        <span className="mt-1 block text-[#9ca3af]">
                            {t('تحصيلٌ لا بيعٌ جديد: البيعُ محسوبٌ في فاتورته، ولا يُضاف هذا إلى إجمالي المبيعات.')}
                        </span>
                    </div>
                </Card>
            ) : (
            <Card className="overflow-hidden">
                <DataTable
                    rows={orders}
                    columns={columns}
                    rowKey={(o) => (isLink(o) ? o.key : o.id)}
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
                    {t('الفواتير')}: {number(totalCount)} — {withLinks ? t('إجمالي المبيعات') : t('الإجمالي')}:{' '}
                    <span className="font-semibold text-[#111]" data-testid="sales-total">
                        {money(totalAmount, currency)}
                    </span>
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
                    {/*
                        وتحصيلاتُ روابط الدفع خارج الإجمالي — تُذكر ولا تُجمع:
                        مالٌ قُبض عن بيعٍ محسوبٍ في فاتورته. ويُفتح بمُرشِّحه.
                    */}
                    {linkCollections && (
                        <span className="mt-1 block" data-testid="link-collections">
                            {withLinks
                                ? t('مجموع التحصيلات عبر روابط الدفع (صفوفها في الجدول، وليست من إجمالي المبيعات)')
                                : t('وتحصيلات عبر روابط الدفع (ليست ضمن الإجمالي)')}
                            : {number(linkCollections.count)} —{' '}
                            <span className="font-semibold">{money(linkCollections.amount, currency)}</span>{' '}
                            <SmartLink
                                routeName="admin.orders.index"
                                href={route('admin.orders.index', { ...linkParams, channel: PAYMENT_LINK })}
                                className="text-[#6d28d9] hover:underline"
                            >
                                {t('عرضها')}
                            </SmartLink>
                        </span>
                    )}
                </div>
            </Card>
            )}
            {confirmDialog}
        </AdminLayout>
    );
}

/** من أكّد الطلب — والجملةُ من الخادم (`SaleCells::confirmation`) */
function Confirmed({ c }: { c: SaleConfirmation }) {
    const tone =
        c.state === 'pending' ? 'text-[#b45309]' : c.state === 'confirmed' && c.by ? 'text-[#166534]' : 'text-[#6b7280]';

    return (
        <span className={`block max-w-[8rem] text-[12px] ${tone}`} data-testid="order-confirmation" data-state={c.state}>
            {c.label}
        </span>
    );
}

/** «منتجان — 5 قطع» وتحتها الإضافات — من الخادم (`SaleCells::products`) */
function Bought({ p }: { p: SaleProducts }) {
    return (
        // يلتفّ على سطرين ولا يمدّ الجدول: عمودٌ عريضٌ يُخرج «عرض» من الشاشة على 1366
        <span className="flex max-w-[6.5rem] flex-col" data-testid="order-products">
            <span className="tabular-nums">{p.label}</span>
            {p.addons_label && <span className="text-[11px] text-[#9ca3af]">{p.addons_label}</span>}
        </span>
    );
}

/** نوعُ التنفيذ دائمًا، و«متأخّر» بجانبه، ثمّ الموعدُ أو «لم يُحدد موعد» (`SaleCells::handover`) */
function Handover({ h, late }: { h: SaleHandover | null; late: boolean }) {
    const t = useTranslate();

    if (!h) {
        return <span className="text-[#9ca3af]">—</span>;
    }

    return (
        <span className="flex flex-col" data-testid="handover" data-kind={h.kind ?? ''}>
            {(h.type || late) && (
                <span className="flex flex-wrap items-center gap-1">
                    {h.type && <span className="font-medium text-[#111]">{h.type}</span>}
                    {late && <Badge variant="danger">{t('متأخّر')}</Badge>}
                </span>
            )}
            <span className={late ? 'text-[11.5px] font-medium text-[#b91c1c]' : 'text-[11.5px] text-[#4b4b4b]'} dir="auto">
                {h.when ?? (h.type ? t('لم يُحدد موعد') : h.label)}
            </span>
            {h.details && h.details.length > 0 && (
                <span className="text-[11px] text-[#9ca3af]" data-testid="handover-details">
                    {h.details.join(' · ')}
                </span>
            )}
        </span>
    );
}

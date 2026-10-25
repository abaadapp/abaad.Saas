import { usePage } from '@inertiajs/react';
import { Eye, FileText, Globe, Store } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ExportMenu from '@/Components/ExportMenu';
import PeriodControls, { type PeriodState } from '@/Components/PeriodControls';
import SectionTabs, { CUSTOMER_INVOICE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** صفٌّ من `SalesRegister::row` — طلبٌ أو فاتورةٌ مستقلّة */
export interface SaleRow {
    key: string;
    kind: 'order' | 'invoice';
    id: number;
    number: string | null;
    type: string;
    source: string;
    source_label: string;
    customer: string;
    seller_kind: 'website' | 'person' | 'unknown';
    seller: string | null;
    seller_id: number | null;
    /** الحسابُ المسجَّل ساعةَ البيع إن لم يكن هو الكاشير المختار */
    account: string | null;
    branch: string | null;
    date: string;
    amount: number;
    credited: number | null;
    pay_state: string;
    /** الفاتورةُ التي تغطّي الطلب — يُحصَّل عليها */
    cover: { id: number; number: string | null } | null;
    prep: string | null;
    prep_stage: 'none' | 'waiting' | 'active' | 'done' | 'failed';
    status: string;
    cancelled: boolean;
    prep_id: number | null;
}

interface Props {
    rows: SaleRow[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    period?: PeriodState;
    /** مصدرُ التبويب — `null` لـ«جميع عمليات البيع» */
    source: 'pos' | 'website' | null;
    totals: { count: number; sales: number; collected: number; outstanding: number; cancelled: number };
    sellers: { value: string; label: string }[];
    paymentStates: string[];
    prepStages: Record<string, string>;
    /** الفرعُ المختار في الترويسة — يُقال لأنّه يُخفي ما سواه */
    branch: string | null;
    mayPrepare: boolean;
    /** في تبويبَي الصندوق والموقع: بيعاتٌ قبل حفظ المصدر لا يُعرف بابُها */
    unknownCount: number | null;
}

const payTone = (state: string) =>
    state === 'مدفوع' ? 'bg-[#ecfdf5] text-[#047857]'
    : state === 'ملغى' ? 'bg-[#f4f4f5] text-[#71717a]'
    : state === 'مدفوع جزئيًا' ? 'bg-[#fffbeb] text-[#b45309]'
    : 'bg-[#eff6ff] text-[#1d4ed8]';

/** رابطُ المستند الأصليّ — الطلبُ برقمه، والفاتورةُ بمعرّفها */
function documentLink(r: SaleRow) {
    return r.kind === 'order'
        ? { routeName: 'admin.orders.show', href: route('admin.orders.show', r.number ?? '') }
        : { routeName: 'admin.customerInvoices.show', href: route('admin.customerInvoices.show', r.id) };
}

/** خليّةُ البائع — والموقعُ مصدرٌ لا موظّف، وما لا يُعرف لا يُنسب */
export function SellerCell({ row }: { row: SaleRow }) {
    const t = useTranslate();

    if (row.seller_kind === 'website') {
        return <span className="text-[#9ca3af]">{t('الموقع الإلكتروني')}</span>;
    }
    if (row.seller_kind === 'unknown') {
        return <span className="text-[#9ca3af]">{t('غير محدد')}</span>;
    }

    return (
        <>
            <span>{row.seller}</span>
            {row.account && (
                <div className="text-[11px] text-[#9ca3af]">
                    {t('سُجّلت من حساب')}: {row.account}
                </div>
            )}
        </>
    );
}

/**
 * جميعُ عمليات البيع — طلباتُ الصندوق والموقع وفواتيرُ العملاء المستقلّة.
 *
 * كلُّ بيعةٍ صفٌّ واحد: الطلبُ الذي غطّته فاتورةٌ يبقى طلبًا يُذكر رقمُ
 * فاتورته، والتجهيزُ والسدادُ حالان فيه لا صفّان آخران.
 */
export default function CustomerInvoicesSales({
    rows,
    pagination,
    filters,
    period,
    source,
    totals,
    sellers,
    paymentStates,
    prepStages,
    branch,
    mayPrepare,
    unknownCount,
}: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const exportArgs = source ? { source } : undefined;

    const columns: Column<SaleRow>[] = [
        {
            key: 'number',
            header: 'رقم العملية',
            cell: (r) => {
                const link = documentLink(r);

                return (
                    <>
                        <SmartLink routeName={link.routeName} href={link.href} className="whitespace-nowrap font-medium text-[#1d4ed8]">
                            {r.number ?? '—'}
                        </SmartLink>
                        {r.cover && (
                            <div className="text-[11px] text-[#9ca3af]">
                                {t('مفوتر في')}{' '}
                                <SmartLink
                                    routeName="admin.customerInvoices.show"
                                    href={route('admin.customerInvoices.show', r.cover.id)}
                                    className="text-[#6b7280] underline"
                                >
                                    {r.cover.number ?? t('مسودة')}
                                </SmartLink>
                            </div>
                        )}
                    </>
                );
            },
        },
        {
            key: 'type',
            header: 'النوع',
            cell: (r) =>
                r.kind === 'invoice' ? (
                    <Badge variant="info">
                        <FileText className="size-3" />
                        {t(r.type)}
                    </Badge>
                ) : r.source === 'website' ? (
                    <Badge variant="primary">
                        <Globe className="size-3" />
                        {t(r.type)}
                    </Badge>
                ) : (
                    <Badge variant="neutral">
                        <Store className="size-3" />
                        {t(r.type)}
                    </Badge>
                ),
        },
        { key: 'customer', header: 'العميل', cell: (r) => r.customer || '—' },
        { key: 'seller', header: 'الموظف', cell: (r) => <SellerCell row={r} /> },
        { key: 'branch', header: 'الفرع', cell: (r) => r.branch ?? <span className="text-[#9ca3af]">{t('بلا فرع')}</span> },
        {
            key: 'date',
            header: 'التاريخ',
            cell: (r) => <span dir="ltr" className="whitespace-nowrap text-[#6b7280]">{r.date}</span>,
        },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            cell: (r) => (
                <>
                    <span className={'whitespace-nowrap tabular-nums font-medium ' + (r.cancelled ? 'text-[#9ca3af] line-through' : '')}>
                        {m(r.amount)}
                    </span>
                    {r.credited !== null && r.credited > 0 && (
                        <div className="text-[11px] tabular-nums text-[#b45309]">
                            {t('إشعار دائن')} −{m(r.credited)}
                        </div>
                    )}
                </>
            ),
        },
        {
            key: 'pay_state',
            header: 'السداد',
            cell: (r) => (
                <>
                    <span className={'whitespace-nowrap rounded-full px-2 py-1 text-[11px] ' + payTone(r.pay_state)}>{t(r.pay_state)}</span>
                    {r.cover && !r.cancelled && (
                        <div className="mt-1 text-[11px] text-[#9ca3af]">{t('عبر الفاتورة')}</div>
                    )}
                </>
            ),
        },
        {
            key: 'prep',
            header: 'التجهيز',
            cell: (r) => {
                if (r.prep === null) return <span className="text-[#d4d4d8]">—</span>;
                const open = r.prep_stage === 'waiting' || r.prep_stage === 'active';

                return (
                    <>
                        <span>{t(r.prep)}</span>
                        {open && mayPrepare && (
                            <div>
                                <SmartLink
                                    routeName="admin.preparation.index"
                                    href={route('admin.preparation.index')}
                                    className="text-[11px] text-[#1d4ed8]"
                                >
                                    {t('لوحة التجهيز')}
                                </SmartLink>
                            </div>
                        )}
                    </>
                );
            },
        },
        { key: 'status', header: 'الحالة', cell: (r) => <span className={r.cancelled ? 'text-[#b91c1c]' : ''}>{t(r.status)}</span> },
        {
            key: 'actions',
            header: '',
            cell: (r) => {
                const link = documentLink(r);

                return (
                    <SmartLink routeName={link.routeName} href={link.href} className="text-[#6b7280] hover:text-[#111]" aria-label={t('عرض التفاصيل')}>
                        <Eye className="size-4" />
                    </SmartLink>
                );
            },
        },
    ];

    const tableFilters: Filter<SaleRow>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'state',
            options: [
                { label: 'سارية', value: 'live' },
                { label: 'ملغاة', value: 'cancelled' },
            ],
        },
        {
            label: 'جميع الموظفين',
            param: 'seller',
            options: [
                ...sellers,
                { label: 'الموقع الإلكتروني', value: 'website' },
                // بلا حساب موظّف: طلبٌ قديمٌ باسمٍ محفوظ، أو ما لا يُعرف بائعُه — لا يُنسب إلى أحد
                { label: 'غير مرتبط بحساب موظف', value: 'none' },
            ],
        },
        ...(source === null
            ? [
                  {
                      label: 'جميع مصادر البيع',
                      param: 'source',
                      options: [
                          { label: 'نقطة البيع', value: 'pos' },
                          { label: 'الموقع الإلكتروني', value: 'website' },
                          { label: 'فاتورة عميل', value: 'invoice' },
                          // بيعاتُ ما قبل حفظ المصدر — تُقال باسمها ولا تُخمَّن (`SalesChannel::UNKNOWN`)
                          { label: 'غير محدّدة', value: 'unknown' },
                      ],
                  },
              ]
            : []),
        {
            label: 'كل حالات السداد',
            param: 'payment_state',
            options: paymentStates.map((s) => ({ label: s, value: s })),
        },
        {
            label: 'كل مراحل التجهيز',
            param: 'prep',
            options: Object.entries(prepStages).map(([value, label]) => ({ label, value })),
        },
        {
            label: 'عدد الصفوف',
            param: 'per_page',
            options: [20, 50, 100].map((n) => ({ label: String(n), value: String(n) })),
        },
    ];

    const title = source === 'pos' ? 'مبيعات نقطة البيع' : source === 'website' ? 'طلبات الموقع الإلكتروني' : 'جميع عمليات البيع';

    return (
        <AdminLayout title={t(title)}>
            <PageHeader
                title={t(title)}
                subtitle={t('كلُّ بيعة في متجرك من مكانٍ واحد: من باعها، ومن أين، وما سُدّد منها، وأين وصل تجهيزها.')}
                actions={
                    <ExportMenu
                        xlsx={route('admin.customerInvoices.sales.xlsx', exportArgs)}
                        pdf={route('admin.customerInvoices.sales.pdf', exportArgs)}
                        csv={route('admin.customerInvoices.sales.csv', exportArgs)}
                    />
                }
            />

            <SectionTabs
                tabs={CUSTOMER_INVOICE_TABS}
                current={
                    source === 'pos'
                        ? 'admin.customerInvoices.posSales'
                        : source === 'website'
                          ? 'admin.customerInvoices.websiteOrders'
                          : 'admin.customerInvoices.sales'
                }
            />

            {branch && (
                <div
                    data-testid="branch-notice"
                    className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e]"
                >
                    <span>{t('تعرض هذه الصفحة فرع «:name» وحده — الفرع مختار من الترويسة.', { name: branch })}</span>
                    <SmartLink routeName="admin.branch.switch" href={route('admin.branch.switch', 'all')} className="font-medium underline">
                        {t('عرض كل الفروع')}
                    </SmartLink>
                </div>
            )}

            {source !== null && (unknownCount ?? 0) > 0 && (
                <div
                    data-testid="unknown-source-notice"
                    className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e5e7eb] bg-[#f9fafb] px-4 py-3 text-[13px] text-[#4b5563]"
                >
                    <span>
                        {t(':n عملية بيع سُجّلت قبل أن يُحفظ مصدرُ البيع — لا تظهر هنا، وتظهر في «جميع عمليات البيع» بمصدر «غير محدّدة».', {
                            n: number(unknownCount ?? 0),
                        })}
                    </span>
                    <SmartLink
                        routeName="admin.customerInvoices.sales"
                        href={route('admin.customerInvoices.sales', { source: 'unknown' })}
                        className="font-medium underline"
                    >
                        {t('عرضها')}
                    </SmartLink>
                </div>
            )}

            {period && <PeriodControls period={period} params={filters} />}

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('عدد العمليات'), value: number(totals.count), icon: 'receipt', color: 'info' }} index={0} />
                <StatCard stat={{ label: t('قيمة المبيعات'), value: m(totals.sales), icon: 'wallet', color: 'primary' }} index={1} />
                <StatCard stat={{ label: t('المحصّل'), value: m(totals.collected), icon: 'banknote', color: 'success' }} index={2} />
                <StatCard stat={{ label: t('المتبقي'), value: m(totals.outstanding), icon: 'alert-triangle', color: 'warning' }} index={3} />
            </div>
            {totals.cancelled > 0 && (
                <p className="-mt-2 mb-3 text-[12px] text-[#9ca3af]">
                    {t('منها :n ملغاة — تُعرض ولا تدخل قيمة المبيعات.', { n: number(totals.cancelled) })}
                </p>
            )}

            <DataTable
                rows={rows}
                columns={columns}
                rowKey={(r) => r.key}
                searchPlaceholder="رقم العملية أو العميل أو الموظف"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا عمليات بيع بعد"
                server={{ pagination, params: filters }}
            />
        </AdminLayout>
    );
}

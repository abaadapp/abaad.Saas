import SmartLink from '@/Components/SmartLink';
import {
    Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow,
} from '@/Components/ui/table';
import { useTranslate } from '@/lib/i18n';

/**
 * سجلّا العميل في صفحته: ما اشتراه، وما دفعه.
 *
 * المشترياتُ صفوفُ سجلّ عمليات البيع نفسُه (`SalesRegister::customerRows`):
 * طلبُ الموقع وبيعةُ الصندوق والفاتورةُ المستقلّة، بحال سدادها. والطلبُ الذي
 * غطّته فاتورةٌ صفٌّ واحد يُذكر فيه رقمُها — لا شراءٌ ثانٍ.
 *
 * والتحصيلاتُ كلُّ مالٍ وصل (`CustomerCollections::rows`): بابُه، ومستندُه إن
 * كان، وما لم يُخصَّص منه رصيدٌ للعميل. ورابطُ الدفع المستقلّ هنا، لا هناك.
 */

export interface PurchaseRow {
    key: string;
    kind: 'order' | 'invoice';
    id: number;
    number: string | null;
    type: string;
    source: string;
    source_label: string;
    date: string;
    amount: number;
    credited: number | null;
    /** ما زادته الإشعاراتُ المدينة على الفاتورة كما صدرت */
    debited?: number;
    pay_state: string;
    cover: { id: number; number: string | null } | null;
    status: string;
    cancelled: boolean;
}

export interface PurchaseTotals {
    count: number;
    sales: number;
    collected: number;
    outstanding: number;
    cancelled: number;
}

export interface CollectionRow {
    key: string;
    /** تحصيلٌ مسجَّل، أو الدفعُ عند البيع، أو تحصيلُ متبقٍّ أو ردُّ فرقٍ بعده */
    kind: 'payment' | 'sale' | 'balance' | 'refund';
    id: number;
    number: string | null;
    date: string;
    method: string;
    amount: number;
    state: string;
    /** مالٌ وصل فعلًا — لا ملغى ولا مرتجع ولا شيكٌ تحت التحصيل */
    counted: boolean;
    /** شيكٌ تحت التحصيل: يُعرض بحاله ولا يُجمع إلى المقبوض */
    pending: boolean;
    /** ملغى أو مرتجع — يُشطب */
    void: boolean;
    reference: string | null;
    link: string | null;
    invoices: { id: number; number: string | null; amount: number; orders: { id: number; number: string }[] }[];
    order: { id: number; number: string; source: string | null } | null;
    credit: number;
}

export interface CollectionTotals {
    received: number;
    pending: number;
}

/** ألوانُ حال السداد — ألوانُ سجلّ عمليات البيع نفسُها */
export const payTone = (state: string) =>
    state === 'مدفوع' ? 'bg-[#ecfdf5] text-[#047857]'
    : state === 'ملغى' ? 'bg-[#f4f4f5] text-[#71717a]'
    : state === 'مدفوع جزئيًا' ? 'bg-[#fffbeb] text-[#b45309]'
    : 'bg-[#eff6ff] text-[#1d4ed8]';

const collectionTone = (state: string) =>
    state === 'مقبوض' || state === 'محصَّل' ? 'bg-[#ecfdf5] text-[#047857]'
    : state === 'مردود' ? 'bg-[#f5f3ff] text-[#6d28d9]'
    : state === 'تحت التحصيل' ? 'bg-[#fffbeb] text-[#b45309]'
    : state === 'مرتجع' ? 'bg-[#fef2f2] text-[#b91c1c]'
    : 'bg-[#f4f4f5] text-[#71717a]';

const Pill = ({ tone, children }: { tone: string; children: React.ReactNode }) => (
    <span className={'whitespace-nowrap rounded-full px-2 py-1 text-[11px] ' + tone}>{children}</span>
);

function DocLink({ kind, id, number }: { kind: 'order' | 'invoice'; id: number; number: string | null }) {
    return kind === 'order' ? (
        <SmartLink routeName="admin.orders.show" href={route('admin.orders.show', number ?? '')}
            className="font-mono font-medium text-[#6d28d9] hover:underline">{number ?? `#${id}`}</SmartLink>
    ) : (
        <SmartLink routeName="admin.customerInvoices.show" href={route('admin.customerInvoices.show', id)}
            className="font-mono font-medium text-[#6d28d9] hover:underline">{number ?? `#${id}`}</SmartLink>
    );
}

export function PurchaseHistory({ rows, totals, money }: { rows: PurchaseRow[]; totals: PurchaseTotals; money: (n: number) => string }) {
    const t = useTranslate();

    return (
        <>
            <div className="flex flex-wrap gap-x-6 gap-y-1 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-3 text-[13px] text-[#4b4b4b]" data-testid="purchase-totals">
                <span>{t('المشتريات')}: <b className="tabular-nums text-[#111]">{money(totals.sales)}</b></span>
                {/* «المسدَّد» كما تقرؤه الفاتورة — والشيكُ تحت التحصيل يسدّدها ولم يصل مالُه بعد */}
                <span>{t('المسدَّد منها')}: <b className="tabular-nums text-[#047857]">{money(totals.collected)}</b></span>
                <span>{t('المتبقّي عليها')}: <b className="tabular-nums text-[#b45309]">{money(totals.outstanding)}</b></span>
            </div>
            <Table data-testid="customer-purchases">
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        {['التاريخ', 'الرقم', 'المصدر', 'القيمة', 'حالة الدفع'].map((h) => (
                            <TableHead key={h}>{t(h)}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 ? (
                        <TableEmpty colSpan={5}>{t('لا مشتريات لهذا العميل بعد')}</TableEmpty>
                    ) : rows.map((r) => (
                        <TableRow key={r.key} data-testid="customer-purchase-row" className={r.cancelled ? 'opacity-60' : undefined}>
                            <TableCell dir="ltr" className="whitespace-nowrap text-[#6b7280]">{r.date}</TableCell>
                            <TableCell className="whitespace-nowrap">
                                <DocLink kind={r.kind} id={r.id} number={r.number} />
                                {/* الطلبُ المفوتَر يُحصَّل على فاتورته — تُذكر هنا ولا تُعدّ شراءً ثانيًا */}
                                {r.kind === 'order' && r.cover && (
                                    <span className="block text-[11px] text-[#6b7280]">
                                        {t('فاتورة')}{' '}
                                        <DocLink kind="invoice" id={r.cover.id} number={r.cover.number} />
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-[#4b4b4b]">{t(r.kind === 'invoice' ? 'فاتورة مستقلة' : r.source_label)}</TableCell>
                            <TableCell className="whitespace-nowrap tabular-nums font-medium">
                                <span className={r.cancelled ? 'line-through' : undefined}>{money(r.amount)}</span>
                                {!r.cancelled && (r.credited ?? 0) > 0 && (
                                    <span className="block text-[11px] font-normal text-[#b45309]">
                                        {t('إشعار دائن')} −{money(r.credited ?? 0)}
                                    </span>
                                )}
                                {!r.cancelled && (r.debited ?? 0) > 0 && (
                                    <span className="block text-[11px] font-normal text-[#1d4ed8]">
                                        {t('إشعار مدين')} +{money(r.debited ?? 0)}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell><Pill tone={payTone(r.pay_state)}>{t(r.pay_state)}</Pill></TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </>
    );
}

export function CollectionHistory({ rows, totals, money }: { rows: CollectionRow[]; totals: CollectionTotals; money: (n: number) => string }) {
    const t = useTranslate();

    return (
        <>
            <div className="flex flex-wrap gap-x-6 gap-y-1 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-3 text-[13px] text-[#4b4b4b]" data-testid="collection-totals">
                <span>{t('المقبوض فعلًا')}: <b className="tabular-nums text-[#111]">{money(totals.received)}</b></span>
                {totals.pending > 0 && (
                    <span data-testid="collection-pending">
                        {t('شيكات تحت التحصيل')}: <b className="tabular-nums text-[#b45309]">{money(totals.pending)}</b>
                        <span className="text-[#9ca3af]"> — {t('لا تُجمع حتى تُصرف')}</span>
                    </span>
                )}
            </div>
            <Table data-testid="customer-collections">
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        {['التاريخ', 'الرقم', 'الوسيلة', 'المبلغ', 'على', 'الحالة'].map((h) => (
                            <TableHead key={h}>{t(h)}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 ? (
                        <TableEmpty colSpan={6}>{t('لا تحصيلات لهذا العميل بعد')}</TableEmpty>
                    ) : rows.map((r) => (
                        <TableRow key={r.key} data-testid="customer-collection-row" className={r.void ? 'opacity-60' : undefined}>
                            <TableCell dir="ltr" className="whitespace-nowrap text-[#6b7280]">{r.date}</TableCell>
                            <TableCell className="whitespace-nowrap">
                                {r.kind === 'payment' ? (
                                    <SmartLink routeName="admin.customerPayments.show" href={route('admin.customerPayments.show', r.id)}
                                        className="font-mono font-medium text-[#6d28d9] hover:underline">{r.number ?? `#${r.id}`}</SmartLink>
                                ) : (
                                    <span className="text-[12px] text-[#6b7280]">
                                        {t(r.kind === 'balance' ? 'تحصيل متبقٍّ' : r.kind === 'refund' ? 'ردّ فرق' : 'عند البيع')}
                                    </span>
                                )}
                                {r.link && <span className="block text-[11px] text-[#6b7280]">{t('رابط دفع')} <span className="font-mono">{r.link}</span></span>}
                            </TableCell>
                            <TableCell className="text-[#4b4b4b]">{t(r.method === 'بطاقة' ? 'فيزا' : r.method)}</TableCell>
                            <TableCell className="whitespace-nowrap tabular-nums font-medium">
                                <span className={r.void ? 'line-through' : undefined}>{money(r.amount)}</span>
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-[12px]">
                                {r.order && <span className="block">{t('طلب')} <DocLink kind="order" id={r.order.id} number={r.order.number} /></span>}
                                {r.invoices.map((i) => (
                                    <span key={i.id} className="block">
                                        {/* الرقمُ ومبلغُه معزولان بفاصل — بلا عزلٍ يلتصق رقمٌ لاتينيّ بمبلغه في سطرٍ عربيّ */}
                                        <bdi><DocLink kind="invoice" id={i.id} number={i.number} /></bdi>
                                        <span className="text-[#9ca3af]"> · </span>
                                        <bdi className="tabular-nums text-[#6b7280]">{money(i.amount)}</bdi>
                                        {i.orders.map((o) => (
                                            <span key={o.id} className="ms-1 text-[#6b7280]">· <DocLink kind="order" id={o.id} number={o.number} /></span>
                                        ))}
                                    </span>
                                ))}
                                {/* رابطٌ مستقلّ أو دفعةٌ مقدّمة — رصيدٌ له، لا شراء */}
                                {r.credit > 0 && (
                                    <span className="block text-[#6d28d9]">{t('رصيد للعميل')} <span className="tabular-nums">{money(r.credit)}</span></span>
                                )}
                                {!r.order && r.invoices.length === 0 && r.credit <= 0 && <span className="text-[#9ca3af]">—</span>}
                            </TableCell>
                            <TableCell><Pill tone={collectionTone(r.state)}>{t(r.state)}</Pill></TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </>
    );
}

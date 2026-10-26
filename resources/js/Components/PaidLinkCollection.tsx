import { usePage } from '@inertiajs/react';
import { FileDown, Link2, Receipt } from 'lucide-react';

import DocumentMeta from '@/Components/DocumentMeta';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTenantLink } from '@/lib/exportLink';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { SaleHandover, SaleProducts } from '@/types/models';

/**
 * تحصيلٌ عبر رابط دفع كما يُفتح من «المبيعات» — `PaidLinksList::detail`.
 *
 * مالٌ قبضته Paymob عن فاتورةٍ (بيعُها محسوبٌ فيها) أو رصيدًا للعميل حين لا
 * فاتورة. فلا يُقرأ بيعًا ثانيًا ولا يُضاف إلى إجمالي المبيعات.
 */
export interface PaidCollection {
    key: string;
    /** الرابطُ نفسُه — به يُفتح من «عرض» */
    link_id: number;
    /** رقمُ سند القبض */
    id: string;
    /** مرجعُ العمليّة عند Paymob */
    reference: string;
    customer: string;
    type: 'تحصيل فاتورة' | 'تحصيل عبر رابط دفع';
    invoice: { id: number; number: string } | null;
    /** الطلباتُ التي صدرت عنها الفاتورة — بيعُها هناك */
    orders: string[];
    /** فرعُ فاتورته (أو تجهيزها أو طلبها) — والرابطُ بلا فاتورةٍ لا فرعَ له */
    branch?: string | null;
    /** أسماءُ الفروع حين تعدّدت طلباتُ الفاتورة — تفاصيلُ «فروع متعددة» */
    branches?: string[];
    /** منشئُ رابط الدفع — «الموظف» في صفّ التحصيل */
    creator?: string | null;
    /** بنودُ فاتورته — `null` لرابطٍ بلا فاتورة: «لا توجد منتجات مرتبطة» */
    products?: SaleProducts | null;
    /** موعدُ تجهيز الفاتورة أو طلبِها — و«مواعيد متعددة» بتفاصيلها */
    handover?: SaleHandover | null;
    amount: number;
    date: string;
    status: string;
    /** دفعةٌ ثانيةٌ على الرابط أو استرجاعٌ أبلغت به Paymob */
    review: boolean;
    payment: { id: number; number: string };
    /** مرجعُ الرابط عندنا ووصفُه — في صفحته وحدها */
    link_reference?: string | null;
    description?: string | null;
}

/** «عرض» صفّ التحصيل: فاتورتُه الأصليّة إن كانت، وإلّا صفحةُ تحصيله */
export function collectionHref(c: Pick<PaidCollection, 'invoice' | 'link_id'>): { routeName: string; href: string } {
    return c.invoice
        ? { routeName: 'admin.customerInvoices.show', href: route('admin.customerInvoices.show', { id: c.invoice.id, link: c.link_id }) }
        : { routeName: 'admin.orders.collection', href: route('admin.orders.collection', c.link_id) };
}

/**
 * بطاقةُ التحصيل — حالُه ومبلغُه ويومُه ومرجعُ Paymob وسندُه، وعن أيّ فاتورةٍ
 * وطلبٍ دُفع. وسندُ القبض صفحةُ «المالية»: رابطُه وطباعتُه لمن يفتحها وحده.
 */
export default function PaidLinkCollectionCard({ collection: c, mayOpenReceipts }: { collection: PaidCollection; mayOpenReceipts: boolean }) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    // ورقةُ السند مسارٌ محروس: تحمل نشاطَ الصفحة (`TenantExportGuard`)
    const tenant = useTenantLink();

    return (
        <Card className="space-y-3 border-[#ddd6fe] p-4 sm:p-5" data-testid="paid-collection">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 font-bold text-[#111]">
                    <Link2 className="size-4 text-[#6d28d9]" />
                    {t('تحصيل عبر رابط دفع')}
                </h2>
                {c.review ? (
                    <Badge variant="warning">{t('مدفوع — يحتاج مراجعة')}</Badge>
                ) : (
                    <Badge status="مدفوع">{t('مدفوع')}</Badge>
                )}
            </div>

            <DocumentMeta
                cells={[
                    { label: 'المبلغ المحصّل عبر Paymob', value: money(c.amount, context!.currency) },
                    { label: 'تاريخ الدفع', value: c.date, ltr: true },
                    { label: 'مرجع عملية Paymob', value: c.reference, ltr: true },
                    c.link_reference ? { label: 'مرجع الرابط', value: c.link_reference, ltr: true } : null,
                    { label: 'رقم سند القبض', value: c.payment.number, ltr: true },
                    { label: 'العميل', value: c.customer || '—' },
                    { label: 'أنشأ الرابط', value: c.creator || t('غير معروف') },
                    c.branch ? { label: 'الفرع', value: c.branches && c.branches.length > 0 ? `${c.branch}: ${c.branches.join('، ')}` : c.branch } : null,
                    {
                        label: 'الفاتورة',
                        value: c.invoice ? (
                            c.invoice.number
                        ) : (
                            <span className="text-[#92400e]" data-testid="collection-no-invoice">
                                {t('لا توجد فاتورة مرتبطة بهذا التحصيل')}
                            </span>
                        ),
                    },
                    c.orders.length > 0
                        ? {
                              label: 'عن الطلب',
                              value: <OrderLinks numbers={c.orders} />,
                          }
                        : null,
                    c.description ? { label: 'وصف الرابط', value: c.description, wide: true } : null,
                ]}
            />

            <p className="text-[12px] text-[#6b7280]">
                {t('تحصيلٌ لا بيعٌ جديد: البيعُ محسوبٌ في فاتورته، ولا يُضاف هذا إلى إجمالي المبيعات.')}
            </p>

            {mayOpenReceipts && (
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <SmartLink routeName="admin.customerPayments.show" href={route('admin.customerPayments.show', c.payment.id)}>
                            <Receipt />
                            {t('سند القبض')}
                        </SmartLink>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <a href={tenant(route('admin.customerPayments.pdf', c.payment.id))} target="_blank" rel="noreferrer">
                            <FileDown />
                            {t('طباعة سند القبض')}
                        </a>
                    </Button>
                </div>
            )}
        </Card>
    );
}

/** الطلباتُ روابطَ إلى صفحاتها — البيعُ الأصليّ هناك */
export function OrderLinks({ numbers }: { numbers: string[] }) {
    return (
        <span className="flex flex-wrap gap-x-2 gap-y-1" data-testid="order-links">
            {numbers.map((n) => (
                <SmartLink key={n} routeName="admin.orders.show" href={route('admin.orders.show', n)} className="font-mono text-[#6d28d9] hover:underline">
                    {n}
                </SmartLink>
            ))}
        </span>
    );
}

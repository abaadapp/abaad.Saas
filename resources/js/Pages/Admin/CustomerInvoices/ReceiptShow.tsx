import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import DocumentMeta from '@/Components/DocumentMeta';
import DocumentPanel, { DocumentAside } from '@/Components/DocumentPanel';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Allocation {
    id: number;
    amount: number;
    invoice_id: number;
    invoice: string | null;
}

interface Props {
    receipt: {
        id: number;
        number: string;
        amount: number;
        allocated: number;
        unallocated: number;
        method: string;
        bank: string | null;
        reference: string | null;
        occurred_at: string | null;
        notes: string | null;
        customer: string | null;
        customer_id: number | null;
        cheque_status: string | null;
        cheque_due_at: string | null;
        cancelled_at: string | null;
        cancellation_reason: string | null;
        allocations: Allocation[];
    };
    paper: { html: string; size: string; url: string };
}

/**
 * سندُ قبضٍ واحد — ما استُلم، وأين ذهب.
 *
 * ═══ والسندُ كان يُقرأ مقطّعًا ═══
 *
 * من دفع بمئةٍ سُدِّدت بها ثلاثُ فواتير كان سندُه سطرًا في كلٍّ منها: ثلاثةُ
 * مواضعَ تقول كم أخذت هي، ولا موضعَ يقول كم دفع هو. ومن يراجع تحصيلًا أمام
 * زبونٍ يحتاج الورقةَ كاملة.
 *
 * وما لم يُوزَّع يُقال هنا صراحةً — رصيدٌ عند المتجر لباقي فواتير صاحبه، لا
 * مبلغٌ ضاع بين السطور.
 */
export default function ReceiptShow() {
    const { receipt, paper, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [previewing, setPreviewing] = useState(false);

    const cancelled = receipt.cancelled_at !== null;

    return (
        <AdminLayout title={receipt.number}>
            <BackLink
                routeName="admin.finance.receivables"
                href={route('admin.finance.receivables')}
                label="الذمم"
            />

            <PageHeader
                title={receipt.number}
                subtitle={`${t('سند قبض')}${receipt.customer ? ` — ${receipt.customer}` : ''}`}
                actions={
                    <Button variant="outline" className="xl:hidden" onClick={() => setPreviewing(true)}>
                        <FileText />
                        {t('معاينة الورقة')}
                    </Button>
                }
            />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    {/* والملغى يُقرأ أوّلًا: سندٌ أُلغي ويُقرأ ساريًا سندٌ يُصدَّق */}
                    {cancelled && (
                        <Card className="border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] leading-relaxed text-[#b91c1c]">
                            <span className="font-bold">
                                {t('أُلغي هذا السند')} — {receipt.cancelled_at}
                            </span>
                            {receipt.cancellation_reason && <> · {receipt.cancellation_reason}</>}
                        </Card>
                    )}

                    <DocumentMeta
                        cells={[
                            { label: 'تاريخ القبض', value: receipt.occurred_at || '—', ltr: true },
                            { label: 'وسيلة الدفع', value: t(receipt.method) },
                            receipt.bank !== null && { label: 'الحساب البنكي', value: receipt.bank },
                            receipt.reference !== null && { label: 'المرجع', value: receipt.reference, ltr: true },
                            receipt.cheque_status !== null && {
                                label: 'حال الشيك',
                                value: <Badge variant="neutral">{t(receipt.cheque_status)}</Badge>,
                            },
                            receipt.cheque_due_at !== null && {
                                label: 'استحقاق الشيك',
                                value: receipt.cheque_due_at,
                                ltr: true,
                            },
                            receipt.notes !== null && { label: 'ملاحظات', value: receipt.notes, wide: true },
                        ]}
                    />

                    <Card className="p-4 sm:p-5">
                        <h2 className="mb-3 font-bold text-[#111]">{t('سُدِّد من')}</h2>

                        {receipt.allocations.length === 0 ? (
                            <p className="text-[13px] leading-relaxed text-[#6b7280]">
                                {t('لم يُخصَّص هذا المبلغُ لفاتورةٍ بعد — وهو رصيدٌ للجهة عند المتجر.')}
                            </p>
                        ) : (
                            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] text-[13px]">
                                {receipt.allocations.map((a) => (
                                    <li key={a.id} className="flex items-center justify-between gap-3 py-2">
                                        <SmartLink
                                            routeName="admin.customerInvoices.show"
                                            href={route('admin.customerInvoices.show', a.invoice_id)}
                                            className="font-mono text-[#6d28d9] hover:underline"
                                        >
                                            {a.invoice ?? `#${a.invoice_id}`}
                                        </SmartLink>
                                        <span className="tabular-nums text-[#111]">{m(a.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <dl className="mt-4 ms-auto max-w-xs border-t border-[var(--ui-border,#e8e8e8)] pt-3 text-[13px]">
                            <div className="flex items-center justify-between gap-3">
                                <dt className="font-bold text-[#111]">{t('المبلغ المستلم')}</dt>
                                <dd className="font-bold tabular-nums text-[#111]">{m(receipt.amount)}</dd>
                            </div>
                            {receipt.unallocated > 0 && (
                                <div className="mt-1 flex items-center justify-between gap-3">
                                    <dt className="text-[#b45309]">{t('رصيد لم يُخصَّص بعد')}</dt>
                                    <dd className="tabular-nums text-[#b45309]">{m(receipt.unallocated)}</dd>
                                </div>
                            )}
                        </dl>
                    </Card>
                </div>

                <DocumentAside className="xl:col-span-2">
                    <DocumentPanel
                        html={paper.html}
                        size={paper.size}
                        url={paper.url}
                        filename={`${receipt.number}.pdf`}
                        label={`${t('سند قبض')} ${receipt.number}`}
                        open={previewing}
                        onOpenChange={setPreviewing}
                    />
                </DocumentAside>
            </div>
        </AdminLayout>
    );
}

import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { FileText, MessageCircle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Invoice {
    id: number;
    number: string;
    customer: string;
    customer_id: number;
    status: string;
    state: string;
    issued_at: string | null;
    due_at: string | null;
    total: number;
    paid: number;
    outstanding: number;
    days_overdue: number;
    po_number: string | null;
    contract_number: string | null;
    external_reference: string | null;
    department: string | null;
    cost_center: string | null;
    attention_to: string | null;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    notes: string | null;
    orders: string[];
    cancellation_reason: string | null;
    items: { description: string; quantity: number; unit_price: number; discount: number; tax_rate: number; line_total: number }[];
    payments: { number: string; amount: number; method: string; at: string | null }[];
    credit_notes: { number: string; amount: number; reason: string | null; at: string | null }[];
}

/** فاتورةُ عميل — بنودُها وتحصيلاتُها وما بقي منها */
export default function CustomerInvoiceShow() {
    const { invoice, context } = usePage<PageProps<{ invoice: Invoice }>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const [paying, setPaying] = useState(false);

    const issue = useForm({});
    const cancel = useForm({ reason: '' });
    const remind = useForm({});

    return (
        <AdminLayout title={invoice.number}>
            <PageHeader
                title={invoice.number}
                subtitle={`${invoice.customer} — ${invoice.state}`}
                actions={
                    <>
                        {invoice.status === 'مسودة' && (
                            <Button disabled={issue.processing} onClick={() => issue.post(`/admin/customer-invoices/${invoice.id}/issue`, { preserveScroll: true })}>
                                {t('إصدار الفاتورة')}
                            </Button>
                        )}
                        {invoice.status === 'صادرة' && (
                            <>
                                <Button variant="outline" onClick={() => setPaying((v) => !v)}>
                                    {t('تسجيل دفعة')}
                                </Button>
                                {invoice.outstanding > 0 && (
                                    <Button
                                        variant="outline"
                                        disabled={remind.processing}
                                        onClick={() => remind.post(`/admin/customer-invoices/${invoice.id}/remind`, { preserveScroll: true })}
                                    >
                                        <MessageCircle />
                                        {t('تذكير بالسداد')}
                                    </Button>
                                )}
                            </>
                        )}
                        <Button variant="outline" asChild>
                            <a href={`/admin/customer-invoices/${invoice.id}/pdf`} target="_blank" rel="noreferrer">
                                <FileText />
                                {t('تصدير PDF')}
                            </a>
                        </Button>
                    </>
                }
            />

            {invoice.status === 'ملغاة' && (
                <Card className="mb-4 border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]">
                    {t('فاتورة ملغاة')} — {invoice.cancellation_reason}
                </Card>
            )}

            {paying && <PayForm invoice={invoice} onDone={() => setPaying(false)} />}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="p-4 lg:col-span-2">
                    <table className="w-full text-[13px]">
                        <thead className="text-[12px] text-[#71717a]">
                            <tr>
                                <th className="p-2 text-start">{t('البيان')}</th>
                                <th className="p-2 text-start">{t('الكمية')}</th>
                                <th className="p-2 text-start">{t('السعر')}</th>
                                <th className="p-2 text-start">{t('الإجمالي')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.items.map((it, i) => (
                                <tr key={i} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                    <td className="p-2">{it.description}</td>
                                    <td className="p-2">{it.quantity}</td>
                                    <td className="p-2">{m(it.unit_price)}</td>
                                    <td className="p-2">{m(it.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>

                <Card className="space-y-2 p-4 text-[13px]">
                    <Row label={t('المجموع الفرعي')} value={m(invoice.subtotal)} />
                    {invoice.discount_total > 0 && <Row label={t('الخصم')} value={m(invoice.discount_total)} />}
                    {invoice.tax_total > 0 && <Row label={t('الضريبة')} value={m(invoice.tax_total)} />}
                    <Row label={t('الإجمالي')} value={m(invoice.total)} strong />
                    <Row label={t('المسدَّد')} value={m(invoice.paid)} />
                    <Row label={t('الباقي')} value={m(invoice.outstanding)} strong />
                    {invoice.due_at && <Row label={t('الاستحقاق')} value={invoice.due_at} />}
                    {invoice.po_number && <Row label={t('أمر الشراء')} value={invoice.po_number} />}
                    {invoice.contract_number && <Row label={t('رقم العقد')} value={invoice.contract_number} />}
                    {invoice.department && <Row label={t('القسم')} value={invoice.department} />}
                    {invoice.attention_to && <Row label={t('عناية')} value={invoice.attention_to} />}
                </Card>
            </div>

            {invoice.payments.length > 0 && (
                <Card className="mt-4 p-4">
                    <h3 className="mb-2 text-[13px] font-bold">{t('التحصيلات')}</h3>
                    {invoice.payments.map((p) => (
                        <div key={p.number} className="flex justify-between border-t border-[var(--ui-border,#e8e8e8)] py-2 text-[13px]">
                            <span>{p.number} — {p.method}</span>
                            <span dir="ltr">{p.at}</span>
                            <span>{m(p.amount)}</span>
                        </div>
                    ))}
                </Card>
            )}

            {invoice.credit_notes.length > 0 && (
                <Card className="mt-4 p-4">
                    <h3 className="mb-2 text-[13px] font-bold">{t('إشعارات دائن')}</h3>
                    {invoice.credit_notes.map((n) => (
                        <div key={n.number} className="flex justify-between border-t border-[var(--ui-border,#e8e8e8)] py-2 text-[13px]">
                            <span>{n.number} — {n.reason}</span>
                            <span>{m(n.amount)}</span>
                        </div>
                    ))}
                </Card>
            )}

            {invoice.status !== 'ملغاة' && (
                <Card className="mt-4 flex flex-wrap items-end gap-2 p-4">
                    <label className="flex-1 text-[13px]">
                        {t('سبب الإلغاء')}
                        <Input value={cancel.data.reason} onChange={(e) => cancel.setData('reason', e.target.value)} />
                        {cancel.errors.reason && <p className="text-[12px] text-[#b91c1c]">{cancel.errors.reason}</p>}
                    </label>
                    {/* والسببُ مطلوب: إلغاءٌ بلا سبب لا يُقرأ بعد شهر */}
                    <Button
                        variant="danger"
                        disabled={cancel.processing}
                        onClick={() => cancel.post(`/admin/customer-invoices/${invoice.id}/cancel`, { preserveScroll: true })}
                    >
                        {t('إلغاء الفاتورة')}
                    </Button>
                </Card>
            )}
        </AdminLayout>
    );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={'flex justify-between ' + (strong ? 'font-bold' : '')}>
            <span className="text-[#71717a]">{label}</span>
            <span>{value}</span>
        </div>
    );
}

function PayForm({ invoice, onDone }: { invoice: Invoice; onDone: () => void }) {
    const t = useTranslate();
    const form = useForm({
        customer_id: String(invoice.customer_id),
        customer_invoice_id: String(invoice.id),
        amount: String(invoice.outstanding),
        method: 'نقدي',
        occurred_at: '',
        external_reference: '',
    });

    return (
        <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
            <label className="text-[13px]">
                {t('المبلغ')}
                <Input value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                {form.errors.amount && <p className="text-[12px] text-[#b91c1c]">{form.errors.amount}</p>}
            </label>
            <label className="text-[13px]">
                {t('وسيلة الدفع')}
                <select
                    className="mt-1 block rounded-[8px] border border-[var(--ui-border,#e8e8e8)] p-2"
                    value={form.data.method}
                    onChange={(e) => form.setData('method', e.target.value)}
                >
                    {['نقدي', 'بطاقة', 'تحويل', 'شيك'].map((x) => (
                        <option key={x} value={x}>{t(x)}</option>
                    ))}
                </select>
            </label>
            <label className="text-[13px]">
                {t('التاريخ')}
                <Input type="date" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
            </label>
            <Button
                disabled={form.processing}
                onClick={() => form.post('/admin/customer-payments', { preserveScroll: true, onSuccess: onDone })}
            >
                {t('حفظ التحصيل')}
            </Button>
        </Card>
    );
}

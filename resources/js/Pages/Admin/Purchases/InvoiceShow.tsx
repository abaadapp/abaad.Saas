import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, FileText, Paperclip, Wallet, X } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import DocumentMeta from '@/Components/DocumentMeta';
import DocumentPanel, { DocumentAside } from '@/Components/DocumentPanel';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Invoice {
    id: number;
    reference: string;
    supplier: string | null;
    supplier_id: number | null;
    issued_at: string | null;
    due_at: string | null;
    subtotal: number;
    tax: number;
    total: number;
    paid: number;
    outstanding: number;
    status: string;
    approval_status: string;
    match_status: string | null;
    match_notes: string[];
    override_reason: string | null;
    rejection_reason: string | null;
    overdue: boolean;
    notes: string | null;
    submitted_by: string | null;
    approved_at: string | null;
    approved_by: string | null;
    rejected_at: string | null;
    rejected_by: string | null;
    has_attachment: boolean;
    attachment: string | null;
    attachment_name: string | null;
}

interface Payment {
    number: string;
    at: string | null;
    amount: number;
}

interface Props {
    invoice: Invoice;
    payments: Payment[];
    order: { id: number; number: string; total: number; received_value: number | null } | null;
    paper: { html: string; size: string; url: string };
    can: { approve: boolean; reject: boolean; pay: boolean };
    today: string;
}

const PENDING = 'بانتظار الاعتماد';
const APPROVED = 'معتمد';

/**
 * سندُ مورّدٍ واحد — ما فيه، ومن اعتمده، وما سُدّد منه.
 *
 * ═══ والبابُ الذي لم يكن ═══
 *
 * كان السندُ صفًّا في جدولٍ لا يُفتح: مرجعُ المورّد نصٌّ رماديّ، وكلُّ فعلٍ
 * عليه يقع في نافذةٍ فوق القائمة. فلا عنوانَ له يُرسَل إلى محاسب، ولا موضعَ
 * يقول من اعتمده ومتى، ولا كشفًا بما سُدّد منه ومن أيّ صندوق.
 *
 * وهو أكثرُ مستندات المشتريات حاجةً إلى صفحة: عليه تنشأ الذمّة، وبه تُراجَع
 * المطابقةُ مع أمر الشراء وما وصل فعلًا.
 *
 * ═══ وورقتُه ورقةُ أمره ═══
 *
 * السندُ ورقةُ المورّد لا ورقتُنا — انظر `SupplierInvoiceController::show`.
 * فما يُعرض إلى جانبه أمرُ الشراء المرتبط به، مسمًّى باسمه، ومرفقُ المورّد
 * يُفتح من الترويسة كما رُفع.
 */
export default function SupplierInvoiceShow() {
    const { invoice, payments, order, paper, can, today, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [previewing, setPreviewing] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [paying, setPaying] = useState(false);

    const pay = useForm({ amount: '', paid_at: today, from: 'cash' });

    const pending = invoice.approval_status === PENDING;
    const approved = invoice.approval_status === APPROVED;

    const approve = () =>
        router.post(route('admin.purchases.invoices.approve', invoice.id), {}, { preserveScroll: true });

    const reject = () =>
        router.post(
            route('admin.purchases.invoices.reject', invoice.id),
            { reason },
            { preserveScroll: true, onFinish: () => setRejecting(false) },
        );

    return (
        <AdminLayout title={invoice.reference}>
            {/* الرجوعُ طريقٌ لا فعل: فوق الترويسة لا بين أزرارها */}
            <BackLink
                routeName="admin.purchases.invoices"
                href={route('admin.purchases.invoices')}
                label="كل سندات الموردين"
            />

            <PageHeader
                title={invoice.reference}
                subtitle={`${t('سند مورّد')}${invoice.supplier ? ` — ${invoice.supplier}` : ''}`}
                actions={
                    <>
                        {pending && can.approve && (
                            <Button variant="success" onClick={approve}>
                                <Check />
                                {t('اعتماد السند')}
                            </Button>
                        )}
                        {pending && can.reject && (
                            <Button variant="outline" className="text-[#b91c1c]" onClick={() => setRejecting(true)}>
                                <X />
                                {t('رفض')}
                            </Button>
                        )}
                        {/* والسدادُ بعد الاعتماد وحده — لا يخرج مالٌ مقابل دَينٍ لم يُقرّ */}
                        {approved && can.pay && invoice.outstanding > 0 && (
                            <Button onClick={() => setPaying(true)}>
                                <Wallet />
                                {t('سداد')}
                            </Button>
                        )}

                        {/* و«معاينة» على الشاشة الضيّقة وحدها — الورقةُ هناك أسفل البطاقات */}
                        <Button variant="outline" className="xl:hidden" onClick={() => setPreviewing(true)}>
                            <FileText />
                            {t('معاينة الورقة')}
                        </Button>

                        {/* وفاتورةُ المورّد نفسُها — الورقةُ التي رُفعت، لا ورقةٌ تُولَّد */}
                        {invoice.attachment && (
                            <Button variant="outline" asChild>
                                <a href={invoice.attachment} target="_blank" rel="noreferrer">
                                    <Paperclip />
                                    {invoice.attachment_name || t('فاتورة المورّد')}
                                </a>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    {/* ومرفقٌ موجودٌ لا يُقرأ يُقال، لا يُكتم */}
                    {invoice.has_attachment && !invoice.attachment && (
                        <Card className="border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]">
                            {t('مع هذا السند فاتورة مورّد — وفتحُ المرفقات صلاحيةٌ لا تملكها')}
                        </Card>
                    )}

                    {/* وسببُ الرفض يُقرأ أوّلًا: هو الخبرُ كلُّه على ورقةٍ مرفوضة */}
                    {invoice.rejection_reason && (
                        <Card className="border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] leading-relaxed text-[#b91c1c]">
                            <span className="font-bold">{t('سبب الرفض')}: </span>
                            {invoice.rejection_reason}
                        </Card>
                    )}

                    {/*
                        وملاحظاتُ المطابقة تُقرأ قبل الاعتماد لا بعده.

                        هي ما يقول إنّ السند يطلب أكثرَ ممّا وصل، أو يخالف سعرَ
                        الأمر. ومن يعتمد بلا قراءتها يُقرّ دَينًا لم يُقابَل بشيء.
                    */}
                    {invoice.match_notes.length > 0 && (
                        <Card className="border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] leading-relaxed text-[#92400e]">
                            <p className="font-bold">{t('مطابقة السند')}</p>
                            <ul className="mt-1 list-inside list-disc space-y-0.5">
                                {invoice.match_notes.map((n, i) => (
                                    <li key={i}>{n}</li>
                                ))}
                            </ul>
                            {invoice.override_reason && (
                                <p className="mt-2">
                                    <span className="font-bold">{t('سبب التجاوز')}: </span>
                                    {invoice.override_reason}
                                </p>
                            )}
                        </Card>
                    )}

                    <DocumentMeta
                        cells={[
                            {
                                label: 'حالة الاعتماد',
                                value: (
                                    <Badge
                                        variant={
                                            approved ? 'success' : invoice.approval_status === 'مرفوض' ? 'danger' : 'warning'
                                        }
                                    >
                                        {t(invoice.approval_status)}
                                    </Badge>
                                ),
                            },
                            {
                                label: 'حالة السداد',
                                value: (
                                    <Badge
                                        variant={
                                            invoice.status === 'مدفوع'
                                                ? 'success'
                                                : invoice.overdue
                                                  ? 'danger'
                                                  : 'neutral'
                                        }
                                    >
                                        {t(invoice.status)}
                                    </Badge>
                                ),
                            },
                            { label: 'المورّد', value: invoice.supplier || '—' },
                            {
                                label: 'أمر الشراء',
                                value: order ? (
                                    <SmartLink
                                        routeName="admin.purchases.show"
                                        href={route('admin.purchases.show', order.id)}
                                        className="font-mono text-[#6d28d9] hover:underline"
                                    >
                                        {order.number}
                                    </SmartLink>
                                ) : (
                                    '—'
                                ),
                            },
                            { label: 'تاريخ السند', value: invoice.issued_at || '—', ltr: true },
                            {
                                label: 'تاريخ الاستحقاق',
                                value: invoice.due_at ? (
                                    <span className={invoice.overdue ? 'font-bold text-[#b91c1c]' : undefined}>
                                        {invoice.due_at}
                                    </span>
                                ) : (
                                    '—'
                                ),
                                ltr: true,
                            },
                            invoice.match_status !== null && { label: 'المطابقة', value: t(invoice.match_status) },
                            invoice.submitted_by !== null && { label: 'سجّل السند', value: invoice.submitted_by },
                            invoice.approved_at !== null && {
                                label: 'اعتُمد',
                                value: `${invoice.approved_at}${invoice.approved_by ? ` — ${invoice.approved_by}` : ''}`,
                            },
                            invoice.rejected_at !== null && {
                                label: 'رُفض',
                                value: `${invoice.rejected_at}${invoice.rejected_by ? ` — ${invoice.rejected_by}` : ''}`,
                            },
                            invoice.notes !== null && { label: 'ملاحظات', value: invoice.notes, wide: true },
                        ]}
                    />

                    {/*
                        والمبالغُ من الخادم كما هي — لا يُعاد حسابُ شيءٍ هنا.

                        «المتبقّي» يأتي محسوبًا من `outstanding()`، وهو المصدرُ
                        نفسُه الذي يمنع السدادَ بأكثر منه. ونسخةٌ ثانيةٌ في
                        JavaScript تفترق عنه عند أوّل كسر.
                    */}
                    <Card className="p-4 sm:p-5">
                        <h2 className="mb-3 font-bold text-[#111]">{t('المبالغ')}</h2>

                        <dl className="ms-auto max-w-sm space-y-2 text-[13px]">
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('المجموع الفرعي')}</dt>
                                <dd className="tabular-nums">{m(invoice.subtotal)}</dd>
                            </div>
                            {invoice.tax > 0 && (
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-[#6b7280]">{t('الضريبة')}</dt>
                                    <dd className="tabular-nums">{m(invoice.tax)}</dd>
                                </div>
                            )}
                            <div className="flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] pt-2">
                                <dt className="font-bold text-[#111]">{t('الإجمالي')}</dt>
                                <dd className="font-bold tabular-nums text-[#111]">{m(invoice.total)}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('المسدَّد')}</dt>
                                <dd className="tabular-nums text-[#047857]">{m(invoice.paid)}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt className="font-bold text-[#111]">{t('المتبقّي')}</dt>
                                <dd
                                    className={`font-bold tabular-nums ${
                                        invoice.outstanding > 0 ? 'text-[#b91c1c]' : 'text-[#047857]'
                                    }`}
                                >
                                    {m(invoice.outstanding)}
                                </dd>
                            </div>
                        </dl>

                        {/*
                            وقيمةُ ما وصل على الأمر بجانب إجمالي السند.

                            هو المقياسُ الذي يُراجَع به: سندٌ بألفٍ على بضاعةٍ
                            وصل منها بستّمئة يُسأل عنه قبل أن يُعتمد.
                        */}
                        {order && order.received_value !== null && (
                            <p className="mt-4 border-t border-[var(--ui-border,#e8e8e8)] pt-3 text-[12px] leading-relaxed text-[#9ca3af]">
                                {t('قيمة ما استُلم على أمره')}:{' '}
                                <span className="font-semibold text-[#6b7280]">{m(order.received_value)}</span>
                                {' · '}
                                {t('إجمالي الأمر')}:{' '}
                                <span className="font-semibold text-[#6b7280]">{m(order.total)}</span>
                            </p>
                        )}
                    </Card>

                    {/*
                        وكشفُ السداد من الدفتر لا من عمودٍ واحد.

                        «سُدّد ٣٠ من ١٠٠» لا يكفي في مراجعة: متى، وبأيّ قيد،
                        ومن أيّ صندوق. وهذه الأسطرُ هي القيودُ نفسُها.
                    */}
                    {payments.length > 0 && (
                        <Card className="p-4 sm:p-5">
                            <h2 className="mb-3 font-bold text-[#111]">{t('السدادات')}</h2>
                            <table className="w-full text-[13px]">
                                <thead className="text-[12px] text-[#71717a]">
                                    <tr>
                                        <th className="p-2 text-start">{t('التاريخ')}</th>
                                        <th className="p-2 text-start">{t('رقم القيد')}</th>
                                        <th className="p-2 text-end">{t('المبلغ')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payments.map((p, i) => (
                                        <tr key={i} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                            <td className="p-2" dir="ltr">
                                                {p.at ?? '—'}
                                            </td>
                                            <td className="p-2 font-mono text-[12px] text-[#6b7280]">{p.number}</td>
                                            <td className="p-2 text-end font-semibold tabular-nums">{m(p.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Card>
                    )}
                </div>

                {/*
                    وورقةُ السند نفسِه إلى جانبه.

                    وكانت ورقةَ أمر الشراء: قاربت الغرضَ ولم تكن هي، وتغيب
                    حين يُسجَّل سندٌ بلا أمر — فيبقى نصفُ السندات بلا ورقة.
                    وأمرُ الشراء على بُعد نقرةٍ من بطاقة «أمر الشراء» أعلاه.
                */}
                <DocumentAside className="xl:col-span-2">
                    <DocumentPanel
                        html={paper.html}
                        size={paper.size}
                        url={paper.url}
                        filename={`${invoice.reference}.pdf`}
                        label={`${t('فاتورة مورّد')} ${invoice.reference}`}
                        note={t('سندُ ما على المتجر لهذا المورّد — وورقةُ المورّد نفسُها مرفقٌ يُفتح من الأعلى.')}
                        open={previewing}
                        onOpenChange={setPreviewing}
                    />
                </DocumentAside>
            </div>

            <Dialog open={rejecting} onOpenChange={(v) => !v && setRejecting(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('رفض السند')} — {invoice.reference}
                        </DialogTitle>
                    </DialogHeader>
                    {/* والحشوُ على الجسم: `DialogContent` بلا حشو — انظر ui/dialog */}
                    <div className="space-y-3 px-5 pb-2">
                        <p className="text-[13px] leading-relaxed text-[#6b7280]">
                            {t('السببُ يُقرأ في سجلّ السند بعد اليوم — اكتبه كما تقوله للمورّد.')}
                        </p>
                        <Input
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={t('سبب الرفض')}
                            aria-label={t('سبب الرفض')}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setRejecting(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button variant="danger" disabled={reason.trim() === ''} onClick={reject}>
                            {t('رفض السند')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={paying} onOpenChange={(v) => !v && setPaying(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('سداد')} — {invoice.reference}
                        </DialogTitle>
                    </DialogHeader>
                    {/* والحشوُ على الجسم كأختها */}
                    <form
                        className="space-y-3 px-5 pb-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            pay.post(route('admin.purchases.invoices.pay', invoice.id), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    pay.reset();
                                    setPaying(false);
                                },
                            });
                        }}
                    >
                        <label className="block text-[13px]">
                            <span className="text-[#6b7280]">
                                {t('المبلغ')} — {t('المتبقّي')} {m(invoice.outstanding)}
                            </span>
                            <Input
                                type="number"
                                step="0.001"
                                min="0.001"
                                max={invoice.outstanding}
                                value={pay.data.amount}
                                onChange={(e) => pay.setData('amount', e.target.value)}
                                required
                            />
                        </label>
                        {pay.errors.amount && <p className="text-[12px] text-[#b91c1c]">{pay.errors.amount}</p>}

                        <label className="block text-[13px]">
                            <span className="text-[#6b7280]">{t('تاريخ السداد')}</span>
                            <Input
                                type="date"
                                value={pay.data.paid_at}
                                onChange={(e) => pay.setData('paid_at', e.target.value)}
                                required
                            />
                        </label>

                        <label className="block text-[13px]">
                            <span className="text-[#6b7280]">{t('من')}</span>
                            <select
                                className="mt-1 h-9 w-full rounded-md border border-[var(--ui-border,#e8e8e8)] px-2 text-[13px]"
                                value={pay.data.from}
                                onChange={(e) => pay.setData('from', e.target.value)}
                            >
                                <option value="cash">{t('الصندوق')}</option>
                                <option value="bank">{t('البنك')}</option>
                            </select>
                        </label>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setPaying(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={pay.processing}>
                                {t('تسجيل السداد')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

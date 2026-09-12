import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, FileText, Paperclip, X } from 'lucide-react';

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
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Line {
    name: string;
    quantity: number;
    cost: number;
    total: number;
    ordered: number | null;
    received_before: number | null;
    remaining: number | null;
}

interface Note {
    id: number;
    number: string;
    status: string;
    supplier: string | null;
    branch: string | null;
    received_at: string | null;
    receiver: string | null;
    notes: string | null;
    approved_at: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    submitted_by: string | null;
    approved_by: string | null;
    rejected_by: string | null;
    value: number;
    has_attachment: boolean;
    attachment: string | null;
    attachment_name: string | null;
    items: Line[];
}

interface Props {
    note: Note;
    order: { id: number; number: string } | null;
    can: { approve: boolean; reject: boolean };
    paper: { html: string; size: string };
}

const PENDING = 'بانتظار الاعتماد';

/**
 * سندُ استلامٍ واحد — ما وصل، ومن استلمه، وما جرى للورقة بعدُ.
 *
 * ═══ والبابُ الذي لم يكن ═══
 *
 * كان السندُ يُقرأ في نافذةٍ فوق القائمة، ورقمُه في شاشة أمر الشراء يقود إلى
 * **ملفّ PDF** في لسانٍ آخر. فلا عنوانَ له يُرسَل إلى زميل، ولا رجوعَ منه
 * إلى أمره، ولا موضعَ يقول من اعتمده ومتى أو لمَ رُفض. ومن يراجع خلافًا مع
 * مورّدٍ يحتاج الثلاثة في نظرةٍ واحدة.
 *
 * والقرارُ يبقى هنا كما كان في القائمة: من يملك الاعتماد يعتمد من الورقة
 * التي يقرؤها، لا يعود إلى الطابور ليبحث عن سطرها.
 */
export default function ReceiptShow() {
    const { note, order, can, paper, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [previewing, setPreviewing] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');

    const pending = note.status === PENDING;

    const approve = () =>
        router.post(route('admin.purchases.receipts.approve', note.id), {}, { preserveScroll: true });

    const reject = () =>
        router.post(
            route('admin.purchases.receipts.reject', note.id),
            { reason },
            { preserveScroll: true, onFinish: () => setRejecting(false) },
        );

    return (
        <AdminLayout title={note.number}>
            {/* الرجوعُ طريقٌ لا فعل: فوق الترويسة لا بين أزرارها */}
            <BackLink
                routeName="admin.inventory.receipts"
                href={route('admin.inventory.receipts')}
                label="كل سندات الاستلام"
            />

            <PageHeader
                title={note.number}
                subtitle={`${t('سند استلام بضاعة')}${note.supplier ? ` — ${note.supplier}` : ''}`}
                actions={
                    <>
                        {pending && can.approve && (
                            <Button variant="success" onClick={approve}>
                                <Check />
                                {t('اعتماد الاستلام')}
                            </Button>
                        )}
                        {pending && can.reject && (
                            <Button variant="outline" className="text-[#b91c1c]" onClick={() => setRejecting(true)}>
                                <X />
                                {t('رفض')}
                            </Button>
                        )}

                        {/* و«معاينة» على الشاشة الضيّقة وحدها — الورقةُ هناك أسفل البطاقات */}
                        <Button variant="outline" className="xl:hidden" onClick={() => setPreviewing(true)}>
                            <FileText />
                            {t('معاينة الورقة')}
                        </Button>

                        {/* ورقةُ المورّد التي جاءت مع الشحنة — غيرُ سند أبعاد */}
                        {note.attachment && (
                            <Button variant="outline" asChild>
                                <a href={note.attachment} target="_blank" rel="noreferrer">
                                    <Paperclip />
                                    {note.attachment_name || t('ورقة المورّد')}
                                </a>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    {/* ومرفقٌ موجودٌ لا يُقرأ يُقال، لا يُكتم */}
                    {note.has_attachment && !note.attachment && (
                        <Card className="border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]">
                            {t('مع هذا السند ورقة مورّد — وفتحُ المرفقات صلاحيةٌ لا تملكها')}
                        </Card>
                    )}

                    {/* وسببُ الرفض يُقرأ أوّلًا: هو الخبرُ كلُّه على ورقةٍ مرفوضة */}
                    {note.rejection_reason && (
                        <Card className="border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] leading-relaxed text-[#b91c1c]">
                            <span className="font-bold">{t('سبب الرفض')}: </span>
                            {note.rejection_reason}
                        </Card>
                    )}

                    <DocumentMeta
                        cells={[
                            {
                                label: 'الحالة',
                                value: (
                                    <Badge
                                        variant={
                                            note.status === 'معتمد'
                                                ? 'success'
                                                : note.status === 'مرفوض'
                                                  ? 'danger'
                                                  : 'warning'
                                        }
                                    >
                                        {t(note.status)}
                                    </Badge>
                                ),
                            },
                            { label: 'المورّد', value: note.supplier || '—' },
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
                            { label: 'الفرع', value: note.branch || '—' },
                            { label: 'تاريخ الاستلام', value: note.received_at || '—', ltr: true },
                            { label: 'المستلِم', value: note.receiver || '—' },
                            note.submitted_by !== null && { label: 'سجّل الورقة', value: note.submitted_by },
                            note.approved_at !== null && {
                                label: 'اعتُمد',
                                value: `${note.approved_at}${note.approved_by ? ` — ${note.approved_by}` : ''}`,
                            },
                            note.rejected_at !== null && {
                                label: 'رُفض',
                                value: `${note.rejected_at}${note.rejected_by ? ` — ${note.rejected_by}` : ''}`,
                            },
                            note.notes !== null && { label: 'ملاحظات', value: note.notes, wide: true },
                        ]}
                    />

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('البنود')}</h2>

                        {/*
                            وكلُّ سطرٍ يقول أين موضعُه من أمره: كم طُلب، وكم
                            استُلم قبله، وكم في هذه الورقة، وكم يبقى. ومن يعتمد
                            بلا هذه الأربعة يعتمد رقمًا لا يعرف نسبته إلى شيء.
                        */}
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] text-[13px]">
                                <thead className="text-[12px] text-[#71717a]">
                                    <tr>
                                        <th className="p-2 text-start">{t('الصنف')}</th>
                                        <th className="p-2 text-end">{t('المطلوب')}</th>
                                        <th className="p-2 text-end">{t('استُلم قبله')}</th>
                                        <th className="p-2 text-end">{t('في هذه الورقة')}</th>
                                        <th className="p-2 text-end">{t('المتبقّي')}</th>
                                        <th className="p-2 text-end">{t('تكلفة الوحدة')}</th>
                                        <th className="p-2 text-end">{t('الإجمالي')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {note.items.map((i, idx) => (
                                        <tr key={idx} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                            <td className="p-2 font-medium text-[#111]">{i.name}</td>
                                            <td className="p-2 text-end tabular-nums text-[#6b7280]">
                                                {i.ordered === null ? '—' : number(i.ordered, 2)}
                                            </td>
                                            <td className="p-2 text-end tabular-nums text-[#6b7280]">
                                                {i.received_before === null ? '—' : number(i.received_before, 2)}
                                            </td>
                                            <td className="p-2 text-end font-semibold tabular-nums text-[#047857]">
                                                {number(i.quantity, 2)}
                                            </td>
                                            <td className="p-2 text-end tabular-nums">
                                                {i.remaining === null ? '—' : number(i.remaining, 2)}
                                            </td>
                                            <td className="p-2 text-end tabular-nums">{m(i.cost)}</td>
                                            <td className="p-2 text-end font-semibold tabular-nums">{m(i.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/*
                            وقيمةُ ما دخل بهذه الورقة — تُقابَل بفاتورة المورّد.

                            وليست ذمّةً: السندُ يقول «وصل» لا «عليك». والذمّةُ
                            تنشأ باعتماد سند المورّد وحده.
                        */}
                        <dl className="mt-4 ms-auto max-w-xs border-t border-[var(--ui-border,#e8e8e8)] pt-4 text-[13px]">
                            <div className="flex items-center justify-between gap-3">
                                <dt className="font-bold text-[#111]">{t('قيمة ما دخل')}</dt>
                                <dd className="font-bold tabular-nums text-[#111]">{m(note.value)}</dd>
                            </div>
                            <p className="mt-2 text-[12px] leading-relaxed text-[#9ca3af]">
                                {t('هذه قيمةُ البضاعة الداخلة — ولا تنشأ بها ذمّةٌ للمورّد قبل اعتماد سنده.')}
                            </p>
                        </dl>
                    </Card>
                </div>

                <DocumentAside className="xl:col-span-2">
                    <DocumentPanel
                        html={paper.html}
                        size={paper.size}
                        url={route('admin.inventory.receipts.pdf', note.id)}
                        filename={`${note.number}.pdf`}
                        label={`${t('سند استلام بضاعة')} ${note.number}`}
                        open={previewing}
                        onOpenChange={setPreviewing}
                    />
                </DocumentAside>
            </div>

            <Dialog open={rejecting} onOpenChange={(v) => !v && setRejecting(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('رفض الاستلام')} — {note.number}
                        </DialogTitle>
                    </DialogHeader>

                    {/* والحشوُ على الجسم: `DialogContent` بلا حشو — انظر ui/dialog */}
                    <div className="space-y-3 px-5 pb-5">
                        <p className="text-[13px] text-[#4b4b4b]">
                            {t('لن تدخل البضاعة الرفّ — واكتب السبب ليقرأه من سجّل الورقة.')}
                        </p>
                        <Input
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={t('الكمية أقلّ ممّا في الشحنة')}
                            aria-label={t('سبب الرفض')}
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="danger" disabled={reason.trim() === ''} onClick={reject}>
                            {t('رفض')}
                        </Button>
                        <Button variant="outline" onClick={() => setRejecting(false)}>
                            {t('إلغاء')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

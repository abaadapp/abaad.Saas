import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import SmartLink from '@/Components/SmartLink';
import { Check, FileText, PackagePlus, Printer, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface NoteItem {
    name: string;
    quantity: number;
    cost: number;
    /* موضعُ السطر من أمره — كم طُلب، وكم استُلم قبله، وكم بقي */
    ordered: number | null;
    received_before: number | null;
    remaining: number | null;
}

interface Note {
    id: number;
    number: string;
    supplier: string | null;
    order: string | null;
    branch: string | null;
    received_at: string | null;
    receiver: string | null;
    notes: string | null;
    value: number;
    status: string;
    approved_at: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    /** اسمُ ورقة المورّد المرفقة — لا مسارُها: المسارُ خلف بابٍ يسأل */
    attachment: string | null;
    items: NoteItem[];
}

/** كما يكتبها الخادم في `GoodsReceipts` — نصٌّ واحد لا نصّان */
const PENDING = 'بانتظار الاعتماد';

/** لونُ الحال — والمعلَّقُ وحده أصفر: هو الذي يطلب فعلًا */
const tone = (status: string) =>
    status === PENDING ? 'bg-[#fffbeb] text-[#b45309]'
    : status === 'مرفوض' ? 'bg-[#fef2f2] text-[#b91c1c]'
    : 'bg-[#ecfdf5] text-[#047857]';

interface Props {
    notes: Note[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    sorts: string[];
    pendingCount: number;
    canApprove: boolean;
    /** والرفضُ فعلٌ غيرُ الاعتماد: نصفُ المراجعة الآمن يُمنح وحدَه */
    canReject: boolean;
    canSeeAttachment: boolean;
}

/**
 * إشعار استلام بضاعة — توأمُ إشعار التسليم بالاتجاه المعاكس.
 *
 * وقراءةٌ فقط بلا زرّ «إشعار جديد»: هذه الأوراق تُنشئها لحظةُ استلام أمر
 * الشراء شاهدةً على واقعةٍ جرت، فلا تُكتب بيدٍ ولا تُحذف. ونموذجٌ يُنشئ
 * إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم يقله المخزون.
 */
export default function InventoryReceipts() {
    const { notes, pagination, filters, sorts, pendingCount, canApprove, canReject, canSeeAttachment, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [viewing, setViewing] = useState<Note | null>(null);
    const [rejecting, setRejecting] = useState<Note | null>(null);
    const reject = useForm({ reason: '' });

    const approve = (n: Note) =>
        router.post(route('admin.purchases.receipts.approve', n.id), {}, {
            preserveScroll: true,
            onSuccess: () => setViewing(null),
        });

    const columns: Column<Note>[] = [
        {
            key: 'number',
            header: 'الإشعار',
            cell: (n) => (
                <>
                    <span className="font-mono text-[12px] text-[#4b4b4b]">{n.number}</span>
                    <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                        {n.received_at}
                    </span>
                </>
            ),
        },
        {
            key: 'supplier',
            header: 'المورّد',
            cell: (n) => (
                <>
                    <span>{n.supplier ?? '—'}</span>
                    {/*
                        رقم الأمر تحت اسم المورّد: منه تُقابَل الورقة بأمرها.

                        ورابطٌ يفتح الأمر نفسه لا قائمةً فيها أربعةٌ وستّون:
                        الرقم يصل مع الرابط ويُملأ به حقل البحث في الشاشة
                        الأخرى. ولا شاشةَ أمرٍ مفردة في النظام، ورابطٌ يُنزلك
                        في رأس قائمةٍ ويتركك تبحث يَعِد ولا يفي.
                    */}
                    {n.order && (
                        <SmartLink
                            routeName="admin.purchases.orders"
                            href={route('admin.purchases.orders', { q: n.order })}
                            className="block font-mono text-[12px] text-[#6b7280] hover:text-[#111] hover:underline"
                        >
                            {n.order}
                        </SmartLink>
                    )}
                </>
            ),
        },
        {
            key: 'status',
            header: 'الحال',
            cell: (n) => (
                <span className={'rounded-full px-2 py-1 text-[11px] ' + tone(n.status)}>{t(n.status)}</span>
            ),
        },
        { key: 'branch', header: 'الفرع', cell: (n) => n.branch ?? '—' },
        { key: 'receiver', header: 'المستلِم', cell: (n) => n.receiver ?? '—' },
        {
            key: 'items',
            header: 'الأصناف',
            align: 'end',
            cell: (n) => <span className="tabular-nums">{number(n.items.length)}</span>,
        },
        {
            key: 'value',
            header: 'القيمة',
            align: 'end',
            cell: (n) => <span className="tabular-nums font-semibold">{m(n.value)}</span>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (n) => (
                <div className="flex items-center justify-end gap-1.5">
                    {/* ورقةٌ تُوقَّع عند باب المخزن — والسند الذي لا يخرج لا يُوقَّع */}
                    <Button variant="ghost" size="icon-sm" aria-label={t('طباعة السند')} asChild>
                        <a href={route('admin.inventory.receipts.pdf', n.id)} target="_blank" rel="noreferrer">
                            <Printer />
                        </a>
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setViewing(n)}>
                        {t('مراجعة')}
                    </Button>
                    {/*
                        وزرّا القرار لمن يملكه وحده، وعلى المعلَّق وحده:
                        زرٌّ يُرسم لمن يُردّ عند ضغطه أسوأ من غيابه.
                    */}
                    {n.status === PENDING && canApprove && (
                        <Button variant="ghost" size="icon-sm" aria-label={t('اعتماد الاستلام')} onClick={() => approve(n)}>
                            <Check className="text-[#047857]" />
                        </Button>
                    )}
                    {n.status === PENDING && canReject && (
                        <Button variant="ghost" size="icon-sm" aria-label={t('رفض')} onClick={() => setRejecting(n)}>
                            <X className="text-[#b91c1c]" />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="إشعار استلام بضاعة">
            <PageHeader
                title="إشعار استلام بضاعة"
                subtitle={t('ورقةُ ما دخل المخزن ومن استلمه — تُنشئها دفعةُ استلام أمر الشراء')}
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.receipts" />

            {/*
                طابورُ الاعتماد فوق الجدول لا داخله.

                البضاعةُ لا تدخل الرفَّ حتى يُعتمد استلامُها، فورقةٌ تنتظر
                تعني رفًّا يقول أقلَّ ممّا فيه. ومن لا يعرف أنّها تنتظر لا
                يعتمدها — فتبقى معلّقةً أسبوعًا ولا شيء يقول لماذا نقص
                المخزون.
            */}
            {pendingCount > 0 && (
                <Card className="mb-4 flex flex-wrap items-center gap-3 border-[#fed7aa] bg-[#fffbeb] p-4">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#fef3c7] text-[#b45309]">
                        <PackagePlus className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[14px] font-semibold text-[#92400e]">
                            {t(':n استلام بانتظار الاعتماد', { n: pendingCount })}
                        </p>
                        <p className="text-[12px] text-[#b45309]">
                            {t('البضاعة لا تدخل المخزون حتى يُعتمد استلامها.')}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        className="ms-auto"
                        onClick={() => router.get(route('admin.inventory.receipts'), { status: PENDING }, { preserveState: true })}
                    >
                        {t('عرض الطابور')}
                    </Button>
                </Card>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={notes}
                    columns={columns}
                    rowKey={(n) => n.id}
                    searchPlaceholder="ابحث بالرقم أو المورّد أو أمر الشراء…"
                    searchable={() => ''}
                    empty="لا إشعارات استلام بعد — تُنشأ عند تأكيد استلام أمر شراء"
                    server={{ pagination, params: filters, sorts }}
                />
            </Card>

            {/*
                نافذةُ العرض — ورقةُ الاستلام كما تُقرأ عند باب المخزن.

                وكانت ستّةَ حقولٍ في شبكةٍ واحدة تحت عنوانٍ واحد: المورّد
                وأمرُ الشراء والفرعُ والمستلِم والتاريخُ والقيمة — فلا تُقرأ
                الورقةُ طرفين، ولا يُعرف من أين جاءت البضاعة ومن استلمها إلا
                بقراءة الستّة كلِّها. وصارت طرفين: مصدرٌ ووجهة.

                والمجموعُ في ذيل الجدول لا في الشبكة فوقه: العينُ تطلب
                المجموع تحت آخر سطرٍ لا فوق أوّله. وأُضيف إجماليّ الكميّة —
                وهو أوّلُ ما يُعدّ عند الباب، ولم يكن في الورقة أصلًا.
            */}
            <Dialog open={viewing !== null} onOpenChange={(open) => !open && setViewing(null)}>
                <DialogContent className="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span className="flex items-center gap-2">
                                    <PackagePlus className="size-4 text-[#047857]" />
                                    <span className="font-mono">{viewing?.number}</span>
                                </span>
                                {viewing?.received_at && (
                                    <span dir="ltr" className="text-[13px] font-normal text-[#9ca3af]">
                                        {viewing.received_at}
                                    </span>
                                )}
                            </span>
                        </DialogTitle>
                    </DialogHeader>

                    {viewing && (
                        /* الجسم وحده يمرّ تحت اليد: الترويسة والذيل يبقيان
                           ظاهرين مهما طالت قائمة الأصناف */
                        <div className="max-h-[70dvh] space-y-5 overflow-y-auto overscroll-contain">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
                                    <p className="mb-2 text-[12px] font-medium text-[#9ca3af]">{t('من أين جاءت')}</p>
                                    <p className="font-medium text-[#111]">{viewing.supplier ?? t('بلا مورّد')}</p>
                                    {/* ورقمُ الأمر رابطٌ هنا كما هو في الجدول: من يقرأ
                                        السند يريد أن يقابله بأمره لا أن ينسخ رقمه */}
                                    {viewing.order ? (
                                        <SmartLink
                                            routeName="admin.purchases.orders"
                                            href={route('admin.purchases.orders', { q: viewing.order })}
                                            className="mt-1 block font-mono text-[12px] text-[#6b7280] hover:text-[#111] hover:underline"
                                        >
                                            {viewing.order}
                                        </SmartLink>
                                    ) : (
                                        <p className="mt-1 text-[12px] text-[#9ca3af]">{t('بلا أمر شراء')}</p>
                                    )}
                                </div>

                                <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
                                    <p className="mb-2 text-[12px] font-medium text-[#9ca3af]">{t('أين دخلت ومن استلمها')}</p>
                                    <p className="font-medium text-[#111]">{viewing.branch ?? t('بلا فرع')}</p>
                                    <p className="mt-1 text-[12px] text-[#6b7280]">
                                        {viewing.receiver ?? t('لم يُسجَّل مستلِم')}
                                    </p>
                                </div>
                            </div>

                            {viewing.items.length === 0 ? (
                                <p className="rounded-[10px] bg-[#fafafa] p-4 text-center text-sm text-[#9ca3af]">
                                    {t('لا أصناف على هذا الإشعار.')}
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead>{t('الصنف')}</TableHead>
                                            {/* موضعُ السطر من أمره — ومن يعتمد رقمًا لا
                                                يعرف نسبته إلى شيء يعتمد على غير علم */}
                                            <TableHead className="text-end">{t('المطلوب')}</TableHead>
                                            <TableHead className="text-end">{t('استُلم قبله')}</TableHead>
                                            <TableHead className="text-end">{t('في هذه الورقة')}</TableHead>
                                            <TableHead className="text-end">{t('التكلفة')}</TableHead>
                                            <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {viewing.items.map((i, k) => (
                                            <TableRow key={k}>
                                                <TableCell className="font-medium text-[#111]">{i.name}</TableCell>
                                                <TableCell className="text-end tabular-nums text-[#9ca3af]">
                                                    {i.ordered === null ? '—' : number(i.ordered)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums text-[#9ca3af]">
                                                    {i.received_before === null ? '—' : number(i.received_before)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums font-semibold">
                                                    {number(i.quantity)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                                    {m(i.cost)}
                                                </TableCell>
                                                <TableCell className="text-end tabular-nums font-medium">
                                                    {m(i.quantity * i.cost)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {/*
                                            والمجموع يُحسب من السطور لا يُقرأ من حقلٍ
                                            ثانٍ: رقمان لشيءٍ واحد يفترقان يومًا، فيقول
                                            الذيلُ غير ما تقوله السطور فوقه.
                                        */}
                                        <TableRow className="border-t-2 border-[#111] font-semibold hover:bg-transparent">
                                            <TableCell className="text-[#111]">
                                                {t('الإجمالي')}
                                                <span className="ms-2 text-[12px] font-normal text-[#9ca3af]">
                                                    {number(viewing.items.length)} {t('صنف')}
                                                </span>
                                            </TableCell>
                                            <TableCell />
                                            <TableCell />
                                            <TableCell className="text-end tabular-nums">
                                                {number(viewing.items.reduce((a, i) => a + i.quantity, 0))}
                                            </TableCell>
                                            <TableCell />
                                            <TableCell className="text-end tabular-nums">
                                                {m(viewing.items.reduce((a, i) => a + i.quantity * i.cost, 0))}
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            )}

                            {viewing.notes && (
                                <div className="rounded-[10px] bg-[#fafafa] p-3">
                                    <p className="mb-1 text-[12px] text-[#9ca3af]">{t('ملاحظات')}</p>
                                    <p className="text-sm whitespace-pre-line text-[#4b4b4b]">{viewing.notes}</p>
                                </div>
                            )}
                        </div>
                    )}

                    {/* والطباعة من داخل النافذة أيضًا: من فتح السند ليقرأه هو
                        من يريد ورقةً يوقّعها، فلا يُغلقها ليبحث عن أيقونةٍ في صفّه */}
                    {viewing && (
                        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                            {viewing.status === 'مرفوض' && viewing.rejection_reason && (
                                <p className="me-auto text-[12px] text-[#b91c1c]">
                                    {t('سبب الرفض')}: {viewing.rejection_reason}
                                </p>
                            )}
                            {/* وورقةُ المورّد تُفتح من هنا: من يعتمد يريد أن
                                يقابل ما في الشاشة بما في يده */}
                            {viewing.attachment && canSeeAttachment && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={route('admin.inventory.receipts.attachment', viewing.id)}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <FileText />
                                        {viewing.attachment}
                                    </a>
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <a
                                    href={route('admin.inventory.receipts.pdf', viewing.id)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer />
                                    {t('طباعة السند')}
                                </a>
                            </Button>
                            {/* والقرارُ من حيث تُقرأ الورقة: من فتحها ليراجعها هو من يقرّر */}
                            {viewing.status === PENDING && canReject && (
                                <Button variant="outline" onClick={() => setRejecting(viewing)}>
                                    <X />
                                    {t('رفض')}
                                </Button>
                            )}
                            {viewing.status === PENDING && canApprove && (
                                <Button onClick={() => approve(viewing)}>
                                    <Check />
                                    {t('اعتماد الاستلام')}
                                </Button>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/*
                والرفضُ بسببٍ مكتوب: ورقةٌ رُفضت بلا سبب تُسأل عنها بعد شهر
                فلا يُعرف لماذا رُدّت الشحنة ولا من ردّها.
            */}
            <Dialog open={rejecting !== null} onOpenChange={(open) => !open && setRejecting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('رفض الاستلام')} <span className="font-mono">{rejecting?.number}</span>
                        </DialogTitle>
                    </DialogHeader>

                    <p className="mb-3 text-[13px] text-[#6b7280]">
                        {t('لن تدخل البضاعة المخزون، ولا تُمحى الورقة — تبقى مرفوضةً بسببها.')}
                    </p>

                    <Input
                        value={reject.data.reason}
                        onChange={(e) => reject.setData('reason', e.target.value)}
                        placeholder={t('سبب الرفض — مثال: الشحنة تالفة')}
                    />
                    {reject.errors.reason && (
                        <p className="mt-1 text-[12px] text-[#b91c1c]">{reject.errors.reason}</p>
                    )}

                    <div className="mt-4 flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setRejecting(null)}>
                            {t('إلغاء')}
                        </Button>
                        <Button
                            variant="danger"
                            disabled={reject.processing}
                            onClick={() =>
                                rejecting &&
                                reject.post(route('admin.purchases.receipts.reject', rejecting.id), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        reject.reset();
                                        setRejecting(null);
                                        setViewing(null);
                                    },
                                })
                            }
                        >
                            {t('رفض الاستلام')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

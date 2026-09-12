import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import SmartLink from '@/Components/SmartLink';
import { Check, PackagePlus, Printer, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
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
    /** مفتاحُ الأمر — به يُفتح، والرقمُ وحده لا يفتح شيئًا */
    order_id: number | null;
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
}

/**
 * إشعار استلام بضاعة — توأمُ إشعار التسليم بالاتجاه المعاكس.
 *
 * وقراءةٌ فقط بلا زرّ «إشعار جديد»: هذه الأوراق تُنشئها لحظةُ استلام أمر
 * الشراء شاهدةً على واقعةٍ جرت، فلا تُكتب بيدٍ ولا تُحذف. ونموذجٌ يُنشئ
 * إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم يقله المخزون.
 */
export default function InventoryReceipts() {
    const { notes, pagination, filters, sorts, pendingCount, canApprove, canReject, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [rejecting, setRejecting] = useState<Note | null>(null);
    const reject = useForm({ reason: '' });

    const approve = (n: Note) =>
        router.post(route('admin.purchases.receipts.approve', n.id), {}, { preserveScroll: true });

    const columns: Column<Note>[] = [
        {
            key: 'number',
            header: 'الإشعار',
            cell: (n) => (
                <>
                    {/*
                        والرقمُ بابُ ورقته.

                        كان نصًّا لا يُفتح، و«مراجعة» تفتح نافذةً فوق القائمة:
                        لا عنوانَ للسند يُرسَل إلى زميل، ولا رجوعَ منه إلى
                        أمره، ولا موضعَ يقول من اعتمده. انظر `Inventory/ReceiptShow`.
                    */}
                    <SmartLink
                        routeName="admin.inventory.receipts.show"
                        href={route('admin.inventory.receipts.show', n.id)}
                        className="font-mono text-[12px] text-[#4b4b4b] hover:text-[#111] hover:underline"
                    >
                        {n.number}
                    </SmartLink>
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

                        ويفتح الأمرَ نفسَه. وكان يُنزلك في رأس القائمة بالرقم
                        في حقل البحث — يومَ لم تكن للأمر شاشةٌ مفردة. وصارت
                        له (`admin.purchases.show`)، فرابطٌ يتركك تبحث يَعِد
                        ولا يفي.
                    */}
                    {n.order && n.order_id !== null && (
                        <SmartLink
                            routeName="admin.purchases.show"
                            href={route('admin.purchases.show', n.order_id)}
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
                    {/* و«مراجعة» تفتح صفحةَ السند — لا نافذةً تقول نصفَ ما فيها */}
                    <Button variant="ghost" size="sm" asChild>
                        <SmartLink
                            routeName="admin.inventory.receipts.show"
                            href={route('admin.inventory.receipts.show', n.id)}
                        >
                            {t('مراجعة')}
                        </SmartLink>
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
                ولا نافذةَ عرضٍ هنا بعد اليوم — للسند صفحتُه.

                كانت «مراجعة» تفتح نافذةً فوق القائمة تعرض الأطرافَ والبنودَ
                والمجموع. وصار للسند عنوانٌ يُفتح ويُرسَل ويُرجَع منه إلى أمره،
                وفيه ما لم تكن النافذةُ تعرضه: من سجّل الورقة ومن اعتمدها،
                والورقةُ نفسُها كما تُطبع. ونافذةٌ تقول نصفَ ما تقوله الصفحة
                تجعل من يقرأ في أحدهما لا يعرف أنّ في الآخر أكثر.

                انظر `Pages/Admin/Inventory/ReceiptShow`.
            */}

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

                    {/* والحشوُ على الجسم: `DialogContent` بلا حشو — انظر ui/dialog */}
                    <div className="px-5 pb-5">
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
                    </div>

                    <DialogFooter>
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
                                    },
                                })
                            }
                        >
                            {t('رفض الاستلام')}
                        </Button>
                        <Button variant="outline" onClick={() => setRejecting(null)}>
                            {t('إلغاء')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

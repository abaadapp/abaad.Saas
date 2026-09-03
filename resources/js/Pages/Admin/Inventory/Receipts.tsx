import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import SmartLink from '@/Components/SmartLink';
import { PackagePlus, Printer } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
    items: NoteItem[];
}

interface Props {
    notes: Note[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    sorts: string[];
}

/**
 * إشعار استلام بضاعة — توأمُ إشعار التسليم بالاتجاه المعاكس.
 *
 * وقراءةٌ فقط بلا زرّ «إشعار جديد»: هذه الأوراق تُنشئها لحظةُ استلام أمر
 * الشراء شاهدةً على واقعةٍ جرت، فلا تُكتب بيدٍ ولا تُحذف. ونموذجٌ يُنشئ
 * إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم يقله المخزون.
 */
export default function InventoryReceipts() {
    const { notes, pagination, filters, sorts, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [viewing, setViewing] = useState<Note | null>(null);

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
                        {t('عرض')}
                    </Button>
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
                                            <TableHead className="text-end">{t('الكمية')}</TableHead>
                                            <TableHead className="text-end">{t('التكلفة')}</TableHead>
                                            <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {viewing.items.map((i, k) => (
                                            <TableRow key={k}>
                                                <TableCell className="font-medium text-[#111]">{i.name}</TableCell>
                                                <TableCell className="text-end tabular-nums">{number(i.quantity)}</TableCell>
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
                        <div className="flex justify-end border-t border-[var(--ui-border,#e8e8e8)] pt-4">
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
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

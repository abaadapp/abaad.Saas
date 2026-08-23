import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { PackagePlus } from 'lucide-react';
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
                    {/* رقم الأمر تحت اسم المورّد: منه تُقابَل الورقة بأمرها */}
                    {n.order && <span className="block font-mono text-[12px] text-[#9ca3af]">{n.order}</span>}
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
                <div className="flex items-center justify-end">
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

            <Dialog open={viewing !== null} onOpenChange={(open) => !open && setViewing(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            <span className="flex items-center gap-2">
                                <PackagePlus className="size-4 text-[#047857]" />
                                {viewing?.number}
                            </span>
                        </DialogTitle>
                    </DialogHeader>

                    {viewing && (
                        <div className="space-y-4">
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                {[
                                    ['المورّد', viewing.supplier ?? '—'],
                                    ['أمر الشراء', viewing.order ?? '—'],
                                    ['الفرع', viewing.branch ?? '—'],
                                    ['المستلِم', viewing.receiver ?? '—'],
                                    ['تاريخ الاستلام', viewing.received_at ?? '—'],
                                    ['قيمة ما دخل', m(viewing.value)],
                                ].map(([label, value]) => (
                                    <div key={label as string}>
                                        <dt className="text-[12px] text-[#9ca3af]">{t(label as string)}</dt>
                                        <dd className="font-medium text-[#111]">{value}</dd>
                                    </div>
                                ))}
                            </dl>

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
                                </TableBody>
                            </Table>

                            {viewing.notes && (
                                <p className="rounded-[10px] bg-[#fafafa] p-3 text-sm text-[#4b4b4b]">
                                    {viewing.notes}
                                </p>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

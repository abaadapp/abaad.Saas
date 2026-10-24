import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import BranchItemsPicker, { type PickedItem } from '@/Components/BranchItemsPicker';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { TRANSFER_STATUS, clientKey } from '@/lib/branchTransfers';
import type { PageProps } from '@/types';

interface TransferRow {
    id: number;
    number: string;
    status: string;
    from: string;
    to: string;
    lines_count: number;
    quantity: number;
    requested_by: string | null;
    approved_by: string | null;
    created_at: string | null;
    dispatched_at: string | null;
    received_at: string | null;
}

interface BranchOption {
    id: number;
    name: string;
    /** هل الموظّفُ مسندٌ إلى هذا الفرع — `BranchTransfers::covers` */
    mine: boolean;
}

interface Props {
    transfers: TransferRow[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    counts: { pending: number; approved: number; in_transit: number; discrepancy: number };
    inTransit: { quantity: number; value: number; transfers: number };
    branches: BranchOption[];
    employees: { id: number; name: string }[];
    licensed: boolean;
    can: { create: boolean; execute: boolean };
}

/**
 * نقلُ المخزون بين الفروع — السنداتُ ومراحلُها، وإنشاءُ سندٍ بأسطرٍ عدّة.
 *
 * والشاشةُ لا تقرّر: الخادمُ يفحص الصلاحيةَ وإسنادَ الفرع والرصيدَ عند كلّ
 * مرحلة (`BranchTransfers`). وما تُظهره هنا من رصيدٍ متاح عرضٌ يعين، لا حارس.
 */
export default function TransfersIndex() {
    const { transfers, pagination, filters, counts, inTransit, branches, employees, licensed, can, errors, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [open, setOpen] = useState(false);

    const columns: Column<TransferRow>[] = [
        {
            key: 'number',
            header: 'السند',
            cell: (r) => (
                <SmartLink routeName="admin.inventory.transfers.show" href={route('admin.inventory.transfers.show', r.id)} className="font-medium text-[#111] hover:underline">
                    {r.number}
                </SmartLink>
            ),
        },
        {
            key: 'route',
            header: 'من ← إلى',
            cell: (r) => (
                <span className="text-[13px]">
                    {r.from} <span className="text-[#9ca3af]">←</span> {r.to}
                </span>
            ),
        },
        { key: 'quantity', header: 'الكمية', align: 'end', cell: (r) => <span className="tabular-nums">{number(r.quantity)}</span> },
        {
            key: 'status',
            header: 'الحالة',
            cell: (r) => <Badge variant={TRANSFER_STATUS[r.status]?.tone ?? 'neutral'}>{t(TRANSFER_STATUS[r.status]?.label ?? r.status)}</Badge>,
        },
        { key: 'requested_by', header: 'طلبه', cell: (r) => <span className="text-[13px]">{r.requested_by ?? '—'}</span> },
        { key: 'created_at', header: 'التاريخ', cell: (r) => <span className="text-[12px] tabular-nums text-[#6b7280]">{r.created_at}</span> },
    ];

    const statusFilter: Filter<TransferRow>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: Object.entries(TRANSFER_STATUS)
                .filter(([key]) => key !== 'legacy')
                .map(([value, s]) => ({ label: s.label, value })),
        },
        {
            label: 'كل الفروع',
            param: 'branch',
            options: branches.map((b) => ({ label: b.name, value: String(b.id) })),
        },
    ];

    return (
        <AdminLayout title="نقل المخزون بين الفروع">
            <PageHeader
                title="نقل المخزون بين الفروع"
                subtitle={t('طلب ← موافقة ← تحضير ← إرسال ← استلام. لا يتحرّك المخزون إلا عند الإرسال والاستلام.')}
                actions={
                    can.create && (
                        <Button onClick={() => setOpen(true)} data-testid="transfer-new">
                            <Plus />
                            {t('طلب نقل جديد')}
                        </Button>
                    )
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.transfers" />

            {!licensed && (
                <p className="mb-4 rounded-lg bg-[#fffbeb] px-3 py-2 text-[13px] text-[#92400e]" data-testid="transfers-unlicensed">
                    {t('باقتك الحالية لا تتيح إنشاء تحويلات جديدة. التحويلات المرسلة قبل تغيير الباقة تُستلم وتُسوّى من هنا.')}
                </p>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                {[
                    { label: 'بانتظار الموافقة', value: counts.pending },
                    { label: 'معتمد ينتظر الإرسال', value: counts.approved },
                    { label: 'قيد النقل', value: counts.in_transit },
                    { label: 'فروقات استلام', value: counts.discrepancy },
                ].map((c) => (
                    <Card key={c.label} className="p-3">
                        <p className="text-[12px] text-[#6b7280]">{t(c.label)}</p>
                        <p className="mt-1 text-[20px] font-bold tabular-nums">{number(c.value)}</p>
                    </Card>
                ))}
                <Card className="col-span-2 p-3 md:col-span-1" data-testid="in-transit-total">
                    <p className="text-[12px] text-[#6b7280]">{t('مخزون قيد النقل')}</p>
                    <p className="mt-1 text-[15px] font-bold tabular-nums">
                        {number(inTransit.quantity)} <span className="text-[12px] font-normal text-[#6b7280]">{t('قطعة')}</span>
                    </p>
                    <p className="text-[12px] tabular-nums text-[#6b7280]">{money(inTransit.value, context!.currency)}</p>
                </Card>
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={transfers}
                    columns={columns}
                    rowKey={(r) => r.id}
                    searchPlaceholder="ابحث برقم السند أو المنتج…"
                    searchable={() => ''}
                    filters={statusFilter}
                    empty="لا تحويلات بعد"
                    server={{ pagination, params: filters, sorts: [] }}
                />
            </Card>

            {open && (
                <NewTransfer
                    branches={branches}
                    employees={employees}
                    canExecute={can.execute}
                    error={(errors as Record<string, string>)?.transfer}
                    onClose={() => setOpen(false)}
                />
            )}
        </AdminLayout>
    );
}

function NewTransfer({
    branches,
    employees,
    canExecute,
    error,
    onClose,
}: {
    branches: BranchOption[];
    employees: { id: number; name: string }[];
    canExecute: boolean;
    error?: string;
    onClose: () => void;
}) {
    const t = useTranslate();
    const [items, setItems] = useState<PickedItem[]>([]);
    const form = useForm({
        from_branch_id: String(branches.find((b) => b.mine)?.id ?? ''),
        to_branch_id: '',
        notes: '',
        responsible_user_id: '',
        execute: false,
        self_approve: false,
        client_key: clientKey(),
        items: [] as { product_id: number; quantity: number }[],
    });

    const from = form.data.from_branch_id ? Number(form.data.from_branch_id) : null;
    const same = form.data.from_branch_id !== '' && form.data.from_branch_id === form.data.to_branch_id;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            items: items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
            responsible_user_id: d.responsible_user_id || null,
            self_approve: d.execute,
        }));
        form.post(route('admin.inventory.transfers.store'), { preserveScroll: true });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ArrowLeftRight className="size-5" />
                        {t('طلب نقل مخزون')}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="transfer-form">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="الفرع المصدر" required>
                            <Select
                                value={form.data.from_branch_id}
                                onChange={(e) => form.setData('from_branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: String(b.id) }))}
                                placeholder="اختر الفرع"
                                aria-label={t('الفرع المصدر')}
                            />
                        </Field>
                        <Field label="الفرع المستلم" required error={same ? t('اختر فرعين مختلفين') : undefined}>
                            <Select
                                value={form.data.to_branch_id}
                                onChange={(e) => form.setData('to_branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: String(b.id), disabled: String(b.id) === form.data.from_branch_id }))}
                                placeholder="اختر الفرع"
                                aria-label={t('الفرع المستلم')}
                            />
                        </Field>
                    </div>

                    <Field label="المنتجات والكميات" required>
                        <BranchItemsPicker items={items} onChange={setItems} branchId={from} />
                    </Field>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="الموظف المسؤول">
                            <Select
                                value={form.data.responsible_user_id}
                                onChange={(e) => form.setData('responsible_user_id', e.target.value)}
                                options={employees.map((u) => ({ label: u.name, value: String(u.id) }))}
                                placeholder="بلا تحديد"
                                aria-label={t('الموظف المسؤول')}
                            />
                        </Field>
                        <Field label="ملاحظات">
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                    </div>

                    {canExecute && (
                        <label className="flex items-start gap-2 rounded-lg bg-[#fafafa] px-3 py-2 text-[13px]">
                            <input
                                type="checkbox"
                                checked={form.data.execute}
                                onChange={(e) => form.setData('execute', e.target.checked)}
                                className="mt-0.5"
                                data-testid="transfer-execute"
                            />
                            <span>
                                {t('تنفيذ مباشر: أعتمد طلبي بنفسي وأرسل البضاعة الآن')}
                                <span className="block text-[12px] text-[#6b7280]">
                                    {t('يُسجَّل اعتمادك الذاتي في سجل السند، ويبقى تأكيد الاستلام للفرع المستلم.')}
                                </span>
                            </span>
                        </label>
                    )}

                    {(error || form.errors.items || form.errors.to_branch_id) && (
                        <p className="rounded-lg bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]" role="alert">
                            {error || form.errors.items || form.errors.to_branch_id}
                        </p>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" disabled={form.processing || same || !items.length || !form.data.to_branch_id || !form.data.from_branch_id}>
                            {form.data.execute ? t('اعتماد وإرسال') : t('إرسال الطلب للموافقة')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

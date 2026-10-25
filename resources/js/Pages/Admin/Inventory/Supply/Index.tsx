import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { PackagePlus, Plus } from 'lucide-react';
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
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { SUPPLY_STATUS, clientKey } from '@/lib/branchTransfers';
import type { PageProps } from '@/types';

interface SupplyRow {
    id: number;
    number: string;
    status: string;
    progress: string;
    branch: string;
    requested_by: string | null;
    lines_count: number;
    quantity: number;
    created_at: string | null;
}

interface Props {
    requests: SupplyRow[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    pendingCount: number;
    branches: { id: number; name: string; mine: boolean }[];
    licensed: boolean;
    canCreate: boolean;
}

/**
 * طلباتُ تزويد الفروع — يطلب الفرعُ، وتعتمد الإدارةُ وتختار من يزوّد.
 *
 * والطلبُ لا يحرّك رفًّا: يصير سندَ نقلٍ عند اعتماده، والبضاعةُ تخرج عند إرساله.
 */
export default function SupplyIndex() {
    const { requests, pagination, filters, pendingCount, branches, licensed, canCreate, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [open, setOpen] = useState(false);

    const columns: Column<SupplyRow>[] = [
        {
            key: 'number',
            header: 'الطلب',
            cell: (r) => (
                <SmartLink routeName="admin.inventory.supply.show" href={route('admin.inventory.supply.show', r.id)} className="font-medium text-[#111] hover:underline">
                    {r.number}
                </SmartLink>
            ),
        },
        { key: 'branch', header: 'الفرع الطالب', cell: (r) => r.branch },
        { key: 'quantity', header: 'الكمية', align: 'end', cell: (r) => <span className="tabular-nums">{number(r.quantity)}</span> },
        {
            key: 'progress',
            header: 'الحالة',
            cell: (r) => <Badge variant={SUPPLY_STATUS[r.progress]?.tone ?? 'neutral'}>{t(SUPPLY_STATUS[r.progress]?.label ?? r.progress)}</Badge>,
        },
        { key: 'requested_by', header: 'طلبه', cell: (r) => <span className="text-[13px]">{r.requested_by ?? '—'}</span> },
        { key: 'created_at', header: 'التاريخ', cell: (r) => <span className="text-[12px] tabular-nums text-[#6b7280]">{r.created_at}</span> },
    ];

    const tableFilters: Filter<SupplyRow>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: ['pending', 'approved', 'rejected', 'cancelled'].map((value) => ({ label: SUPPLY_STATUS[value].label, value })),
        },
        { label: 'كل الفروع', param: 'branch', options: branches.map((b) => ({ label: b.name, value: String(b.id) })) },
    ];

    return (
        <AdminLayout title="طلبات تزويد الفروع">
            <PageHeader
                title="طلبات تزويد الفروع"
                subtitle={t(':n طلب بانتظار المراجعة', { n: number(pendingCount) })}
                actions={
                    canCreate && (
                        <Button onClick={() => setOpen(true)} data-testid="supply-new">
                            <Plus />
                            {t('طلب تزويد')}
                        </Button>
                    )
                }
            />
            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.supply" />

            {!licensed && (
                <p className="mb-4 rounded-lg bg-[#fffbeb] px-3 py-2 text-[13px] text-[#92400e]">
                    {t('باقتك الحالية لا تتيح طلبات تزويد جديدة. الطلبات السابقة تبقى ظاهرة هنا.')}
                </p>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={requests}
                    columns={columns}
                    rowKey={(r) => r.id}
                    searchPlaceholder="ابحث برقم الطلب أو المنتج…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا طلبات تزويد بعد"
                    server={{ pagination, params: filters, sorts: [] }}
                />
            </Card>

            {open && <NewSupply branches={branches} error={(errors as Record<string, string>)?.supply} onClose={() => setOpen(false)} />}
        </AdminLayout>
    );
}

function NewSupply({ branches, error, onClose }: { branches: Props['branches']; error?: string; onClose: () => void }) {
    const t = useTranslate();
    const mine = branches.filter((b) => b.mine);
    const [items, setItems] = useState<PickedItem[]>([]);
    const form = useForm({
        branch_id: String(mine[0]?.id ?? ''),
        notes: '',
        client_key: clientKey(),
        items: [] as { product_id: number; quantity: number }[],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, items: items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })) }));
        form.post(route('admin.inventory.supply.store'), { preserveScroll: true });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <PackagePlus className="size-5" />
                        {t('طلب تزويد')}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="supply-form">
                    <Field label="الفرع الذي يحتاج البضاعة" required>
                        <Select
                            value={form.data.branch_id}
                            onChange={(e) => form.setData('branch_id', e.target.value)}
                            options={mine.map((b) => ({ label: b.name, value: String(b.id) }))}
                            placeholder="اختر الفرع"
                            aria-label={t('الفرع')}
                        />
                    </Field>
                    <Field label="المنتجات والكميات المطلوبة" required>
                        <BranchItemsPicker
                            items={items}
                            onChange={setItems}
                            branchId={form.data.branch_id ? Number(form.data.branch_id) : null}
                            availableLabel="الرصيد في الفرع"
                        />
                    </Field>
                    <Field label="ملاحظات">
                        <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                    {(error || form.errors.items) && (
                        <p className="rounded-lg bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]" role="alert">
                            {error || form.errors.items}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" disabled={form.processing || !items.length || !form.data.branch_id}>
                            {t('إرسال الطلب للإدارة')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

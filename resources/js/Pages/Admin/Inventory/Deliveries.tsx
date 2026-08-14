import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, PackageCheck, Plus, Trash2, Truck } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface NoteItem {
    name: string;
    quantity: number;
    unit: string | null;
}

interface Note {
    id: number;
    number: string;
    customer: string | null;
    order: string | null;
    branch: string | null;
    delivered_at: string | null;
    recipient: string | null;
    driver: string | null;
    address: string | null;
    status: string;
    editable: boolean;
    /** الإشعار المربوط بطلب لا يمسّ المخزون — أُنقص يوم البيع */
    moves_stock: boolean;
    notes: string | null;
    items: NoteItem[];
}

interface ProductOption {
    value: number;
    label: string;
    quantity: number;
}

interface Props {
    notes: Note[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    customers: { value: number; label: string }[];
    products: ProductOption[];
    orders: { value: number; label: string }[];
    summary: { drafts: number; delivered: number };
    today: string;
}

interface DraftItem {
    product_id: string;
    name: string;
    quantity: string;
    unit: string;
}

const emptyItem = (): DraftItem => ({ product_id: '', name: '', quantity: '1', unit: '' });

const STATUS_TONE: Record<string, string> = {
    مسودة: 'neutral',
    'مُسلَّم': 'success',
    ملغى: 'danger',
};

export default function Deliveries() {
    const { notes, pagination, filters, customers, products, orders, summary, today } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [adding, setAdding] = useState(false);
    const [viewing, setViewing] = useState<Note | null>(null);

    const form = useForm({
        customer_id: '',
        order_id: '',
        delivered_at: today,
        recipient: '',
        driver: '',
        address: '',
        notes: '',
        items: [emptyItem()] as DraftItem[],
    });

    const setItem = (i: number, patch: Partial<DraftItem>) =>
        form.setData(
            'items',
            form.data.items.map((it, j) => (j === i ? { ...it, ...patch } : it)),
        );

    /** اختيار المنتج يملأ اسمه — والاسم يُنسخ فلا يُفرّغه حذف المنتج لاحقًا */
    const pickProduct = (i: number, id: string) => {
        const product = products.find((p) => String(p.value) === id);
        setItem(i, { product_id: id, name: product ? product.label.split(' — ')[0] : '' });
    };

    const ready =
        form.data.items.some((it) => it.name.trim() && (parseFloat(it.quantity) || 0) > 0) &&
        !!form.data.delivered_at;

    const columns: Column<Note>[] = [
        {
            key: 'number',
            header: 'الإشعار',
            cell: (n) => (
                <>
                    <span className="font-mono text-[12px] text-[#4b4b4b]">{n.number}</span>
                    <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                        {n.delivered_at}
                    </span>
                </>
            ),
        },
        {
            key: 'customer',
            header: 'العميل',
            cell: (n) => (
                <>
                    <span>{n.customer ?? '—'}</span>
                    {n.order && <span className="block font-mono text-[12px] text-[#9ca3af]">{n.order}</span>}
                </>
            ),
        },
        { key: 'recipient', header: 'المستلم', cell: (n) => n.recipient ?? '—' },
        { key: 'driver', header: 'السائق', cell: (n) => n.driver ?? '—' },
        {
            key: 'items',
            header: 'الأصناف',
            align: 'end',
            cell: (n) => <span className="tabular-nums">{number(n.items.length)}</span>,
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (n) => (
                <Badge variant={(STATUS_TONE[n.status] ?? 'neutral') as never}>{t(n.status)}</Badge>
            ),
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (n) => (
                <div className="flex items-center justify-end gap-1">
                    <Button variant="ghost" size="sm" onClick={() => setViewing(n)}>
                        {t('عرض')}
                    </Button>
                    {n.status === 'مسودة' && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    if (
                                        !confirm(
                                            n.moves_stock
                                                ? t('تسجيل التسليم؟ ستُخصم الأصناف من المخزون.')
                                                : t('تسجيل التسليم؟ المخزون أُنقص يوم البيع فلا يُمسّ.'),
                                        )
                                    )
                                        return;
                                    router.post(
                                        route('admin.inventory.deliveries.deliver', n.id),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <PackageCheck />
                                {t('تسليم')}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-[#b91c1c]"
                                onClick={() => {
                                    if (!confirm(t('حذف الإشعار؟'))) return;
                                    router.delete(route('admin.inventory.deliveries.destroy', n.id), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <Trash2 />
                            </Button>
                        </>
                    )}
                </div>
            ),
        },
    ];

    const tableFilters: Filter<Note>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: [
                { label: 'مسودة', value: 'مسودة' },
                { label: 'مُسلَّم', value: 'مُسلَّم' },
                { label: 'ملغى', value: 'ملغى' },
            ],
        },
    ];

    return (
        <AdminLayout title="إشعار تسليم شحنة">
            <PageHeader
                title="إشعار تسليم شحنة"
                subtitle={t('ورقةُ ما خرج من المخزن ومن استلمه — مستند حركةٍ لا مستند مال')}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        {t('إشعار جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.deliveries" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard
                    stat={{ label: t('مسودات تنتظر التسليم'), value: number(summary.drafts), icon: 'clipboard-list', color: summary.drafts > 0 ? 'warning' : 'success' }}
                    index={0}
                />
                <StatCard
                    stat={{ label: t('شحنات سُلّمت'), value: number(summary.delivered), icon: 'truck', color: 'success' }}
                    index={1}
                />
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={notes}
                    columns={columns}
                    rowKey={(n) => n.id}
                    searchPlaceholder="ابحث بالرقم أو المستلم…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا إشعارات بعد"
                    server={{ pagination, params: filters }}
                />
            </Card>

            {/* ===== إشعار جديد ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{t('إشعار تسليم جديد')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('admin.inventory.deliveries.store'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setAdding(false);
                                    form.reset();
                                },
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="العميل" error={form.errors.customer_id}>
                                <Select
                                    placeholder="بلا عميل"
                                    value={form.data.customer_id}
                                    onChange={(e) => form.setData('customer_id', e.target.value)}
                                    options={customers}
                                />
                            </Field>
                            <Field
                                label="الطلب"
                                hint="الربط بطلبٍ يعني أنّ المخزون أُنقص يوم البيع فلا يُمسّ"
                                error={form.errors.order_id}
                            >
                                <Select
                                    placeholder="بلا طلب — شحنة مستقلّة"
                                    value={form.data.order_id}
                                    onChange={(e) => form.setData('order_id', e.target.value)}
                                    options={orders}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="تاريخ التسليم" required error={form.errors.delivered_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.delivered_at}
                                    onChange={(e) => form.setData('delivered_at', e.target.value)}
                                />
                            </Field>
                            <Field label="المستلم" error={form.errors.recipient}>
                                <Input
                                    value={form.data.recipient}
                                    onChange={(e) => form.setData('recipient', e.target.value)}
                                />
                            </Field>
                            <Field label="السائق" error={form.errors.driver}>
                                <Input
                                    value={form.data.driver}
                                    onChange={(e) => form.setData('driver', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="العنوان" error={form.errors.address}>
                            <Input
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                            />
                        </Field>

                        <div className="space-y-2">
                            {form.data.items.map((item, i) => (
                                <div key={i} className="grid grid-cols-12 items-start gap-2">
                                    <div className="col-span-12 sm:col-span-5">
                                        <Select
                                            placeholder="صنف حرّ"
                                            value={item.product_id}
                                            onChange={(e) => pickProduct(i, e.target.value)}
                                            options={products}
                                            aria-label={t('المنتج')}
                                        />
                                    </div>
                                    <div className="col-span-7 sm:col-span-3">
                                        <Input
                                            placeholder={t('اسم الصنف')}
                                            aria-label={t('اسم الصنف')}
                                            value={item.name}
                                            onChange={(e) => setItem(i, { name: e.target.value })}
                                        />
                                    </div>
                                    <div className="col-span-3 sm:col-span-2">
                                        <Input
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            dir="ltr"
                                            placeholder={t('الكمية')}
                                            aria-label={t('الكمية')}
                                            value={item.quantity}
                                            onChange={(e) => setItem(i, { quantity: e.target.value })}
                                        />
                                    </div>
                                    <div className="col-span-2 sm:col-span-2">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="text-[#b91c1c]"
                                            disabled={form.data.items.length <= 1}
                                            aria-label={t('حذف الصنف')}
                                            onClick={() =>
                                                form.setData(
                                                    'items',
                                                    form.data.items.filter((_, j) => j !== i),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => form.setData('items', [...form.data.items, emptyItem()])}
                            >
                                <Plus />
                                {t('صنف')}
                            </Button>
                        </div>

                        <Field label="ملاحظات" error={form.errors.notes}>
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing} disabled={!ready}>
                                <Check />
                                {t('حفظ مسودة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== عرض إشعار ===== */}
            <Dialog open={viewing !== null} onOpenChange={(o) => !o && setViewing(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {viewing?.number} — {viewing?.customer ?? t('بلا عميل')}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-1 text-[13px] text-[#6b7280]">
                        <p>
                            {viewing?.delivered_at} · {t(viewing?.status ?? '')}
                            {viewing?.order ? ` · ${viewing.order}` : ''}
                        </p>
                        {viewing?.recipient && (
                            <p>
                                {t('المستلم')}: {viewing.recipient}
                            </p>
                        )}
                        {viewing?.driver && (
                            <p>
                                {t('السائق')}: {viewing.driver}
                            </p>
                        )}
                        {viewing?.address && <p>{viewing.address}</p>}
                        {viewing?.notes && <p className="text-[#9ca3af]">{viewing.notes}</p>}
                        {viewing && !viewing.moves_stock && (
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('مربوطٌ بطلب — المخزون أُنقص يوم البيع فلا يمسّه هذا الإشعار.')}
                            </p>
                        )}
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>{t('الصنف')}</TableHead>
                                <TableHead className="text-end">{t('الكمية')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {(viewing?.items ?? []).map((item, i) => (
                                <TableRow key={i}>
                                    <TableCell>{item.name}</TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {number(item.quantity)} {item.unit ?? ''}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>

                    {viewing?.status === 'مسودة' && (
                        <div className="flex justify-end gap-2">
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    router.post(
                                        route('admin.inventory.deliveries.cancel', viewing.id),
                                        {},
                                        { preserveScroll: true, onSuccess: () => setViewing(null) },
                                    );
                                }}
                            >
                                {t('إلغاء الإشعار')}
                            </Button>
                            <Button
                                onClick={() =>
                                    router.post(
                                        route('admin.inventory.deliveries.deliver', viewing.id),
                                        {},
                                        { preserveScroll: true, onSuccess: () => setViewing(null) },
                                    )
                                }
                            >
                                <Truck />
                                {t('تسجيل التسليم')}
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

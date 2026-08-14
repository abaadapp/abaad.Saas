import { useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, FileText, Plus, Trash2, Wallet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PURCHASE_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Invoice {
    id: number;
    supplier: string;
    supplier_id: number;
    reference: string;
    order: string | null;
    issued_at: string | null;
    due_at: string | null;
    subtotal: number;
    tax: number;
    total: number;
    paid: number;
    outstanding: number;
    status: string;
    overdue: boolean;
    notes: string | null;
}

interface Props {
    invoices: Invoice[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    suppliers: { value: number; label: string }[];
    orders: { value: number; label: string; supplier_id: number | null; total: number }[];
    summary: { count: number; outstanding: number; overdue: number; overdue_value: number };
    today: string;
}

export default function SupplierInvoices() {
    const { invoices, pagination, filters, suppliers, orders, summary, today, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [adding, setAdding] = useState(false);
    const [paying, setPaying] = useState<Invoice | null>(null);

    const form = useForm({
        supplier_id: '',
        purchase_order_id: '',
        supplier_ref: '',
        issued_at: today,
        due_at: '',
        subtotal: '',
        tax: '0',
        notes: '',
    });

    const payForm = useForm({ amount: '', paid_at: today, from: 'cash' });

    // الإجمالي يُحسب أمام العين: الفرق بين ما يُكتب وما يُحفظ يُكتشف بعد الحفظ
    const total = useMemo(
        () => (parseFloat(form.data.subtotal) || 0) + (parseFloat(form.data.tax) || 0),
        [form.data.subtotal, form.data.tax],
    );

    /** ربط السند بأمرٍ يملأ مورّده ومبلغه — والأمر لا يُفوتَر مرّتين */
    const pickOrder = (id: string) => {
        const order = orders.find((o) => String(o.value) === id);
        form.setData((d) => ({
            ...d,
            purchase_order_id: id,
            supplier_id: order?.supplier_id ? String(order.supplier_id) : d.supplier_id,
            subtotal: order ? String(order.total) : d.subtotal,
        }));
    };

    const columns: Column<Invoice>[] = [
        {
            key: 'reference',
            header: 'رقم السند',
            cell: (i) => (
                <>
                    <span className="font-mono text-[12px] text-[#4b4b4b]">{i.reference}</span>
                    {i.order && <span className="block text-[12px] text-[#9ca3af]">{i.order}</span>}
                </>
            ),
        },
        { key: 'supplier', header: 'المورّد', cell: (i) => i.supplier },
        {
            key: 'issued_at',
            header: 'التاريخ',
            cell: (i) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {i.issued_at ?? '—'}
                </span>
            ),
        },
        {
            key: 'due_at',
            header: 'الاستحقاق',
            cell: (i) => (
                <span dir="ltr" className={cn('text-[#6b7280]', i.overdue && 'font-semibold text-[#b91c1c]')}>
                    {i.due_at ?? '—'}
                </span>
            ),
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (i) => <span className="tabular-nums">{m(i.total)}</span>,
        },
        {
            key: 'outstanding',
            header: 'المستحقّ',
            align: 'end',
            cell: (i) => (
                <span
                    className={cn(
                        'font-semibold tabular-nums',
                        i.outstanding > 0 ? 'text-[#b45309]' : 'text-[#047857]',
                    )}
                >
                    {i.outstanding > 0 ? m(i.outstanding) : '—'}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (i) => (
                <Badge variant={i.status === 'مدفوع' ? 'success' : i.overdue ? 'danger' : 'warning'}>
                    {t(i.overdue ? 'متأخّر' : i.status)}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (i) => (
                <div className="flex items-center justify-end gap-1">
                    {i.outstanding > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                payForm.clearErrors();
                                payForm.setData({ amount: String(i.outstanding), paid_at: today, from: 'cash' });
                                setPaying(i);
                            }}
                        >
                            <Wallet />
                            {t('سداد')}
                        </Button>
                    )}
                    {i.paid === 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-[#b91c1c]"
                            onClick={() => {
                                if (!confirm(t('حذف السند وقيده من الدفتر؟'))) return;
                                router.delete(route('admin.purchases.invoices.destroy', i.id), {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const tableFilters: Filter<Invoice>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: [
                { label: 'غير مدفوع', value: 'غير مدفوع' },
                { label: 'جزئي', value: 'جزئي' },
                { label: 'مدفوع', value: 'مدفوع' },
            ],
        },
        { label: 'كل الموردين', param: 'supplier', options: suppliers.map((s) => ({ label: s.label, value: String(s.value) })) },
    ];

    return (
        <AdminLayout title="سندات الموردين">
            <PageHeader
                title="سندات الموردين"
                subtitle={t('فواتير الموردين كما وصلت — وما بقي عليها')}
                actions={
                    <Button onClick={() => setAdding(true)} disabled={suppliers.length === 0}>
                        <Plus />
                        {t('سند جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={PURCHASE_TABS} current="admin.purchases.invoices" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard stat={{ label: t('السندات'), value: number(summary.count), icon: 'file-text', color: 'info' }} index={0} />
                <StatCard
                    stat={{
                        label: t('مستحقّ للموردين'),
                        value: m(summary.outstanding),
                        icon: 'truck',
                        color: summary.outstanding > 0 ? 'warning' : 'success',
                    }}
                    index={1}
                />
                <StatCard
                    stat={{
                        label: t('متأخّر عن استحقاقه'),
                        value: m(summary.overdue_value),
                        icon: 'alert-triangle',
                        color: summary.overdue > 0 ? 'danger' : 'success',
                        trend: summary.overdue > 0 ? t(':n سندًا', { n: summary.overdue }) : undefined,
                        up: false,
                    }}
                    index={2}
                />
            </div>

            {suppliers.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <FileText className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا موردين بعد')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('السند يُحرَّر على مورّد — أضف مورّديك أولًا من قسم المخزون.')}
                    </p>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={invoices}
                        columns={columns}
                        rowKey={(i) => i.id}
                        searchPlaceholder="ابحث برقم السند أو المورّد…"
                        searchable={() => ''}
                        filters={tableFilters}
                        empty="لا سندات بعد"
                        server={{ pagination, params: filters }}
                    />
                </Card>
            )}

            {/* ===== سند جديد ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('سند مورّد جديد')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('admin.purchases.invoices.store'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setAdding(false);
                                    form.reset();
                                },
                            });
                        }}
                        className="space-y-4"
                    >
                        <Field
                            label="أمر الشراء"
                            hint="الربط يمنع عدّ الشراء مرّتين في قائمة المشتريات"
                            error={form.errors.purchase_order_id}
                        >
                            <Select
                                placeholder="بلا أمر — شراء مباشر"
                                value={form.data.purchase_order_id}
                                onChange={(e) => pickOrder(e.target.value)}
                                options={orders}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="المورّد" required error={form.errors.supplier_id}>
                                <Select
                                    placeholder="اختر المورّد"
                                    value={form.data.supplier_id}
                                    onChange={(e) => form.setData('supplier_id', e.target.value)}
                                    options={suppliers}
                                />
                            </Field>
                            <Field
                                label="رقم السند"
                                required
                                hint="رقمه عند المورّد كما هو على الورقة"
                                error={form.errors.supplier_ref}
                            >
                                <Input
                                    dir="ltr"
                                    value={form.data.supplier_ref}
                                    onChange={(e) => form.setData('supplier_ref', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="تاريخ السند" required error={form.errors.issued_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.issued_at}
                                    onChange={(e) => form.setData('issued_at', e.target.value)}
                                />
                            </Field>
                            <Field label="تاريخ الاستحقاق" error={form.errors.due_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.due_at}
                                    onChange={(e) => form.setData('due_at', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="المبلغ قبل الضريبة" required error={form.errors.subtotal}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.subtotal}
                                    onChange={(e) => form.setData('subtotal', e.target.value)}
                                />
                            </Field>
                            <Field label="الضريبة" error={form.errors.tax}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.tax}
                                    onChange={(e) => form.setData('tax', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="flex items-center justify-between rounded-[12px] bg-[#fafafa] px-4 py-3 text-sm">
                            <span className="text-[#6b7280]">{t('الإجمالي')}</span>
                            <span className="font-semibold tabular-nums text-[#111]">{m(total)}</span>
                        </div>

                        <Field label="ملاحظات" error={form.errors.notes}>
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing} disabled={total <= 0}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== سداد ===== */}
            <Dialog open={paying !== null} onOpenChange={(o) => !o && setPaying(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('سداد السند')} — {paying?.reference}
                        </DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!paying) return;
                            payForm.post(route('admin.purchases.invoices.pay', paying.id), {
                                preserveScroll: true,
                                onSuccess: () => setPaying(null),
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="rounded-[12px] bg-[#fafafa] p-3 text-[13px] text-[#6b7280]">
                            <p className="flex justify-between">
                                <span>{t('المستحقّ')}</span>
                                <span className="font-semibold tabular-nums text-[#111]">
                                    {m(paying?.outstanding ?? 0)}
                                </span>
                            </p>
                            {(paying?.paid ?? 0) > 0 && (
                                <p className="mt-1 flex justify-between text-[12px] text-[#9ca3af]">
                                    <span>{t('سُدّد سابقًا')}</span>
                                    <span className="tabular-nums">{m(paying?.paid ?? 0)}</span>
                                </p>
                            )}
                        </div>

                        <Field label="المبلغ" required error={payForm.errors.amount}>
                            <Input
                                type="number"
                                step="0.001"
                                min="0"
                                dir="ltr"
                                value={payForm.data.amount}
                                onChange={(e) => payForm.setData('amount', e.target.value)}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="التاريخ" required error={payForm.errors.paid_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={payForm.data.paid_at}
                                    onChange={(e) => payForm.setData('paid_at', e.target.value)}
                                />
                            </Field>
                            <Field label="دُفع من" required error={payForm.errors.from}>
                                <Select
                                    value={payForm.data.from}
                                    onChange={(e) => payForm.setData('from', e.target.value)}
                                    options={[
                                        { value: 'cash', label: 'الصندوق' },
                                        { value: 'bank', label: 'البنك' },
                                    ]}
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setPaying(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={payForm.processing}>
                                <Wallet />
                                {t('تسجيل السداد')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

import { useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, FileText, Plus, ShieldAlert, Trash2, Wallet, X } from 'lucide-react';
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
import { useConfirm } from '@/Components/ConfirmDialog';
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
    approval_status: string;
    match_status: string | null;
    match_notes: string[];
    override_reason: string | null;
    rejection_reason: string | null;
    /** قيمةُ ما وصل فعلًا على أمره — من الاستلامات المعتمَدة وحدها */
    /** اسمُ فاتورة المورّد المرفقة — لا مسارُها */
    attachment: string | null;
    received_value: number | null;
    order_total: number | null;
    overdue: boolean;
    notes: string | null;
}

/** كما يكتبها الخادم في `SupplierInvoices` — نصٌّ واحد لا نصّان */
const PENDING = 'بانتظار الاعتماد';
const APPROVED = 'معتمد';
const BLOCKED = 'ممنوع';

interface Props {
    invoices: Invoice[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    /** أعمدة يرتّبها الخادم — مصدرها `Sort::keys` في المتحكّم */
    sorts: string[];
    suppliers: { value: number; label: string }[];
    orders: { value: number; label: string; supplier_id: number | null; total: number }[];
    summary: { count: number; outstanding: number; overdue: number; overdue_value: number };
    today: string;
    pendingCount: number;
    canApprove: boolean;
    canOverride: boolean;
    /** والرفضُ فعلٌ غيرُ الاعتماد، والسدادُ ثالثٌ غيرُهما */
    canReject: boolean;
    canCreate: boolean;
    canPay: boolean;
    canSeeAttachment: boolean;
}

export default function SupplierInvoices() {
    const { invoices, pagination, filters, sorts, suppliers, orders, summary, today,
        pendingCount, canApprove, canOverride, canReject, canCreate, canPay,
        canSeeAttachment, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const m = (v: number) => money(v, context!.currency);

    const [adding, setAdding] = useState(false);
    const [paying, setPaying] = useState<Invoice | null>(null);
    const [deciding, setDeciding] = useState<Invoice | null>(null);
    // ورسالةُ الردّ تصل في `approve` — فيُحجَز لها موضعٌ في النموذج
    const decide = useForm({ override_reason: '', reason: '', approve: '' });

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
            /*
                حالان لا حالٌ واحدة: الاعتمادُ والسداد.
                وخلطُهما يجعل «معتمد» و«مدفوع» في خانةٍ واحدة — فلا يُعرف
                سندٌ اعتُمد ولم يُدفع من سندٍ لم يُعتمد أصلًا.
            */
            key: 'approval',
            header: 'الاعتماد',
            cell: (i) => (
                <div className="flex flex-col items-start gap-1">
                    <Badge
                        variant={
                            i.approval_status === APPROVED ? 'success'
                            : i.approval_status === PENDING ? 'warning'
                            : 'danger'
                        }
                    >
                        {t(i.approval_status)}
                    </Badge>
                    {/* وسببُ المنع يُقال في الصفّ لا خلف نقرة: من يعتمد يريد
                        أن يعرف لماذا مُنع قبل أن يفتح شيئًا */}
                    {i.approval_status === PENDING && i.match_status === BLOCKED && (
                        <span className="text-[11px] text-[#b91c1c]">{i.match_notes[0]}</span>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: 'السداد',
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
                    {/* والقرارُ قبل السداد: لا يُسدَّد ما لم يُعتمد */}
                    {(canApprove || canReject) && i.approval_status === PENDING && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                decide.clearErrors();
                                decide.setData({ override_reason: '', reason: '' });
                                setDeciding(i);
                            }}
                        >
                            <Check />
                            {t('مراجعة')}
                        </Button>
                    )}
                    {canPay && i.outstanding > 0 && i.approval_status === APPROVED && (
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
                    {canCreate && i.paid === 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-[#b91c1c]"
                            onClick={async () => {
                                if (! await ask({ message: 'حذف السند وقيده من الدفتر؟', danger: true, action: 'حذف' })) return;
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
                    canCreate ? (
                        <Button onClick={() => setAdding(true)} disabled={suppliers.length === 0}>
                            <Plus />
                            {t('سند جديد')}
                        </Button>
                    ) : null
                }
            />

            <SectionTabs tabs={PURCHASE_TABS} current="admin.purchases.invoices" />

            {/*
                طابورُ الاعتماد فوق كلّ شيء.

                والذمّةُ لا تنشأ حتى يُعتمد السند — فسندٌ ينتظر يعني دَينًا
                على المتجر لا يقوله الدفتر. ومن لا يعرف أنّه ينتظر لا يعتمده،
                فيصل موعدُ السداد وهو خارج الحساب.
            */}
            {pendingCount > 0 && (
                <Card className="mb-4 flex flex-wrap items-center gap-3 border-[#fed7aa] bg-[#fffbeb] p-4">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#fef3c7] text-[#b45309]">
                        <ShieldAlert className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[14px] font-semibold text-[#92400e]">
                            {t(':n سند بانتظار الاعتماد', { n: pendingCount })}
                        </p>
                        <p className="text-[12px] text-[#b45309]">
                            {t('الذمّة لا تنشأ في الدفتر حتى يُعتمد السند.')}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        className="ms-auto"
                        onClick={() => router.get(route('admin.purchases.invoices'), { approval: PENDING }, { preserveState: true })}
                    >
                        {t('عرض الطابور')}
                    </Button>
                </Card>
            )}

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
                        server={{ pagination, params: filters, sorts }}
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
                        className="space-y-4 px-5 pb-5"
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
                        className="space-y-4 px-5 pb-5"
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

            {/*
                نافذةُ المراجعة — الأرقامُ الثلاثة معًا ثمّ القرار.

                ما طُلب، وما وصل، وما طُولبنا به. ومن يعتمد رقمًا لا يرى
                جاريه يعتمد على غير علم — وهو ما كان يقع: يُرحَّل السند لحظةَ
                كتابته بلا أن يُقابَل بشيء.
            */}
            <Dialog open={deciding !== null} onOpenChange={(open) => !open && setDeciding(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('مراجعة السند')} <span className="font-mono">{deciding?.reference}</span>
                        </DialogTitle>
                    </DialogHeader>

                    {deciding && (
                        <div className="space-y-4">
                            <dl className="grid grid-cols-3 gap-3 text-center">
                                <div className="rounded-[10px] bg-[#fafafa] p-3">
                                    <dt className="text-[11px] text-[#9ca3af]">{t('أمر الشراء')}</dt>
                                    <dd className="mt-1 text-[15px] font-bold tabular-nums text-[#111]">
                                        {deciding.order_total === null ? '—' : m(deciding.order_total)}
                                    </dd>
                                </div>
                                <div className="rounded-[10px] bg-[#fafafa] p-3">
                                    <dt className="text-[11px] text-[#9ca3af]">{t('ما وصل واعتُمد')}</dt>
                                    <dd className="mt-1 text-[15px] font-bold tabular-nums text-[#111]">
                                        {deciding.received_value === null ? '—' : m(deciding.received_value)}
                                    </dd>
                                </div>
                                <div className="rounded-[10px] bg-[#fafafa] p-3">
                                    <dt className="text-[11px] text-[#9ca3af]">{t('السند')}</dt>
                                    <dd className="mt-1 text-[15px] font-bold tabular-nums text-[#111]">
                                        {m(deciding.total)}
                                    </dd>
                                </div>
                            </dl>

                            {deciding.match_notes.length > 0 && (
                                <ul
                                    className={cn(
                                        'space-y-1 rounded-[10px] p-3 text-[13px]',
                                        deciding.match_status === BLOCKED
                                            ? 'bg-[#fef2f2] text-[#b91c1c]'
                                            : 'bg-[#fffbeb] text-[#b45309]',
                                    )}
                                >
                                    {deciding.match_notes.map((n) => (
                                        <li key={n}>• {n}</li>
                                    ))}
                                </ul>
                            )}

                            {/*
                                والتجاوزُ بسببٍ يُكتب ويُنسب — ولا يقع صامتًا.
                                ولمن يملكه وحده: الدفعُ عن بضاعةٍ لم تصل قرارُ
                                من يملك المال.
                            */}
                            {deciding.match_status === BLOCKED && (
                                canOverride ? (
                                    <Field label="سبب التجاوز" error={decide.errors.approve}>
                                        <Input
                                            value={decide.data.override_reason}
                                            onChange={(e) => decide.setData('override_reason', e.target.value)}
                                            placeholder={t('مثال: وافقتُ على رفع السعر هاتفيًّا')}
                                        />
                                    </Field>
                                ) : (
                                    <p className="rounded-[10px] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]">
                                        {t('هذا السند لا يطابق أمره، وتجاوزُ ذلك ليس من صلاحياتك.')}
                                    </p>
                                )
                            )}

                            {canReject && (
                                <Field label="سبب الرفض" hint="يُكتب إن رفضتَ السند" error={decide.errors.reason}>
                                    <Input
                                        value={decide.data.reason}
                                        onChange={(e) => decide.setData('reason', e.target.value)}
                                    />
                                </Field>
                            )}

                            <div className="flex flex-wrap justify-end gap-2">
                                {/* وورقةُ المورّد تُفتح هنا: من يعتمد يقابل
                                    الرقمَ بما في يده لا بما نُقل عنه */}
                                {deciding.attachment && canSeeAttachment && (
                                    <Button variant="outline" className="me-auto" asChild>
                                        <a
                                            href={route('admin.purchases.invoices.attachment', deciding.id)}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <FileText />
                                            {deciding.attachment}
                                        </a>
                                    </Button>
                                )}
                                {canReject && (
                                    <Button
                                        variant="outline"
                                        disabled={decide.processing}
                                        onClick={() => {
                                            decide.transform((d) => ({ reason: d.reason }));
                                            decide.post(route('admin.purchases.invoices.reject', deciding.id), {
                                                preserveScroll: true,
                                                onSuccess: () => setDeciding(null),
                                            });
                                        }}
                                    >
                                        <X />
                                        {t('رفض')}
                                    </Button>
                                )}
                                {canApprove && (
                                <Button
                                    disabled={decide.processing || (deciding.match_status === BLOCKED && !canOverride)}
                                    onClick={() => {
                                        decide.transform((d) => ({ override_reason: d.override_reason }));
                                        decide.post(route('admin.purchases.invoices.approve', deciding.id), {
                                            preserveScroll: true,
                                            onSuccess: () => setDeciding(null),
                                        });
                                    }}
                                >
                                    <Check />
                                    {t('اعتماد السند')}
                                </Button>
                                )}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </AdminLayout>
    );
}

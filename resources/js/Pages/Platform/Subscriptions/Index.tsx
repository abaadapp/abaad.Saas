import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { FileText, Layers, Pencil, Plus } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import RowActions from '@/Components/RowActions';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface Subscription {
    id: number;
    business_id: number;
    plan_id: number | null;
    business: string;
    plan: string;
    start: string;
    end: string;
    amount: number;
    payment: string;
    status: string;
}

interface PlanOption {
    label: string;
    value: number;
    monthly: number;
    yearly: number;
}

interface Props {
    stats: Stat[];
    subscriptions: Subscription[];
    planNames: string[];
    planOptions: PlanOption[];
    currency: Currency;
}

export default function SubscriptionsIndex() {
    const { stats, subscriptions, planNames, planOptions, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<Subscription | null>(null);

    const form = useForm({
        plan_id: '',
        starts_at: '',
        ends_at: '',
        amount: '',
        payment_status: 'غير مدفوع',
        status: 'نشط',
    });

    /*
     * القيم تُملأ في أثرٍ لا في لحظة الفتح.
     *
     * Select من Radix يرفض قيمةً تُضبط في نفس الدورة التي تُركَّب فيها
     * خياراته، فتظهر النافذة بحقلٍ فارغ وقد اختير — والمستخدم يحفظ ظانًّا
     * أنه لم يغيّر شيئًا.
     */
    useEffect(() => {
        if (!editing) return;
        form.clearErrors();
        form.setData({
            plan_id: editing.plan_id ? String(editing.plan_id) : '',
            starts_at: editing.start === '—' ? '' : editing.start,
            ends_at: editing.end === '—' ? '' : editing.end,
            amount: String(editing.amount),
            payment_status: editing.payment || 'غير مدفوع',
            status: editing.status || 'نشط',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing?.id]);

    const save = () => {
        if (!editing) return;
        form.put(route('super-admin.subscriptions.update', editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    /** سعر الباقة المختارة — يملأ المبلغ ولا يفرضه (الخصم يبقى ممكنًا) */
    const suggest = (cycle: 'monthly' | 'yearly') => {
        const plan = planOptions.find((p) => String(p.value) === form.data.plan_id);
        if (plan) form.setData('amount', String(plan[cycle]));
    };

    const columns: Column<Subscription>[] = [
        { key: 'business', header: 'الشركة', cell: (s) => <span className="font-medium text-[#111]">{s.business}</span>, value: (s) => s.business },
        { key: 'plan', header: 'الباقة', cell: (s) => <Badge variant="primary">{t(s.plan)}</Badge>, value: (s) => s.plan },
        { key: 'start', header: 'تاريخ البداية', cell: (s) => <span dir="ltr">{s.start}</span>, value: (s) => s.start },
        { key: 'end', header: 'تاريخ الانتهاء', cell: (s) => <span dir="ltr">{s.end}</span>, value: (s) => s.end, sortable: true },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            sortable: true,
            cell: (s) => <span className="font-semibold tabular-nums">{money(s.amount, currency)}</span>,
            value: (s) => s.amount,
        },
        { key: 'payment', header: 'حالة الدفع', cell: (s) => <Badge status={s.payment} />, value: (s) => s.payment },
        { key: 'status', header: 'حالة الاشتراك', cell: (s) => <Badge status={s.status} />, value: (s) => s.status },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (s) => (
                <RowActions
                    show={{
                        href: route('super-admin.businesses.show', s.business_id),
                        routeName: 'super-admin.businesses.show',
                    }}
                    destroy={{
                        url: route('super-admin.subscriptions.destroy', s.id),
                        message:
                            'سيُحذف سجلّ هذه الدورة، ويعود تاريخ انتهاء المتجر إلى الدورة السابقة. هل تريد المتابعة؟',
                    }}
                    extra={[
                        {
                            label: 'تعديل',
                            icon: <Pencil className="size-4" />,
                            onSelect: () => setEditing(s),
                        },
                    ]}
                />
            ),
        },
    ];

    const filters: Filter<Subscription>[] = [
        {
            label: 'كل الباقات',
            options: planNames.map((p) => ({ label: p, value: p })),
            match: (s, v) => s.plan === v,
        },
        {
            label: 'كل حالات الدفع',
            options: [
                { label: 'مدفوع', value: 'مدفوع' },
                { label: 'غير مدفوع', value: 'غير مدفوع' },
            ],
            match: (s, v) => s.payment === v,
        },
        {
            label: 'كل الحالات',
            options: [
                { label: 'نشط', value: 'نشط' },
                { label: 'منتهي', value: 'منتهي' },
                { label: 'معطل', value: 'معطل' },
            ],
            match: (s, v) => s.status === v,
        },
    ];

    return (
        <PlatformLayout title="الاشتراكات">
            <PageHeader
                title="الاشتراكات"
                subtitle={t('إدارة اشتراكات الشركات في المنصة ومتابعة حالة الدفع والتجديد')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.plans"
                                href={route('super-admin.subscriptions.plans')}
                            >
                                <Layers />
                                {t('الباقات')}
                            </SmartLink>
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.invoices"
                                href={route('super-admin.subscriptions.invoices')}
                            >
                                <FileText />
                                {t('الفواتير')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink
                                routeName="super-admin.businesses.create"
                                href={route('super-admin.businesses.create')}
                            >
                                <Plus />
                                {t('اشتراك جديد')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <DataTable
                rows={subscriptions}
                columns={columns}
                rowKey={(s) => s.id}
                filters={filters}
                searchPlaceholder="ابحث باسم الشركة…"
                searchable={(s) => s.business}
                empty={t('لا توجد اشتراكات بعد')}
            />

            <Dialog open={editing !== null} onOpenChange={(v) => !v && setEditing(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {t('تعديل الاشتراك')} — {editing?.business}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-4 px-5 pb-5 sm:grid-cols-2">
                        <Field label="الباقة" className="sm:col-span-2" error={form.errors.plan_id}>
                            <Select
                                value={form.data.plan_id}
                                onChange={(e) => form.setData('plan_id', e.target.value)}
                                options={planOptions.map((p) => ({ label: p.label, value: String(p.value) }))}
                                placeholder="اختر الباقة…"
                            />
                        </Field>

                        <Field label="تاريخ البداية" required error={form.errors.starts_at}>
                            <Input
                                type="date"
                                dir="ltr"
                                value={form.data.starts_at}
                                onChange={(e) => form.setData('starts_at', e.target.value)}
                            />
                        </Field>

                        <Field label="تاريخ الانتهاء" required error={form.errors.ends_at}>
                            <Input
                                type="date"
                                dir="ltr"
                                value={form.data.ends_at}
                                onChange={(e) => form.setData('ends_at', e.target.value)}
                            />
                        </Field>

                        <Field label="المبلغ" required className="sm:col-span-2" error={form.errors.amount}>
                            <Input
                                inputMode="decimal"
                                dir="ltr"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                            {/* السعر يُقترح ولا يُفرض: الخصم المتّفق عليه يجب أن يُكتب كما هو */}
                            <div className="mt-2 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => suggest('monthly')}
                                    className="rounded-[8px] bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#4b4b4b] transition-colors hover:bg-[#e8e8e6]"
                                >
                                    {t('سعر الشهر')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => suggest('yearly')}
                                    className="rounded-[8px] bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#4b4b4b] transition-colors hover:bg-[#e8e8e6]"
                                >
                                    {t('سعر السنة')}
                                </button>
                            </div>
                        </Field>

                        <Field label="حالة الدفع" required error={form.errors.payment_status}>
                            <Select
                                value={form.data.payment_status}
                                onChange={(e) => form.setData('payment_status', e.target.value)}
                                options={[
                                    { label: t('مدفوع'), value: 'مدفوع' },
                                    { label: t('غير مدفوع'), value: 'غير مدفوع' },
                                ]}
                            />
                        </Field>

                        <Field label="حالة الاشتراك" required error={form.errors.status}>
                            <Select
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value)}
                                options={[
                                    { label: t('نشط'), value: 'نشط' },
                                    { label: t('منتهي'), value: 'منتهي' },
                                    { label: t('معطل'), value: 'معطل' },
                                ]}
                            />
                        </Field>

                        {/*
                            التعديل يمسّ المتجر لا هذا السطر وحده — يُقال صراحةً
                            لأن الحارس يقرأ تاريخ المتجر لا تاريخ الاشتراك.
                        */}
                        <p className="text-[12px] text-[#9ca3af] sm:col-span-2">
                            {t('تعديل أحدث دورة يُحدّث باقة المتجر وتاريخ انتهائه.')}
                        </p>

                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="button" loading={form.processing} onClick={save}>
                                {t('حفظ')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
